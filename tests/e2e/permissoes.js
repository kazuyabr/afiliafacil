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

  await page.goto('http://localhost:9876/admin/agent.php', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#agentInput', { timeout: 15000 });

  // 1. Abrir modal de permissoes
  await page.click('button[title="Permissões do Sócio"]');
  await page.waitForTimeout(1200);
  const modalOpen = await page.locator('#permissionsModal.active').count() > 0;
  const total = await page.locator('.permission-tool').count();
  const checked = await page.locator('.permission-tool:checked').count();
  const leituraBadges = await page.locator('#permissionsList span:has-text("leitura")').count();
  const acaoBadges = await page.locator('#permissionsList span:has-text("ação")').count();
  console.log('1. modal aberto=' + modalOpen + ' tools=' + total + ' marcadas=' + checked);
  console.log('2. badges: leitura=' + leituraBadges + ' acao=' + acaoBadges);

  // 3. Desmarcar pesquisar_web e salvar
  await page.locator('.permission-tool[value="pesquisar_web"]').uncheck();
  await page.click('#permissionsModal button.btn-primary');
  await page.waitForTimeout(1500);
  const closed = await page.locator('#permissionsModal.active').count() === 0;
  console.log('3. salvou e fechou: ' + closed);

  // 4. Verificar via API
  const api = await page.evaluate(async () => {
    const r = await fetch('/admin/api/agent.php?action=permissions');
    return await r.json();
  });
  console.log('4. API: pesquisar_web permitida=' + api.allowed_tools.includes('pesquisar_web') + ' total=' + api.allowed_tools.length);

  // 5. Reabrir e religar
  await page.click('button[title="Permissões do Sócio"]');
  await page.waitForTimeout(1000);
  await page.locator('.permission-tool[value="pesquisar_web"]').check();
  await page.click('#permissionsModal button.btn-primary');
  await page.waitForTimeout(1200);
  const api2 = await page.evaluate(async () => {
    const r = await fetch('/admin/api/agent.php?action=permissions');
    return await r.json();
  });
  console.log('5. religado: pesquisar_web=' + api2.allowed_tools.includes('pesquisar_web') + ' total=' + api2.allowed_tools.length);

  console.log('JS_ERRORS=' + errors.length);
  errors.slice(0, 4).forEach(e => console.log('  ' + e));
  await browser.close();
})();
