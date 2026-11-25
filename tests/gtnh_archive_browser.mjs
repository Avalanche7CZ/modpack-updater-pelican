import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';

const { chromium } = await import(pathToFileURL(process.env.PLAYWRIGHT_MODULE));
const panel = process.env.PELICAN_SOURCE;
assert.ok(panel, 'Set PELICAN_SOURCE to a disposable Pelican source checkout');
const root = await mkdtemp(join(tmpdir(), 'amm-gtnh-browser-'));
const socket = createServer();
await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
const address = '127.0.0.1:' + socket.address().port;
await new Promise(resolve => socket.close(resolve));
const origin = 'http://' + address;
const router = fileURLToPath(new URL('integration/fixtures/archive-browser.php', import.meta.url));
const server = spawn('php', ['-S', address, '-t', join(panel, 'public'), router], {
    env: { ...process.env, GTNH_BROWSER_FIXTURE: root },
});
let log = '';
server.stderr.on('data', chunk => { log += chunk; });
const closed = new Promise(resolve => server.once('close', resolve));
let browser;

async function fixture(path, method = 'GET') {
    const response = await fetch(origin + '/fixture/' + path, { method });
    if (!response.ok) throw new Error(path + ' ' + response.status + ' ' + (await response.text()).slice(0, 300));
    return response;
}

const evidence = async record => (await fixture('evidence/' + record)).json();
const work = async () => (await fixture('work', 'POST')).text();

async function pageFor(record) {
    const page = await browser.newPage();
    page.setDefaultTimeout(12000);
    await page.route('**/*', route => route.request().url().startsWith(origin + '/') ? route.continue() : route.abort());
    page.on('pageerror', error => { log += '\nBrowser: ' + error.stack; });
    page.on('requestfailed', request => {
        if (request.resourceType() === 'script') log += '\nScript request failed: ' + request.url();
    });
    page.on('response', response => {
        if (response.request().method() === 'POST') log += '\nBrowser POST ' + new URL(response.url()).pathname + ' ' + response.status();
    });
    const response = await page.goto(origin + '/fixture/view/' + record);
    assert.equal(response.status(), 200);
    try {
        await page.waitForFunction(() => window.Livewire?.all().length > 0, undefined, { timeout: 12000 });
    } catch (error) {
        console.error(await page.locator('script[src]').evaluateAll(scripts => scripts.map(script => script.src)));
        throw error;
    }
    return page;
}

const reviewDialog = page => page.locator('.fi-modal-window:visible');
const button = (scope, name) => scope.getByRole('button', { name, exact: true });

async function pending(record, page, target = false) {
    if (target) await button(page, 'Updates').click();
    await button(page, target ? 'Prepare update' : 'Prepare installed baseline').click();
    try {
        await button(reviewDialog(page), 'Confirm').click();
    } catch (error) {
        console.error(await page.locator('body').ariaSnapshot());
        throw error;
    }
    await reviewDialog(page).waitFor({ state: 'hidden' });
    assert.equal((await evidence(record)).jobs, 1, await page.locator('body').innerText());
    assert.match(await work(), /PrepareUpdate.*DONE/s);
    const state = await evidence(record);
    assert.equal(state.pin, 'pending');
    assert.equal(state.operations.length, 1);
    assert.equal(state.operations[0].phase, 'archive_review_required');
    assert.equal(state.downloads, 1);
    try {
        await page.getByText('Archive approval required', { exact: true }).waitFor();
    } catch (error) {
        console.error(await page.locator('body').ariaSnapshot());
        throw error;
    }
    return state;
}

async function choose(page, choice) {
    await button(page, choice).click();
    const dialog = reviewDialog(page);
    await dialog.getByText('SHA-256', { exact: true }).waitFor();
    assert.match(await dialog.innerText(), /GT_New_Horizons_2\.9\.\d_Server_Java_17-26\.zip/);
    assert.match(await dialog.innerText(), /[a-f0-9]{64}/);
    return button(dialog, choice);
}

