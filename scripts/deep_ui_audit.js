const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1';

const CASES = [
  {
    name: 'de-home-desktop',
    url: `${BASE}/?cb=${Date.now()}`,
    viewport: { width: 1366, height: 900 },
    lang: 'de',
    sections: ['Deutschland', 'Ukraine'],
  },
  {
    name: 'de-home-mobile',
    url: `${BASE}/?cb=${Date.now() + 1}`,
    viewport: { width: 390, height: 844 },
    lang: 'de',
    sections: ['Deutschland', 'Ukraine'],
  },
  {
    name: 'uk-home-mobile',
    url: `${BASE}/?lang=uk&cb=${Date.now() + 2}`,
    viewport: { width: 390, height: 844 },
    lang: 'uk',
    sections: ['Німеччина', 'Україна'],
  },
  {
    name: 'en-home-desktop',
    url: `${BASE}/?lang=en&cb=${Date.now() + 3}`,
    viewport: { width: 1366, height: 900 },
    lang: 'en',
    sections: ['Germany', 'Ukraine'],
  },
  {
    name: 'de-post-desktop',
    url: `${BASE}/?p=398&cb=${Date.now() + 4}`,
    viewport: { width: 1366, height: 900 },
    lang: 'de',
    sections: [],
  },
];

function fail(message) {
  throw new Error(message);
}

async function auditPage(page, testCase) {
  await page.goto(testCase.url, { waitUntil: 'networkidle' });

  return page.evaluate(({ sectionTitles }) => {
    const rect = (el) => {
      if (!el) {
        return null;
      }

      const r = el.getBoundingClientRect();
      return {
        top: r.top,
        right: r.right,
        bottom: r.bottom,
        left: r.left,
        width: r.width,
        height: r.height,
      };
    };

    const overlap = (a, b) => {
      if (!a || !b) {
        return false;
      }

      return !(
        a.left >= b.right ||
        a.right <= b.left ||
        a.top >= b.bottom ||
        a.bottom <= b.top
      );
    };

    const slide = document.querySelector('.europulse-top-slide.is-active, .europulse-top-slide');
    const badge = slide?.querySelector('.europulse-top-slide-badge');
    const meta = slide?.querySelector('.europulse-top-slide-meta');
    const chip = slide?.querySelector('.europulse-top-slide-badge .europulse-breaking-chip, .europulse-top-slide-badge .europulse-kicker, .europulse-top-slide-badge .europulse-sponsored-chip');
    const terms = slide?.querySelector('.europulse-top-slide-terms');
    const latestCards = [...document.querySelectorAll('.europulse-home-latest-card')];
    const latestHeights = latestCards.map((el) => Math.round(el.getBoundingClientRect().height));
    const logo = document.querySelector('[data-id="logo"] .site-title');
    const logoBefore = logo ? getComputedStyle(logo, '::before') : null;
    const ticker = document.querySelector('.europulse-breaking-marquee');

    const sections = sectionTitles.map((title) => {
      const section = [...document.querySelectorAll('.europulse-section-module')]
        .find((el) => el.querySelector('h2')?.textContent?.trim() === title);
      const links = section ? [...section.querySelectorAll('.wp-block-post-title a')] : [];
      const leadImage = section?.querySelector('.europulse-section-lead .wp-block-post-featured-image, .europulse-section-lead img');
      const list = section?.querySelector('.europulse-section-list');

      return {
        title,
        exists: !!section,
        count: links.length,
        titles: links.map((a) => a.textContent.trim()),
        hrefs: links.map((a) => a.getAttribute('href') || ''),
        leadWidth: leadImage ? Math.round(leadImage.getBoundingClientRect().width) : null,
        listWidth: list ? Math.round(list.getBoundingClientRect().width) : null,
      };
    });

    return {
      tickerExists: !!ticker,
      tickerDuration: ticker ? getComputedStyle(ticker).animationDuration : null,
      latestCount: latestCards.length,
      latestHeights,
      searchMobile: !!document.querySelector('.europulse-mobile-menu-search'),
      backToTop: !!document.querySelector('[data-europulse-back-to-top]'),
      chipOverlap: overlap(rect(chip), rect(meta)),
      chipRightGap: badge && chip ? Math.round(rect(badge).right - rect(chip).right) : null,
      chipText: chip?.textContent?.trim() || '',
      termsText: terms?.textContent?.trim() || '',
      termsHeight: terms ? Math.round(terms.getBoundingClientRect().height) : null,
      logoBeforeContent: logoBefore?.content || '',
      logoBeforeDisplay: logoBefore?.display || '',
      sections,
    };
  }, { sectionTitles: testCase.sections });
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const report = [];

  try {
    for (const testCase of CASES) {
      const page = await browser.newPage({ viewport: testCase.viewport });
      const result = await auditPage(page, testCase);

      if (!result.tickerExists) {
        fail(`${testCase.name}: missing ticker`);
      }

      if (testCase.name !== 'de-post-desktop') {
        if (result.latestCount < 4) {
          fail(`${testCase.name}: too few latest cards`);
        }

        const uniqueLatestHeights = [...new Set(result.latestHeights)];
        if (uniqueLatestHeights.length > 1) {
          fail(`${testCase.name}: uneven latest heights ${uniqueLatestHeights.join(',')}`);
        }

        for (const section of result.sections) {
          if (!section.exists) {
            fail(`${testCase.name}: missing section ${section.title}`);
          }

          if (section.count === 0) {
            fail(`${testCase.name}: empty section ${section.title}`);
          }

          if (section.titles.some((title) => title === 'Startseite' || title === 'Home Page')) {
            fail(`${testCase.name}: placeholder title in ${section.title}`);
          }

          if (section.hrefs.some((href) => href === '/' || href === '' || href === '#')) {
            fail(`${testCase.name}: suspicious href in ${section.title}`);
          }
        }
      }

      if (result.chipOverlap) {
        fail(`${testCase.name}: hero chip overlaps meta`);
      }

      if (result.logoBeforeContent !== 'none' || result.logoBeforeDisplay !== 'none') {
        fail(`${testCase.name}: logo ::before still active`);
      }

      if (testCase.viewport.width <= 480 && !result.searchMobile) {
        fail(`${testCase.name}: missing mobile menu search`);
      }

      report.push({ case: testCase.name, ok: true, result });
      await page.close();
    }

    console.log(JSON.stringify(report, null, 2));
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.stack || error.message || String(error));
  process.exit(1);
});
