<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/** Resolves a canonical public origin without trusting arbitrary proxy headers. */
final class PublicUrlResolver
{
    private ?string $configuredBaseUrl;
    private string $environment;
    private bool $trustForwardedProto;
    /** @var string[] */
    private array $trustedProxyIps;

    private function __construct(?string $configuredBaseUrl, string $environment, bool $trustForwardedProto, array $trustedProxyIps)
    {
        $this->configuredBaseUrl = $configuredBaseUrl;
        $this->environment = $environment;
        $this->trustForwardedProto = $trustForwardedProto;
        $this->trustedProxyIps = $trustedProxyIps;
    }

    public static function fromConfig(array $config): self
    {
        $configured = $config['public_base_url'] ?? null;
        if ($configured !== null && !is_string($configured)) {
            throw new InvalidArgumentException('Invalid public URL configuration.');
        }
        $configured = is_string($configured) && trim($configured) !== ''
            ? self::normalizeConfiguredUrl($configured)
            : null;

        $environment = $config['environment'] ?? 'production';
        if (!is_string($environment) || !in_array($environment, ['production', 'development', 'testing'], true)) {
            throw new InvalidArgumentException('Invalid application environment.');
        }

        $trustForwardedProto = $config['trust_forwarded_proto'] ?? false;
        $trustedProxyIps = $config['trusted_proxy_ips'] ?? [];
        if (!is_bool($trustForwardedProto) || !is_array($trustedProxyIps)) {
            throw new InvalidArgumentException('Invalid proxy configuration.');
        }
        foreach ($trustedProxyIps as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('Invalid proxy configuration.');
            }
        }

        return new self($configured, $environment, $trustForwardedProto, array_values($trustedProxyIps));
    }

    public function resolve(array $server): string
    {
        if ($this->configuredBaseUrl !== null) {
            return $this->configuredBaseUrl;
        }
        if (!in_array($this->environment, ['development', 'testing'], true)) {
            throw new InvalidArgumentException('Public URL is not configured.');
        }

        $hostHeader = $server['HTTP_HOST'] ?? ($server['SERVER_NAME'] ?? null);
        if (!is_string($hostHeader)) {
            throw new InvalidArgumentException('Invalid public request host.');
        }
        $authority = self::normalizeAuthority($hostHeader);

        $scheme = self::serverUsesHttps($server) ? 'https' : 'http';
        $remoteAddress = $server['REMOTE_ADDR'] ?? '';
        $isTrustedProxy = $this->trustForwardedProto
            && is_string($remoteAddress)
            && in_array($remoteAddress, $this->trustedProxyIps, true);
        if ($isTrustedProxy && array_key_exists('HTTP_X_FORWARDED_PROTO', $server)) {
            $forwardedProto = $server['HTTP_X_FORWARDED_PROTO'];
            if (!is_string($forwardedProto) || !in_array($forwardedProto, ['http', 'https'], true)) {
                throw new InvalidArgumentException('Invalid forwarded protocol.');
            }
            $scheme = $forwardedProto;
        }

        return $scheme . '://' . $authority;
    }

    private static function normalizeConfiguredUrl(string $url): string
    {
        self::rejectControlCharacters($url);
        if (preg_match('#^https?://([^/]+)(?:/)?$#iD', $url, $authorityMatch) !== 1) {
            throw new InvalidArgumentException('Invalid public URL configuration.');
        }
        self::validateBracketSyntax($authorityMatch[1]);
        try {
            $parts = parse_url($url);
        } catch (\ValueError $exception) {
            $parts = false;
        }
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            throw new InvalidArgumentException('Invalid public URL configuration.');
        }

        $authority = self::formatHostAndPort((string)$parts['host'], $parts['port'] ?? null);
        return strtolower((string)$parts['scheme']) . '://' . $authority;
    }

    private static function normalizeAuthority(string $authority): string
    {
        self::rejectControlCharacters($authority);
        if ($authority === '' || trim($authority) !== $authority || strlen($authority) > 255) {
            throw new InvalidArgumentException('Invalid public request host.');
        }
        self::validateBracketSyntax($authority);
        try {
            $parts = parse_url('http://' . $authority);
        } catch (\ValueError $exception) {
            $parts = false;
        }
        if (!is_array($parts) || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')) {
            throw new InvalidArgumentException('Invalid public request host.');
        }

        return self::formatHostAndPort((string)$parts['host'], $parts['port'] ?? null);
    }

    /** @param mixed $port */
    private static function formatHostAndPort(string $host, $port): string
    {
        $unwrappedHost = trim($host, '[]');
        $isIp = filter_var($unwrappedHost, FILTER_VALIDATE_IP) !== false;
        if (!$isIp && !self::isValidHostname($unwrappedHost)) {
            throw new InvalidArgumentException('Invalid public request host.');
        }
        if ($port !== null && (!is_int($port) || $port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('Invalid public request port.');
        }

        $formattedHost = strpos($unwrappedHost, ':') !== false ? '[' . strtolower($unwrappedHost) . ']' : strtolower($unwrappedHost);
        return $formattedHost . ($port !== null ? ':' . $port : '');
    }

    private static function isValidHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        $host = rtrim($host, '.');
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D', $label) !== 1) {
                return false;
            }
        }
        return true;
    }

    private static function validateBracketSyntax(string $authority): void
    {
        $hasOpeningBracket = strpos($authority, '[') !== false;
        $hasClosingBracket = strpos($authority, ']') !== false;
        if (!$hasOpeningBracket && !$hasClosingBracket) {
            return;
        }
        if (preg_match('/^\[([^\[\]]+)\](?::([0-9]{1,5}))?$/D', $authority, $match) !== 1
            || filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException('Invalid IPv6 host syntax.');
        }
        if (isset($match[2]) && ((int)$match[2] < 1 || (int)$match[2] > 65535)) {
            throw new InvalidArgumentException('Invalid public request port.');
        }
    }

    private static function rejectControlCharacters(string $value): void
    {
        if (preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('Invalid public URL value.');
        }
    }

    private static function serverUsesHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';
        return is_string($https) && $https !== '' && strtolower($https) !== 'off' && $https !== '0';
    }
}
