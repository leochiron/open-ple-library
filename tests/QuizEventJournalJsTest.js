'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { harness, render, inlineScripts, state, flush, root } = require('./QuizLiveSettingsJsTest');
const source = fs.readFileSync(path.join(root, 'public/assets/js/quiz-journal.js'), 'utf8');
const generation = 'a'.repeat(32);
function create(storage, setup) {
    const h = harness('', state(), storage);
    if (setup) { setup(h); }
    h.run(source);
    h.run(`window.statuses=[]; window.acks=[]; window.detached=false; window.stale=false;
        window.journal=window.QuizEventJournal({attemptId:1,endpoint:'/quiz/api/event?attempt_id=1',
        onStatus:function(s){window.statuses.push(s);},onAck:function(r,e){window.acks.push(e);},
        onDetached:function(){window.detached=true;},onConflict:function(){window.stale=true;}});
        window.journal.setGeneration('${generation}');`);
    return h;
}
const saved = h => JSON.parse(h.storage.get('quiz-journal-v1:1:' + generation));
const ack = e => Promise.resolve({ ok: true, status: 200, json: async () => ({ event_uid: e.event_uid, attempt_id: e.attempt_id, incident_count: 0 }) });

async function factualDurations() {
    const h = create();
    const j = h.window.journal;
    j.start('hidden');
    assert.equal(h.events[0].type, 'away_start', 'Departure is sent immediately without needing a return');
    assert.equal('duration_ms' in h.events[0], false, 'An open departure has no invented duration');
    await flush();
    h.advance(750); h.jumpClock(-60000); j.end('hidden'); await flush();
    const micro = h.events.find(e => e.type === 'hidden');
    assert.equal(micro.duration_ms, 750, 'Subsecond timing uses the monotone clock, independently of wall clock changes');
    assert.equal(micro.away_seconds, 0);
    assert.equal(micro.related_event_uid, h.events[0].event_uid);
    j.start('hidden'); j.start('hidden'); j.start('blur'); j.start('fullscreen_exit'); await flush();
    h.advance(12000); j.end('hidden'); j.end('blur'); await flush();
    const hidden = h.events.filter(e => e.type === 'hidden').at(-1);
    const blur = h.events.find(e => e.type === 'blur');
    const episode = hidden.absence_uid;
    assert.equal(blur.absence_uid, episode);
    j.start('hidden'); await flush(); h.advance(500); j.end('hidden'); await flush();
    assert.notEqual(h.events.filter(e => e.type === 'hidden').at(-1).absence_uid, episode, 'A real page return closes the episode even while fullscreen remains exited');
    h.advance(27500); j.end('fullscreen_exit'); await flush();
    const fsReturn = h.events.find(e => e.type === 'fullscreen_exit');
    assert.equal(fsReturn.duration_ms, 40000);
    assert.equal(hidden.duration_ms, 12000, 'Long fullscreen absence never inflates the page-hidden duration');
    assert.equal(fsReturn.absence_uid, episode);
    assert.equal(h.events.filter(e => e.type === 'away_start' && e.source === 'hidden').length, 3, 'Duplicate start signal is suppressed, distinct transitions remain');
    j.start('blur'); h.advance(4000000); j.end('blur'); await flush();
    assert.equal(h.events.filter(e => e.type === 'blur').at(-1).duration_ms, 3600000, 'Payload duration is bounded');
    assert.equal(h.events.every(e => !('key' in e) && !('answer' in e) && JSON.stringify(e).length < 1024), true);
    assert.equal(saved(h).queue.length, 0, 'Only acknowledged entries leave the queue');
}

async function retryAndReload() {
    const storage = new Map();
    const h = create(storage, h => h.setEventResponse(() => Promise.reject(new Error('offline'))));
    h.window.journal.start('hidden'); await flush();
    const uid = h.events[0].event_uid;
    h.advance(200); h.window.journal.end('hidden'); await flush();
    assert.equal(saved(h).queue.length, 2);
    await h.tick(3000);
    assert.equal(h.events.at(-1).event_uid, uid, 'Network retry preserves the event UID');
    h.window.journal.beacon();
    assert.equal(saved(h).queue.length, 2, 'Beacon never discards an unacknowledged entry');
    const reloaded = create(storage); await flush();
    assert.equal(reloaded.events[0].event_uid, uid, 'Reload recovers the same observation UID');
    assert.equal(saved(reloaded).queue.length, 0);
    assert.equal(reloaded.events.some(e => ['finish', 'resume'].includes(e.type)), false, 'Completion is never queued or replayed');
    const wrongAck = create(undefined, h => h.setEventResponse(e => Promise.resolve({ ok: true, status: 200, json: async () => ({ event_uid: e.event_uid, attempt_id: 2 }) })));
    wrongAck.window.journal.observe('reload', 'navigation'); await flush();
    assert.equal(saved(wrongAck).queue.length, 1, 'An ack for another attempt cannot consume the queue');
    wrongAck.setEventResponse(ack); await wrongAck.tick(3000);
    assert.equal(saved(wrongAck).queue.length, 0);
}

