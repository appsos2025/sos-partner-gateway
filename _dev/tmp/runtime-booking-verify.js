const fs = require('fs');
const crypto = require('crypto');
const { chromium } = require('playwright-core');

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function clickFirstByTexts(page, selectors, texts) {
  for (const selector of selectors) {
    const locator = page.locator(selector);
    const count = await locator.count();
    for (let i = 0; i < count; i += 1) {
      const item = locator.nth(i);
      const text = ((await item.textContent()) || '').trim();
      if (texts.some((value) => text.includes(value))) {
        await item.click({ force: true });
        return text;
      }
    }
  }
  return '';
}

async function acceptCookiesIfPresent(page) {
  const candidates = [
    page.getByRole('button', { name: /accetta tutti/i }),
    page.locator('button:has-text("Accetta tutti")'),
    page.locator('[id*="CybotCookiebotDialogBodyLevelButtonLevelOptinAllowAll"]')
  ];

  for (const locator of candidates) {
    if (await locator.count()) {
      await locator.first().click({ force: true });
      await page.waitForTimeout(1000);
      return true;
    }
  }

  return false;
}

async function clickFirstAvailableDay(page) {
  const selectors = [
    '[data-date]:not(.is-off):not(.is-past):not(.is-booked-full)',
    '.os-monthly-calendar-days-w [data-date]:not(.is-off):not(.is-past):not(.is-booked-full)',
    '.latepoint-calendar-w [data-date]:not(.is-off):not(.is-past):not(.is-booked-full)'
  ];
  for (const selector of selectors) {
    const locator = page.locator(selector);
    const count = await locator.count();
    for (let i = 0; i < count; i += 1) {
      const item = locator.nth(i);
      const disabled = await item.getAttribute('disabled');
      if (disabled !== null) continue;
      const date = (await item.getAttribute('data-date')) || '';
      await item.click({ force: true });
      return date;
    }
  }
  const bodySnippet = ((await page.locator('body').innerText()) || '').slice(0, 4000);
  const htmlSnippet = ((await page.locator('body').innerHTML()) || '').slice(0, 6000);
  throw new Error(`No available day found | url=${page.url()} | body=${bodySnippet} | html=${htmlSnippet}`);
}

async function clickFirstAvailableSlot(page) {
  const selectors = [
    '.dp-timepicker-trigger.dp-timebox',
    '.os-time-slot',
    '[data-slot-start]',
    'a',
    'button',
    'div',
    'span'
  ];
  for (const selector of selectors) {
    const locator = page.locator(selector);
    const count = await locator.count();
    for (let i = 0; i < count; i += 1) {
      const item = locator.nth(i);
      if (!(await item.isVisible().catch(() => false))) {
        continue;
      }
      const text = ((await item.textContent()) || '').trim();
      if (!/^\d{2}:\d{2}$/.test(text)) {
        continue;
      }
      const className = (await item.getAttribute('class')) || '';
      if (/booked|unavailable|disabled/i.test(className)) {
        continue;
      }
      await item.click({ force: true });
      await page.waitForTimeout(700);
      const summaryText = ((await page.locator('body').innerText()) || '').slice(0, 3000);
      if (summaryText.includes(text)) {
        return text;
      }
    }
  }
  const bodySnippet = ((await page.locator('body').innerText()) || '').slice(0, 4000);
  const htmlSnippet = ((await page.locator('body').innerHTML()) || '').slice(0, 6000);
  throw new Error(`No available slot found | url=${page.url()} | body=${bodySnippet} | html=${htmlSnippet}`);
}

async function selectFirstAvailableDayAndSlot(page) {
  const daySelectors = [
    '[data-date]:not(.is-off):not(.is-past):not(.is-booked-full)',
    '.os-monthly-calendar-days-w [data-date]:not(.is-off):not(.is-past):not(.is-booked-full)',
    '.latepoint-calendar-w [data-date]:not(.is-off):not(.is-past):not(.is-booked-full)'
  ];

  for (const selector of daySelectors) {
    const locator = page.locator(selector);
    const count = await locator.count();
    for (let i = 0; i < count; i += 1) {
      const item = locator.nth(i);
      const disabled = await item.getAttribute('disabled');
      if (disabled !== null) continue;
      const date = (await item.getAttribute('data-date')) || '';
      await item.click({ force: true });
      await page.waitForTimeout(1200);

      try {
        const slot = await clickFirstAvailableSlot(page);
        return { date, slot };
      } catch (error) {
        // Continue scanning the next available day until a real slot exists.
      }
    }
  }

  const date = await clickFirstAvailableDay(page);
  const slot = await clickFirstAvailableSlot(page);
  return { date, slot };
}

