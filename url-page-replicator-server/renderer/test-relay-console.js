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

async function run() {
  const chromePath = findChromeExecutable();
  const args = [
    '--disable-blink-features=AutomationControlled',
    '--disable-http2'
  ];
  const browser = await puppeteer.launch({
    executablePath: chromePath,
    headless: 'new',
    ignoreDefaultArgs: ['--enable-automation'],
    args: args
  });

  const page = await browser.newPage();
  
  page.on('console', msg => console.log('BROWSER LOG:', msg.text()));
  page.on('pageerror', err => console.error('BROWSER ERROR:', err.message));
  page.on('requestfailed', request => console.log('REQUEST FAILED:', request.url(), request.failure().errorText));

  // Set mobile emulation
  await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
  await page.setUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1');

  console.log("Navigating to https://relayhumancloud.com/ ...");
  await page.goto('https://relayhumancloud.com/', { waitUntil: 'networkidle2', timeout: 30000 });
  
  await new Promise(resolve => setTimeout(resolve, 5000));
  const html = await page.content();
  console.log("HTML length:", html.length);
  
  await browser.close();
}

run().catch(console.error);
