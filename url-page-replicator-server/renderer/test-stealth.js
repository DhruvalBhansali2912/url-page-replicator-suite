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
  const browser = await puppeteer.launch({
    executablePath: chromePath,
    headless: 'new',
    ignoreDefaultArgs: ['--enable-automation']
  });

  const page = await browser.newPage();
  await page.goto('https://bot.sannysoft.com/');
  await new Promise(resolve => setTimeout(resolve, 5000));
  const html = await page.content();
  
  // Look for webdriver result on Sannysoft
  console.log("Checking Sannysoft detection results:");
  if (html.includes('WebDriver (Screening): class="failed"')) {
    console.log("-> Webdriver is DETECTED!");
  } else if (html.includes('WebDriver (Screening): class="passed"')) {
    console.log("-> Webdriver is HIDDEN!");
  } else {
    console.log("-> Could not determine webdriver state.");
  }
  
  await browser.close();
}

run().catch(console.error);
