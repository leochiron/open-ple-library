<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Services\PresentationDrawService;
use App\Services\I18nService;

final class PresentationDrawController
{
    public function handle(string $storage, I18nService $i18n, array $config): void
    {
        $directory = $storage . '/presentation-draw';
        if (!is_file($directory . '/config.json')) { (new ErrorController($i18n, $config))->notFound(); return; }
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex,follow');
        $_SESSION['presentation_draw_csrf'] ??= bin2hex(random_bytes(24));
        $error = null; $result = null; $teams = [];
        try {
            $service = new PresentationDrawService($directory);
            $teams = $service->teams(); $result = $service->result();
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if ($method === 'GET' && ($_GET['format'] ?? '') === 'json' && $result !== null) {
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="presentation-order.json"');
                echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR); return;
            }
            if ($method === 'POST') {
                if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['presentation_draw_csrf'], $_POST['csrf']) || ($_POST['confirm'] ?? '') !== '1' || !is_string($_POST['launch_code'] ?? null) || strlen($_POST['launch_code']) > 256) {
                    throw new \RuntimeException('La demande a expiré ou n’a pas été confirmée.', 403);
                }
                $service->draw($_POST['launch_code']);
                header('Location: /presentation-draw', true, 303); return;
            }
            if (!in_array($method, ['GET', 'HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD, POST'); return; }
        } catch (\Throwable $e) {
            http_response_code($e->getCode() === 403 ? 403 : 503);
            $error = $e->getCode() === 403 ? 'Code ou confirmation invalide. Le tirage n’a pas été modifié.' : 'Le tirage est momentanément indisponible.';
            error_log('Presentation draw: ' . get_class($e));
        }
        render('library/presentation-draw', ['teams' => $teams, 'result' => $result, 'error' => $error, 'csrf' => $_SESSION['presentation_draw_csrf']], $i18n, $config);
    }
}
