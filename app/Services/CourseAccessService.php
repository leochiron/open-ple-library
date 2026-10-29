<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;

final class CourseAccessService
{
    public static function requiredAssessments(string $path, bool $download, ?string $storage = null): array
    {
        $required = [];
        foreach (AssessmentRegistry::load($storage) as $assessment) {
            if ($assessment['access'] !== 'protected') { continue; }
            $scope = $assessment['scope'];
            $within = $path === $scope || strncmp($path, $scope . '/', strlen($scope) + 1) === 0;
            $ancestorArchive = $download && ($path === '' || strncmp($scope, $path . '/', strlen($path) + 1) === 0);
            if ($within || $ancestorArchive) {
                $required[] = $assessment;
            }
        }
        return $required;
    }

    public static function requiresAccess(string $path, bool $download, ?string $storage = null): bool
    {
        return self::requiredAssessments($path, $download, $storage) !== [];
    }

    public static function enforce(SecurityService $security, I18nService $i18n, array $config): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = preg_replace('~^/(?:public/)?index\.php(?=/|$)~', '', $uri);
        $uri = rawurldecode((string) $uri);
        $download = preg_match('~\+download/?$~', $uri) === 1;
        $uri = preg_replace('~\+(?:download|open)/?$~', '', $uri);
        try {
            $path = $security->validateRelativePath($uri);
        } catch (\InvalidArgumentException $e) {
            return; // The normal router rejects invalid paths.
        }
        $storage = $config['storage_path'] ?? dirname(__DIR__, 2) . '/storage';
        $required = self::requiredAssessments($path, $download, $storage);
        if ($required === []) {
            return;
        }

        // Run before raw Markdown, previews and downloads. Override subsequent
        // resource headers so an unlocked response cannot enter a shared cache.
        header_register_callback(static function (): void {
            header_remove('Expires');
            header('Cache-Control: private, no-store, max-age=0');
            header('Pragma: no-cache');
            header('X-Robots-Tag: noindex, noarchive');
            header('Vary: Cookie');
        });
        foreach ($required as $assessment) {
        $record = is_file($storage . '/' . $assessment['record'])
            ? json_decode((string) file_get_contents($storage . '/' . $assessment['record']), true)
            : null;
        $hash = is_array($record) ? ($record['password_hash'] ?? '') : '';
        $configured = is_string($hash) && strlen($hash) > 40;
        if (($_GET['cc_lock'] ?? '') === '1') {
            unset($_SESSION[$assessment['session']]);
            header('Location: /' . $assessment['scope'], true, 303);
            exit;
        }
        $grant = $_SESSION[$assessment['session']] ?? null;
        if ($configured && is_array($grant)
            && ($grant['expires'] ?? 0) > time()
            && hash_equals(hash('sha256', $hash), (string) ($grant['version'] ?? ''))) {
            continue;
        }
        unset($_SESSION[$assessment['session']]);
        if (!isset($_SESSION[$assessment['session'] . '_csrf']) || !is_string($_SESSION[$assessment['session'] . '_csrf'])) {
            $_SESSION[$assessment['session'] . '_csrf'] = bin2hex(random_bytes(32));
        }
        $error = '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'POST') {
            $csrf = $_POST['cc_csrf'] ?? '';
            $password = $_POST['cc_password'] ?? '';
            if (!is_string($csrf) || !hash_equals($_SESSION[$assessment['session'] . '_csrf'], $csrf)) {
                $error = 'La page a expiré. Rechargez-la avant de réessayer.';
            } elseif (!$configured) {
                $error = 'Le contrôle continu reste fermé. Attendez le lancement par votre enseignant.';
            } elseif (!is_string($password) || strlen($password) > 256) {
                $error = 'Mot de passe incorrect.';
            } else {
                $attempt = self::checkPassword($storage, $password, $hash, $assessment['scope']);
                if ($attempt === 'accepted') {
                    session_regenerate_id(true);
                    $_SESSION[$assessment['session']] = [
                        'expires' => time() + 8 * 3600,
                        'version' => hash('sha256', $hash),
                    ];
                    // Reinforce the shared session cookie on the protected flow.
                    $cookie = session_get_cookie_params();
                    setcookie(session_name(), session_id(), [
                        'expires' => 0, 'path' => $cookie['path'] ?: '/',
                        'domain' => $cookie['domain'], 'secure' => true,
                        'httponly' => true, 'samesite' => 'Lax',
                    ]);
                    header('Location: /' . $assessment['scope'], true, 303);
                    exit;
                }
                if ($attempt === 'limited') {
                    http_response_code(429);
                    header('Retry-After: 300');
                    $error = 'Trop de tentatives. Attendez cinq minutes avant de réessayer.';
                } else {
                    $error = 'Mot de passe incorrect.';
                }
            }
        }
        if (http_response_code() !== 429) {
            http_response_code(403);
        }
        header('Content-Type: text/html; charset=utf-8');
        render('library/protected', [
            'error' => $error,
            'csrf' => $_SESSION[$assessment['session'] . '_csrf'],
            'accessAction' => '/' . $assessment['scope'],
            'title' => $assessment['title'],
            'accessLabel' => $assessment['label'],
            'accessType' => $assessment['type'] ?? 'Contrôle continu',
            'returnPath' => $assessment['return'],
        ], $i18n, $config);
        exit;
        }
    }

    private static function checkPassword(string $storage, string $password, string $hash, string $scope): string
    {
        $directory = $storage . '/course-access-attempts';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Course access storage unavailable');
        }
        // REMOTE_ADDR is supplied by the server; never trust a client header.
        $key = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . $scope);
        $file = $directory . '/' . $key . '.json';
        $stream = fopen($file, 'c+');
        if ($stream === false || !flock($stream, LOCK_EX)) {
            throw new RuntimeException('Course access limiter unavailable');
        }
        chmod($file, 0600);
        try {
            $attempts = json_decode(stream_get_contents($stream), true);
            $attempts = is_array($attempts) ? $attempts : [];
            $attempts = array_values(array_filter($attempts, static fn($time): bool => is_int($time) && $time > time() - 300));
            if (count($attempts) >= 10) {
                return 'limited';
            }
            if (password_verify($password, $hash)) {
                $attempts = [];
                $result = 'accepted';
            } else {
                $attempts[] = time();
                $result = 'rejected';
            }
            rewind($stream);
            ftruncate($stream, 0);
            fwrite($stream, json_encode($attempts));
            fflush($stream);
            return $result;
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }
}
