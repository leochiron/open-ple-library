<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/** Browser policy uses only an authenticated current student request, never teacher UA. */
trait QuizBrowser
{
    private ?string $browserRequestUserAgent = null;

    public function setBrowserRequestUserAgent(?string $userAgent): void { $this->browserRequestUserAgent = $userAgent; }

    private function browserPolicy(array $session): array
    {
        return ['enabled' => !empty($session['browser_enabled']), 'allowed_families' => json_decode($session['browser_families_json'], true, 16, JSON_THROW_ON_ERROR), 'fullscreen_required' => !empty($session['require_fullscreen'])];
    }

    public function setBrowserPolicy(int $sessionId, bool $enabled, array $families, int $expectedSettingsRevision, string $reason): void
    {
        $this->currentAdminId(); $families = QuizBrowserEvaluator::normalizeFamilies($families); $reason = $this->normalizeAccessReason($reason);
        $this->db->beginTransaction();
        try {
            $this->lockTeacherSession($sessionId); $session = $this->requireSession($sessionId);
            if ((int)$session['settings_revision'] !== $expectedSettingsRevision) { throw new RuntimeException('settings_revision_mismatch'); }
            if ($enabled && !self::trackingStrengthened($session['tracking_mode'])) { throw new RuntimeException('browser_requires_tracking'); }
            $before = $this->browserPolicy($session);
            if ($before['enabled'] === $enabled && $before['allowed_families'] === $families) { throw new RuntimeException('browser_policy_unchanged'); }
            $stmt = $this->db->prepare('UPDATE quiz_sessions SET browser_enabled=:enabled,browser_families_json=:families,settings_revision=settings_revision+1 WHERE id=:id');
            $stmt->execute(['enabled' => (int)$enabled, 'families' => json_encode($families, JSON_THROW_ON_ERROR), 'id' => $sessionId]);
            $this->invalidateBrowserSession($sessionId, 'policy_changed');
            $after = $this->browserPolicy($this->requireSession($sessionId)); $after['reason'] = $reason;
            $this->appendAudit($sessionId, 'browser_policy_changed', $before, $after);
            $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
    }

    private function invalidateBrowserSession(int $sessionId, string $kind): void
    {
        $last = 0;
        do {
            $stmt = $this->db->prepare("SELECT * FROM quiz_tracking_contexts WHERE session_id=:sid AND status='active' AND id>:last ORDER BY id LIMIT 50");
            $stmt->execute(['sid' => $sessionId, 'last' => $last]); $rows = $stmt->fetchAll(); $stmt->closeCursor();
            foreach ($rows as $row) { $last = (int)$row['id']; $this->browserInvalidate($row, null, in_array($kind,['policy_changed','fullscreen_changed','fullscreen_added'],true) ? 'pending' : 'terminated', $kind); }
        } while (count($rows) === 50);
    }

    private function browserInvalidate(array $context, ?array $assessment, string $status, string $kind): array
    {
        if($assessment===null && $context['browser_status']==='missing' && !in_array($kind,['policy_changed','fullscreen_changed'],true)){return $context;}
        $stmt = $this->db->prepare('UPDATE quiz_tracking_contexts SET browser_status=:status,browser_incarnation=:inc,browser_policy_fingerprint=NULL,browser_canonical_json=:canonical,browser_diagnostic_json=:diagnostic WHERE id=:id');
        $details = $assessment !== null ? $this->browserDetails($assessment) : ['causes' => ['browser_verification_required'], 'transition' => $kind];
        if ($assessment !== null) { $details['causes'] = array_values(array_unique(array_merge(['browser_environment_changed', 'browser_verification_required'], $details['causes']))); }
        $stmt->execute(['status' => $status, 'inc' => bin2hex(random_bytes(16)), 'canonical' => $assessment !== null ? json_encode($assessment['canonical'], JSON_THROW_ON_ERROR) : null, 'diagnostic' => json_encode($details, JSON_THROW_ON_ERROR), 'id' => $context['id']]);
        $this->trackingTerminatePending((int)$context['id']);
        if ($context['browser_status'] !== 'missing' || $assessment !== null) { $this->browserDiagnostic($context, $assessment !== null ? 'browser_environment_changed' : 'browser_verification_required', 'transition', $details); }
        return $this->browserReloadContext((int)$context['id']);
    }

    private function browserReloadContext(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM quiz_tracking_contexts WHERE id=:id'); $stmt->execute(['id' => $id]); return $stmt->fetch();
    }

    private function browserDetails(array $assessment): array
    {
        $canonical = $assessment['canonical']; unset($canonical['policy_fingerprint']);
        return $canonical + ['observed_capabilities' => $assessment['observed_capabilities'], 'causes' => $assessment['causes'], 'applied_policy'=>$assessment['applied_policy']??null, 'provenance' => $assessment['provenance']];
    }

    /** Called only after current document binding, and only by state/decision/protocol. */
    private function browserObserveHttp(array $session, array $context): array
    {
        if (empty($session['browser_enabled'])) { return $context; }
        if ($this->browserRequestUserAgent === null) { throw new RuntimeException('schema_invalid'); }
        $http = QuizBrowserEvaluator::parse($this->browserRequestUserAgent);
        $coherent = QuizBrowserEvaluator::coherentTokens($this->browserRequestUserAgent);
        $canonical = $context['browser_canonical_json'] !== null ? json_decode($context['browser_canonical_json'], true, 32, JSON_THROW_ON_ERROR) : null;
        $fingerprint = QuizBrowserEvaluator::policyFingerprint($this->browserPolicy($session));
        if ($canonical !== null && ($canonical['http'] !== $http || ($canonical['http_tokens_consistent']??true) !== $coherent || $canonical['policy_fingerprint'] !== $fingerprint)) {
            $canonical['http'] = $http; $canonical['http_tokens_consistent'] = $coherent; $canonical['policy_fingerprint'] = $fingerprint;
            $causes=[];
            if(!in_array($http['family'],$this->browserPolicy($session)['allowed_families'],true)){$causes[]='browser_outside_policy';}
            if(!$coherent){$causes[]='browser_info_inconsistent';}
            return $this->browserInvalidate($context, ['canonical' => $canonical, 'observed_capabilities' => [], 'causes' => $causes, 'provenance' => ['http' => 'server_observed']], 'pending', 'http_changed');
        }
        return $context;
    }

    private function browserAssessment(array $session, ?array $browser): ?array
    {
        if ($browser !== null) { $browser = QuizBrowserEvaluator::normalizeEnvelope($browser); }
        if (empty($session['browser_enabled'])) { return null; }
        if ($browser === null || $this->browserRequestUserAgent === null) { throw new RuntimeException('schema_invalid'); }
        return QuizBrowserEvaluator::evaluate($this->browserRequestUserAgent, $browser, $this->browserPolicy($session));
    }

    /** Returns a fresh canonical observation and immutable nonce powers, never assesses health. */
    private function browserIssue(array $session, array $context, ?array $browser, string $trackingPower): array
    {
        $assessment = $this->browserAssessment($session, $browser);
        if ($assessment !== null) {
            $context = $this->browserObserveHttp($session, $context);
            if ($context['browser_canonical_json'] !== null && hash('sha256', $context['browser_canonical_json']) !== $assessment['environment_fingerprint']) { $context = $this->browserInvalidate($context, $assessment, 'pending', 'declaration_changed'); }
            if ($trackingPower === 'full_preflight' && $context['proof_status'] === 'healthy' && $context['browser_status'] !== 'valid') { $trackingPower = 'diagnostic_only'; }
        }
        return ['context' => $context, 'assessment' => $assessment, 'tracking_power' => $trackingPower, 'browser_power' => $assessment !== null ? 'assessment' : 'none', 'protocol' => $browser !== null ? 2 : 1];
    }

    private function browserBindNonce(int $nonceId, array $issue): void
    {
        $stmt = $this->db->prepare('UPDATE quiz_tracking_challenges SET tracking_power=:tracking,browser_power=:browser,browser_protocol=:protocol,browser_policy_fingerprint=:policy,browser_environment_fingerprint=:environment,browser_incarnation=:inc,proof_incarnation=:proof WHERE id=:id');
        $stmt->execute(['tracking' => $issue['tracking_power'], 'browser' => $issue['browser_power'], 'protocol' => $issue['protocol'], 'policy' => $issue['assessment']['canonical']['policy_fingerprint'] ?? null, 'environment' => $issue['assessment']['environment_fingerprint'] ?? null, 'inc' => $issue['context']['browser_incarnation'], 'proof' => $issue['context']['proof_incarnation'], 'id' => $nonceId]);
    }

    /** A relevant declaration change is a durable browser-only refusal, with no consume/renew. */
    private function browserCheckNonce(array $session, array $context, array $nonce, ?array $browser): array
    {
        $assessment = $this->browserAssessment($session, $browser);
        if ((int)$nonce['browser_protocol'] !== ($browser !== null ? 2 : 1)) { return ['context' => $context, 'assessment' => $assessment, 'refusal' => 'schema_invalid']; }
        if ($assessment === null) { return ['context' => $context, 'assessment' => null, 'refusal' => $nonce['browser_power'] === 'none' ? null : 'settings_revision_mismatch']; }
        if ($nonce['browser_power'] !== 'assessment' || $nonce['browser_policy_fingerprint'] !== $assessment['canonical']['policy_fingerprint']) { return ['context' => $context, 'assessment' => $assessment, 'refusal' => 'settings_revision_mismatch']; }
        if ($nonce['browser_environment_fingerprint'] !== $assessment['environment_fingerprint']) {
            $context = $this->browserInvalidate($context, $assessment, 'pending', 'declaration_changed');
            return ['context' => $context, 'assessment' => $assessment, 'refusal' => 'browser_environment_changed'];
        }
        if ($nonce['browser_incarnation'] !== $context['browser_incarnation']) { return ['context' => $context, 'assessment' => $assessment, 'refusal' => 'browser_verification_required']; }
        return ['context' => $context, 'assessment' => $assessment, 'refusal' => null];
    }

    private function browserConsumeAssessment(array $context, ?array $assessment, string $operation): void
    {
        if ($assessment === null) { return; }
        $details = $this->browserDetails($assessment);
        $stmt = $this->db->prepare('UPDATE quiz_tracking_contexts SET browser_status=:status,browser_assessed_at=:now,browser_incarnation=:inc,browser_policy_fingerprint=:policy,browser_canonical_json=:canonical,browser_diagnostic_json=:details WHERE id=:id');
        $stmt->execute(['status' => $assessment['allowed'] ? 'valid' : 'failed', 'now' => $this->now(), 'inc' => bin2hex(random_bytes(16)), 'policy' => $assessment['canonical']['policy_fingerprint'], 'canonical' => json_encode($assessment['canonical'], JSON_THROW_ON_ERROR), 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'id' => $context['id']]);
        $this->browserDiagnostic($context, $assessment['allowed'] ? 'browser_assessment_valid' : 'browser_assessment_failed', $operation, $details);
    }

    private function browserDiagnostic(array $context, string $code, string $operation, array $details): void
    {
        if (!in_array($code, ['browser_assessment_valid', 'browser_assessment_failed', 'browser_environment_changed', 'browser_verification_required'], true) || !in_array($operation, ['preflight', 'pulse', 'transition'], true)) { throw new RuntimeException('invalid_browser_diagnostic'); }
        $json = json_encode($details, JSON_THROW_ON_ERROR); if (strlen($json) > 4096) { throw new RuntimeException('invalid_browser_diagnostic'); }
        $stmt = $this->db->prepare('INSERT INTO quiz_browser_diagnostics(session_id,student_id,attempt_id,context_ref,tracking_generation,first_name,last_name,created_at,code,operation,details_json,identity_truncated) VALUES(:sid,:student,:aid,:ref,:gen,:first,:last,:now,:code,:operation,:details,:reduced)');
        $stmt->execute(['sid' => $context['session_id'], 'student' => $context['student_id'], 'aid' => $context['attempt_id'], 'ref' => $context['context_ref'], 'gen' => $context['tracking_generation'], 'first' => $context['first_name'], 'last' => $context['last_name'], 'now' => $this->now(), 'code' => $code, 'operation' => $operation, 'details' => $json, 'reduced' => $context['identity_truncated']]);
    }

    public function listBrowserDiagnostics(int $sessionId, int $beforeId = 0, int $limit = 25): array
    {
        $this->currentAdminId(); $this->requireSession($sessionId); $limit = max(1, min(50, $limit));
        $stmt = $this->db->prepare('SELECT * FROM quiz_browser_diagnostics WHERE session_id=:sid'.($beforeId > 0 ? ' AND id<:before' : '').' ORDER BY id DESC LIMIT '.($limit+1));
        $args = ['sid' => $sessionId]; if ($beforeId > 0) { $args['before'] = $beforeId; } $stmt->execute($args); $rows = $stmt->fetchAll(); $more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$row) { $row['details'] = json_decode($row['details_json'], true, 32, JSON_THROW_ON_ERROR); unset($row['details_json']); } unset($row);
        return ['rows' => $rows, 'next_before' => $more ? (int)end($rows)['id'] : null];
    }

    public function getBrowserPolicy(int $sessionId): array
    {
        $this->currentAdminId(); return $this->browserPolicy($this->requireSession($sessionId));
    }

    private function csvBrowserRecords($out, int $sessionId): void
    {
        $session = $this->requireSession($sessionId); $row = array_fill(0, 23, ''); $row[0] = 'politique_navigateur';
        $this->csvRow($out, $row, array_pad(['browser_courant'], 9, '') + [9 => json_encode(['browser_policy' => $this->browserPolicy($session)], JSON_THROW_ON_ERROR)]);
        $stmt = $this->db->prepare('SELECT * FROM quiz_browser_diagnostics WHERE session_id=:sid ORDER BY id'); $stmt->execute(['sid' => $sessionId]);
        while ($record = $stmt->fetch()) { $record['details'] = json_decode($record['details_json'], true, 32, JSON_THROW_ON_ERROR); unset($record['details_json']); $this->csvTrackingRecord($out, 'diagnostic_navigateur', $record, ['diagnostic_browser']); } $stmt->closeCursor();
    }
}
