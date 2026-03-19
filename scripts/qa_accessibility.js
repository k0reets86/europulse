const { chromium } = require('playwright');
const AxeBuilder = require('@axe-core/playwright').default;

const cases = [
  { name: 'de-home-desktop', url: 'http://127.0.0.1/?cb=' + Date.now(), viewport: { width: 1366, height: 900 } },
  { name: 'de-home-mobile', url: 'http://127.0.0.1/?cb=' + (Date.now() + 1), viewport: { width: 390, height: 844 } },
  { name: 'uk-home-mobile', url: 'http://127.0.0.1/?lang=uk&cb=' + (Date.now() + 2), viewport: { width: 390, height: 844 } },
  { name: 'en-home-desktop', url: 'http://127.0.0.1/?lang=en&cb=' + (Date.now() + 3), viewport: { width: 1366, height: 900 } },
  { name: 'de-post-desktop', url: 'http://127.0.0.1/?p=398&cb=' + (Date.now() + 4), viewport: { width: 1366, height: 900 } }
];

(async () => {
  const browser = await chromium.launch({ headless: true });
  const report = [];

  try {
    for (const c of cases) {
      const context = await browser.newContext({ viewport: c.viewport });
      const page = await context.newPage();
      await page.goto(c.url, { waitUntil: 'networkidle' });
      const axe = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .analyze();

      report.push({
        case: c.name,
        violations: axe.violations.map(v => ({
          id: v.id,
          impact: v.impact,
          description: v.description,
          help: v.help,
          nodes: v.nodes.length
        }))
      });

      await page.close();
      await context.close();
    }

    console.log(JSON.stringify(report, null, 2));
  } finally {
    await browser.close();
  }
})();
