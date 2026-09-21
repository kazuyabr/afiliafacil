const { chromium } = require('playwright');

// Testa o gerenciamento de modelos locais (VRAM): listar, carregar (sem duplicar), descarregar.
// Requer um servidor LM Studio rodando em http://127.0.0.1:1234 com pelo menos um modelo baixado.
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

  await page.goto('http://localhost:9876/admin/ai-settings.php', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => document.getElementById('aiProvider') && document.getElementById('aiProvider').options.length > 5, { timeout: 60000 }).catch(() => {});

  // 1. Selecionar lmstudio -> lista modelos do servidor local
  await page.selectOption('#aiProvider', 'lmstudio');
  await page.waitForTimeout(4000);
  const hint1 = await page.locator('#localModelHint').textContent().catch(() => '');
  const actionsVisible = await page.locator('#localModelActions').isVisible().catch(() => false);
  const modelCount = await page.locator('#aiModel option').count();
  console.log('1. hint="' + hint1.substring(0, 80) + '" acoes_visiveis=' + actionsVisible + ' modelos=' + modelCount);

  if (modelCount === 0) { console.log('SEM MODELOS LOCAIS — abortando'); await browser.close(); return; }

  // Escolhe o primeiro modelo com quantization (evita embeddings)
  const firstModel = await page.locator('#aiModel option').first().getAttribute('value');
  await page.selectOption('#aiModel', firstModel);
  console.log('2. modelo escolhido: ' + firstModel);

  // 3. Carregar na VRAM
  await page.click('button:has-text("Carregar na VRAM")');
  let loadOk = false;
  for (let i = 0; i < 40; i++) {
    await page.waitForTimeout(3000);
    const h = await page.locator('#localModelHint').textContent().catch(() => '');
    if (h.includes('na VRAM:')) { loadOk = true; break; }
  }
  const hintLoaded = await page.locator('#localModelHint').textContent().catch(() => '');
  console.log('3. carregou na VRAM: ' + loadOk + ' | ' + hintLoaded.substring(0, 90));

  // 4. Carregar de novo -> nao deve duplicar (resposta "ja estava carregado")
  await page.click('button:has-text("Carregar na VRAM")');
  await page.waitForTimeout(4000);
  const hintAgain = await page.locator('#localModelHint').textContent().catch(() => '');
  console.log('4. apos 2o clique: ' + hintAgain.substring(0, 90));

  // 5. Descarregar
  await page.click('button:has-text("Descarregar")');
  let unloadOk = false;
  for (let i = 0; i < 20; i++) {
    await page.waitForTimeout(3000);
    const h = await page.locator('#localModelHint').textContent().catch(() => '');
    if (h.includes('nenhum carregado')) { unloadOk = true; break; }
  }
  const hintFinal = await page.locator('#localModelHint').textContent().catch(() => '');
  console.log('5. descarregou: ' + unloadOk + ' | ' + hintFinal.substring(0, 90));

  console.log('JS_ERRORS=' + errors.length);
  errors.slice(0, 4).forEach(e => console.log('  ' + e));
  await browser.close();
})();
