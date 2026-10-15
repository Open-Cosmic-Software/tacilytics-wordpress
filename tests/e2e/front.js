const { chromium } = require('playwright');
const fs = require('fs');
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const IP = process.env.IP, BASE = 'http://taci-wp.test';
const ok = [], bad = []; const t = (n, c, i) => (c ? ok : bad).push(n + (c ? '' : ' → ' + (i || '')));
(async () => {
  const b = await chromium.launch({ args: ['--host-resolver-rules=MAP tacilytics.test ' + IP + ', MAP taci-wp.test 127.0.0.1:8931'] });
  const { execFileSync } = require('child_process');
  let since = 0; const hitDb = () => JSON.parse(execFileSync('sqlite3', ['-json', '/tmp/taci-wptest/app/t.db', `select name n, path p, props pr from events where id > ${since} order by id`], { encoding: 'utf8' }) || '[]').map((x) => ({ ...x, pr: x.pr ? JSON.parse(x.pr) : null }));
  const mark = () => { since = Number(execFileSync('sqlite3', ['/tmp/taci-wptest/app/t.db', 'select coalesce(max(id),0) from events'], { encoding: 'utf8' }).trim()); };
  const hit = { get length() { return hitDb().length; }, set length(v) { mark(); }, filter: (f) => hitDb().filter(f) };
  const watch = () => {};
  const login = async (p, u, pw) => { await p.goto(BASE + '/wp-login.php'); await p.fill('#user_login', u); await p.fill('#user_pass', pw); await p.click('#wp-submit'); await p.waitForLoadState(); };
  const pv = () => hit.filter((x) => !x.n);

  // 1) anonymous visitor
  let ctx = await b.newContext({ userAgent: UA }); watch(ctx); let p = await ctx.newPage();
  const errs = []; p.on('pageerror', (e) => errs.push(e.message));
  hit.length = 0;
  await p.goto(BASE + '/'); await p.waitForTimeout(800);
  t('visitor: pageview sent', pv().length === 1 && pv()[0].p === '/', JSON.stringify(hitDb()));
  const post = BASE + '/hello-tacilytics/';
  await p.goto(post); await p.waitForTimeout(500);
  await p.evaluate(() => document.querySelectorAll('a').forEach((a) => a.addEventListener('click', (e) => e.preventDefault())));
  await p.click('a[href$="guide.pdf"]'); await p.click('a[href="https://example.org/"]'); await p.waitForTimeout(500);
  const ev = (n) => hit.filter((x) => x.n === n);
  t('visitor: download event', ev('File Download').length === 1 && ev('File Download')[0].pr.file === 'guide.pdf', JSON.stringify(ev('File Download')));
  t('visitor: outbound event', ev('Outbound Link').length === 1 && ev('Outbound Link')[0].pr.url === 'example.org/', JSON.stringify(ev('Outbound Link')));
  await p.goto(BASE + '/?s=tacilytics'); await p.waitForTimeout(3200);
  t('visitor: search with results', ev('Search').some((x) => x.pr.q === 'tacilytics' && Number(x.pr.results) >= 1), JSON.stringify(ev('Search')));
  await p.goto(BASE + '/?s=zzqqxx'); await p.waitForTimeout(3200);
  t('visitor: search without results', ev('Search').some((x) => x.pr.q === 'zzqqxx' && Number(x.pr.results) === 0), JSON.stringify(ev('Search')));
  await p.goto(BASE + '/gibt-es-nicht-123/'); await p.waitForTimeout(800);
  t('visitor: 404 event', ev('404').length === 1 && ev('404')[0].pr.url === '/gibt-es-nicht-123/', JSON.stringify(ev('404')));
  // WooCommerce sets its own sbjs_* order-attribution cookies; only ours matter here.
  const ours = (await ctx.cookies()).filter((c) => !/^(sbjs_|wp-wpml|woocommerce_|wp_woocommerce)/.test(c.name));
  t('visitor: no cookies from plugin', ours.length === 0, JSON.stringify(ours.map((c) => c.name)));
  t('visitor: no JS errors', errs.length === 0, errs.join('|'));
  await ctx.close();

  // 2) editor (excluded by default) → no hits on the front end, also after logout on this browser
  ctx = await b.newContext({ userAgent: UA }); watch(ctx); p = await ctx.newPage(); hit.length = 0;
  await login(p, 'ed', 'ed-pass-123'); hit.length = 0;
  await p.goto(BASE + '/'); await p.waitForTimeout(800);
  t('editor: not counted', hit.length === 0, JSON.stringify(hitDb()));
  await ctx.close();

  // 3) subscriber (not excluded) → counted; a visitor's own ?tacilytics=off is not undone by WordPress
  ctx = await b.newContext({ userAgent: UA }); watch(ctx); p = await ctx.newPage();
  await login(p, 'sub', 'sub-pass-123'); hit.length = 0;
  await p.goto(BASE + '/'); await p.waitForTimeout(800);
  t('subscriber: counted', pv().length === 1, JSON.stringify(hitDb()));
  await p.goto(BASE + '/?tacilytics=off'); await p.waitForTimeout(300); hit.length = 0;
  await p.goto(BASE + '/'); await p.waitForTimeout(800);
  t('subscriber: own opt-out kept', hit.length === 0 && (await p.evaluate(() => localStorage.getItem('tacilytics_ignore'))) === '1', JSON.stringify(hitDb()));
  await ctx.close();

  // 4) admin marked, then the same browser logs in as subscriber → counted again (marker was ours)
  ctx = await b.newContext({ userAgent: UA }); watch(ctx); p = await ctx.newPage();
  await login(p, 'admin', 'taci-test-pw'); await p.goto(BASE + '/'); await p.waitForTimeout(300);
  await p.goto(BASE + '/wp-login.php?action=logout'); await p.click('a:has-text("log out")').catch(() => {}); await p.waitForLoadState();
  await login(p, 'sub', 'sub-pass-123'); await p.goto(BASE + '/'); await p.waitForTimeout(300); hit.length = 0;
  await p.goto(BASE + '/'); await p.waitForTimeout(800);
  t('admin→subscriber: counted again', pv().length === 1, JSON.stringify(hitDb()) + ' ls=' + (await p.evaluate(() => localStorage.getItem('tacilytics_ignore'))));
  await ctx.close();
  // 5) Kiro (a): own ?tacilytics=off, then logged in as excluded admin, then as counted subscriber → own opt-out stays
  ctx = await b.newContext({ userAgent: UA }); p = await ctx.newPage();
  await p.goto(BASE + '/?tacilytics=off'); await p.waitForTimeout(300);
  await login(p, 'admin', 'taci-test-pw'); await p.goto(BASE + '/'); await p.waitForTimeout(300);
  const wpAfterAdmin = await p.evaluate(() => localStorage.getItem('tacilytics_wp'));
  await p.goto(BASE + '/wp-login.php?action=logout'); await p.click('a:has-text("log out")').catch(() => {}); await p.waitForLoadState();
  await login(p, 'sub', 'sub-pass-123'); await p.goto(BASE + '/'); await p.waitForTimeout(300);
  t('own opt-out survives excluded→counted role', (await p.evaluate(() => localStorage.getItem('tacilytics_ignore'))) === '1' && wpAfterAdmin === null, 'wp after admin=' + wpAfterAdmin + ' ignore=' + (await p.evaluate(() => localStorage.getItem('tacilytics_ignore'))));
  await ctx.close();

  // Tyto (d): attribute value with $0 / \1 survives literally
  ctx = await b.newContext({ userAgent: UA }); p = await ctx.newPage(); await p.goto(BASE + '/');
  const dv = await p.evaluate(() => { const s = document.querySelector('script[src*="tacilytics.js"]'); return s && s.getAttribute('data-taci-test'); });
  t('attribute with $0 and \\1 kept literally', dv === 'a$0b\\1c', dv);
  await ctx.close();

  console.log('OK ' + ok.length + ' / BAD ' + bad.length); bad.forEach((x) => console.log('  ✗ ' + x));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
