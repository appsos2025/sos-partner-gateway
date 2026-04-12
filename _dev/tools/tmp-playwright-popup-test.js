const fs = require('fs');
const crypto = require('crypto');
const { chromium } = require('playwright');

(async() => {
  const key = fs.readFileSync('_dev/keys/private.pem', 'utf8');
  const partnerId = 'caf';
  const payloadEmail = 'copilot.runtime.test@example.com';
  const timestamp = Math.floor(Date.now()/1000);
  const nonce = crypto.randomBytes(6).toString('hex');
  const message = `${partnerId}|${payloadEmail}|${timestamp}|${nonce}`;
  const sign = crypto.createSign('SHA256');
  sign.update(message);
  sign.end();
  const signature = sign.sign(key).toString('base64');
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  page.on('console', msg => console.log('OPENER_CONSOLE', msg.type(), msg.text()));
  await page.goto('http://127.0.0.1:8123/partner-return', { waitUntil: 'domcontentloaded' });
  const popupPromise = page.waitForEvent('popup');
  await page.evaluate((data) => {
    const targetName = 'popup_' + Date.now();
    window.open('', targetName, 'popup=yes,width=1280,height=900,resizable=yes,scrollbars=yes');
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'https://videoconsulto.sospediatra.org/partner-login/';
    form.target = targetName;
    for (const [key, value] of Object.entries(data)) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = key;
      input.value = String(value);
      form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
  }, {
    partner_id: partnerId,
    payload: payloadEmail,
    timestamp: String(timestamp),
    nonce,
    signature,
    return_url: 'http://127.0.0.1:8123/partner-return',
    opener_origin: 'http://127.0.0.1:8123',
    sos_pg_flow_context: 'partner_wordpress_popup'
  });
  const popup = await popupPromise;
  popup.on('console', msg => console.log('POPUP_CONSOLE', msg.type(), msg.text()));
  await popup.waitForLoadState('domcontentloaded', { timeout: 30000 }).catch(() => {});
  await popup.waitForTimeout(5000);
  console.log('POPUP_URL', popup.url());
  console.log('POPUP_TITLE', await popup.title().catch(() => ''));
  const text = await popup.locator('body').innerText().catch(() => '');
  console.log('POPUP_TEXT_START', text.slice(0, 4000));
  await browser.close();
})().catch(error => {
  console.error('SCRIPT_ERROR', error && error.stack ? error.stack : String(error));
  process.exit(1);
});
