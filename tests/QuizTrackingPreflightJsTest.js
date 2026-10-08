'use strict';
// VM contract tests. Trusted flags here are mocks, never evidence of native browser gestures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { harness, render, inlineScripts, state, flush, root } = require('./QuizLiveSettingsJsTest');
const source = name => fs.readFileSync(path.join(root, 'public/assets/js', name), 'utf8');

function setup(overrides = {}, monitor = true) {
    const initial = state({ tracking_mode: 'preflight', settings_revision: 1, access_allowed: false, access_until: null, require_fullscreen: false, ...overrides });
    if (!initial.access_allowed) { delete initial.form_url; }
    const h = harness(render('room'), initial);
    inlineScripts(render('room')).forEach(h.run);
    const cfg = h.window.QUIZ_ROOM;
    cfg.state = initial; cfg.requireFullscreen = initial.require_fullscreen;
    cfg.roomEpoch = 'e'.repeat(32); cfg.endpoints.challenge = '/quiz/api/tracking/challenge'; cfg.endpoints.preflight = '/quiz/api/tracking/preflight';
    let focused = true, listenerBlocked = false, result = { ...initial }, resultDelay = 0, preflightReply = null;
    h.document.hasFocus = () => focused;
    const remove = function (type, fn) { this.listeners[type] = (this.listeners[type] || []).filter(value => value !== fn); };
    for (const target of [h.window, h.document, ...h.elements.values()]) { target.removeEventListener = remove; }
    class Probe extends EventTarget {
        addEventListener(type, fn, options) { if (!listenerBlocked || type !== 'keydown') { super.addEventListener(type, fn, options); } }
    }
    h.context.EventTarget = Probe; h.context.Event = Event;
    const fetchBase = h.context.fetch, diagnostics = [], challenges = [];
    h.context.fetch = async (url, options = {}) => {
        if (url === cfg.endpoints.challenge) { challenges.push(JSON.parse(options.body)); return { ok: true, json: async () => ({ challenge: 'd'.repeat(64), challenge_until: initial.server_now + 120 }) }; }
        if (url === cfg.endpoints.preflight) { diagnostics.push(JSON.parse(options.body)); if (preflightReply) { return preflightReply; } h.advance(resultDelay); return { ok: true, json: async () => result }; }
        return fetchBase(url, options);
    };
    h.run(source('quiz-journal.js')); h.run(source('quiz-preflight.js'));
    let preflight;
    if (monitor) { h.run(source('quiz-monitor.js')); }
    else { preflight = h.window.QuizTrackingPreflight({ config: cfg }); preflight.update(initial); }
    return { ...h, cfg, diagnostics, challenges, preflight,
        blockProbe: () => { listenerBlocked = true; },
        result: (value, delay = 0) => { result = value; resultDelay = delay; h.setState(value); },
        setPreflightReply: value => { preflightReply = value; },
        focus: value => { focused = value; h.window.emit(value ? 'focus' : 'blur', { isTrusted: true }); },
        visible: value => { h.document.visibilityState = value ? 'visible' : 'hidden'; h.document.emit('visibilitychange', { isTrusted: true }); },
        fullscreen: value => { h.document.fullscreenElement = value ? h.document.documentElement : null; h.document.emit('fullscreenchange', { isTrusted: true }); },
        enter: trusted => { h.elements.get('quiz-preflight-enter').emit('keydown', { key: 'Enter', isTrusted: trusted }); },
        submit: () => h.elements.get('quiz-preflight-submit').emit('click', { isTrusted: true }),
    };
}

async function gesturesAndProbe() {
    const h = setup(); await flush();
    assert.equal(h.elements.get('quiz-iframe-wrap').children.length, 0, 'Initial denied document never injects a form');
    h.elements.get('quiz-preflight-enter').emit('click', { isTrusted: true }); h.enter(false);
    h.visible(true); h.focus(true);
    h.submit(); await flush();
    assert.equal(h.diagnostics[0].checks.trusted_enter_received, false, 'Click and synthetic Enter do not stand in for native keydown');
    assert.equal(h.diagnostics[0].checks.visible_after_hidden_received, false, 'Initial visible does not count as a return');
    assert.equal(h.events.length, 0, 'Preparation observations never enter ordinary journal');
    h.submit(); await flush(); h.enter(true); h.visible(false); h.focus(false); h.advance(250); h.visible(true); h.focus(true);
    h.blockProbe(); h.submit(); await flush();
    assert.equal(h.diagnostics[1].checks.listener_roundtrip, false, 'Fresh final keydown probe detects registration disabled after observed gestures');
    assert.equal(h.diagnostics[1].checks.trusted_enter_received, true, 'Previously observed native keydown remains a reported fact');
    assert.equal(h.diagnostics[1].checks.hidden_received, true);
    assert.equal(h.events.length, 0, 'No hidden/blur diagnostic becomes an incident observation');
    assert.equal(JSON.stringify(h.diagnostics).includes('Student draft'), false, 'No input content is collected');
    assert.equal(h.cfg.roomEpoch, h.challenges[0].room_epoch);
}

