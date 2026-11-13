// Loaded only on Console. The optional Panel hook owns the existing terminal.
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
    if (stopped || hook?.version !== 1 || hook.serverUuid !== serverUuid || typeof hook.append !== 'function' || active?.hook === hook) return;
    stop();
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
            if ([401, 403, 404].includes(response.status)) { stop(); return; }
            if (!response.ok) throw new Error('Console feed unavailable');
            const data = await response.json();
            if (active !== subscription) return;
            for (const line of data.lines) {
                if (!Number.isSafeInteger(line.id) || line.id <= (subscription.cursor ?? -1)) continue;
                if (!hook.append({ serverUuid, source: 'arcturus-modpack', id: line.id, text: line.text })) return;
                subscription.cursor = line.id;
            }
            if (data.lines.length === 100) delay = 100;
        } catch (_) {
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
