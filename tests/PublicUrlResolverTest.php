<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Services\PublicUrlResolver;

$configured = PublicUrlResolver::fromConfig(['public_base_url' => 'https://Courses.Example.org:8443/']);
assertSameValue('https://courses.example.org:8443', $configured->resolve([
    'HTTP_HOST' => 'hostile.example',
    'HTTP_X_FORWARDED_PROTO' => 'http',
]), 'Configured public URL must be normalized and take priority');

$fallback = PublicUrlResolver::fromConfig(['environment' => 'testing']);
assertSameValue('http://localhost:8080', $fallback->resolve(['HTTP_HOST' => 'localhost:8080']), 'HTTP host fallback');
assertSameValue('https://127.0.0.1', $fallback->resolve(['HTTP_HOST' => '127.0.0.1', 'HTTPS' => 'on']), 'Server HTTPS fallback');
assertSameValue('http://[::1]:8080', $fallback->resolve(['HTTP_HOST' => '[::1]:8080']), 'IPv6 fallback');
assertSameValue('http://example.org', $fallback->resolve([
    'HTTP_HOST' => 'example.org',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'REMOTE_ADDR' => '203.0.113.4',
]), 'Forwarded protocol must be ignored by default');

$trustedProxy = PublicUrlResolver::fromConfig([
    'environment' => 'testing',
    'trust_forwarded_proto' => true,
    'trusted_proxy_ips' => ['127.0.0.1'],
]);
assertSameValue('https://example.org', $trustedProxy->resolve([
    'HTTP_HOST' => 'example.org',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'REMOTE_ADDR' => '127.0.0.1',
]), 'Trusted proxy may provide the public protocol');
assertSameValue('http://example.org', $trustedProxy->resolve([
    'HTTP_HOST' => 'example.org',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'REMOTE_ADDR' => '203.0.113.4',
]), 'Unlisted proxy must not influence protocol');

foreach ([
    'https://example.org/path',
    'https://user@example.org',
    'https://example.org?query=1',
    "https://example.org\r\nX-Test: injected",
    'ftp://example.org',
    'https://example.org:99999',
    'https://[[::1]]',
    'https://[::1]]',
    'https://[[::1]',
    'https://[::1]:443]',
] as $invalidUrl) {
    assertThrows(
        static fn(): PublicUrlResolver => PublicUrlResolver::fromConfig(['public_base_url' => $invalidUrl]),
        'Invalid configured URL must be rejected'
    );
}

foreach ([
    'example.org/path',
    'user@example.org',
    'example.org:99999',
    "example.org\r\nX-Test: injected",
    'bad_host.example',
    '[[::1]]',
    '[::1]]',
    '[[::1]',
    '[::1]:443]',
] as $invalidHost) {
    assertThrows(
        static fn(): string => $fallback->resolve(['HTTP_HOST' => $invalidHost]),
        'Hostile Host value must be rejected'
    );
}

assertThrows(
    static fn(): string => $trustedProxy->resolve([
        'HTTP_HOST' => 'example.org',
        'HTTP_X_FORWARDED_PROTO' => "https\r\nX-Test: injected",
        'REMOTE_ADDR' => '127.0.0.1',
    ]),
    'Hostile forwarded protocol from a trusted proxy must be rejected'
);

assertThrows(
    static fn(): string => PublicUrlResolver::fromConfig(['environment' => 'production'])
        ->resolve(['HTTP_HOST' => 'valid.example']),
    'Production SEO resolution must require an explicit public URL'
);
assertSameValue(
    'https://[2001:db8::1]:8443',
    PublicUrlResolver::fromConfig(['public_base_url' => 'https://[2001:db8::1]:8443'])->resolve([]),
    'Configured bracketed IPv6 URL must be accepted'
);
assertThrows(
    static fn(): PublicUrlResolver => PublicUrlResolver::fromConfig([
        'trust_forwarded_proto' => true,
        'trusted_proxy_ips' => ['not-an-ip'],
    ]),
    'Invalid trusted proxy configuration must be rejected'
);

echo "PublicUrlResolverTest: OK\n";