async function registrationThrows() {
    const h = setup({}, false);
    // The challenge callback has not run yet: block registration of the preparation listeners.
    h.elements.get('quiz-preflight-enter').addEventListener = () => { throw new Error('blocked'); };
    h.document.addEventListener = () => { throw new Error('blocked'); };
    await flush(); await h.tick(45000);
    assert.equal(h.diagnostics.length, 1, 'Registration failure still submits a diagnostic by45s');
    assert.equal(h.diagnostics[0].checks.trusted_enter_received, false);
    assert.equal(h.diagnostics[0].checks.hidden_received, false);
    h.preflight.update(state({ tracking_mode: 'preflight', settings_revision: 1, access_allowed: true }));
    assert.equal(h.preflight.captureAllowed('hidden'), true, 'A later server override can recover despite false checks');
}

async function diagnosticLatch() {
    const h = setup({ require_fullscreen: true }); await flush();
    h.visible(false); h.focus(false); h.fullscreen(false);
    h.result(state({ tracking_mode: 'preflight', settings_revision: 1, require_fullscreen: true, access_allowed: true, access_until: h.cfg.state.server_now + 60, server_now: h.cfg.state.server_now }));
    await h.tick(45000);
    assert.equal(h.diagnostics.length, 1, 'Timeout submits false even with a source still outside');
    h.visible(true); // Real hidden return arms hidden, blur and fullscreen are still outside.
    await h.tick(1000);
    assert.equal(h.events.length, 0, 'Focus probe cannot invent a departure from a diagnostic source left open at admission');
    h.focus(true); h.fullscreen(true); await flush();
    assert.equal(h.events.length, 0, 'Diagnostic return does not fabricate an ordinary return');
    h.focus(false); await h.tick(0); h.advance(1200); h.focus(true); await flush();
    assert.deepEqual(h.events.map(event => event.type), ['away_start','blur'], 'Only the next genuine departure/return becomes ordinary');
    assert.equal(h.events[1].duration_ms, 1200);
    assert.equal(h.elements.get('quiz-fullscreen-gate').hidden, true, 'Server permission overrides the legacy fullscreen gate');
    const denied = { ...h.cfg.state, access_allowed: false, access_until: null }; delete denied.form_url;
    h.setState(denied); await h.tick(3000);
    h.visible(false); await flush(); h.advance(200); h.visible(true); await flush();
    assert.deepEqual(h.events.slice(-2).map(event => event.type), ['away_start','hidden'], 'Manual/TTL suspension keeps acquired ordinary monitoring armed');
    h.submit(); await flush(); // A genuine new preparation disarms only new sources.
    const before = h.events.length; h.visible(false); h.visible(true); await flush();
    assert.equal(h.events.length, before, 'A new preparation resumes exclusive diagnostic capture');
}

async function oldEpisodeAndGuard() {
    const h = setup({ tracking_mode: 'off', settings_revision: 0, access_allowed: true }); await flush();
    const original = h.elements.get('quiz-iframe-wrap').children[0]; original.answer = 'Student draft';
    h.visible(false); await flush();
    const departure = h.events[0]; assert.equal(departure.type, 'away_start');
    const pending = state({ tracking_mode: 'preflight', settings_revision: 1, access_allowed: false, access_until: null, require_fullscreen: false }); delete pending.form_url;
    h.setState(pending); await h.tick(15000); await flush();
    h.advance(12000); h.visible(true); await flush();
    const returned = h.events.find(event => event.type === 'hidden');
    assert.equal(returned.related_event_uid, departure.event_uid, 'An ordinary episode opened before preparation closes on its real return');
    assert.equal(returned.duration_ms, 12000, 'No synthetic closure or loss of factual duration');
    h.result(state({ tracking_mode: 'preflight', settings_revision: 1, access_allowed: true, server_now: pending.server_now, access_until: pending.server_now + 60, require_fullscreen: false }), 5000);
    h.submit(); await flush();
    assert.equal(h.elements.get('quiz-iframe-wrap').children[0], original, 'Admission restores the same iframe and draft');
    assert.equal(original.answer, 'Student draft');
    h.jumpClock(-86400000); h.advance(54999); await h.tick(500);
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, true, 'Monotone guard valid just before network-adjusted deadline');
    h.advance(1); await h.tick(500);
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, false, 'Permission expires at60s including measured network delay, despite wall-clock change');
    const oldOff = state({ tracking_mode: 'off', settings_revision: 0, access_allowed: true }); h.setState(oldOff); await h.tick(3000);
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, false, 'Stale off response cannot clear a known preflight suspension');
    assert.equal(h.elements.get('quiz-iframe-wrap').children[0], original);
    assert.equal(h.storage.size > 0, true, 'Ordinary journal storage still exists');
    for (const raw of h.storage.values()) { assert.equal(raw.includes(h.cfg.roomEpoch), false, 'Document epoch remains transport-only'); }
}