async function limitsAndFences() {
    const h = create(undefined, h => h.setEventResponse(() => Promise.reject(new Error('offline'))));
    for (let i = 0; i < 105; i++) { h.window.journal.observe('copy', 'shortcut'); }
    await flush();
    assert.equal(saved(h).queue.length, 100);
    assert.equal(saved(h).dropped, 5, 'Overflow is recorded instead of silently lost');
    h.setEventResponse(ack); await h.tick(3000);
    assert.equal(h.events.some(e => e.type === 'tracking_diagnostic' && e.dropped_events === 5), true);
    assert.equal(saved(h).queue.length, 0);
    h.setEventResponse(() => Promise.reject(new Error('offline')));
    h.window.journal.observe('reload', 'navigation'); await flush();
    h.advance(86400001); await h.tick(3000);
    assert.equal(saved(h).queue[0].payload.type, 'tracking_diagnostic', 'Expired entries become a visible diagnostic');
    const staleUID = saved(h).queue[0].payload.event_uid;
    h.window.journal.setGeneration('b'.repeat(32)); await flush();
    const next = JSON.parse(h.storage.get('quiz-journal-v1:1:' + 'b'.repeat(32)));
    assert.equal(h.storage.has('quiz-journal-v1:1:' + generation), false);
    assert.equal(next.queue.every(item => item.payload.tracking_generation === 'b'.repeat(32) && item.payload.event_uid !== staleUID), true, 'Launch nonce fences old observations');
    h.setEventResponse(() => Promise.resolve({ ok: false, status: 409, json: async () => ({ error: 'access_unavailable' }) }));
    await h.tick(3000); assert.equal(h.window.stale, true, 'Generic conflict requests a context refresh');
    h.window.journal.detach(); // GET expected-attempt would reject a changed cookie
    const requests = h.events.length;
    h.window.journal.observe('copy', 'shortcut'); await h.tick(3000);
    assert.equal(h.events.length, requests, 'Detached student A does not send through the cookie of student B');
    const stale = create(undefined, h => h.setEventResponse(() => Promise.resolve({ ok: false, status: 409, json: async () => ({ error: 'access_unavailable' }) })));
    stale.window.journal.observe('reload', 'navigation'); await flush();
    assert.equal(stale.window.stale, true);
    assert.equal(saved(stale).queue.length, 1);
}

async function roomBinding() {
    const html = render('room'), h = harness(html, state());
    inlineScripts(html).forEach(h.run); h.run(source);
    h.run(fs.readFileSync(path.join(root, 'public/assets/js/quiz-monitor.js'), 'utf8'));
    h.document.visibilityState = 'hidden'; h.document.emit('visibilitychange'); await flush();
    h.advance(120); h.document.visibilityState = 'visible'; h.document.emit('visibilitychange'); await flush();
    assert.equal(h.events.find(e => e.type === 'hidden').duration_ms, 120, 'Room captures actual micro-absence');
    assert.equal(h.requests.filter(url => url.includes('/api/')).every(url => url.includes('attempt_id=1')), true);
    h.setState({ error: 'access_unavailable' }); h.setStateStatus(409); await h.tick(15000);
    assert.equal(h.elements.get('quiz-exam').hidden, true);
    assert.equal(h.elements.get('quiz-tracking-status').textContent, 'Accès indisponible. Contactez le formateur.');
    h.document.exitFullscreen(); h.advance(3600000); await h.tick(500);
    assert.equal(h.elements.get('quiz-fullscreen-gate').hidden, true, 'A later fullscreen change cannot reopen the gate on a detached page');
    assert.equal(h.elements.get('quiz-timeover').hidden, true, 'Timer overlay cannot mask the generic unavailable message');
    assert.equal(html.includes('trackingPending') || html.includes('trackingLost'), false, 'Student config contains no technical loss counters');
}

async function separatePageEpisodes() {
    for (const required of [false, true]) {
        for (const fullscreenFirst of [false, true]) {
            const html = render('room'), h = harness(html, state({ require_fullscreen: required }));
            inlineScripts(html).forEach(h.run); h.run(source);
            h.run(fs.readFileSync(path.join(root, 'public/assets/js/quiz-monitor.js'), 'utf8'));
            h.setState(state({ require_fullscreen: required })); await h.tick(15000);
            h.document.documentElement.requestFullscreen();
            if (fullscreenFirst) { h.document.exitFullscreen(); }
            h.document.visibilityState = 'hidden'; h.document.emit('visibilitychange');
            if (!fullscreenFirst) { h.document.exitFullscreen(); }
            await flush(); h.advance(12000);
            h.document.visibilityState = 'visible'; h.document.emit('visibilitychange'); await flush();
            h.advance(30000);
            h.document.visibilityState = 'hidden'; h.document.emit('visibilitychange'); await flush();
            h.advance(12000); h.document.visibilityState = 'visible'; h.document.emit('visibilitychange'); await flush();
            h.document.documentElement.requestFullscreen(); await flush();
            const pages = h.events.filter(e => e.type === 'hidden');
            const fsReturn = h.events.find(e => e.type === 'fullscreen_exit');
            assert.equal(pages.length, 2);
            assert.notEqual(pages[0].absence_uid, pages[1].absence_uid, 'Real return separates page episodes irrespective of fullscreen requirement');
            assert.equal(fsReturn.absence_uid, pages[0].absence_uid, 'Delayed fullscreen return remains correlated to its first overlapping episode');
            assert.equal(fsReturn.duration_ms, 54000);
            assert.equal(pages.every(e => e.duration_ms === 12000), true);
        }
    }
}

(async () => {
    await factualDurations(); await retryAndReload(); await limitsAndFences(); await roomBinding(); await separatePageEpisodes();
    process.stdout.write('QuizEventJournalJsTest: OK (micro, sources, retry/reload, bounds, generation, attempt binding)\n');
})().catch(error => { console.error(error); process.exitCode = 1; });
