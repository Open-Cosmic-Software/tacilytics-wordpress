const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const IP = process.env.IP, BASE = 'http://taci-wp.test';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const ok = [], bad = []; const t = (n, c, i) => (c ? ok : bad).push(n + (c ? '' : ' → ' + (i || '')));
const db = (sql) => execFileSync('sqlite3', ['-json', process.env.TACI_DB || (process.env.TACI_DB || '/tmp/taci-wptest/app/t.db'), sql], { encoding: 'utf8' });
(async () => {
  const b = await chromium.launch({ args: ['--host-resolver-rules=MAP tacilytics.test ' + IP + ', MAP taci-wp.test 127.0.0.1:8931'] });
  const ctx = await b.newContext({ userAgent: UA }); const p = await ctx.newPage();
  const errs = []; p.on('pageerror', (e) => errs.push(e.message));
  const before = Number(execFileSync('sqlite3', [(process.env.TACI_DB || '/tmp/taci-wptest/app/t.db'), 'select coalesce(max(id),0) from events'], { encoding: 'utf8' }).trim());
  await p.goto(BASE + '/?add-to-cart=' + process.env.PID + '&quantity=2'); await p.waitForLoadState();
  await p.goto(BASE + '/checkout/'); await p.waitForLoadState('networkidle');
  const f = async (sel, v) => { const l = p.locator(sel).first(); if (await l.count()) await l.fill(v); };
  await f('#email', 'kunde@example.com'); await f('#billing_email', 'kunde@example.com');
  for (const pre of ['#billing', '#shipping']) {
    await f(pre + '-first_name', 'Max'); await f(pre + '-last_name', 'Muster'); await f(pre + '-address_1', 'Teststr. 1');
    await f(pre + '-city', 'Bestwig'); await f(pre + '-postcode', '59909');
    await f(pre + '_first_name', 'Max'); await f(pre + '_last_name', 'Muster'); await f(pre + '_address_1', 'Teststr. 1'); await f(pre + '_city', 'Bestwig'); await f(pre + '_postcode', '59909');
  }
  const cod = p.locator('input[value="cod"]').first(); if (await cod.count()) await cod.check({ force: true });
  await p.locator('.wc-block-components-checkout-place-order-button, #place_order').first().click();
  await p.waitForURL(/order-received/, { timeout: 30000 });
  await p.waitForTimeout(2000);
  const html = await p.content();
  t('thank-you page has purchase script', html.includes("window.tacilytics('Purchase'"));
  t('no customer data in script', !/kunde@example\.com|Muster|Teststr/.test((html.match(/<script>\s*\(function \(\) \{[\s\S]*?Purchase[\s\S]*?<\/script>/) || [''])[0]));
  let ev = JSON.parse(db(`select name, props from events where id > ${before} and name='Purchase'`) || '[]');
  t('purchase event stored once', ev.length === 1, JSON.stringify(ev));
  const pr = ev[0] ? JSON.parse(ev[0].props) : {};
  t('purchase value 19.80 EUR, 2 items', Number(pr.value) === 19.8 && pr.currency === 'EUR' && Number(pr.items) === 2, JSON.stringify(pr));
  await p.reload(); await p.waitForTimeout(2000);
  ev = JSON.parse(db(`select name from events where id > ${before} and name='Purchase'`) || '[]');
  t('reload does not count twice', ev.length === 1, ev.length);
  // a new browser session (sessionStorage empty) on the same thank-you URL: server-side flag stops it
  const ctx2 = await b.newContext({ userAgent: UA }); const p2 = await ctx2.newPage();
  await p2.goto(p.url()); await p2.waitForTimeout(2000);
  ev = JSON.parse(db(`select name from events where id > ${before} and name='Purchase'`) || '[]');
  t('other session does not count twice', ev.length === 1, ev.length);
  t('no JS errors', errs.length === 0, errs.join('|'));

  // Kiro (b): blocked tracker → nothing claimed, nothing counted; later visit with tracker → counted once
  const buy = async (pg) => {
    await pg.goto(BASE + '/?add-to-cart=' + process.env.PID + '&quantity=1'); await pg.waitForLoadState();
    await pg.goto(BASE + '/checkout/'); await pg.waitForLoadState('networkidle');
    const ff = async (sel, v) => { const l = pg.locator(sel).first(); if (await l.count()) await l.fill(v); };
    await ff('#email', 'k2@example.com');
    for (const pre of ['#billing', '#shipping']) { for (const [k, v] of [['first_name', 'A'], ['last_name', 'B'], ['address_1', 'C 1'], ['city', 'D'], ['postcode', '59909']]) { await ff(pre + '-' + k, v); } }
    const c2 = pg.locator('input[value="cod"]').first(); if (await c2.count()) await c2.check({ force: true });
    await pg.locator('.wc-block-components-checkout-place-order-button, #place_order').first().click();
    await pg.waitForURL(/order-received/, { timeout: 30000 });
  };
  const ctxB = await b.newContext({ userAgent: UA }); await ctxB.route('**/tacilytics.js', (r) => r.abort());
  const pb = await ctxB.newPage(); const before2 = Number(execFileSync('sqlite3', [process.env.TACI_DB || (process.env.TACI_DB || '/tmp/taci-wptest/app/t.db'), 'select coalesce(max(id),0) from events'], { encoding: 'utf8' }).trim());
  await buy(pb); await pb.waitForTimeout(11000);
  const url2 = pb.url();
  let ev2 = JSON.parse(db(`select name from events where id > ${before2} and name='Purchase'`) || '[]');
  t('blocked tracker: not counted', ev2.length === 0, ev2.length);
  // two tabs with the tracker open at the same time
  const ctxC = await b.newContext({ userAgent: UA }); const t1 = await ctxC.newPage(); const t2 = await ctxC.newPage();
  await Promise.all([t1.goto(url2), t2.goto(url2)]); await t1.waitForTimeout(3000);
  ev2 = JSON.parse(db(`select name from events where id > ${before2} and name='Purchase'`) || '[]');
  t('later visit counts once, also with two tabs', ev2.length === 1, ev2.length);
  // race: 10 parallel claims for one fresh order (tracker blocked, so nothing claimed it yet) → exactly one wins
  const ctxR = await b.newContext({ userAgent: UA }); await ctxR.route('**/tacilytics.js', (r) => r.abort());
  const pr2 = await ctxR.newPage(); await buy(pr2); await pr2.waitForTimeout(500);
  const wins = await pr2.evaluate(async () => {
    const c = window.tacilyticsPurchase;
    const one = () => fetch(c.url, { method: 'POST', body: new URLSearchParams({ action: c.action, order: String(c.order), token: c.token }) }).then((r) => r.json());
    const all = await Promise.all(Array.from({ length: 10 }, one));
    return all.filter((j) => j && j.success && j.data && j.data.claimed).length;
  });
  t('10 parallel claims → exactly 1 wins', wins === 1, wins);
  // forged token → 403
  const forged = await t1.evaluate(async (u) => (await fetch(u + '/wp-admin/admin-ajax.php', { method: 'POST', body: new URLSearchParams({ action: 'tacilytics_purchase_sent', order: '1', token: 'x' }) })).status, BASE);
  t('forged token rejected', forged === 403, forged);
  console.log('OK ' + ok.length + ' / BAD ' + bad.length); bad.forEach((x) => console.log('  ✗ ' + x));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
