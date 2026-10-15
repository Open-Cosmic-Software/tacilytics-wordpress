const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const IP = process.env.IP, BASE = 'http://taci-wp.test';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const ok = [], bad = []; const t = (n, c, i) => (c ? ok : bad).push(n + (c ? '' : ' → ' + (i || '')));
const db = (sql) => execFileSync('sqlite3', ['-json', '' + (process.env.TACI_DB || '/tmp/taci-wptest/app/t.db') + '', sql], { encoding: 'utf8' });
(async () => {
  const b = await chromium.launch({ args: ['--host-resolver-rules=MAP tacilytics.test ' + IP + ', MAP taci-wp.test 127.0.0.1:8931'] });
  const ctx = await b.newContext({ userAgent: UA }); const p = await ctx.newPage();
  const errs = []; p.on('pageerror', (e) => errs.push(e.message));
  const before = Number(execFileSync('sqlite3', ['' + (process.env.TACI_DB || '/tmp/taci-wptest/app/t.db') + '', 'select coalesce(max(id),0) from events'], { encoding: 'utf8' }).trim());
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
  console.log('OK ' + ok.length + ' / BAD ' + bad.length); bad.forEach((x) => console.log('  ✗ ' + x));
  await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
