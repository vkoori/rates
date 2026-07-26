#!/usr/bin/env node
/**
 * render.js — headless rendering helper for bonbast_rates.php
 *
 * Loads a URL in headless Chrome, waits until the JavaScript-generated content
 * is present, and writes the fully rendered HTML to STDOUT. Diagnostics go to
 * STDERR so STDOUT stays a clean HTML stream.
 *
 * Usage:
 *   node render.js --url=https://www.bonbast.com/ \
 *                  --selector="table.table.table-condensed td" \
 *                  --timeout=60000
 *
 * Exit codes: 0 success, 1 render/navigation failure, 2 bad usage.
 */

'use strict';

const DEFAULTS = {
  url: 'https://www.bonbast.com/',
  selector: 'table.table.table-condensed td',
  timeout: 60000,
  waitUntil: 'networkidle2',
  userAgent:
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) ' +
    'Chrome/124.0.0.0 Safari/537.36',
  executablePath: null,
  settleMs: 750,
};

function parseArgs(argv) {
  const options = { ...DEFAULTS };

  for (const argument of argv) {
    const match = /^--([a-zA-Z0-9-]+)=?(.*)$/s.exec(argument);

    if (!match) {
      throw new UsageError(`Unrecognised argument: ${argument}`);
    }

    const [, rawKey, value] = match;
    const key = rawKey.replace(/-([a-z])/g, (_, c) => c.toUpperCase());

    switch (key) {
      case 'url':
      case 'selector':
      case 'waitUntil':
      case 'userAgent':
      case 'executablePath':
        options[key] = value;
        break;
      case 'timeout':
      case 'settleMs': {
        const parsed = Number.parseInt(value, 10);
        if (!Number.isFinite(parsed) || parsed <= 0) {
          throw new UsageError(`--${rawKey} must be a positive integer.`);
        }
        options[key] = parsed;
        break;
      }
      default:
        throw new UsageError(`Unknown option: --${rawKey}`);
    }
  }

  if (!/^https?:\/\//i.test(options.url)) {
    throw new UsageError(`--url must be an absolute http(s) URL, got: ${options.url}`);
  }

  return options;
}

class UsageError extends Error {}

function loadPuppeteer() {
  try {
    // `puppeteer` ships its own Chromium; `puppeteer-core` needs --executable-path.
    return require('puppeteer');
  } catch (primaryError) {
    try {
      return require('puppeteer-core');
    } catch (fallbackError) {
      throw new Error(
        'Puppeteer is not installed. Run "npm install puppeteer" in this directory. ' +
          `(${primaryError.message})`
      );
    }
  }
}

/** Blocks assets we never need — cuts render time and bandwidth substantially. */
async function blockHeavyAssets(page) {
  await page.setRequestInterception(true);

  page.on('request', (request) => {
    const type = request.resourceType();

    if (type === 'image' || type === 'media' || type === 'font') {
      request.abort().catch(() => {});
    } else {
      request.continue().catch(() => {});
    }
  });
}

async function render(options) {
  const puppeteer = loadPuppeteer();

  const launchOptions = {
    headless: 'new',
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--no-first-run',
      '--no-zygote',
      '--lang=en-US,en',
    ],
  };

  if (options.executablePath) {
    launchOptions.executablePath = options.executablePath;
  }

  let browser;

  try {
    browser = await puppeteer.launch(launchOptions);

    const page = await browser.newPage();
    page.setDefaultTimeout(options.timeout);
    page.setDefaultNavigationTimeout(options.timeout);

    await page.setUserAgent(options.userAgent);
    await page.setViewport({ width: 1366, height: 900 });
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });
    await blockHeavyAssets(page);

    page.on('pageerror', (error) => {
      process.stderr.write(`page error: ${error.message}\n`);
    });

    const response = await page.goto(options.url, {
      waitUntil: options.waitUntil,
      timeout: options.timeout,
    });

    if (!response) {
      throw new Error(`No HTTP response received for ${options.url}`);
    }

    const status = response.status();

    if (status >= 400) {
      throw new Error(`HTTP ${status} ${response.statusText()} for ${options.url}`);
    }

    if (options.selector) {
      try {
        await page.waitForSelector(options.selector, {
          timeout: options.timeout,
          visible: false,
        });
      } catch (waitError) {
        throw new Error(
          `Timed out waiting for "${options.selector}" — the page loaded but the ` +
            `expected content never appeared. (${waitError.message})`
        );
      }
    }

    // Small settle window so late XHR-driven cell updates are captured.
    await new Promise((resolve) => setTimeout(resolve, options.settleMs));

    const html = await page.content();

    if (!html || html.trim() === '') {
      throw new Error('Rendered document was empty.');
    }

    return html;
  } finally {
    if (browser) {
      await browser.close().catch(() => {});
    }
  }
}

(async () => {
  let options;

  try {
    options = parseArgs(process.argv.slice(2));
  } catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 2;
    return;
  }

  try {
    const html = await render(options);
    process.stdout.write(html);
    process.exitCode = 0;
  } catch (error) {
    process.stderr.write(`${(error && error.message) || String(error)}\n`);
    process.exitCode = 1;
  }
})();
