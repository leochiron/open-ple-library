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

CREATE TABLE IF NOT EXISTS quiz_attempt_archives (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    source_attempt_id INTEGER NOT NULL,
    generation TEXT NOT NULL,
    format_version INTEGER NOT NULL,
    actor_admin_id INTEGER NOT NULL,
    actor_name TEXT NOT NULL,
    archived_at TEXT NOT NULL,
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    incident_count INTEGER NOT NULL,
    status TEXT NOT NULL,
    finished_at TEXT,
    event_count INTEGER NOT NULL,
    snapshot_json TEXT NOT NULL,
    UNIQUE(session_id, generation, source_attempt_id)
);
CREATE INDEX IF NOT EXISTS idx_quiz_archives_session ON quiz_attempt_archives(session_id, id);
CREATE INDEX IF NOT EXISTS idx_quiz_archives_attempt ON quiz_attempt_archives(session_id, source_attempt_id, id);

CREATE TABLE IF NOT EXISTS quiz_session_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    actor_admin_id INTEGER NOT NULL,
    actor_name TEXT NOT NULL,
    created_at TEXT NOT NULL,
    format_version INTEGER NOT NULL DEFAULT 1,
    before_json TEXT NOT NULL,
    after_json TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_quiz_audit_session ON quiz_session_audit(session_id, id);

CREATE TABLE IF NOT EXISTS quiz_student_access (
    session_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    manual_blocked INTEGER NOT NULL DEFAULT 0 CHECK (manual_blocked IN (0, 1)),
    reason TEXT NOT NULL,
    actor_admin_id INTEGER NOT NULL,
    actor_name TEXT NOT NULL,
    changed_at TEXT NOT NULL,
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    PRIMARY KEY(session_id, student_id)
);

