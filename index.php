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
$frontController = __DIR__ . '/public/index.php';
if (!is_file($frontController) || !is_readable($frontController)) {
	$incidentId = str_replace('.', '', uniqid('bootstrap', true));
	error_log('[incident:' . $incidentId . '] Repository shim cannot read public/index.php');
	http_response_code(503);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	header('X-Robots-Tag: noindex');
	echo 'Service temporarily unavailable. Incident: ' . $incidentId;
	exit;
}

try {
	require $frontController;
} catch (Throwable $exception) {
	$incidentId = str_replace('.', '', uniqid('bootstrap', true));
	error_log(sprintf(
		'[incident:%s] Repository shim bootstrap failure: %s: %s in %s:%d',
		$incidentId,
		get_class($exception),
		$exception->getMessage(),
		$exception->getFile(),
		$exception->getLine()
	));
	if (!headers_sent()) {
		http_response_code(500);
		header('Content-Type: text/plain; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Robots-Tag: noindex');
	}
	echo 'Service temporarily unavailable. Incident: ' . $incidentId;
}
