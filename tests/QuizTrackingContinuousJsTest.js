'use strict';
// VM flags model listener responses, never prove real trusted gestures.
const assert = require('node:assert/strict');
const { setup } = require('./QuizTrackingPreflightJsTest');
const { state, flush } = require('./QuizLiveSettingsJsTest');

function continuous(overrides = {}) {
    const h = setup({ tracking_mode: 'continuous', ...overrides });
    h.cfg.endpoints.pulseChallenge = '/quiz/api/tracking/pulse/challenge'; h.cfg.endpoints.pulse = '/quiz/api/tracking/pulse';
    let pulseReply = null, challengeReply = null, pulseAllowed = true;
    const base = h.context.fetch, pulseChallenges = [], pulses = [];
    h.context.fetch = async (url, options) => {
        if (url === h.cfg.endpoints.pulseChallenge) {
            pulseChallenges.push(JSON.parse(options.body));
            return challengeReply || { ok: true, json: async () => ({ challenge: 'f'.repeat(64), challenge_until: h.cfg.state.server_now + 120 }) };
        }
        if (url === h.cfg.endpoints.pulse) {
            const body = JSON.parse(options.body); pulses.push(body);
            return pulseReply || { ok: true, json: async () => ({ ...h.cfg.state, access_allowed: pulseAllowed, access_until: pulseAllowed ? h.cfg.state.server_now + 60 : null, form_url: undefined }) };
        }
        return base(url, options);
    };
    const allow = () => { const result = state({ tracking_mode: 'continuous', settings_revision: 1, access_allowed: true, server_now: h.cfg.state.server_now, access_until: h.cfg.state.server_now + 60, require_fullscreen: false }); h.result(result); h.submit(); return result; };
    return { ...h, pulses, pulseChallenges, allow,
        setPulseReply: value => { pulseReply = value; }, setPulseChallengeReply: value => { challengeReply = value; }, setPulseAllowed: value => { pulseAllowed = value; },
    };
}

async function stableCadenceAndFreshProbe() {
    const h = continuous(); await flush();
    await h.tick(20000); assert.equal(h.pulseChallenges.length, 0, 'No pulse while complete preparation collects gestures');
    h.allow(); await flush(); const form = h.elements.get('quiz-iframe-wrap').children[0]; form.answer = 'Private draft';
    await h.tick(3000); await h.tick(20000); await flush();
    assert.equal(h.pulses.length, 1, 'Stable twenty-second timer survives state polling');
    assert.deepEqual(h.pulses[0].checks, { listener_roundtrip: true, fullscreen_active: false }, 'Pulse submits exactly two boolean facts');
    h.blockProbe(); h.setPulseAllowed(false); await h.tick(15000); await h.tick(20000); await flush();
    assert.equal(h.pulses[1].checks.listener_roundtrip, false, 'Methods modified after admission are probed anew');
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, false, 'Failed decision suspends same form');
    assert.equal(h.elements.get('quiz-iframe-wrap').children[0], form); assert.equal(form.answer, 'Private draft');
    assert.equal(h.events.length, 0, 'Isolated keydown never enters ordinary journal');
    h.setPulseAllowed(true); await h.tick(20000); await flush();
    assert.equal(h.pulses.at(-1).checks.listener_roundtrip, false, 'False diagnostics continue under tracking exemption');
    assert.equal(h.elements.get('quiz-iframe-wrap').children[0], form);
    assert.equal(JSON.stringify(h.pulses).includes('Private draft'), false, 'No form value in pulse transport');
    assert.equal(h.elements.get('quiz-fullscreen-gate').hidden, true, 'Strengthened permission remains fullscreen authority');
}

async function fullPreflightPendingThroughSettlement() {
    const h = continuous(); await flush(); let resolve;
    h.setPreflightReply(new Promise(done => { resolve = done; })); h.submit(); await flush();
    await h.tick(20000); h.submit(); await flush();
    assert.equal(h.pulseChallenges.length, 0, 'No pulse challenge during full POST still in flight');
    assert.equal(h.challenges.length, 1, 'Repeated continue cannot create a competing full challenge');
    const allowed = state({ tracking_mode: 'continuous', settings_revision: 1, access_allowed: true, access_until: h.cfg.state.server_now + 60, server_now: h.cfg.state.server_now, require_fullscreen: false });
    resolve({ ok: true, json: async () => allowed }); await flush(); await h.tick(20000); await flush();
    assert.equal(h.pulses.length, 1, 'Pulse resumes after the full request settles');
    let pulseResolve; h.setPulseReply(new Promise(done => { pulseResolve = done; })); await h.tick(20000); await flush();
    const challengesBefore = h.pulseChallenges.length; await h.tick(20000); h.submit(); await flush();
    assert.equal(h.pulseChallenges.length, challengesBefore, 'Pulse is single-flight through its result POST');
    assert.equal(h.challenges.length, 1, 'Full check does not supersede an in-flight pulse voluntarily');
    pulseResolve({ ok: true, json: async () => allowed }); await flush();
}

