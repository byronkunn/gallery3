import puppeteer from 'puppeteer';
import fs from 'fs';
import path from 'path';

const outDir = '/Users/byron/.gemini/antigravity-ide/brain/027a948b-3642-423e-8659-52ab075431c1/screens';
if (!fs.existsSync(outDir)) fs.mkdirSync(outDir, { recursive: true });

const viewports = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 900 }
];

const pages = [
  { name: 'gallery', path: '/' },
  { name: 'post', path: '/post/1' },
  { name: 'notifications', path: '/notifications' },
  { name: 'messages', path: '/messages' },
  { name: 'profile', path: '/profile/kira_art' },
  { name: 'pools', path: '/pools' },
  { name: 'pool_detail', path: '/pool/1' },
  { name: 'collection_detail', path: '/collection/1' },
  { name: 'settings', path: '/settings' }
];

async function run() {
  const browser = await puppeteer.launch({
    headless: true,
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  const issues = [];

  for (const vp of viewports) {
    console.log(`\n--- Testing Viewport: ${vp.name} (${vp.width}x${vp.height}) ---`);
    const page = await browser.newPage();
    await page.setViewport({ width: vp.width, height: vp.height });

    for (const p of pages) {
      const url = `http://127.0.0.1:8000${p.path}`;
      const errors = [];
      page.on('pageerror', err => errors.push(err.message));

      try {
        await page.goto(url, { waitUntil: 'networkidle2', timeout: 10000 });
        await new Promise(r => setTimeout(r, 600)); // wait for transitions & livewire

        // Check horizontal overflow
        const overflow = await page.evaluate((vpWidth) => {
          const doc = document.documentElement;
          const body = document.body;
          const scrollW = Math.max(doc.scrollWidth, body.scrollWidth);
          const clientW = doc.clientWidth;
          
          let offending = [];
          if (scrollW > clientW + 2) {
            const all = document.querySelectorAll('*');
            for (const el of all) {
              const r = el.getBoundingClientRect();
              if (r.right > vpWidth + 2) {
                offending.push({
                  tag: el.tagName,
                  class: el.className ? String(el.className).slice(0, 60) : '',
                  right: Math.round(r.right),
                  width: Math.round(r.width)
                });
              }
            }
          }
          return {
            scrollW,
            clientW,
            hasOverflow: scrollW > clientW + 2,
            offending: offending.slice(0, 5)
          };
        }, vp.width);

        if (overflow.hasOverflow) {
          console.warn(`[OVERFLOW] ${vp.name} - ${p.name}: scrollWidth (${overflow.scrollW}) > clientWidth (${overflow.clientW})`);
          issues.push({
            type: 'overflow',
            viewport: vp.name,
            page: p.name,
            details: overflow
          });
        }

        if (errors.length > 0) {
          console.error(`[JS ERROR] ${vp.name} - ${p.name}:`, errors);
          issues.push({
            type: 'js_error',
            viewport: vp.name,
            page: p.name,
            errors
          });
        }

        const screenshotPath = `${outDir}/${p.name}_${vp.name}.png`;
        await page.screenshot({ path: screenshotPath, fullPage: false });
        console.log(`✓ ${p.name} on ${vp.name} captured`);

      } catch (e) {
        console.error(`[FAIL] ${vp.name} - ${p.name}: ${e.message}`);
        issues.push({
          type: 'navigation_fail',
          viewport: vp.name,
          page: p.name,
          error: e.message
        });
      }
    }
    await page.close();
  }

  await browser.close();

  console.log('\n=======================================');
  console.log('SUMMARY OF DETECTED ISSUES:');
  console.log(JSON.stringify(issues, null, 2));
}

run().catch(console.error);
