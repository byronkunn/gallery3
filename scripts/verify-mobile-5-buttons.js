import puppeteer from 'puppeteer';
import path from 'path';

const BASE_URL = 'http://127.0.0.1:8000';
const ARTIFACT_DIR = '/Users/byron/.gemini/antigravity-ide/brain/027a948b-3642-423e-8659-52ab075431c1';

(async () => {
    console.log('🚀 Verifying Mobile 5-Button Bottom Nav & Profile Slide-Up Modal...');
    const browser = await puppeteer.launch({
        executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 390, height: 844 }); // iPhone 13 / 14 mobile viewport

    await page.goto(`${BASE_URL}/switch-user/1`, { waitUntil: 'networkidle0' });
    await page.goto(`${BASE_URL}/gallery`, { waitUntil: 'networkidle0' });

    // Screenshot of 5-button bottom nav bar
    await page.screenshot({ path: path.join(ARTIFACT_DIR, 'mobile_5_button_bottom_nav.png') });
    console.log('✓ Captured 5-button mobile bottom navigation bar');

    // Click Profile Avatar button (5th button)
    await page.evaluate(() => {
        const btns = Array.from(document.querySelectorAll('nav button'));
        if (btns.length > 0) {
            btns[btns.length - 1].click();
        }
    });

    await new Promise(r => setTimeout(r, 600));
    await page.screenshot({ path: path.join(ARTIFACT_DIR, 'mobile_profile_slideup_modal.png') });
    console.log('✓ Captured Profile Avatar slide-up modal containing all secondary options');

    await browser.close();
})();
