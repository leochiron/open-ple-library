<?php

declare(strict_types=1);

/**
 * Dedicated page: every monitoring event of one student's attempt.
 *
 * @var array $session
 * @var array $attempt
 * @var array $events
 * @var App\Services\I18nService $i18n
 */

$studentName = trim((string)$attempt['first_name'] . ' ' . (string)$attempt['last_name']);
$maxIncidents = (int)$session['max_incidents'];
$overQuota = (int)$attempt['incident_count'] >= $maxIncidents;
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetBase(), ENT_QUOTES, 'UTF-8'); ?>/css/quiz.css">

<div class="quiz-admin-grid">

<section class="card">
    <header class="card-header">
        <h1><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?></h1>
    </header>
    <div class="card-body">
        <p>
            <?php echo htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8'); ?>
            &nbsp;·&nbsp; <?php echo htmlspecialchars($i18n->t('quiz.admin.col_status'), ENT_QUOTES, 'UTF-8'); ?> :
            <span class="quiz-status--<?php echo htmlspecialchars($attempt['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($attempt['status'], ENT_QUOTES, 'UTF-8'); ?></span>
            &nbsp;·&nbsp; <?php echo htmlspecialchars($i18n->t('quiz.admin.col_incidents'), ENT_QUOTES, 'UTF-8'); ?> :
            <?php echo (int)$attempt['incident_count']; ?> / <?php echo $maxIncidents; ?>
            <?php if ($overQuota): ?>
                <span class="quiz-quota-badge">⚠️ <?php echo htmlspecialchars($i18n->t('quiz.admin.quota_exceeded'), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </p>
        <p class="quiz-hint">
            <?php echo htmlspecialchars($i18n->t('quiz.admin.joined_at'), ENT_QUOTES, 'UTF-8'); ?> :
            <?php echo htmlspecialchars(formatParisTime($attempt['started_at']), ENT_QUOTES, 'UTF-8'); ?>
            &nbsp;·&nbsp; <?php echo htmlspecialchars($i18n->t('quiz.admin.last_seen'), ENT_QUOTES, 'UTF-8'); ?> :
            <?php echo htmlspecialchars(formatParisTime($attempt['last_heartbeat_at']), ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <div class="quiz-admin-actions">
            <a class="btn primary" href="/quiz-admin/report?id=<?php echo (int)$attempt['id']; ?>" target="_blank"><?php echo htmlspecialchars($i18n->t('quiz.admin.action_report'), ENT_QUOTES, 'UTF-8'); ?></a>
            <a class="btn ghost" href="/quiz-admin/session?id=<?php echo (int)$session['id']; ?>"><?php echo htmlspecialchars($i18n->t('quiz.admin.back_session'), ENT_QUOTES, 'UTF-8'); ?></a>
        </div>
    </div>
</section>

<section class="card">
    <header class="card-header">
        <h2><?php echo htmlspecialchars($i18n->t('quiz.admin.attempt_events_title'), ENT_QUOTES, 'UTF-8'); ?> (<?php echo count($events); ?>)</h2>
    </header>
    <div class="card-body">
        <?php if (count($events) === 0): ?>
            <p><?php echo htmlspecialchars($i18n->t('quiz.admin.no_events'), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php else: ?>
            <table class="quiz-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_away'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_date'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_arbitration'), ENT_QUOTES, 'UTF-8'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $e): ?>
                        <?php
                            $isIncident = (int)$e['is_incident'] === 1;
                            $isExcused = !empty($e['excused']);
                            $rowClass = $isIncident ? ($isExcused ? 'quiz-row--excused' : 'quiz-row--alert') : '';
                        ?>
                        <tr class="<?php echo $rowClass; ?>">
                            <td>
                                <?php echo htmlspecialchars($e['event_type'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($isIncident): ?>
                                    <?php echo $isExcused ? '✓' : ' ⚠️'; ?>
                                    <?php if ($isExcused): ?>
                                        <span class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.admin.excused_label'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int)$e['away_seconds']; ?>s</td>
                            <td><?php echo htmlspecialchars(formatParisTime($e['created_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if ($isIncident): ?>
                                    <form method="post" action="/quiz-admin/event/excuse" style="display:inline;">
                                        <input type="hidden" name="event_id" value="<?php echo (int)$e['id']; ?>">
                                        <input type="hidden" name="attempt_id" value="<?php echo (int)$attempt['id']; ?>">
                                        <input type="hidden" name="excused" value="<?php echo $isExcused ? '0' : '1'; ?>">
                                        <button type="submit" class="btn ghost quiz-btn-small">
                                            <?php echo htmlspecialchars($i18n->t($isExcused ? 'quiz.admin.reinstate' : 'quiz.admin.excuse'), ENT_QUOTES, 'UTF-8'); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>

</div>
