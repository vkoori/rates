#!/usr/bin/env php
<?php
/**
 * bonbast_rates.php
 *
 * Fetches https://www.bonbast.com/ with a real (headless) browser so that the
 * page's JavaScript is executed, then parses the rendered DOM with
 * DOMDocument/DOMXPath and writes the extracted currency rates to a JSON file.
 *
 * Output shape:
 *   { "USD": { "sell": "930000", "buy": "925000" }, ... }
 *
 * Usage:
 *   php bonbast_rates.php [options]
 *
 * Run `php bonbast_rates.php --help` for the full option list.
 *
 * Requires: PHP 8.1+ with ext-dom, ext-json, ext-mbstring.
 * Rendering backends (either one):
 *   - Node.js + Puppeteer  (default; uses the bundled render.js)
 *   - chrome-php/chrome    (pure PHP, install via composer)
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Bonbast;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use JsonException;
use RuntimeException;
use Throwable;

/* -------------------------------------------------------------------------
 | Exceptions
 | ---------------------------------------------------------------------- */

/** Base exception for every failure this tool can produce. */
class ScraperException extends RuntimeException {}

/** The page could not be fetched or its JavaScript could not be executed. */
final class RenderException extends ScraperException {}

/** The rendered HTML did not contain the data we expect. */
final class ExtractionException extends ScraperException {}

/** The result could not be persisted to disk. */
final class OutputException extends ScraperException {}

/* -------------------------------------------------------------------------
 | Logging
 | ---------------------------------------------------------------------- */

/**
 * Minimal leveled logger writing to STDERR so that STDOUT stays clean and
 * pipeable (the JSON payload is echoed to STDOUT when --stdout is used).
 */
final class Logger
{
    public const LEVEL_DEBUG = 10;
    public const LEVEL_INFO = 20;
    public const LEVEL_WARNING = 30;
    public const LEVEL_ERROR = 40;
    public const LEVEL_SILENT = 100;

    public function __construct(private int $minLevel = self::LEVEL_INFO) {}

    public function debug(string $message): void
    {
        $this->log(self::LEVEL_DEBUG, 'DEBUG', $message);
    }

    public function info(string $message): void
    {
        $this->log(self::LEVEL_INFO, 'INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->log(self::LEVEL_WARNING, 'WARN', $message);
    }

    public function error(string $message): void
    {
        $this->log(self::LEVEL_ERROR, 'ERROR', $message);
    }

    private function log(int $level, string $label, string $message): void
    {
        if ($level < $this->minLevel) {
            return;
        }

        $line = sprintf("[%s] %-5s %s\n", date('Y-m-d\TH:i:sP'), $label, $message);
        fwrite(STDERR, $line);
    }
}

/* -------------------------------------------------------------------------
 | Configuration
 | ---------------------------------------------------------------------- */

/** Immutable run configuration, built from CLI arguments. */
final class Config
{
    public function __construct(
        public readonly string $url = 'https://www.bonbast.com/',
        public readonly string $outputPath = 'rates.json',
        public readonly string $renderer = 'auto',
        public readonly int $timeoutSeconds = 60,
        public readonly string $nodeBinary = 'node',
        public readonly ?string $chromeBinary = null,
        public readonly ?string $rendererScript = null,
        public readonly bool $pretty = true,
        public readonly bool $echoToStdout = false,
        public readonly int $logLevel = Logger::LEVEL_INFO,
        public readonly string $waitSelector = 'table.table.table-condensed td',
        public readonly string $userAgent =
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/124.0.0.0 Safari/537.36',
    ) {}

    /** Absolute path of the bundled Puppeteer runner. */
    public function resolveRendererScript(): string
    {
        return $this->rendererScript ?? __DIR__ . DIRECTORY_SEPARATOR . 'render.js';
    }
}

/* -------------------------------------------------------------------------
 | Rendering
 | ---------------------------------------------------------------------- */

/** Anything that can turn a URL into fully rendered HTML. */
interface RendererInterface
{
    /**
     * @throws RenderException when the page cannot be loaded or rendered.
     */
    public function render(string $url): string;

