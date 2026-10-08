<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

/** Preparation and continuous pulses. Binding secrets never enter private projections. */
trait QuizTracking
{
    private ?string $trackingRequestEpoch = null;
    private ?int $trackingDecisionTime = null;
    private const TRACKING_CHECKS = ['listener_roundtrip', 'trusted_enter_received', 'hidden_received', 'visible_after_hidden_received', 'focus_after_hidden_received', 'fullscreen_change_received', 'fullscreen_active'];
    private const PULSE_CHECKS = ['listener_roundtrip', 'fullscreen_active'];

    /** One predicate for every strengthened route/decision; off remains historical. */
    public static function trackingStrengthened(string $mode): bool
    {
        return in_array($mode, ['preflight', 'continuous'], true);
    }

    public function setTrackingRequestEpoch(?string $epoch): void
    {
        $this->trackingRequestEpoch = $epoch;
    }

    private function trackingClock(): int
    {
        if(!$this->db->inTransaction()) {
            return (int)strtotime($this->now().' UTC');
        }
        if($this->trackingDecisionTime===null) {
            $this->trackingDecisionTime=(int)strtotime($this->now().' UTC');
        }
        return $this->trackingDecisionTime;
    }

    private function trackingCookieHash(bool $create = false): ?string
    {
        if (!isset($_SESSION['quiz_tracking_binding']) && $create)  {
            $_SESSION['quiz_tracking_binding'] = bin2hex(random_bytes(32));
        }
        $secret = $_SESSION['quiz_tracking_binding'] ?? null;
        return is_string($secret) && preg_match('/^[a-f0-9]{64}$/D', $secret) === 1 ? hash_hmac('sha256', 'quiz-tracking-cookie-v1:' . $secret, $this->hmacSecret) : null;
    }

