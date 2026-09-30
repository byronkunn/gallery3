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

  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 850 });

  // 1. Log in as Demo User 2 so we are not viewing ourselves
  await page.goto(`${BASE_URL}/switch-user/2`, { waitUntil: 'networkidle0' });

  // 2. Visit Kira Art's Profile (Commission Status Open)
  await page.goto(`${BASE_URL}/profile/kira_art`, { waitUntil: 'networkidle0' });

  const clickedComm = await page.evaluate(() => {
    const btns = Array.from(document.querySelectorAll('button'));
    const btn = btns.find(b => b.textContent.includes('Request Commission'));
    if (btn) {
      btn.click();
      return true;
    }
    return false;
  });

  if (clickedComm) {
    await new Promise(r => setTimeout(r, 600));
    await page.screenshot({ path: path.join(ARTIFACT_DIR, 'commission_inquiry_modal.png') });
    console.log('✓ Captured commission_inquiry_modal.png');
  } else {
    console.error('Commission button not found');
  }

  // 3. Visit Upload Page and toggle Batch Mode
  await page.goto(`${BASE_URL}/upload`, { waitUntil: 'networkidle0' });

  const clickedBatch = await page.evaluate(() => {
    const btns = Array.from(document.querySelectorAll('button'));
    const btn = btns.find(b => b.textContent.includes('Batch Multi-Post Uploader'));
    if (btn) {
      btn.click();
      return true;
    }
    return false;
  });

  if (clickedBatch) {
    await new Promise(r => setTimeout(r, 600));
    await page.screenshot({ path: path.join(ARTIFACT_DIR, 'batch_uploader_mode.png') });
    console.log('✓ Captured batch_uploader_mode.png');
  }

  await browser.close();
})();