async function clickNext(page) {
  const selectors = [
    '.latepoint-next-btn',
    'button[type="submit"]',
    'input[type="submit"]'
  ];
  for (const selector of selectors) {
    const locator = page.locator(selector);
    const count = await locator.count();
    for (let i = 0; i < count; i += 1) {
      const item = locator.nth(i);
      if (!(await item.isVisible().catch(() => false))) {
        continue;
      }
      const className = (await item.getAttribute('class')) || '';
      const disabledAttr = await item.getAttribute('disabled');
      if (disabledAttr !== null || /\bdisabled\b/i.test(className)) {
        continue;
      }
      const text = (((await item.textContent()) || '') + ' ' + ((await item.getAttribute('value')) || '')).trim();
      if (!text || /continua|next|avanti|invia|confirm|prenota/i.test(text)) {
        await item.click({ force: true });
        return text;
      }
    }
  }
  const bodySnippet = ((await page.locator('body').innerText()) || '').slice(0, 4000);
  throw new Error(`Next button not found | url=${page.url()} | body=${bodySnippet}`);
}

(async () => {
  const privateKeyPem = fs.readFileSync('c:/Users/vbass/Documents/sos-partner-gateway/_dev/keys/private.pem', 'utf8');
  const email = `copilot.deploy.verify.${Date.now()}@example.com`;
  const partnerId = 'caf';
  const timestamp = Math.floor(Date.now() / 1000);
  const nonce = crypto.randomBytes(8).toString('hex');
  const message = `${partnerId}|${email}|${timestamp}|${nonce}`;
  const signature = crypto.sign('sha256', Buffer.from(message, 'utf8'), privateKeyPem).toString('base64');

  const browser = await chromium.launch({
    headless: true,
    executablePath: 'C:/Users/vbass/AppData/Local/ms-playwright/chromium-1217/chrome-win64/chrome.exe'
  });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1600 } });

  try {
    await page.setContent(`<!DOCTYPE html><html><body>
      <form id="login" action="https://videoconsulto.sospediatra.org/partner-login/" method="POST">
        <input type="hidden" name="partner_id" value="${partnerId}">
        <input type="hidden" name="payload" value="${email}">
        <input type="hidden" name="timestamp" value="${timestamp}">
        <input type="hidden" name="nonce" value="${nonce}">
        <input type="hidden" name="signature" value="${signature}">
      </form>
      <script>document.getElementById('login').submit();</script>
    </body></html>`);

    await page.waitForLoadState('networkidle', { timeout: 120000 });
    await page.waitForTimeout(2000);
    await acceptCookiesIfPresent(page);

    await page.goto('https://videoconsulto.sospediatra.org/prenota-un-videoconsulto/', { waitUntil: 'domcontentloaded', timeout: 120000 });
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(2000);
    await acceptCookiesIfPresent(page);

    await clickFirstByTexts(page, ['.os-item', '.latepoint-service-selector .item', '[data-service-id]'], ['Teleconsulto']);
    await page.waitForTimeout(1000);
    await clickFirstByTexts(page, ['.os-item', '.latepoint-agent-selector .item', '[data-agent-id]'], ['Medico SOSPediatra']);
    await page.waitForTimeout(1000);

    const selection = await selectFirstAvailableDayAndSlot(page);
    const selectedDate = selection.date;
    const selectedSlot = selection.slot;
    await page.waitForTimeout(1000);

    await clickNext(page);
    await page.waitForTimeout(1500);

    const firstName = page.locator('input[name*="first_name"], input[id*="first_name"]').first();
    const lastName = page.locator('input[name*="last_name"], input[id*="last_name"]').first();
    const emailInput = page.locator('input[type="email"], input[name*="email"], input[id*="email"]').first();
    const phoneInput = page.locator('input[type="tel"], input[name*="phone"], input[id*="phone"]').first();

    if (await firstName.count()) await firstName.fill('Copilot');
    if (await lastName.count()) await lastName.fill('Deploy');
    if (await emailInput.count()) await emailInput.fill(email);
    if (await phoneInput.count()) await phoneInput.fill('3331234567');

    await clickNext(page);
    await page.waitForTimeout(2000);
    await clickNext(page);

    await page.waitForFunction(() => document.body.innerText.includes('Prenotazione Completata') || document.body.innerText.includes('ORDER #'), {}, { timeout: 120000 });

    const bodyText = await page.locator('body').innerText();
    const orderMatch = bodyText.match(/ORDER\s*#\s*([A-Z0-9]+)/i);

    console.log(JSON.stringify({
      ok: true,
      email,
      selectedDate,
      selectedSlot,
      order: orderMatch ? orderMatch[1] : '',
      url: page.url(),
      state: await page.locator('body').getAttribute('class')
    }, null, 2));
  } catch (error) {
    console.error(JSON.stringify({ ok: false, email, error: String(error && error.stack ? error.stack : error) }, null, 2));
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
})();
