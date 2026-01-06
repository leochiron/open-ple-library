<?php

namespace App\Services;

declare(strict_types=1);

class I18nService
{
    private array $translations;
    private string $language;
    private array $languages;
    private string $defaultLanguage;
    private string $cookieName;
    private int $cookieTtl;

    public function __construct(array $config, array $translations)
    {
        $this->translations = $translations;
        $this->languages = $config['languages'] ?? ['fr'];
        $this->defaultLanguage = $config['default_language'] ?? 'fr';
        $this->cookieName = $config['language_cookie'] ?? 'ple_lang';
        $this->cookieTtl = $config['language_cookie_ttl'] ?? (60 * 60 * 24 * 30);
        $this->language = $this->defaultLanguage;
    }

    public function detectLanguage(): string
    {
        $requested = $_GET['lang'] ?? null;
        if ($requested && $this->isSupported($requested)) {
            $this->language = $requested;
            setcookie($this->cookieName, $requested, time() + $this->cookieTtl, '/');
            return $this->language;
        }

        $cookie = $_COOKIE[$this->cookieName] ?? null;
        if ($cookie && $this->isSupported($cookie)) {
            $this->language = $cookie;
            return $this->language;
        }

        $browser = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($browser !== '') {
            $parsed = strtolower(substr($browser, 0, 2));
            if ($this->isSupported($parsed)) {
                $this->language = $parsed;
                return $this->language;
            }
        }

        $this->language = $this->defaultLanguage;
        return $this->language;
    }

    public function t(string $key): string
    {
        $lang = $this->language;
        if (isset($this->translations[$lang][$key])) {
            return $this->translations[$lang][$key];
        }

        if (isset($this->translations[$this->defaultLanguage][$key])) {
            return $this->translations[$this->defaultLanguage][$key];
        }

        return $key;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getLanguages(): array
    {
        return $this->languages;
    }

    private function isSupported(string $language): bool
    {
        return in_array(strtolower($language), $this->languages, true);
    }
}
