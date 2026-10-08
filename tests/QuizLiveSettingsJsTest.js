'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const php = process.env.QUIZ_TEST_PHP || 'php';
const render = view => execFileSync(php, [path.join(__dirname, 'fixtures/RenderQuizLiveSettings.php'), view], { encoding: 'utf8' });
const flush = () => new Promise(resolve => setImmediate(resolve));

class Element {
    constructor(id = '') {
        this.id = id;
        this.hidden = false;
        this.disabled = false;
        this.checked = false;
        this.value = '';
        this.textContent = '';
        this.dataset = {};
        this.children = [];
        this.listeners = {};
        this.classes = new Set();
        this.classList = {
            add: (...names) => names.forEach(name => this.classes.add(name)),
            remove: (...names) => names.forEach(name => this.classes.delete(name)),
            toggle: (name, on) => on ? this.classes.add(name) : this.classes.delete(name),
        };
    }
    set innerHTML(html) { this.html = html; this.children = []; }
    get innerHTML() {
        return this.html === undefined ? String(this.textContent).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') : this.html;
    }
    addEventListener(name, listener) { (this.listeners[name] ||= []).push(listener); }
    emit(name, event = {}) { (this.listeners[name] || []).forEach(listener => listener(event)); }
    setAttribute(name, value) { this[name] = value; }
    get firstChild() { return this.children[0] || null; }
    get lastChild() { return this.children[this.children.length - 1] || null; }
    appendChild(child) { child.parent = this; this.children.push(child); }
    insertBefore(child, before) {
        child.parent = this;
        const index = before ? this.children.indexOf(before) : -1;
        if (index < 0) { this.children.push(child); } else { this.children.splice(index, 0, child); }
    }
    removeChild(child) { this.children.splice(this.children.indexOf(child), 1); child.parent = null; }
    remove() { if (this.parent) { this.parent.removeChild(this); } }
    querySelector(selector) { return this.parts?.[selector] || null; }
}

function harness(html, initial) {
    const elements = new Map();
    for (const match of html.matchAll(/<[^>]*\bid="([^"]+)"[^>]*>/g)) {
        const el = new Element(match[1]);
        el.hidden = /\bhidden(?:\s|>)/.test(match[0]);
        el.value = /\bvalue="([^"]*)"/.exec(match[0])?.[1] || '';
        elements.set(el.id, el);
    }
    const document = new Element();
    Object.assign(document, {
        title: 'Initial quiz', visibilityState: 'visible', activeElement: null,
        getElementById: id => elements.get(id) || null,
        createElement: () => new Element(), hasFocus: () => true,
        querySelector: selector => rows.get(Number(/data-student-id="(\d+)"/.exec(selector)?.[1])) || null,
        documentElement: new Element(), fullscreenElement: null,
    });
    document.documentElement.requestFullscreen = () => {
        document.fullscreenElement = document.documentElement;
        document.emit('fullscreenchange');
    };
    document.exitFullscreen = () => {
        document.fullscreenElement = null;
        document.emit('fullscreenchange');
    };
    const rows = new Map();
    for (const id of [1, 2]) {
        const row = new Element();
        row.parts = Object.fromEntries(['count', 'status', 'last', 'quota', 'finished', 'events', 'incidents', 'action'].map(role => [`[data-role="${role}"]`, new Element()]));
        rows.set(id, row);
    }
    if (elements.has('qb-grid')) { elements.get('qb-grid').querySelector = document.querySelector; }
    const window = new Element();
    const timers = new Map();
    const events = [];
    const requests = [];
    let nextId = 0;
    let now = Date.now();
    let state = initial;
    let completion = null;
    let eventFeed = { events: [] };
    const schedule = (fn, delay, repeat) => {
        timers.set(++nextId, { fn, delay, repeat });
        return nextId;
    };
    const context = vm.createContext({
        window, document, navigator: { sendBeacon: () => false },
        performance: { getEntriesByType: () => [{ type: 'navigate' }] },
        Blob, AbortController,
        Date: class extends Date { static now() { return now; } },
        setTimeout: (fn, delay) => schedule(fn, delay, false),
        setInterval: (fn, delay) => schedule(fn, delay, true),
        clearTimeout: id => timers.delete(id), clearInterval: id => timers.delete(id),
        fetch: (url, options = {}) => {
            requests.push(url);
            if (url.includes('/event') && options.method === 'POST') {
                const event = JSON.parse(options.body);
                events.push(event);
                if (['finish', 'resume'].includes(event.type) && completion) { return completion; }
                return Promise.resolve({ ok: true, json: async () => ({ incident_count: 0, status: 'started', is_incident: false }) });
            }
            const response = url.includes('/events') ? eventFeed
                : url.includes('/heartbeat') ? { ok: true } : state;
            return Promise.resolve({ status: 200, ok: true, json: async () => response });
        },
    });
    return {
        context, document, elements, rows, events, requests,
        setEventFeed: value => { eventFeed = value; },
        setState: value => { state = value; },
        setCompletion: value => { completion = value; },
        advance: ms => { now += ms; },
        run: source => vm.runInContext(source, context),
        tick: async delay => {
            const scheduled = [...timers].filter(([, timer]) => timer.delay === delay);
            assert.ok(scheduled.length, `A ${delay}ms refresh must be scheduled`);
            for (const [id, timer] of scheduled) {
                if (!timer.repeat) { timers.delete(id); }
                timer.fn();
            }
            await flush();
        },
    };
}

