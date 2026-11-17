// Only our plugin-owned fallback is updated; the native terminal remains private.
const fallback = document.getElementById?.('arcturus-modpack-output-' + serverUuid);
const widget = fallback?.closest('[data-modpack-console-widget]') ?? fallback;
const status = fallback?.querySelector('[data-status]');
const list = fallback?.querySelector('[data-lines]');
const history = fallback?.querySelector('[data-history]');
const recent = [];
const showWidget = (visible) => {
    if (!widget) return;
    widget.hidden = !visible;
    widget.style.display = visible ? '' : 'none';
};
const reset = () => {
    recent.length = 0;
    list?.replaceChildren();
    if (history) { history.hidden = true; history.open = false; }
    if (status) status.textContent = '';
};
const showLine = (line) => {
    if (!list) return;
    recent.push(line); if (recent.length > 8) recent.shift();
    if (status) status.textContent = line.replace(/^\[Modpack\] /, '');
    list.replaceChildren(...recent.slice(0, -1).reverse().map(value => {
        const li = document.createElement('li'); li.textContent = value.replace(/^\[Modpack\] /, ''); return li;
    }));
    if (history) history.hidden = recent.length < 2;
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
    showWidget(false);
    reset();
    const subscription = { hook, operation: null, cursor: null, timer: null, controller: null };
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
            if ([401, 403, 404].includes(response.status)) { showWidget(false); reset(); stop(); return; }
            if (!response.ok) throw new Error('Console feed unavailable');
            const data = await response.json();
            if (active !== subscription) return;
            if (!data.operation_id) {
                subscription.operation = null;
                subscription.cursor = null;
                showWidget(false);
                reset();
                delay = 10000;
                return;
            }
            if (subscription.operation !== data.operation_id) {
                const restartCursor = subscription.cursor !== null;
                subscription.operation = data.operation_id;
                subscription.cursor = null;
                reset();
                if (restartCursor) {
                    showWidget(false);
                    delay = 100;
                    return;
                }
            }
            for (const line of data.lines) {
                if (!Number.isSafeInteger(line.id) || line.id <= (subscription.cursor ?? -1)) continue;
                if (hook) {
                    if (!hook.append({ serverUuid, source: 'arcturus-modpack', id: line.id, text: line.text })) { attach(null); return; }
                } else { showLine(line.text); }
                subscription.cursor = line.id;
            }
            if (!hook && status) {
                status.textContent = recent.at(-1)?.replace(/^\[Modpack\] /, '') ?? 'Waiting for the first progress report…';
            }
            showWidget(!hook);
            if (data.lines.length === 100) delay = 100;
        } catch (_) {
            if (active === subscription && subscription.operation && status) status.textContent = 'Connection interrupted · retrying update status';
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
