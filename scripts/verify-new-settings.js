import puppeteer from 'puppeteer';
import path from 'path';

const BASE_URL = 'http://127.0.0.1:8000';
const ARTIFACT_DIR = '/Users/byron/.gemini/antigravity-ide/brain/027a948b-3642-423e-8659-52ab075431c1';

(async () => {
    console.log('🚀 Verifying New Settings Categories...');
    const browser = await puppeteer.launch({
        executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });

    await page.goto(`${BASE_URL}/switch-user/1`, { waitUntil: 'networkidle0' });
    await page.goto(`${BASE_URL}/settings`, { waitUntil: 'networkidle0' });

    // Click Media & Autoplay
    const mediaBtn = await page.evaluate(() => {
        const btns = Array.from(document.querySelectorAll('button'));
        const match = btns.find(b => b.innerText.includes('Media & Autoplay'));
        if (match) { match.click(); return true; }
        return false;
    });
    await new Promise(r => setTimeout(r, 500));
    await page.screenshot({ path: path.join(ARTIFACT_DIR, 'settings_media_autoplay.png') });
    console.log('✓ Media & Autoplay settings panel verified');

    // Click Artist & Commissions
    const artistBtn = await page.evaluate(() => {
        const btns = Array.from(document.querySelectorAll('button'));
        const match = btns.find(b => b.innerText.includes('Artist & Commissions'));
        if (match) { match.click(); return true; }
        return false;
    });
    await new Promise(r => setTimeout(r, 500));
    await page.screenshot({ path: path.join(ARTIFACT_DIR, 'settings_artist_commissions.png') });
    console.log('✓ Artist & Commissions settings panel verified');

    await browser.close();
})();
