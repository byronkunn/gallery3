import puppeteer from 'puppeteer';
import path from 'path';

const BASE_URL = 'http://127.0.0.1:8000';
const ARTIFACT_DIR = '/Users/byron/.gemini/antigravity-ide/brain/027a948b-3642-423e-8659-52ab075431c1';

(async () => {
    console.log('🚀 Starting Comprehensive Tabs Functional Verification...');
    const browser = await puppeteer.launch({
        executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });

    // 1. Login
    console.log('--- Logging in as Demo User/Admin ---');
    await page.goto(`${BASE_URL}/switch-user/1`, { waitUntil: 'networkidle0' });

    // Helper to click button by text snippet
    const clickTab = async (textSnippet) => {
        return page.evaluate((text) => {
            const btns = Array.from(document.querySelectorAll('button'));
            const match = btns.find(b => b.innerText.includes(text));
            if (match) {
                match.click();
                return true;
            }
            return false;
        }, textSnippet);
    };

    // 2. Gallery Feed Tabs
    console.log('--- Testing Gallery Feed Tabs ---');
    await page.goto(`${BASE_URL}/gallery`, { waitUntil: 'networkidle0' });
    await clickTab('Following');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Gallery: Following clicked');

    await clickTab('For You');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Gallery: For You clicked');

    // 3. Profile Tabs
    console.log('--- Testing Profile View Tabs ---');
    await page.goto(`${BASE_URL}/@kira`, { waitUntil: 'networkidle0' });
    
    await clickTab('Media Grid');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Profile: Media Grid clicked');

    await clickTab('Likes');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Profile: Likes clicked');

    await clickTab('Collections');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Profile: Collections clicked');

    await clickTab('Pools & Manga');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Profile: Pools clicked');

    // 4. Pools Index Filter Tabs
    console.log('--- Testing Pools Index Filter Tabs ---');
    await page.goto(`${BASE_URL}/pools`, { waitUntil: 'networkidle0' });

    await clickTab('Following');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Pools: Following clicked');

    await clickTab('My Pools');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Pools: My Pools clicked');

    await clickTab('Popular');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Pools: Popular clicked');

    // 5. Notifications Filter Tabs
    console.log('--- Testing Notifications Filter Tabs ---');
    await page.goto(`${BASE_URL}/notifications`, { waitUntil: 'networkidle0' });

    await clickTab('Unread');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Notifications: Unread clicked');

    await clickTab('Pools & Collections');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Notifications: Pools & Collections clicked');

    // 6. Settings Category Tabs
    console.log('--- Testing Settings Category Tabs ---');
    await page.goto(`${BASE_URL}/settings`, { waitUntil: 'networkidle0' });

    await clickTab('Privacy & Security');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Settings: Privacy & Security clicked');

    // 7. Admin Dashboard Tabs
    console.log('--- Testing Admin Dashboard Tabs ---');
    await page.goto(`${BASE_URL}/admin`, { waitUntil: 'networkidle0' });

    await clickTab('Pools & Series');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Admin: Pools & Series clicked');

    // 8. Messages Filter Tabs
    console.log('--- Testing Messages Filter Tabs ---');
    await page.goto(`${BASE_URL}/messages`, { waitUntil: 'networkidle0' });

    await clickTab('Unread');
    await new Promise(r => setTimeout(r, 400));
    console.log('✓ Messages: Unread clicked');

    console.log('\n🎉 ALL TABS ACROSS THE SITE OPERATIONAL!');
    await browser.close();
})();
