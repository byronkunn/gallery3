import puppeteer from 'puppeteer';
import path from 'path';

const BASE_URL = 'http://127.0.0.1:8000';
const ARTIFACT_DIR = '/Users/byron/.gemini/antigravity-ide/brain/027a948b-3642-423e-8659-52ab075431c1';

(async () => {
    console.log('🚀 Verifying Sidenav Collapsed & Expanded Layout...');
    const browser = await puppeteer.launch({
        executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });

    await page.goto(`${BASE_URL}/gallery`, { waitUntil: 'networkidle0' });

    // Expanded view
    await page.screenshot({ path: path.join(ARTIFACT_DIR, 'sidenav_expanded.png') });
    console.log('✓ Captured expanded sidenav');

    // Click toggle arrow
    await page.evaluate(() => {
        const toggleBtn = document.querySelector('aside button');
        if (toggleBtn) toggleBtn.click();
    });

    await new Promise(r => setTimeout(r, 500));
    await page.screenshot({ path: path.join(ARTIFACT_DIR, 'sidenav_collapsed.png') });
    console.log('✓ Captured collapsed sidenav with B logo and arrow underneath');

    await browser.close();
})();