async function lobbyGeneration() {
    const h = setup({ state: 'lobby' }); await flush(); assert.equal(h.challenges.length, 0);
    const launched = state({ tracking_mode: 'preflight', settings_revision: 1, tracking_generation: 'b'.repeat(32), access_allowed: false, require_fullscreen: false }); delete launched.form_url;
    h.setState(launched); await h.tick(3000); await flush();
    assert.equal(h.challenges[0].tracking_generation, 'b'.repeat(32), 'Lobby document challenges the newly adopted generation');
    assert.equal(h.challenges[0].room_epoch, 'e'.repeat(32), 'Document epoch stays stable across launch');
    h.setState({ ...launched, state: 'lobby' }); await h.tick(3000);
    h.setState({ ...launched, tracking_generation: 'c'.repeat(32) }); await h.tick(3000); await flush();
    assert.equal(h.challenges.at(-1).tracking_generation, 'c'.repeat(32), 'Stop/launch restarts preparation without old challenge');
}

async function staleDiagnosticReply() {
    const h = setup(); await flush();
    let resolve;
    h.setPreflightReply(new Promise(done => { resolve = done; }));
    h.submit(); await flush();
    const positive = state({ tracking_mode: 'preflight', settings_revision: 1, access_allowed: true, access_until: h.cfg.state.server_now + 60 });
    const denied = { ...positive, access_allowed: false, access_until: null }; delete denied.form_url;
    h.setState(denied); await h.tick(3000);
    resolve({ ok: true, json: async () => positive }); await flush();
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, false, 'A delayed positive preflight cannot override a newer denial at the same revision');
    assert.equal(h.elements.get('quiz-iframe-wrap').children.length, 0, 'Stale permission never creates an iframe');
    h.setState(positive); await h.tick(3000);
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, true, 'A genuinely fresh state decision can recover');
}

async function completionLeaseAndOrder() {
    const h = setup({ finished: true }); await flush();
    const allowed = state({ tracking_mode: 'preflight', settings_revision: 1, access_allowed: true, access_until: h.cfg.state.server_now + 60, server_now: h.cfg.state.server_now, finished: true });
    h.result(allowed); h.submit(); await flush(); h.advance(50000);
    let resolve;
    h.setCompletion(new Promise(done => { resolve = done; }));
    h.elements.get('quiz-resume-btn').emit('click'); await flush();
    const resume = h.events.at(-1);
    resolve({ ok: true, json: async () => ({ event_uid: resume.event_uid, attempt_id: 1, finished: false, access_allowed: true }) }); await flush();
    h.setStateStatus(503); h.advance(10000); await h.tick(500);
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, false, 'Resume ACK without timing retains the original lease and expires at60 with network unavailable');

    const late = setup({ finished: true }); await flush(); late.result({ ...allowed, server_now: late.cfg.state.server_now, access_until: late.cfg.state.server_now + 60 }); late.submit(); await flush();
    late.setCompletion(new Promise(done => { resolve = done; })); late.elements.get('quiz-resume-btn').emit('click'); await flush();
    const oldResume = late.events.at(-1), denied = { ...allowed, access_allowed: false, access_until: null }; delete denied.form_url;
    late.setState(denied); await late.tick(3000);
    resolve({ ok: true, json: async () => ({ event_uid: oldResume.event_uid, attempt_id: 1, finished: false, access_allowed: true }) }); await flush();
    assert.equal(late.elements.get('quiz-access-unavailable').hidden, false, 'An older successful resume cannot re-admit after a newer denial');
    late.setState({ ...allowed, access_allowed: true, finished: true }); await late.tick(3000);
    assert.equal(late.elements.get('quiz-finished').hidden, false, 'A stale resume did not overwrite the newer declared-finish state');
}

(async () => {
    await gesturesAndProbe(); await registrationThrows(); await diagnosticLatch(); await oldEpisodeAndGuard(); await lobbyGeneration(); await staleDiagnosticReply(); await completionLeaseAndOrder();
    process.stdout.write('QuizTrackingPreflightJsTest: OK (fresh probes/false timeout/real return/ordinary queue/guard/iframe/generations)\n');
})().catch(error => { console.error(error); process.exitCode = 1; });
