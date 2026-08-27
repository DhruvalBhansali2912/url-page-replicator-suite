const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
const path = require('path');
const fs = require('fs');

puppeteer.use(StealthPlugin());

function findChromeExecutable() {
  const paths = [
    path.join(process.env.PROGRAMFILES || 'C:\\Program Files', 'Google\\Chrome\\Application\\chrome.exe'),
    path.join(process.env['PROGRAMFILES(X86)'] || 'C:\\Program Files (x86)', 'Google\\Chrome\\Application\\chrome.exe'),
    path.join(process.env.LOCALAPPDATA || '', 'Google\\Chrome\\Application\\chrome.exe')
  ];
  for (const p of paths) {
    if (fs.existsSync(p)) return p;
  }
  return null;
}

(async () => {
  const chromePath = findChromeExecutable();
  console.log("Using Chrome Path:", chromePath);

  const browser = await puppeteer.launch({
    headless: "new",
    executablePath: chromePath,
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });
  const page = await browser.newPage();

  console.log("=== Monitoring Network Requests ===");
  page.on('request', request => {
    const url = request.url();
    if (url.includes('.js') || url.includes('.woff') || url.includes('localhost') || url.includes('relay')) {
      console.log(`[Request] ${request.method()} -> ${url}`);
    }
  });

  page.on('requestfailed', request => {
    console.log(`[FAILED] -> ${request.url()} | Error: ${request.failure().errorText}`);
  });

  page.on('console', msg => {
    console.log(`[Console] ${msg.type()}: ${msg.text()}`);
  });

  try {
    await page.goto('http://localhost/test/relay-human-cloud-scale-with-global-talent-2/', {
      waitUntil: 'domcontentloaded',
      timeout: 10000
    });
    // Sleep 5 seconds to capture runtime logs
    await new Promise(resolve => setTimeout(resolve, 5000));
  } catch (e) {
    console.log("Navigation timeout or error:", e.message);
  }

  await browser.close();
  console.log("=== Done ===");
})();
