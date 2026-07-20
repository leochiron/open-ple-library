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

// Front controller shim so the project works when the hosting document root is the repository root.
require __DIR__ . '/public/index.php';
