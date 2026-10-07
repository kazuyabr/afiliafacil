// G3 — valida o JS inline SERVIDO das telas admin (sintaxe de todo <script> sem src).
// Uso: node served-check.js   (requer app em http://localhost:9876 e npm install)
const { request } = require('playwright');
const vm = require('vm');

const BASE = 'http://localhost:9876';
const PAGES = [
  '/admin/index.php', '/admin/pages.php', '/admin/clone.php', '/admin/pressel.php',
  '/admin/video.php', '/admin/pixel.php', '/admin/backredirect.php', '/admin/cookie.php',
  '/admin/domains.php', '/admin/integrations.php', '/admin/publicacoes.php', '/admin/adspy.php',
  '/admin/ofertas.php', '/admin/agent.php', '/admin/ai-settings.php', '/admin/transcribe.php',
  '/admin/tts.php', '/admin/users.php', '/admin/roles.php', '/admin/pricing.php',
  '/admin/pay.php', '/admin/audit.php', '/admin/moderation.php', '/admin/training.php',
  '/admin/plan.php', '/admin/storage.php', '/admin/settings.php', '/admin/feedback.php',
  '/admin/agent-monitor.php', '/admin/videos.php',
];

async function main() {
  const ctx = await request.newContext({ ignoreHTTPSErrors: true });

  // login (mesmo fluxo do fetch-served: GET + POST com cookies, seguindo redirect)
  await ctx.get(BASE + '/login');
  await ctx.post(BASE + '/login', {
    form: { email: 'admin@afiliafacil.com', password: 'admin123' },
  });
  // prova que a sessao e valida antes de buscar as telas admin
  const probe = await ctx.get(BASE + '/admin/index.php');
  const probeHtml = await probe.text();
  if (probe.status() !== 200 || probeHtml.includes('name="password"')) {
    console.log('ERRO: login admin falhou (status ' + probe.status() + ')');
    process.exit(1);
  }

  let failures = 0;
  for (const path of PAGES) {
    const res = await ctx.get(BASE + path);
    if (res.status() !== 200) {
      console.log('FAIL ' + path + ' status=' + res.status() + ' (login falhou ou rota quebrada)');
      failures++;
      continue;
    }
    const html = await res.text();
    const scripts = [];
    const re = /<script(\s[^>]*)?>([\s\S]*?)<\/script>/g;
    let m;
    while ((m = re.exec(html)) !== null) {
      const attrs = m[1] || '';
      if (/\ssrc\s*=/.test(attrs)) continue;               // externo (app.js etc) — fora do escopo
      const t = (attrs.match(/type\s*=\s*["']([^"']+)["']/i) || [])[1];
      if (t && !/javascript/i.test(t)) continue;           // templates type=text/template
      if (/module/i.test(t || '')) continue;               // ESM inline (raro; fora do check)
      if (m[2].trim() === '') continue;
      scripts.push(m[2]);
    }
    if (scripts.length === 0) {
      // sem script inline e valido (ex.: admin/index.php so usa <script src=...>)
      console.log('ok   ' + path + ' (0 script inline — so externos)');
      continue;
    }
    let pageOk = true;
    for (let i = 0; i < scripts.length; i++) {
      try {
        new vm.Script(scripts[i], { filename: path + '#' + i });
      } catch (err) {
        console.log('FAIL ' + path + ' script#' + i + ' sintaxe: ' + err.message.split('\n')[0]);
        failures++;
        pageOk = false;
      }
    }
    if (pageOk) console.log('ok   ' + path + ' (' + scripts.length + ' script inline)');
  }

  console.log(failures ? 'SERVED: FAIL (' + failures + ')' : 'SERVED: OK (' + PAGES.length + ' paginas)');
  process.exit(failures ? 1 : 0);
}

main().catch(e => {
  console.error('ERRO: ' + e.message);
  process.exit(1);
});