    /** Human readable backend name, used in log messages. */
    public function name(): string;

    /** Whether this backend can run in the current environment. */
    public function isAvailable(): bool;
}

/**
 * Runs an external command with a hard wall-clock timeout, capturing both
 * STDOUT and STDERR without dead-locking on full pipe buffers.
 */
final class ProcessRunner
{
    /**
     * @param list<string> $command
     * @return array{exitCode:int, stdout:string, stderr:string, timedOut:bool}
     */
    public static function run(array $command, int $timeoutSeconds): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);

        if (!\is_resource($process)) {
            throw new RenderException(
                'Unable to start process: ' . implode(' ', array_map('escapeshellarg', $command))
            );
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;

        while (true) {
            $read = array_filter([$pipes[1], $pipes[2]], static fn($p) => \is_resource($p) && !feof($p));

            if ($read === []) {
                break;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }

            $write = $except = null;
            $seconds = (int) $remaining;
            $micros = (int) (($remaining - $seconds) * 1_000_000);

            if (@stream_select($read, $write, $except, $seconds, $micros) === false) {
                break;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        if ($timedOut) {
            self::terminate($process);
        }

        foreach ([$pipes[1], $pipes[2]] as $pipe) {
            if (\is_resource($pipe)) {
                // Drain whatever is still buffered before closing.
                $rest = stream_get_contents($pipe);
                if (\is_string($rest) && $rest !== '') {
                    if ($pipe === $pipes[1]) {
                        $stdout .= $rest;
                    } else {
                        $stderr .= $rest;
                    }
                }
                fclose($pipe);
            }
        }

        $exitCode = proc_close($process);

        return [
            'exitCode' => $timedOut ? 124 : $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timedOut' => $timedOut,
        ];
    }

    /** @param resource $process */
    private static function terminate($process): void
    {
        @proc_terminate($process, \defined('SIGTERM') ? SIGTERM : 15);
        usleep(500_000);

        $status = @proc_get_status($process);
        if (\is_array($status) && ($status['running'] ?? false)) {
            @proc_terminate($process, \defined('SIGKILL') ? SIGKILL : 9);
        }
    }
}

/**
 * Renders the page with Node.js + Puppeteer by delegating to the bundled
 * `render.js`, which prints the rendered HTML to STDOUT.
 */
final class PuppeteerRenderer implements RendererInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    public function name(): string
    {
        return 'puppeteer';
    }

    public function isAvailable(): bool
    {
        if (!is_file($this->config->resolveRendererScript())) {
            return false;
        }

        $probe = ProcessRunner::run([$this->config->nodeBinary, '--version'], 15);

        return $probe['exitCode'] === 0;
    }

    public function render(string $url): string
    {
        $script = $this->config->resolveRendererScript();

        if (!is_file($script) || !is_readable($script)) {
            throw new RenderException("Renderer script not found or unreadable: {$script}");
        }

        $command = [
            $this->config->nodeBinary,
            $script,
            '--url=' . $url,
            '--selector=' . $this->config->waitSelector,
            '--timeout=' . ($this->config->timeoutSeconds * 1000),
            '--user-agent=' . $this->config->userAgent,
        ];

        if ($this->config->chromeBinary !== null) {
            $command[] = '--executable-path=' . $this->config->chromeBinary;
        }

        $this->logger->debug('Executing: ' . implode(' ', $command));

        // Give the process a small grace period beyond the in-page timeout so
        // that Puppeteer can report its own, more descriptive error first.
        $result = ProcessRunner::run($command, $this->config->timeoutSeconds + 20);

        if ($result['timedOut']) {
            throw new RenderException(
                "Rendering timed out after {$this->config->timeoutSeconds}s (URL: {$url})."
            );
        }

        if ($result['exitCode'] !== 0) {
            $detail = trim($result['stderr']) !== '' ? trim($result['stderr']) : 'no error output';
            throw new RenderException(
                "Headless browser exited with code {$result['exitCode']}: {$detail}"
            );
        }

        if (trim($result['stderr']) !== '') {
            $this->logger->debug('Renderer stderr: ' . trim($result['stderr']));
        }

        $html = $result['stdout'];

        if (trim($html) === '') {
            throw new RenderException('Headless browser returned an empty document.');
        }

        $this->logger->info(sprintf('%s rendered %s (%d bytes).', $this->name(), $url, \strlen($html)));

        return $html;
    }
}

/**
 * Pure-PHP alternative that drives Chrome over the DevTools protocol using
 * chrome-php/chrome (`composer require chrome-php/chrome`).
 */
final class ChromePhpRenderer implements RendererInterface
{
    private const FACTORY = 'HeadlessChromium\\BrowserFactory';

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    public function name(): string
    {
        return 'chrome-php';
    }

