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

  await page.goto('http://localhost:9876/admin/ai-settings.php', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#aiProvider', { timeout: 15000 });

  // Aguarda o catalogo models.dev popular os providers (pode demorar — ~5MB)
  await page.waitForFunction(() => {
    const sel = document.getElementById('aiProvider');
    return sel && sel.options.length > 5;
  }, { timeout: 60000 }).catch(() => {});
  const providerCount = await page.locator('#aiProvider option').count();
  console.log('providers no catalogo: ' + providerCount);

  // 1. Deteccao de tipo de API por SDK
  for (const [provider, expected] of [['lmstudio', 'openai'], ['anthropic', 'anthropic'], ['azure', 'azure'], ['google', 'google'], ['groq', 'openai']]) {
    await page.selectOption('#aiProvider', provider);
    await page.waitForTimeout(500);
    const type = await page.inputValue('#aiApiType');
    const sdk = await page.locator('#apiTypeSdk').textContent().catch(() => '');
    console.log(`${provider}: api_type=${type} (esperado ${expected}) sdk="${sdk}"`);
  }

  // 2. SDK nao suportado (bedrock) -> aviso
  const bedrockOpt = page.locator('#aiProvider option').filter({ hasText: /bedrock/i }).first();
  const hasBedrock = await bedrockOpt.count() > 0;
  if (hasBedrock) {
    const value = await bedrockOpt.getAttribute('value');
    await page.selectOption('#aiProvider', value);
    await page.waitForTimeout(500);
    console.log('bedrock (' + value + ') hint: ' + (await page.locator('#apiTypeHint').textContent().catch(() => '')).substring(0, 90));
  } else {
    console.log('bedrock: (nao esta no catalogo carregado)');
  }

  // 3. URL local nao e convertida (fica 127.0.0.1)
  await page.selectOption('#aiProvider', 'lmstudio');
  await page.waitForTimeout(600);
  const baseUrl = await page.inputValue('#aiBaseUrl');
  const hint = await page.locator('#aiBaseUrlHint').textContent().catch(() => '');
  console.log('lmstudio base_url="' + baseUrl + '"');
  console.log('hint: ' + hint.substring(0, 90));

  // 4. Salvar config LM Studio sem chave
  await page.fill('#aiBaseUrl', 'http://127.0.0.1:1234/v1');
  await page.fill('#aiLocalTtl', '60');
  await page.fill('#aiApiKey', '');
  // modelo: preenche manualmente se nao houver opcao
  const modelOptions = await page.locator('#aiModel option').allTextContents();
  const pick = modelOptions.find(m => m.includes('qwen3.5-9b-deepseek-v4-flash')) || modelOptions[0];
  if (pick) await page.selectOption('#aiModel', { label: pick });
  console.log('modelo selecionado: ' + pick);
  await page.click('button:has-text("Salvar")');
  await page.waitForTimeout(1500);

  // 5. Testar conexao (deve conectar no LM Studio via fallback)
  await page.click('button:has-text("Testar conexão")');
  let testMsg = '';
  for (let i = 0; i < 30; i++) {
    await page.waitForTimeout(3000);
    const toast = await page.locator('.toast, [class*=toast], [class*=alert]').allTextContents().catch(() => []);
    testMsg = (toast || []).join(' | ');
    if (testMsg && (testMsg.includes('Conexão') || testMsg.includes('OK') || testMsg.includes('Falha') || testMsg.includes('erro'))) break;
  }
  console.log('teste de conexao: ' + testMsg.substring(0, 160));

  console.log('JS_ERRORS=' + errors.length);
  errors.slice(0, 4).forEach(e => console.log('  ' + e));
  await browser.close();
})();
