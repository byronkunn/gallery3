import puppeteer from 'puppeteer';

(async () => {
  const browser = await puppeteer.launch({
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 800 });

  // 1. Log in first so follow buttons show up
  await page.goto('http://127.0.0.1:8000/switch-user/1', { waitUntil: 'networkidle0' });

  // 2. Visit Kira Art's profile page
  await page.goto('http://127.0.0.1:8000/profile/kira_art', { waitUntil: 'networkidle0' });
  await page.screenshot({ path: '/Users/byron/.gemini/antigravity-ide/brain/027a948b-3642-423e-8659-52ab075431c1/profile_page.png' });
  console.log('Saved profile_page.png');

  // 3. Click Following button
  const clickedFollowing = await page.evaluate(() => {
    const buttons = Array.from(document.querySelectorAll('button'));
    const btn = buttons.find(b => b.textContent.includes('Following'));
    if (btn) {
      btn.click();
      return true;
    }
    return false;
  });

  if (clickedFollowing) {
    await new Promise(r => setTimeout(r, 600));
    await page.screenshot({ path: '/Users/byron/.gemini/antigravity-ide/brain/027a948b-3642-423e-8659-52ab075431c1/following_modal.png' });
    console.log('Saved following_modal.png');
  } else {
    console.error('Following button not found');
  }

  // 4. Switch to Followers tab in modal
  const clickedFollowers = await page.evaluate(() => {
    const buttons = Array.from(document.querySelectorAll('button'));
    const btn = buttons.find(b => b.textContent.includes('Followers'));
    if (btn) {
      btn.click();
      return true;
    }
    return false;
  });

  if (clickedFollowers) {
    await new Promise(r => setTimeout(r, 600));
    await page.screenshot({ path: '/Users/byron/.gemini/antigravity-ide/brain/027a948b-3642-423e-8659-52ab075431c1/followers_modal.png' });
    console.log('Saved followers_modal.png');
  }

  await browser.close();
})();
