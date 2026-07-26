# Bonbast rate scraper (PHP + headless Chrome)

Fetches <https://www.bonbast.com/>, **executes the page's JavaScript** in a real
browser, parses the rendered DOM with `DOMDocument`/`DOMXPath`, and writes the
currency rates to `rates.json`.

```json
{
  "USD": { "sell": "930,000", "buy": "925,000" },
  "EUR": { "sell": "1,095,000", "buy": "1,090,000" }
}
```

Bonbast renders its rate cells from an authenticated XHR after page load, so a
plain `file_get_contents()` / cURL fetch returns empty cells. A JS-capable
browser is required — that is what `render.js` provides.

## Requirements

- PHP 8.1+ with `ext-dom`, `ext-json`, `ext-mbstring`
- One rendering backend:
  - **Node.js 18+ with Puppeteer** (default), or
  - **`chrome-php/chrome`** for a pure-PHP setup

## Install

```bash
npm install                 # installs puppeteer + its bundled Chromium
# or, for the pure-PHP backend:
composer require chrome-php/chrome
```

On a bare Linux server Chromium also needs system libraries:

```bash
npx puppeteer browsers install chrome   # if the bundled download was skipped
sudo apt-get install -y libnss3 libatk-bridge2.0-0 libxkbcommon0 \
  libxcomposite1 libxdamage1 libxrandr2 libgbm1 libasound2
```

## Usage

```bash
php bonbast_rates.php                          # writes ./rates.json
php bonbast_rates.php --output=/var/data/rates.json --compact --quiet
php bonbast_rates.php --renderer=chrome-php --chrome=/usr/bin/chromium
php bonbast_rates.php --stdout --verbose        # debug a failing run
php bonbast_rates.php --help
```

| Option | Default | Purpose |
| --- | --- | --- |
| `--url=URL` | `https://www.bonbast.com/` | Page to scrape |
| `--output=PATH` | `rates.json` | Destination file (written atomically) |
| `--renderer=NAME` | `auto` | `auto`, `puppeteer` or `chrome-php` |
| `--timeout=SECONDS` | `60` | Navigation / selector wait budget |
| `--selector=CSS` | `table.table.table-condensed td` | Waited on before reading the DOM |
| `--node=PATH` / `--chrome=PATH` | `node` / auto | Binary overrides |
| `--script=PATH` | `./render.js` | Alternate renderer script |
| `--compact` / `--stdout` | off | Minified JSON / echo result |
| `--quiet` / `--verbose` | — | Log verbosity (logs go to STDERR) |

**Exit codes:** `0` success · `1` render failure · `2` extraction failure ·
`3` output failure · `4` usage error. Suitable for cron:

```cron
*/15 * * * * cd /srv/bonbast && php bonbast_rates.php --quiet --output=/srv/www/rates.json
```

## How it works

1. `RendererFactory` picks the first available backend (`PuppeteerRenderer`,
   which shells out to `render.js` via `ProcessRunner`, or `ChromePhpRenderer`).
2. The browser navigates, waits for `networkidle2`, waits for the selector to
   exist, settles briefly for late XHR updates, then returns `document`'s HTML.
3. `RateExtractor` loads that HTML into `DOMDocument` and selects tables with an
   XPath equivalent of `table.table.table-condensed` (order- and
   extra-class-tolerant). For every table it skips row 0 and maps
   `td[0] → key`, `td[2] → sell`, `td[3] → buy`.
4. `JsonWriter` encodes with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE` and
   writes to a temp file in the target directory before `rename()`, so readers
   never see a partial file.

### Error handling

Network/navigation errors, HTTP ≥ 400, missing selectors and hung browsers all
surface as `RenderException` (the child process is SIGTERM'd then SIGKILL'd on
timeout). Missing tables or zero usable rows raise `ExtractionException`.
Unwritable directories, encoding failures and failed renames raise
`OutputException`, with the temp file cleaned up. Malformed individual rows
(fewer than 4 `<td>`s, empty key) are logged and skipped rather than aborting
the run; duplicate keys log a warning and last-write-wins.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `No rendering backend available` | `npm install puppeteer`, or pass `--node=/path/to/node` |
| `Timed out waiting for "…"` | Raise `--timeout`; check the site is reachable from the host |
| `Failed to launch the browser process` | Install the system libs above, or pass `--chrome=/usr/bin/chromium` |
| Empty `sell`/`buy` values | Column layout changed — adjust the index constants in `RateExtractor` |
| `No table matching …` | Run with `--verbose`; the site markup may have changed |

## Notes

Rates are returned exactly as displayed (thousands separators included). Cast or
strip them downstream if you need numerics. Scrape politely — a few times per
hour is plenty for a rate board, and check the site's terms before deploying.
