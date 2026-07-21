<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * SQLite storage for the quiz monitoring module.
 * The database file lives in storage/quiz.db and the schema is created on first use.
 */
class QuizDbService
{
    private PDO $pdo;

    public function __construct(string $storagePath)
    {
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('PHP extension pdo_sqlite is required for the quiz module.');
        }

        $this->pdo = new PDO('sqlite:' . $storagePath . DIRECTORY_SEPARATOR . 'quiz.db');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        // Better concurrency for simultaneous student heartbeats
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        $this->createSchema();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function createSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS quiz_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    google_form_url TEXT NOT NULL,
    attempt_entry_id TEXT NOT NULL,
    duration_minutes INTEGER NOT NULL DEFAULT 30,
    max_incidents INTEGER NOT NULL DEFAULT 2,
    min_away_seconds INTEGER NOT NULL DEFAULT 10,
    require_fullscreen INTEGER NOT NULL DEFAULT 0,
    reload_is_incident INTEGER NOT NULL DEFAULT 0,
    state TEXT NOT NULL DEFAULT 'armed',
    access_pin TEXT,
    pin_generated_at TEXT,
    started_at TEXT,
    closed_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    display_name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('super_admin', 'quiz_admin')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    must_change_password INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS quiz_students (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL REFERENCES quiz_sessions(id),
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    email TEXT,
    code TEXT NOT NULL,
    code_email_sent_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(session_id, code)
);

CREATE TABLE IF NOT EXISTS quiz_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL REFERENCES quiz_sessions(id),
    student_id INTEGER NOT NULL REFERENCES quiz_students(id),
    public_token TEXT NOT NULL UNIQUE,
    started_at TEXT,
    last_heartbeat_at TEXT,
    incident_count INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'started',
    finished_at TEXT,
    ip_hash TEXT,
    user_agent TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(session_id, student_id)
);

CREATE TABLE IF NOT EXISTS quiz_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL REFERENCES quiz_attempts(id),
    event_type TEXT NOT NULL,
    away_seconds INTEGER NOT NULL DEFAULT 0,
    is_incident INTEGER NOT NULL DEFAULT 0,
    excused INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_quiz_students_session ON quiz_students(session_id);
CREATE INDEX IF NOT EXISTS idx_quiz_attempts_session ON quiz_attempts(session_id);
CREATE INDEX IF NOT EXISTS idx_quiz_events_attempt ON quiz_events(attempt_id);
CREATE INDEX IF NOT EXISTS idx_admin_users_status ON admin_users(status);
SQL);

        // Migrations for databases created before these columns existed
        $this->ensureColumn('quiz_sessions', 'require_fullscreen', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('quiz_sessions', 'reload_is_incident', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('quiz_events', 'excused', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('quiz_students', 'email', 'TEXT');
        $this->ensureColumn('quiz_students', 'code_email_sent_at', 'TEXT');
        $this->ensureColumn('quiz_attempts', 'finished_at', 'TEXT');
        // Nullable during the migration so an existing installation can start.
        // QuizAdminAuthService assigns every orphaned session to the first
        // super-administrator as soon as that account is bootstrapped.
        $this->ensureColumn('quiz_sessions', 'owner_admin_id', 'INTEGER REFERENCES admin_users(id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_quiz_sessions_owner ON quiz_sessions(owner_admin_id)');
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $columns = $this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        foreach ($columns as $col) {
            if ($col['name'] === $column) {
                return;
            }
        }
        $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}