    private function trackingCurrentContext(): ?array
    {
        $hash = $this->trackingCookieHash();
        if ($hash === null)  {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM quiz_tracking_contexts WHERE cookie_binding_hash=:hash AND status='active'");
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private function trackingNewContext(array $session, array $attempt, string $cookieHash, string $epochHash): array
    {
        $stmt = $this->db->prepare("INSERT INTO quiz_tracking_contexts(context_ref,cookie_binding_hash,document_epoch_hash,session_id,student_id,attempt_id,tracking_generation,first_name,last_name,status,created_at,identity_truncated) VALUES(:ref,:cookie,:epoch,:sid,:student,:aid,:gen,:first,:last,'active',:now,:reduced)");
        $stmt->execute(['ref' => bin2hex(random_bytes(16)), 'cookie' => $cookieHash, 'epoch' => $epochHash, 'sid' => (int)$session['id'], 'student' => (int)$attempt['student_id'], 'aid' => (int)$attempt['id'], 'gen' => $session['tracking_generation'], 'first' => mb_substr($attempt['first_name'],0,200), 'last' => mb_substr($attempt['last_name'],0,200), 'now' => $this->now(), 'reduced' => (int)(mb_strlen($attempt['first_name']) > 200 || mb_strlen($attempt['last_name']) > 200)]);
        $context = $this->trackingCurrentContext();
        $this->trackingDiagnostic($context, 'proof_missing', 'room', 'server_observed');
        return $context;
    }

    private function trackingTerminateContext(array $context, string $kind): void
    {
        $this->browserInvalidate($context, null, 'terminated', $kind);
        $stmt = $this->db->prepare("UPDATE quiz_tracking_contexts SET status='terminated',terminated_at=:now,termination_kind=:kind WHERE id=:id AND status='active'");
        $stmt->execute(['now' => $this->now(), 'kind' => $kind, 'id' => $context['id']]);
        $stmt = $this->db->prepare('UPDATE quiz_tracking_challenges SET terminated_at=:now WHERE context_id=:id AND consumed_at IS NULL AND terminated_at IS NULL');
        $stmt->execute(['now' => $this->now(), 'id' => $context['id']]);
    }

    private function trackingLock(int $sessionId, int $attemptId): array
    {
        $stmt = $this->db->prepare('UPDATE quiz_sessions SET settings_revision=settings_revision WHERE id=:id');
        $stmt->execute(['id' => $sessionId]);
        $this->trackingDecisionTime=null;
        $session = $this->requireSession($sessionId);
        $attempt = $this->getAttempt($attemptId);
        if ($attempt === null || (int)$attempt['session_id'] !== $sessionId || ($_SESSION['quiz_attempt_id'] ?? null) !== $attemptId)  {
            throw new RuntimeException('attempt_mismatch');
        }
        return [$session, $attempt];
    }

    private function trackingBoundContext(array $session, array $attempt, bool $adopt = false): array
    {
        $context = $this->trackingCurrentContext();
        $epoch = $this->trackingRequestEpoch;
        if ($context === null || (int)$context['session_id'] !== (int)$session['id'] || (int)$context['attempt_id'] !== (int)$attempt['id'])  {
            throw new RuntimeException('cookie_context_mismatch');
        }
        if (!is_string($epoch) || preg_match('/^[a-f0-9]{32}$/D',$epoch) !== 1 || !hash_equals($context['document_epoch_hash'],hash('sha256',$epoch)))  {
            throw new RuntimeException('document_epoch_mismatch');
        }
        if (!hash_equals($context['tracking_generation'],(string)$session['tracking_generation']))  {
            if (!$adopt)  {
                throw new RuntimeException('generation_mismatch');
            }
            $this->trackingTerminateContext($context,'generation_changed');
            $context = $this->trackingNewContext($session,$attempt,$context['cookie_binding_hash'],$context['document_epoch_hash']);
        }
        return $context;
    }

    public function openTrackingRoomDocument(int $sessionId, int $attemptId): string
    {
        $this->db->beginTransaction();
        try  {
            [$session,$attempt] = $this->trackingLock($sessionId,$attemptId);
            $old = $this->trackingCurrentContext();
            if ($old !== null)  {
                $this->trackingTerminateContext($old,'document_replaced');
            }
            $epoch = bin2hex(random_bytes(16));
            $this->trackingNewContext($session,$attempt,$this->trackingCookieHash(true),hash('sha256',$epoch));
            $this->trackingRequestEpoch = $epoch;
            $this->db->commit();
            return $epoch;
        } catch (Throwable $e)  {
            if ($this->db->inTransaction())  {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    /** Only a state request may adopt a teacher-created generation, with the same document epoch. */

    public function prepareTrackingState(int $sessionId,int $attemptId): array
    {
        $this->db->beginTransaction();
        try  {
            [$session,$attempt]=$this->trackingLock($sessionId,$attemptId);
            if (self::trackingStrengthened($session['tracking_mode']))  {
                $this->trackingBoundContext($session,$attempt,true);
            }
            $result=$this->buildStatePayload($session,$attempt);
            $this->db->commit();
            return $result;
        } catch (Throwable $e)  {
            if ($this->db->inTransaction())  {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function createTrackingChallenge(int $sessionId,int $attemptId,string $expectedGeneration,string $expectedRoomEpoch,?array $browser=null): array
    {
        $this->trackingRequestEpoch=$expectedRoomEpoch;
        $this->db->beginTransaction();
        try  {
            [$session,$attempt]=$this->trackingLock($sessionId,$attemptId);
            if (!self::trackingStrengthened($session['tracking_mode']) || $session['state']!=='running')  {
                throw new RuntimeException('invalid_tracking_state');
            }
            if (!hash_equals($session['tracking_generation'],$expectedGeneration))  {
                throw new RuntimeException('generation_mismatch');
            }
            $context=$this->trackingExpireProof($this->trackingBoundContext($session,$attempt));
            $issue=$this->browserIssue($session,$context,$browser,'full_preflight');$context=$issue['context'];
            $stmt=$this->db->prepare('UPDATE quiz_tracking_challenges SET terminated_at=:now WHERE context_id=:id AND consumed_at IS NULL AND terminated_at IS NULL');
            $stmt->execute(['now'=>$this->now(),'id'=>$context['id']]);
            $secret=bin2hex(random_bytes(32));
            $until=$this->trackingClock()+120;
            $stmt=$this->db->prepare('INSERT INTO quiz_tracking_challenges(context_id,secret_hash,settings_revision,issued_at,expires_at) VALUES(:id,:hash,:rev,:now,:until)');
            $stmt->execute(['id'=>$context['id'],'hash'=>hash('sha256',$secret),'rev'=>$session['settings_revision'],'now'=>$this->now(),'until'=>$until]);
            $this->browserBindNonce((int)$this->db->lastInsertId(),$issue);
            $this->db->commit();
            return ['challenge'=>$secret,'challenge_until'=>$until];
        } catch (Throwable $e)  {
            if($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function submitTrackingPreflight(int $sessionId,int $attemptId,string $expectedGeneration,string $expectedRoomEpoch,string $challenge,array $checks,?array $browser=null): array
    {
        if (count($checks)!==count(self::TRACKING_CHECKS) || array_diff(array_keys($checks),self::TRACKING_CHECKS)!==[])  {
            throw new RuntimeException('schema_invalid');
        }
        foreach(self::TRACKING_CHECKS as $key) {
            if(!is_bool($checks[$key]??null)) {
                throw new RuntimeException('schema_invalid');
            }
        }
        if(preg_match('/^[a-f0-9]{64}$/D',$challenge)!==1) {
            throw new RuntimeException('schema_invalid');
        }
        $this->trackingRequestEpoch=$expectedRoomEpoch;
        $this->db->beginTransaction();
        try  {
            [$session,$attempt]=$this->trackingLock($sessionId,$attemptId);
            if(!self::trackingStrengthened($session['tracking_mode'])||$session['state']!=='running') {
                throw new RuntimeException('invalid_tracking_state');
            }
            if(!hash_equals($session['tracking_generation'],$expectedGeneration)) {
                throw new RuntimeException('generation_mismatch');
            }
            $context=$this->trackingBoundContext($session,$attempt);
            $stmt=$this->db->prepare('SELECT * FROM quiz_tracking_challenges WHERE secret_hash=:hash AND context_id=:id');
            $stmt->execute(['hash'=>hash('sha256',$challenge),'id'=>$context['id']]);
            $row=$stmt->fetch();
            if($row===false) {
                throw new RuntimeException('challenge_mismatch');
            }
            if ($row['kind'] !== 'preflight') { throw new RuntimeException('challenge_kind_mismatch'); }
            if($row['consumed_at']!==null) {
                throw new RuntimeException('challenge_replayed');
            }
            if($row['terminated_at']!==null) {
                throw new RuntimeException('challenge_terminated');
            }
            if($this->trackingClock()>=(int)$row['expires_at']) {
                throw new RuntimeException('challenge_expired');
            }
            if((int)$row['settings_revision']!==(int)$session['settings_revision']) {
                throw new RuntimeException('settings_revision_mismatch');
            }
            $context=$this->trackingExpireProof($context);
            $checked=$this->browserCheckNonce($session,$context,$row,$browser);$context=$checked['context'];
            if($checked['refusal']!==null){$this->db->commit();throw new RuntimeException($checked['refusal']);}
            $trackingPower=$row['tracking_power']??'full_preflight';
            if($trackingPower==='diagnostic_only' && $row['proof_incarnation']!==$context['proof_incarnation']){throw new RuntimeException('proof_incarnation_mismatch');}
            if(!in_array($trackingPower,['full_preflight','diagnostic_only'],true)){throw new RuntimeException('invalid_tracking_state');}
            $stmt=$this->db->prepare('UPDATE quiz_tracking_challenges SET consumed_at=:now WHERE id=:id');
            $stmt->execute(['now'=>$this->now(),'id'=>$row['id']]);
            $required=array_slice(self::TRACKING_CHECKS,0,5);
            if(!empty($session['require_fullscreen'])) {
                $required=array_merge($required,array_slice(self::TRACKING_CHECKS,5));
            }
            $healthy=true;
            foreach($required as $key) {
                if(!$checks[$key]) {
                    $healthy=false;
                }
            }
            if($trackingPower==='full_preflight'){
                $json=json_encode($checks,JSON_THROW_ON_ERROR);
                $until=$healthy?$this->trackingClock()+60:null;
                $stmt=$this->db->prepare("UPDATE quiz_tracking_contexts SET phase='complete',proof_status=:status,proof_until=:until,proof_issued_at=:now,proof_incarnation=:inc,checks_json=:checks,proof_fullscreen_required=:fs,last_pulse_at=NULL,last_pulse_checks_json=NULL,last_pulse_outcome=NULL,last_pulse_until=NULL,last_pulse_fullscreen_required=NULL,pulse_failure_checks_json=NULL,pulse_failure_fullscreen_required=NULL WHERE id=:id");
                $stmt->execute(['status'=>$healthy?'healthy':'failed','until'=>$until,'now'=>$this->now(),'inc'=>bin2hex(random_bytes(16)),'checks'=>$json,'fs'=>(int)$session['require_fullscreen'],'id'=>$context['id']]);
            }
            $this->trackingDiagnostic($context,$trackingPower==='diagnostic_only'?'preflight_diagnostic_only':($healthy?'preflight_healthy':'preflight_failed'),'preflight','client_reported',$checks);
            $this->browserConsumeAssessment($context,$checked['assessment'],'preflight');
            $result=$this->buildStatePayload($session,$attempt);
            $this->db->commit();
            return $result;
        } catch(Throwable $e) {
            if($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function trackingExpireProof(array $context): array
    {
        if($context['proof_status']==='healthy' && $this->trackingClock()>=(int)$context['proof_until']) {
            $stmt=$this->db->prepare("UPDATE quiz_tracking_contexts SET proof_status='expired',proof_until=NULL WHERE id=:id AND proof_status='healthy'");
            $stmt->execute(['id'=>$context['id']]);
            if($stmt->rowCount()===1) {
                $this->trackingTerminatePending((int)$context['id'], 'pulse');
                $this->trackingDiagnostic($context,'proof_expired','state','server_observed');
            }
            $context['proof_status']='expired';
            $context['proof_until']=null;
        }
        return $context;
    }

    private function trackingTerminatePending(int $contextId, ?string $kind = null): void
    {
        $stmt = $this->db->prepare('UPDATE quiz_tracking_challenges SET terminated_at=:now WHERE context_id=:id AND consumed_at IS NULL AND terminated_at IS NULL' . ($kind !== null ? ' AND kind=:kind' : ''));
        $args = ['now' => $this->now(), 'id' => $contextId];
        if ($kind !== null) { $args['kind'] = $kind; }
        $stmt->execute($args);
    }

    public function createTrackingPulseChallenge(int $sessionId, int $attemptId, string $expectedGeneration, string $expectedRoomEpoch, ?array $browser=null): array
    {
        $this->trackingRequestEpoch = $expectedRoomEpoch;
        $this->db->beginTransaction();
        try {
            [$session, $attempt] = $this->trackingLock($sessionId, $attemptId);
            if ($session['tracking_mode'] !== 'continuous' || $session['state'] !== 'running' || $attempt['finished_at'] !== null) { throw new RuntimeException('invalid_tracking_state'); }
            if (!hash_equals($session['tracking_generation'], $expectedGeneration)) { throw new RuntimeException('generation_mismatch'); }
            $context = $this->trackingExpireProof($this->trackingBoundContext($session, $attempt));
            $purpose = $context['proof_status'] === 'healthy' ? 'renewing' : 'diagnostic_only';
            $issue=$this->browserIssue($session,$context,$browser,$purpose);$context=$issue['context'];
            $this->trackingTerminatePending((int)$context['id']);
            $secret = bin2hex(random_bytes(32)); $until = $this->trackingClock() + 120;
            $stmt = $this->db->prepare("INSERT INTO quiz_tracking_challenges(context_id,secret_hash,settings_revision,issued_at,expires_at,kind,purpose,proof_incarnation,proof_status_at_issue,fullscreen_required) VALUES(:id,:hash,:rev,:now,:until,'pulse',:purpose,:inc,:status,:fs)");
            $stmt->execute(['id' => $context['id'], 'hash' => hash('sha256', $secret), 'rev' => $session['settings_revision'], 'now' => $this->now(), 'until' => $until, 'purpose' => $purpose, 'inc' => $context['proof_incarnation'], 'status' => $context['proof_status'], 'fs' => (int)$session['require_fullscreen']]);
            $this->browserBindNonce((int)$this->db->lastInsertId(),$issue);
            $this->db->commit();
            return ['challenge' => $secret, 'challenge_until' => $until];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    public function submitTrackingPulse(int $sessionId, int $attemptId, string $expectedGeneration, string $expectedRoomEpoch, string $challenge, array $checks, ?array $browser=null): array
    {
        if (count($checks) !== 2 || array_diff(array_keys($checks), self::PULSE_CHECKS) !== [] || preg_match('/^[a-f0-9]{64}$/D', $challenge) !== 1) { throw new RuntimeException('schema_invalid'); }
        foreach (self::PULSE_CHECKS as $key) { if (!is_bool($checks[$key] ?? null)) { throw new RuntimeException('schema_invalid'); } }
        $this->trackingRequestEpoch = $expectedRoomEpoch;
        $this->db->beginTransaction();
        try {
            [$session, $attempt] = $this->trackingLock($sessionId, $attemptId);
            if ($session['tracking_mode'] !== 'continuous' || $session['state'] !== 'running' || $attempt['finished_at'] !== null) { throw new RuntimeException('invalid_tracking_state'); }
            if (!hash_equals($session['tracking_generation'], $expectedGeneration)) { throw new RuntimeException('generation_mismatch'); }
            // Only a trusted bound context may expire before an expected protocol refusal.
            $context = $this->trackingExpireProof($this->trackingBoundContext($session, $attempt));
            $stmt = $this->db->prepare('SELECT * FROM quiz_tracking_challenges WHERE secret_hash=:hash AND context_id=:id');
            $stmt->execute(['hash' => hash('sha256', $challenge), 'id' => $context['id']]); $row = $stmt->fetch();
            $refusal = null;
            if ($row === false) { $refusal = 'challenge_mismatch'; }
            elseif ($row['kind'] !== 'pulse') { $refusal = 'challenge_kind_mismatch'; }
            elseif ($row['consumed_at'] !== null) { $refusal = 'challenge_replayed'; }
            elseif ($row['terminated_at'] !== null) { $refusal = 'challenge_terminated'; }
            elseif ($this->trackingClock() >= (int)$row['expires_at']) { $refusal = 'challenge_expired'; }
            elseif ((int)$row['settings_revision'] !== (int)$session['settings_revision'] || (int)$row['fullscreen_required'] !== (int)$session['require_fullscreen']) { $refusal = 'settings_revision_mismatch'; }
            elseif ($row['proof_incarnation'] !== $context['proof_incarnation'] || $row['proof_status_at_issue'] !== $context['proof_status']) { $refusal = 'proof_incarnation_mismatch'; }
            elseif (!in_array($row['purpose'], ['renewing', 'diagnostic_only'], true) || ($row['purpose'] === 'renewing' && $context['proof_status'] !== 'healthy')) { $refusal = 'proof_incarnation_mismatch'; }
            if ($refusal !== null) {
                // No consume/result/renew happened: retain only observed expiry and its pending fence.
                $this->db->commit();
                throw new RuntimeException($refusal);
            }
            $checked=$this->browserCheckNonce($session,$context,$row,$browser);$context=$checked['context'];
            if($checked['refusal']!==null){$this->db->commit();throw new RuntimeException($checked['refusal']);}
            $stmt = $this->db->prepare('UPDATE quiz_tracking_challenges SET consumed_at=:now WHERE id=:id');
            $stmt->execute(['now' => $this->now(), 'id' => $row['id']]);
            $healthy = $checks['listener_roundtrip'] && (!(bool)$session['require_fullscreen'] || $checks['fullscreen_active']);
            $renewing = $row['purpose'] === 'renewing';
            $outcome = $renewing ? ($healthy ? 'healthy' : 'failed') : 'diagnostic_only';
            $until = $renewing && $healthy ? $this->trackingClock() + 60 : null;
            $stmt = $this->db->prepare('UPDATE quiz_tracking_contexts SET last_pulse_at=:now,last_pulse_checks_json=:checks,last_pulse_outcome=:outcome,last_pulse_until=:until,last_pulse_fullscreen_required=:fs WHERE id=:id');
            $stmt->execute(['now' => $this->now(), 'checks' => json_encode($checks, JSON_THROW_ON_ERROR), 'outcome' => $outcome, 'until' => $until, 'fs' => (int)$session['require_fullscreen'], 'id' => $context['id']]);
            if ($renewing) {
                $stmt = $this->db->prepare('UPDATE quiz_tracking_contexts SET proof_status=:status,proof_until=:until WHERE id=:id');
                $stmt->execute(['status' => $healthy ? 'healthy' : 'failed', 'until' => $until, 'id' => $context['id']]);
                if (!$healthy) {
                    $stmt = $this->db->prepare('UPDATE quiz_tracking_contexts SET pulse_failure_checks_json=:checks,pulse_failure_fullscreen_required=:fs WHERE id=:id');
                    $stmt->execute(['checks' => json_encode($checks, JSON_THROW_ON_ERROR), 'fs' => (int)$session['require_fullscreen'], 'id' => $context['id']]);
                    $this->trackingTerminatePending((int)$context['id'], 'pulse');
                }
            }
            $this->trackingDiagnostic($context, 'pulse_' . $outcome, 'pulse', 'client_reported', $checks);
            $this->browserConsumeAssessment($context,$checked['assessment'],'pulse');
            $result = $this->buildStatePayload($session, $attempt);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    private function trackingDiagnostic(array $context,string $code,string $operation,string $provenance,?array $checks=null): void
    {
        $codes=['proof_missing','proof_expired','preflight_healthy','preflight_failed','pulse_healthy','pulse_failed','pulse_diagnostic_only','proof_terminated_new_launch','proof_terminated_stopped','proof_terminated_reset','proof_terminated_closed','proof_terminated_mode_changed','proof_terminated_fullscreen_added','challenge_kind_mismatch','proof_incarnation_mismatch','challenge_expired','challenge_replayed','challenge_terminated','challenge_mismatch','document_epoch_mismatch','cookie_context_mismatch','attempt_mismatch','generation_mismatch','settings_revision_mismatch','schema_invalid','body_too_large','csrf_invalid','invalid_tracking_state','database_failure'];
        $codes=array_merge($codes,['preflight_diagnostic_only','browser_environment_changed','browser_verification_required']);
        if(!in_array($code,$codes,true)||!in_array($operation,['room','state','challenge','preflight','pulse_challenge','pulse','finish','resume','policy'],true)||!in_array($provenance,['client_reported','server_observed'],true)) {
            throw new RuntimeException('invalid_tracking_diagnostic');
        }
        $json=$checks!==null?json_encode($checks,JSON_THROW_ON_ERROR):null;
        if($json!==null && strlen($json)>2048) {
            throw new RuntimeException('invalid_tracking_diagnostic');
        }
        $stmt=$this->db->prepare('INSERT INTO quiz_tracking_diagnostics(session_id,student_id,attempt_id,context_ref,tracking_generation,first_name,last_name,created_at,code,operation,provenance,checks_json,identity_truncated) VALUES(:sid,:student,:aid,:ref,:gen,:first,:last,:now,:code,:op,:provenance,:checks,:reduced)');
        $stmt->execute(['sid'=>$context['session_id'],'student'=>$context['student_id'],'aid'=>$context['attempt_id'],'ref'=>$context['context_ref'],'gen'=>$context['tracking_generation'],'first'=>$context['first_name'],'last'=>$context['last_name'],'now'=>$this->now(),'code'=>$code,'op'=>$operation,'provenance'=>$provenance,'checks'=>$json,'reduced'=>$context['identity_truncated']]);
    }

    public function recordTrackingRefusal(string $operation,string $code,?Throwable $error=null): void
    {
        $allowed=['challenge_kind_mismatch','proof_incarnation_mismatch','challenge_expired','challenge_replayed','challenge_terminated','challenge_mismatch','document_epoch_mismatch','cookie_context_mismatch','attempt_mismatch','generation_mismatch','settings_revision_mismatch','schema_invalid','body_too_large','csrf_invalid','invalid_tracking_state','database_failure'];
        $allowed=array_merge($allowed,['browser_environment_changed','browser_verification_required']);
        if(!in_array($code,$allowed,true)) {
            $code='schema_invalid';
        }
        $operation=in_array($operation,['state','challenge','preflight','pulse_challenge','pulse','finish','resume','room'],true)?$operation:'state';
        $recorded=false;
        try {
            $context=$this->trackingCurrentContext();
            if($context!==null && (int)$context['attempt_id']===($_SESSION['quiz_attempt_id']??null)) {
                $this->trackingDiagnostic($context,$code,$operation,'server_observed');
                $recorded=true;
            }
        }catch(Throwable $ignored) {
        }
        if($recorded && $error===null) {
            return;
        }
        error_log('Quiz tracking refusal: op='.$operation.'; code='.$code.'; utc='.$this->now().($error!==null?'; exception='.get_class($error).'; sqlstate='.preg_replace('/[^A-Z0-9]/','',substr((string)$error->getCode(),0,8)).'; file='.$error->getFile().'; line='.$error->getLine():''));
    }

    private function invalidateTrackingSession(int $sessionId,string $kind): void
    {
        $this->invalidateBrowserSession($sessionId,$kind);
        $stmt=$this->db->prepare("UPDATE quiz_tracking_challenges SET terminated_at=:now WHERE context_id IN (SELECT id FROM quiz_tracking_contexts WHERE session_id=:sid) AND consumed_at IS NULL AND terminated_at IS NULL");
        $stmt->execute(['now'=>$this->now(),'sid'=>$sessionId]);
        $last=0;
        do {
            $stmt=$this->db->prepare("SELECT * FROM quiz_tracking_contexts WHERE session_id=:sid AND status='active' AND proof_status<>'terminated' AND id>:last ORDER BY id LIMIT 50");
            $stmt->execute(['sid'=>$sessionId,'last'=>$last]);
            $rows=$stmt->fetchAll();
            $stmt->closeCursor();
            foreach($rows as $row) {
                $last=(int)$row['id'];
                $stmt=$this->db->prepare("UPDATE quiz_tracking_contexts SET phase='pending',proof_status='terminated',proof_until=NULL WHERE id=:id");
                $stmt->execute(['id'=>$row['id']]);
                $this->trackingDiagnostic($row,'proof_terminated_'.$kind,'policy','server_observed');
            }
        }while(count($rows)===50);
    }

    public function setTrackingMode(int $sessionId,string $mode,int $expectedSettingsRevision,string $reason): void
    {
        $this->currentAdminId();
        $reason=$this->normalizeAccessReason($reason);
        if(!in_array($mode,['off','preflight','continuous'],true)) {
            throw new RuntimeException('invalid_tracking_mode');
        }
        $this->db->beginTransaction();
        try {
            $this->lockTeacherSession($sessionId);
            $session=$this->requireSession($sessionId);
            if($mode==='off' && !empty($session['browser_enabled'])){throw new RuntimeException('browser_requires_tracking');}
            if((int)$session['settings_revision']!==$expectedSettingsRevision) {
                throw new RuntimeException('settings_revision_mismatch');
            }
            if($session['tracking_mode']===$mode) {
                throw new RuntimeException('tracking_mode_unchanged');
            }
            $this->invalidateTrackingSession($sessionId,'mode_changed');
            $stmt=$this->db->prepare('UPDATE quiz_sessions SET tracking_mode=:mode,settings_revision=settings_revision+1 WHERE id=:id');
            $stmt->execute(['mode'=>$mode,'id'=>$sessionId]);
            $this->appendAudit($sessionId,'tracking_mode_changed',['tracking_mode'=>$session['tracking_mode'],'settings_revision'=>(int)$session['settings_revision']],['tracking_mode'=>$mode,'settings_revision'=>$expectedSettingsRevision+1,'reason'=>$reason]);
            $this->db->commit();
        }catch(Throwable $e) {
            if($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function privateTrackingContext(array $row): array
    {
        $fields=['id','context_ref','session_id','student_id','attempt_id','tracking_generation','first_name','last_name','status','phase','created_at','terminated_at','termination_kind','proof_status','proof_until','proof_issued_at','proof_fullscreen_required','admitted_at','identity_truncated','last_pulse_at','last_pulse_outcome','last_pulse_until','last_pulse_fullscreen_required','pulse_failure_fullscreen_required'];
        $out=array_intersect_key($row,array_fill_keys($fields,true));
        $out['checks']=$row['checks_json']!==null?json_decode($row['checks_json'],true):null;
        $out['checks_provenance']=$out['checks']!==null?'client_reported':null;
        $out['last_pulse_checks']=$row['last_pulse_checks_json']!==null?json_decode($row['last_pulse_checks_json'],true):null;
        $out['last_pulse_provenance']=$out['last_pulse_checks']!==null?'client_reported':null;
        $out['pulse_failure_checks']=$row['pulse_failure_checks_json']!==null?json_decode($row['pulse_failure_checks_json'],true):null;
        $out['browser_validation']=['status'=>$row['browser_status'],'assessed_at'=>$row['browser_assessed_at'],'details'=>$row['browser_diagnostic_json']!==null?json_decode($row['browser_diagnostic_json'],true,32,JSON_THROW_ON_ERROR):null];
        $out['causes']=[];
        if($row['proof_status']==='failed' && is_array($out['checks'])) {
            $mapping=['listener_roundtrip'=>'listener_probe_failed','trusted_enter_received'=>'enter_not_observed','hidden_received'=>'hidden_not_observed','visible_after_hidden_received'=>'return_not_observed','focus_after_hidden_received'=>'focus_not_observed'];
            if((int)$row['proof_fullscreen_required']===1) {
                $mapping+=['fullscreen_change_received'=>'fullscreen_not_observed','fullscreen_active'=>'fullscreen_inactive'];
            }
            foreach($mapping as $check=>$cause) {
                if(($out['checks'][$check]??false)!==true) {
                    $out['causes'][]=$cause;
                }
            }
            if(!$out['checks']['hidden_received'] && ($out['checks']['visible_after_hidden_received'] || $out['checks']['focus_after_hidden_received'])) {
                $out['causes'][]='sequence_inconsistent';
            }
            if (is_array($out['pulse_failure_checks'])) {
                if (!$out['pulse_failure_checks']['listener_roundtrip']) { $out['causes'][]='listener_probe_failed'; }
                if (!empty($row['pulse_failure_fullscreen_required']) && !$out['pulse_failure_checks']['fullscreen_active']) { $out['causes'][]='fullscreen_inactive'; }
            }
            $out['causes']=array_values(array_unique($out['causes']));
            if ($out['causes']===[]) { $out['causes'][]='proof_failed'; }
        }elseif($row['proof_status']!=='healthy') {
            $out['causes'][]='proof_'.$row['proof_status'];
        }
        return $out;
    }

    public function refreshTrackingProofs(int $sessionId): void
    {
        $this->currentAdminId();
        $this->requireSession($sessionId);
        $owns=!$this->db->inTransaction();
        if($owns) {
            $this->db->beginTransaction();
        }
        try {
            $this->lockTeacherSession($sessionId);
            $this->trackingDecisionTime=null;
            $last=0;
            do {
                $stmt=$this->db->prepare("SELECT * FROM quiz_tracking_contexts WHERE session_id=:sid AND status='active' AND proof_status='healthy' AND id>:last ORDER BY id LIMIT 50");
                $stmt->execute(['sid'=>$sessionId,'last'=>$last]);
                $rows=$stmt->fetchAll();
                $stmt->closeCursor();
                foreach($rows as $row) {
                    $last=(int)$row['id'];
                    $this->trackingExpireProof($row);
                }
            }while(count($rows)===50);
            if($owns) {
                $this->db->commit();
            }
        }catch(Throwable $e) {
            if($owns&&$this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function trackingSnapshotContexts(int $sessionId,int $attemptId): array
    {
        // archiveAttempts already observed expiry before copying any attempt.
        $stmt=$this->db->prepare('SELECT COUNT(*) FROM quiz_tracking_contexts WHERE session_id=:sid AND attempt_id=:aid');
        $stmt->execute(['sid'=>$sessionId,'aid'=>$attemptId]);
        if((int)$stmt->fetchColumn()>256) {
            throw new RuntimeException('archive_tracking_context_limit');
        }
        $stmt=$this->db->prepare('SELECT * FROM quiz_tracking_contexts WHERE session_id=:sid AND attempt_id=:aid ORDER BY id LIMIT 256');
        $stmt->execute(['sid'=>$sessionId,'aid'=>$attemptId]);
        $rows=[];
        while($row=$stmt->fetch()) {
            $rows[]=$this->privateTrackingContext($row);
        }
        return $rows;
    }

    public function getTrackingContexts(int $sessionId,int $attemptId,int $beforeId=0,int $limit=25): array
    {
        $this->refreshTrackingProofs($sessionId);
        $limit=max(1,min(50,$limit));
        $stmt=$this->db->prepare('SELECT * FROM quiz_tracking_contexts WHERE session_id=:sid AND attempt_id=:aid'.($beforeId>0?' AND id<:before':'').' ORDER BY id DESC LIMIT '.($limit+1));
        $args=['sid'=>$sessionId,'aid'=>$attemptId];
        if($beforeId>0) {
            $args['before']=$beforeId;
        }
        $stmt->execute($args);
        $rows=$stmt->fetchAll();
        $more=count($rows)>$limit;
        $rows=array_map(fn(array $r):array=>$this->privateTrackingContext($r),array_slice($rows,0,$limit));
        return ['rows'=>$rows,'next_before'=>$more?(int)end($rows)['id']:null];
    }

    public function listTrackingDiagnostics(int $sessionId,int $beforeId=0,int $limit=25): array
    {
        $this->refreshTrackingProofs($sessionId);
        $limit=max(1,min(50,$limit));
        $stmt=$this->db->prepare('SELECT * FROM quiz_tracking_diagnostics WHERE session_id=:sid'.($beforeId>0?' AND id<:before':'').' ORDER BY id DESC LIMIT '.($limit+1));
        $args=['sid'=>$sessionId];
        if($beforeId>0) {
            $args['before']=$beforeId;
        }
        $stmt->execute($args);
        $rows=$stmt->fetchAll();
        $more=count($rows)>$limit;
        $rows=array_slice($rows,0,$limit);
        foreach($rows as &$row) {
            $row['checks']=$row['checks_json']!==null?json_decode($row['checks_json'],true):null;
            unset($row['checks_json']);
        }
        unset($row);
        return ['rows'=>$rows,'next_before'=>$more?(int)end($rows)['id']:null];
    }
    /** Iterate complete private history without loading every context/result into memory. */

    private function csvTrackingRecords($out, int $sessionId): void
    {
        $stmt = $this->db->prepare('SELECT * FROM quiz_tracking_contexts WHERE session_id=:sid ORDER BY id');
        $stmt->execute(['sid' => $sessionId]);
        while ($row = $stmt->fetch())  {
            $this->csvTrackingRecord($out, 'contexte_suivi', $this->privateTrackingContext($row), ['suivi_courant']);
        }
        $stmt->closeCursor();
        $stmt = $this->db->prepare('SELECT * FROM quiz_tracking_diagnostics WHERE session_id=:sid ORDER BY id');
        $stmt->execute(['sid' => $sessionId]);
        while ($row = $stmt->fetch())  {
            $row['checks'] = $row['checks_json'] !== null ? json_decode($row['checks_json'], true) : null;
            unset($row['checks_json']);
            $this->csvTrackingRecord($out, 'diagnostic_suivi', $row, ['diagnostic']);
        }
        $stmt->closeCursor();
    }

    private function csvTrackingRecord($out, string $type, array $context, array $metadata): void
    {
        $row = array_fill(0, 23, '');
        $row[0] = $type;
        $row[1] = $context['last_name'];
        $row[2] = $context['first_name'];
        $row[8] = $context['proof_status'] ?? '';
        $row[12] = $context['code'] ?? '';
        $row[14] = $this->toParisTime($context['created_at']);
        $row[22] = $context['tracking_generation'];
        $metadata = array_pad($metadata, 10, '');
        $key=$type==='diagnostic_navigateur'?'browser_diagnostic':($type==='diagnostic_suivi'?'tracking_diagnostic':'tracking_context');
        $metadata[9] = json_encode([$key => $context], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->csvRow($out, $row, $metadata);
    }
}
