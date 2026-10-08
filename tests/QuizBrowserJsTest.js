'use strict';
// VM declarations and mocked decisions. These are not attestations of a real browser or native gestures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { setup } = require('./QuizTrackingPreflightJsTest');
const { harness, render, inlineScripts, state, flush, root } = require('./QuizLiveSettingsJsTest');
const source = name => fs.readFileSync(path.join(root, 'public/assets/js', name), 'utf8');
const capabilities = ['event_target', 'visibility_api', 'focus_api', 'fetch_api', 'abort_controller', 'monotonic_clock', 'fullscreen'];
const ua = 'Mozilla/5.0 Chrome/151.0.0.0 Safari/537.36';
const failGetter = () => { throw new Error('private getter failure'); };

function configureBrowser(h) {
    h.context.navigator.userAgent = ua;
    h.context.navigator.userAgentData = { brands: [{ brand: 'Google Chrome', version: '151' }, { brand: 'Not.A/Brand;=?', version: '99' }], getHighEntropyValues: () => { throw new Error('Forbidden high entropy read'); } };
    h.document.hidden = false;
    Object.defineProperty(h.window, 'fetch', { configurable: true, get: () => h.context.fetch });
}
function browserSetup(overrides = {}, configure = null, monitor = true) {
    return setup(overrides, monitor, (h, cfg) => { configureBrowser(h); if (configure) { configure(h, cfg); } });
}
function allowed(h, extra = {}) {
    return state({ tracking_mode: h.cfg.state.tracking_mode, settings_revision: 1, require_fullscreen: false, access_allowed: true, access_until: h.cfg.state.server_now + 60, ...extra });
}

async function twoEnvelopesAndFreshPulse() {
    const h = browserSetup({ tracking_mode: 'continuous' }); await flush();
    const first = h.challenges[0];
    assert.equal(first.schema_version, 2);
    assert.deepEqual(Object.keys(first.browser.capabilities), capabilities);
    assert.ok(Object.values(first.browser.capabilities).every(value => value === true));
    assert.deepEqual(Object.keys(first).sort(), ['_csrf', 'attempt_id', 'browser', 'room_epoch', 'schema_version', 'tracking_generation'].sort());
    h.context.navigator.userAgent = ua.replace('151.0.0.0', '151.9.5.2');
    h.result(allowed(h)); h.submit(); await flush();
    assert.equal(h.diagnostics[0].schema_version, 2);
    assert.equal(h.diagnostics[0].browser.user_agent, h.context.navigator.userAgent, 'Result collects a fresh envelope rather than caching the challenge');
    const form = h.elements.get('quiz-iframe-wrap').children[0]; form.answer = 'Private draft';
    h.cfg.endpoints.pulseChallenge = '/quiz/api/tracking/pulse/challenge'; h.cfg.endpoints.pulse = '/quiz/api/tracking/pulse';
    const base = h.context.fetch, pulses = [], challenges = [];
    h.context.fetch = async (url, init) => {
        if (url === h.cfg.endpoints.pulseChallenge) {
            challenges.push(JSON.parse(init.body));
            return { ok: true, json: async () => ({ challenge: 'f'.repeat(64) }) };
        }
        if (url === h.cfg.endpoints.pulse) { pulses.push(JSON.parse(init.body)); return { ok: true, json: async () => ({ ...allowed(h), access_allowed: false, access_until: null, form_url: undefined }) }; }
        return base(url, init);
    };
    // Loss of abort support does not prevent a bounded diagnostic POST when fetch still works.
    h.context.AbortController = undefined;
    await h.tick(20000); await flush();
    assert.equal(challenges.length, 1); assert.equal(pulses.length, 1);
    assert.equal(pulses[0].browser.capabilities.abort_controller, false);
    assert.equal(pulses[0].schema_version, 2);
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, false);
    assert.equal(h.elements.get('quiz-iframe-wrap').children[0], form);
    assert.equal(form.answer, 'Private draft');
    assert.equal(JSON.stringify([...h.challenges, ...h.diagnostics, ...pulses]).includes('Private draft'), false);
}

async function throwingCapabilities() {
    const cases = [
        ['event_target', h => Object.defineProperty(h.context.EventTarget.prototype, 'addEventListener', { configurable: true, get: failGetter })],
        ['visibility_api', h => Object.defineProperty(h.document, 'hidden', { configurable: true, get: failGetter })],
        ['focus_api', h => Object.defineProperty(h.document, 'hasFocus', { configurable: true, get: failGetter })],
        ['abort_controller', h => { h.context.AbortController = undefined; }],
        ['monotonic_clock', h => Object.defineProperty(h.context.performance, 'now', { configurable: true, get: failGetter })],
        ['fullscreen', h => Object.defineProperty(h.document.documentElement, 'requestFullscreen', { configurable: true, get: failGetter })],
    ];
    for (const [name, change] of cases) {
        const h = browserSetup({}, change); await flush(); await h.tick(45000);
        assert.equal(h.diagnostics.length, 1, `${name} failure still posts a bounded false diagnostic`);
        assert.equal(h.diagnostics[0].browser.capabilities[name], false, name);
        assert.equal(h.elements.get('quiz-access-unavailable').hidden, false);
        assert.equal(h.events.length, 0, 'Unknown preparation states never fabricate ordinary incidents');
        assert.equal(JSON.stringify(h.diagnostics).includes('private getter failure'), false);
    }
    const brokenFetch = browserSetup({}, h => { h.context.fetch = undefined; }); await flush();
    assert.equal(brokenFetch.challenges.length, 0, 'No transmission is possible when fetch itself is unavailable');
    assert.equal(brokenFetch.elements.get('quiz-access-unavailable').hidden, false);
    const throwsFetch = browserSetup({}, h => { h.context.fetch = failGetter; }); await flush();
    assert.equal(throwsFetch.elements.get('quiz-iframe-wrap').children.length, 0, 'Synchronous fetch failure never grants permission');
}

