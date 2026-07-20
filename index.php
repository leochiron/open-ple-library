<?php

declare(strict_types=1);

$reqUri = $_SERVER['REQUEST_URI'] ?? '';
$earlyPath = parse_url($reqUri, PHP_URL_PATH) ?: '/';
$earlyPath = preg_replace('#^/index\.php(?=/|$)#', '', $earlyPath) ?: '/';

// Serve the exact favicon path when docroot is the repository root.
if ($earlyPath === '/favicon.ico') {
	$favicon = base64_decode('AAABAAEAEBAAAAEAIABoBAAAFgAAACgAAAAQAAAAIAAAAAEAGAAAAAAAAAMAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAD///8A////////AAAAAA==');
	header('Content-Type: image/x-icon');
	header('Content-Length: ' . strlen($favicon));
	echo $favicon;
	exit;
}

// Keep SEO files public when requests hit the repository root shim.
if ($earlyPath === '/robots.txt') {
	$robotsPath = __DIR__ . '/robots.txt';
	if (is_file($robotsPath)) {
		header('Content-Type: text/plain; charset=utf-8');
		readfile($robotsPath);
		exit;
	}
}
if ($earlyPath === '/sitemap.xml') {
	$sitemapPath = __DIR__ . '/public/sitemap.xml';
	if (is_file($sitemapPath)) {
		header('Content-Type: application/xml; charset=utf-8');
		readfile($sitemapPath);
		exit;
	}
}

// Front controller shim so the project works when the hosting document root is the repository root.
require __DIR__ . '/public/index.php';
