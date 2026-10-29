<?php
declare(strict_types=1);
namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class AssessmentSubmissionService
{
    public static function validate(array $input, string $platform = 'github'): array
    {
        $errors = [];
        $values = ['nom' => '', 'prenom' => '', 'github_url' => ''];
        foreach (['nom' => 'nom', 'prenom' => 'prénom'] as $key => $label) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || preg_match('/[\p{C}]/u', $value) !== 0) {
                $errors[] = 'Vérifiez votre ' . $label . '.';
                continue;
            }
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));
            $values[$key] = $value;
            if (!preg_match("/\A[\p{L}\p{M} .’'-]{1,80}\z/u", $value) || !preg_match('/\p{L}/u', $value)) {
                $errors[] = 'Indiquez votre ' . $label . ' (80 caractères maximum).';
            }
        }
        $url = $input['github_url'] ?? '';
        if (is_string($url)) {
            $url = trim($url);
            $values['github_url'] = $url;
        }
        $pattern = '~\Ahttps://(?:www\.)?github\.com/([a-z0-9](?:[a-z0-9-]{0,37}[a-z0-9])?)/([a-z0-9_.-]{1,100})/?\z~i';
        if ($platform === 'gitlab') {
            $pattern = '~\Ahttps://gitlab\.com/([a-z0-9][a-z0-9_.-]{0,254}(?:/[a-z0-9][a-z0-9_.-]{0,254})*)/([a-z0-9][a-z0-9_.-]{0,254})/?\z~i';
        }
        if (!is_string($url) || !preg_match($pattern, $url, $match) || in_array($match[2], ['.', '..'], true)) {
            $errors[] = $platform === 'gitlab' ? 'Indiquez l’URL du projet : https://gitlab.com/compte/projet (pas celle du profil).' : 'Indiquez l’URL du dépôt : https://github.com/compte/depot (pas celle du profil).';
        } else {
            $repo = $match[2];
            if (strlen($repo) > 4 && substr($repo, -4) === '.git') {
                $repo = substr($repo, 0, -4);
            }
            $values['github_url'] = ($platform === 'gitlab' ? 'https://gitlab.com/' : 'https://github.com/') . $match[1] . '/' . $repo;
        }
        return ['values' => $values, 'errors' => $errors];
    }

    public static function forFolder(string $path, string $storage): ?array
    {
        $course = null;
        foreach (AssessmentRegistry::load($storage) as $definition) {
            if ($definition['scope'] === $path && $definition['submission']) { $course = $definition; break; }
        }
        if ($course === null) { return null; }
        self::requireGrant($path, $storage);
        $key = 'assessment_submission_' . $course['id'];
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }
        $session = &$_SESSION[$key];
        if (!isset($session['csrf'])) {
            $session['csrf'] = bin2hex(random_bytes(32));
        }
        if (!isset($session['nonce'])) {
            $session['nonce'] = bin2hex(random_bytes(16));
        }
        $state = [
            'course' => $course, 'action' => '/' . $path,
            'csrf' => $session['csrf'], 'nonce' => $session['nonce'],
            'errors' => [], 'values' => ['nom' => '', 'prenom' => '', 'github_url' => ''],
            'receipt' => $session['receipt'] ?? null,
            'platform' => $course['platform'] ?? 'github',
        ];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
            || ($_POST['cc_submission'] ?? '') !== '1') {
            return $state;
        }
        $csrf = $_POST['submission_csrf'] ?? '';
        $nonce = $_POST['submission_nonce'] ?? '';
        if (!is_string($csrf) || !hash_equals($session['csrf'], $csrf)) {
            http_response_code(403);
            $state['errors'] = ['La page a expiré. Rechargez-la avant d’envoyer votre remise.'];
            return $state;
        }
        // A browser retry of the last accepted POST returns the same receipt.
        if (is_string($nonce) && isset($session['last_nonce'])
            && hash_equals($session['last_nonce'], $nonce)) {
            self::redirect($path);
        }
        if (!is_string($nonce) || !hash_equals($session['nonce'], $nonce)) {
            http_response_code(422);
            $state['errors'] = ['Ce formulaire n’est plus à jour. Rechargez la page.'];
            return $state;
        }
        $validated = self::validate($_POST, $course['platform'] ?? 'github');
        $state['values'] = $validated['values'];
        $state['errors'] = $validated['errors'];
        if ($state['errors'] !== []) {
            http_response_code(422);
            return $state;
        }
        try {
            $record = self::save($storage, $course, $nonce, $validated['values']);
        } catch (RuntimeException $exception) {
            error_log('Assessment submission storage failure: ' . $exception->getMessage());
            http_response_code(503);
            $state['errors'] = ['Votre remise n’a pas pu être enregistrée. Réessayez ou prévenez votre enseignant.'];
            return $state;
        }
        $session['receipt'] = $record;
        $session['last_nonce'] = $nonce;
        unset($session['nonce']);
        self::redirect($path);
    }

    private static function requireGrant(string $path, string $storage): void
    {
        foreach (AssessmentRegistry::load($storage) as $definition) {
            if ($definition['scope'] === $path && $definition['submission'] && $definition['access'] === 'public') { return; }
        }
        $assessments = CourseAccessService::requiredAssessments($path, false, $storage);
        if (count($assessments) !== 1) {
            throw new RuntimeException('Assessment submission scope unavailable');
        }
        $assessment = $assessments[0];
        $recordFile = $storage . '/' . $assessment['record'];
        $record = is_file($recordFile) ? json_decode((string) file_get_contents($recordFile), true) : null;
        $hash = is_array($record) ? ($record['password_hash'] ?? null) : null;
        $grant = $_SESSION[$assessment['session']] ?? null;
        if (!is_string($hash) || strlen($hash) < 40 || !is_array($grant)
            || ($grant['expires'] ?? 0) <= time()
            || !hash_equals(hash('sha256', $hash), (string) ($grant['version'] ?? ''))) {
            throw new RuntimeException('Assessment submission access denied');
        }
    }

    private static function save(string $storage, array $course, string $nonce, array $values): array
    {
        $directory = rtrim($storage, '/') . '/remises-cc/' . $course['id'];
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create submission directory');
        }
        // The token is created by the server. Names never become filesystem paths.
        $file = $directory . '/' . $nonce . '.json';
        if (is_file($file)) {
            $existing = json_decode((string) file_get_contents($file), true);
            if (!is_array($existing) || ($existing['assessment'] ?? '') !== $course['id']) {
                throw new RuntimeException('Invalid existing submission record');
            }
            return $existing;
        }
        $record = [
            'assessment' => $course['id'],
            'reference' => $course['id'] . '-' . strtoupper(substr($nonce, 0, 12)),
            'received_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DATE_ATOM),
            'nom' => $values['nom'], 'prenom' => $values['prenom'],
            'github_url' => $values['github_url'],
        ];
        $data = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = tempnam($directory, '.pending-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot allocate submission file');
        }
        try {
            chmod($temporary, 0600);
            if (file_put_contents($temporary, $data, LOCK_EX) !== strlen($data)) {
                throw new RuntimeException('Cannot write submission record');
            }
            if (!rename($temporary, $file)) {
                throw new RuntimeException('Cannot finalize submission record');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary); // Only the temporary file created above.
            }
        }
        return $record;
    }

    private static function redirect(string $path): void
    {
        header('Location: /' . $path . '#remise', true, 303);
        exit;
    }
}
