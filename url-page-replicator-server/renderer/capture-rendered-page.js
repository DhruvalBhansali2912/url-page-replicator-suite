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
  const browser = await puppeteer.launch({
    headless: "new",
    executablePath: chromePath,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1280,900']
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 900 });

  try {
    console.log("Navigating to local page (no timeout)...");
    await page.goto('http://localhost/test/relay-human-cloud-scale-with-global-talent-2/', {
      waitUntil: 'domcontentloaded',
      timeout: 0
    });
    console.log("DOM loaded. Waiting 10 seconds for React components to mount and images to render...");
    await new Promise(resolve => setTimeout(resolve, 10000));
    
    const screenshotPath = 'C:\\Users\\DhruvalBhansali\\.gemini\\antigravity\\brain\\c53e28ea-95f6-4521-a3cb-6cf14c463c44\\media__1783939546942.png';
    await page.screenshot({ path: screenshotPath, fullPage: true });
    console.log("Screenshot captured successfully at:", screenshotPath);
  } catch (e) {
    console.log("Screenshot error:", e.message);
  }

  await browser.close();
  console.log("Done");
})();
