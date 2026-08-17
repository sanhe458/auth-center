// Screenshot all auth center pages using system chromium via puppeteer-core
const puppeteer = require('puppeteer-core');

const PAGES = {
  'login':    'http://127.0.0.1:8899/index.html',
  'register': 'http://127.0.0.1:8899/register.html',
  'oauth':    'http://127.0.0.1:8899/oauth.html',
  'console':  'http://127.0.0.1:8899/user/',
};

(async () => {
  const browser = await puppeteer.launch({
    executablePath: '/snap/bin/chromium',
    headless: 'new',
    args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 430, height: 860, deviceScaleFactor: 2 });
  page.on('pageerror', e => console.log(`[${name}] pageerror:`, e.message));

  for (const [name, url] of Object.entries(PAGES)) {
    try {
      await page.goto(url, { waitUntil: 'networkidle0', timeout: 60000 });
      await new Promise(r => setTimeout(r, 1200));
      await page.screenshot({ path: `/tmp/auth-${name}.png` });
      console.log(`saved /tmp/auth-${name}.png`);
    } catch (e) {
      console.log(`FAIL ${name}: ${e.message}`);
    }
  }
  await browser.close();
})();
