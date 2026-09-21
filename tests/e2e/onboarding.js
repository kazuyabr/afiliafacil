const { chromium } = require('playwright');

async function waitJobDone(page, conversationId, maxTries = 60) {
  for (let i = 0; i < maxTries; i++) {
    await page.waitForTimeout(4000);
    const data = await page.evaluate(async (cid) => {
      const r = await fetch('/admin/api/agent.php?action=job-status&conversation_id=' + cid);
      return await r.json();
    }, conversationId);
    const job = data.job;
    if (!job) return 'no-job';
    if (job.status === 'done') return 'done';
    if (job.status === 'failed') return 'failed';
  }
  return 'timeout';
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  const errors = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

  await page.goto('http://localhost:9876/login');
  await page.fill('input[name="email"]', 'admin@afiliafacil.com');
  await page.fill('input[name="password"]', 'admin123');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/admin/**');

  await page.goto('http://localhost:9876/admin/agent.php', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#agentInput', { timeout: 15000 });
  await page.waitForTimeout(2000);

  // 1. Conversa nova (perfil sem nicho) -> chips dinamicos de nicho
  await page.click('button:has-text("Nova conversa")');
  await page.waitForTimeout(2500);
  const convId = await page.evaluate(() => conversationId);
  const greeting = await page.locator('.msg.agent').last().textContent().catch(() => '');
  const chips = await page.locator('.chip').allTextContents();
  console.log('1. greeting afiliacao=' + /afilia/i.test(greeting) + ' pergunta nicho=' + /nicho/i.test(greeting));
  console.log('2. chips: ' + JSON.stringify(chips));

  // 3. Clicar no chip "Finanças" -> salva perfil + investiga
  await page.locator('.chip', { hasText: 'Finanças' }).first().click();
  const status = await waitJobDone(page, convId);
  console.log('3. job do onboarding: ' + status);

  // 4. Perfil salvo deterministicamente
  const prof = await page.evaluate(async () => {
    const r = await fetch('/admin/api/agent.php?action=profile');
    return await r.json();
  });
  console.log('4. perfil niche="' + (prof.profile?.niche || '') + '"');

  // 5. Tool executada (busca de ofertas) + comentario da IA
  await page.evaluate(async (cid) => { await openConversation(cid); }, convId);
  await page.waitForTimeout(2500);
  const executed = await page.locator('.status-executed').count();
  const toolNames = await page.locator('.tool-card .tool-name, .tool-result').allTextContents().catch(() => []);
  const lastAgent = await page.locator('.msg.agent').last().textContent().catch(() => '');
  console.log('5. tools executadas=' + executed);
  console.log('6. comentario IA: ' + lastAgent.substring(0, 100).replace(/\n/g, ' '));

  // 7. Nova conversa -> greeting NAO assume o nicho: oferece escolha mantendo o atual
  await page.click('button:has-text("Nova conversa")');
  await page.waitForTimeout(2500);
  const greeting2 = await page.locator('.msg.agent').last().textContent().catch(() => '');
  const chips2 = await page.locator('.chip').allTextContents();
  console.log('7. greeting2 cita o nicho salvo=' + /finan/i.test(greeting2) + ' e oferece escolha=' + /continuamos|outro/i.test(greeting2));
  console.log('8. chips2: ' + JSON.stringify(chips2));

  console.log('JS_ERRORS=' + errors.length);
  errors.slice(0, 4).forEach(e => console.log('  ' + e));
  await browser.close();
})();
