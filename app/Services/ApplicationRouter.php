<?php

declare(strict_types=1);

namespace App\Services;

/** Classifies requests before feature-specific services are constructed. */
final class ApplicationRouter
{
    public const QUIZ = 'quiz';
    public const QUIZ_ADMIN = 'quiz_admin';
    public const LIBRARY = 'library';
    public const SYNC = 'sync';
    public const DIAGNOSTIC = 'diagnostic';
    public const PUBLIC_RESOURCE = 'public_resource';
    public const UNAVAILABLE = 'unavailable';
    public const MAINTENANCE = 'maintenance';

    private ApplicationProfile $profile;

    public function __construct(ApplicationProfile $profile)
    {
        $this->profile = $profile;
    }

    public function classify(string $requestUri): string
    {
        $path = self::normalizePath($requestUri);

        if ($this->isPublicResource($path)) {
            return self::PUBLIC_RESOURCE;
        }
        if ($path === '/debug' || $path === '/debug.php' || strpos($path, '/debug/') === 0) {
            return self::DIAGNOSTIC;
        }
        if ($path === '/sync' || strpos($path, '/sync/') === 0) {
            return $this->profile->syncEnabled() ? self::SYNC : self::UNAVAILABLE;
        }
        if ($path === '/quiz-admin' || strpos($path, '/quiz-admin/') === 0) {
            return $this->profile->quizEnabled() ? self::QUIZ_ADMIN : self::UNAVAILABLE;
        }
        if ($path === '/quiz' || strpos($path, '/quiz/') === 0) {
            return $this->profile->quizEnabled() ? self::QUIZ : self::UNAVAILABLE;
        }
        if ($path === '/') {
            if ($this->profile->quizAtHomepage()) {
                return self::QUIZ;
            }
            if ($this->profile->quizHomepageUnavailable()) {
                return self::MAINTENANCE;
            }
        }

        return $this->profile->libraryEnabled() ? self::LIBRARY : self::UNAVAILABLE;
    }

    public static function normalizePath(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        if ($path === '/index.php') {
            return '/';
        }
        if (strpos($path, '/index.php/') === 0) {
            $path = substr($path, strlen('/index.php'));
        }
        return $path === '' ? '/' : $path;
    }

    private function isPublicResource(string $path): bool
    {
        return $path === '/favicon.ico'
            || $path === '/robots.txt'
            || $path === '/sitemap.xml'
            || strpos($path, '/assets/') === 0
            || strpos($path, '/public/assets/') === 0;
    }
}
