<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;

final class AssessmentFeedbackService
{
    public static function forFolder(string $path, string $storage): ?array
    {
        $assessment = null;
        foreach (AssessmentRegistry::load($storage) as $id => $definition) {
            $course = $definition['feedback'];
            if ($course === null) { continue; }
            if (in_array($path, [$course['subject'], $course['returns']], true)) { $assessment = $id; break; }
        }
        if ($assessment === null) { return null; }
        $definition = AssessmentRegistry::load($storage)[$assessment];
        $course = $definition['feedback'];
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, noarchive');
        header('Vary: Cookie');
        $key = 'assessment_feedback_' . strtolower(str_replace('-', '_', $assessment)) . '_csrf';
        if (!is_string($_SESSION[$key] ?? null)) { $_SESSION[$key] = bin2hex(random_bytes(32)); }
        $state = ['csrf' => $_SESSION[$key], 'action' => '/' . $course['returns'], 'return' => $definition['return'], 'is_returns' => $path === $course['returns'], 'email' => '', 'error' => '', 'result' => null];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || ($_POST['assessment_feedback'] ?? '') !== '1') { return $state; }
        if (!is_string($_POST['feedback_csrf'] ?? null) || !hash_equals($state['csrf'], $_POST['feedback_csrf'])) {
            http_response_code(403); $state['error'] = 'La page a expiré. Rechargez-la avant de réessayer.'; return $state;
        }
        $raw = $_POST['feedback_email'] ?? '';
        $state['email'] = is_string($raw) ? substr(trim($raw), 0, 254) : '';
        if (!is_string($raw) || strlen(trim($raw)) > 254 || !filter_var(trim($raw), FILTER_VALIDATE_EMAIL)) {
            http_response_code(422); $state['error'] = 'Indiquez une adresse mail valide.'; return $state;
        }
        try {
            if (!self::consumeAttempt($storage, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), time())) {
                http_response_code(429); header('Retry-After: 300');
                $state['error'] = 'Trop de consultations. Réessayez dans cinq minutes.'; return $state;
            }
            $state['result'] = self::lookup($storage, strtolower(trim($raw)), $assessment);
            if ($state['result'] === null) {
                $state['error'] = 'Aucun retour disponible pour cette adresse. Vérifiez votre adresse mail école ou contactez Léo.';
            }
        } catch (RuntimeException $e) {
            error_log('Assessment feedback unavailable: ' . $e->getMessage());
            http_response_code(503); $state['error'] = 'Les retours sont momentanément indisponibles. Réessayez plus tard.';
        }
        return $state;
    }

    public static function lookup(string $storage, string $email, string $assessment): ?array
    {
        if (empty(AssessmentRegistry::load($storage)[$assessment]['feedback'])) { throw new RuntimeException('Unknown assessment'); }
        $file = rtrim($storage, '/\\') . '/assessment-feedback/' . $assessment . '/results.json';
        if (!is_file($file) || !is_readable($file)) { throw new RuntimeException('Results not readable'); }
        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || ($data['assessment'] ?? '') !== $assessment || !is_array($data['results'] ?? null)) { throw new RuntimeException('Results invalid'); }
        $record = $data['results'][hash('sha256', strtolower(trim($email)))] ?? null;
        if ($record === null) { return null; }
        if (!is_array($record) || !is_string($record['html'] ?? null) || !is_string($record['student_id'] ?? null)) { throw new RuntimeException('Result invalid'); }
        // Only the selected student's record reaches the view. Email-only
        // consultation is an explicit publishing choice, not authentication.
        return $record;
    }

    public static function consumeAttempt(string $storage, string $ip, int $now): bool
    {
        $directory = rtrim($storage, '/\\') . '/assessment-feedback/attempts';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new RuntimeException('Limiter directory unavailable'); }
        $file = $directory . '/' . hash('sha256', $ip) . '.json';
        $stream = fopen($file, 'c+');
        if ($stream === false) { throw new RuntimeException('Limiter unavailable'); }
        try {
            if (!flock($stream, LOCK_EX)) { throw new RuntimeException('Limiter lock unavailable'); }
            chmod($file, 0600);
            $raw = stream_get_contents($stream);
            $attempts = $raw === false ? null : json_decode($raw, true);
            if ($raw !== '' && !is_array($attempts)) { throw new RuntimeException('Limiter invalid'); }
            $attempts = array_values(array_filter($attempts ?? [], static fn($stamp): bool => is_int($stamp) && $stamp > $now - 300));
            // The shared campus connection must support a whole class.
            if (count($attempts) >= 100) { return false; }
            $attempts[] = $now;
            $encoded = json_encode($attempts, JSON_THROW_ON_ERROR);
            rewind($stream);
            if (!ftruncate($stream, 0) || fwrite($stream, $encoded) !== strlen($encoded) || !fflush($stream)) { throw new RuntimeException('Limiter write failed'); }
            return true;
        } finally { flock($stream, LOCK_UN); fclose($stream); }
    }
}
