<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Pure, declared-product policy evaluation. No executable identity or raw UA retention. */
final class QuizBrowserEvaluator
{
    public const FAMILIES = ['chrome', 'edge', 'firefox', 'safari'];
    public const CAPABILITIES = ['event_target', 'visibility_api', 'focus_api', 'fetch_api', 'abort_controller', 'monotonic_clock', 'fullscreen'];

    public static function normalizeFamilies(array $families): array
    {
        if (!array_is_list($families) || $families === [] || count($families) > 4) { throw new RuntimeException('invalid_browser_policy'); }
        foreach ($families as $family) { if (!is_string($family) || !in_array($family, self::FAMILIES, true)) { throw new RuntimeException('invalid_browser_policy'); } }
        if(count(array_unique($families))!==count($families)){throw new RuntimeException('invalid_browser_policy');}
        return array_values(array_intersect(self::FAMILIES, $families));
    }

    public static function boundedString(mixed $value, int $limit): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $limit || !mb_check_encoding($value, 'UTF-8') || preg_match('/\p{Cc}/u', $value)) { throw new RuntimeException('schema_invalid'); }
        return $value;
    }

    public static function normalizeEnvelope(array $browser): array
    {
        if (count($browser) !== 3 || array_diff(array_keys($browser), ['user_agent', 'brands', 'capabilities']) !== []) { throw new RuntimeException('schema_invalid'); }
        $ua = self::boundedString($browser['user_agent'] ?? null, 1024);
        $brands = $browser['brands'] ?? null;
        if (!array_key_exists('brands', $browser) || ($brands !== null && (!is_array($brands) || !array_is_list($brands) || count($brands) > 12))) { throw new RuntimeException('schema_invalid'); }
        foreach ($brands ?? [] as $brand) {
            if (!is_array($brand) || count($brand) !== 2 || array_diff(array_keys($brand), ['brand', 'version']) !== []) { throw new RuntimeException('schema_invalid'); }
            self::boundedString($brand['brand'] ?? null, 64); self::boundedString($brand['version'] ?? null, 24);
        }
        $caps = $browser['capabilities'] ?? null;
        if (!is_array($caps) || count($caps) !== 7 || array_diff(array_keys($caps), self::CAPABILITIES) !== []) { throw new RuntimeException('schema_invalid'); }
        $ordered = [];
        foreach (self::CAPABILITIES as $key) { if (!is_bool($caps[$key] ?? null)) { throw new RuntimeException('schema_invalid'); } $ordered[$key] = $caps[$key]; }
        return ['user_agent' => $ua, 'brands' => $brands, 'capabilities' => $ordered];
    }

    private static function major(string $value): ?int
    {
        if (preg_match('/^([1-9][0-9]{0,8})(?:\.[0-9]+)*$/D', $value, $match) !== 1) { return null; }
        return (int)$match[1];
    }

    public static function parse(string $ua): array
    {
        self::boundedString($ua, 1024);
        // Explicit competing products must never fall through to their engine's Chrome/Safari token.
        foreach (['outside_policy' => '(?:OPR|OPT|Opera|Edge)', 'edge' => '(?:Edg|EdgA|EdgiOS)', 'firefox' => '(?:Firefox|FxiOS)', 'chrome' => '(?:Chrome|CriOS)'] as $family => $token) {
            if (preg_match('~(?:^|[\s;(])'.$token.'/([^\s;)]+)~', $ua, $match) === 1) {
                $major = self::major($match[1]);
                return ['family' => $major !== null ? $family : 'unknown', 'major' => $major];
            }
        }
        if (preg_match('~(?:^|[\s;(])Safari/~', $ua) && preg_match('~(?:^|[\s;(])Version/([^\s;)]+)~', $ua, $match)) {
            $major = self::major($match[1]); return ['family' => $major !== null ? 'safari' : 'unknown', 'major' => $major];
        }
        return ['family' => 'unknown', 'major' => null];
    }

    public static function policyFingerprint(array $policy): string
    {
        return hash('sha256', json_encode(['families' => self::normalizeFamilies($policy['allowed_families']), 'required' => self::requiredCapabilities($policy)], JSON_THROW_ON_ERROR));
    }

    public static function requiredCapabilities(array $policy): array
    {
        return !empty($policy['fullscreen_required']) ? self::CAPABILITIES : array_slice(self::CAPABILITIES, 0, 6);
    }

    /** Canon contains only product majors, known coherence and required capabilities/policy. */
    public static function evaluate(string $httpUa, array $browser, array $policy): array
    {
        $browser = self::normalizeEnvelope($browser);
        $http = self::parse($httpUa); $js = self::parse($browser['user_agent']);
        $consistent = $http === $js && self::coherentTokens($httpUa) && self::coherentTokens($browser['user_agent']); $products = [];
        foreach ($browser['brands'] ?? [] as $brand) {
            $family = ['Google Chrome' => 'chrome', 'Microsoft Edge' => 'edge'][$brand['brand']] ?? null;
            if ($family === null) { continue; }
            $major = self::major($brand['version']);
            if ($major === null || $family !== $http['family'] || $major !== $http['major'] || (isset($products[$family]) && $products[$family] !== $major)) { $consistent = false; }
            $products[$family] = $major;
        }
        $required = self::requiredCapabilities($policy); $observed = [];
        foreach ($required as $cap) { $observed[$cap] = $browser['capabilities'][$cap]; }
        $causes = [];
        if (!empty($policy['enabled'])) {
            if (!in_array($http['family'], $policy['allowed_families'], true) || !in_array($js['family'], $policy['allowed_families'], true)) { $causes[] = 'browser_outside_policy'; }
            if (!$consistent) { $causes[] = 'browser_info_inconsistent'; }
            if (in_array(false, $observed, true)) { $causes[] = 'required_capability_unavailable'; }
        }
        $canonical = ['http' => $http, 'http_tokens_consistent' => self::coherentTokens($httpUa), 'javascript' => $js, 'known_brands_consistent' => $consistent, 'required_capabilities' => $observed, 'policy_fingerprint' => self::policyFingerprint($policy)];
        return ['allowed' => $causes === [], 'causes' => $causes, 'canonical' => $canonical,
            'environment_fingerprint' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
            'observed_capabilities' => $browser['capabilities'], 'applied_policy'=>['enabled'=>!empty($policy['enabled']),'allowed_families'=>self::normalizeFamilies($policy['allowed_families']),'fullscreen_required'=>!empty($policy['fullscreen_required'])], 'provenance' => ['http' => 'server_observed', 'javascript' => 'client_reported', 'brands' => 'client_reported', 'capabilities' => 'client_reported']];
    }

    public static function coherentTokens(string $ua): bool
    {
        preg_match_all('~(?:^|[\s;(])(Chrome|CriOS|Edg|EdgA|EdgiOS|Firefox|FxiOS)/([^\s;)]+)~', $ua, $matches, PREG_SET_ORDER);
        $families = [];
        foreach ($matches as $match) {
            $family = in_array($match[1], ['Chrome', 'CriOS'], true) ? 'chrome' : (in_array($match[1], ['Firefox', 'FxiOS'], true) ? 'firefox' : 'edge');
            $major = self::major($match[2]);
            if (array_key_exists($family, $families) && $families[$family] !== $major) { return false; }
            $families[$family] = $major;
        }
        if (self::parse($ua)['family'] === 'safari') {
            preg_match_all('~(?:^|[\s;(])Version/([^\s;)]+)~', $ua, $versions, PREG_SET_ORDER);
            $majors = array_map(static fn(array $version): ?int => self::major($version[1]), $versions);
            if (count(array_unique($majors, SORT_REGULAR)) > 1) { return false; }
        }
        // Edge's Chrome engine is documented; Firefox plus either competing product is not.
        return !(isset($families['firefox']) && (isset($families['chrome']) || isset($families['edge'])));
    }
}
