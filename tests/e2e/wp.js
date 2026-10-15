const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://taci-wp.test', OUT = (process.env.OUT || '/tmp/tacilytics-wp-shots/');
fs.mkdirSync(OUT, { recursive: true });
const ok = []; const bad = []; const t = (name, cond, info) => (cond ? ok : bad).push(name + (cond ? '' : ' → ' + (info || '')));
(async () => {
  const b = await chromium.launch({ args: ['--host-resolver-rules=MAP tacilytics.test ' + (process.env.IP || '172.21.0.4') + ', MAP taci-wp.test ' + (process.env.WP || '127.0.0.1:8931') + ''] }); const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, locale: 'en-US' }); const p = await ctx.newPage();
  const errs = []; p.on('pageerror', (e) => errs.push(e.message)); p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  await p.goto(BASE + '/wp-login.php'); await p.fill('#user_login', 'admin'); await p.fill('#user_pass', 'taci-test-pw'); await p.click('#wp-submit'); await p.waitForURL(/wp-admin/);
  // notice on dashboard while not set up
  await p.goto(BASE + '/wp-admin/index.php');
  t('setup notice', await p.locator('text=Tacilytics is installed but not counting yet').count() === 1);
  t('widget says not set up', await p.locator('#tacilytics_dashboard >> text=not set up yet').count() === 1);
  await p.goto(BASE + '/wp-admin/options-general.php?page=tacilytics');
  t('two choices', await p.locator('.tacilytics-choice').count() === 2);
  await p.screenshot({ path: OUT + '1-choice.png', fullPage: true });
  // Cloud: the tile opens the Cloud setup (domain + link to cloud.tacilytics.com/signup); not saved here, self-hosted is tested below
  t('cloud tile is a link', (await p.locator('a.tacilytics-choice-cloud').count()) === 1);
  await p.click('a.tacilytics-choice-cloud'); await p.waitForLoadState();
  t('cloud setup shows sign-up link', (await p.locator('a[href="https://cloud.tacilytics.com/signup"]').count()) === 1 && (await p.locator('input[type=submit][value="Connect to Tacilytics Cloud"]').count()) === 1);
  await p.screenshot({ path: OUT + '2-cloud.png', fullPage: true });
  await p.goto(BASE + '/wp-admin/options-general.php?page=tacilytics&mode=self'); await p.waitForLoadState();
  // invalid URL first
  await p.fill('#tacilytics-server', 'ftp://evil.example.com/x?y=1'); await p.fill('#tacilytics-domain', 'taci-wp.test');
  await p.click('#submit'); await p.waitForLoadState();
  t('bad URL rejected', await p.locator('text=must start with https://').count() >= 1);
  await p.goto(BASE + '/wp-admin/options-general.php?page=tacilytics');
  t('bad URL keeps first-run choice', await p.locator('.tacilytics-choice').count() === 2);
  await p.goto(BASE + '/wp-admin/options-general.php?page=tacilytics&mode=self');
  await p.fill('#tacilytics-server', process.env.SRV + '/'); await p.fill('#tacilytics-domain', 'https://www.taci-wp.test/');
  await p.screenshot({ path: OUT + '3-self.png', fullPage: true });
  await p.click('#submit'); await p.waitForLoadState();
  t('connected status', await p.locator('.tacilytics-status >> text=taci-wp.test').count() === 1, await p.locator('.wrap').innerText());
  // key
  await p.fill('#tacilytics-api-key', fs.readFileSync('key', 'utf8').trim()); await p.click('#submit'); await p.waitForLoadState();
  const masked = await p.inputValue('#tacilytics-api-key');
  t('key masked', /^tk_.{4}•+$/.test(masked), masked);
  t('key not in page source', !(await p.content()).includes(fs.readFileSync('key', 'utf8').trim()));
  // save again with the masked value -> key kept
  await p.click('#submit'); await p.waitForLoadState();
  t('masked save keeps key', (await p.inputValue('#tacilytics-api-key')) === masked);
  await p.screenshot({ path: OUT + '4-settings.png', fullPage: true });
  // Kiro (c): changing the server drops the stored key
  const cur = await p.inputValue('#tacilytics-server');
  await p.fill('#tacilytics-server', 'https://other.example.net'); await p.click('#submit'); await p.waitForLoadState();
  t('server change drops key', (await p.inputValue('#tacilytics-api-key')) === '' && (await p.locator('text=the saved API key was removed').count()) === 1, await p.inputValue('#tacilytics-api-key'));
  await p.fill('#tacilytics-server', cur); await p.fill('#tacilytics-api-key', fs.readFileSync(process.env.KEY_FILE || 'key', 'utf8').trim()); await p.click('#submit'); await p.waitForLoadState();
  await p.click('input[value="Test connection"]'); await p.waitForLoadState();
  const notes = await p.locator('.notice').allInnerTexts();
  t('test: tracker ok', notes.some((n) => n.includes('Tracker script reachable')), notes.join(' | '));
  t('test: key ok', notes.some((n) => n.includes('API key works')), notes.join(' | '));
  await p.goto(BASE + '/wp-admin/index.php');
  t('widget kpis', await p.locator('#tacilytics_dashboard .tacilytics-kpi-value').count() >= 2, await p.locator('#tacilytics_dashboard').innerText().catch(() => ''));
  await p.locator('#tacilytics_dashboard').screenshot({ path: OUT + '5-widget.png' });
  t('no admin JS errors', errs.length === 0, errs.join(' | '));
  // front end as admin -> marker set, no events
  await p.goto(BASE + '/');
  const html = await p.content();
  const tag = await p.evaluate(() => { const e = document.querySelector('script[src$="/tacilytics.js"]'); return e ? { src: e.src, d: e.dataset.domain, out: e.hasAttribute('data-track-outbound'), dl: e.hasAttribute('data-track-downloads'), s: e.dataset.trackSearch, f: e.getAttribute('data-track-404'), defer: e.defer } : null; });
  const document_ok = true;
  t('tracker tag', !!tag && tag.src === 'http://tacilytics.test/tacilytics.js' && tag.d === 'taci-wp.test' && tag.out && tag.dl && tag.s === 's' && tag.f === 'body.error404' && tag.defer && document_ok, JSON.stringify(tag));
  t('admin marked as ignored', await p.evaluate(() => localStorage.getItem('tacilytics_ignore')) === '1');
  t('no front-end JS errors (admin)', errs.length === 0, errs.join(' | '));
  fs.writeFileSync('result.json', JSON.stringify({ ok, bad }, null, 1));
  console.log('OK ' + ok.length + ' / BAD ' + bad.length); bad.forEach((x) => console.log('  ✗ ' + x));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
