'use strict';
// VM regressions complement, but do not replace, the native browser recipe.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { setup } = require('./QuizTrackingPreflightJsTest');
const { harness, render, inlineScripts, state, flush, root } = require('./QuizLiveSettingsJsTest');
const source = name => fs.readFileSync(path.join(root, 'public/assets/js', name), 'utf8');

async function voluntaryPreparation() {
    const h = setup({}, true, null, false); await flush();
    assert.equal(h.challenges.length, 0, 'Reading the instructions must not consume the preparation window');
    h.submit(); await flush();
    assert.equal(h.challenges.length, 1, 'Start obtains a fresh challenge');
    assert.equal(h.elements.get('quiz-preflight-submit').disabled, true, 'Validation is unavailable until the requested gestures');
    h.enter(true); h.visible(false); h.focus(false); h.advance(200); h.visible(true); h.focus(true);
    assert.equal(h.elements.get('quiz-preflight-submit').disabled, false, 'Observed gestures enable validation');
    h.submit(); await flush();
    assert.equal(h.diagnostics[0].checks.trusted_enter_received, true);
    assert.equal(h.elements.get('quiz-preflight-start').disabled, false, 'Refusal offers a working retry action at the top');
    h.submit(); await flush();
    assert.equal(h.challenges.length, 2, 'Retry reopens collection, without requiring a reload');
    await h.tick(45000); await flush();
    assert.equal(h.elements.get('quiz-preflight-start').disabled, false, 'Expired preparation can be restarted');
    assert.notEqual(h.elements.get('quiz-preflight-status').textContent, '', 'Preparation has a visible state');
}

async function keyboardWithoutButtonFocus() {
    const h = setup({}, true, null, false); await flush();
    h.document.emit('keydown', { key: 'Enter', isTrusted: true });
    assert.equal(h.challenges.length, 0, 'Enter before starting does not begin preparation');
    h.submit(); await flush();
    h.elements.get('quiz-preflight-enter').emit('click', { isTrusted: true });
    h.document.emit('keydown', { key: 'Enter', isTrusted: false });
    assert.equal(h.elements.get('quiz-preflight-step-enter')['data-done'], 'false', 'Click and synthetic Enter do not validate the keyboard step');
    h.document.emit('keydown', { key: 'Enter', isTrusted: true });
    assert.equal(h.elements.get('quiz-preflight-step-enter')['data-done'], 'true', 'Native Enter is detected even when the dedicated button does not have focus');
    h.visible(false); h.focus(false); h.visible(true); h.focus(true); h.submit(); await flush();
    assert.equal(h.diagnostics[0].checks.trusted_enter_received, true);
}

async function guidedPreparationAndReturn() {
    const html = render('room');
    assert.ok(html.indexOf('id="quiz-preflight-start"') >= 0 && html.indexOf('id="quiz-preflight-start"') < html.indexOf('id="quiz-preflight-step-enter"'), 'Start is above the gestures');
    const h = setup({ require_fullscreen: true }, true, null, false); await flush();
    const el = id => h.elements.get('quiz-preflight-' + id);
    assert.equal(el('start').hidden, false);
    assert.equal(el('steps').hidden, true, 'Reading view only offers Start');
    assert.equal(el('submit').hidden, true, 'Validation has its own action, after the gestures');
    el('start').emit('click', { isTrusted: true }); await flush();
    assert.equal(el('step-enter').hidden, false);
    assert.equal(el('step-return').hidden, true, 'Future actions are not presented as immediately actionable');
    assert.equal(el('fullscreen').disabled, true);
    h.enter(true);
    assert.equal(el('step-return').hidden, false);
    assert.equal(el('enter').hidden, true, 'Completed keyboard action leaves a check, not another button');
    h.visible(false); h.focus(false); h.visible(true); h.focus(true);
    assert.equal(el('fullscreen').hidden, false);
    assert.equal(el('fullscreen').disabled, false, 'Fullscreen is usable while server admission is still denied');
    el('fullscreen').emit('click', { isTrusted: true }); h.fullscreen(true);
    assert.equal(el('submit').hidden, false);
    const allowed = state({ tracking_mode: 'preflight', settings_revision: 1, require_fullscreen: true, access_allowed: true, server_now: h.cfg.state.server_now, access_until: h.cfg.state.server_now + 60 });
    h.result(allowed); el('submit').emit('click', { isTrusted: true }); await flush();
    const iframe = h.elements.get('quiz-iframe-wrap').children[0]; iframe.answer = 'Draft after incident';
    h.fullscreen(false);
    const denied = { ...allowed, access_allowed: false, access_until: null }; delete denied.form_url;
    h.setState(denied); await h.tick(3000);
    assert.equal(h.elements.get('quiz-fullscreen-gate').hidden, true, 'Legacy overlay does not cover the recovery preparation');
    assert.equal(el('start').hidden, false, 'Recovery explicitly offers a new start at the top');
    assert.equal(el('steps').hidden, true, 'Old successful checks are not displayed as a new preparation');
    assert.equal(el('status').textContent, h.cfg.i18n.prepareRecovery, 'Recovery points to the new start instead of reporting a generic refusal');
    el('start').emit('click', { isTrusted: true }); await flush();
    h.enter(true); h.visible(false); h.focus(false); h.visible(true); h.focus(true);
    assert.equal(el('fullscreen').disabled, false, 'Fullscreen becomes actionable again during recovery');
    h.document.documentElement.requestFullscreen = () => Promise.reject(new Error('Permission denied'));
    el('fullscreen').emit('click', { isTrusted: true }); await flush();
    assert.equal(el('fullscreen').disabled, false, 'A browser refusal leaves a usable retry button');
    assert.equal(el('submit').hidden, true, 'Browser fullscreen failure cannot finish preparation');
    assert.match(el('status').textContent, /plein écran|fullscreen/i);
    h.document.documentElement.requestFullscreen = () => { h.document.fullscreenElement = h.document.documentElement; };
    el('fullscreen').emit('click', { isTrusted: true }); h.fullscreen(true);
    assert.equal(h.elements.get('quiz-exam').hidden, true, 'Re-entering fullscreen alone never grants access');
    h.result(allowed); el('submit').emit('click', { isTrusted: true }); await flush();
    assert.equal(h.elements.get('quiz-exam').hidden, false);
    assert.equal(h.elements.get('quiz-iframe-wrap').children[0], iframe, 'Recovery restores the existing form');
    assert.equal(iframe.answer, 'Draft after incident');
}

