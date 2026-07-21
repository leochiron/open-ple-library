<?php

declare(strict_types=1);

/** @var array $session */
/** @var array $students */
/** @var array $attempts */
/** @var array $recentEvents */
/** @var string $flash */
/** @var string $csrfToken */
/** @var array $admin */
/** @var array $admins */
/** @var App\Services\I18nService $i18n */

$stateLabels = [
    'armed' => $i18n->t('quiz.state.armed'),
    'lobby' => $i18n->t('quiz.state.lobby'),
    'running' => $i18n->t('quiz.state.running'),
    'closed' => $i18n->t('quiz.state.closed'),
];
$sid = (int)$session['id'];
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetBase(), ENT_QUOTES, 'UTF-8'); ?>/css/quiz.css">

<div class="quiz-admin-grid">

<section class="card">
    <header class="card-header">
        <h1><?php echo htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
    </header>
    <div class="card-body">
        <?php if ($flash !== ''): ?>
            <p class="quiz-flash" role="status"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <p>
            <span class="quiz-state quiz-state--<?php echo htmlspecialchars($session['state'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($stateLabels[$session['state']] ?? $session['state'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
            &nbsp;·&nbsp; <?php echo (int)$session['duration_minutes']; ?> min
            &nbsp;·&nbsp; <?php echo htmlspecialchars($i18n->t('quiz.admin.field_max_incidents'), ENT_QUOTES, 'UTF-8'); ?> : <?php echo (int)$session['max_incidents']; ?>
            &nbsp;·&nbsp; <?php echo htmlspecialchars($i18n->t('quiz.admin.field_min_away'), ENT_QUOTES, 'UTF-8'); ?> : <?php echo (int)$session['min_away_seconds']; ?>s
            <?php if (!empty($session['require_fullscreen'])): ?>
                &nbsp;·&nbsp; <?php echo htmlspecialchars($i18n->t('quiz.admin.rule_fullscreen'), ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
            <?php if (!empty($session['reload_is_incident'])): ?>
                &nbsp;·&nbsp; <?php echo htmlspecialchars($i18n->t('quiz.admin.rule_reload'), ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
        </p>

        <?php if (in_array($session['state'], ['lobby', 'running'], true) && $session['access_pin'] !== null): ?>
            <p>
                <?php echo htmlspecialchars($i18n->t('quiz.admin.pin_label'), ENT_QUOTES, 'UTF-8'); ?><br>
                <span class="quiz-pin-display"><?php echo htmlspecialchars($session['access_pin'], ENT_QUOTES, 'UTF-8'); ?></span>
            </p>
            <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.admin.pin_hint'), ENT_QUOTES, 'UTF-8'); ?> <code><?php echo htmlspecialchars(($_SERVER['HTTP_HOST'] ?? '') . '/quiz', ENT_QUOTES, 'UTF-8'); ?></code></p>
        <?php endif; ?>

        <div class="quiz-admin-actions">
            <?php if (in_array($session['state'], ['armed', 'closed'], true)): ?>
                <form method="post" action="/quiz-admin/session/open">
                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="id" value="<?php echo $sid; ?>">
                    <button type="submit" class="btn primary"><?php echo htmlspecialchars($i18n->t('quiz.admin.action_open'), ENT_QUOTES, 'UTF-8'); ?></button>
                </form>
            <?php endif; ?>
            <?php if ($session['state'] === 'lobby'): ?>
                <form method="post" action="/quiz-admin/session/launch">
                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="id" value="<?php echo $sid; ?>">
                    <button type="submit" class="btn primary"><?php echo htmlspecialchars($i18n->t('quiz.admin.action_launch'), ENT_QUOTES, 'UTF-8'); ?></button>
                </form>
            <?php endif; ?>
            <?php if ($session['state'] === 'running'): ?>
                <form method="post" action="/quiz-admin/session/launch"
                      onsubmit="return confirm('<?php echo htmlspecialchars($i18n->t('quiz.admin.action_relaunch_confirm'), ENT_QUOTES, 'UTF-8'); ?>');">
                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="id" value="<?php echo $sid; ?>">
                    <button type="submit" class="btn primary"><?php echo htmlspecialchars($i18n->t('quiz.admin.action_relaunch'), ENT_QUOTES, 'UTF-8'); ?></button>
                </form>
                <form method="post" action="/quiz-admin/session/stop">
                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="id" value="<?php echo $sid; ?>">
                    <button type="submit" class="btn ghost"><?php echo htmlspecialchars($i18n->t('quiz.admin.action_stop'), ENT_QUOTES, 'UTF-8'); ?></button>
                </form>
            <?php endif; ?>
            <?php if (in_array($session['state'], ['lobby', 'running'], true)): ?>
                <form method="post" action="/quiz-admin/session/reset"
                      onsubmit="return confirm('<?php echo htmlspecialchars($i18n->t('quiz.admin.action_reset_confirm'), ENT_QUOTES, 'UTF-8'); ?>');">
                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="id" value="<?php echo $sid; ?>">
                    <button type="submit" class="btn ghost">🔄 <?php echo htmlspecialchars($i18n->t('quiz.admin.action_reset'), ENT_QUOTES, 'UTF-8'); ?></button>
                </form>
            <?php endif; ?>
            <?php if (in_array($session['state'], ['lobby', 'running'], true)): ?>
                <form method="post" action="/quiz-admin/session/close"
                      onsubmit="return confirm('<?php echo htmlspecialchars($i18n->t('quiz.admin.action_close_confirm'), ENT_QUOTES, 'UTF-8'); ?>');">
                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="id" value="<?php echo $sid; ?>">
                    <button type="submit" class="btn ghost"><?php echo htmlspecialchars($i18n->t('quiz.admin.action_close'), ENT_QUOTES, 'UTF-8'); ?></button>
                </form>
            <?php endif; ?>
            <a class="btn primary" href="/quiz-admin/board?id=<?php echo $sid; ?>" target="_blank">📺 <?php echo htmlspecialchars($i18n->t('quiz.admin.board_open'), ENT_QUOTES, 'UTF-8'); ?></a>
            <a class="btn ghost" href="/quiz-admin/codes?id=<?php echo $sid; ?>" target="_blank"><?php echo htmlspecialchars($i18n->t('quiz.admin.action_codes'), ENT_QUOTES, 'UTF-8'); ?></a>
            <a class="btn ghost" href="/quiz-admin/export?id=<?php echo $sid; ?>"><?php echo htmlspecialchars($i18n->t('quiz.admin.action_export'), ENT_QUOTES, 'UTF-8'); ?></a>
            <a class="btn ghost" href="/quiz-admin"><?php echo htmlspecialchars($i18n->t('nav.back'), ENT_QUOTES, 'UTF-8'); ?></a>
        </div>

        <?php if ($admin['role'] === 'super_admin'): ?>
            <form method="post" action="/quiz-admin/session/transfer" class="quiz-admin-actions" style="margin-top:12px;">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="id" value="<?php echo $sid; ?>">
                <label for="session-owner">Propriétaire</label>
                <select id="session-owner" name="owner_admin_id" class="quiz-input quiz-input--inline">
                    <?php foreach ($admins as $candidate): ?>
                        <?php if ($candidate['status'] === 'active'): ?>
                            <option value="<?php echo (int)$candidate['id']; ?>" <?php echo (int)$candidate['id'] === (int)$session['owner_admin_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($candidate['display_name'] . ' — ' . $candidate['email'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn ghost">Transférer</button>
            </form>
        <?php endif; ?>

        <details class="quiz-tuto" style="margin-top: 18px;">
            <summary>⚙️ <?php echo htmlspecialchars($i18n->t('quiz.admin.edit_title'), ENT_QUOTES, 'UTF-8'); ?></summary>
            <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.admin.edit_hint'), ENT_QUOTES, 'UTF-8'); ?></p>
            <form method="post" action="/quiz-admin/session/update" class="quiz-admin-form">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="id" value="<?php echo $sid; ?>">
                <div class="quiz-field quiz-field--full">
                    <label for="qe-title"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_title'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="text" id="qe-title" name="title" required class="quiz-input"
                           value="<?php echo htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="quiz-field quiz-field--full">
                    <label for="qe-url"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_url'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="url" id="qe-url" name="google_form_url" required class="quiz-input"
                           value="<?php echo htmlspecialchars($session['google_form_url'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="quiz-field quiz-field--full">
                    <label for="qe-entry"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_entry'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="text" id="qe-entry" name="attempt_entry_id" required class="quiz-input"
                           value="<?php echo htmlspecialchars($session['attempt_entry_id'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="quiz-field">
                    <label for="qe-duration"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_duration'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="number" id="qe-duration" name="duration_minutes" min="1" max="240" class="quiz-input"
                           value="<?php echo (int)$session['duration_minutes']; ?>">
                </div>
                <div class="quiz-field">
                    <label for="qe-max-incidents"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_max_incidents'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="number" id="qe-max-incidents" name="max_incidents" min="1" max="20" class="quiz-input"
                           value="<?php echo (int)$session['max_incidents']; ?>">
                </div>
                <div class="quiz-field">
                    <label for="qe-min-away"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_min_away'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="number" id="qe-min-away" name="min_away_seconds" min="1" max="300" class="quiz-input"
                           value="<?php echo (int)$session['min_away_seconds']; ?>">
                </div>
                <div class="quiz-field">
                    <label class="quiz-consent">
                        <input type="checkbox" name="require_fullscreen" <?php echo !empty($session['require_fullscreen']) ? 'checked' : ''; ?>>
                        <span><?php echo htmlspecialchars($i18n->t('quiz.admin.field_fullscreen'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </label>
                </div>
                <div class="quiz-field">
                    <label class="quiz-consent">
                        <input type="checkbox" name="reload_is_incident" <?php echo !empty($session['reload_is_incident']) ? 'checked' : ''; ?>>
                        <span><?php echo htmlspecialchars($i18n->t('quiz.admin.field_reload'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </label>
                </div>
                <div class="quiz-field quiz-field--full">
                    <button type="submit" class="btn primary"><?php echo htmlspecialchars($i18n->t('quiz.admin.edit_submit'), ENT_QUOTES, 'UTF-8'); ?></button>
                </div>
            </form>
        </details>
    </div>
</section>

<section class="card">
    <header class="card-header">
        <h2><?php echo htmlspecialchars($i18n->t('quiz.admin.attempts_title'), ENT_QUOTES, 'UTF-8'); ?>
            <span id="qa-connected-count" class="quiz-hint"></span></h2>
    </header>
    <div class="card-body">
        <table class="quiz-table" id="qa-attempts-table">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_student'), ENT_QUOTES, 'UTF-8'); ?></th>
                    <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_connected'), ENT_QUOTES, 'UTF-8'); ?></th>
                    <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_incidents'), ENT_QUOTES, 'UTF-8'); ?></th>
                    <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_status'), ENT_QUOTES, 'UTF-8'); ?></th>
                </tr>
            </thead>
            <tbody id="qa-attempts-body">
                <tr><td colspan="4"><?php echo htmlspecialchars($i18n->t('quiz.admin.no_attempts'), ENT_QUOTES, 'UTF-8'); ?></td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <header class="card-header">
        <h2><?php echo htmlspecialchars($i18n->t('quiz.admin.feed_title'), ENT_QUOTES, 'UTF-8'); ?> <span class="quiz-live-dot" aria-hidden="true"></span></h2>
    </header>
    <div class="card-body">
        <table class="quiz-table">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_student'), ENT_QUOTES, 'UTF-8'); ?></th>
                    <th>Type</th>
                    <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_away'), ENT_QUOTES, 'UTF-8'); ?></th>
                    <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_date'), ENT_QUOTES, 'UTF-8'); ?></th>
                </tr>
            </thead>
            <tbody id="qa-feed-body">
                <?php if (count($recentEvents) === 0): ?>
                    <tr id="qa-feed-empty"><td colspan="4"><?php echo htmlspecialchars($i18n->t('quiz.admin.no_events'), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($recentEvents as $e): ?>
                        <tr class="<?php echo ((int)$e['is_incident'] === 1 && empty($e['excused'])) ? 'quiz-row--alert' : ''; ?>">
                            <td><?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($e['event_type'], ENT_QUOTES, 'UTF-8'); ?><?php echo ((int)$e['is_incident'] === 1) ? ' ⚠️' : ''; ?></td>
                            <td><?php echo (int)$e['away_seconds']; ?>s</td>
                            <td><?php echo htmlspecialchars(formatParisTime($e['created_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <header class="card-header">
        <h2><?php echo htmlspecialchars($i18n->t('quiz.admin.roster_title'), ENT_QUOTES, 'UTF-8'); ?> (<?php echo count($students); ?>)</h2>
    </header>
    <div class="card-body">
        <?php if (count($students) > 0): ?>
            <form method="post" action="/quiz-admin/students/update">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="id" value="<?php echo $sid; ?>">
                <table class="quiz-table">
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_first_name'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_last_name'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th>Code</th>
                            <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_email'), ENT_QUOTES, 'UTF-8'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $st): ?>
                            <?php $field = 'students[' . (int)$st['id'] . ']'; ?>
                            <tr>
                                <td><input type="text" name="<?php echo $field; ?>[first_name]" required class="quiz-input quiz-input--inline"
                                           value="<?php echo htmlspecialchars($st['first_name'], ENT_QUOTES, 'UTF-8'); ?>"></td>
                                <td><input type="text" name="<?php echo $field; ?>[last_name]" class="quiz-input quiz-input--inline"
                                           value="<?php echo htmlspecialchars($st['last_name'], ENT_QUOTES, 'UTF-8'); ?>"></td>
                                <td><code><?php echo htmlspecialchars($st['code'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td>
                                    <input type="email" name="<?php echo $field; ?>[email]" class="quiz-input quiz-input--inline"
                                           value="<?php echo htmlspecialchars((string)($st['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                           placeholder="—">
                                    <?php if (!empty($st['code_email_sent_at'])): ?>
                                        <span class="quiz-email-sent" title="<?php echo htmlspecialchars(formatParisTime($st['code_email_sent_at']), ENT_QUOTES, 'UTF-8'); ?>">✉️ <?php echo htmlspecialchars($i18n->t('quiz.admin.email_sent_badge'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <?php $delMsg = sprintf($i18n->t('quiz.admin.student_delete_confirm'), trim($st['first_name'] . ' ' . $st['last_name'])); ?>
                                    <button type="submit" class="btn ghost quiz-btn-small quiz-btn-danger"
                                            formaction="/quiz-admin/students/delete" formnovalidate
                                            name="student_id" value="<?php echo (int)$st['id']; ?>"
                                            title="<?php echo htmlspecialchars($i18n->t('quiz.admin.student_delete'), ENT_QUOTES, 'UTF-8'); ?>"
                                            onclick="return confirm(<?php echo htmlspecialchars(json_encode($delMsg, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>);">🗑️</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn primary" style="margin-top: 12px;">💾 <?php echo htmlspecialchars($i18n->t('quiz.admin.students_save'), ENT_QUOTES, 'UTF-8'); ?></button>
            </form>

            <form method="post" action="/quiz-admin/email-codes" style="margin-top: 12px;"
                  onsubmit="return confirm('<?php echo htmlspecialchars($i18n->t('quiz.admin.email_confirm'), ENT_QUOTES, 'UTF-8'); ?>');">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="id" value="<?php echo $sid; ?>">
                <button type="submit" class="btn primary">✉️ <?php echo htmlspecialchars($i18n->t('quiz.admin.email_send'), ENT_QUOTES, 'UTF-8'); ?></button>
            </form>
        <?php endif; ?>

        <form method="post" action="/quiz-admin/roster" class="quiz-join-form" style="margin-top: 16px;" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="id" value="<?php echo $sid; ?>">
            <div class="quiz-field">
                <label for="qa-roster-add"><?php echo htmlspecialchars($i18n->t('quiz.admin.roster_add'), ENT_QUOTES, 'UTF-8'); ?></label>
                <textarea id="qa-roster-add" name="roster" class="quiz-input" rows="4" placeholder="Léa Dupont lea.dupont@ecole.fr&#10;Karim Benali"></textarea>
            </div>
            <div class="quiz-field">
                <label for="qa-roster-add-file"><?php echo htmlspecialchars($i18n->t('quiz.admin.field_roster_file'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="file" id="qa-roster-add-file" name="roster_file" accept=".csv,.txt" class="quiz-input">
            </div>
            <button type="submit" class="btn ghost"><?php echo htmlspecialchars($i18n->t('quiz.admin.roster_submit'), ENT_QUOTES, 'UTF-8'); ?></button>
        </form>
    </div>
</section>

<section class="card">
    <header class="card-header">
        <h2><?php echo htmlspecialchars($i18n->t('quiz.admin.events_by_student'), ENT_QUOTES, 'UTF-8'); ?></h2>
    </header>
    <div class="card-body">
        <?php if (count($students) === 0): ?>
            <p><?php echo htmlspecialchars($i18n->t('quiz.admin.no_events'), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php else: ?>
            <?php
                $attemptsByStudent = [];
                foreach ($attempts as $a) {
                    $attemptsByStudent[(int)$a['student_id']] = $a;
                }
                $maxIncidents = (int)$session['max_incidents'];
            ?>
            <table class="quiz-table" id="qa-students-table">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_student'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_events'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_incidents'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_status'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $st): ?>
                        <?php
                            $a = $attemptsByStudent[(int)$st['id']] ?? null;
                            $overQuota = $a !== null && (int)$a['incident_count'] >= $maxIncidents;
                            $isFinished = $a !== null && !empty($a['finished_at']);
                        ?>
                        <tr class="<?php echo $overQuota ? 'quiz-row--alert' : ''; ?>" data-student-id="<?php echo (int)$st['id']; ?>">
                            <td>
                                <?php echo htmlspecialchars($st['first_name'] . ' ' . $st['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                                <span class="quiz-quota-badge" data-role="quota" <?php echo $overQuota ? '' : 'hidden'; ?>>⚠️ <?php echo htmlspecialchars($i18n->t('quiz.admin.quota_exceeded'), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="quiz-finished-badge" data-role="finished" <?php echo $isFinished ? '' : 'hidden'; ?>>✅ <?php echo htmlspecialchars($i18n->t('quiz.admin.finished_badge'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </td>
                            <?php if ($a === null): ?>
                                <td data-role="events">—</td>
                                <td data-role="incidents">—</td>
                                <td data-role="status" class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.admin.not_joined'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-role="action"></td>
                            <?php else: ?>
                                <td data-role="events"><?php echo (int)$a['event_count']; ?></td>
                                <td data-role="incidents"><?php echo (int)$a['incident_count']; ?> / <?php echo $maxIncidents; ?></td>
                                <td data-role="status"><span class="quiz-status--<?php echo htmlspecialchars($a['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($a['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td data-role="action"><a class="btn ghost" href="/quiz-admin/attempt?id=<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($i18n->t('quiz.admin.view_detail'), ENT_QUOTES, 'UTF-8'); ?></a></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>

</div>

<script>
(function () {
    'use strict';
    var body = document.getElementById('qa-attempts-body');
    var counter = document.getElementById('qa-connected-count');
    var sessionId = <?php echo $sid; ?>;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    var finishedBadge = <?php echo json_encode('✅ ' . $i18n->t('quiz.admin.finished_badge')); ?>;
    var viewDetailLabel = <?php echo json_encode($i18n->t('quiz.admin.view_detail')); ?>;

    // Mirrors each attempt into the static "events by student" table so the
    // counters, statuses and badges stay live without reloading the page.
    function updateStudentRow(a, maxIncidents) {
        var row = document.querySelector('#qa-students-table tr[data-student-id="' + a.student_id + '"]');
        if (!row) { return; }
        var overQuota = a.incident_count >= maxIncidents;
        row.classList.toggle('quiz-row--alert', overQuota);

        var quota = row.querySelector('[data-role="quota"]');
        if (quota) { quota.hidden = !overQuota; }
        var fin = row.querySelector('[data-role="finished"]');
        if (fin) { fin.hidden = !a.finished; }

        var events = row.querySelector('[data-role="events"]');
        if (events) { events.textContent = String(a.event_count); }
        var incidents = row.querySelector('[data-role="incidents"]');
        if (incidents) { incidents.textContent = a.incident_count + ' / ' + maxIncidents; }
        var status = row.querySelector('[data-role="status"]');
        if (status) {
            status.classList.remove('quiz-hint');
            status.innerHTML = '<span class="quiz-status--' + esc(a.status) + '">' + esc(a.status) + '</span>';
        }
        var action = row.querySelector('[data-role="action"]');
        if (action && !action.firstChild) {
            action.innerHTML = '<a class="btn ghost" href="/quiz-admin/attempt?id=' + a.attempt_id + '">' + esc(viewDetailLabel) + '</a>';
        }
    }

    function refresh() {
        fetch('/quiz-admin/api/attempts?id=' + sessionId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !Array.isArray(data.attempts)) { return; }
                var maxIncidents = typeof data.max_incidents === 'number' ? data.max_incidents : 0;
                var connected = 0;
                var html = '';
                data.attempts.forEach(function (a) {
                    if (a.connected) { connected++; }
                    var dot = a.connected ? '🟢' : (a.last_seen_seconds === null ? '⚪' : '🟠');
                    var seen = a.connected ? '' : (a.last_seen_seconds !== null ? ' (' + a.last_seen_seconds + 's)' : '');
                    var fin = a.finished ? ' <span class="quiz-finished-badge">' + finishedBadge + '</span>' : '';
                    html += '<tr>'
                        + '<td>' + esc(a.first_name + ' ' + a.last_name) + fin + '</td>'
                        + '<td>' + dot + seen + '</td>'
                        + '<td>' + a.incident_count + '</td>'
                        + '<td><span class="quiz-status--' + esc(a.status) + '">' + esc(a.status) + '</span></td>'
                        + '</tr>';
                    updateStudentRow(a, maxIncidents);
                });
                if (html === '') {
                    html = '<tr><td colspan="4"><?php echo htmlspecialchars($i18n->t('quiz.admin.no_attempts'), ENT_QUOTES, 'UTF-8'); ?></td></tr>';
                }
                body.innerHTML = html;
                counter.textContent = ' — ' + connected + '/' + data.attempts.length + ' 🟢';
            })
            .catch(function () {});
    }

    refresh();
    setInterval(refresh, 5000);
})();

// Live event feed: poll for events newer than the last one displayed
(function () {
    'use strict';
    var tbody = document.getElementById('qa-feed-body');
    if (!tbody) { return; }
    var sessionId = <?php echo $sid; ?>;
    var lastId = <?php echo (int)($recentEvents[0]['id'] ?? 0); ?>;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function pollFeed() {
        fetch('/quiz-admin/api/events?id=' + sessionId + '&after=' + lastId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !Array.isArray(data.events) || data.events.length === 0) { return; }
                var empty = document.getElementById('qa-feed-empty');
                if (empty) { empty.remove(); }
                // Events arrive oldest first: prepending each one keeps newest on top
                data.events.forEach(function (e) {
                    lastId = Math.max(lastId, e.id);
                    var tr = document.createElement('tr');
                    if (e.is_incident && !e.excused) { tr.className = 'quiz-row--alert'; }
                    tr.innerHTML = '<td>' + esc(e.first_name + ' ' + e.last_name) + '</td>'
                        + '<td>' + esc(e.event_type) + (e.is_incident ? ' ⚠️' : '') + '</td>'
                        + '<td>' + e.away_seconds + 's</td>'
                        + '<td>' + esc(e.date) + '</td>';
                    tbody.insertBefore(tr, tbody.firstChild);
                });
                while (tbody.children.length > 100) {
                    tbody.removeChild(tbody.lastChild);
                }
            })
            .catch(function () {});
    }

    setInterval(pollFeed, 5000);
})();
</script>
