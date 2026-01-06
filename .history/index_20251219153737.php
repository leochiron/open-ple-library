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

// Front controller shim so the project works when the hosting document root is the repository root.
require __DIR__ . '/public/index.php';
