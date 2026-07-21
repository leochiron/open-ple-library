<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Persistence and authentication for named quiz administrators.
 *
 * Account creation remains an explicit administrative action: this service
 * intentionally exposes no public-registration workflow.
 */
final class QuizAdminAuthService
{
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_QUIZ_ADMIN = 'quiz_admin';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    private const SESSION_KEY = 'quiz_admin_identity';

    private PDO $db;

    public function __construct(QuizDbService $database, bool $startSession = true)
    {
        $this->db = $database->pdo();
        if ($startSession && session_status() === PHP_SESSION_NONE && !session_start()) {
            throw new RuntimeException('Unable to start the quiz administrator session.');
        }
    }

    /**
     * Creates the first super-administrator, and never changes an installation
     * that already contains an account. The password can be supplied explicitly
     * (CLI/deployment secret), through QUIZ_BOOTSTRAP_ADMIN_PASSWORD, or through
     * the legacy quiz_admin_password key during the compatibility period.
     */
    public function bootstrapFromConfig(array $branding, ?string $deploymentSecret = null): ?array
    {
        if ($this->countAdmins() > 0) {
            return null;
        }

        $quiz = isset($branding['quiz']) && is_array($branding['quiz']) ? $branding['quiz'] : [];
        $email = trim((string)($quiz['bootstrap_admin_email'] ?? ''));
        $password = $deploymentSecret;
        if ($password === null || $password === '') {
            $environmentPassword = getenv('QUIZ_BOOTSTRAP_ADMIN_PASSWORD');
            $password = is_string($environmentPassword) ? $environmentPassword : '';
        }
        if ($password === '') {
            // Keeps existing single-password deployments bootstrappable without
            // persisting a second clear-text secret in configuration.
            $password = (string)($branding['quiz_admin_password'] ?? '');
        }

        if ($email === '' || $password === '') {
            return null;
        }

        $displayName = trim((string)($quiz['bootstrap_admin_name'] ?? 'Administrateur'));
        return $this->createAdmin($email, $displayName, $password, self::ROLE_SUPER_ADMIN, true);
    }

    public function createAdmin(
        string $email,
        string $displayName,
        string $password,
        string $role = self::ROLE_QUIZ_ADMIN,
        bool $mustChangePassword = true
    ): array {
        $email = $this->normalizeEmail($email);
        $displayName = trim($displayName);
        $this->validateRole($role);
        $this->validatePassword($password);
        if ($displayName === '') {
            throw new InvalidArgumentException('display_name_required');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash)) {
            throw new RuntimeException('password_hash_failed');
        }

        $this->db->beginTransaction();
        try {
            $isFirstSuperAdmin = $role === self::ROLE_SUPER_ADMIN
                && (int)$this->db->query("SELECT COUNT(*) FROM admin_users WHERE role = 'super_admin'")->fetchColumn() === 0;
            $stmt = $this->db->prepare(
                'INSERT INTO admin_users (email, display_name, password_hash, role, must_change_password)
                 VALUES (:email, :name, :hash, :role, :must_change)'
            );
            $stmt->execute([
                'email' => $email,
                'name' => $displayName,
                'hash' => $hash,
                'role' => $role,
                'must_change' => $mustChangePassword ? 1 : 0,
            ]);
            $id = (int)$this->db->lastInsertId();

            if ($isFirstSuperAdmin) {
                $owner = $this->db->prepare(
                    'UPDATE quiz_sessions SET owner_admin_id = :owner WHERE owner_admin_id IS NULL'
                );
                $owner->execute(['owner' => $id]);
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return $this->requireAdmin($id);
    }

    public function authenticate(string $email, string $password): bool
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);
        $admin = $stmt->fetch();

        if (!is_array($admin)
            || $admin['status'] !== self::STATUS_ACTIVE
            || !password_verify($password, (string)$admin['password_hash'])) {
            return false;
        }

