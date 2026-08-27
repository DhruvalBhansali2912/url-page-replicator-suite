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
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });
  const page = await browser.newPage();

  await page.evaluateOnNewDocument(() => {
    try {
      const originalDesc = Object.getOwnPropertyDescriptor(Location.prototype, 'pathname');
      Object.defineProperty(Location.prototype, 'pathname', {
        get: function() {
          return "/OVERRIDDEN";
        },
        set: originalDesc ? originalDesc.set : undefined,
        configurable: true,
        enumerable: true
      });
    } catch (e) {
      console.error("Eval error:", e.message);
    }
  });

  await page.goto('http://localhost/test/1-new-message-3/', {
    waitUntil: 'domcontentloaded',
    timeout: 0
  });

  const pathname = await page.evaluate(() => {
    return {
      windowLocationPathname: window.location.pathname,
      locationPathname: location.pathname,
      destructuredPathname: (() => {
        const { pathname } = window.location;
        return pathname;
      })()
    };
  });

  console.log("Evaluation Results:", pathname);
  await browser.close();
})();