CREATE TABLE IF NOT EXISTS quiz_technical_overrides (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    tracking_generation TEXT NOT NULL,
    scope_browser INTEGER NOT NULL CHECK (scope_browser IN (0, 1)),
    scope_tracking INTEGER NOT NULL CHECK (scope_tracking IN (0, 1)),
    status TEXT NOT NULL CHECK (status IN ('active', 'revoked', 'expired')),
    grant_reason TEXT NOT NULL,
    granted_at TEXT NOT NULL,
    grant_actor_id INTEGER NOT NULL,
    grant_actor_name TEXT NOT NULL,
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    ended_at TEXT,
    end_actor_id INTEGER,
    end_actor_name TEXT,
    end_reason TEXT,
    end_kind TEXT,
    CHECK (scope_browser = 1 OR scope_tracking = 1)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_quiz_override_active ON quiz_technical_overrides(session_id, student_id, tracking_generation) WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_quiz_override_session ON quiz_technical_overrides(session_id, id);
CREATE INDEX IF NOT EXISTS idx_quiz_override_student ON quiz_technical_overrides(session_id, student_id, id);

CREATE TABLE IF NOT EXISTS quiz_tracking_contexts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, context_ref TEXT NOT NULL UNIQUE,
 cookie_binding_hash TEXT NOT NULL, document_epoch_hash TEXT NOT NULL,
 session_id INTEGER NOT NULL, student_id INTEGER NOT NULL, attempt_id INTEGER NOT NULL,
 tracking_generation TEXT NOT NULL, first_name TEXT NOT NULL, last_name TEXT NOT NULL,
 status TEXT NOT NULL CHECK(status IN ('active','terminated')),
 created_at TEXT NOT NULL, terminated_at TEXT, termination_kind TEXT,
 proof_status TEXT NOT NULL DEFAULT 'missing', proof_until INTEGER, proof_issued_at TEXT,
 proof_incarnation TEXT, checks_json TEXT, proof_fullscreen_required INTEGER NOT NULL DEFAULT 0,
 admitted_at TEXT, phase TEXT NOT NULL DEFAULT 'pending',
 identity_truncated INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_quiz_tracking_cookie ON quiz_tracking_contexts(cookie_binding_hash) WHERE status='active';
CREATE INDEX IF NOT EXISTS idx_quiz_tracking_attempt ON quiz_tracking_contexts(session_id,attempt_id,id);
CREATE TABLE IF NOT EXISTS quiz_tracking_challenges (
 id INTEGER PRIMARY KEY AUTOINCREMENT, context_id INTEGER NOT NULL,
 secret_hash TEXT NOT NULL UNIQUE, settings_revision INTEGER NOT NULL,
 issued_at TEXT NOT NULL, expires_at INTEGER NOT NULL, consumed_at TEXT, terminated_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_quiz_tracking_challenge_context ON quiz_tracking_challenges(context_id,id);
CREATE TABLE IF NOT EXISTS quiz_tracking_diagnostics (
 id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER NOT NULL,
 student_id INTEGER NOT NULL, attempt_id INTEGER NOT NULL, context_ref TEXT NOT NULL,
 tracking_generation TEXT NOT NULL, first_name TEXT NOT NULL, last_name TEXT NOT NULL,
 created_at TEXT NOT NULL, code TEXT NOT NULL, operation TEXT NOT NULL,
 provenance TEXT NOT NULL, checks_json TEXT, identity_truncated INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_quiz_tracking_diagnostics_session ON quiz_tracking_diagnostics(session_id,id);
SQL);

        // Migrations for databases created before these columns existed
        $this->ensureColumn('quiz_sessions', 'google_form_edit_url', "TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('quiz_sessions', 'require_fullscreen', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('quiz_sessions', 'reload_is_incident', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('quiz_events', 'excused', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('quiz_sessions', 'tracking_generation', 'TEXT');
        $this->ensureColumn('quiz_sessions', 'history_revision', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('quiz_sessions', 'browser_enabled', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('quiz_sessions', 'browser_families_json', 'TEXT NOT NULL DEFAULT \'["chrome","edge","firefox","safari"]\'');
        foreach (['browser_assessed_at', 'browser_incarnation', 'browser_policy_fingerprint', 'browser_canonical_json', 'browser_diagnostic_json'] as $column) { $this->ensureColumn('quiz_tracking_contexts', $column, 'TEXT'); }
        $this->ensureColumn('quiz_tracking_contexts', 'browser_status', "TEXT NOT NULL DEFAULT 'missing'");
        foreach (['tracking_power', 'browser_policy_fingerprint', 'browser_environment_fingerprint', 'browser_incarnation'] as $column) { $this->ensureColumn('quiz_tracking_challenges', $column, 'TEXT'); }
        $this->ensureColumn('quiz_tracking_challenges', 'browser_power', "TEXT NOT NULL DEFAULT 'none'");
        $this->ensureColumn('quiz_tracking_challenges', 'browser_protocol', 'INTEGER NOT NULL DEFAULT 1');
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS quiz_browser_diagnostics (
 id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER NOT NULL, student_id INTEGER NOT NULL,
 attempt_id INTEGER NOT NULL, context_ref TEXT NOT NULL, tracking_generation TEXT NOT NULL,
 first_name TEXT NOT NULL, last_name TEXT NOT NULL, created_at TEXT NOT NULL,
 code TEXT NOT NULL, operation TEXT NOT NULL, details_json TEXT NOT NULL, identity_truncated INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_quiz_browser_diagnostics_session ON quiz_browser_diagnostics(session_id,id);
SQL);
        $this->ensureColumn('quiz_sessions', 'tracking_mode', "TEXT NOT NULL DEFAULT 'off'");
        $this->ensureColumn('quiz_sessions', 'settings_revision', 'INTEGER NOT NULL DEFAULT 0');
        $this->ensureColumn('quiz_tracking_challenges', 'kind', "TEXT NOT NULL DEFAULT 'preflight'");
        $this->ensureColumn('quiz_tracking_challenges', 'purpose', "TEXT NOT NULL DEFAULT 'preflight'");
        $this->ensureColumn('quiz_tracking_challenges', 'proof_incarnation', 'TEXT');
        $this->ensureColumn('quiz_tracking_challenges', 'proof_status_at_issue', 'TEXT');
        $this->ensureColumn('quiz_tracking_challenges', 'fullscreen_required', 'INTEGER NOT NULL DEFAULT 0');
        foreach (['last_pulse_at', 'last_pulse_checks_json', 'last_pulse_outcome', 'pulse_failure_checks_json'] as $column) {
            $this->ensureColumn('quiz_tracking_contexts', $column, 'TEXT');
        }
        $this->ensureColumn('quiz_tracking_contexts', 'last_pulse_until', 'INTEGER');
        $this->ensureColumn('quiz_tracking_contexts', 'last_pulse_fullscreen_required', 'INTEGER');
        $this->ensureColumn('quiz_tracking_contexts', 'pulse_failure_fullscreen_required', 'INTEGER');
        foreach (['event_uid', 'absence_uid', 'source', 'related_event_uid', 'tracking_generation'] as $column) {
            $this->ensureColumn('quiz_events', $column, 'TEXT');
        }
        $this->ensureColumn('quiz_events', 'duration_ms', 'INTEGER');
        $this->ensureColumn('quiz_events', 'dropped_events', 'INTEGER');
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_quiz_events_uid ON quiz_events(attempt_id, event_uid) WHERE event_uid IS NOT NULL');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_quiz_events_absence ON quiz_events(attempt_id, absence_uid)');
        $this->pdo->exec("UPDATE quiz_sessions SET tracking_generation = lower(hex(randomblob(16))) WHERE tracking_generation IS NULL OR tracking_generation = ''");
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
