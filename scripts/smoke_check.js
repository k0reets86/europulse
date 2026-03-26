const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1';

const cases = [
  { name: 'de-home-desktop', url: `${BASE}/?cb=${Date.now()}`, viewport: { width: 1366, height: 900 }, lang: 'de' },
  { name: 'de-home-mobile', url: `${BASE}/?cb=${Date.now() + 1}`, viewport: { width: 390, height: 844 }, lang: 'de' },
  { name: 'uk-home-mobile', url: `${BASE}/?lang=uk&cb=${Date.now() + 2}`, viewport: { width: 390, height: 844 }, lang: 'uk' },
  { name: 'en-home-desktop', url: `${BASE}/?lang=en&cb=${Date.now() + 3}`, viewport: { width: 1366, height: 900 }, lang: 'en' },
  { name: 'de-post-desktop', url: `${BASE}/?p=398&cb=${Date.now() + 4}`, viewport: { width: 1366, height: 900 }, lang: 'de' }
];

function fail(message) {
  throw new Error(message);
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const report = [];

  try {
    for (const c of cases) {
      const page = await browser.newPage({ viewport: c.viewport });
      await page.goto(c.url, { waitUntil: 'networkidle' });

      const result = await page.evaluate(({ lang }) => {
        const title = document.title;
        const ticker = document.querySelector('.europulse-breaking-bar');
        const latestCards = [...document.querySelectorAll('.europulse-home-latest-card')];
        const latestHeights = latestCards.map(el => Math.round(el.getBoundingClientRect().height));
        const sections = [...document.querySelectorAll('.europulse-section-module')].map(el => ({
          title: el.querySelector('h2')?.textContent?.trim() || '',
          links: [...el.querySelectorAll('.wp-block-post-title a')].map(a => a.textContent.trim()),
          hrefs: [...el.querySelectorAll('.wp-block-post-title a')].map(a => a.getAttribute('href') || ''),
          text: (el.textContent || '').trim()
        }));
        const backButton = document.querySelector('[data-europulse-back-to-top]');
        return {
          title,
          tickerExists: !!ticker,
          latestCount: latestCards.length,
          latestHeights,
          sections,
          backButtonExists: !!backButton,
          htmlLang: document.documentElement.lang || '',
          bodyClass: document.body.className,
          lang
        };
      }, { lang: c.lang });

      if (!result.tickerExists) fail(`${c.name}: missing breaking ticker`);
      if (c.url.includes('?p=398')) {
        if (!result.backButtonExists) fail(`${c.name}: missing back-to-top button`);
      } else {
        if (result.latestCount < 4) fail(`${c.name}: too few latest cards`);
        const uniqueHeights = [...new Set(result.latestHeights)];
        if (uniqueHeights.length > 1) fail(`${c.name}: latest cards uneven heights ${uniqueHeights.join(',')}`);
        const neededSections = c.lang === 'de' ? ['Deutschland', 'Ukraine'] : [];
        for (const name of neededSections) {
          const section = result.sections.find(s => s.title === name);
          if (!section) fail(`${c.name}: missing section ${name}`);
          if (section.links.length === 0) {
            const hasValidEmptyState = /Noch keine Artikel|Поки що немає матеріалів|No articles in/i.test(section.text);
            if (!hasValidEmptyState) fail(`${c.name}: empty section ${name}`);
          }
          if (section.links.some(t => t === 'Startseite')) fail(`${c.name}: bad placeholder link text in ${name}`);
          if (section.hrefs.some(h => /(?:^|\/)\??$/.test(h) || h === '/' || /Startseite/.test(h))) {
            fail(`${c.name}: suspicious section href in ${name}`);
          }
        }
      }

      report.push({ case: c.name, ok: true, summary: result });
      await page.close();
    }

    console.log(JSON.stringify(report, null, 2));
  } finally {
    await browser.close();
  }
})();
