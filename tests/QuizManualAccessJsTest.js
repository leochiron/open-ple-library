'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { harness, render, inlineScripts, state, flush, root } = require('./QuizLiveSettingsJsTest');
const journalSource = fs.readFileSync(path.join(root, 'public/assets/js/quiz-journal.js'), 'utf8');
const monitorSource = fs.readFileSync(path.join(root, 'public/assets/js/quiz-monitor.js'), 'utf8');
function room(overrides = {}) {
    const html = render('room'); const h = harness(html, state(overrides));
    inlineScripts(html).forEach(s => h.run(s));
    Object.assign(h.window.QUIZ_ROOM.state, overrides);
    if (overrides.access_allowed === false) { delete h.window.QUIZ_ROOM.state.form_url; }
    h.run(journalSource); h.run(monitorSource);
    return h;
}
const response = value => ({ ok: true, status: 200, json: async () => value });
const iframe = h => h.elements.get('quiz-iframe-wrap').children[0];
const unavailable = h => !h.elements.get('quiz-access-unavailable').hidden;
async function suspendLiftPreservesForm() {
    const h = room(); await flush(); const form = iframe(h); form.savedInput = 'student answer remains in existing frame';
    h.setState(state({ access_allowed: false, form_url: undefined, finished: true, remaining_seconds: 0 }));
    await h.tick(15000);
    assert.equal(unavailable(h), true);
    assert.equal(h.elements.get('quiz-exam').hidden, true);
    assert.equal(h.elements.get('quiz-finished').hidden, true, 'Generic suspension has precedence over completion');
    assert.equal(h.elements.get('quiz-completion').hidden, true);
    assert.equal(h.elements.get('quiz-resume-btn').disabled, true);
    h.document.emit('fullscreenchange'); await h.tick(500);
    assert.equal(h.elements.get('quiz-fullscreen-gate').hidden, true);
    assert.equal(h.elements.get('quiz-timeover').hidden, true, 'No secondary overlay covers the generic suspension');
    assert.equal(iframe(h), form);
    h.setState(state({ access_allowed: true, finished: true, require_fullscreen: false })); await h.tick(3000);
    assert.equal(unavailable(h), false);
    assert.equal(h.elements.get('quiz-finished').hidden, false);
    assert.equal(h.elements.get('quiz-resume-btn').disabled, false, 'Lift re-enables resume for an already finished student');
    let resolve;
    h.setCompletion(new Promise(r => { resolve = r; }));
    h.elements.get('quiz-resume-btn').emit('click'); await flush();
    const resume = h.events.at(-1);
    assert.equal(resume.type, 'resume');
    resolve(response({ event_uid: resume.event_uid, attempt_id: 1, finished: false, access_allowed: true })); await flush();
    assert.equal(iframe(h), form, 'Lift/resume reuses the identical iframe');
    assert.equal(form.savedInput, 'student answer remains in existing frame');
    assert.equal(h.elements.get('quiz-exam').hidden, false);
}
async function freshDenialAndStalePoll() {
    const h = room(); await flush();
    let resolvePoll;
    h.setStateReply(new Promise(r => { resolvePoll = r; })); await h.tick(15000);
    h.setEventResponse(e => Promise.resolve(response({ event_uid: e.event_uid, attempt_id: 1, incident_count: 0, status: 'started', is_incident: false, access_allowed: false })));
    h.document.visibilityState = 'hidden'; h.document.emit('visibilitychange'); await flush();
    assert.equal(unavailable(h), true, 'Observation ack can immediately enforce a new block');
    resolvePoll(response(state({ access_allowed: true, require_fullscreen: false }))); await flush();
    assert.equal(unavailable(h), true, 'An older authorized poll cannot undo the fresh observation denial');
    h.setStateReply(null); h.setState(state({ access_allowed: false, form_url: undefined })); await h.tick(3000);
    h.advance(700); h.document.visibilityState = 'visible'; h.document.emit('visibilitychange'); await flush();
    assert.equal(h.events.some(e => e.type === 'hidden' && e.duration_ms === 700), true, 'Observations and return durations continue during suspension');
    const count = h.events.length;
    h.elements.get('quiz-submit-confirm').checked = true;
    h.elements.get('quiz-submit-confirm').emit('change'); h.elements.get('quiz-finish-btn').emit('click'); await flush();
    assert.equal(h.events.length, count, 'Blocked room cannot send completion');
    await h.tick(10000);
    assert.equal(h.requestOptions.filter(r => r.url.includes('/heartbeat')).at(-1).options.headers['X-Quiz-CSRF'], 'c'.repeat(48), 'Heartbeat carries the current transport token');
    assert.equal(h.requests.some(u => u.includes('/api/state')), true, 'Suspension keeps polling active');
    const initial = room({ access_allowed: false }); await flush();
    assert.equal(initial.elements.get('quiz-iframe-wrap').children.length, 0, 'Initial refusal injects no iframe');
}
async function completionDeniedWhilePending() {
    const h = room(); await flush(); const form = iframe(h);
    let resolve;
    h.setCompletion(new Promise(r => { resolve = r; }));
    h.elements.get('quiz-submit-confirm').checked = true; h.elements.get('quiz-submit-confirm').emit('change');
    h.elements.get('quiz-finish-btn').emit('click'); await flush();
    resolve(response(state({ access_allowed: false, form_url: undefined, finished: false, remaining_seconds: 450 }))); await flush();
    assert.equal(unavailable(h), true, 'Server refuses stale completion with ordinary suspended state');
    assert.equal(h.elements.get('quiz-finished').hidden, true);
    assert.equal(iframe(h), form);
    assert.equal(h.elements.get('quiz-finish-status').textContent, '', 'No detailed error is rendered for the refusal');
}
async function transportTokenOutsideQueue() {
    const h = harness('', state()); let token = 'FIRST_CSRF', beacons = [];
    h.context.navigator.sendBeacon = (_url, blob) => { beacons.push(blob); return true; };
    h.setEventResponse(() => Promise.reject(new Error('offline')));
    h.run(journalSource); h.window.csrfGetter = () => token;
    h.run("window.journal=window.QuizEventJournal({attemptId:1,endpoint:'/quiz/api/event',getCsrfToken:window.csrfGetter});window.journal.setGeneration('" + 'a'.repeat(32) + "');");
    h.window.journal.observe('copy', 'shortcut'); await flush();
    const original = h.events[0];
    assert.equal(original._csrf, token);
    assert.equal([...h.storage.values()].some(v => v.includes('_csrf') || v.includes(token)), false, 'Queue/storage never retain transport CSRF');
    token = 'ROTATED_CSRF'; await h.tick(3000);
    assert.equal(h.events.at(-1).event_uid, original.event_uid);
    assert.equal(h.events.at(-1)._csrf, token, 'Retry uses the current token without changing immutable UID');
    h.window.journal.beacon(); const body = JSON.parse(await beacons[0].text());
    assert.equal(body._csrf, token, 'Beacon carries the same transport protection');
    assert.equal(body.event_uid, original.event_uid);
}
async function boardGeneric() {
    const html = render('board'); const h = harness(html, state({ attempts: [{ student_id: 1, attempt_id: null, incident_count: 0, finished: false, connected: false, access_allowed: false }] }));
    inlineScripts(html).forEach(s => h.run(s)); await flush();
    assert.equal(h.rows.get(1).parts['[data-role="status"]'].textContent, 'Accès indisponible', 'Preconnection projected badge is generic');
    assert.equal(h.requests.some(u => u.includes('/api/board?id=')), true);
    assert.equal(h.requests.some(u => u.includes('/api/board/events?id=')), true);
    assert.equal(h.requests.some(u => u.includes('/api/attempts')), false, 'Board uses a dedicated public-safe projection');
}
(async () => {
    await suspendLiftPreservesForm(); await freshDenialAndStalePoll(); await completionDeniedWhilePending(); await transportTokenOutsideQueue(); await boardGeneric();
    console.log('QuizManualAccessJsTest: OK (suspension/lift/resume, identical iframe, stale polls, observations, CSRF retries/beacon, generic board)');
})().catch(error => { console.error(error); process.exitCode = 1; });
