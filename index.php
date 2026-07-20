<?php

declare(strict_types=1);

// Serve favicon early when docroot is repository root and no static mapping exists.
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($reqUri, 'favicon.ico') !== false) {
	$favicon = base64_decode('AAABAAEAEBAAAAEAIABoBAAAFgAAACgAAAAQAAAAIAAAAAEAGAAAAAAAAAMAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAAAAA==');
	header('Content-Type: image/x-icon');
	header('Content-Length: ' . strlen($favicon));
	echo $favicon;
	exit;
}

// Keep SEO files public when requests hit the repository root shim.
$requestPath = parse_url($reqUri, PHP_URL_PATH) ?: '/';
$normalizedRequestPath = preg_replace('#^/index\.php#', '', $requestPath) ?: '/';
$seoPath = '/' . ltrim($normalizedRequestPath, '/');
if (preg_match('#/robots\.txt$#', $seoPath) === 1) {
	$robotsPath = __DIR__ . '/robots.txt';
	if (is_file($robotsPath)) {
		header('Content-Type: text/plain; charset=utf-8');
		readfile($robotsPath);
		exit;
	}
}
if (preg_match('#/sitemap\.xml$#', $seoPath) === 1) {
	$sitemapPath = __DIR__ . '/public/sitemap.xml';
	if (is_file($sitemapPath)) {
		header('Content-Type: application/xml; charset=utf-8');
		readfile($sitemapPath);
		exit;
	}
}

// Front controller shim so the project works when the hosting document root is the repository root.
require __DIR__ . '/public/index.php';
