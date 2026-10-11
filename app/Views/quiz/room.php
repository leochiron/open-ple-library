<?php

declare(strict_types=1);

/**
 * Monitored room — standalone page (no site layout, the exam needs the full viewport).
 * Driven by quiz-monitor.js: lobby → exam → closed, by polling /quiz/api/state.
 *
 * @var array $session
 * @var array $attempt
 * @var array $state
 * @var App\Services\I18nService $i18n
 * @var array $config
 */

$studentName = trim((string)$attempt['first_name'] . ' ' . (string)$attempt['last_name']);
$assetBase = assetBase();
$jsVersionPath = __DIR__ . '/../../../public/assets/js/quiz-monitor.js';
$jsVersion = is_file($jsVersionPath) ? filemtime($jsVersionPath) : time();
$journalVersionPath = __DIR__ . '/../../../public/assets/js/quiz-journal.js';
$journalVersion = is_file($journalVersionPath) ? filemtime($journalVersionPath) : time();
$preflightPath = __DIR__ . '/../../../public/assets/js/quiz-preflight.js';
$preflightVersion = is_file($preflightPath) ? filemtime($preflightPath) : time();
$cssVersionPath = __DIR__ . '/../../../public/assets/css/quiz.css';
$cssVersion = is_file($cssVersionPath) ? filemtime($cssVersionPath) : time();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($i18n->getLanguage(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?php echo htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>/css/quiz.css?v=<?php echo $cssVersion; ?>">
</head>
<body class="quiz-room">

<header class="quiz-banner" id="quiz-banner">
    <div class="quiz-banner__left">
        <span class="quiz-banner__badge"><?php echo htmlspecialchars($i18n->t('quiz.room.monitored'), ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="quiz-banner__student"><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?></span>
        <span id="quiz-strict-label" class="quiz-banner__mode"<?php echo empty($session['require_fullscreen']) ? ' hidden' : ''; ?>><?php echo htmlspecialchars($i18n->t('quiz.room.strict'), ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="quiz-banner__center">
        <span class="quiz-banner__timer" id="quiz-timer">--:--</span>
    </div>
    <div class="quiz-banner__right">
        <span id="quiz-incidents" class="quiz-banner__incidents" data-count="<?php echo (int)$attempt['incident_count']; ?>">
            <?php echo htmlspecialchars($i18n->t('quiz.room.incidents'), ENT_QUOTES, 'UTF-8'); ?> :
            <strong id="quiz-incidents-count"><?php echo (int)$attempt['incident_count']; ?></strong>/<span id="quiz-incidents-max"><?php echo (int)$session['max_incidents']; ?></span>
        </span>
    </div>
</header>

<main class="quiz-room__main">
    <p id="quiz-tracking-status" class="quiz-hint" role="status" hidden></p>
    <div class="quiz-room__identity">
        <h1 class="quiz-student-heading"><?php echo htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="quiz-hint quiz-room__rules">
            <span id="quiz-rule-duration_minutes"><?php echo (int)$session['duration_minutes']; ?></span> min
            · <?php echo htmlspecialchars($i18n->t('quiz.admin.field_max_incidents'), ENT_QUOTES, 'UTF-8'); ?> : <span id="quiz-rule-max_incidents"><?php echo (int)$session['max_incidents']; ?></span>
            · <?php echo htmlspecialchars($i18n->t('quiz.admin.field_min_away'), ENT_QUOTES, 'UTF-8'); ?> : <span id="quiz-rule-min_away_seconds"><?php echo (int)$session['min_away_seconds']; ?></span>s
            <span id="quiz-rule-fullscreen"<?php echo empty($session['require_fullscreen']) ? ' hidden' : ''; ?>> · <?php echo htmlspecialchars($i18n->t('quiz.admin.rule_fullscreen'), ENT_QUOTES, 'UTF-8'); ?></span>
            <span id="quiz-rule-reload"<?php echo empty($session['reload_is_incident']) ? ' hidden' : ''; ?>> · <?php echo htmlspecialchars($i18n->t('quiz.admin.rule_reload'), ENT_QUOTES, 'UTF-8'); ?></span>
        </p>
    </div>
    <section id="quiz-access-unavailable" class="quiz-panel quiz-access-message" role="status"<?php echo ($state['access_allowed'] ?? true) ? ' hidden' : ''; ?>>
        <p><?php echo htmlspecialchars($i18n->t('quiz.journal.detached'), ENT_QUOTES, 'UTF-8'); ?></p>
    </section>
    <section id="quiz-preflight" class="quiz-panel quiz-preparation" aria-labelledby="quiz-preflight-title" hidden>
        <span class="quiz-preparation__eyebrow"><?php echo htmlspecialchars($i18n->t('quiz.preflight.eyebrow'), ENT_QUOTES, 'UTF-8'); ?></span>
        <h2 id="quiz-preflight-title"><?php echo htmlspecialchars($i18n->t('quiz.preflight.title'), ENT_QUOTES, 'UTF-8'); ?></h2>
        <p id="quiz-preflight-intro"><?php echo htmlspecialchars($i18n->t('quiz.preflight.steps'),ENT_QUOTES,'UTF-8'); ?></p>
        <button type="button" id="quiz-preflight-start" class="quiz-room-button"><?php echo htmlspecialchars($i18n->t('quiz.preflight.start'),ENT_QUOTES,'UTF-8'); ?></button>
        <p id="quiz-preflight-status" class="quiz-preparation__status" role="status" aria-live="polite"></p>
        <p id="quiz-preflight-remaining" class="quiz-preparation__remaining" hidden></p>
        <ol id="quiz-preflight-steps" class="quiz-preparation__steps" hidden>
            <li id="quiz-preflight-step-enter" data-done="false"><span><?php echo htmlspecialchars($i18n->t('quiz.preflight.step_enter'), ENT_QUOTES, 'UTF-8'); ?></span><button type="button" id="quiz-preflight-enter" class="quiz-room-button quiz-room-button--secondary" disabled><?php echo htmlspecialchars($i18n->t('quiz.preflight.enter'),ENT_QUOTES,'UTF-8'); ?></button></li>
            <li id="quiz-preflight-step-return" data-done="false"><span><?php echo htmlspecialchars($i18n->t('quiz.preflight.step_return'), ENT_QUOTES, 'UTF-8'); ?></span></li>
            <li id="quiz-preflight-step-fullscreen" data-done="false" hidden><span><?php echo htmlspecialchars($i18n->t('quiz.preflight.step_fullscreen'), ENT_QUOTES, 'UTF-8'); ?></span><button type="button" id="quiz-preflight-fullscreen" class="quiz-room-button quiz-room-button--secondary" hidden disabled><?php echo htmlspecialchars($i18n->t('quiz.preflight.fullscreen'),ENT_QUOTES,'UTF-8'); ?></button></li>
        </ol>
        <button type="button" id="quiz-preflight-submit" class="quiz-room-button" hidden disabled><?php echo htmlspecialchars($i18n->t('quiz.preflight.validate'),ENT_QUOTES,'UTF-8'); ?></button>
    </section>

    <!-- Lobby: waiting for teacher launch -->
    <section id="quiz-lobby" class="quiz-panel" hidden>
        <h1 id="quiz-title"><?php echo htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="quiz-lobby__status">
            <span class="quiz-pulse" aria-hidden="true"></span>
            <?php echo htmlspecialchars($i18n->t('quiz.room.waiting'), ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <div class="quiz-rules quiz-rules--lobby">
            <h2><?php echo htmlspecialchars($i18n->t('quiz.rules.title'), ENT_QUOTES, 'UTF-8'); ?></h2>
            <ul>
                <li><?php echo htmlspecialchars($i18n->t('quiz.rules.item1'), ENT_QUOTES, 'UTF-8'); ?></li>
                <li><?php echo htmlspecialchars($i18n->t('quiz.rules.item2'), ENT_QUOTES, 'UTF-8'); ?></li>
                <li><?php echo htmlspecialchars($i18n->t('quiz.rules.item5'), ENT_QUOTES, 'UTF-8'); ?></li>
                <li><?php echo htmlspecialchars($i18n->t('quiz.rules.item3'), ENT_QUOTES, 'UTF-8'); ?></li>
                <li><?php echo htmlspecialchars($i18n->t('quiz.rules.item4'), ENT_QUOTES, 'UTF-8'); ?></li>
            </ul>
        </div>

        <!-- Greyed-out quiz placeholder: the real form URL is only delivered at launch -->
        <div class="quiz-locked" aria-hidden="true">
            <div class="quiz-locked__bars">
                <span></span><span></span><span></span>
            </div>
            <div class="quiz-locked__icon">🔒</div>
            <p class="quiz-locked__text"><?php echo htmlspecialchars($i18n->t('quiz.room.locked_message'), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </section>

    <!-- Exam: Google Form iframe, injected at launch -->
    <section id="quiz-exam" class="quiz-exam" hidden>
        <div id="quiz-iframe-wrap" class="quiz-iframe-wrap"></div>
    </section>

    <section id="quiz-completion" class="quiz-completion" aria-label="Fin du questionnaire" hidden>
        <p><strong><?php echo htmlspecialchars($i18n->t('quiz.room.submit_step'), ENT_QUOTES, 'UTF-8'); ?></strong></p>
        <p><?php echo htmlspecialchars($i18n->t('quiz.room.finish_step'), ENT_QUOTES, 'UTF-8'); ?></p>
        <label class="quiz-completion__check"><input type="checkbox" id="quiz-submit-confirm"> <?php echo htmlspecialchars($i18n->t('quiz.room.submit_check'), ENT_QUOTES, 'UTF-8'); ?></label>
        <button type="button" id="quiz-finish-btn" class="quiz-finish-btn" disabled><?php echo htmlspecialchars($i18n->t('quiz.room.finish_button'), ENT_QUOTES, 'UTF-8'); ?></button>
        <p id="quiz-finish-status" role="status" aria-live="polite"></p>
    </section>

    <!-- Finished: the student declared they are done -->
    <section id="quiz-finished" class="quiz-panel" hidden>
        <h1><?php echo htmlspecialchars($i18n->t('quiz.room.finished_title'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p><?php echo htmlspecialchars($i18n->t('quiz.room.finished_message'), ENT_QUOTES, 'UTF-8'); ?></p>
        <button type="button" id="quiz-resume-btn" class="quiz-fullscreen-btn" hidden><?php echo htmlspecialchars($i18n->t('quiz.room.resume_button'), ENT_QUOTES, 'UTF-8'); ?></button>
        <p id="quiz-resume-status" role="status" aria-live="polite"></p>
    </section>

    <!-- Closed -->
    <section id="quiz-closed" class="quiz-panel" hidden>
        <h1><?php echo htmlspecialchars($i18n->t('quiz.room.closed_title'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p><?php echo htmlspecialchars($i18n->t('quiz.room.closed_message'), ENT_QUOTES, 'UTF-8'); ?></p>
    </section>

    <!-- Time over: informational strip only, never blocks the form -->
    <div id="quiz-timeover" class="quiz-timeover" hidden>
        <p><?php echo htmlspecialchars($i18n->t('quiz.room.time_over'), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

    <!-- Away warning shown on return after an absence -->
    <div id="quiz-away-warning" class="quiz-away-warning" role="status" aria-live="polite" hidden>
        <p id="quiz-away-warning-text"></p>
    </div>

    <!-- Fullscreen gate: blocks the form until the student is in fullscreen -->
    <div id="quiz-fullscreen-gate" class="quiz-fullscreen-gate" hidden>
        <div class="quiz-fullscreen-gate__box">
            <p><?php echo htmlspecialchars($i18n->t('quiz.room.fullscreen_required'), ENT_QUOTES, 'UTF-8'); ?></p>
            <button type="button" id="quiz-fullscreen-btn" class="quiz-fullscreen-btn"><?php echo htmlspecialchars($i18n->t('quiz.room.fullscreen_button'), ENT_QUOTES, 'UTF-8'); ?></button>
        </div>
    </div>
</main>

<script>
    window.QUIZ_ROOM = {
        attemptId: <?php echo (int)$attempt['id']; ?>,
        csrfToken: <?php echo json_encode($state['csrf_token'] ?? ''); ?>,
        roomEpoch: <?php echo json_encode($roomEpoch ?? ''); ?>,
        state: <?php echo json_encode($state); ?>,
        endpoints: {
            state: '/quiz/api/state',
            heartbeat: '/quiz/api/heartbeat',
            event: '/quiz/api/event',
            challenge: '/quiz/api/tracking/challenge',
            preflight: '/quiz/api/tracking/preflight',
            pulseChallenge: '/quiz/api/tracking/pulse/challenge',
            pulse: '/quiz/api/tracking/pulse'
        },
        minAwaySeconds: <?php echo (int)$session['min_away_seconds']; ?>,
        requireFullscreen: <?php echo !empty($session['require_fullscreen']) ? 'true' : 'false'; ?>,
        finished: <?php echo !empty($attempt['finished_at']) ? 'true' : 'false'; ?>,
        i18n: {
            incidentWarning: <?php echo json_encode($i18n->t('quiz.room.incident_warning')); ?>,
            exitDetected: <?php echo json_encode($i18n->t('quiz.room.exit_detected')); ?>,
            exitReturned: <?php echo json_encode($i18n->t('quiz.room.exit_returned')); ?>,
            exitRecorded: <?php echo json_encode($i18n->t('quiz.room.exit_recorded')); ?>,
            awayWarning: <?php echo json_encode($i18n->t('quiz.room.away_warning')); ?>,
            keyWarning: <?php echo json_encode($i18n->t('quiz.room.key_warning')); ?>,
            finishConfirm: <?php echo json_encode($i18n->t('quiz.room.finish_confirm')); ?>,
            finishPending: <?php echo json_encode($i18n->t('quiz.room.finish_pending')); ?>,
            finishError: <?php echo json_encode($i18n->t('quiz.room.finish_error')); ?>,
            trackingDetached: <?php echo json_encode($i18n->t('quiz.journal.detached')); ?>,
            prepareStart: <?php echo json_encode($i18n->t('quiz.preflight.start')); ?>,
            prepareEnterPrompt: <?php echo json_encode($i18n->t('quiz.preflight.enter')); ?>,
            prepareEnterDetected: <?php echo json_encode($i18n->t('quiz.preflight.enter_detected')); ?>,
            prepareTitle: <?php echo json_encode($i18n->t('quiz.preflight.title')); ?>,
            prepareReturnTitle: <?php echo json_encode($i18n->t('quiz.preflight.return_title')); ?>,
            prepareRecovery: <?php echo json_encode($i18n->t('quiz.preflight.recovery')); ?>,
            prepareIntro: <?php echo json_encode($i18n->t('quiz.preflight.steps')); ?>,
            prepareReturnIntro: <?php echo json_encode($i18n->t('quiz.preflight.return_intro')); ?>,
            prepareKeyboard: <?php echo json_encode($i18n->t('quiz.preflight.now_keyboard')); ?>,
            prepareTab: <?php echo json_encode($i18n->t('quiz.preflight.now_tab')); ?>,
            prepareFullscreen: <?php echo json_encode($i18n->t('quiz.preflight.now_fullscreen')); ?>,
            prepareFullscreenError: <?php echo json_encode($i18n->t('quiz.preflight.fullscreen_error')); ?>,
            prepareValidate: <?php echo json_encode($i18n->t('quiz.preflight.validate')); ?>,
            prepareRetry: <?php echo json_encode($i18n->t('quiz.preflight.retry')); ?>,
            prepareWorking: <?php echo json_encode($i18n->t('quiz.preflight.working')); ?>,
            prepareIdle: <?php echo json_encode($i18n->t('quiz.preflight.idle')); ?>,
            prepareCollecting: <?php echo json_encode($i18n->t('quiz.preflight.collecting')); ?>,
            prepareReady: <?php echo json_encode($i18n->t('quiz.preflight.ready')); ?>,
            prepareExpired: <?php echo json_encode($i18n->t('quiz.preflight.expired')); ?>,
            prepareNetworkError: <?php echo json_encode($i18n->t('quiz.preflight.network_error')); ?>,
            prepareRefused: <?php echo json_encode($i18n->t('quiz.preflight.refused')); ?>,
            prepareRemaining: <?php echo json_encode($i18n->t('quiz.preflight.remaining')); ?>,
            prepareExitFullscreen: <?php echo json_encode($i18n->t('quiz.preflight.exit_fullscreen')); ?>,
            prepareEnterFullscreen: <?php echo json_encode($i18n->t('quiz.room.fullscreen_button')); ?>
        }
    };
</script>
<script src="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>/js/quiz-journal.js?v=<?php echo $journalVersion; ?>"></script>
<script src="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>/js/quiz-preflight.js?v=<?php echo $preflightVersion; ?>"></script>
<script src="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>/js/quiz-monitor.js?v=<?php echo $jsVersion; ?>"></script>
</body>
</html>
