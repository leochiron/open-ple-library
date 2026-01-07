<?php

declare(strict_types=1);

namespace App\Services;

class AuthService
{
    private array $passwords;
    private bool $enabled;
    private const SESSION_KEY = 'ple_authenticated';

    public function __construct(array $config)
    {
        $this->passwords = $config['passwords'] ?? [];
        $this->enabled = !empty($this->passwords);

        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Check if authentication is required.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if the user is currently authenticated.
     */
    public function isAuthenticated(): bool
    {
        if (!$this->enabled) {
            return true;
        }

        return isset($_SESSION[self::SESSION_KEY]) && $_SESSION[self::SESSION_KEY] === true;
    }

    /**
     * Verify a password and set the authentication session if valid.
     *
     * @return bool True if the password is valid, false otherwise
     */
    public function verifyPassword(string $password): bool
    {
        if (!$this->enabled) {
            return true;
        }

        // Use constant-time comparison to prevent timing attacks
        foreach ($this->passwords as $valid) {
            if (hash_equals($valid, $password)) {
                // Set the authentication session
                $_SESSION[self::SESSION_KEY] = true;
                return true;
            }
        }

        return false;
    }

    /**
     * Log out the user by removing the authentication session.
     */
    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}

