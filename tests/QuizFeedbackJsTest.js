'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { setup } = require('./QuizTrackingPreflightJsTest');
const { harness, render, inlineScripts, state, flush, root } = require('./QuizLiveSettingsJsTest');
const source = name => fs.readFileSync(path.join(root, 'public/assets/js', name), 'utf8');

async function noPreparationWarnings() {
    const h = setup({}, true, null, false); await flush(); h.submit(); await flush();
    h.document.emit('copy'); await flush();
    assert.equal(h.events.length, 0);
    assert.equal(h.elements.get('quiz-away-warning').hidden, true, 'Preparation must not warn about ordinary observations it does not record');
}

async function completionWithConcurrentPoll() {
    const initial = state({ tracking_mode: 'continuous', require_fullscreen: false, access_until: state().server_now + 60 });
    const html = render('room'), h = harness(html, initial);
    inlineScripts(html).forEach(h.run); h.window.QUIZ_ROOM.state = initial; h.window.QUIZ_ROOM.requireFullscreen = false;
    h.run(source('quiz-journal.js')); h.run(source('quiz-monitor.js')); await flush();
    let ack;
    h.setCompletion(new Promise(resolve => { ack = resolve; }));
    h.elements.get('quiz-submit-confirm').checked = true;
    h.elements.get('quiz-submit-confirm').emit('change'); h.elements.get('quiz-finish-btn').emit('click'); await flush();
    const event = h.events.at(-1);
    await h.tick(15000);
    ack({ ok: true, status: 200, json: async () => ({ attempt_id: 1, event_uid: event.event_uid, finished: true, access_allowed: true }) }); await flush();
    assert.equal(h.elements.get('quiz-finished').hidden, false, 'A periodic poll must not discard the completion ACK');
    assert.equal(h.window.QUIZ_ROOM.state.finished, true, 'Confirmed completion must also update the shared state used by preparation');
    h.setState({ ...initial, finished: true, completion_recorded: true, access_allowed: false, access_until: null, form_url: undefined });
    await h.tick(15000);
    assert.equal(h.elements.get('quiz-finished').hidden, false, 'Saved receipt survives the end of the monitoring permission');
    assert.equal(h.elements.get('quiz-exam').hidden, true, 'Receipt never reopens the form');
    assert.equal(h.elements.get('quiz-resume-btn').disabled, true, 'Receipt never authorizes resume');
    h.setState({ ...initial, finished: true, completion_recorded: false, access_allowed: false });
    await h.tick(3000);
    assert.equal(h.elements.get('quiz-finished').hidden, true, 'An explicit server denial retains precedence');
}

async function completionRetryAndReconciliation() {
    const initial = state({ tracking_mode: 'off', require_fullscreen: false }), html = render('room'), h = harness(html, initial);
    inlineScripts(html).forEach(h.run); h.window.QUIZ_ROOM.state = initial; h.window.QUIZ_ROOM.requireFullscreen = false;
    h.run(source('quiz-journal.js')); h.run(source('quiz-monitor.js')); await flush();
    const iframe = h.elements.get('quiz-iframe-wrap').children[0];
    h.setCompletion(Promise.resolve({ ok: false, status: 503, json: async () => ({}) }));
    h.elements.get('quiz-submit-confirm').checked = true; h.elements.get('quiz-finish-btn').emit('click'); await flush();
    const first = h.events.at(-1);
    assert.equal(h.elements.get('quiz-finished').hidden, true, 'A failed write is never announced as saved');
    h.setEventResponse(e => Promise.resolve({ ok: true, status: 200, json: async () => ({ attempt_id: 1, event_uid: e.event_uid, finished: true, access_allowed: true }) }));
    h.setCompletion(null); h.elements.get('quiz-finish-btn').emit('click'); await flush();
    assert.equal(h.events.at(-1).event_uid, first.event_uid, 'Retry keeps the same idempotent completion identifier');
    assert.equal(h.elements.get('quiz-finished').hidden, false);
    assert.equal(h.elements.get('quiz-iframe-wrap').children[0], iframe, 'Retry preserves the form instance');
}

async function quickArbitration() {
    const html = render('session');
    assert.ok(html.includes('/quiz-admin/attempt/excuse-all'), 'Teacher session exposes whole-student arbitration');
    const h = harness(html, state({ attempts: [{ student_id: 1, attempt_id: 9, incident_count: 2, event_count: 3, status: 'suspect', connected: true }], history_revision: 0 }));
    inlineScripts(html).forEach(h.run); await flush();
    assert.ok(h.rows.get(1).parts['[data-role="action"]'].innerHTML.includes('excuse-all'), 'New live rows expose the same arbitration');
    h.setEventFeed({events:[{id:10,student_id:1,first_name:'Test',last_name:'Auto',event_type:'hidden',is_incident:true,excused:true,away_seconds:12,date:'14/10/2026'}]});
    await h.tick(5000);
    const row = h.elements.get('qa-feed-body').children[0];
    assert.ok(row.innerHTML.includes('(excusé)'), 'Excused history explicitly shows the teacher decision');
    assert.equal(row.innerHTML.includes('⚠️'), false, 'Excused history no longer displays an active warning icon');
}

(async () => { await noPreparationWarnings(); await completionWithConcurrentPoll(); await completionRetryAndReconciliation(); await quickArbitration(); console.log('QuizFeedbackJsTest: OK'); })().catch(e => { console.error(e); process.exitCode = 1; });
