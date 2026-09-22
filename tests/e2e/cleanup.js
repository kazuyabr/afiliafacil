const { chromium } = require('playwright');

// Limpa os dados criados pelos testes E2E (conversas do Socio + perfil + jobs).
// Rode apos qualquer teste: node cleanup.js
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();

  await page.goto('http://localhost:9876/login');
  await page.fill('input[name="email"]', 'admin@afiliafacil.com');
  await page.fill('input[name="password"]', 'admin123');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/admin/**');

  const result = await page.evaluate(async () => {
    const out = { conversations: 0, skipped: 0, profile: '' };

    const listResp = await fetch('/admin/api/agent.php?action=conversations');
    const list = await listResp.json();
    for (const c of (list.conversations || [])) {
      // So remove conversas de teste (prefixo [e2e]) — NUNCA apaga conversas reais do usuario.
      if (!String(c.title || '').startsWith('[e2e]')) { out.skipped++; continue; }
      await fetch('/admin/api/agent.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'delete', id: String(c.id) })
      });
      out.conversations++;
    }

    await fetch('/admin/api/agent.php', {
      method: 'POST',
      body: new URLSearchParams({ action: 'profile', clear: '1' })
    });
    out.profile = 'limpo';

    return out;
  });

  console.log('conversas [e2e] removidas: ' + result.conversations);
  console.log('conversas reais preservadas: ' + result.skipped);
  console.log('perfil: ' + result.profile);
  await browser.close();
})();
