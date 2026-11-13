// Only our plugin-owned fallback is updated; the native terminal remains private.
const fallback = document.getElementById?.('arcturus-modpack-output-' + serverUuid);
const status = fallback?.querySelector('[data-status]');
const list = fallback?.querySelector('[data-lines]');
const recent = [];
const showLine = (line) => {
    if (!list) return;
    recent.push(line); if (recent.length > 8) recent.shift();
    list.replaceChildren(...recent.map(value => { const li = document.createElement('li'); li.textContent = value; return li; }));
};
let active = null;
let stopped = false;
const stop = () => {
    if (active) {
        clearTimeout(active.timer);
        active.controller?.abort();
        active = null;
    }
};
const attach = (hook) => {
    const valid = hook?.version === 1 && hook.serverUuid === serverUuid && typeof hook.append === 'function';
    if (hook && !valid) return;
    hook = valid ? hook : null;
    if (stopped || (active && active.hook === hook)) return;
    stop();
    if (fallback) fallback.hidden = !!hook;
    if (!hook) { recent.length = 0; list?.replaceChildren(); }
    const subscription = { hook, cursor: null, timer: null, controller: null };
    active = subscription;
    const poll = async () => {
        if (active !== subscription) return;
        subscription.controller = new AbortController();
        let delay = 3000;
        try {
            const url = new URL(endpoint, window.location.origin);
            if (subscription.cursor !== null) url.searchParams.set('after', subscription.cursor);
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, signal: subscription.controller.signal });
            if ([401, 403, 404].includes(response.status)) { if (status) status.textContent = 'Update history unavailable for this account.'; stop(); return; }
            if (!response.ok) throw new Error('Console feed unavailable');
            const data = await response.json();
            if (active !== subscription) return;
            if (status) status.textContent = data.lines.length || recent.length ? 'Recorded update progress' : 'No recorded update operation.';
            for (const line of data.lines) {
                if (!Number.isSafeInteger(line.id) || line.id <= (subscription.cursor ?? -1)) continue;
                if (hook) {
                    if (!hook.append({ serverUuid, source: 'arcturus-modpack', id: line.id, text: line.text })) { attach(null); return; }
                } else { showLine(line.text); }
                subscription.cursor = line.id;
            }
            if (data.lines.length === 100) delay = 100;
        } catch (_) {
            if (status) status.textContent = 'Connection interrupted · retrying update history';
            // Preserve this terminal's cursor on network/reconnect failure. No raw errors enter xterm.
        } finally {
            if (active === subscription) subscription.timer = setTimeout(poll, delay);
        }
    };
    poll();
};
const ready = (event) => attach(event.detail);
window.addEventListener('pelican:console-ready', ready);
attach(window.PelicanConsoleAppend);
document.addEventListener('livewire:navigating', () => {
    stopped = true;
    stop();
    window.removeEventListener('pelican:console-ready', ready);
}, { once: true });