function inlineScripts(html) {
    return [...html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map(match => match[1]).filter(Boolean);
}

function state(overrides = {}) {
    return {
        state: 'running', title: 'Updated quiz', server_now: Math.floor(Date.now() / 1000), remaining_seconds: 1200,
        duration_minutes: 20, max_incidents: 3, min_away_seconds: 5, require_fullscreen: true, reload_is_incident: true,
        incident_count: 2, attempt_status: 'suspect', finished: false,
        form_url: 'https://docs.google.com/forms/d/e/changed/viewform',
        ...overrides,
    };
}

async function studentTest() {
    const html = render('room');
    const h = harness(html, state());
    inlineScripts(html).forEach(h.run);
    h.run(fs.readFileSync(path.join(root, 'public/assets/js/quiz-monitor.js'), 'utf8'));
    const el = id => h.elements.get(id);
    const wrap = el('quiz-iframe-wrap');
    assert.equal(wrap.children.length, 1, 'Initial active quiz injects exactly one Form');
    const originalIframe = wrap.children[0];
    originalIframe.answer = 'Student draft';
    assert.equal(el('quiz-fullscreen-gate').hidden, true, 'Initial optional fullscreen does not block');

    await h.tick(15000);
    assert.equal(el('quiz-fullscreen-gate').hidden, false, 'Enabling fullscreen after load opens the gate');
    assert.equal(el('quiz-incidents-max').textContent, '3', 'Student quota updates on the same page');
    assert.equal(el('quiz-rule-min_away_seconds').textContent, '5', 'Current threshold is visible');
    assert.equal(h.document.title, 'Updated quiz', 'Current title updates');
    await h.tick(500);
    assert.equal(el('quiz-timer').textContent, '20:00', 'Clock adopts the new server duration');
    el('quiz-fullscreen-btn').emit('click');
    assert.equal(h.document.fullscreenElement, h.document.documentElement, 'Button is wired even when initial rule was false');
    assert.equal(el('quiz-fullscreen-gate').hidden, true, 'Entering fullscreen clears the gate');

    h.document.exitFullscreen();
    h.advance(5000);
    h.setState(state({ require_fullscreen: false, reload_is_incident: false }));
    await h.tick(15000);
    assert.equal(el('quiz-fullscreen-gate').hidden, true, 'Disabling fullscreen removes a visible gate');
    assert.equal(el('quiz-rule-fullscreen').hidden, true);
    assert.equal(el('quiz-rule-reload').hidden, true);
    h.setState(state());
    await h.tick(15000);
    el('quiz-fullscreen-btn').emit('click');
    await flush();
    assert.equal(h.events.filter(e => e.type === 'fullscreen_exit').length, 0, 'Disabling rule cancels the old exit interval');
    h.document.exitFullscreen();
    h.advance(7000);
    el('quiz-fullscreen-btn').emit('click');
    await flush();
    assert.equal(h.events.filter(e => e.type === 'fullscreen_exit').length, 1, 'Reenabled listener reports the next genuine fullscreen exit');

    let acknowledge;
    h.setCompletion(new Promise(resolve => { acknowledge = resolve; }));
    el('quiz-submit-confirm').checked = true;
    el('quiz-submit-confirm').emit('change');
    el('quiz-finish-btn').emit('click');
    await flush();
    assert.equal(el('quiz-exam').hidden, false, 'Finish waits for server acknowledgement');
    h.setState(state({ finished: true }));
    await h.tick(15000);
    assert.equal(el('quiz-exam').hidden, false, 'Poll during pending finish cannot commit local completion');
    acknowledge({ ok: true, json: async () => ({ finished: true }) });
    await flush();
    assert.equal(el('quiz-exam').hidden, true);
    assert.equal(el('quiz-finished').hidden, false);
    assert.equal(el('quiz-fullscreen-gate').hidden, true, 'Finished student remains free of fullscreen gate');
    h.setState(state({ finished: true, max_incidents: 4 }));
    await h.tick(15000);
    assert.equal(el('quiz-incidents-max').textContent, '4', 'Finished student still receives updated rules');
    assert.equal(el('quiz-exam').hidden, true, 'Settings poll does not reopen a finished Form');
    h.setCompletion(Promise.resolve({ ok: true, json: async () => ({ finished: false }) }));
    el('quiz-resume-btn').emit('click');
    await flush();
    assert.equal(el('quiz-exam').hidden, false, 'Acknowledged resume reopens the existing quiz');
    assert.equal(el('quiz-fullscreen-gate').hidden, false, 'Resume applies the current fullscreen rule');
    assert.equal(wrap.children.length, 1, 'Settings, finish and resume never replace the iframe');
    assert.equal(wrap.children[0], originalIframe);
    assert.equal(originalIframe.answer, 'Student draft');
    assert.equal(originalIframe.src.includes('/initial/'), true, 'Changed form URL does not silently replace a started questionnaire');
}

async function teacherTest(view, delay, prefix) {
    const html = render(view);
    const attempt = { attempt_id: 1, student_id: 1, first_name: 'Alice', last_name: 'Alpha', incident_count: 2, event_count: 2, status: 'suspect', finished: false, connected: true, last_seen_seconds: 0 };
    const h = harness(html, state({ attempts: [attempt], pin: '123456' }));
    if (view === 'session') { h.elements.get('qe-max-incidents').value = 'unsaved draft'; }
    inlineScripts(html).forEach(h.run);
    await flush();
    assert.equal(h.elements.get(prefix + '-rule-max_incidents').textContent, '3');
    assert.equal(h.elements.get(prefix + '-rule-duration_minutes').textContent, '20');
    assert.equal(h.elements.get(prefix + '-rule-fullscreen').hidden, false);
    assert.equal(h.elements.get(prefix + '-title').textContent, 'Updated quiz');
    if (view === 'board') {
        assert.equal(h.rows.get(1).classes.has('is-danger'), false, 'Two incidents are below the new quota of three');
        await h.tick(500);
        assert.equal(h.elements.get('qb-timer').textContent, '20:00');
    } else {
        assert.equal(h.rows.get(1).parts['[data-role="incidents"]'].textContent, '2 / 3');
        assert.equal(h.elements.get('qe-max-incidents').value, 'unsaved draft', 'Teacher polling preserves unsaved input');
    }
    h.setState(state({ max_incidents: 2, require_fullscreen: false, reload_is_incident: false, attempts: [attempt] }));
    await h.tick(delay);
    assert.equal(h.elements.get(prefix + '-rule-fullscreen').hidden, true);
    if (view === 'board') {
        assert.equal(h.rows.get(1).classes.has('is-danger'), true, 'Lower quota changes board danger color without reload');
        assert.equal(h.elements.get('qb-ticker').hidden, true, 'Rule changes do not replay old board notifications');
        assert.equal(h.requests.filter(url => url.includes('/events')).every(url => !url.includes('rules_version')), true, 'Board keeps its event cursor independently of incident version');
        h.setState(state({ attempts: [{ ...attempt, finished: true }] }));
        await h.tick(delay);
        assert.equal(h.rows.get(1).classes.has('is-finished'), true, 'Finished board tile survives rule changes');
    }
}

async function feedReconciliationTest() {
    const html = render('session');
    const h = harness(html, state({ attempts: [] }));
    inlineScripts(html).forEach(h.run);
    await flush();
    const tbody = h.elements.get('qa-feed-body');
    const raw = { student_id: 1, first_name: 'Alice', last_name: 'Alpha', event_type: 'hidden', away_seconds: 7, date: '08/10/2026 12:00:00', excused: false };
    h.setEventFeed({ events: [{ ...raw, id: 1, is_incident: true }, { ...raw, id: 2, is_incident: true }], rules_version: 'initial-rules', reset: false });
    await h.tick(5000);
    assert.equal(tbody.children.length, 2);
    assert.equal(tbody.children.every(row => row.className === 'quiz-row--alert'), true, 'Initial feed shows old incident qualification');
    h.elements.get('qe-max-incidents').value = 'unsaved draft';
    h.setEventFeed({ events: [{ ...raw, id: 1, is_incident: false }, { ...raw, id: 2, is_incident: true, excused: true }], rules_version: 'changed-rules', reset: true });
    await h.tick(5000);
    assert.equal(tbody.children.length, 2, 'Reconciliation replaces history instead of duplicating old rows');
    assert.equal(tbody.children.every(row => row.className !== 'quiz-row--alert'), true, 'Existing rows lose obsolete alerts and keep arbitration');
    assert.equal(h.elements.get('qe-max-incidents').value, 'unsaved draft', 'History refresh leaves unsaved teacher input intact');
    h.setEventFeed({ events: [{ ...raw, id: 3, is_incident: false }], rules_version: 'changed-rules', reset: false });
    await h.tick(5000);
    assert.equal(tbody.children.length, 3, 'Next ordinary event is appended once after reconciliation');
    const lastRequest = h.requests.filter(url => url.includes('/events')).at(-1);
    assert.equal(lastRequest.includes('after=2'), true, 'Reconciled cursor reaches newest displayed event');
    assert.equal(lastRequest.includes('rules_version=changed-rules'), true, 'Next request carries the acknowledged rules version');
    h.setEventFeed({ events: [], rules_version: 'empty-rules', reset: true });
    await h.tick(5000);
    assert.equal(tbody.children.length, 0, 'Empty reconciliation clears obsolete rows');
    assert.equal(tbody.innerHTML.includes('qa-feed-empty'), true, 'Empty feed has a visible empty-state row');
}

(async () => {
    await studentTest();
    await teacherTest('board', 3000, 'qb');
    await teacherTest('session', 5000, 'qa');
    await feedReconciliationTest();
    process.stdout.write('QuizLiveSettingsJsTest: OK (room, board, teacher session, feed reconciliation)\n');
})().catch(error => { console.error(error); process.exitCode = 1; });
