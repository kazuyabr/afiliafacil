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

  // Conversa nova: pedir pesquisa web (tool de leitura, sem confirmacao)
  await page.goto('http://localhost:9876/admin/agent.php');
  await page.waitForSelector('#agentInput', { timeout: 10000 });
  await page.waitForTimeout(1500);
  await page.click('button:has-text("Nova conversa")');
  await page.waitForTimeout(1500);
  await page.fill('#agentInput', 'pesquise na web tendencias de marketing digital 2026');
  await page.keyboard.press('Enter');
  console.log('1. mensagem enviada');

  // Aguardar o tool card de pesquisa web (executa automaticamente, pois e leitura)
  let webCard = false;
  let links = 0;
  for (let i = 0; i < 50; i++) {
    await page.waitForTimeout(4000);
    links = await page.locator('.tool-result a[target="_blank"]').count();
    if (links > 0) { webCard = true; break; }
  }
  console.log('2. card de pesquisa web com links: ' + webCard + ' (links=' + links + ')');

  const executed = await page.locator('.status-executed').count();
  console.log('3. tool executada sem confirmacao: ' + (executed > 0));

  const firstTitle = await page.locator('.tool-result a[target="_blank"]').first().textContent().catch(() => '');
  const firstHost = await page.locator('.tool-result a[target="_blank"]').first().getAttribute('href').catch(() => '');
  console.log('4. primeiro resultado: "' + (firstTitle || '').substring(0, 70) + '" -> ' + (firstHost || '').substring(0, 60));

  await page.screenshot({ path: 'web-search.png' });
  console.log('JS_ERRORS=' + errors.length);
  errors.slice(0, 4).forEach(e => console.log('  ' + e));
  await browser.close();
})();
