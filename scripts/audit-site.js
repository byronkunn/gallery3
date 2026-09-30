import puppeteer from 'puppeteer';
import path from 'path';

const ARTIFACT_DIR = '/Users/byron/.gemini/antigravity-ide/brain/027a948b-3642-423e-8659-52ab075431c1';
const BASE_URL = 'http://127.0.0.1:8000';

(async () => {
  console.log('🔍 Auditing site with populated SFW media...');
  const browser = await puppeteer.launch({
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 850 });

  // 1. Log in as Kira Art
  await page.goto(`${BASE_URL}/switch-user/1`, { waitUntil: 'networkidle0' });

  // 2. Gallery Feed Audit
  await page.goto(`${BASE_URL}/gallery`, { waitUntil: 'networkidle0' });
  await page.screenshot({ path: path.join(ARTIFACT_DIR, 'audit_gallery_feed.png') });
  console.log('✓ Captured audit_gallery_feed.png');

  // 3. User Profile Audit
  await page.goto(`${BASE_URL}/profile/kira_art`, { waitUntil: 'networkidle0' });
  await page.screenshot({ path: path.join(ARTIFACT_DIR, 'audit_profile_page.png') });
  console.log('✓ Captured audit_profile_page.png');

  // 4. Messages / Direct Messaging Audit
  await page.goto(`${BASE_URL}/messages`, { waitUntil: 'networkidle0' });
  await page.screenshot({ path: path.join(ARTIFACT_DIR, 'audit_messages_page.png') });
  console.log('✓ Captured audit_messages_page.png');

  // 5. Pools & Manga Index Audit
  await page.goto(`${BASE_URL}/pools`, { waitUntil: 'networkidle0' });
  await page.screenshot({ path: path.join(ARTIFACT_DIR, 'audit_pools_page.png') });
  console.log('✓ Captured audit_pools_page.png');

  await browser.close();
})();