async function completionAndFinishedPause() {
    const h = continuous(); await flush(); h.allow(); await flush();
    let resolve; h.setCompletion(new Promise(done => { resolve = done; }));
    h.elements.get('quiz-submit-confirm').checked = true; h.elements.get('quiz-submit-confirm').emit('change'); h.elements.get('quiz-finish-btn').emit('click'); await flush();
    await h.tick(20000); assert.equal(h.pulseChallenges.length, 0, 'CompletionPending pauses challenge emission');
    const finish = h.events.at(-1); resolve({ ok: true, json: async () => ({ event_uid: finish.event_uid, attempt_id: 1, access_allowed: true, finished: true }) }); await flush();
    await h.tick(20000); assert.equal(h.pulseChallenges.length, 0, 'Finished attempt has no automatic pulses');
    h.setCompletion(new Promise(done => { resolve = done; }));
    h.elements.get('quiz-resume-btn').emit('click'); await flush(); await h.tick(20000); assert.equal(h.pulseChallenges.length, 0, 'ResumePending remains paused');
    const resume = h.events.at(-1); resolve({ ok: true, json: async () => ({ event_uid: resume.event_uid, attempt_id: 1, access_allowed: true, finished: false }) }); await flush();
    await h.tick(20000); await flush(); assert.equal(h.pulses.length, 1, 'Confirmed resume resumes the loop without reopening by pulse');
}

async function stalePulseAndExpiry() {
    const h = continuous(); await flush(); const allowed = h.allow(); await flush();
    let resolve; h.setPulseReply(new Promise(done => { resolve = done; })); await h.tick(20000); await flush();
    const denied = { ...allowed, access_allowed: false, access_until: null }; delete denied.form_url; h.setState(denied); await h.tick(3000);
    resolve({ ok: true, json: async () => allowed }); await flush();
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, false, 'Older pulse result cannot erase newer denial at the same revision');
    h.setState(allowed); await h.tick(3000); h.setStateStatus(503); h.setPulseReply(Promise.resolve({ ok: false, status: 503, json: async () => ({ error: 'access_unavailable' }) }));
    h.advance(60000); await h.tick(500);
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, false, 'Network failure cannot silently extend the local sixty-second lease');
    assert.equal(h.elements.get('quiz-iframe-wrap').children.length, 1, 'Guard keeps iframe rather than destroying it');
}

async function integerServerClockGuard() {
    const h = continuous(); await flush();
    // Server decision happens at second+.9; response takes20ms and reports floor(server_now).
    h.advance(900);
    h.result(state({ tracking_mode: 'continuous', settings_revision: 1, access_allowed: true,
        server_now: h.cfg.state.server_now, access_until: h.cfg.state.server_now + 60, require_fullscreen: false }), 20);
    h.submit(); await flush(); h.setStateStatus(503);
    h.advance(58499); await h.tick(500);
    assert.equal(h.elements.get('quiz-access-unavailable').hidden, false, 'Integer clock uncertainty and guard cadence cannot extend permission beyond server expiry');
    assert.equal(h.elements.get('quiz-iframe-wrap').children.length, 1, 'Conservative guard preserves the form instance');
}

async function generationAndDetach() {
    const h = continuous(); await flush(); h.allow(); await flush();
    let resolve; h.setPulseChallengeReply(new Promise(done => { resolve = done; })); await h.tick(20000);
    const next = state({ tracking_mode: 'continuous', settings_revision: 1, tracking_generation: 'b'.repeat(32), access_allowed: false, require_fullscreen: false }); delete next.form_url; h.setState(next); await h.tick(3000);
    resolve({ ok: true, json: async () => ({ challenge: 'f'.repeat(64), challenge_until: next.server_now + 120 }) }); await flush(); await h.tick(0); await flush();
    assert.equal(h.pulses.length, 0, 'Old generation challenge never becomes a new generation result');
    assert.equal(h.challenges.at(-1).tracking_generation, 'b'.repeat(32), 'New generation waits for old HTTP settlement and starts fresh full preparation');
    assert.equal(h.challenges.at(-1).room_epoch, 'e'.repeat(32));
    h.setStateStatus(409); await h.tick(3000); const before = h.pulseChallenges.length;
    await assert.rejects(h.tick(20000), /refresh must be scheduled/, 'Detachment removes the pulse interval durably');
    h.advance(60000); h.submit(); await flush();
    assert.equal(h.pulseChallenges.length, before, 'Detached document never emits new challenges');
    assert.equal(h.elements.get('quiz-preflight').hidden, true, 'Preparation panel stays hidden after detachment');
}

(async () => {
    await stableCadenceAndFreshProbe(); await fullPreflightPendingThroughSettlement(); await completionAndFinishedPause(); await stalePulseAndExpiry(); await integerServerClockGuard(); await generationAndDetach();
    process.stdout.write('QuizTrackingContinuousJsTest: OK (20s cadence/fresh probe/single flight/finish/order/guard/iframe/generation/detach)\n');
})().catch(error => { console.error(error); process.exitCode = 1; });
