// G4 — responsividade + erros JS nao tratados, 3 breakpoints.
// Uso: node responsive.js   (requer app em http://localhost:9876 e npm install)
const { chromium } = require('playwright');

const BASE = 'http://localhost:9876';
const VPS = [
  { w: 360, h: 740 },
  { w: 768, h: 1024 },
  { w: 1280, h: 800 },
];
const PUBLIC_PAGES = ['/', '/login', '/register', '/termos', '/privacidade', '/cookies', '/degustacao'];
// mesmas paginas do smoke (admin) + feedback/agent-monitor/videos (telas reais fora do smoke)
const ADMIN_PAGES = [
  '/admin/index.php',
  '/admin/pages.php',
  '/admin/clone.php',
  '/admin/pressel.php',
  '/admin/video.php',
  '/admin/pixel.php',
  '/admin/backredirect.php',
  '/admin/cookie.php',
  '/admin/domains.php',
  '/admin/integrations.php',
  '/admin/publicacoes.php',
  '/admin/adspy.php',
  '/admin/ofertas.php',
  '/admin/agent.php',
  '/admin/ai-settings.php',
  '/admin/transcribe.php',
  '/admin/tts.php',
  '/admin/users.php',
  '/admin/roles.php',
  '/admin/pricing.php',
  '/admin/pay.php',
  '/admin/audit.php',
  '/admin/moderation.php',
  '/admin/training.php',
  '/admin/plan.php',
  '/admin/storage.php',
  '/admin/settings.php',
  '/admin/feedback.php',
  '/admin/agent-monitor.php',
  '/admin/videos.php',
];

let checks = 0;
let failures = 0;

function fail(msg) {
  failures++;
  console.log('FAIL ' + msg);
}

async function audit(page, url, vp) {
  const errors = [];
  const onErr = e => errors.push(e.message.split('\n')[0]);
  page.on('pageerror', onErr);
  checks++;
  try {
    await page.goto(BASE + url, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await page.waitForTimeout(700);
    const m = await page.evaluate(() => {
      const doc = document.documentElement;
      const vw = doc.clientWidth;
      const sw = doc.scrollWidth;
      const offenders = [];
      if (sw > vw + 2) {
        for (const el of document.querySelectorAll('body *')) {
          const r = el.getBoundingClientRect();
          if (r.width <= 0 || r.right <= vw + 2) continue;
          const cs = getComputedStyle(el);
          if (cs.position === 'fixed' || cs.display === 'none' || cs.visibility === 'hidden') continue;
          const id = el.id ? '#' + el.id : '';
          const cls = typeof el.className === 'string' && el.className.trim()
            ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '';
          offenders.push(el.tagName.toLowerCase() + id + cls);
          if (offenders.length >= 5) break;
        }
      }
      return {
        vw,
        sw,
        hasViewport: !!document.querySelector('meta[name="viewport"]'),
        offenders,
      };
    });

    const tag = url + ' @' + vp.w;
    if (!m.hasViewport) fail('viewport meta ausente em ' + tag);
    if (m.sw > m.vw + 2) {
      fail('overflow horizontal em ' + tag + ': scrollWidth=' + m.sw + ' > ' + m.vw + ' | ' + m.offenders.join(', '));
    }
    if (errors.length) fail('pageerror em ' + tag + ': ' + errors[0]);
    if (m.hasViewport && m.sw <= m.vw + 2 && errors.length === 0) console.log('ok   ' + tag);
  } catch (e) {
    fail('goto ' + url + ' @' + vp.w + ': ' + e.message.split('\n')[0]);
  } finally {
    page.off('pageerror', onErr);
  }
}

async function login(page) {
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded', timeout: 20000 });
  await page.fill('input[name="email"]', 'admin@afiliafacil.com');
  await page.fill('input[name="password"]', 'admin123');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/admin/**', { timeout: 15000 });
}

(async () => {
  const browser = await chromium.launch();
  try {
    for (const vp of VPS) {
      // paginas publicas (contexto sem sessao)
      let ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
      let page = await ctx.newPage();
      page.setDefaultTimeout(15000);
      for (const u of PUBLIC_PAGES) await audit(page, u, vp);
      await ctx.close();

      // paginas admin (login una vez por breakpoint)
      ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
      page = await ctx.newPage();
      page.setDefaultTimeout(15000);
      await login(page);
      for (const u of ADMIN_PAGES) await audit(page, u, vp);
      await ctx.close();
      console.log('-- breakpoint ' + vp.w + 'x' + vp.h + ' concluido');
    }
  } finally {
    await browser.close();
  }
  console.log(failures
    ? 'RESPONSIVE: FAIL (' + failures + '/' + checks + ' checks)'
    : 'RESPONSIVE: OK (' + checks + ' checks)');
  process.exit(failures ? 1 : 0);
})().catch(e => {
  console.error('ERRO: ' + e.message);
  process.exit(1);
});
