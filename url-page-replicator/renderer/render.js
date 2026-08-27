const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

// Bind to puppeteer-core
const puppeteerCore = require('puppeteer-core');
puppeteer.vanillaPuppeteer = puppeteerCore;

const fs = require('fs');
const path = require('path');

// Helper to parse CLI arguments
function getArg(name) {
  const index = process.argv.indexOf(name);
  if (index !== -1 && index + 1 < process.argv.length) {
    return process.argv[index + 1];
  }
  return null;
}

const targetUrl = getArg('--url');
const targetDir = getArg('--dir');

if (!targetUrl) {
  console.error('Error: Missing --url argument.');
  process.exit(1);
}

// Locate Google Chrome executable path
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
  } else if (platform === 'darwin') {
    const paths = [
      '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
      '/Applications/Chromium.app/Contents/MacOS/Chromium'
    ];
    for (const p of paths) {
      if (fs.existsSync(p)) return p;
    }
  } else {
    // Linux and other platforms
    const paths = [
      '/usr/bin/google-chrome',
      '/usr/bin/chromium',
      '/usr/bin/chromium-browser',
      '/usr/bin/chrome',
      '/snap/bin/chromium'
    ];
    for (const p of paths) {
      if (fs.existsSync(p)) return p;
    }
  }

  // Fallback: Check if full puppeteer downloaded chromium locally
  // Typically stored under ~/.cache/puppeteer or inside local node_modules
  const localChromium = path.join(__dirname, 'node_modules', 'puppeteer', '.local-chromium');
  if (fs.existsSync(localChromium)) {
    // Search recursively for executable
    const files = getFilesRecursive(localChromium);
    for (const f of files) {
      if (f.endsWith('chrome.exe') || f.endsWith('chrome') || f.endsWith('chromium')) {
        return f;
      }
    }
  }

  return null;
}

function getFilesRecursive(dir) {
  let results = [];
  const list = fs.readdirSync(dir);
  list.forEach((file) => {
    file = path.join(dir, file);
    const stat = fs.statSync(file);
    if (stat && stat.isDirectory()) {
      results = results.concat(getFilesRecursive(file));
    } else {
      results.push(file);
    }
  });
  return results;
}

