<?php

declare(strict_types=1);

/**
 * Integrity report — standalone printable page (outside the site layout).
 * Timestamped timeline of the attempt, proposed verdict, teacher decision box.
 *
 * @var array $session
 * @var array $attempt
 * @var array $events
 * @var App\Services\I18nService $i18n
 */

$studentName = trim((string)$attempt['first_name'] . ' ' . (string)$attempt['last_name']);
$maxIncidents = (int)$session['max_incidents'];
$incidentCount = (int)$attempt['incident_count'];
$excusedCount = 0;
foreach ($events as $e) {
    if ((int)$e['is_incident'] === 1 && !empty($e['excused'])) {
        $excusedCount++;
    }
}
$timeline = array_reverse($events); // chronological order
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($i18n->getLanguage(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?php echo htmlspecialchars($i18n->t('quiz.report.title') . ' — ' . $studentName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetBase(), ENT_QUOTES, 'UTF-8'); ?>/css/quiz.css">
</head>
<body class="quiz-report-page">

<div class="quiz-no-print quiz-admin-actions" style="margin-bottom: 18px;">
    <button onclick="window.print()" class="btn primary quiz-fullscreen-btn"><?php echo htmlspecialchars($i18n->t('quiz.admin.codes_print'), ENT_QUOTES, 'UTF-8'); ?></button>
</div>

<header class="quiz-report-header">
    <h1><?php echo htmlspecialchars($i18n->t('quiz.report.title'), ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="quiz-report-meta">
        <?php echo htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8'); ?>
        &nbsp;·&nbsp; <?php echo htmlspecialchars($i18n->t('quiz.report.generated_at'), ENT_QUOTES, 'UTF-8'); ?> :
        <?php echo htmlspecialchars(formatParisTime(gmdate('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8'); ?>
    </p>
    <?php if (isset($archive)): ?>
        <p><strong><?php echo htmlspecialchars($i18n->t('quiz.history.snapshot'), ENT_QUOTES, 'UTF-8'); ?> #<?php echo (int)$archive['id']; ?></strong>
            · <?php echo htmlspecialchars(formatParisTime($archive['archived_at']), ENT_QUOTES, 'UTF-8'); ?>
            · <?php echo htmlspecialchars($archive['actor_name'], ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.history.nonce'), ENT_QUOTES, 'UTF-8'); ?> : <code><?php echo htmlspecialchars($archive['generation'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></code><br>
            <?php echo htmlspecialchars($i18n->t('quiz.history.generations'), ENT_QUOTES, 'UTF-8'); ?> : <code><?php echo htmlspecialchars(implode(', ', $archive['snapshot']['event_generations']) ?: '—', ENT_QUOTES, 'UTF-8'); ?></code>
        </p>
        <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.history.read_only'), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
</header>

<section class="quiz-report-section">
    <h2 class="quiz-report-student"><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?></h2>
    <table class="quiz-table">
        <?php if (!isset($archive)): ?><tr>
            <th><?php echo htmlspecialchars($i18n->t('quiz.report.code'), ENT_QUOTES, 'UTF-8'); ?></th>
            <td><code><?php echo htmlspecialchars((string)$attempt['code'], ENT_QUOTES, 'UTF-8'); ?></code></td>
            <th><?php echo htmlspecialchars($i18n->t('quiz.report.token'), ENT_QUOTES, 'UTF-8'); ?></th>
            <td><code><?php echo htmlspecialchars((string)$attempt['public_token'], ENT_QUOTES, 'UTF-8'); ?></code></td>
        </tr><?php endif; ?>
        <tr>
            <th><?php echo htmlspecialchars($i18n->t('quiz.admin.joined_at'), ENT_QUOTES, 'UTF-8'); ?></th>
            <td><?php echo htmlspecialchars(formatParisTime($attempt['started_at']), ENT_QUOTES, 'UTF-8'); ?></td>
            <th><?php echo htmlspecialchars($i18n->t('quiz.admin.last_seen'), ENT_QUOTES, 'UTF-8'); ?></th>
            <td><?php echo htmlspecialchars(formatParisTime($attempt['last_heartbeat_at']), ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
        <tr>
            <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_incidents'), ENT_QUOTES, 'UTF-8'); ?></th>
            <td><strong><?php echo $incidentCount; ?> / <?php echo $maxIncidents; ?></strong>
                <?php if ($excusedCount > 0): ?>
                    (+ <?php echo $excusedCount; ?> <?php echo htmlspecialchars($i18n->t('quiz.report.excused_count'), ENT_QUOTES, 'UTF-8'); ?>)
                <?php endif; ?>
            </td>
            <th><?php echo htmlspecialchars($i18n->t('quiz.report.verdict'), ENT_QUOTES, 'UTF-8'); ?></th>
            <td><span class="quiz-status--<?php echo htmlspecialchars($attempt['status'], ENT_QUOTES, 'UTF-8'); ?>"><strong><?php echo htmlspecialchars($i18n->t('quiz.report.verdict_' . $attempt['status']), ENT_QUOTES, 'UTF-8'); ?></strong></span></td>
        </tr>
        <tr>
            <th><?php echo htmlspecialchars($i18n->t('quiz.report.rules'), ENT_QUOTES, 'UTF-8'); ?></th>
            <td colspan="3">
                <?php echo (int)$session['duration_minutes']; ?> min
                &nbsp;·&nbsp; <?php echo htmlspecialchars($i18n->t('quiz.admin.field_min_away'), ENT_QUOTES, 'UTF-8'); ?> : <?php echo (int)$session['min_away_seconds']; ?>s
                <?php if (!empty($session['require_fullscreen'])): ?>&nbsp;·&nbsp; <?php echo htmlspecialchars($i18n->t('quiz.admin.rule_fullscreen'), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                <?php if (!empty($session['reload_is_incident'])): ?>&nbsp;·&nbsp; <?php echo htmlspecialchars($i18n->t('quiz.admin.rule_reload'), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
            </td>
        </tr>
    </table>
    <h3><?php echo htmlspecialchars($i18n->t('quiz.access.title'), ENT_QUOTES, 'UTF-8'); ?></h3>
    <?php if (isset($accessContext)): ?>
        <p><?php echo htmlspecialchars($i18n->t($accessContext['manual_blocked'] ? 'quiz.access.blocked' : 'quiz.access.allowed'), ENT_QUOTES, 'UTF-8'); ?>
            <?php if (!empty($accessContext['reason'])): ?> · <?php echo htmlspecialchars($accessContext['reason'] . ' · ' . $accessContext['actor_name'], ENT_QUOTES, 'UTF-8'); ?>
                · <?php echo htmlspecialchars(formatParisTime($accessContext['changed_at']), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p>
    <?php else: ?><p><?php echo htmlspecialchars($i18n->t('quiz.access.not_recorded'), ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <h3><?php echo htmlspecialchars($i18n->t('quiz.override.title'), ENT_QUOTES, 'UTF-8'); ?></h3>
    <?php $technicalOverride = $accessContext['technical_override'] ?? null; include __DIR__ . '/override-details.php'; ?>
    <?php include __DIR__ . '/tracking-details.php'; ?>
</section>

<section class="quiz-report-section">
    <h3><?php echo htmlspecialchars($i18n->t('quiz.report.timeline'), ENT_QUOTES, 'UTF-8'); ?> (<?php echo count($timeline); ?>)</h3>
    <?php if (count($timeline) === 0): ?>
        <p><?php echo htmlspecialchars($i18n->t('quiz.admin.no_events'), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php else: ?>
        <table class="quiz-table">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_date'), ENT_QUOTES, 'UTF-8'); ?></th>
                    <th>Type</th>
                    <th><?php echo htmlspecialchars($i18n->t('quiz.admin.col_away'), ENT_QUOTES, 'UTF-8'); ?></th>
                    <th><?php echo htmlspecialchars($i18n->t('quiz.report.col_qualification'), ENT_QUOTES, 'UTF-8'); ?></th>
                    <?php if (isset($archive)): ?><th><?php echo htmlspecialchars($i18n->t('quiz.history.event_generation'), ENT_QUOTES, 'UTF-8'); ?></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($timeline as $e): ?>
                    <?php
                        $isIncident = (int)$e['is_incident'] === 1;
                        $isExcused = !empty($e['excused']);
                    ?>
                    <tr class="<?php echo $isIncident ? ($isExcused ? 'quiz-row--excused' : 'quiz-row--alert') : ''; ?>">
                        <td><?php echo htmlspecialchars(formatParisTime($e['created_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(quizEventLabel($e, $i18n), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(quizEventDuration($e), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if ($isIncident && $isExcused): ?>
                                <?php echo htmlspecialchars($i18n->t('quiz.report.qual_excused'), ENT_QUOTES, 'UTF-8'); ?>
                            <?php elseif ($isIncident): ?>
                                ⚠️ <?php echo htmlspecialchars($i18n->t('quiz.report.qual_incident'), ENT_QUOTES, 'UTF-8'); ?>
                            <?php else: ?>
                                <?php echo htmlspecialchars($i18n->t('quiz.report.qual_info'), ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </td>
                        <?php if (isset($archive)): ?><td><code title="<?php echo htmlspecialchars($e['tracking_generation'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(substr($e['tracking_generation'] ?? '', 0, 8) ?: '—', ENT_QUOTES, 'UTF-8'); ?></code></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.journal.received_time'), ENT_QUOTES, 'UTF-8'); ?></p>
</section>

<section class="quiz-report-section quiz-report-decision">
    <h3><?php echo htmlspecialchars($i18n->t('quiz.report.decision_title'), ENT_QUOTES, 'UTF-8'); ?></h3>
    <p>
        ☐ <?php echo htmlspecialchars($i18n->t('quiz.report.decision_valid'), ENT_QUOTES, 'UTF-8'); ?>
        &nbsp;&nbsp;&nbsp; ☐ <?php echo htmlspecialchars($i18n->t('quiz.report.decision_invalid'), ENT_QUOTES, 'UTF-8'); ?>
        &nbsp;&nbsp;&nbsp; ☐ <?php echo htmlspecialchars($i18n->t('quiz.report.decision_other'), ENT_QUOTES, 'UTF-8'); ?>
    </p>
    <p class="quiz-report-lines"><?php echo htmlspecialchars($i18n->t('quiz.report.decision_comment'), ENT_QUOTES, 'UTF-8'); ?> :</p>
    <div class="quiz-report-writein"></div>
    <div class="quiz-report-writein"></div>
    <p style="margin-top: 24px;">
        <?php echo htmlspecialchars($i18n->t('quiz.report.decision_date'), ENT_QUOTES, 'UTF-8'); ?> : ______________________
        &nbsp;&nbsp;&nbsp;&nbsp; <?php echo htmlspecialchars($i18n->t('quiz.report.decision_signature'), ENT_QUOTES, 'UTF-8'); ?> : ______________________
    </p>
</section>

<footer class="quiz-report-footer">
    <p class="quiz-hint"><?php echo htmlspecialchars($i18n->t('quiz.report.footer'), ENT_QUOTES, 'UTF-8'); ?></p>
</footer>

</body>
</html>
