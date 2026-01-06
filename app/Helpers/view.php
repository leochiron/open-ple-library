<?php

declare(strict_types=1);

use App\Services\I18nService;

/**
 * Render a view inside the main layout.
 */
function render(string $view, array $data, I18nService $i18n, array $config): void
{
    $viewPath = __DIR__ . '/../Views/' . $view . '.php';
    if (!is_file($viewPath)) {
        http_response_code(500);
        echo 'View not found';
        return;
    }

    extract($data, EXTR_OVERWRITE);

    ob_start();
    include $viewPath;
    $content = ob_get_clean();

    include __DIR__ . '/../Views/layout.php';
}
