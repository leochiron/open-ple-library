<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/app/Helpers/url.php';

use App\Services\I18nService;

$view = $argv[1] ?? '';
if (!in_array($view, ['room', 'board', 'session'], true)) {
    throw new InvalidArgumentException('Unknown quiz fixture');
}
$i18n = new I18nService(['default_language' => 'fr'], require dirname(__DIR__, 2) . '/app/Config/i18n.php');
$session = [
    'id' => 1, 'title' => 'Initial quiz', 'state' => 'running', 'duration_minutes' => 15,
    'max_incidents' => 2, 'min_away_seconds' => 10, 'require_fullscreen' => 0,
    'reload_is_incident' => 0, 'access_pin' => '123456', 'google_form_edit_url' => '',
    'google_form_url' => 'https://docs.google.com/forms/d/e/initial/viewform', 'attempt_entry_id' => '123456',
];
$students = [
    ['id' => 1, 'first_name' => 'Alice', 'last_name' => 'Alpha', 'code' => 'ABCDE', 'email' => 'alice@example.test', 'code_email_sent_at' => null],
    ['id' => 2, 'first_name' => 'Bob', 'last_name' => 'Beta', 'code' => 'BCDEF', 'email' => 'bob@example.test', 'code_email_sent_at' => null],
];
$attempt = ['id' => 1, 'first_name' => 'Alice', 'last_name' => 'Alpha', 'incident_count' => 0, 'finished_at' => null];
$state = [
    'state' => 'running', 'title' => 'Initial quiz', 'server_now' => time(), 'remaining_seconds' => 900,
    'duration_minutes' => 15, 'max_incidents' => 2, 'min_away_seconds' => 10,
    'require_fullscreen' => false, 'reload_is_incident' => false, 'incident_count' => 0,
    'attempt_status' => 'started', 'finished' => false, 'form_url' => $session['google_form_url'],
];
$attempts = $recentEvents = $admins = [];
$rulesVersion = 'initial-rules';
$admin = ['id' => 1, 'role' => 'quiz_admin'];
$flash = $csrfToken = '';
$config = [];
include dirname(__DIR__, 2) . '/app/Views/quiz/' . ($view === 'room' ? 'room' : 'admin/' . $view) . '.php';
