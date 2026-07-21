<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Normalized deployment profile and its enabled capabilities.
 *
 * Legacy branding files omit both app_mode and quiz: they intentionally map
 * to the historical hybrid behavior with the quiz module enabled.
 */
final class ApplicationProfile
{
    public const LIBRARY = 'library';
    public const QUIZ = 'quiz';
    public const HYBRID = 'hybrid';

    private string $mode;
    private bool $quizConfiguredEnabled;
    private bool $showAdminLink;

    private function __construct(string $mode, bool $quizConfiguredEnabled, bool $showAdminLink)
    {
        $this->mode = $mode;
        $this->quizConfiguredEnabled = $quizConfiguredEnabled;
        $this->showAdminLink = $showAdminLink;
    }

    public static function fromBranding(array $branding): self
    {
        $mode = $branding['app_mode'] ?? self::HYBRID;
        if (!is_string($mode) || !in_array($mode, [self::LIBRARY, self::QUIZ, self::HYBRID], true)) {
            throw new InvalidArgumentException('Invalid application profile.');
        }

        $quiz = $branding['quiz'] ?? [];
        if (!is_array($quiz)) {
            throw new InvalidArgumentException('Invalid quiz configuration.');
        }

        $enabled = $quiz['enabled'] ?? true;
        $showAdminLink = $quiz['show_admin_link'] ?? true;
        if (!is_bool($enabled) || !is_bool($showAdminLink)) {
            throw new InvalidArgumentException('Invalid quiz configuration.');
        }

        return new self($mode, $enabled, $showAdminLink);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function libraryEnabled(): bool
    {
        return $this->mode !== self::QUIZ;
    }

    public function quizEnabled(): bool
    {
        return $this->mode !== self::LIBRARY && $this->quizConfiguredEnabled;
    }

    public function syncEnabled(): bool
    {
        return $this->libraryEnabled();
    }

    public function quizAtHomepage(): bool
    {
        return $this->mode === self::QUIZ && $this->quizConfiguredEnabled;
    }

    public function quizHomepageUnavailable(): bool
    {
        return $this->mode === self::QUIZ && !$this->quizConfiguredEnabled;
    }

    public function showAdminLink(): bool
    {
        return $this->quizEnabled() && $this->showAdminLink;
    }
}
