const puppeteer = require('puppeteer-core');
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
  if (!chromePath) {
    console.error('Chrome not found.');
    process.exit(1);
  }

  const browser = await puppeteer.launch({
    executablePath: chromePath,
    headless: 'new'
  });

  const page = await browser.newPage();
  
  // Track all network responses
  console.log('Loading replicated page and tracking requests...\n');
  page.on('response', async (response) => {
    const status = response.status();
    const url = response.url();
    console.log(`[Status ${status}] URL: ${url}`);
    if (status >= 400) {
      try {
        const text = await response.text();
        console.log(`Error Snippet: ${text.substring(0, 150)}...\n`);
      } catch (e) {
        console.log('Could not read body.\n');
      }
    }
  });

  try {
    await page.goto('http://localhost/test/airless-packaging-manufacturer-custom-cosmetic-synerpack-2/', {
      waitUntil: 'networkidle2',
      timeout: 90000
    });
    await new Promise(resolve => setTimeout(resolve, 5000));
  } catch (error) {
    console.error('Error loading page:', error.message);
  } finally {
    await browser.close();
  }
}

run();
