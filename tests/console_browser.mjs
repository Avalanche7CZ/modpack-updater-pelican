// Optional real-xterm rendering smoke test. See docs/console-integration.md for dependencies.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { pathToFileURL } from 'node:url';

const { chromium } = await import(pathToFileURL(process.env.PLAYWRIGHT_MODULE));
const native = fs.readFileSync(`${process.env.PELICAN_SOURCE}/resources/views/filament/components/server-console.blade.php`, 'utf8');
const nativeHandler = native.slice(native.indexOf('        const handleConsoleOutput'), native.indexOf('        const handleTransferStatus'));
const patch = fs.readFileSync(new URL('../patches/pelican/rc2-console-append.patch', import.meta.url), 'utf8');
const additions = patch.split('\n').filter(line => line.startsWith('+') && !line.startsWith('+++')).map(line => line.slice(1)).join('\n');
const hook = additions.slice(0, additions.indexOf('            if (window.PelicanConsoleAppend')).replaceAll('@js($this->server->uuid)', JSON.stringify('server-a'));
const client = fs.readFileSync(new URL('../resources/js/console-output.js', import.meta.url), 'utf8');
const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH });
try {
    const page = await browser.newPage();
    page.on('pageerror', error => { throw error; });
    const rows = [{ id: 1, text: '[Modpack] Waiting for the locked Pelican backup' }];
    await page.route('https://panel.fixture/**', route => {
        if (route.request().url().includes('/feed')) {
            const after = Number(new URL(route.request().url()).searchParams.get('after') ?? -1);
            return route.fulfill({ json: { lines: rows.filter(row => row.id > after), cursor: rows.at(-1)?.id ?? 0 } });
        }
        return route.fulfill({ contentType: 'text/html', body: '<div id="terminal"></div>' });
    });
    await page.goto('https://panel.fixture/');
    await page.addScriptTag({ path: process.env.XTERM_SCRIPT });
    await page.addScriptTag({ content: `
        const terminal = new Terminal({ rows: 30, cols: 160, allowProposedApi: true });
        terminal.open(document.getElementById('terminal'));
        let tornDown = false;
        const TERMINAL_PRELUDE = 'panel@fixture ~ ';
        ${nativeHandler}
        window.fixtureGameOutput = handleConsoleOutput;
        window.fixtureBuffer = () => Array.from({ length: terminal.buffer.active.length }, (_, i) => terminal.buffer.active.getLine(i).translateToString(true)).join('\\n');
        (() => { ${hook} })();
        (() => { const serverUuid = 'server-a'; const endpoint = 'https://panel.fixture/feed'; ${client} })();
    ` });
    await page.waitForFunction(() => window.fixtureBuffer().includes('locked Pelican backup'));
    await page.evaluate(() => window.fixtureGameOutput('[Server thread/INFO]: Minecraft game output\n'));
    rows.push({ id: 2, text: '[Modpack] Starting Minecraft · waiting for verified startup' });
    rows.push({ id: 3, text: '[Modpack] Update completed · Minecraft startup verified' });
    await page.waitForFunction(() => window.fixtureBuffer().includes('Minecraft startup verified'));
    rows.push({ id: 4, text: '[Modpack] Update failed · restoring and verifying the pre-update backup' });
    rows.push({ id: 5, text: '[Modpack] Restore completed · waiting for verified original Minecraft startup' });
    rows.push({ id: 6, text: '[Modpack] Rollback verified · original Minecraft startup verified' });
    await page.waitForFunction(() => window.fixtureBuffer().includes('Rollback verified'));
    await page.evaluate(() => window.fixtureGameOutput('[Server thread/INFO]: Original Minecraft game output\n'));
    await page.waitForFunction(() => window.fixtureBuffer().includes('Original Minecraft game output'));
    const output = await page.evaluate(() => window.fixtureBuffer());
    assert.ok(output.includes('[Server thread/INFO]: Minecraft game output'));
    assert.equal(output.split('locked Pelican backup').length - 1, 1, 'Polling duplicated xterm output');
    assert.ok(output.indexOf('Restore completed') < output.indexOf('Rollback verified'));
    await page.screenshot({ path: '/tmp/arcturus-console-xterm.png' });
    console.log('Chromium + real xterm 6: offline backup feed, native game output, verified success and rollback interleaving passed');
} finally { await browser.close(); }
