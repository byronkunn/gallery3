import puppeteer from 'puppeteer';
import path from 'path';

const ARTIFACT_DIR = '/Users/byron/.gemini/antigravity-ide/brain/027a948b-3642-423e-8659-52ab075431c1';
const BASE_URL = 'http://127.0.0.1:8000';

(async () => {
  const browser = await puppeteer.launch({
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  // 1. Desktop View (1280x800) logged in as Admin (Kira)
  const pageDesktop = await browser.newPage();
  await pageDesktop.setViewport({ width: 1280, height: 800 });
  await pageDesktop.goto(`${BASE_URL}/switch-user/1`, { waitUntil: 'domcontentloaded' });
  await new Promise(r => setTimeout(r, 500));
  await pageDesktop.goto(`${BASE_URL}/gallery`, { waitUntil: 'domcontentloaded' });
  await new Promise(r => setTimeout(r, 500));
  await pageDesktop.screenshot({ path: path.join(ARTIFACT_DIR, 'admin_nav_desktop.png') });
  console.log('✓ Captured admin_nav_desktop.png');

  // 2. Mobile View (390x844) logged in as Admin (Kira)
  const pageMobile = await browser.newPage();
  await pageMobile.setViewport({ width: 390, height: 844 });
  await pageMobile.goto(`${BASE_URL}/switch-user/1`, { waitUntil: 'domcontentloaded' });
  await new Promise(r => setTimeout(r, 500));
  await pageMobile.goto(`${BASE_URL}/gallery`, { waitUntil: 'domcontentloaded' });
  await new Promise(r => setTimeout(r, 500));

  // Open mobile profile slide-up modal
  const clickedProfile = await pageMobile.evaluate(() => {
    const btns = Array.from(document.querySelectorAll('nav button'));
    if (btns.length > 0) {
      btns[btns.length - 1].click();
      return true;
    }
    return false;
  });

  if (clickedProfile) {
    await new Promise(r => setTimeout(r, 600));
    await pageMobile.screenshot({ path: path.join(ARTIFACT_DIR, 'admin_nav_mobile_modal.png') });
    console.log('✓ Captured admin_nav_mobile_modal.png');
  }

  await browser.close();
})();
