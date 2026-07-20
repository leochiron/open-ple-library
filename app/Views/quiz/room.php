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
    </div>
    <div class="quiz-banner__center">
        <span class="quiz-banner__timer" id="quiz-timer">--:--</span>
    </div>
    <div class="quiz-banner__right">
        <span id="quiz-incidents" class="quiz-banner__incidents" data-count="<?php echo (int)$attempt['incident_count']; ?>">
            <?php echo htmlspecialchars($i18n->t('quiz.room.incidents'), ENT_QUOTES, 'UTF-8'); ?> :
            <strong id="quiz-incidents-count"><?php echo (int)$attempt['incident_count']; ?></strong>/<?php echo (int)$session['max_incidents']; ?>
        </span>
        <button type="button" id="quiz-finish-btn" class="quiz-finish-btn" hidden>✅ <?php echo htmlspecialchars($i18n->t('quiz.room.finish_button'), ENT_QUOTES, 'UTF-8'); ?></button>
    </div>
</header>

<main class="quiz-room__main">
    <h2 class="quiz-student-heading"><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?></h2>

    <!-- Lobby: waiting for teacher launch -->
    <section id="quiz-lobby" class="quiz-panel" hidden>
        <h1><?php echo htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
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

    <!-- Finished: the student declared they are done -->
    <section id="quiz-finished" class="quiz-panel" hidden>
        <h1><?php echo htmlspecialchars($i18n->t('quiz.room.finished_title'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p><?php echo htmlspecialchars($i18n->t('quiz.room.finished_message'), ENT_QUOTES, 'UTF-8'); ?></p>
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
    <div id="quiz-away-warning" class="quiz-away-warning" hidden>
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
        state: <?php echo json_encode($state); ?>,
        endpoints: {
            state: '/quiz/api/state',
            heartbeat: '/quiz/api/heartbeat',
            event: '/quiz/api/event'
        },
        minAwaySeconds: <?php echo (int)$session['min_away_seconds']; ?>,
        requireFullscreen: <?php echo !empty($session['require_fullscreen']) ? 'true' : 'false'; ?>,
        finished: <?php echo !empty($attempt['finished_at']) ? 'true' : 'false'; ?>,
        i18n: {
            incidentWarning: <?php echo json_encode($i18n->t('quiz.room.incident_warning')); ?>,
            awayWarning: <?php echo json_encode($i18n->t('quiz.room.away_warning')); ?>,
            keyWarning: <?php echo json_encode($i18n->t('quiz.room.key_warning')); ?>,
            finishConfirm: <?php echo json_encode($i18n->t('quiz.room.finish_confirm')); ?>
        }
    };
</script>
<script src="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>/js/quiz-monitor.js?v=<?php echo $jsVersion; ?>"></script>
</body>
</html>