async function renderPage() {
  const chromePath = findChromeExecutable();
  if (!chromePath) {
    console.error('Error: Google Chrome/Chromium executable not found on this system.');
    process.exit(2);
  }

  let browser;
  try {
    const args = [
      '--disable-blink-features=AutomationControlled',
      '--disable-http2'
    ];
    if (process.platform !== 'win32') {
      args.push('--no-sandbox');
      args.push('--disable-setuid-sandbox');
    }

    browser = await puppeteer.launch({
      executablePath: chromePath,
      headless: 'new',
      ignoreDefaultArgs: ['--enable-automation'],
      args: args
    });

    const page = await browser.newPage();
    
    // Assets Interceptor & Parallel Downloader
    const pendingWrites = [];
    if (targetDir) {
      if (!fs.existsSync(targetDir)) {
        fs.mkdirSync(targetDir, { recursive: true });
      }

      page.on('response', (response) => {
        const p = (async () => {
          try {
            const status = response.status();
            if (status !== 200) return;

            const url = response.url();
            if (url.startsWith('data:') || url.length > 1000) return;
            if (url === targetUrl) return;

            const contentType = response.headers()['content-type'] || '';
            
            let ext = '';
            if (contentType.includes('css')) ext = 'css';
            else if (contentType.includes('javascript') || contentType.includes('js')) ext = 'js';
            else if (contentType.includes('image/jpeg') || contentType.includes('image/jpg')) ext = 'jpg';
            else if (contentType.includes('image/png')) ext = 'png';
            else if (contentType.includes('image/gif')) ext = 'gif';
            else if (contentType.includes('image/svg')) ext = 'svg';
            else if (contentType.includes('image/webp')) ext = 'webp';
            else if (contentType.includes('font/woff2')) ext = 'woff2';
            else if (contentType.includes('font/woff')) ext = 'woff';
            else if (contentType.includes('font/ttf')) ext = 'ttf';
            else {
              const parsedExt = path.extname(new URL(url).pathname);
              if (parsedExt) ext = parsedExt.substring(1);
            }

            if (!ext) return;

            const crypto = require('crypto');
            const hash = crypto.createHash('md5').update(url).digest('hex');
            const filename = `${hash}.${ext}`;
            const filepath = path.join(targetDir, filename);

            const buffer = await response.buffer();
            fs.writeFileSync(filepath, buffer);

            // Also save under original filename to support dynamic runtime imports (e.g. vendor-react-*.js)
            const parsedUrl = new URL(url);
            const origFilename = path.basename(parsedUrl.pathname);
            if (origFilename && origFilename.includes('.') && origFilename !== filename) {
              const origFilepath = path.join(targetDir, origFilename);
              fs.writeFileSync(origFilepath, buffer);
            }
          } catch (e) {
            // Silently ignore buffer errors
          }
        })();
        pendingWrites.push(p);
      });
    }
    
    // Hide webdriver automation property
    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(navigator, 'webdriver', {
        get: () => undefined,
      });
    });

    // Set iPhone mobile viewport
    await page.setViewport({ 
      width: 390, 
      height: 844,
      deviceScaleFactor: 3,
      isMobile: true,
      hasTouch: true,
      isLandscape: false
    });

    // Set iPhone Safari User-Agent (iOS 17.5)
    await page.setUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1');

    // Set standard mobile browser navigation headers on the primary document request only
    await page.setRequestInterception(true);
    page.on('request', (request) => {
      if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
        const headers = Object.assign({}, request.headers(), {
          'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
          'Accept-Language': 'en-US,en;q=0.9',
          'Upgrade-Insecure-Requests': '1',
          'Sec-Fetch-Site': 'none',
          'Sec-Fetch-Mode': 'navigate',
          'Sec-Fetch-Dest': 'document'
        });
        request.continue({ headers });
      } else {
        request.continue();
      }
    });

    // Navigate to page
    await page.goto(targetUrl, {
      waitUntil: 'networkidle2',
      timeout: 30000
    });

    // Loop to detect and wait if we are on a CDN challenge page (Akamai or Cloudflare)
    let isChallenge = true;
    let attempts = 0;
    while (isChallenge && attempts < 8) {
      const content = await page.content();
      if (
        content.includes('sec-if-cpt-container') || 
        content.includes('akamai-logo') || 
        content.includes('scf-akamai-protected-by') ||
        content.includes('cf-challenge') ||
        content.includes('cf-cookie-error') ||
        content.includes('challenge-platform') ||
        content.includes('__CF$cv$params')
      ) {
        // Simulate natural mouse movements to satisfy behavioral checks
        for (let m = 0; m < 5; m++) {
          const x = Math.floor(Math.random() * 800) + 100;
          const y = Math.floor(Math.random() * 600) + 100;
          await page.mouse.move(x, y, { steps: 8 });
          await new Promise(resolve => setTimeout(resolve, 200));
        }
        // Wait for challenge scripts to execute
        await new Promise(resolve => setTimeout(resolve, 2000));
        attempts++;
      } else {
        isChallenge = false;
      }
    }

    // Auto-scroll the page dynamically to trigger lazy loaded assets and images
    try {
      await autoScroll(page);
    } catch (err) {
      console.warn('Warning during autoscrolling:', err.message);
    }

    // Additional delay to allow client-side hydration / dynamic components to run
    await new Promise(resolve => setTimeout(resolve, 5000));

    // Wait for all parallel asset writes to complete
    try {
      await Promise.all(pendingWrites);
    } catch (e) {
      console.warn('Warning waiting for writes:', e.message);
    }

    // Capture DOM HTML content
    const htmlContent = await page.content();
    console.log(htmlContent);
  } catch (error) {
    console.error('Error during headless rendering:', error.message);
    process.exit(3);
  } finally {
    if (browser) {
      await browser.close();
    }
  }
}

async function autoScroll(page) {
  await page.evaluate(async () => {
    await new Promise((resolve) => {
      let totalHeight = 0;
      const distance = 150;
      const maxScrolls = 40;
      let scrolls = 0;
      const timer = setInterval(() => {
        const scrollHeight = document.body.scrollHeight;
        window.scrollBy(0, distance);
        totalHeight += distance;
        scrolls++;

        if (totalHeight >= scrollHeight - window.innerHeight || scrolls >= maxScrolls) {
          clearInterval(timer);
          resolve();
        }
      }, 50);
    });
  });
}

renderPage();