try {
    for (let attempt = 0; ; attempt++) {
        try {
            await evidence(1);
            break;
        } catch (error) {
            if (error.cause?.code !== 'ECONNREFUSED' || attempt >= 40) throw error;
            await delay(100);
        }
    }
    browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH });
    const approvePage = await pageFor(1);
    const first = await pending(1, approvePage, true);
    const approve = await choose(approvePage, 'Approve archive');

    // Hold the network response so the real button's pending state is observable.
    let release;
    const hold = new Promise(resolve => { release = resolve; });
    let submissions = 0;
    const delayApproval = async route => {
        const calls = route.request().postDataJSON()?.components?.flatMap(component => component.calls) ?? [];
        if (calls.some(call => call.method === 'callMountedAction')) {
            submissions++;
            await hold;
        }
        await route.continue();
    };
    await approvePage.route('**/livewire*/**', delayApproval);
    const parentRefresh = approvePage.waitForRequest(request => {
        const calls = request.postDataJSON()?.components?.flatMap(component => component.calls) ?? [];
        return calls.some(call => call.method === '__dispatch' && call.params[0] === 'archive-decision-recorded');
    });
    void parentRefresh.catch(() => {});
    const position = await approve.boundingBox();
    await approve.click();
    try {
        await approvePage.waitForFunction(() => [...document.querySelectorAll('.fi-modal-footer button')]
            .some(button => button.offsetWidth && button.textContent.trim() === 'Approve archive' && button.disabled),
        undefined, { timeout: 5000 });
        await approvePage.mouse.click(position.x + position.width / 2, position.y + position.height / 2);
    } finally {
        release();
    }
    await reviewDialog(approvePage).waitFor({ state: 'hidden' });
    await parentRefresh;
    await approvePage.unroute('**/livewire*/**', delayApproval);
    assert.equal(submissions, 1);
    await approvePage.getByText('Archive approved. Preparation continues automatically.', { exact: true }).waitFor();
    await button(approvePage, 'Resume preparation').waitFor(); // Parent refreshed before its next scheduled poll.
    const queued = await evidence(1);
    assert.equal(queued.pin, 'admin_reviewed');
    assert.equal(queued.jobs, 1);
    assert.equal(queued.operations.length, 2);
    assert.notEqual(queued.operations.find(op => op.id === queued.active).node_operation_id, first.operations[0].node_operation_id);
    assert.match(await work(), /PrepareUpdate.*DONE/s);
    const complete = await evidence(1);
    assert.equal(complete.operations.filter(op => op.phase === 'completed').length, 1, JSON.stringify(complete));
    assert.equal(complete.prepared.version, '2.9.2');
    assert.equal(complete.prepared.level, 'admin_reviewed');
    assert.equal(complete.prepared.sha256, first.sha256);
    assert.equal(complete.prepared.size, first.size);
    assert.equal(complete.downloads, 1, 'Approved continuation downloaded the archive again');
    assert.equal(complete.jobs, 0);
    try {
        await approvePage.locator('[role="status"]').getByText('Completed', { exact: true }).waitFor();
        await button(approvePage, 'Resume preparation').waitFor({ state: 'hidden' });
    } catch (error) {
        console.error(await approvePage.locator('body').ariaSnapshot());
        throw error;
    }

    const rejectPage = await pageFor(2);
    await pending(2, rejectPage);
    const stalePage = await pageFor(2);
    const staleApproval = await choose(stalePage, 'Approve archive');
    await (await choose(rejectPage, 'Reject archive')).click();
    await rejectPage.locator('[role="status"]').getByText('Archive rejected', { exact: true }).waitFor();
    await staleApproval.click();
    await stalePage.getByRole('alert').filter({ hasText: 'The exact measurement is no longer pending' }).waitFor();
    const rejected = await evidence(2);
    assert.equal(rejected.pin, 'rejected');
    assert.equal(rejected.operations.length, 1);
    assert.equal(rejected.downloads, 1);
    assert.equal(rejected.jobs, 0);

    const variantPage = await pageFor(3);
    await pending(3, variantPage);
    const staleVariant = await choose(variantPage, 'Approve archive');
    await fixture('stale/3', 'POST');
    await staleVariant.click();
    await variantPage.getByRole('alert').filter({ hasText: 'The selected archive has changed' }).waitFor();
    const stale = await evidence(3);
    assert.equal(stale.pin, 'pending');
    assert.equal(stale.operations.length, 1);
    assert.equal(stale.jobs, 0);
    assert.doesNotMatch(log, /Browser:/);
    console.log('Chromium Strict GTNH archive: approve and continue with one download, reject, and stale decisions passed');
} catch (error) {
    console.error(log);
    throw error;
} finally {
    await browser?.close();
    server.kill();
    await closed;
    await rm(root, { recursive: true, force: true });
}
