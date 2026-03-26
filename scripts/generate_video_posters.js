const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const targets = [
  {
    postId: 398,
    src: 'https://storage.googleapis.com/gtv-videos-bucket/sample/ForBiggerEscapes.mp4',
    out: '/var/www/europulse/public/wp-content/uploads/2026/03/europulse-video-poster-398.png',
  },
  {
    postId: 399,
    src: 'https://storage.googleapis.com/gtv-videos-bucket/sample/ForBiggerEscapes.mp4',
    out: '/var/www/europulse/public/wp-content/uploads/2026/03/europulse-video-poster-399.png',
  },
  {
    postId: 400,
    src: 'https://storage.googleapis.com/gtv-videos-bucket/sample/ForBiggerEscapes.mp4',
    out: '/var/www/europulse/public/wp-content/uploads/2026/03/europulse-video-poster-400.png',
  },
];

async function capturePoster(page, src, out) {
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.setContent(`
    <html>
      <body style="margin:0;background:#111;display:grid;place-items:center;height:100vh">
        <video id="v" src="${src}" crossorigin="anonymous" muted playsinline style="width:960px;height:540px;object-fit:cover;background:#111"></video>
      </body>
    </html>
  `);

  await page.evaluate(async () => {
    const video = document.getElementById('v');
    if (!video) throw new Error('Video element not found');

    await new Promise((resolve, reject) => {
      const onLoaded = () => resolve();
      const onError = () => reject(video.error || new Error('Video load error'));
      video.addEventListener('loadedmetadata', onLoaded, { once: true });
      video.addEventListener('error', onError, { once: true });
      video.load();
    });

    const targetTime = Math.min(Math.max(1.25, (video.duration || 3) / 4), Math.max((video.duration || 3) - 0.25, 0.25));

    await new Promise((resolve, reject) => {
      const onSeek = () => resolve();
      const onError = () => reject(video.error || new Error('Video seek error'));
      video.addEventListener('seeked', onSeek, { once: true });
      video.addEventListener('error', onError, { once: true });
      video.currentTime = targetTime;
    });
  });

  const video = page.locator('#v');
  await video.screenshot({ path: out });
}

async function main() {
  const browser = await chromium.launch({
    headless: true,
    executablePath: '/root/projects/europulse/tmp-playwright/chromium-1208/chrome-linux64/chrome',
  });
  const page = await browser.newPage();

  for (const target of targets) {
    fs.mkdirSync(path.dirname(target.out), { recursive: true });
    await capturePoster(page, target.src, target.out);
    console.log(`saved ${target.out}`);
  }

  await browser.close();
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
