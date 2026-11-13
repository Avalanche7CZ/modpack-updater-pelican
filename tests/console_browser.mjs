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
    for (const hasHook of [false, true]) {
    const page = await browser.newPage();
    page.on('pageerror', error => { throw error; });
    const rows = [{ id: 1, text: '[Modpack] Waiting for the locked Pelican backup' }];
    await page.route('https://panel.fixture/**', route => {
        if (route.request().url().includes('/feed')) {
            const after = Number(new URL(route.request().url()).searchParams.get('after') ?? -1);
            return route.fulfill({ json: { lines: rows.filter(row => row.id > after), cursor: rows.at(-1)?.id ?? 0 } });
        }
        return route.fulfill({ contentType: 'text/html', body: '<section id="arcturus-modpack-output-server-a"><strong>Modpack update</strong><p data-status></p><ol data-lines></ol></section><div id="terminal"></div>' });
    });
    const initialize = async () => {
        await page.addScriptTag({ path: process.env.XTERM_SCRIPT });
        await page.addScriptTag({ content: `
            const terminal = new Terminal({ rows: 30, cols: 160, allowProposedApi: true });
            terminal.open(document.getElementById('terminal'));
            let tornDown = false;
            const TERMINAL_PRELUDE = 'panel@fixture ~ ';
            ${nativeHandler}
            window.fixtureGameOutput = handleConsoleOutput;
            window.fixtureBuffer = () => Array.from({ length: terminal.buffer.active.length }, (_, i) => terminal.buffer.active.getLine(i).translateToString(true)).join('\\n');
            window.fixtureOutput = () => ${hasHook} ? window.fixtureBuffer() : document.querySelector('[data-lines]').textContent;
            ${hasHook ? `(() => { ${hook} })();` : ''}
            (() => { const serverUuid = 'server-a'; const endpoint = 'https://panel.fixture/feed'; ${client} })();
        ` });
    };
    await page.goto('https://panel.fixture/'); await initialize();
    await page.waitForFunction(() => window.fixtureOutput().includes('locked Pelican backup'));
    assert.equal(await page.locator('#arcturus-modpack-output-server-a').evaluate(el => el.hidden), hasHook);
    await page.evaluate(() => window.fixtureGameOutput('[Server thread/INFO]: Minecraft game output\n'));
    rows.push({ id: 2, text: '[Modpack] Uploading and verifying Wings staging files · 112 / 340' });
    rows.push({ id: 3, text: '[Modpack] Update completed · Minecraft startup verified' });
    await page.waitForFunction(() => window.fixtureOutput().includes('Minecraft startup verified'));
    rows.push({ id: 4, text: '[Modpack] Update failed · restoring and verifying the pre-update backup' });
    rows.push({ id: 5, text: '[Modpack] Restore completed · waiting for verified original Minecraft startup' });
    rows.push({ id: 6, text: '[Modpack] Rollback verified · original Minecraft startup verified' });
    await page.waitForFunction(() => window.fixtureOutput().includes('Rollback verified'));
    assert.ok((await page.evaluate(() => window.fixtureBuffer())).includes('Minecraft game output'));
    let output = await page.evaluate(() => window.fixtureOutput());
    assert.equal(output.split('locked Pelican backup').length - 1, 1);
    assert.ok(output.indexOf('Restore completed') < output.indexOf('Rollback verified'));
    await page.reload(); await initialize();
    await page.waitForFunction(() => window.fixtureOutput().includes('Rollback verified'));
    output = await page.evaluate(() => window.fixtureOutput());
    assert.equal(output.split('Rollback verified').length - 1, 1, 'Refresh duplicated history');
    if (!hasHook) {
        await page.addScriptTag({ content: `(() => { ${hook} })();` });
        await page.waitForFunction(() => window.fixtureBuffer().includes('Rollback verified'));
        assert.equal(await page.locator('#arcturus-modpack-output-server-a').evaluate(el => el.hidden), true);
    }
    await page.screenshot({ path: '/tmp/arcturus-console-' + (hasHook ? 'xterm' : 'fallback') + '.png' });
    await page.close();
    }
    console.log('Chromium + xterm/fallback: offline feed, counts, native output, rollback, refresh and late hook passed');
} finally { await browser.close(); }
