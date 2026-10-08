/** Full preparation and optional20s pulses. Neither enters the observation journal. */
(function () {
    'use strict';
    window.QuizTrackingPreflight = function (options) {
        var cfg = options.config, stage = 'idle', mode = 'off', generation = '', revision = -1;
        var serial = 0, deadline = null, challenge = null, checks = null, removers = [];
        var admitted = false, armed = false, blocked = { hidden: false, blur: false, fullscreen_exit: false };
        var preflightPending = false, pulsePending = false, networkPending = 0, restartAfterSettlement = false;
        var stopped = false;
        var panel = document.getElementById('quiz-preflight');
        var enter = document.getElementById('quiz-preflight-enter');
        var fsButton = document.getElementById('quiz-preflight-fullscreen');
        var submitButton = document.getElementById('quiz-preflight-submit');
        function fullscreen() { return !!(document.fullscreenElement || document.webkitFullscreenElement); }
        function strengthened(value) { return value === 'preflight' || value === 'continuous'; }
        function body(extra) { return Object.assign({ attempt_id: cfg.attemptId, tracking_generation: generation, room_epoch: cfg.roomEpoch, _csrf: cfg.csrfToken }, extra || {}); }
        function request(endpoint, data) {
            var aborter = new AbortController(), timeout = setTimeout(function () { aborter.abort(); }, 12000);
            ++networkPending;
            return fetch(endpoint, { method: 'POST', credentials: 'same-origin', signal: aborter.signal, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
                .then(function (response) { if (!response.ok) { if (options.onConflict) { options.onConflict(); } throw new Error('access_unavailable'); } return response.json(); }).finally(function () {
                    clearTimeout(timeout); --networkPending;
                    if (networkPending === 0 && restartAfterSettlement) { restartAfterSettlement = false; setTimeout(start, 0); }
                });
        }
        function unavailable() { return stopped || (options.isDetached && options.isDetached()); }
        function render() { if (panel) { panel.hidden = unavailable() || !strengthened(mode) || (admitted && stage === 'submitted') || cfg.state.state !== 'running'; } if (fsButton) { fsButton.hidden = !cfg.requireFullscreen; } }
        function cancel() { ++serial; clearTimeout(deadline); removers.forEach(function (remove) { remove(); }); removers = []; challenge = null; if (networkPending === 0) { preflightPending = false; } }
        function listen(target, type, handler) {
            // A blocked registration must still produce a bounded diagnostic with false checks.
            try {
                target.addEventListener(type, handler);
                removers.push(function () { try { target.removeEventListener(type, handler); } catch (ignored) {} });
            } catch (ignored) {}
        }
        function nativeEvent(event) { return event && event.isTrusted === true; }
        function probeListener() {
            // No document event and no key value: test the CURRENT methods on a fresh target.
            try {
                var target = new EventTarget(), received = false;
                var listener = function () { received = true; };
                target.addEventListener('keydown', listener);
                target.dispatchEvent(new Event('keydown'));
                target.removeEventListener('keydown', listener);
                return received;
            } catch (ignored) { return false; }
        }
        function start() {
            if (unavailable() || !strengthened(mode) || cfg.state.state !== 'running' || preflightPending || pulsePending || networkPending > 0 || (options.isCompletionPending && options.isCompletionPending())) { return; }
            cancel(); stage = 'starting'; preflightPending = true; admitted = false; armed = false; render();
            var run = serial;
            request(cfg.endpoints.challenge, body()).then(function (response) {
                if (run !== serial || !strengthened(mode)) { return; }
                challenge = response.challenge; stage = 'collecting';
                checks = { listener_roundtrip: false, trusted_enter_received: false, hidden_received: false, visible_after_hidden_received: false, focus_after_hidden_received: false, fullscreen_change_received: false, fullscreen_active: fullscreen() };
                checks.listener_roundtrip = probeListener();
                listen(enter, 'keydown', function (event) { if (stage === 'collecting' && nativeEvent(event) && event.key === 'Enter') { checks.trusted_enter_received = true; } });
                listen(document, 'visibilitychange', function (event) {
                    if (!nativeEvent(event)) { return; }
                    if (document.visibilityState === 'hidden') { if (stage === 'collecting') { checks.hidden_received = true; blocked.hidden = true; } }
                    else { blocked.hidden = false; if (stage === 'collecting' && checks.hidden_received) { checks.visible_after_hidden_received = true; if (document.hasFocus()) { checks.focus_after_hidden_received = true; } } }
                });
                listen(window, 'blur', function (event) { if (nativeEvent(event) && stage === 'collecting') { blocked.blur = true; } });
                listen(window, 'focus', function (event) { if (nativeEvent(event) && document.visibilityState === 'visible' && document.hasFocus()) { blocked.blur = false; if (stage === 'collecting' && checks.hidden_received) { checks.focus_after_hidden_received = true; } } });
                var fs = function (event) { if (!nativeEvent(event)) { return; } if (fullscreen()) { blocked.fullscreen_exit = false; } else if (stage === 'collecting') { blocked.fullscreen_exit = true; } if (stage === 'collecting') { checks.fullscreen_change_received = true; checks.fullscreen_active = fullscreen(); } };
                listen(document, 'fullscreenchange', fs); listen(document, 'webkitfullscreenchange', fs);
                deadline = setTimeout(submit, 45000); render();
            }).catch(function () { if (run === serial) { stage = 'idle'; render(); } }).finally(function () { if (stage !== 'collecting' && networkPending === 0) { preflightPending = false; } });
        }
        function submit() {
            if (stage !== 'collecting' || !challenge) { if (stage === 'submitted' || stage === 'idle') { start(); } return; }
            clearTimeout(deadline);
            // An extension can change registration methods after the gestures were observed.
            checks.listener_roundtrip = probeListener();
            // Keep source latches until real native returns; never synthesize journal starts.
            checks.fullscreen_active = fullscreen();
            blocked.hidden = blocked.hidden || document.visibilityState === 'hidden';
            blocked.blur = blocked.blur || !document.hasFocus();
            blocked.fullscreen_exit = blocked.fullscreen_exit || (cfg.requireFullscreen && !fullscreen());
            stage = 'submitted'; var run = serial, started = performance.now();
            var requestOrder = options.onDecisionRequest ? options.onDecisionRequest() : null;
            request(cfg.endpoints.preflight, body({ schema_version: 1, challenge: challenge, checks: checks })).then(function (state) {
                if (run !== serial) { return; }
                if (options.onState) {
                    // The shared monitor accepts/rejects the response using its emission order.
                    options.onState(state, Math.max(0, performance.now() - started), requestOrder);
                } else {
                    admitted = state.access_allowed === true;
                    if (admitted) { armed = true; }
                }
                render();
            }).catch(function () { if (run === serial) { admitted = false; render(); } }).finally(function () { preflightPending = false; });
        }

        function pulse() {
            var finished = options.isFinished ? options.isFinished() : !!cfg.state.finished;
            if (unavailable() || mode !== 'continuous' || cfg.state.state !== 'running' || finished || preflightPending || pulsePending || networkPending > 0 || (options.isCompletionPending && options.isCompletionPending())) { return; }
            pulsePending = true; var run = serial;
            request(cfg.endpoints.pulseChallenge, body()).then(function (response) {
                if (run !== serial || mode !== 'continuous' || cfg.state.state !== 'running' || (options.isFinished ? options.isFinished() : cfg.state.finished) || (options.isCompletionPending && options.isCompletionPending())) { return; }
                var pulseChecks = { listener_roundtrip: probeListener(), fullscreen_active: false };
                try { pulseChecks.fullscreen_active = fullscreen(); } catch (ignored) {}
                var started = performance.now(), order = options.onDecisionRequest ? options.onDecisionRequest() : null;
                return request(cfg.endpoints.pulse, body({ schema_version: 1, challenge: response.challenge, checks: pulseChecks })).then(function (state) {
                    if (run !== serial) { return; }
                    if (options.onState) { options.onState(state, Math.max(0, performance.now() - started), order); }
                    else { admitted = state.access_allowed === true; }
                    render();
                });
            }).catch(function () {}).finally(function () { pulsePending = false; });
        }
        // Stable cadence: fifteen-second state polls never reset this interval.
        var pulseTimer = setInterval(pulse, 20000);
        if (submitButton) { submitButton.addEventListener('click', submit); }
        if (fsButton) { fsButton.addEventListener('click', function () { var target = fullscreen() ? document : document.documentElement; var action = fullscreen() ? (document.exitFullscreen || document.webkitExitFullscreen) : (target.requestFullscreen || target.webkitRequestFullscreen); if (action) { try { var promise = action.call(target); if (promise && promise.catch) { promise.catch(function () {}); } } catch (ignored) {} } }); }
        return {
            update: function (state) {
                if (unavailable()) { render(); return; }
                var nextMode = state.tracking_mode || mode, nextGeneration = state.tracking_generation || generation;
                var nextRevision = typeof state.settings_revision === 'number' ? state.settings_revision : revision;
                var changed = nextMode !== mode || nextGeneration !== generation || (nextRevision > revision && (preflightPending || state.access_allowed === false));
                cfg.state = Object.assign({}, cfg.state, state); mode = nextMode; generation = nextGeneration; revision = Math.max(revision, nextRevision);
                admitted = state.access_allowed === true;
                if (mode === 'off') { cancel(); stage = 'idle'; armed = false; blocked = { hidden: false, blur: false, fullscreen_exit: false }; render(); return; }
                if (cfg.state.state !== 'running') { cancel(); stage = 'idle'; render(); return; }
                if (changed) { cancel(); stage = 'idle'; armed = false; }
                if (stage === 'submitted' && admitted) { armed = true; }
                if (cfg.state.state === 'running' && stage === 'idle' && changed) { if (networkPending > 0) { restartAfterSettlement = true; } else { start(); } }
                render();
            },
            // Suspension keeps ordinary observations alive after acquired admission.
            captureAllowed: function (source) { return mode === 'off' || (armed && stage === 'submitted' && !blocked[source]); },
            preparing: function () { return strengthened(mode) && preflightPending; },
            stop: function () { stopped = true; restartAfterSettlement = false; clearInterval(pulseTimer); cancel(); render(); }
        };
    };
}());
