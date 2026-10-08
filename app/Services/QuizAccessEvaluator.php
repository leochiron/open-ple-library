<?php
declare(strict_types=1);
namespace App\Services;

use RuntimeException;

/** Internal domain decision. Causes are server diagnostics, never proof of fraud. */
final class QuizAccessEvaluator
{
    public static function normalizeScopes(array $scopes): array
    {
        if ($scopes === [] || count($scopes) > 2) { throw new RuntimeException('invalid_override_scopes'); }
        $normalized = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !in_array($scope, ['browser', 'tracking'], true) || in_array($scope, $normalized, true)) { throw new RuntimeException('invalid_override_scopes'); }
            $normalized[] = $scope;
        }
        sort($normalized);
        return $normalized;
    }

    public static function evaluate(bool $manualBlocked, array $causes, array $scopes = []): bool
    {
        $scopes = $scopes !== [] ? self::normalizeScopes($scopes) : [];
        $unmasked = false;
        foreach ($causes as $cause) {
            if (!is_array($cause) || !is_string($cause['scope'] ?? null) || !in_array($cause['scope'], ['browser', 'tracking'], true) || !is_bool($cause['active'] ?? null)) {
                throw new RuntimeException('invalid_technical_cause');
            }
            if ($cause['active'] && !in_array($cause['scope'], $scopes, true)) { $unmasked = true; }
        }
        return !$manualBlocked && !$unmasked;
    }
}
