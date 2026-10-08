/** Bounded observation journal. No form values or typed keys enter this queue. */
(function () {
    'use strict';
    window.QuizEventJournal = function (options) {
        var attempt = options.attemptId, generation = '', key = '', queue = [], dropped = 0;
        var sources = {}, episode = null, busy = false, detached = false, lastTick = 0, storageFailed = false;
        var MAX = 100, TTL = 86400000, PREFIX = 'quiz-journal-v1:' + attempt + ':';
        function uid() {
            var bytes = new Uint8Array(16);
            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(bytes);
                return Array.prototype.map.call(bytes, function (n) { return ('0' + n.toString(16)).slice(-2); }).join('');
            }
            return Date.now().toString(36) + '_' + Math.random().toString(36).slice(2) + '_' + Math.random().toString(36).slice(2);
        }
        function now() {
            lastTick = Math.max(lastTick, performance.now());
            return lastTick;
        }
        function status() {
            if (options.onStatus) { options.onStatus({ pending: queue.length, dropped: dropped, detached: detached }); }
        }
        function lose(count) { dropped = Math.min(100000, dropped + count); }
        function persist() {
            try { sessionStorage.setItem(key, JSON.stringify({ queue: queue, dropped: dropped })); }
            catch (e) { if (!storageFailed) { lose(1); storageFailed = true; } } // memory retry remains available
            status();
        }
        function valid(item) {
            if (!item || !item.payload || typeof item.time !== 'number') { return false; }
            var p = item.payload;
            var allowed = ['type', 'away_seconds', 'attempt_id', 'tracking_generation', 'event_uid', 'source', 'absence_uid', 'related_event_uid', 'duration_ms', 'dropped_events'];
            return Object.keys(p).every(function (name) { return allowed.indexOf(name) !== -1; })
                && p.attempt_id === attempt && p.tracking_generation === generation
                && /^[a-zA-Z0-9_-]{16,80}$/.test(p.event_uid)
                && ['away_start', 'hidden', 'blur', 'fullscreen_exit', 'reload', 'leave', 'devtools', 'copy', 'paste', 'print', 'tracking_diagnostic'].indexOf(p.type) !== -1
                && JSON.stringify(p).length <= 1024;
        }
        function prune() {
            var cutoff = Date.now() - TTL;
            queue = queue.filter(function (item) {
                if (item.time >= cutoff && valid(item)) { return true; }
                lose(1); return false;
            });
        }
        function payload(type, source, extra) {
            return Object.assign({ type: type, away_seconds: 0, attempt_id: attempt, tracking_generation: generation, event_uid: uid(), source: source }, extra || {});
        }
        function append(p) {
            if (!generation || detached) { return; }
            prune();
            if (queue.length >= MAX) { lose(1); persist(); return; }
            queue.push({ payload: p, time: Date.now() });
            persist(); drain();
        }
        function diagnostic() {
            if (dropped && queue.length < MAX && generation && !detached) {
                var count = dropped;
                dropped = 0;
                queue.push({ payload: payload('tracking_diagnostic', 'queue', { dropped_events: count }), time: Date.now() });
            }
        }
        function drain() {
            if (busy || detached || !generation) { return; }
            prune(); diagnostic(); persist();
            if (!queue.length) { status(); return; }
            busy = true;
            var item = queue[0], sentGeneration = generation;
            var aborter = new AbortController();
            var timeout = setTimeout(function () { aborter.abort(); }, 12000);
            fetch(options.endpoint, {
                method: 'POST', credentials: 'same-origin', keepalive: true,
                signal: aborter.signal, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(item.payload)
            }).then(function (r) {
                return r.json().then(function (body) { return { ok: r.ok, status: r.status, body: body }; });
            }).then(function (result) {
                if (detached || generation !== sentGeneration || queue[0] !== item) { return; }
                if (result.status === 409) {
                    if (options.onConflict) { options.onConflict(); }
                    return;
                }
                if (result.ok && result.body.event_uid === item.payload.event_uid && result.body.attempt_id === attempt) {
                    queue.shift();
                    if (options.onAck) { options.onAck(result.body, item.payload); }
                } else if (result.status === 400 || result.status === 413) {
                    if (item.payload.type === 'tracking_diagnostic') { return; }
                    queue.shift(); lose(1);
                } else { return; } // no ack, network/5xx: retain the original UID
                diagnostic(); persist();
            }).catch(function () { status(); }).finally(function () {
                clearTimeout(timeout);
                busy = false;
                // Continue only after removal. A failed request waits for the bounded retry tick.
                if (!detached && (generation !== sentGeneration || queue[0] !== item)) { drain(); }
            });
        }
        function detach() { detached = true; sources = {}; episode = null; status(); if (options.onDetached) { options.onDetached(); } }
        function setGeneration(value) {
            if (generation === value || !/^[a-f0-9]{32}$/.test(value || '')) { return; }
            if (generation) {
                lose(queue.length); queue = [];
                try { sessionStorage.removeItem(key); } catch (e) { lose(1); }
            }
            sources = {}; episode = null;
            generation = value; key = PREFIX + generation;
            try {
                // Old launch queues must never become observations of the new launch.
                for (var i = sessionStorage.length - 1; i >= 0; i--) {
                    var oldKey = sessionStorage.key(i);
                    if (oldKey.indexOf(PREFIX) === 0 && oldKey !== key) {
                        var old = JSON.parse(sessionStorage.getItem(oldKey) || '{}');
                        lose(Array.isArray(old.queue) ? Math.min(MAX, old.queue.length) : 1);
                        sessionStorage.removeItem(oldKey);
                    }
                }
                var storedRaw = sessionStorage.getItem(key) || '{}';
                if (storedRaw.length > 131072) { throw new Error('oversized_queue'); }
                var stored = JSON.parse(storedRaw);
                if (Array.isArray(stored.queue)) {
                    queue = stored.queue.slice(0, MAX);
                    lose(Math.max(0, stored.queue.length - MAX));
                }
                if (Number.isInteger(stored.dropped) && stored.dropped > 0) { lose(Math.min(100000, stored.dropped)); }
            } catch (e) { lose(1); }
            prune(); diagnostic(); persist(); drain();
        }
        function start(source) {
            if (detached || !generation || sources[source]) { return; }
            if (['hidden', 'blur', 'fullscreen_exit'].indexOf(source) === -1) { return; }
            if (!episode) { episode = uid(); }
            var p = payload('away_start', source, { absence_uid: episode });
            sources[source] = { tick: now(), uid: p.event_uid, episode: episode };
            append(p);
        }
        function end(source) {
            var started = sources[source];
            if (!started) { return; }
            var duration = Math.min(3600000, Math.max(0, Math.floor(now() - started.tick)));
            delete sources[source];
            append(payload(source, source, { absence_uid: started.episode, related_event_uid: started.uid, duration_ms: duration, away_seconds: Math.floor(duration / 1000) }));
            // A real page/window return closes that episode even if fullscreen
            // stays exited. Its delayed return retains the UID fixed at departure.
            if (!sources.hidden && !sources.blur) { episode = null; }
        }
        function discard(source) {
            if (source) { delete sources[source]; } else { sources = {}; }
            if (!sources.hidden && !sources.blur) { episode = null; }
        }
        function observe(type, source) { append(payload(type, source)); }
        function beacon() {
            if (detached || !navigator.sendBeacon) { return; }
            queue.slice(0, 10).forEach(function (item) {
                try { navigator.sendBeacon(options.endpoint, new Blob([JSON.stringify(item.payload)], { type: 'application/json' })); } catch (e) {}
            }); // persisted entries stay until a later acknowledged fetch
        }
        setInterval(drain, 3000);
        window.addEventListener('online', drain);
        return { start: start, end: end, discard: discard, observe: observe, beacon: beacon, setGeneration: setGeneration, detach: detach, explicitPayload: payload, retry: drain };
    };
})();
