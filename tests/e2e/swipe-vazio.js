const { chromium } = require('playwright');

async function waitJobDone(page, conversationId, maxTries = 70) {
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

  // 1. Conversa nova -> clicar "Outro" (nicho livre)
  await page.click('button:has-text("Nova conversa")');
  await page.waitForTimeout(2500);
  const convId = await page.evaluate(() => conversationId);
  await page.locator('.chip', { hasText: 'Outro' }).first().click();
  const s1 = await waitJobDone(page, convId);
  console.log('1. IA pediu o nicho apos "Outro": ' + s1);

  // 2. Responder "Games"
  await page.fill('#agentInput', 'Games');
  await page.keyboard.press('Enter');
  const s2 = await waitJobDone(page, convId);
  console.log('2. job do nicho Games: ' + s2);

  // 3. Analisar a conversa inteira
  await page.evaluate(async (cid) => { await openConversation(cid); }, convId);
  await page.waitForTimeout(3000);

  const allText = (await page.locator('#agentMessages').textContent().catch(() => '')).replace(/\s+/g, ' ');
  const tools = await page.locator('.status-executed, .status-pending_confirmation').allTextContents().catch(() => []);

  const pedeTermo = /qual termo|tentar outro termo|gostaria de tentar|qual palavra/i.test(allText);
  const usaFontes = /espionar|an[uú]ncio|biblioteca|pesquis|mercado|web/i.test(allText);
  const sugereNichos = /finan[cç]as|emagrecimento|espiritualidade|relacionamento/i.test(allText);
  const caminhos = /caminho|op[cç][oõ]es|alternativa|adjacente|investigar|pr[oó]ximo passo|considere/i.test(allText);

  console.log('3. NAO devolve pergunta aberta (nao pede termo): ' + !pedeTermo);
  console.log('4. menciona investigacao nas fontes: ' + usaFontes);
  console.log('5. sugere nichos disponiveis/alternativas: ' + sugereNichos);
  console.log('6. oferece caminhos concretos: ' + caminhos);
  console.log('7. tools na conversa: ' + JSON.stringify(tools.map(t => t.substring(0, 60).replace(/\n/g, ' '))));
  console.log('--- ultima resposta (trecho) ---');
  const last = await page.locator('.msg.agent').last().textContent().catch(() => '');
  console.log(last.substring(0, 500).replace(/\n/g, ' | '));

  console.log('JS_ERRORS=' + errors.length);
  errors.slice(0, 4).forEach(e => console.log('  ' + e));
  await browser.close();
})();
