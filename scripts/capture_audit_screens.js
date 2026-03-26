const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1';
const OUT_DIR = '/root/projects/europulse/qa-reports/screens-2026-03-18';

const CASES = [
  { name: 'de-home-desktop', url: `${BASE}/?cb=${Date.now()}`, viewport: { width: 1440, height: 2200 } },
  { name: 'de-home-mobile', url: `${BASE}/?cb=${Date.now() + 1}`, viewport: { width: 390, height: 1800 } },
  { name: 'uk-home-mobile', url: `${BASE}/?lang=uk&cb=${Date.now() + 2}`, viewport: { width: 390, height: 1800 } },
  { name: 'en-home-desktop', url: `${BASE}/?lang=en&cb=${Date.now() + 3}`, viewport: { width: 1440, height: 2200 } },
  { name: 'de-newsroom', url: `${BASE}/?page_id=28&cb=${Date.now() + 4}`, viewport: { width: 1440, height: 2200 } },
  { name: 'de-category-germany', url: `${BASE}/?cat=14&cb=${Date.now() + 5}`, viewport: { width: 1440, height: 2200 } },
  { name: 'de-post-latest', url: `${BASE}/?p=2087&cb=${Date.now() + 6}`, viewport: { width: 1440, height: 2200 } },
];

async function ensureDir(dir) {
  await fs.promises.mkdir(dir, { recursive: true });
}

async function main() {
  await ensureDir(OUT_DIR);
  const browser = await chromium.launch({ headless: true });
  const report = [];

  try {
    for (const test of CASES) {
      const context = await browser.newContext({ viewport: test.viewport });
      const page = await context.newPage();
      await page.goto(test.url, { waitUntil: 'networkidle' });
      await page.screenshot({
        path: path.join(OUT_DIR, `${test.name}.png`),
        fullPage: true,
      });

      const meta = await page.evaluate(() => ({
        title: document.title,
        lang: document.documentElement.lang || '',
        bodyClass: document.body.className,
        h1: document.querySelector('h1')?.textContent?.trim() || '',
      }));

      report.push({
        name: test.name,
        url: test.url,
        viewport: test.viewport,
        screenshot: path.join(OUT_DIR, `${test.name}.png`),
        meta,
      });
      await context.close();
    }
  } finally {
    await browser.close();
  }

  process.stdout.write(JSON.stringify(report, null, 2));
}

main().catch((error) => {
  console.error(error.stack || error.message || String(error));
  process.exit(1);
});
