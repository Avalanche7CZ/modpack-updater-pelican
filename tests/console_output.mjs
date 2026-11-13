import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const client = fs.readFileSync(new URL('../resources/js/console-output.js', import.meta.url), 'utf8');
const patch = fs.readFileSync(new URL('../patches/pelican/rc2-console-append.patch', import.meta.url), 'utf8');
const additions = patch.split('\n').filter(line => line.startsWith('+') && !line.startsWith('+++')).map(line => line.slice(1)).join('\n');
const hookSource = additions.slice(0, additions.indexOf('            if (window.PelicanConsoleAppend'))
    .replaceAll('@js($this->server->uuid)', JSON.stringify('server-a'));
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
function environment() {
    const window = new EventTarget();
    const document = new EventTarget();
    window.location = { origin: 'https://panel.test' };
    const lines = [], requests = [], timers = new Map();
    let timerId = 0;
    let response = { lines: [], cursor: 0 };
    let failed = false;
    const context = vm.createContext({ window, document, URL, AbortController, Map, Object, Number,
        CustomEvent: class extends Event { constructor(name, options) { super(name); this.detail = options.detail; } },
        terminal: { writeln: line => lines.push(line) },
        fetch: async (url, options) => {
            requests.push({ url: String(url), options });
            if (failed) throw new Error('private exception');
            return { ok: true, status: 200, json: async () => response };
        },
        setTimeout: fn => { timers.set(++timerId, fn); return timerId; },
        clearTimeout: id => timers.delete(id),
    });
    vm.runInContext('let tornDown = false;', context);
    const hook = () => vm.runInContext(`(() => { ${hookSource} })()`, context);
    const load = () => vm.runInContext(`(() => { const serverUuid = 'server-a'; const endpoint = 'https://panel.test/feed'; ${client} })()`, context);
    const tick = async () => { const item = timers.entries().next().value; assert.ok(item); timers.delete(item[0]); item[1](); await flush(); };
    return { window, document, context, lines, requests, timers, hook, load, tick,
        reply: value => { response = value; }, fail: value => { failed = value; } };
}
const e = environment();
e.load(); await flush();
assert.equal(e.requests.length, 1, 'Unpatched Panel must poll the authorized fallback feed');
e.reply({ lines: [{ id: 1, text: '[Modpack] Waiting for the locked Pelican backup' }], cursor: 1 });
e.hook(); await flush();
assert.equal(e.lines.length, 1, 'Offline Minecraft must not block the Panel feed');
assert.ok(e.lines[0].includes('locked Pelican backup'));
assert.deepEqual(Object.keys(e.window.PelicanConsoleAppend).sort(), ['append', 'serverUuid', 'version']);
assert.equal(e.window.PelicanConsoleAppend.append({ serverUuid: 'server-b', source: 'arcturus-modpack', id: 9, text: 'wrong server' }), false);
assert.equal(e.lines.length, 1);
await e.tick();
assert.equal(e.lines.length, 1, 'Repeated history must not duplicate');
assert.ok(e.requests.at(-1).url.includes('after=1'));
e.fail(true); await e.tick(); e.fail(false);
e.reply({ lines: [{ id: 1, text: 'duplicate' }, { id: 2, text: '[Modpack] Applying and verifying 112 / 340 files' }], cursor: 2 });
await e.tick();
assert.equal(e.lines.length, 2, 'Reconnect must resume after the last accepted ID');
// Use RC2's actual native output handler when its source is supplied.
if (process.env.PELICAN_SOURCE) {
    const native = fs.readFileSync(`${process.env.PELICAN_SOURCE}/resources/views/filament/components/server-console.blade.php`, 'utf8');
    const handler = native.slice(native.indexOf('        const handleConsoleOutput'), native.indexOf('        const handleTransferStatus'));
    vm.runInContext(`const TERMINAL_PRELUDE = 'panel@server ~ '; ${handler}`, e.context);
    vm.runInContext("handleConsoleOutput('[Server thread/INFO]: Done (1s)!\\n')", e.context);
    assert.ok(e.lines.at(-1).includes('[Server thread/INFO]: Done (1s)!'), 'Normal native game output broke');
}
e.window.PelicanConsoleAppend.append({ serverUuid: 'server-a', source: 'security-test', id: 1, text: 'plain\x1b[2J\r\n\u009b\u202eend' });
assert.ok(!/[\x00-\x1f\x7f-\x9f\u202a-\u202e]/u.test(e.lines.at(-1).replace(/\x1b\[0m$/, '')));
const oldRequest = e.requests.at(-1);
e.document.dispatchEvent(new Event('livewire:navigating'));
assert.equal(e.timers.size, 0, 'Navigation retained a polling timer');
assert.equal(oldRequest.options.signal.aborted, true, 'Navigation retained a fetch');
const requestCount = e.requests.length;
e.hook(); await flush();
assert.equal(e.requests.length, requestCount, 'Old server listener survived navigation');
const fresh = environment(); fresh.hook();
fresh.reply({ lines: [{ id: 1, text: 'recent backup phase' }, { id: 2, text: 'recent applying phase' }], cursor: 2 });
fresh.load(); await flush();
assert.equal(fresh.lines.length, 2, 'New terminal did not replay recent history after refresh');
assert.ok(!fresh.requests[0].url.includes('after='), 'A blank terminal incorrectly inherited the previous terminal cursor');
vm.runInContext('tornDown = true;', fresh.context);
assert.equal(fresh.window.PelicanConsoleAppend.append({ serverUuid: 'server-a', source: 'test', id: 99, text: 'stale' }), false);
console.log('Console hook/client: optional fallback, offline output, isolation, reconnect, refresh, sanitization and navigation cleanup passed');
