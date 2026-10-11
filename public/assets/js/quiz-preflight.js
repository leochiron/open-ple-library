/** Full preparation and optional20s pulses. Neither enters the observation journal. */
(function () {
    'use strict';
    window.QuizTrackingPreflight = function (options) {
        var cfg = options.config, stage = 'idle', mode = 'off', generation = '', revision = -1;
        var serial = 0, deadline = null, challenge = null, checks = null, removers = [];
        var admitted = false, armed = false, blocked = { hidden: false, blur: false, fullscreen_exit: false };
        var preflightPending = false, pulsePending = false, networkPending = 0, restartAfterSettlement = false;
        var stopped = false, initiated = false, collectingUntil = null, expired = false;
        var ui = cfg.i18n || {};
        var panel = document.getElementById('quiz-preflight');
        var enter = document.getElementById('quiz-preflight-enter');
        var fsButton = document.getElementById('quiz-preflight-fullscreen');
        var submitButton = document.getElementById('quiz-preflight-submit');
        var status = document.getElementById('quiz-preflight-status');
        var remaining = document.getElementById('quiz-preflight-remaining');
        var steps = { enter: document.getElementById('quiz-preflight-step-enter'),
            return: document.getElementById('quiz-preflight-step-return'), fullscreen: document.getElementById('quiz-preflight-step-fullscreen') };
        function fullscreen() { try { return !!(document.fullscreenElement || document.webkitFullscreenElement); } catch (ignored) { return false; } }
        function visibility() { try { return document.visibilityState; } catch (ignored) { return null; } }
        function focus() { try { return document.hasFocus() === true; } catch (ignored) { return false; } }
        function clock() { try { var value = performance.now(); return Number.isFinite(value) ? value : null; } catch (ignored) { return null; } }
        function elapsed(started) { var end = clock(); return started === null || end === null ? Infinity : Math.max(0, end - started); }
        function strengthened(value) { return value === 'preflight' || value === 'continuous'; }
        function declaredBrowser() {
            function available(read) { try { return read() === true; } catch (ignored) { return false; } }
            var ua = '', brands = null;
            try { ua = navigator.userAgent; } catch (ignored) {}
            try {
                var hints = navigator.userAgentData;
                if (hints !== undefined && hints !== null) { brands = hints.brands.map(function (item) { return { brand: item.brand, version: item.version }; }); }
            } catch (ignored) { brands = ''; } // A failed read is invalid transport, never invented absence.
            return { user_agent: ua, brands: brands, capabilities: {
                event_target: available(function () { return typeof EventTarget === 'function' && typeof Event === 'function' && typeof EventTarget.prototype.addEventListener === 'function' && typeof EventTarget.prototype.removeEventListener === 'function' && typeof EventTarget.prototype.dispatchEvent === 'function'; }),
                visibility_api: available(function () { return typeof document.hidden === 'boolean' && typeof document.visibilityState === 'string'; }),
                focus_api: available(function () { return typeof document.hasFocus === 'function'; }),
                fetch_api: available(function () { return typeof window.fetch === 'function'; }),
                abort_controller: available(function () { return typeof AbortController === 'function' && typeof AbortController.prototype.abort === 'function'; }),
                monotonic_clock: available(function () { return typeof performance.now === 'function' && Number.isFinite(performance.now()); }),
                fullscreen: available(function () {
                    var root = document.documentElement;
                    var request = root.requestFullscreen || root.webkitRequestFullscreen;
                    var exit = document.exitFullscreen || document.webkitExitFullscreen;
                    var enabled = root.requestFullscreen ? document.fullscreenEnabled : document.webkitFullscreenEnabled;
                    return typeof request === 'function' && typeof exit === 'function' && enabled !== false;
                })
            } };
        }
        function body(extra) { return Object.assign({ attempt_id: cfg.attemptId, tracking_generation: generation, room_epoch: cfg.roomEpoch, _csrf: cfg.csrfToken, schema_version: 2, browser: declaredBrowser() }, extra || {}); }
        function request(endpoint, data) {
            var aborter = null, timeout;
            try { aborter = new AbortController(); } catch (ignored) {}
            ++networkPending;
            // The Promise settles within12s even when abort is unavailable. Late responses never apply.
            return new Promise(function (resolve, reject) {
                timeout = setTimeout(function () { try { if (aborter) { aborter.abort(); } } catch (ignored) {} reject(new Error('access_unavailable')); }, 12000);
                try {
                    var init = { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) };
                    try { if (aborter) { init.signal = aborter.signal; } } catch (ignored) {}
                    Promise.resolve(fetch(endpoint, init)).then(function (response) {
                        return Promise.resolve(response.json()).then(function (value) { return { ok: response.ok, value: value }; });
                    }).then(resolve, reject);
                } catch (ignored) { reject(new Error('access_unavailable')); }
            }).then(function (result) {
                if (!result.ok) { if (options.onConflict) { options.onConflict(); } throw new Error('access_unavailable'); }
                return result.value;
            }).finally(function () {
                    clearTimeout(timeout); --networkPending;
                    if (networkPending === 0 && restartAfterSettlement) { restartAfterSettlement = false; setTimeout(start, 0); }
                });
        }
        function unavailable() { return stopped || (options.isDetached && options.isDetached()); }
        function visible() { return !unavailable() && strengthened(mode) && !admitted && !cfg.state.finished && cfg.state.state === 'running'; }
        function ready() { return checks && checks.trusted_enter_received && checks.hidden_received && checks.visible_after_hidden_received && checks.focus_after_hidden_received && (!cfg.requireFullscreen || (checks.fullscreen_change_received && fullscreen())); }
        function render() {
            if (panel) { panel.hidden = !visible(); panel.setAttribute('aria-busy', String(stage === 'starting' || (stage === 'submitted' && preflightPending))); }
            var collecting = stage === 'collecting', working = stage === 'starting' || (stage === 'submitted' && preflightPending);
            if (submitButton) {
                submitButton.textContent = working ? ui.prepareWorking : collecting ? ui.prepareValidate : stage === 'idle' ? ui.prepareStart : ui.prepareRetry;
                submitButton.disabled = working || pulsePending || networkPending > 0 || (collecting && !ready());
            }
            if (enter) { enter.disabled = !collecting; enter.textContent = checks && checks.trusted_enter_received ? ui.prepareEnterDetected : ui.prepareEnterPrompt; }
            if (fsButton) { fsButton.hidden = !cfg.requireFullscreen; fsButton.disabled = !collecting; fsButton.textContent = fullscreen() ? ui.prepareExitFullscreen : ui.prepareEnterFullscreen; }
            if (status) { status.textContent = working ? ui.prepareWorking : collecting ? (ready() ? ui.prepareReady : ui.prepareCollecting) : stage === 'idle' ? ui.prepareIdle : expired ? ui.prepareExpired : stage === 'error' ? ui.prepareNetworkError : ui.prepareRefused; }
            var tick = clock();
            if (remaining) { remaining.hidden = !collecting; remaining.textContent = collecting && tick !== null ? (ui.prepareRemaining || '{seconds} s').replace('{seconds}', String(Math.max(0, Math.ceil((collectingUntil - tick) / 1000)))) : ''; }
            if (steps.enter) { steps.enter.setAttribute('data-done', String(!!checks && checks.trusted_enter_received)); }
            if (steps.return) { steps.return.setAttribute('data-done', String(!!checks && checks.visible_after_hidden_received && checks.focus_after_hidden_received)); }
            if (steps.fullscreen) { steps.fullscreen.hidden = !cfg.requireFullscreen; steps.fullscreen.setAttribute('data-done', String(!!checks && checks.fullscreen_change_received && fullscreen())); }
        }
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
            initiated = true; cancel(); checks = null; expired = false; collectingUntil = null;
            stage = 'starting'; preflightPending = true; admitted = false; armed = false; render();
            var run = serial;
            request(cfg.endpoints.challenge, body()).then(function (response) {
                if (run !== serial || !strengthened(mode)) { return; }
                challenge = response.challenge; stage = 'collecting';
                checks = { listener_roundtrip: false, trusted_enter_received: false, hidden_received: false, visible_after_hidden_received: false, focus_after_hidden_received: false, fullscreen_change_received: false, fullscreen_active: fullscreen() };
                checks.listener_roundtrip = probeListener();
                var keyboard = function (event) { if (stage === 'collecting' && nativeEvent(event) && event.key === 'Enter') { checks.trusted_enter_received = true; render(); } };
                // Native keydown bubbles to the document even if a click moved focus away
                // from the dedicated button. Clicks and scripted key events never count.
                listen(document, 'keydown', keyboard);
                listen(enter, 'keydown', keyboard);
                listen(document, 'visibilitychange', function (event) {
                    if (!nativeEvent(event)) { return; }
                    if (visibility() === 'hidden') { if (stage === 'collecting') { checks.hidden_received = true; blocked.hidden = true; } }
                    else if (visibility() === 'visible') { blocked.hidden = false; if (stage === 'collecting' && checks.hidden_received) { checks.visible_after_hidden_received = true; if (focus()) { checks.focus_after_hidden_received = true; } } }
                    render();
                });
                listen(window, 'blur', function (event) { if (nativeEvent(event) && stage === 'collecting') { blocked.blur = true; } });
                listen(window, 'focus', function (event) { if (nativeEvent(event) && visibility() === 'visible' && focus()) { blocked.blur = false; if (stage === 'collecting' && checks.hidden_received) { checks.focus_after_hidden_received = true; } render(); } });
                var fs = function (event) { if (!nativeEvent(event)) { return; } if (fullscreen()) { blocked.fullscreen_exit = false; } else if (stage === 'collecting') { blocked.fullscreen_exit = true; } if (stage === 'collecting') { checks.fullscreen_change_received = true; checks.fullscreen_active = fullscreen(); } render(); };
                listen(document, 'fullscreenchange', fs); listen(document, 'webkitfullscreenchange', fs);
                collectingUntil = clock() + 45000;
                deadline = setTimeout(function () { expired = true; submit(); }, 45000); render();
                if (enter && typeof enter.focus === 'function') { enter.focus(); }
            }).catch(function () { if (run === serial) { stage = 'error'; render(); } }).finally(function () { if (stage !== 'collecting' && networkPending === 0) { preflightPending = false; } render(); });
        }
        function submit() {
            if (stage !== 'collecting' || !challenge) { if (stage === 'submitted' || stage === 'idle' || stage === 'error') { start(); } return; }
            clearTimeout(deadline);
            // An extension can change registration methods after the gestures were observed.
            checks.listener_roundtrip = probeListener();
            // Keep source latches until real native returns; never synthesize journal starts.
            checks.fullscreen_active = fullscreen();
            blocked.hidden = blocked.hidden || visibility() !== 'visible';
            blocked.blur = blocked.blur || !focus();
            blocked.fullscreen_exit = blocked.fullscreen_exit || (cfg.requireFullscreen && !fullscreen());
            stage = 'submitted'; render(); var run = serial, started = clock();
            var requestOrder = options.onDecisionRequest ? options.onDecisionRequest() : null;
            request(cfg.endpoints.preflight, body({ challenge: challenge, checks: checks })).then(function (state) {
                if (run !== serial) { return; }
                if (options.onState) {
                    // The shared monitor accepts/rejects the response using its emission order.
                    options.onState(state, elapsed(started), requestOrder);
                } else {
                    admitted = state.access_allowed === true && clock() !== null;
                    if (admitted) { armed = true; }
                }
                render();
            }).catch(function () { if (run === serial) { admitted = false; stage = 'error'; render(); } }).finally(function () { preflightPending = false; render(); });
        }

        function pulse() {
            var finished = options.isFinished ? options.isFinished() : !!cfg.state.finished;
            if (unavailable() || !initiated || mode !== 'continuous' || cfg.state.state !== 'running' || finished || preflightPending || pulsePending || networkPending > 0 || (options.isCompletionPending && options.isCompletionPending())) { return; }
            pulsePending = true; render(); var run = serial;
            request(cfg.endpoints.pulseChallenge, body()).then(function (response) {
                if (run !== serial || mode !== 'continuous' || cfg.state.state !== 'running' || (options.isFinished ? options.isFinished() : cfg.state.finished) || (options.isCompletionPending && options.isCompletionPending())) { return; }
                var pulseChecks = { listener_roundtrip: probeListener(), fullscreen_active: false };
                try { pulseChecks.fullscreen_active = fullscreen(); } catch (ignored) {}
                var started = clock(), order = options.onDecisionRequest ? options.onDecisionRequest() : null;
                return request(cfg.endpoints.pulse, body({ challenge: response.challenge, checks: pulseChecks })).then(function (state) {
                    if (run !== serial) { return; }
                    if (options.onState) { options.onState(state, elapsed(started), order); }
                    else { admitted = state.access_allowed === true && clock() !== null; }
                    render();
                });
            }).catch(function () {}).finally(function () { pulsePending = false; render(); });
        }
        // Stable cadence: fifteen-second state polls never reset this interval.
        var pulseTimer = setInterval(pulse, 20000);
        var renderTimer = setInterval(function () { if (stage === 'collecting') { render(); } }, 1000);
        try { if (submitButton) { submitButton.addEventListener('click', submit); } } catch (ignored) {}
        try { if (fsButton) { fsButton.addEventListener('click', function () { try { var target = fullscreen() ? document : document.documentElement; var action = fullscreen() ? (document.exitFullscreen || document.webkitExitFullscreen) : (target.requestFullscreen || target.webkitRequestFullscreen); if (action) { var promise = action.call(target); if (promise && promise.catch) { promise.catch(function () {}); } } } catch (ignored) {} }); } } catch (ignored) {}
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
                if (cfg.state.state === 'running' && stage === 'idle' && changed && initiated) { if (networkPending > 0) { restartAfterSettlement = true; } else { start(); } }
                render();
            },
            // Suspension keeps ordinary observations alive after acquired admission.
            captureAllowed: function (source) { return mode === 'off' || (armed && stage === 'submitted' && !blocked[source]); },
            preparing: function () { return strengthened(mode) && preflightPending; },
            visible: visible,
            stop: function () { stopped = true; restartAfterSettlement = false; clearInterval(pulseTimer); clearInterval(renderTimer); cancel(); render(); }
        };
    };
}());
