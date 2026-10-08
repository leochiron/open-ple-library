/**
 * Quiz room monitor.
 * - Polls /quiz/api/state to drive lobby → exam → closed transitions.
 * - Sends a heartbeat every 10s (server-side liveness proof).
 * - Detects page exits (tab switch, minimize, app switch) and reports them;
 *   the server decides whether an absence counts as an incident.
 *
 * Known limitation (documented): when focus is inside the cross-origin Google
 * Form iframe, the parent window cannot see every focus change. Tab switches
 * and minimize are still caught by visibilitychange.
 */
(function () {
    'use strict';

    var cfg = window.QUIZ_ROOM;
    if (!cfg) {
        return;
    }

    var HEARTBEAT_MS = 10000;
    var POLL_LOBBY_MS = 3000;
    var POLL_RUNNING_MS = 15000;
    var PERMISSION_GUARD_MS = 500;

    var currentState = cfg.state.state;
    var serverOffset = 0; // serverNow - clientNow, recomputed on each poll
    var endsAtServer = null; // server timestamp (s) when the quiz ends
    var iframeInjected = false;
    var timeoverShown = false;
    var finished = !!cfg.finished; // student declared they are done: monitoring stops
    var completionPending = false; // wait for the server acknowledgement
    var detached = false;
    var accessAllowed = cfg.state.access_allowed !== false;
    var pollSequence = 0;
    var settingsRevision = -1, permissionDeadline = null;
    function strengthened(mode) { return mode === 'preflight' || mode === 'continuous'; }
    function boundUrl(url) { return url + (url.indexOf('?') === -1 ? '?' : '&') + 'attempt_id=' + cfg.attemptId + (cfg.roomEpoch ? '&room_epoch=' + encodeURIComponent(cfg.roomEpoch) : ''); }
    function clock() { try { var value = performance.now(); return Number.isFinite(value) ? value : null; } catch (ignored) { return null; } }
    function elapsed(started) { var end = clock(); return started === null || end === null ? Infinity : Math.max(0, end - started); }
    function visibility() { try { return document.visibilityState; } catch (ignored) { return null; } }
    function focus() { try { var value = document.hasFocus(); return typeof value === 'boolean' ? value : null; } catch (ignored) { return null; } }
    function listen(target, type, handler, capture) { try { target.addEventListener(type, handler, capture); } catch (ignored) {} }
    function request(url, init) {
        var aborter = null, timeout;
        try { aborter = new AbortController(); } catch (ignored) {}
        return new Promise(function (resolve, reject) {
            timeout = setTimeout(function () { try { if (aborter) { aborter.abort(); } } catch (ignored) {} reject(new Error('access_unavailable')); }, 12000);
            try {
                var transport = Object.assign({}, init);
                try { if (aborter) { transport.signal = aborter.signal; } } catch (ignored) {}
                Promise.resolve(fetch(url, transport)).then(function (response) {
                    return Promise.resolve(response.json()).then(function (value) { return { response: response, value: value }; });
                }).then(resolve, reject);
            } catch (ignored) { reject(new Error('access_unavailable')); }
        }).finally(function () { clearTimeout(timeout); });
    }

    var els = {
        lobby: document.getElementById('quiz-lobby'),
        exam: document.getElementById('quiz-exam'),
        closed: document.getElementById('quiz-closed'),
        iframeWrap: document.getElementById('quiz-iframe-wrap'),
        timer: document.getElementById('quiz-timer'),
        incidents: document.getElementById('quiz-incidents'),
        incidentsCount: document.getElementById('quiz-incidents-count'),
        incidentsMax: document.getElementById('quiz-incidents-max'),
        timeover: document.getElementById('quiz-timeover'),
        awayWarning: document.getElementById('quiz-away-warning'),
        awayWarningText: document.getElementById('quiz-away-warning-text'),
        fsGate: document.getElementById('quiz-fullscreen-gate'),
        fsBtn: document.getElementById('quiz-fullscreen-btn'),
        finished: document.getElementById('quiz-finished'),
        finishBtn: document.getElementById('quiz-finish-btn'),
        completion: document.getElementById('quiz-completion'),
        submitConfirm: document.getElementById('quiz-submit-confirm'),
        finishStatus: document.getElementById('quiz-finish-status'),
        resumeBtn: document.getElementById('quiz-resume-btn'),
        resumeStatus: document.getElementById('quiz-resume-status'),
        trackingStatus: document.getElementById('quiz-tracking-status'),
        accessUnavailable: document.getElementById('quiz-access-unavailable')
    };
    var journal = window.QuizEventJournal({
        attemptId: cfg.attemptId, endpoint: boundUrl(cfg.endpoints.event),
        getCsrfToken: function () { return cfg.csrfToken; },
        getRoomEpoch: function () { return cfg.roomEpoch; },
        onAck: function (result, event) {
            updateIncidents(result.incident_count, result.status);
            if (result.access_allowed === false) { ++pollSequence; applyState({ state: currentState, access_allowed: false }); }
            var seconds = event.duration_ms === undefined ? 0 : event.duration_ms / 1000;
            if (result.is_incident) { showAwayWarning(cfg.i18n.incidentWarning.replace('{seconds}', String(seconds))); }
            else if (seconds >= 3) { showAwayWarning(cfg.i18n.awayWarning.replace('{seconds}', String(seconds))); }
        },
        onStatus: function (status) {
            if (!els.trackingStatus) { return; }
            els.trackingStatus.hidden = !status.detached;
            els.trackingStatus.textContent = status.detached ? cfg.i18n.trackingDetached : '';
        },
        onDetached: function () {
            detached = true;
            if (preflight) { preflight.stop(); }
            [els.exam, els.lobby, els.closed, els.finished, els.completion, els.accessUnavailable, els.fsGate, els.timeover, els.awayWarning].forEach(hide);
        },
        onConflict: poll
    });
    journal.setGeneration(cfg.state.tracking_generation);
    var preflight = window.QuizTrackingPreflight ? window.QuizTrackingPreflight({
        config: cfg,
        isFinished: function () { return finished; },
        isCompletionPending: function () { return completionPending; },
        isDetached: function () { return detached; },
        onDecisionRequest: function () { return ++pollSequence; },
        onState: function (state, elapsed, sequence) {
            if (sequence !== pollSequence) { return false; }
            applyState(state, elapsed);
            return true;
        },
        onConflict: poll
    }) : null;

    // ------------------------------------------------------------------
    // State handling
    // ------------------------------------------------------------------

    function applyState(state, elapsed) {
        if (typeof state.attempt_id === 'number' && state.attempt_id !== cfg.attemptId) { journal.detach(); return; }
        if (detached) { return; }
        if (typeof state.settings_revision === 'number' && state.settings_revision < settingsRevision) { return; }
        if (typeof state.settings_revision === 'number') { settingsRevision = state.settings_revision; }
        cfg.state = Object.assign({}, cfg.state, state);
        if (typeof state.csrf_token === 'string') { cfg.csrfToken = state.csrf_token; }
        if (typeof state.access_allowed === 'boolean') { accessAllowed = state.access_allowed; }
        if (state.tracking_generation) { journal.setGeneration(state.tracking_generation); }
        applyRules(state);
        if (strengthened(cfg.state.tracking_mode)) {
            var tick = clock();
            if (tick === null || (elapsed !== undefined && !Number.isFinite(elapsed))) { accessAllowed = false; permissionDeadline = null; }
            else if (typeof state.access_allowed === 'boolean') {
                if (!state.access_allowed) { permissionDeadline = null; }
                else if (typeof state.access_until === 'number' && typeof state.server_now === 'number') {
                    // server_now is integer seconds. Reserve quantization and the next guard tick.
                    permissionDeadline = tick + Math.max(0, (state.access_until - state.server_now) * 1000 - (elapsed || 0) - 1000 - PERMISSION_GUARD_MS);
                }
                // A completion ACK without timing cannot clear/extend the current permission lease.
                else if (permissionDeadline === null) { accessAllowed = false; }
                if (state.access_allowed && permissionDeadline !== null && permissionDeadline <= tick) { accessAllowed = false; }
            }
        } else if (cfg.state.tracking_mode === 'off') { permissionDeadline = null; }
        if (preflight) { preflight.update(Object.assign({}, cfg.state, { access_allowed: accessAllowed })); }
        if (typeof state.server_now === 'number') {
            serverOffset = state.server_now - Math.floor(Date.now() / 1000);
        }
        if (typeof state.remaining_seconds === 'number' && state.remaining_seconds !== null) {
            endsAtServer = state.server_now + state.remaining_seconds;
        }
        if (typeof state.incident_count === 'number') {
            updateIncidents(state.incident_count, state.attempt_status);
        }
        if (typeof state.finished === 'boolean' && !completionPending) {
            finished = state.finished;
        }

        currentState = state.state;
        if (finished) { hide(els.timeover); timeoverShown = false; }
        if (finished || currentState !== 'running') { journal.discard(); }

        if (currentState !== 'running') {
            // Teacher stopped or closed the quiz: clear timer state and overlays
            endsAtServer = null;
            if (timeoverShown) {
                timeoverShown = false;
                hide(els.timeover);
            }
        }

        if (!accessAllowed) {
            [els.lobby, els.exam, els.closed, els.finished, els.completion, els.fsGate, els.timeover, els.awayWarning].forEach(hide);
            timeoverShown = false;
            show(els.accessUnavailable);
            if (els.finishBtn) { els.finishBtn.disabled = true; els.resumeBtn.disabled = true; }
            return;
        }
        hide(els.accessUnavailable);

        if (finished && currentState !== 'closed') {
            // The student declared they are done: the form stays hidden,
            // even if the page is reloaded while the quiz is still running.
            hide(els.lobby);
            hide(els.exam);
            hide(els.closed);
            show(els.finished);
        } else if (currentState === 'lobby') {
            show(els.lobby);
            hide(els.exam);
            hide(els.closed);
            hide(els.finished);
        } else if (currentState === 'running') {
            hide(els.lobby);
            hide(els.closed);
            hide(els.finished);
            show(els.exam);
            if (!iframeInjected && state.form_url) {
                injectIframe(state.form_url);
            }
        } else if (currentState === 'closed') {
            hide(els.lobby);
            hide(els.exam);
            hide(els.finished);
            show(els.closed);
        }

        if (els.finishBtn) {
            els.completion.hidden = finished || currentState !== 'running';
            els.finishBtn.disabled = completionPending || !els.submitConfirm.checked;
            els.resumeBtn.hidden = !finished || currentState !== 'running';
            els.resumeBtn.disabled = completionPending;
        }
        updateFullscreenGate();
    }

    function injectIframe(url) {
        var iframe = document.createElement('iframe');
        iframe.src = url;
        iframe.id = 'quiz-form-iframe';
        iframe.className = 'quiz-iframe';
        iframe.setAttribute('referrerpolicy', 'no-referrer');
        els.iframeWrap.appendChild(iframe);
        iframeInjected = true;
    }

    function show(el) { if (el) { el.hidden = false; } }
    function hide(el) { if (el) { el.hidden = true; } }

    function applyRules(state) {
        if (typeof state.title === 'string') {
            document.title = state.title;
            var title = document.getElementById('quiz-title');
            if (title) { title.textContent = state.title; }
        }
        ['duration_minutes', 'max_incidents', 'min_away_seconds'].forEach(function (key) {
            if (typeof state[key] !== 'number') { return; }
            if (key === 'duration_minutes') { cfg.durationMinutes = state[key]; }
            if (key === 'max_incidents') { cfg.maxIncidents = state[key]; }
            var el = document.getElementById('quiz-rule-' + key);
            if (el) { el.textContent = String(state[key]); }
        });
        if (typeof state.max_incidents === 'number' && els.incidentsMax) {
            els.incidentsMax.textContent = String(state.max_incidents);
        }
        if (typeof state.min_away_seconds === 'number') { cfg.minAwaySeconds = state.min_away_seconds; }
        if (typeof state.require_fullscreen === 'boolean') {
            requireFullscreen = state.require_fullscreen;
            var fullscreenRule = document.getElementById('quiz-rule-fullscreen');
            if (fullscreenRule) { fullscreenRule.hidden = !requireFullscreen; }
        }
        if (typeof state.reload_is_incident === 'boolean') {
            cfg.reloadIsIncident = state.reload_is_incident;
            var reloadRule = document.getElementById('quiz-rule-reload');
            if (reloadRule) { reloadRule.hidden = !state.reload_is_incident; }
        }
    }

    function updateIncidents(count, status) {
        if (els.incidentsCount) {
            els.incidentsCount.textContent = String(count);
        }
        if (els.incidents) {
            els.incidents.dataset.count = String(count);
            els.incidents.classList.toggle('quiz-banner__incidents--alert', count > 0);
            els.incidents.classList.toggle('quiz-banner__incidents--invalid', status === 'invalid');
        }
    }

    // ------------------------------------------------------------------
    // Polling & heartbeat
    // ------------------------------------------------------------------

    function poll() {
        if (detached) { return; }
        var sequence = ++pollSequence;
        var started = clock();
        request(boundUrl(cfg.endpoints.state), { credentials: 'same-origin' })
            .then(function (result) {
                var r = result.response;
                if (sequence !== pollSequence) { return null; }
                if (r.status === 409 || r.status === 401 || r.status === 403) { journal.detach(); return null; }
                return result.value;
            })
            .then(function (state) {
                if (sequence === pollSequence && state && state.state) {
                    applyState(state, elapsed(started));
                }
            })
            .catch(function () { /* transient network error: keep last state */ })
            .finally(scheduleNextPoll);
    }

    var pollTimeout = null;
    function scheduleNextPoll() {
        clearTimeout(pollTimeout);
        var delay = currentState === 'running' && accessAllowed ? POLL_RUNNING_MS : POLL_LOBBY_MS;
        if (currentState === 'closed') {
            return;
        }
        pollTimeout = setTimeout(poll, delay);
    }

    function heartbeat() {
        if (detached) { return; }
        request(boundUrl(cfg.endpoints.heartbeat), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Quiz-CSRF': cfg.csrfToken },
            keepalive: true
        }).catch(function () { /* will retry on next tick */ });
    }

    // ------------------------------------------------------------------
    // Timer
    // ------------------------------------------------------------------

    function tickTimer() {
        if (detached) { hide(els.timeover); return; }
        var tick = clock();
        if (strengthened(cfg.state.tracking_mode) && accessAllowed && (tick === null || permissionDeadline === null || tick >= permissionDeadline)) { ++pollSequence; applyState({ state: currentState, access_allowed: false }); }
        if (!els.timer) {
            return;
        }
        if (currentState !== 'running' || endsAtServer === null) {
            els.timer.textContent = '--:--';
            return;
        }
        var nowServer = Math.floor(Date.now() / 1000) + serverOffset;
        var remaining = Math.max(0, endsAtServer - nowServer);
        var m = Math.floor(remaining / 60);
        var s = remaining % 60;
        els.timer.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        els.timer.classList.toggle('quiz-banner__timer--low', remaining > 0 && remaining <= 120);

        if (accessAllowed && !finished && remaining === 0 && !timeoverShown) {
            timeoverShown = true;
            show(els.timeover);
        } else if (remaining > 0 && timeoverShown) {
            // The teacher relaunched the quiz: clear the stale "time over" overlay
            timeoverShown = false;
            hide(els.timeover);
        }
    }

    // ------------------------------------------------------------------
    // Monitoring: page exits
    // ------------------------------------------------------------------

    function sendEvent(type) {
        var source = type === 'reload' ? 'navigation' : (type === 'leave' ? 'page' : 'shortcut');
        if (!detached && (!preflight || preflight.captureAllowed(source))) { journal.observe(type, source); }
    }
    function markAway(kind) {
        if (currentState === 'running' && !finished && !detached && (!preflight || preflight.captureAllowed(kind))) { journal.start(kind); }
    }
    function markBack(kind) { journal.end(kind); }

    var warningTimeout = null;
    function showAwayWarning(text) {
        if (!els.awayWarning || detached || !accessAllowed) {
            return;
        }
        els.awayWarningText.textContent = text;
        show(els.awayWarning);
        clearTimeout(warningTimeout);
        warningTimeout = setTimeout(function () { hide(els.awayWarning); }, 6000);
    }

    listen(document, 'visibilitychange', function () {
        if (visibility() === 'hidden') {
            markAway('hidden');
        } else if (visibility() === 'visible') {
            markBack('hidden');
            if (focus() === true) { markBack('blur'); }
        }
    });

    listen(window, 'blur', function () {
        // Clicking into the Google Form iframe blurs the parent window:
        // that is normal exam activity, not an exit.
        setTimeout(function () {
            var active;
            try { active = document.activeElement; } catch (ignored) { return; }
            if (active && active.id === 'quiz-form-iframe') {
                return;
            }
            markAway('blur');
        }, 0);
    });

    listen(window, 'focus', function () {
        if (visibility() === 'visible') { markBack('blur'); }
    });

    listen(window, 'pagehide', function () {
        if (currentState === 'running' && !finished) {
            sendEvent('leave');
        }
        journal.beacon();
    });

    // Focus probe: closes the iframe blind spot. Blur events are masked when
    // focus sits inside the cross-origin Google Form iframe, but
    // document.hasFocus() still answers for the whole window — so switching
    // to another application is caught even mid-typing in the form.
    setInterval(function () {
        if (currentState !== 'running' || finished) {
            return;
        }
        if (visibility() !== 'visible') {
            return; // already handled by visibilitychange
        }
        var focused = focus();
        if (focused === false) {
            markAway('blur');
        } else if (focused === true) {
            markBack('blur');
        }
    }, 1000);

    // ------------------------------------------------------------------
    // Forbidden shortcuts: F12 / devtools, clipboard, print, view-source.
    // Blocked AND recorded. Known limit: keystrokes typed while focus is
    // inside the cross-origin Google Form iframe are invisible to this page.
    // ------------------------------------------------------------------

    var keyThrottle = {};

    function reportKey(type) {
        var now = Date.now();
        if (keyThrottle[type] && now - keyThrottle[type] < 5000) {
            return; // one report per type per 5s, no event spam
        }
        keyThrottle[type] = now;
        sendEvent(type);
        showAwayWarning(cfg.i18n.keyWarning);
    }

    listen(document, 'keydown', function (ev) {
        if (currentState !== 'running' || finished) {
            return;
        }
        var key = (ev.key || '').toLowerCase();
        var ctrl = ev.ctrlKey || ev.metaKey;

        // Devtools: F12, Ctrl+Shift+I/J/C, Ctrl+U (view source)
        if (ev.key === 'F12' || (ctrl && ev.shiftKey && (key === 'i' || key === 'j' || key === 'c')) || (ctrl && !ev.shiftKey && key === 'u')) {
            ev.preventDefault();
            reportKey('devtools');
            return;
        }
        if (ctrl && !ev.shiftKey && (key === 'c' || key === 'x')) {
            ev.preventDefault();
            reportKey('copy');
            return;
        }
        if (ctrl && !ev.shiftKey && key === 'v') {
            ev.preventDefault();
            reportKey('paste');
            return;
        }
        if (ctrl && key === 'p') {
            ev.preventDefault();
            reportKey('print');
        }
    }, true);

    // Context-menu copy/paste does not go through keydown
    listen(document, 'copy', function () { if (currentState === 'running' && !finished) { reportKey('copy'); } });
    listen(document, 'cut', function () { if (currentState === 'running' && !finished) { reportKey('copy'); } });
    listen(document, 'paste', function () { if (currentState === 'running' && !finished) { reportKey('paste'); } });

    // ------------------------------------------------------------------
    // Mandatory fullscreen (per-session rule)
    // ------------------------------------------------------------------

    var requireFullscreen = !!cfg.requireFullscreen;

    function isFullscreen() {
        try { return !!(document.fullscreenElement || document.webkitFullscreenElement); } catch (ignored) { return null; }
    }

    function updateFullscreenGate() {
        if (!els.fsGate) {
            return;
        }
        var needGate = !strengthened(cfg.state.tracking_mode) && !detached && accessAllowed && requireFullscreen && currentState === 'running' && !finished && !isFullscreen();
        els.fsGate.hidden = !needGate;
    }

    if (els.fsBtn) {
        listen(els.fsBtn, 'click', function () {
            try { var el = document.documentElement; var fn = el.requestFullscreen || el.webkitRequestFullscreen; if (fn) { var promise = fn.call(el); if (promise && promise.catch) { promise.catch(function () {}); } } } catch (ignored) {}
        });

        var onFsChange = function () {
            if (currentState === 'running' && !finished && !detached) {
                var fullscreen = isFullscreen();
                if (fullscreen === true) { markBack('fullscreen_exit'); }
                else if (fullscreen === false) { markAway('fullscreen_exit'); }
            } else { journal.discard('fullscreen_exit'); }
            updateFullscreenGate();
        };
        listen(document, 'fullscreenchange', onFsChange);
        listen(document, 'webkitfullscreenchange', onFsChange);
    }

    // ------------------------------------------------------------------
    // "I am done" button: records the finish, leaves fullscreen and stops
    // the monitoring so the student can freely close or leave the page.
    // ------------------------------------------------------------------

    function saveCompletion(type, statusEl) {
        if (completionPending || currentState !== 'running' || detached || !accessAllowed) { return; }
        if (strengthened(cfg.state.tracking_mode) && clock() === null) { ++pollSequence; applyState({ state: currentState, access_allowed: false }); return; }
        completionPending = true;
        els.finishBtn.disabled = true;
        els.resumeBtn.disabled = true;
        statusEl.textContent = cfg.i18n.finishPending;
        var completionPayload = journal.explicitPayload(type, 'page');
        var strengthenedCompletion = strengthened(cfg.state.tracking_mode);
        var sequence = ++pollSequence;
        request(boundUrl(cfg.endpoints.event), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({}, completionPayload, { _csrf: cfg.csrfToken, room_epoch: cfg.roomEpoch }))
        }).then(function (response) {
            var r = response.response;
            if (r.status === 409 || r.status === 403) { poll(); }
            if (!r.ok) { throw new Error('completion_not_saved'); }
            return response.value;
        }).then(function (result) {
            if ((strengthenedCompletion || strengthened(cfg.state.tracking_mode)) && sequence !== pollSequence) {
                completionPending = false;
                statusEl.textContent = '';
                // The mutation may have succeeded; only a fresh state may now reconcile it.
                poll();
                return;
            }
            // Historical mode keeps its explicit completion acknowledgement behavior.
            if (!strengthenedCompletion && !strengthened(cfg.state.tracking_mode)) { ++pollSequence; }
            if (result.access_allowed === false) {
                completionPending = false;
                statusEl.textContent = '';
                applyState(Object.assign({ state: currentState }, result));
                return;
            }
            if (result.attempt_id !== cfg.attemptId || result.event_uid !== completionPayload.event_uid || typeof result.finished !== 'boolean' || result.finished !== (type === 'finish')) {
                throw new Error('completion_not_acknowledged');
            }
            finished = result.finished;
            completionPending = false;
            journal.discard();
            statusEl.textContent = '';
            if (finished && isFullscreen()) {
                try { var exitFn = document.exitFullscreen || document.webkitExitFullscreen; if (exitFn) { var exitPromise = exitFn.call(document); if (exitPromise && exitPromise.catch) { exitPromise.catch(function () {}); } } } catch (ignored) {}
            }
            if (!finished) { els.submitConfirm.checked = false; }
            applyState({ state: currentState });
        }).catch(function () {
            completionPending = false;
            statusEl.textContent = accessAllowed ? cfg.i18n.finishError : '';
        }).finally(function () {
            els.finishBtn.disabled = !accessAllowed || completionPending || !els.submitConfirm.checked;
            els.resumeBtn.disabled = !accessAllowed || completionPending;
        });
    }
    if (els.finishBtn) {
        listen(els.submitConfirm, 'change', function () {
            els.finishBtn.disabled = !accessAllowed || completionPending || !els.submitConfirm.checked;
        });
        listen(els.finishBtn, 'click', function () {
            if (finished || !els.submitConfirm.checked || currentState !== 'running') { return; }
            saveCompletion('finish', els.finishStatus);
        });
        listen(els.resumeBtn, 'click', function () {
            if (finished) { saveCompletion('resume', els.resumeStatus); }
        });
    }

    // Reload detection: a normal first load right after joining is fine,
    // a re-load during a running quiz is worth recording.
    try {
        var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
        if (nav && nav.type === 'reload' && currentState === 'running' && !finished) {
            sendEvent('reload', 0);
        }
    } catch (e) { /* old browser: ignore */ }

    // ------------------------------------------------------------------
    // Start
    // ------------------------------------------------------------------

    applyState(cfg.state);
    scheduleNextPoll();
    heartbeat();
    setInterval(heartbeat, HEARTBEAT_MS);
    setInterval(tickTimer, PERMISSION_GUARD_MS);
})();
