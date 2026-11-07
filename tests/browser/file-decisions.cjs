// Real Chromium -> actual Blade/Alpine/Livewire -> Laravel HTTP -> database queue.
const {chromium} = require(process.env.ARCTURUS_PLAYWRIGHT_MODULE || 'playwright');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const directory = process.env.ARCTURUS_BROWSER_FIXTURE;
const origin = process.env.ARCTURUS_BROWSER_URL || 'http://127.0.0.1:8765';
if (!['127.0.0.1','localhost'].includes(new URL(origin).hostname) || new URL(origin).protocol !== 'http:') throw Error('Loopback HTTP origin required');
if (!directory || !directory.startsWith('/tmp/')) throw Error('Disposable fixture directory required');
const worker = (...args) => execFileSync(process.env.ARCTURUS_PHP || 'php', ['-d','memory_limit=512M',path.join(__dirname,'worker.php'),...args], {cwd:root,env:process.env,stdio:'pipe'}).toString();
const state = () => JSON.parse(execFileSync('python3', ['-c', `import sqlite3,json; c=sqlite3.connect(${JSON.stringify(directory+'/fixture.sqlite')}); r=c.execute('select policy_revision,active_operation_id,approved_plan_sha256,deploy_enabled,file_rules from arcturus_modpack_servers where id=1').fetchone(); print(json.dumps(r))`]).toString());
(async()=>{
 const browser = await chromium.launch({headless:true,...(process.env.ARCTURUS_CHROME_BIN ? {executablePath:process.env.ARCTURUS_CHROME_BIN}: {})});
 const page = await browser.newPage({viewport:{width:1440,height:1000}});
 const errors=[], responses=[], decisions=[];
 page.on('pageerror',e=>errors.push(e.message));
 page.on('console',m=>{if(m.type()==='error')errors.push(m.text())});
 page.on('dialog',d=>d.accept());
 page.on('response',async response=>{
  if(!response.url().includes('/update'))return;
  try {
  const payload=response.request().postDataJSON();
  const calls=payload.components?.flatMap(c=>c.calls||[])||[];
  const result={status:response.status(),calls:calls.map(c=>c.method)};
  responses.push(result);
  if(calls.some(c=>c.method==='resolve')) {
   const json=await response.json();
   decisions.push({payload,url:response.url(),json,status:response.status()});
   fs.writeFileSync(directory+'/decision-responses.json',JSON.stringify(decisions,null,2));
  }
  } catch(e) { if(!page.isClosed()) errors.push(e.message); }
 });
 await page.route('**/*/update',async route=>{
  if(route.request().postDataJSON()?.components?.some(c=>c.calls?.some(c=>c.method==='resolve'))) await new Promise(r=>setTimeout(r,350));
  await route.continue();
 });
 const openChanges = async () => {
  const response = page.waitForResponse(r=>r.url().includes('/update') && r.request().postDataJSON()?.components?.some(c=>c.calls?.some(c=>c.method==='selectTab')));
  await page.getByRole('button',{name:'File Changes',exact:true}).click();
  await (await response).finished();
  const revision=state()[0];
  await page.waitForFunction(rev=>Array.from(document.querySelectorAll('button')).some(b=>(b.getAttribute('wire:click')||'').startsWith('resolve(') && b.getAttribute('wire:click').includes(', '+rev+', ')),revision);
 };
 try {
  await page.goto(origin+'/fixture');
  await openChanges();
  await page.getByRole('button',{name:'Keep local',exact:true}).first().waitFor();
  assert.equal(await page.locator('[wire\\:click*="@js"]').count(),0,'Uncompiled Blade in browser expression');
  const originalRoot = await page.locator('[wire\\:id]').first().getAttribute('wire:id');
  // Pending comparison blocks a second decision. Worker consumes the serialized native job off HTTP.
  for(const [index,choice] of ['Keep local','Use upstream','Keep local','Use upstream'].entries()) {
   const before=state(); const button=page.getByRole('button',{name:choice,exact:true}).first();
   const responsePromise=page.waitForResponse(r=>r.url().includes('/update') && r.request().postDataJSON()?.components?.some(c=>c.calls?.some(c=>c.method==='resolve')));
   await button.click();
   await page.getByText('Saving decision…',{exact:true}).waitFor({state:'visible'});
   const response=await responsePromise; const json=await response.json();
   assert.equal(response.status(),200);
   const parent=json.components.find(c=>c.effects?.dispatches?.some(d=>d.name==='file-decision-result'));
   assert(parent,'Decision feedback event missing'); assert(!parent.effects.html,'Decision synchronously rerendered the full page');
   await page.getByRole('status').filter({hasText:'saved for'}).first().waitFor({state:'visible'});
   assert.equal(state()[0],before[0]+1); assert(state()[1]); assert.equal(state()[2],null); assert.equal(state()[3],0);
   assert(await page.getByRole('button',{name:'Keep local',exact:true}).first().isDisabled(),'Repeated decision remained enabled during comparison');
   // Replay the exact browser HTTP payload, as after a lost response. No duplicate policy/job.
   const payload=response.request().postDataJSON();
   const replay=await page.evaluate(async({url,payload})=>(await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-Livewire':''},body:JSON.stringify(payload)})).status,{url:response.url(),payload});
   assert.equal(replay,200); assert.equal(state()[0],before[0]+1);
   console.log(worker().trim());
   if(index===0) {
    // Leave the actual 10s timer running: only changed terminal evidence should cause a render.
    await page.getByRole('button',{name:'Keep local',exact:true}).first().waitFor({state:'visible'});
    await page.waitForFunction(()=>!document.querySelector('fieldset[disabled]'),{},{timeout:20000});
   } else { await openChanges(); }
   await page.waitForFunction(()=>!document.querySelector('fieldset[disabled]'),{},{timeout:20000});
   assert.equal(await page.locator('[wire\\:id]').first().getAttribute('wire:id'),originalRoot,'Component identity lost after decision');
  }
  // A real HTTP stale-view race must be visible and leave rules unchanged.
  worker('stale'); const stale=state();
  await page.getByRole('button',{name:'Keep local',exact:true}).first().click();
  await page.getByRole('status').filter({hasText:'Policy changed or preparation is active'}).first().waitFor();
  assert.deepEqual(state(),stale);
  await openChanges();
  fs.writeFileSync(directory+'/queue-off','1'); const before=state();
  await page.getByRole('button',{name:'Keep local',exact:true}).first().click();
  await page.getByRole('status').filter({hasText:'asynchronous database or Redis queue'}).first().waitFor();
  assert.deepEqual(state(),before,'Queue rejection partially committed policy');
  fs.unlinkSync(directory+'/queue-off');
  // Last-page decision validates one indexed evidence row, not repeated PHP scans of all 22k changes.
  await page.getByRole('textbox',{name:'Filter file paths'}).fill('recipe-21999.js');
  await page.getByText('kubejs/server_scripts/recipe-21999.js',{exact:true}).waitFor();
  await page.getByRole('button',{name:'Use upstream',exact:true}).first().click();
  await page.getByRole('status').filter({hasText:'saved for'}).first().waitFor(); console.log(worker().trim());
  assert.equal(errors.length,0,errors.join('\n'));
  assert(responses.every(r=>r.status===200),'Non-200 Livewire response');
  await page.screenshot({path:directory+'/file-decisions.png',fullPage:true});
  fs.writeFileSync(directory+'/browser-evidence.json',JSON.stringify({errors,responses,decisions:decisions.length,state:state()},null,2));
  console.log('Real browser decisions, confirmation, loading, renderless feedback, 10s polling, HTTP replay, stale/queue errors and 22,000-file indexed selection passed');
 } finally { if(fs.existsSync(directory+'/queue-off')) fs.unlinkSync(directory+'/queue-off'); await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1});
