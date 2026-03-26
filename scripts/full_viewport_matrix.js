const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1';

const viewports = [
  { name: 'iphone-se', width: 375, height: 667, group: 'phone' },
  { name: 'iphone-12', width: 390, height: 844, group: 'phone' },
  { name: 'iphone-14-pro-max', width: 430, height: 932, group: 'phone' },
  { name: 'pixel-7', width: 412, height: 915, group: 'phone' },
  { name: 'galaxy-s20', width: 360, height: 800, group: 'phone' },
  { name: 'galaxy-a51', width: 412, height: 914, group: 'phone' },
  { name: 'redmi-note', width: 393, height: 851, group: 'phone' },
  { name: 'small-android', width: 360, height: 740, group: 'phone' },
  { name: 'ipad-mini', width: 768, height: 1024, group: 'tablet' },
  { name: 'ipad-air', width: 820, height: 1180, group: 'tablet' },
  { name: 'ipad-pro-11', width: 834, height: 1194, group: 'tablet' },
  { name: 'tablet-android', width: 800, height: 1280, group: 'tablet' },
  { name: 'laptop-1366', width: 1366, height: 768, group: 'laptop' },
  { name: 'laptop-1440', width: 1440, height: 900, group: 'laptop' },
  { name: 'desktop-1600', width: 1600, height: 900, group: 'desktop' },
  { name: 'desktop-1920', width: 1920, height: 1080, group: 'desktop' },
  { name: 'tv-2560', width: 2560, height: 1440, group: 'tv' },
  { name: 'tv-3840', width: 3840, height: 2160, group: 'tv' }
];

const pages = [
  { name: 'de-home', url: `${BASE}/?cb=${Date.now()}` },
  { name: 'uk-home', url: `${BASE}/?lang=uk&cb=${Date.now() + 1}` },
  { name: 'en-home', url: `${BASE}/?lang=en&cb=${Date.now() + 2}` },
  { name: 'de-cat', url: `${BASE}/?cat=14&cb=${Date.now() + 3}` },
  { name: 'de-post', url: `${BASE}/?p=398&cb=${Date.now() + 4}` },
];

async function inspect(page) {
  return page.evaluate(() => {
    const qs = (sel) => document.querySelector(sel);
    const qsa = (sel) => Array.from(document.querySelectorAll(sel));
    const latest = qsa('.europulse-home-latest-card');
    const latestHeights = latest.map((el) => Math.round(el.getBoundingClientRect().height));
    const section = (label) => {
      const head = qsa('.europulse-block-head .wp-block-heading').find((el) => el.textContent.trim() === label);
      if (!head) return null;
      const module = head.closest('.europulse-section-module');
      if (!module) return null;
      return {
        count: qsa('a[href*="?p="], a[href*="&lang="]', module).filter((a) => a.textContent.trim()).length,
        width: Math.round(module.getBoundingClientRect().width),
      };
    };
    const heroChip = qs('.europulse-top-slide.is-active .europulse-breaking-chip, .europulse-top-slide.is-active .europulse-kicker, .europulse-top-slide.is-active .europulse-sponsored-chip');
    const heroTerms = qs('.europulse-top-slide.is-active .europulse-top-slide-terms');
    const utility = qs('.europulse-utility-bar');
    const searchInMenu = qs('.europulse-mobile-menu-search');
    const pulse = getComputedStyle(document.querySelector('[data-id="logo"] .site-title'), '::after');
    return {
      tickerExists: !!qs('.europulse-breaking-bar'),
      tickerLabelWidth: Math.round(qs('.europulse-breaking-label')?.getBoundingClientRect().width || 0),
      latestHeights,
      germany: section('Deutschland') || section('Німеччина') || section('Germany'),
      ukraine: section('Ukraine') || section('Україна'),
      community: section('Community') || section('Спільнота'),
      heroChipText: heroChip?.textContent?.trim() || '',
      heroTermsHeight: Math.round(heroTerms?.getBoundingClientRect().height || 0),
      utilityVisible: !!utility,
      searchInMenu: !!searchInMenu,
      pulseContent: pulse.content,
      pulseAnimation: pulse.animationName,
    };
  });
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const results = [];

  try {
    for (const vp of viewports) {
      const context = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
      const page = await context.newPage();

      for (const target of pages) {
        await page.goto(target.url, { waitUntil: 'networkidle' });
        results.push({
          viewport: vp,
          page: target.name,
          result: await inspect(page),
        });
      }

      await context.close();
    }
  } finally {
    await browser.close();
  }

  console.log(JSON.stringify(results, null, 2));
})();