    public function isAvailable(): bool
    {
        return class_exists(self::FACTORY);
    }

    public function render(string $url): string
    {
        if (!$this->isAvailable()) {
            throw new RenderException(
                'chrome-php/chrome is not installed. Run: composer require chrome-php/chrome'
            );
        }

        $factoryClass = self::FACTORY;
        $timeoutMs = $this->config->timeoutSeconds * 1000;

        /** @var object $factory */
        $factory = $this->config->chromeBinary !== null
            ? new $factoryClass($this->config->chromeBinary)
            : new $factoryClass();

        $browser = null;

        try {
            $browser = $factory->createBrowser([
                'headless' => true,
                'noSandbox' => true,
                'userAgent' => $this->config->userAgent,
                'windowSize' => [1366, 900],
                'connectionDelay' => 0.2,
                'sendSyncDefaultTimeout' => $timeoutMs,
            ]);

            $page = $browser->createPage();
            $page->navigate($url)->waitForNavigation('networkIdle', $timeoutMs);

            $this->waitForSelector($page, $this->config->waitSelector, $timeoutMs);

            $html = $page->evaluate(
                "'<!DOCTYPE html>' + document.documentElement.outerHTML"
            )->getReturnValue($timeoutMs);

            if (!\is_string($html) || trim($html) === '') {
                throw new RenderException('Chrome returned an empty document.');
            }

            $this->logger->info(sprintf('%s rendered %s (%d bytes).', $this->name(), $url, \strlen($html)));

            return $html;
        } catch (RenderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RenderException('chrome-php rendering failed: ' . $e->getMessage(), 0, $e);
        } finally {
            if ($browser !== null) {
                try {
                    $browser->close();
                } catch (Throwable) {
                    // Closing errors must never mask the original failure.
                }
            }
        }
    }

    /** Polls the page until the selector matches or the timeout elapses. */
    private function waitForSelector(object $page, string $selector, int $timeoutMs): void
    {
        $expression = sprintf(
            'document.querySelectorAll(%s).length > 0',
            json_encode($selector, JSON_THROW_ON_ERROR)
        );

        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            $found = $page->evaluate($expression)->getReturnValue(5000);
            if ($found === true) {
                return;
            }
            usleep(250_000);
        }

        throw new RenderException("Timed out waiting for selector \"{$selector}\".");
    }
}

/** Chooses the first usable rendering backend. */
final class RendererFactory
{
    public function __construct(private readonly Logger $logger) {}

    public function create(Config $config): RendererInterface
    {
        $puppeteer = new PuppeteerRenderer($config, $this->logger);
        $chromePhp = new ChromePhpRenderer($config, $this->logger);

        $candidates = match ($config->renderer) {
            'puppeteer' => [$puppeteer],
            'chrome-php' => [$chromePhp],
            'auto' => [$puppeteer, $chromePhp],
            default => throw new ScraperException(
                "Unknown renderer \"{$config->renderer}\". Use: auto, puppeteer, chrome-php."
            ),
        };

        foreach ($candidates as $candidate) {
            if ($candidate->isAvailable()) {
                $this->logger->debug('Selected rendering backend: ' . $candidate->name());

                return $candidate;
            }

            $this->logger->debug('Backend unavailable: ' . $candidate->name());
        }

        throw new RenderException(
            'No rendering backend available. Install Node.js + Puppeteer '
            . '(npm install puppeteer) or chrome-php/chrome (composer require chrome-php/chrome).'
        );
    }
}

