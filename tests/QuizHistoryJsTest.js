'use strict';
const assert = require('node:assert/strict');
const { harness, render, inlineScripts, state, flush } = require('./QuizLiveSettingsJsTest');
const event = (id, name = 'Alice') => ({ id, student_id: 1, first_name: name, last_name: 'Alpha', event_type: 'hidden', away_seconds: 12, duration_label: '12.000 s', date: '08/10/2026 12:00:00', is_incident: true, excused: false });
async function sessionHistory() {
    const html = render('session'), h = harness(html, state({ attempts: [], history_revision: 0 }));
    inlineScripts(html).forEach(h.run); await flush();
    const body = h.elements.get('qa-feed-body');
    h.setEventFeed({ events: [event(10)], rules_version: 'same-rules', history_revision: 0, reset: false });
    await h.tick(5000); assert.equal(body.children.length, 1);
    h.elements.get('qe-max-incidents').value = 'unsaved';
    h.setState(state({ attempts: [], history_revision: 1 }));
    h.setEventFeed({ events: [], rules_version: 'same-rules', history_revision: 1, reset: true });
    await h.tick(5000);
    assert.equal(body.children.length, 0, 'Reset clears obsolete feed rows even when rules stay identical');
    assert.equal(body.innerHTML.includes('qa-feed-empty'), true);
    assert.equal(h.elements.get('qe-max-incidents').value, 'unsaved', 'Reset refresh keeps unsaved teacher input');
    h.setEventFeed({ events: [event(11)], rules_version: 'same-rules', history_revision: 1, reset: false });
    await h.tick(5000);
    assert.equal(body.children.length, 1, 'New current event appears once after history reset');
    assert.equal(h.requests.filter(url => url.includes('/events')).at(-1).includes('history_revision=1'), true);
    // An old in-flight event response must not undo a newer attempt-state reset.
    let oldReply;
    h.setEventFeedReply(new Promise(resolve => { oldReply = resolve; }));
    await h.tick(5000);
    h.setState(state({ attempts: [], history_revision: 2 })); await h.tick(5000);
    oldReply({ ok: true, status: 200, json: async () => ({ events: [event(12, 'OLD RESPONSE')], rules_version: 'same-rules', history_revision: 1, reset: false }) });
    await flush();
    assert.equal(body.children.length, 1, 'Late response from an older history revision is ignored');
    h.setEventFeedReply(null);
    h.setEventFeed({ events: [], rules_version: 'same-rules', history_revision: 2, reset: true }); await h.tick(5000);
    assert.equal(body.children.length, 0);
    let oldStateReply;
    h.setStateReply(new Promise(resolve => { oldStateReply = resolve; }));
    h.setEventFeed({ events: [], rules_version: 'same-rules', history_revision: 3, reset: true }); await h.tick(5000);
    oldStateReply({ ok: true, status: 200, json: async () => state({ title: 'OLD ATTEMPTS RESPONSE', attempts: [], history_revision: 2 }) }); await flush();
    assert.notEqual(h.elements.get('qa-title').textContent, 'OLD ATTEMPTS RESPONSE', 'Feed revision also protects against a delayed older attempt-state response');
    h.setStateReply(null);
}
async function projectedHistory() {
    const html = render('board'), h = harness(html, state({ attempts: [], history_revision: 0 }));
    inlineScripts(html).forEach(h.run); await flush();
    const ticker = h.elements.get('qb-ticker');
    h.setEventFeed({ events: [event(10)], history_revision: 0, reset: false }); await h.tick(3000);
    assert.equal(ticker.hidden, false, 'Ordinary new event announces after baseline catch-up');
    h.setState(state({ attempts: [], history_revision: 1 }));
    h.setEventFeed({ events: [], history_revision: 1, reset: true }); await h.tick(3000);
    assert.equal(ticker.hidden, true, 'Reset neutralizes the old projected ticker');
    assert.equal(ticker.textContent, '');
    h.setEventFeed({ events: [event(11)], history_revision: 1, reset: false }); await h.tick(3000);
    assert.equal(ticker.hidden, false, 'New current observation is announced after reset baseline');
    let oldReply;
    h.setEventFeedReply(new Promise(resolve => { oldReply = resolve; })); await h.tick(3000);
    h.setState(state({ attempts: [], history_revision: 2 })); await h.tick(3000);
    assert.equal(ticker.hidden, true);
    oldReply({ ok: true, status: 200, json: async () => ({ events: [event(12, 'OLD RESPONSE')], history_revision: 1, reset: false }) }); await flush();
    assert.equal(ticker.hidden, true, 'Late old response cannot replay an obsolete alert');
    h.setEventFeedReply(null);
    h.setEventFeed({ events: [event(13, 'NEW BASELINE')], history_revision: 2, reset: true }); await h.tick(3000);
    assert.equal(ticker.hidden, true, 'Reconciliation never announces previously received history');
    h.setEventFeed({ events: [event(14, 'NEW CURRENT')], history_revision: 2, reset: false }); await h.tick(3000);
    assert.equal(ticker.textContent.includes('NEW CURRENT'), true);
}
(async () => {
    await sessionHistory(); await projectedHistory();
    process.stdout.write('QuizHistoryJsTest: OK (same-rules reset, feed/board, stale responses, no replay, unsaved inputs)\n');
})().catch(error => { console.error(error); process.exitCode = 1; });
