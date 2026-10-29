<?php
declare(strict_types=1);
namespace App\Services;

use DateTimeImmutable;
use RuntimeException;

/** Per-deployment definitions live with private data, never in the shared code. */
final class AssessmentRegistry
{
    public static function load(?string $storage = null): array
    {
        $file = rtrim($storage ?? dirname(__DIR__, 2) . '/storage', '/\\') . '/assessments.json';
        if (!is_file($file)) { return []; }
        try { $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new RuntimeException('Assessment configuration is unreadable.', 0, $e); }
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['assessments'] ?? null)) {
            throw new RuntimeException('Invalid assessment configuration version.');
        }
        $result = []; $scopes = [];
        foreach ($data['assessments'] as $id => $definition) {
            if (!is_string($id) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/', $id) || !is_array($definition)) {
                throw new RuntimeException('Invalid assessment identifier.');
            }
            $scope = self::path($definition['scope'] ?? null);
            if (isset($scopes[$scope])) { throw new RuntimeException('Duplicate assessment scope.'); }
            $scopes[$scope] = true;
            if (!is_string($definition['title'] ?? null) || trim($definition['title']) === '') { throw new RuntimeException('Assessment title is required.'); }
            $return = $definition['return'] ?? '/';
            if (!is_string($return) || $return === '' || $return[0] !== '/' || strpos($return, '//') === 0) { throw new RuntimeException('Invalid assessment return path.'); }
            if ($return !== '/') { self::path(substr($return, 1)); }
            $access = $definition['access'] ?? 'protected';
            if (!in_array($access, ['public', 'protected'], true)) { throw new RuntimeException('Invalid assessment access mode.'); }
            $platform = $definition['platform'] ?? 'github';
            if (!in_array($platform, ['github', 'gitlab'], true)) { throw new RuntimeException('Invalid repository platform.'); }
            $record = self::path($definition['access_record'] ?? 'assessment-access/' . $id . '.json');
            $session = $definition['access_session'] ?? 'course_access_' . $id;
            if (!is_string($session) || !preg_match('/\A[a-zA-Z0-9_-]{1,120}\z/', $session)) { throw new RuntimeException('Invalid assessment session key.'); }
            if (isset($definition['deadline'])) {
                if (!is_string($definition['deadline']) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\z/', $definition['deadline'])) { throw new RuntimeException('Invalid assessment deadline.'); }
                try { $date = new DateTimeImmutable($definition['deadline']); }
                catch (\Throwable $e) { throw new RuntimeException('Invalid assessment deadline.', 0, $e); }
                if ($date->format(DATE_ATOM) !== $definition['deadline']) { throw new RuntimeException('Invalid assessment deadline.'); }
            }
            $feedback = $definition['feedback'] ?? null;
            if ($feedback !== null) {
                if (!is_array($feedback)) { throw new RuntimeException('Invalid feedback configuration.'); }
                $feedback = ['subject' => self::path($feedback['subject'] ?? $scope), 'returns' => self::path($feedback['returns'] ?? null)];
            }
            $result[$id] = array_replace($definition, [
                'id' => $id, 'scope' => $scope, 'title' => trim($definition['title']), 'return' => $return,
                'access' => $access, 'platform' => $platform, 'record' => $record, 'session' => $session,
                'label' => $definition['label'] ?? $definition['title'], 'submission' => ($definition['submission'] ?? false) === true,
                'feedback' => $feedback, 'document' => $definition['document'] ?? 'EXPLICATIONS.md',
            ]);
        }
        return $result;
    }

    private static function path($path): string
    {
        if (!is_string($path) || $path === '' || preg_match('~[\\\\%?#\x00-\x1f]~', $path)) { throw new RuntimeException('Invalid assessment path.'); }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || $part[0] === '.') { throw new RuntimeException('Invalid assessment path.'); }
        }
        return $path;
    }
}
