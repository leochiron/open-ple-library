<?php

declare(strict_types=1);

/**
 * Fullscreen classroom board — projected during the exam.
 * One big tile per student, auto-refreshing: name + live count of cheating
 * alerts, colour-coded status, and a ticker that announces every screen exit.
 *
 * Standalone page (no site layout): needs the whole projector viewport.
 *
 * @var array $session
 * @var array $students
 * @var App\Services\I18nService $i18n
 */

$assetBase = assetBase();
$cssVersionPath = __DIR__ . '/../../../public/assets/css/quiz.css';
$cssVersion = is_file($cssVersionPath) ? filemtime($cssVersionPath) : time();
$sid = (int)$session['id'];
$joinHost = (string)($_SERVER['HTTP_HOST'] ?? '');
$initialPin = (string)($session['access_pin'] ?? '');

// Event types worth shouting on the board, with their human label.
$eventLabels = [
    'hidden' => $i18n->t('quiz.admin.evt_hidden'),
    'blur' => $i18n->t('quiz.admin.evt_blur'),
    'fullscreen_exit' => $i18n->t('quiz.admin.evt_fullscreen_exit'),
    'leave' => $i18n->t('quiz.admin.evt_leave'),
    'reload' => $i18n->t('quiz.admin.evt_reload'),
    'devtools' => $i18n->t('quiz.admin.evt_devtools'),
    'copy' => $i18n->t('quiz.admin.evt_copy'),
    'paste' => $i18n->t('quiz.admin.evt_paste'),
    'print' => $i18n->t('quiz.admin.evt_print'),
];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($i18n->getLanguage(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?php echo htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars($i18n->t('quiz.admin.board_title'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>/css/quiz.css?v=<?php echo $cssVersion; ?>">
</head>
<body class="quiz-board">

<header class="quiz-board__bar">
    <div class="quiz-board__heading">
        <span class="quiz-board__live"><span class="quiz-live-dot" aria-hidden="true"></span> <?php echo htmlspecialchars($i18n->t('quiz.admin.board_title'), ENT_QUOTES, 'UTF-8'); ?></span>
        <h1 class="quiz-board__title"><?php echo htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
    </div>
    <div class="quiz-board__pin" id="qb-pin"<?php echo $initialPin === '' ? ' hidden' : ''; ?>>
        <span class="quiz-board__pin-label"><?php echo htmlspecialchars($i18n->t('quiz.admin.board_pin'), ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="quiz-board__pin-code" id="qb-pin-code"><?php echo htmlspecialchars($initialPin, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php if ($joinHost !== ''): ?>
            <span class="quiz-board__pin-url"><?php echo htmlspecialchars($joinHost . '/quiz', ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
    </div>

    <div class="quiz-board__meta">
        <span class="quiz-board__timer" id="qb-timer">--:--</span>
        <span class="quiz-board__stat"><strong id="qb-connected">0</strong> <?php echo htmlspecialchars($i18n->t('quiz.admin.board_connected'), ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="quiz-board__stat quiz-board__stat--alert"><strong id="qb-alerts">0</strong> <?php echo htmlspecialchars($i18n->t('quiz.admin.board_alerts_total'), ENT_QUOTES, 'UTF-8'); ?></span>
        <button type="button" id="qb-sound" class="quiz-board__btn" title="Son">🔇</button>
        <button type="button" id="qb-fs" class="quiz-board__btn" title="Plein écran">⛶</button>
    </div>
</header>

<div id="qb-ticker" class="quiz-board__ticker" hidden></div>

<main class="quiz-board__grid" id="qb-grid">
    <?php if (count($students) === 0): ?>
        <p class="quiz-board__empty"><?php echo htmlspecialchars($i18n->t('quiz.admin.board_no_students'), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php else: ?>
        <?php foreach ($students as $st): ?>
            <article class="quiz-board__tile is-waiting" data-student-id="<?php echo (int)$st['id']; ?>">
                <?php $fullName = trim($st['first_name'] . ' ' . $st['last_name']); ?>
                <div class="quiz-board__name" title="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="quiz-board__count" data-role="count">0</div>
                <div class="quiz-board__state" data-role="status"><?php echo htmlspecialchars($i18n->t('quiz.admin.board_waiting'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="quiz-board__last" data-role="last"></div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<footer class="quiz-board__legend"><?php echo htmlspecialchars($i18n->t('quiz.admin.board_legend'), ENT_QUOTES, 'UTF-8'); ?></footer>

<script>
window.QUIZ_BOARD = {
    sessionId: <?php echo $sid; ?>,
    maxIncidents: <?php echo (int)$session['max_incidents']; ?>,
    endpoints: {
        attempts: '/quiz-admin/api/attempts?id=<?php echo $sid; ?>',
        events: '/quiz-admin/api/events?id=<?php echo $sid; ?>'
    },
    labels: {
        events: <?php echo json_encode($eventLabels, JSON_UNESCAPED_UNICODE); ?>,
        waiting: <?php echo json_encode($i18n->t('quiz.admin.board_waiting')); ?>,
        offline: <?php echo json_encode($i18n->t('quiz.admin.board_offline')); ?>,
        finished: <?php echo json_encode($i18n->t('quiz.admin.board_finished')); ?>,
        ok: <?php echo json_encode($i18n->t('quiz.admin.board_ok')); ?>,
        alerts: <?php echo json_encode($i18n->t('quiz.admin.board_alerts')); ?>
    }
};
</script>
<script>
(function () {
    'use strict';
    var cfg = window.QUIZ_BOARD;

    var grid = document.getElementById('qb-grid');
    var ticker = document.getElementById('qb-ticker');
    var connectedEl = document.getElementById('qb-connected');
    var alertsEl = document.getElementById('qb-alerts');
    var timerEl = document.getElementById('qb-timer');
    var pinWrap = document.getElementById('qb-pin');
    var pinCode = document.getElementById('qb-pin-code');

    var lastEventId = 0;
    var primed = false;      // first events poll only records the cursor, no replay
    var endsAt = null;       // server timestamp (s) when the quiz ends
    var serverOffset = 0;    // serverNow - clientNow

    function tile(studentId) {
        return grid.querySelector('.quiz-board__tile[data-student-id="' + studentId + '"]');
    }

    // ---- Session loss (expired / logged out) -------------------------------
    var timers = [];
    var sessionDead = false;
    function sessionLost() {
        if (sessionDead) { return; }
        sessionDead = true;
        timers.forEach(clearInterval);
        ticker.hidden = false;
        ticker.classList.add('quiz-board__ticker--dead');
        ticker.innerHTML = '🔒 Session expirée — <a href="/quiz-admin" style="color:#fff;text-decoration:underline;">reconnecte-toi</a> puis rouvre le tableau.';
    }
    function asJson(r) {
        if (r.status === 401) { sessionLost(); return null; }
        return r.json();
    }

    // ---- Per-student status from /api/attempts -----------------------------
    function refreshAttempts() {
        if (sessionDead) { return; }
        fetch(cfg.endpoints.attempts, { credentials: 'same-origin' })
            .then(asJson)
            .then(function (data) {
                if (!data || !Array.isArray(data.attempts)) { return; }

                if (typeof data.server_now === 'number') {
                    serverOffset = data.server_now - Math.floor(Date.now() / 1000);
                }

                // Room PIN (shown only while the room is open: lobby / running)
                if (pinWrap && pinCode) {
                    if (data.pin) {
                        if (pinCode.textContent !== data.pin) { pinCode.textContent = data.pin; }
                        pinWrap.hidden = false;
                    } else {
                        pinWrap.hidden = true;
                    }
                }
                endsAt = (data.state === 'running' && typeof data.remaining_seconds === 'number' && data.remaining_seconds !== null)
                    ? data.server_now + data.remaining_seconds
                    : null;

                var connected = 0;
                var totalAlerts = 0;
                data.attempts.forEach(function (a) {
                    if (a.connected) { connected++; }
                    totalAlerts += a.incident_count;
                    paintTile(a, data.state);
                });
                connectedEl.textContent = connected + '/' + data.attempts.length;
                alertsEl.textContent = String(totalAlerts);
            })
            .catch(function () {});
    }

    function paintTile(a, state) {
        var el = tile(a.student_id);
        if (!el) { return; }

        var countEl = el.querySelector('[data-role="count"]');
        var statusEl = el.querySelector('[data-role="status"]');
        if (countEl) { countEl.textContent = String(a.incident_count); }

        el.classList.remove('is-waiting', 'is-ok', 'is-warn', 'is-danger', 'is-finished', 'is-offline');

        if (a.finished) {
            el.classList.add('is-finished');
            if (statusEl) { statusEl.textContent = cfg.labels.finished; }
        } else if (a.incident_count >= cfg.maxIncidents) {
            el.classList.add('is-danger');
            if (statusEl) { statusEl.textContent = a.incident_count + ' ' + cfg.labels.alerts; }
        } else if (a.incident_count > 0) {
            el.classList.add('is-warn');
            if (statusEl) { statusEl.textContent = a.incident_count + ' ' + cfg.labels.alerts; }
        } else {
            el.classList.add('is-ok');
            if (statusEl) { statusEl.textContent = cfg.labels.ok; }
        }

        // Offline overlay only matters while the quiz is actually running
        if (!a.connected && state === 'running' && !a.finished) {
            el.classList.add('is-offline');
            if (statusEl) { statusEl.textContent = cfg.labels.offline; }
        }
    }

    // ---- Live alerts from /api/events --------------------------------------
    function refreshEvents() {
        if (sessionDead) { return; }
        fetch(cfg.endpoints.events + '&after=' + lastEventId, { credentials: 'same-origin' })
            .then(asJson)
            .then(function (data) {
                if (!data || !Array.isArray(data.events)) { return; }
                var fullBatch = data.events.length >= 100; // server LIMIT: more history may follow
                data.events.forEach(function (e) {
                    lastEventId = Math.max(lastEventId, e.id);
                    if (!primed) { return; } // skip history on first load (no replay storm)
                    var label = cfg.labels.events[e.event_type];
                    if (!label) { return; } // join/rejoin/finish etc.: not an alert
                    announce(e, label);
                });
                if (!fullBatch) { primed = true; } // caught up: announce from here on
            })
            .catch(function () {});
    }

    function announce(e, label) {
        var name = (e.first_name + ' ' + e.last_name).trim();
        var time = (e.date || '').split(' ').pop();

        // Flash the student's tile
        var el = tile(e.student_id);
        if (el) {
            var lastEl = el.querySelector('[data-role="last"]');
            if (lastEl) { lastEl.textContent = '⚠ ' + label + ' · ' + time; }
            el.classList.remove('flash');
            void el.offsetWidth; // restart the animation
            el.classList.add('flash');
        }

        // Ticker banner
        ticker.hidden = false;
        ticker.textContent = '⚠ ' + name + ' ' + label + '  ·  ' + time;
        ticker.classList.remove('flash');
        void ticker.offsetWidth;
        ticker.classList.add('flash');

        beep();
    }

    // ---- Countdown ----------------------------------------------------------
    setInterval(function () {
        if (endsAt === null) { timerEl.textContent = '--:--'; return; }
        var nowServer = Math.floor(Date.now() / 1000) + serverOffset;
        var remaining = Math.max(0, endsAt - nowServer);
        var m = Math.floor(remaining / 60);
        var s = remaining % 60;
        timerEl.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        timerEl.classList.toggle('is-low', remaining > 0 && remaining <= 120);
    }, 500);

    // ---- Optional sound alert ----------------------------------------------
    var soundOn = false;
    var audioCtx = null;
    var soundBtn = document.getElementById('qb-sound');
    soundBtn.addEventListener('click', function () {
        soundOn = !soundOn;
        soundBtn.textContent = soundOn ? '🔊' : '🔇';
        if (soundOn && !audioCtx) {
            try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { audioCtx = null; }
        }
    });
    function beep() {
        if (!soundOn || !audioCtx) { return; }
        try {
            var o = audioCtx.createOscillator();
            var g = audioCtx.createGain();
            o.type = 'sine';
            o.frequency.value = 880;
            g.gain.value = 0.15;
            o.connect(g); g.connect(audioCtx.destination);
            o.start();
            o.stop(audioCtx.currentTime + 0.18);
        } catch (e) { /* ignore */ }
    }

    // ---- Fullscreen toggle --------------------------------------------------
    document.getElementById('qb-fs').addEventListener('click', function () {
        if (document.fullscreenElement) {
            (document.exitFullscreen || function () {}).call(document);
        } else {
            var el = document.documentElement;
            (el.requestFullscreen || el.webkitRequestFullscreen || function () {}).call(el);
        }
    });

    // ---- Start --------------------------------------------------------------
    refreshAttempts();
    refreshEvents();
    timers.push(setInterval(refreshAttempts, 3000));
    timers.push(setInterval(refreshEvents, 3000));
})();
</script>
</body>
</html>
