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
  await page.waitForTimeout(3000); // aguarda o catalogo models.dev

  // 1. lmstudio (esta no catalogo: http://127.0.0.1:1234/v1)
  await page.selectOption('#aiProvider', 'lmstudio');
  await page.waitForTimeout(600);
  console.log('1. lmstudio -> value="' + await page.inputValue('#aiBaseUrl') + '" placeholder="' + await page.getAttribute('#aiBaseUrl', 'placeholder') + '"');

  // 2. openai (api vazio no catalogo -> fallback local)
  await page.selectOption('#aiProvider', 'openai');
  await page.waitForTimeout(600);
  console.log('2. openai -> value="' + await page.inputValue('#aiBaseUrl') + '"');

  // 3. groq (fallback)
  await page.selectOption('#aiProvider', 'groq');
  await page.waitForTimeout(600);
  console.log('3. groq -> value="' + await page.inputValue('#aiBaseUrl') + '"');

  // 4. valor manual nao e sobrescrito
  await page.fill('#aiBaseUrl', 'https://meu-proxy-interno.local/v1');
  await page.selectOption('#aiProvider', 'deepseek');
  await page.waitForTimeout(600);
  console.log('4. manual mantido -> value="' + await page.inputValue('#aiBaseUrl') + '"');

  // 5. volta para uma sugestao (limpa o manual e troca)
  await page.selectOption('#aiProvider', 'lmstudio');
  await page.waitForTimeout(600);
  await page.fill('#aiBaseUrl', '');
  await page.selectOption('#aiProvider', 'openai');
  await page.waitForTimeout(600);
  console.log('5. vazio -> openai preenche: "' + await page.inputValue('#aiBaseUrl') + '"');

  // 6. cloudflare (sem base url)
  await page.selectOption('#aiProvider', 'cloudflare');
  await page.waitForTimeout(600);
  console.log('6. cloudflare -> value="' + await page.inputValue('#aiBaseUrl') + '" (deve limpar a sugestao)');

  // 6b. hint de URL local (Docker) ao digitar 127.0.0.1
  await page.fill('#aiBaseUrl', 'http://127.0.0.1:1234/v1');
  await page.waitForTimeout(500);
  const hintVisible = await page.locator('#aiBaseUrlHint').isVisible();
  const hintText = await page.locator('#aiBaseUrlHint').textContent().catch(() => '');
  console.log('6b. hint local visivel=' + hintVisible + ' texto="' + (hintText || '').substring(0, 70) + '"');

  // 7. STT: deepgram (fallback local)
  await page.click('.tab-btn[data-tab="stt"]');
  await page.waitForTimeout(500);
  await page.selectOption('#sttProvider', 'deepgram');
  await page.waitForTimeout(600);
  console.log('7. stt deepgram -> value="' + await page.inputValue('#sttBaseUrl') + '"');

  // 8. TTS: elevenlabs (fallback local)
  await page.click('.tab-btn[data-tab="tts"]');
  await page.waitForTimeout(500);
  await page.selectOption('#ttsProvider', 'elevenlabs');
  await page.waitForTimeout(600);
  console.log('8. tts elevenlabs -> value="' + await page.inputValue('#ttsBaseUrl') + '"');

  await page.screenshot({ path: 'baseurl-suggest.png' });
  console.log('JS_ERRORS=' + errors.length);
  errors.slice(0, 4).forEach(e => console.log('  ' + e));
  await browser.close();
})();