async function fullscreenVariants() {
    for (const [variant, expected] of [['standard', true], ['webkit', true], ['mixed', true], ['disabled', false]]) {
        const h = browserSetup({}, h => {
            if (variant === 'webkit') { h.document.documentElement.webkitRequestFullscreen = h.document.documentElement.requestFullscreen; delete h.document.documentElement.requestFullscreen; }
            if (variant === 'webkit' || variant === 'mixed') { h.document.webkitExitFullscreen = h.document.exitFullscreen; delete h.document.exitFullscreen; }
            if (variant === 'disabled') { h.document.fullscreenEnabled = false; }
        }); await flush();
        assert.equal(h.challenges[0].browser.capabilities.fullscreen, expected, variant);
    }
}

async function lossOfClockAndUnknownSources() {
    const h = browserSetup(); await flush(); h.result(allowed(h)); h.submit(); await flush();
    h.setState(allowed(h)); await h.tick(3000);
    const form = h.elements.get('quiz-iframe-wrap').children[0]; form.answer = 'Private draft';
    Object.defineProperty(h.document, 'hasFocus', { configurable: true, get: failGetter });
    await h.tick(1000);
    Object.defineProperty(h.document, 'fullscreenElement', { configurable: true, get: failGetter }); h.document.emit('fullscreenchange');
    Object.defineProperty(h.document, 'visibilityState', { configurable: true, get: failGetter }); h.document.emit('visibilitychange');
    assert.equal(h.events.length, 0, 'Unknown focus/fullscreen/visibility does not become a departure or return');
    Object.defineProperty(h.context.performance, 'now', { configurable: true, get: failGetter });
    await h.tick(500);
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, false, 'Unreadable monotone clock immediately masks even an allowed override decision');
    h.setState(allowed(h)); await h.tick(15000);
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, false, 'No wall-clock fallback can revive a permission');
    assert.equal(h.elements.get('quiz-iframe-wrap').children[0], form); assert.equal(form.answer, 'Private draft');
}

async function boundedTransportAndLateReply() {
    const h = browserSetup({}, h => { h.context.AbortController = undefined; }); await flush();
    let resolve; h.setPreflightReply(new Promise(done => { resolve = done; })); h.submit(); await flush();
    await h.tick(12000);
    resolve({ ok: true, json: async () => allowed(h) }); await flush();
    assert.equal(h.elements.get('quiz-iframe-wrap').children.length, 0, 'Timed-out fetch never applies a late authorization');
    h.setPreflightReply(null); h.result(allowed(h)); h.submit(); await flush(); h.submit(); await flush();
    assert.equal(h.diagnostics.length, 2, 'Settlement releases single flight and a new complete preparation can recover');
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, true);
}

async function restoredQueueAndUnmeasurableReturn() {
    const initial = state({ tracking_mode: 'off', require_fullscreen: false });
    const stored = { payload: { type: 'reload', away_seconds: 0, attempt_id: 1, tracking_generation: initial.tracking_generation, event_uid: 'u'.repeat(32), source: 'navigation' }, time: Date.now() };
    const storage = new Map([['quiz-journal-v1:1:' + initial.tracking_generation, JSON.stringify({ queue: [stored], dropped: 0 })]]);
    const h = harness(render('room'), initial, storage); h.context.AbortController = undefined;
    Object.defineProperty(h.context.performance, 'now', { configurable: true, get: failGetter });
    h.run(source('quiz-journal.js'));
    h.run('window.journal = window.QuizEventJournal({ attemptId: 1, endpoint: "/quiz/api/event" }); window.journal.setGeneration("' + initial.tracking_generation + '");');
    await flush(); assert.equal(h.events[0].event_uid, stored.payload.event_uid, 'Restored old queue can ACK without abort support or a local clock');
    h.window.journal.start('hidden'); assert.equal(h.events.length, 1, 'No timed source opens when departure cannot be measured');
    Object.defineProperty(h.context.performance, 'now', { configurable: true, value: () => 100 });
    h.window.journal.start('hidden'); await flush();
    Object.defineProperty(h.context.performance, 'now', { configurable: true, get: failGetter });
    h.window.journal.end('hidden'); h.window.journal.end('hidden'); await flush();
    assert.equal(h.events.filter(value => value.type === 'tracking_diagnostic').length, 1, 'An unmeasurable real return signals loss once');
    assert.equal(h.events.filter(value => value.type === 'hidden').length, 0, 'No fabricated zero-ms return');
    Object.defineProperty(h.context.performance, 'now', { configurable: true, value: () => 200 });
    h.window.journal.end('hidden'); await flush();
    assert.equal(h.events.filter(value => value.type === 'hidden').length, 0, 'Recovery rearms without retrospectively inventing duration');
    h.window.journal.start('hidden'); Object.defineProperty(h.context.performance, 'now', { configurable: true, value: () => 450 });
    h.window.journal.end('hidden'); await flush();
    assert.equal(h.events.find(value => value.type === 'hidden').duration_ms, 250, 'Next real episode is measurable');
}

async function main() {
    await twoEnvelopesAndFreshPulse(); await throwingCapabilities(); await fullscreenVariants(); await lossOfClockAndUnknownSources(); await boundedTransportAndLateReply(); await restoredQueueAndUnmeasurableReturn();
    console.log('QuizBrowserJsTest: OK (schema2/fresh envelopes/7capabilities/FS variants/runtime failures/12s bound/clock/ordinary queue)');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