/* -------------------------------------------------------------------------
 | Extraction
 | ---------------------------------------------------------------------- */

/**
 * Parses rendered HTML and pulls the rate tables out of it using
 * DOMDocument + DOMXPath.
 */
final class RateExtractor
{
    /**
     * Matches `table.table.table-condensed` — the XPath equivalent of a CSS
     * class selector, tolerant of class order and extra classes.
     */
    private const TABLE_XPATH =
        "//table[contains(concat(' ', normalize-space(@class), ' '), ' table ')"
        . " and contains(concat(' ', normalize-space(@class), ' '), ' table-condensed ')]";

    private const KEY_INDEX = 0;
    private const SELL_INDEX = 2;
    private const BUY_INDEX = 3;

    public function __construct(private readonly Logger $logger) {}

    /**
     * @return array<string, array{sell:string, buy:string}>
     *
     * @throws ExtractionException
     */
    public function extract(string $html): array
    {
        $xpath = $this->createXPath($html);

        $tables = $xpath->query(self::TABLE_XPATH);

        if ($tables === false || $tables->length === 0) {
            throw new ExtractionException(
                'No table matching "table.table.table-condensed" was found in the rendered page. '
                . 'The site markup may have changed, or the data had not loaded yet.'
            );
        }

        $this->logger->info(sprintf('Found %d matching table(s).', $tables->length));

        $rates = [];
        $skipped = 0;

        foreach ($tables as $tableIndex => $table) {
            if (!$table instanceof DOMElement) {
                continue;
            }

            $rows = $xpath->query('.//tr', $table);

            if ($rows === false || $rows->length < 2) {
                $this->logger->warning(sprintf('Table #%d has no data rows; skipping.', $tableIndex + 1));
                continue;
            }

            foreach ($rows as $rowIndex => $row) {
                // Requirement: the first row is the header and must be ignored.
                if ($rowIndex === 0) {
                    continue;
                }

                if (!$row instanceof DOMElement) {
                    continue;
                }

                $cells = $xpath->query('./td', $row);

                if ($cells === false || $cells->length <= self::BUY_INDEX) {
                    $skipped++;
                    $this->logger->debug(sprintf(
                        'Table #%d row #%d has %d <td> cells (need at least %d); skipping.',
                        $tableIndex + 1,
                        $rowIndex + 1,
                        $cells === false ? 0 : $cells->length,
                        self::BUY_INDEX + 1
                    ));
                    continue;
                }

                $key = $this->text($cells->item(self::KEY_INDEX));

                if ($key === '') {
                    $skipped++;
                    $this->logger->debug(sprintf(
                        'Table #%d row #%d has an empty key cell; skipping.',
                        $tableIndex + 1,
                        $rowIndex + 1
                    ));
                    continue;
                }

                if (isset($rates[$key])) {
                    $this->logger->warning("Duplicate key \"{$key}\" encountered; the later value wins.");
                }

                $rates[$key] = [
                    'sell' => $this->text($cells->item(self::SELL_INDEX)),
                    'buy' => $this->text($cells->item(self::BUY_INDEX)),
                ];
            }
        }

        if ($rates === []) {
            throw new ExtractionException(
                'Matching tables were found but no usable rows could be extracted.'
            );
        }

        if ($skipped > 0) {
            $this->logger->warning(sprintf('%d row(s) were skipped as malformed.', $skipped));
        }

        $this->logger->info(sprintf('Extracted %d rate entries.', \count($rates)));

        return $rates;
    }

    /** Builds a DOMXPath over the rendered markup, suppressing HTML5 noise. */
    private function createXPath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->preserveWhiteSpace = false;
            $document->strictErrorChecking = false;

