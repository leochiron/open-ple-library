<?php

declare(strict_types=1);

// Kept as a defensive endpoint for hosts that serve real files before rewrite
// rules. Runtime diagnostics must never be exposed by a production deployment.
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
