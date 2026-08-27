const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());
const puppeteerCore = require('puppeteer-core');
puppeteer.vanillaPuppeteer = puppeteerCore;

const fs = require('fs');
const path = require('path');

function findChromeExecutable() {
  const platform = process.platform;
  if (platform === 'win32') {
    const paths = [
      path.join(process.env.PROGRAMFILES || 'C:\\Program Files', 'Google\\Chrome\\Application\\chrome.exe'),
      path.join(process.env['PROGRAMFILES(X86)'] || 'C:\\Program Files (x86)', 'Google\\Chrome\\Application\\chrome.exe'),
      path.join(process.env.LOCALAPPDATA || '', 'Google\\Chrome\\Application\\chrome.exe')
    ];
    for (const p of paths) {
      if (fs.existsSync(p)) return p;
    }
  }
  return null;
}

(async () => {
  const chromePath = findChromeExecutable();
  if (!chromePath) {
    console.error('Error: Google Chrome executable not found.');
    process.exit(1);
  }

  const browser = await puppeteer.launch({
    executablePath: chromePath,
    headless: "new",
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });
  const page = await browser.newPage();

  page.on('console', msg => {
    console.log(`PAGE LOG [${msg.type()}]:`, msg.text());
  });

  page.on('pageerror', err => {
    console.error('PAGE ERROR:', err.message);
  });

  page.on('requestfailed', request => {
    console.log('REQUEST FAILED:', request.url(), request.failure().errorText);
  });

  console.log('Navigating to http://localhost/test/madbox-home/...');
  try {
    await page.goto('http://localhost/test/madbox-home/', {
      waitUntil: 'networkidle2',
      timeout: 30000
    });

    console.log('Navigation completed.');
    const title = await page.title();
    console.log('Page Title:', title);

    const bodyHTML = await page.evaluate(() => document.body.innerHTML);
    console.log('Body HTML length:', bodyHTML.length);
    if (bodyHTML.length < 200) {
      console.log('Body content is too short (blank page!):', bodyHTML);
    } else {
      console.log('Body content looks populated!');
    }
  } catch (err) {
    console.error('Error during navigation:', err);
  }

  await browser.close();
})();
