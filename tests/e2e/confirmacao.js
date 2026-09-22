const { chromium } = require('playwright');

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

  // 1. Abrir o Socio e pedir ofertas (gera tool pendente) — em conversa NOVA
  await page.goto('http://localhost:9876/admin/agent.php');
  await page.waitForSelector('#agentInput', { timeout: 10000 });
  await page.waitForTimeout(1500);
  await page.click('button:has-text("Nova conversa")');
  await page.waitForTimeout(1500);
  // Marca a conversa como teste para a limpeza (cleanup.js so remove [e2e])
  await page.evaluate(async () => {
    const r = await fetch('/admin/api/agent.php', {
      method: 'POST',
      body: new URLSearchParams({ action: 'rename', id: String(conversationId), title: '[e2e] confirmacao' })
    });
    return await r.json();
  });
  await page.fill('#agentInput', 'espione anuncios de financas no meta e tiktok');
  await page.keyboard.press('Enter');
  await page.waitForTimeout(2000);

  // 2. Aguardar o tool card pendente aparecer (a IA responde em background)
  let pending = false;
  for (let i = 0; i < 30; i++) {
    await page.waitForTimeout(4000);
    pending = await page.locator('.status-pending_confirmation').count() > 0;
    if (pending) break;
  }
  console.log('1. tool pendente apareceu: ' + pending);

  // Regressao de layout: o card nao pode ser encolhido pelo flex (texto cortado)
  const cardLayout = await page.evaluate(() => {
    const card = document.querySelector('.msg.tool-card');
    if (!card) return null;
    const body = card.querySelector('.tool-body');
    return {
      cardH: card.offsetHeight,
      cardScrollH: card.scrollHeight,
      bodyVisible: body ? body.offsetHeight > 0 : false,
    };
  });
  console.log('1b. layout do card: ' + JSON.stringify(cardLayout) +
    ' (ok=' + (cardLayout && cardLayout.cardH > 50 && cardLayout.cardH >= cardLayout.cardScrollH - 2) + ')');

  const bannerVisible = await page.locator('#pendingBanner').isVisible().catch(() => false);
  console.log('2. banner "aguarda confirmacao" visivel: ' + bannerVisible);
  await page.screenshot({ path: 'confirm-antes.png' });

  // 3. Clicar em Confirmar
  if (pending) {
    await page.locator('[data-tool-actions] .btn-primary').first().click();
    await page.waitForTimeout(1500);
    const processing = await page.locator('.status-processing').count();
    const btnsGone = await page.locator('[data-tool-actions] button:not([disabled])').count();
    console.log('3. apos confirmar: card processing=' + (processing > 0) + ' botoes_ativos=' + btnsGone);
    await page.screenshot({ path: 'confirm-depois.png' });

    // 4. Navegar para outra tela e ver o sino (spinner/working)
    await page.goto('http://localhost:9876/admin/feedback.php', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);
    const bellIcon = await page.locator('#agentBell i').getAttribute('class');
    console.log('4. sino em outra tela (icone): ' + bellIcon);

    // 5. Aguardar terminar e ver o badge/resposta
    let done = false;
    for (let i = 0; i < 25; i++) {
      await page.waitForTimeout(5000);
      const icon = await page.locator('#agentBell i').getAttribute('class');
      const badge = await page.locator('#agentBellBadge').isVisible().catch(() => false);
      if (!icon.includes('spinner') && badge) { done = true; break; }
    }
    console.log('5. terminou e badge apareceu: ' + done);

    // 6. Clicar no sino -> ver o card executado com resultado
    if (done) {
      await page.click('#agentBell');
      await page.waitForTimeout(2500);
      const executed = await page.locator('.status-executed').count();
      const resultText = await page.locator('.tool-result').last().textContent().catch(() => '');
      console.log('6. card executado=' + (executed > 0) + ' | resultado: ' + resultText.substring(0, 80));
      await page.screenshot({ path: 'confirm-final.png' });
    }
  }

  console.log('JS_ERRORS=' + errors.length);
  errors.slice(0, 4).forEach(e => console.log('  ' + e));
  await browser.close();
})();
