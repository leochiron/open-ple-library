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

    var currentState = cfg.state.state;
    var serverOffset = 0; // serverNow - clientNow, recomputed on each poll
    var endsAtServer = null; // server timestamp (s) when the quiz ends
    var iframeInjected = false;
    var timeoverShown = false;
    var finished = !!cfg.finished; // student declared they are done: monitoring stops
    var completionPending = false; // wait for the server acknowledgement

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
        resumeStatus: document.getElementById('quiz-resume-status')
    };

    // ------------------------------------------------------------------
    // State handling
    // ------------------------------------------------------------------

    function applyState(state) {
        applyRules(state);
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
        if (finished || currentState !== 'running') { fsExitSince = null; }

        if (currentState !== 'running') {
            // Teacher stopped or closed the quiz: clear timer state and overlays
            endsAtServer = null;
            if (timeoverShown) {
                timeoverShown = false;
                hide(els.timeover);
            }
        }

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
            if (!requireFullscreen) { fsExitSince = null; }
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
        fetch(cfg.endpoints.state, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (state) {
                if (state && state.state) {
                    applyState(state);
                }
            })
            .catch(function () { /* transient network error: keep last state */ })
            .finally(scheduleNextPoll);
    }

    var pollTimeout = null;
    function scheduleNextPoll() {
        clearTimeout(pollTimeout);
        var delay = currentState === 'running' ? POLL_RUNNING_MS : POLL_LOBBY_MS;
        if (currentState === 'closed') {
            return;
        }
        pollTimeout = setTimeout(poll, delay);
    }

    function heartbeat() {
        fetch(cfg.endpoints.heartbeat, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true
        }).catch(function () { /* will retry on next tick */ });
    }

    // ------------------------------------------------------------------
    // Timer
    // ------------------------------------------------------------------

    function tickTimer() {
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

        if (!finished && remaining === 0 && !timeoverShown) {
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

    var awaySince = null; // ms timestamp when the page went away
    var awayKind = null;  // 'hidden' | 'blur'

    function sendEvent(type, awaySeconds) {
        var payload = JSON.stringify({ type: type, away_seconds: awaySeconds || 0 });

        // sendBeacon survives page unload; fall back to fetch keepalive
        var sent = false;
        if (navigator.sendBeacon) {
            sent = navigator.sendBeacon(cfg.endpoints.event, new Blob([payload], { type: 'application/json' }));
        }
        if (!sent) {
            fetch(cfg.endpoints.event, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'Content-Type': 'application/json' },
                body: payload
            }).then(function (r) { return r.json(); })
              .then(function (result) {
                  if (result && typeof result.incident_count === 'number') {
                      updateIncidents(result.incident_count, result.status);
                      if (result.is_incident) {
                          showAwayWarning(cfg.i18n.incidentWarning);
                      }
                  }
              })
              .catch(function () {});
            return;
        }

        // Beacon gives no response: refresh counters via a state poll shortly after
        setTimeout(poll, 800);
    }

    function markAway(kind) {
        if (awaySince === null && currentState === 'running' && !finished) {
            awaySince = Date.now();
            awayKind = kind;
        }
    }

    function markBack() {
        if (awaySince === null) {
            return;
        }
        var awaySeconds = Math.round((Date.now() - awaySince) / 1000);
        var kind = awayKind;
        awaySince = null;
        awayKind = null;

        if (awaySeconds < 1) {
            return; // micro flicker, ignore
        }

        sendEventWithFeedback(kind, awaySeconds);
    }

    function sendEventWithFeedback(type, awaySeconds) {
        var payload = JSON.stringify({ type: type, away_seconds: awaySeconds });
        fetch(cfg.endpoints.event, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: payload
        }).then(function (r) { return r.json(); })
          .then(function (result) {
              if (result && typeof result.incident_count === 'number') {
                  updateIncidents(result.incident_count, result.status);
                  if (result.is_incident) {
                      showAwayWarning(cfg.i18n.incidentWarning.replace('{seconds}', String(awaySeconds)));
                  } else if (awaySeconds >= 3) {
                      showAwayWarning(cfg.i18n.awayWarning.replace('{seconds}', String(awaySeconds)));
                  }
              }
          })
          .catch(function () {});
    }

    var warningTimeout = null;
    function showAwayWarning(text) {
        if (!els.awayWarning) {
            return;
        }
        els.awayWarningText.textContent = text;
        show(els.awayWarning);
        clearTimeout(warningTimeout);
        warningTimeout = setTimeout(function () { hide(els.awayWarning); }, 6000);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            markAway('hidden');
        } else {
            markBack();
        }
    });

    window.addEventListener('blur', function () {
        // Clicking into the Google Form iframe blurs the parent window:
        // that is normal exam activity, not an exit.
        setTimeout(function () {
            var active = document.activeElement;
            if (active && active.id === 'quiz-form-iframe') {
                return;
            }
            if (document.visibilityState === 'hidden') {
                return; // already handled by visibilitychange
            }
            markAway('blur');
        }, 0);
    });

    window.addEventListener('focus', function () {
        markBack();
    });

    window.addEventListener('pagehide', function () {
        if (currentState === 'running' && !finished) {
            sendEvent('leave', 0);
        }
    });

    // Focus probe: closes the iframe blind spot. Blur events are masked when
    // focus sits inside the cross-origin Google Form iframe, but
    // document.hasFocus() still answers for the whole window — so switching
    // to another application is caught even mid-typing in the form.
    setInterval(function () {
        if (currentState !== 'running' || finished) {
            return;
        }
        if (document.visibilityState === 'hidden') {
            return; // already handled by visibilitychange
        }
        if (!document.hasFocus()) {
            markAway('blur');
        } else if (awayKind === 'blur') {
            markBack();
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
        sendEventWithFeedback(type, 0);
        showAwayWarning(cfg.i18n.keyWarning);
    }

    document.addEventListener('keydown', function (ev) {
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
    document.addEventListener('copy', function () { if (currentState === 'running' && !finished) { reportKey('copy'); } });
    document.addEventListener('cut', function () { if (currentState === 'running' && !finished) { reportKey('copy'); } });
    document.addEventListener('paste', function () { if (currentState === 'running' && !finished) { reportKey('paste'); } });

    // ------------------------------------------------------------------
    // Mandatory fullscreen (per-session rule)
    // ------------------------------------------------------------------

    var requireFullscreen = !!cfg.requireFullscreen;
    var fsExitSince = null; // ms timestamp when fullscreen was left (page visible)

    function isFullscreen() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement);
    }

    function updateFullscreenGate() {
        if (!els.fsGate) {
            return;
        }
        var needGate = requireFullscreen && currentState === 'running' && !finished && !isFullscreen();
        els.fsGate.hidden = !needGate;
    }

    if (els.fsBtn) {
        els.fsBtn.addEventListener('click', function () {
            var el = document.documentElement;
            var fn = el.requestFullscreen || el.webkitRequestFullscreen;
            if (fn) {
                try { fn.call(el); } catch (e) { /* denied: gate stays */ }
            }
        });

        var onFsChange = function () {
            if (!requireFullscreen || currentState !== 'running' || finished) {
                fsExitSince = null;
                updateFullscreenGate();
                return;
            }
            if (isFullscreen()) {
                if (fsExitSince !== null) {
                    var away = Math.round((Date.now() - fsExitSince) / 1000);
                    fsExitSince = null;
                    if (away >= 1) {
                        sendEventWithFeedback('fullscreen_exit', away);
                    }
                }
            } else if (currentState === 'running' && !finished && document.visibilityState === 'visible') {
                // ESC or browser UI exit while the page stays visible.
                // Alt-tab / minimize exits are already covered by visibilitychange.
                fsExitSince = Date.now();
            }
            updateFullscreenGate();
        };
        document.addEventListener('fullscreenchange', onFsChange);
        document.addEventListener('webkitfullscreenchange', onFsChange);
    }

    // ------------------------------------------------------------------
    // "I am done" button: records the finish, leaves fullscreen and stops
    // the monitoring so the student can freely close or leave the page.
    // ------------------------------------------------------------------

    function saveCompletion(type, statusEl) {
        if (completionPending || currentState !== 'running') { return; }
        completionPending = true;
        els.finishBtn.disabled = true;
        els.resumeBtn.disabled = true;
        statusEl.textContent = cfg.i18n.finishPending;
        var aborter = new AbortController();
        var timeout = setTimeout(function () { aborter.abort(); }, 12000);
        fetch(cfg.endpoints.event, {
            method: 'POST', credentials: 'same-origin', signal: aborter.signal,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: type, away_seconds: 0 })
        }).then(function (r) {
            if (!r.ok) { throw new Error('completion_not_saved'); }
            return r.json();
        }).then(function (result) {
            if (typeof result.finished !== 'boolean' || result.finished !== (type === 'finish')) {
                throw new Error('completion_not_acknowledged');
            }
            finished = result.finished;
            completionPending = false;
            awaySince = null;
            awayKind = null;
            fsExitSince = null;
            statusEl.textContent = '';
            if (finished && isFullscreen()) {
                var exitFn = document.exitFullscreen || document.webkitExitFullscreen;
                if (exitFn) { try { exitFn.call(document); } catch (e) {} }
            }
            if (!finished) { els.submitConfirm.checked = false; }
            applyState({ state: currentState });
        }).catch(function () {
            completionPending = false;
            statusEl.textContent = cfg.i18n.finishError;
        }).finally(function () {
            clearTimeout(timeout);
            els.finishBtn.disabled = completionPending || !els.submitConfirm.checked;
            els.resumeBtn.disabled = completionPending;
        });
    }
    if (els.finishBtn) {
        els.submitConfirm.addEventListener('change', function () {
            els.finishBtn.disabled = completionPending || !els.submitConfirm.checked;
        });
        els.finishBtn.addEventListener('click', function () {
            if (finished || !els.submitConfirm.checked || currentState !== 'running') { return; }
            saveCompletion('finish', els.finishStatus);
        });
        els.resumeBtn.addEventListener('click', function () {
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
    setInterval(tickTimer, 500);
})();
