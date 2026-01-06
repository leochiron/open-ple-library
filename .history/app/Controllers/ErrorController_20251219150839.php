<?php

namespace App\Controllers;

declare(strict_types=1);

use App\Services\I18nService;
use InvalidArgumentException;
use Throwable;

class ErrorController
{
    private I18nService $i18n;
    private array $config;

    public function __construct(I18nService $i18n, array $config)
    {
        $this->i18n = $i18n;
        $this->config = $config;
    }

    public function notFound(): void
    {
        http_response_code(404);
        render('error/404', [
            'title' => $this->i18n->t('errors.not_found.title'),
            'message' => $this->i18n->t('errors.not_found.message'),
        ], $this->i18n, $this->config);
    }

    public function serverError(Throwable $exception): void
    {
        http_response_code(500);
        render('error/500', [
            'title' => $this->i18n->t('errors.server_error.title'),
            'message' => $this->i18n->t('errors.server_error.message'),
            'details' => null,
        ], $this->i18n, $this->config);
    }
}