        if (password_needs_rehash((string)$admin['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = password_hash($password, PASSWORD_DEFAULT);
            if (is_string($rehash)) {
                $stmt = $this->db->prepare('UPDATE admin_users SET password_hash = :hash, updated_at = datetime(\'now\') WHERE id = :id');
                $stmt->execute(['hash' => $rehash, 'id' => (int)$admin['id']]);
            }
        }

        $stmt = $this->db->prepare('UPDATE admin_users SET last_login_at = datetime(\'now\'), updated_at = datetime(\'now\') WHERE id = :id');
        $stmt->execute(['id' => (int)$admin['id']]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        // Keep only the stable identifier client-side. Roles, status and names
        // are deliberately reloaded from SQLite on every authorization check.
        $_SESSION[self::SESSION_KEY] = (int)$admin['id'];
        return true;
    }

    public function currentAdmin(): ?array
    {
        $adminId = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_int($adminId) || $adminId < 1) {
            return null;
        }

        $admin = $this->findAdmin($adminId);
        if ($admin === null || $admin['status'] !== self::STATUS_ACTIVE) {
            $this->logout();
            return null;
        }

        return $this->identity($admin);
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function listAdmins(): array
    {
        return $this->db->query(
            'SELECT id, email, display_name, role, status, must_change_password, last_login_at, created_at, updated_at
             FROM admin_users ORDER BY display_name COLLATE NOCASE, id'
        )->fetchAll();
    }

    public function hasAdmins(): bool
    {
        return $this->countAdmins() > 0;
    }

    public function setStatus(int $adminId, string $status): void
    {
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_DISABLED], true)) {
            throw new InvalidArgumentException('invalid_admin_status');
        }
        $stmt = $this->db->prepare('UPDATE admin_users SET status = :status, updated_at = datetime(\'now\') WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $adminId]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('admin_not_found');
        }
    }

    public function resetPassword(int $adminId, string $password, bool $mustChangePassword = true): void
    {
        $this->validatePassword($password);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash)) {
            throw new RuntimeException('password_hash_failed');
        }
        $stmt = $this->db->prepare(
            'UPDATE admin_users SET password_hash = :hash, must_change_password = :must_change,
             updated_at = datetime(\'now\') WHERE id = :id'
        );
        $stmt->execute(['hash' => $hash, 'must_change' => $mustChangePassword ? 1 : 0, 'id' => $adminId]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('admin_not_found');
        }
    }

    /** Transfers all currently persisted quiz sessions between administrators. */
    public function transferSessionsOwnership(int $fromAdminId, int $toAdminId): int
    {
        if ($fromAdminId === $toAdminId) {
            return 0;
        }
        $source = $this->findAdmin($fromAdminId);
        $target = $this->findAdmin($toAdminId);
        if ($source === null || $target === null) {
            throw new InvalidArgumentException('admin_not_found');
        }
        if ($target['status'] !== self::STATUS_ACTIVE) {
            throw new InvalidArgumentException('target_admin_disabled');
        }

        $stmt = $this->db->prepare(
            'UPDATE quiz_sessions SET owner_admin_id = :target WHERE owner_admin_id = :source'
        );
        $stmt->execute(['target' => $toAdminId, 'source' => $fromAdminId]);
        return $stmt->rowCount();
    }

    public function findAdmin(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, email, display_name, role, status, must_change_password, last_login_at, created_at, updated_at
             FROM admin_users WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $admin = $stmt->fetch();
        return is_array($admin) ? $admin : null;
    }

    private function requireAdmin(int $id): array
    {
        $admin = $this->findAdmin($id);
        if ($admin === null) {
            throw new RuntimeException('admin_creation_failed');
        }
        return $admin;
    }

    private function countAdmins(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('invalid_admin_email');
        }
        return $email;
    }

    private function validateRole(string $role): void
    {
        if (!in_array($role, [self::ROLE_SUPER_ADMIN, self::ROLE_QUIZ_ADMIN], true)) {
            throw new InvalidArgumentException('invalid_admin_role');
        }
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 12) {
            throw new InvalidArgumentException('admin_password_too_short');
        }
    }

    private function identity(array $admin): array
    {
        return [
            'id' => (int)$admin['id'],
            'email' => (string)$admin['email'],
            'display_name' => (string)$admin['display_name'],
            'role' => (string)$admin['role'],
            'must_change_password' => (bool)$admin['must_change_password'],
        ];
    }
}