            // The XML prolog forces libxml to treat the input as UTF-8 even when
            // the document lacks a <meta charset> declaration.
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
            );

            if ($loaded === false) {
                throw new ExtractionException('Rendered HTML could not be parsed into a DOM tree.');
            }

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Normalises cell text: strips tags/entities, NBSPs and repeated whitespace. */
    private function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        $value = $node->textContent;
        $value = str_replace(["\xC2\xA0", "\xE2\x80\x8F", "\xE2\x80\x8E"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}

/* -------------------------------------------------------------------------
 | Output
 | ---------------------------------------------------------------------- */

/** Serialises the result and writes it to disk atomically. */
final class JsonWriter
{
    public function __construct(private readonly Logger $logger) {}

    /** @param array<string, array{sell:string, buy:string}> $rates */
    public function encode(array $rates, bool $pretty): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        try {
            // Force an object even for an empty result set.
            return json_encode((object) $rates, $flags);
        } catch (JsonException $e) {
            throw new OutputException('Failed to encode result as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Writes to a temp file in the destination directory and renames it into
     * place, so readers never observe a half-written file.
     */
    public function write(string $path, string $contents): void
    {
        $directory = \dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new OutputException("Output directory does not exist and could not be created: {$directory}");
        }

        if (!is_writable($directory)) {
            throw new OutputException("Output directory is not writable: {$directory}");
        }

        if (file_exists($path) && !is_writable($path)) {
            throw new OutputException("Output file exists but is not writable: {$path}");
        }

        $temporary = @tempnam($directory, '.rates-');

        if ($temporary === false) {
            throw new OutputException("Could not create a temporary file in: {$directory}");
        }

        try {
            $bytes = @file_put_contents($temporary, $contents . "\n", LOCK_EX);

            if ($bytes === false) {
                throw new OutputException("Failed to write temporary file: {$temporary}");
            }

            if (!@rename($temporary, $path)) {
                throw new OutputException("Failed to move the result into place: {$path}");
            }

            @chmod($path, 0o644);
        } catch (Throwable $e) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            throw $e instanceof OutputException
                ? $e
                : new OutputException('Failed to write output file: ' . $e->getMessage(), 0, $e);
        }

        $this->logger->info(sprintf('Wrote %d bytes to %s', \strlen($contents) + 1, $path));
    }
}

/* -------------------------------------------------------------------------
 | CLI
 | ---------------------------------------------------------------------- */

/** Parses `--key=value` / `--flag` arguments into a Config object. */
final class CliParser
{
    private const USAGE = <<<TXT
    Usage: php bonbast_rates.php [options]

      --url=URL                Page to scrape (default: https://www.bonbast.com/)
      --output=PATH            Destination JSON file (default: rates.json)
      --renderer=NAME          auto | puppeteer | chrome-php (default: auto)
      --timeout=SECONDS        Per-stage rendering timeout (default: 60)
      --node=PATH              Node.js binary (default: node)
      --chrome=PATH            Chrome/Chromium binary (default: auto-detected)
      --script=PATH            Path to render.js (default: ./render.js)
      --selector=CSS           Selector to wait for before reading the DOM
      --compact                Write minified JSON instead of pretty-printed
      --stdout                 Also print the JSON to STDOUT
      --quiet                  Only log errors
      --verbose                Log debug details
      -h, --help               Show this help text

    Exit codes: 0 success, 1 render failure, 2 extraction failure,
                3 output failure, 4 usage/other error.
    TXT;

    /** @param list<string> $argv */
    public function parse(array $argv): Config
    {
        $defaults = new Config();

        $url = $defaults->url;
        $output = $defaults->outputPath;
        $renderer = $defaults->renderer;
        $timeout = $defaults->timeoutSeconds;
        $node = $defaults->nodeBinary;
        $chrome = $defaults->chromeBinary;
        $script = $defaults->rendererScript;
        $selector = $defaults->waitSelector;
        $pretty = $defaults->pretty;
        $echo = $defaults->echoToStdout;
        $logLevel = $defaults->logLevel;

        foreach (\array_slice($argv, 1) as $argument) {
            [$name, $value] = $this->split($argument);

            switch ($name) {
                case '-h':
                case '--help':
                    fwrite(STDOUT, self::USAGE . "\n");
                    exit(0);
                case '--url':
                    $url = $this->requireValue($name, $value);
                    break;
                case '--output':
                case '--out':
                    $output = $this->requireValue($name, $value);
                    break;
                case '--renderer':
                    $renderer = $this->requireValue($name, $value);
                    break;
                case '--timeout':
                    $timeout = max(5, (int) $this->requireValue($name, $value));
                    break;
                case '--node':
                    $node = $this->requireValue($name, $value);
                    break;
                case '--chrome':
                    $chrome = $this->requireValue($name, $value);
                    break;
                case '--script':
                    $script = $this->requireValue($name, $value);
                    break;
                case '--selector':
                    $selector = $this->requireValue($name, $value);
                    break;
                case '--compact':
                    $pretty = false;
                    break;
                case '--stdout':
                    $echo = true;
                    break;
                case '--quiet':
                    $logLevel = Logger::LEVEL_ERROR;
                    break;
                case '--verbose':
                    $logLevel = Logger::LEVEL_DEBUG;
                    break;
                default:
                    throw new ScraperException("Unknown option \"{$name}\".\n\n" . self::USAGE);
            }
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ScraperException("Invalid --url value: \"{$url}\".");
        }

        return new Config(
            url: $url,
            outputPath: $output,
            renderer: $renderer,
            timeoutSeconds: $timeout,
            nodeBinary: $node,
            chromeBinary: $chrome,
            rendererScript: $script,
            pretty: $pretty,
            echoToStdout: $echo,
            logLevel: $logLevel,
            waitSelector: $selector,
        );
    }

    /** @return array{0:string, 1:?string} */
    private function split(string $argument): array
    {
        $position = strpos($argument, '=');

        return $position === false
            ? [$argument, null]
            : [substr($argument, 0, $position), substr($argument, $position + 1)];
    }

    private function requireValue(string $name, ?string $value): string
    {
        if ($value === null || $value === '') {
            throw new ScraperException("Option \"{$name}\" requires a value, e.g. {$name}=...");
        }

        return $value;
    }
}

/** Wires the pieces together. */
final class Application
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    public function run(): void
    {
        $renderer = (new RendererFactory($this->logger))->create($this->config);

        $this->logger->info(sprintf('Rendering %s via %s…', $this->config->url, $renderer->name()));
        $html = $renderer->render($this->config->url);

        $rates = (new RateExtractor($this->logger))->extract($html);
        $payload = [
            'rates' => $rates,
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
        ];

        $writer = new JsonWriter($this->logger);
        $json = $writer->encode($payload, $this->config->pretty);
        $writer->write($this->config->outputPath, $json);

        if ($this->config->echoToStdout) {
            fwrite(STDOUT, $json . "\n");
        }
    }
}

/* -------------------------------------------------------------------------
 | Entry point
 | ---------------------------------------------------------------------- */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(4);
}

foreach (['dom', 'json', 'mbstring'] as $extension) {
    if (!\extension_loaded($extension)) {
        fwrite(STDERR, "Missing required PHP extension: ext-{$extension}\n");
        exit(4);
    }
}

$logger = new Logger();

try {
    $config = (new CliParser())->parse($argv);
    $logger = new Logger($config->logLevel);

    (new Application($config, $logger))->run();

    exit(0);
} catch (RenderException $e) {
    $logger->error('Rendering failed: ' . $e->getMessage());
    exit(1);
} catch (ExtractionException $e) {
    $logger->error('Extraction failed: ' . $e->getMessage());
    exit(2);
} catch (OutputException $e) {
    $logger->error('Writing output failed: ' . $e->getMessage());
    exit(3);
} catch (Throwable $e) {
    $logger->error($e->getMessage());
    exit(4);
}