async function fullscreenAddedDuringPreparation() {
    const h = setup({ require_fullscreen: false }); await flush();
    const next = { ...h.cfg.state, settings_revision: 2, tracking_generation: 'b'.repeat(32), require_fullscreen: true, access_allowed: false };
    h.setState(next); await h.tick(3000); await flush();
    h.enter(true); h.visible(false); h.focus(false); h.visible(true); h.focus(true);
    assert.equal(h.elements.get('quiz-preflight-fullscreen').hidden, false, 'A newly required fullscreen rule has an actionable preparation button');
    assert.equal(h.elements.get('quiz-preflight-fullscreen').disabled, false);
    assert.equal(h.elements.get('quiz-preflight-submit').hidden, true, 'The new fullscreen requirement cannot be skipped');
}

async function immediateFullscreen() {
    for (const mode of ['off', 'preflight', 'continuous']) {
        const initial = state({ tracking_mode: mode, settings_revision: 1, require_fullscreen: true, access_allowed: true, access_until: Math.floor(Date.now() / 1000) + 60 });
        const html = render('room'), h = harness(html, initial);
        inlineScripts(html).forEach(h.run); h.window.QUIZ_ROOM.state = initial; h.window.QUIZ_ROOM.requireFullscreen = true;
        h.document.fullscreenElement = h.document.documentElement;
        h.run(source('quiz-journal.js')); h.run(source('quiz-monitor.js')); await flush();
        const iframe = h.elements.get('quiz-iframe-wrap').children[0]; iframe.answer = 'Draft';
        h.document.fullscreenElement = null; h.document.emit('fullscreenchange', { isTrusted: true }); await flush();
        assert.equal(h.elements.get('quiz-fullscreen-gate').hidden, false, mode + ': immediate fullscreen gate');
        assert.equal(h.elements.get('quiz-exam').hidden, true, mode + ': keyboard and pointer cannot reach the form outside fullscreen');
        h.document.fullscreenElement = h.document.documentElement; h.document.emit('fullscreenchange', { isTrusted: true }); await flush();
        assert.equal(h.elements.get('quiz-exam').hidden, false, mode + ': current server admission restores the same form');
        assert.equal(h.elements.get('quiz-iframe-wrap').children[0], iframe);
        assert.equal(iframe.answer, 'Draft');
        h.setState({ ...initial, access_allowed: false, access_until: null }); await h.tick(15000);
        h.document.emit('fullscreenchange', { isTrusted: true });
        assert.equal(h.elements.get('quiz-exam').hidden, true, mode + ': fullscreen never overrides server refusal');
    }
}

async function shortExitAndPendingNetwork() {
    const initial = state({ tracking_mode: 'off' }), html = render('room'), h = harness(html, initial);
    inlineScripts(html).forEach(h.run); h.window.QUIZ_ROOM.state = initial;
    h.run(source('quiz-journal.js')); h.run(source('quiz-monitor.js')); await flush();
    h.setEventResponse(() => new Promise(() => {}));
    h.document.hasFocus = () => false; h.window.emit('blur'); await h.tick(0);
    assert.equal(h.elements.get('quiz-away-warning').hidden, false, 'Departure is visible even before the network acknowledgement');
    h.advance(200); h.document.hasFocus = () => true; h.window.emit('focus'); await flush();
    assert.equal(h.elements.get('quiz-away-warning').hidden, false, 'A short return has a visible warning below the incident threshold');
}

(async () => {
    await fullscreenAddedDuringPreparation(); await guidedPreparationAndReturn(); await voluntaryPreparation(); await keyboardWithoutButtonFocus(); await immediateFullscreen(); await shortExitAndPendingNetwork();
    process.stdout.write('QuizStrictExperienceJsTest: OK (guided steps, recovery, fullscreen retry/live rule, existing draft, short exits)\n');
})().catch(error => { console.error(error); process.exitCode = 1; });
