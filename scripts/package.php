<?php

/**
 * Plugin header check, distignore zip, and release metadata.
 *
 * PHP 7.4+. Invoked by scripts/release.sh.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$argv = $_SERVER['argv'];
$command = isset($argv[1]) ? (string) $argv[1] : '';
$args = [];
for ($i = 2, $n = count($argv); $i < $n; $i++) {
    $token = (string) $argv[$i];
    if (strpos($token, '--') !== 0) {
        fwrite(STDERR, "unexpected argument\n");
        exit(1);
    }
    $key = substr($token, 2);
    $value = isset($argv[$i + 1]) ? (string) $argv[$i + 1] : '';
    if ($value === '' || strpos($value, '--') === 0) {
        fwrite(STDERR, "missing value for --{$key}\n");
        exit(1);
    }
    $args[$key] = $value;
    $i++;
}

if ($command === 'has-strauss') {
    $json = read_composer();
    exit(isset($json['extra']['strauss']) ? 0 : 1);
}

if ($command === 'strauss-target') {
    $json = read_composer();
    $target = 'vendor-prefixed';
    if (isset($json['extra']['strauss']['target_directory']) && is_string($json['extra']['strauss']['target_directory']) && $json['extra']['strauss']['target_directory'] !== '') {
        $target = $json['extra']['strauss']['target_directory'];
    }
    echo $target, "\n";
    exit(0);
}

if ($command === 'check') {
    $headers = read_headers(require_arg($args, 'source'), require_arg($args, 'slug'));
    assert_version($headers, require_arg($args, 'version'));
    exit(0);
}

if ($command === 'metadata') {
    $source = require_arg($args, 'source');
    $slug = require_arg($args, 'slug');
    $version = require_arg($args, 'version');
    $zip = require_arg($args, 'zip');
    $headers = read_headers($source, $slug);
    assert_version($headers, $version);
    $sha = hash_file('sha256', $zip);
    if (!is_string($sha) || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
        fwrite(STDERR, "could not hash the zip\n");
        exit(1);
    }
    $requiresWp = version_field(
        optional_env('WPBR_REQUIRES_WP'),
        $headers['requires_wp'],
        'requires_wp',
        'set the requires-wp input, or add plugin header "Requires at least"'
    );
    $requiresPhp = version_field(
        optional_env('WPBR_REQUIRES_PHP'),
        $headers['requires_php'],
        'requires_php',
        'set the requires-php input, or add plugin header "Requires PHP"'
    );
    $testedWp = version_field(
        optional_env('WPBR_TESTED_WP'),
        readme_tested_wp($source),
        'tested_wp',
        'set the tested-wp input, or add readme.txt "Tested up to"'
    );
    $changelog = changelog_for_version($source, $version, optional_env('WPBR_CHANGELOG'));
    $metadata = [
        'product_slug' => $slug,
        'version' => $version,
        'changelog' => $changelog,
        'requires_wp' => $requiresWp,
        'requires_php' => $requiresPhp,
        'tested_wp' => $testedWp,
        'sha256' => $sha,
    ];
    $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        fwrite(STDERR, "could not encode metadata\n");
        exit(1);
    }
    if (file_put_contents(require_arg($args, 'out'), $encoded) === false) {
        fwrite(STDERR, "could not write metadata\n");
        exit(1);
    }
    fwrite(STDOUT, $sha . "\n");
    exit(0);
}

if ($command === 'zip') {
    build_zip(
        require_arg($args, 'source'),
        require_arg($args, 'slug'),
        require_arg($args, 'out')
    );
    exit(0);
}

fwrite(STDERR, "usage: package.php check|metadata|zip|has-strauss|strauss-target\n");
exit(1);

/**
 * @return array<string, mixed>
 */
function read_composer(): array
{
    if (!is_file('composer.json')) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents('composer.json'), true);
    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
}

/**
 * @param array<string, string> $args
 */
function require_arg(array $args, string $key): string
{
    if (!isset($args[$key]) || $args[$key] === '') {
        fwrite(STDERR, "missing --{$key}\n");
        exit(1);
    }

    return $args[$key];
}

/**
 * @return array{version: string, requires_wp: ?string, requires_php: ?string}
 */
function read_headers(string $source, string $slug): array
{
    $path = rtrim($source, '/') . '/' . $slug . '.php';
    if (!is_file($path)) {
        fwrite(STDERR, "main plugin file not found: {$slug}.php\n");
        exit(1);
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        fwrite(STDERR, "could not read {$slug}.php\n");
        exit(1);
    }
    $text = (string) fread($handle, 8192);
    fclose($handle);

    $version = header_value($text, 'Version');
    if ($version === null || $version === '') {
        fwrite(STDERR, "plugin header missing: version\n");
        exit(1);
    }
    if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $version) !== 1) {
        fwrite(STDERR, "plugin header version is not a version: {$version}\n");
        exit(1);
    }

    return [
        'version' => $version,
        'requires_wp' => header_value($text, 'Requires at least'),
        'requires_php' => header_value($text, 'Requires PHP'),
    ];
}

function optional_env(string $name): string
{
    $value = getenv($name);
    if (!is_string($value)) {
        return '';
    }

    return $value;
}

function version_field(string $input, ?string $fallback, string $name, string $hint): string
{
    $value = trim($input);
    if ($value === '') {
        $value = $fallback === null ? '' : trim($fallback);
    }
    if ($value === '') {
        fwrite(STDERR, "missing {$name} before upload ({$hint})\n");
        exit(1);
    }
    if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $value) !== 1) {
        fwrite(STDERR, "{$name} is not a version: {$value}\n");
        exit(1);
    }

    return $value;
}

function readme_tested_wp(string $source): ?string
{
    $root = rtrim($source, '/');
    foreach (['readme.txt', 'README.txt'] as $name) {
        $path = $root . '/' . $name;
        if (!is_file($path)) {
            continue;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            continue;
        }
        $text = (string) fread($handle, 8192);
        fclose($handle);
        $value = header_value($text, 'Tested up to');
        if ($value !== null && $value !== '') {
            return $value;
        }
    }

    return null;
}

function changelog_for_version(string $source, string $version, string $input): string
{
    if (trim($input) !== '') {
        $changelog = trim($input);
    } else {
        $changelog = changelog_from_files($source, $version);
    }
    if ($changelog === null || $changelog === '') {
        fwrite(STDERR, "missing changelog for {$version} before upload (set the changelog input, or add a CHANGELOG.md or readme.txt section for that version)\n");
        exit(1);
    }
    if (strlen($changelog) > 100000) {
        fwrite(STDERR, "changelog for {$version} is longer than 100000 bytes\n");
        exit(1);
    }

    return $changelog;
}

function changelog_from_files(string $source, string $version): ?string
{
    $root = rtrim($source, '/');
    foreach (['CHANGELOG.md', 'CHANGELOG.txt'] as $name) {
        $section = section_from_file($root . '/' . $name, $version, 'markdown');
        if ($section !== null) {
            return $section;
        }
    }
    foreach (['readme.txt', 'README.txt'] as $name) {
        $section = section_from_file($root . '/' . $name, $version, 'readme');
        if ($section !== null) {
            return $section;
        }
    }

    return null;
}

function section_from_file(string $path, string $version, string $kind): ?string
{
    if (!is_file($path)) {
        return null;
    }
    $text = (string) file_get_contents($path);
    if ($kind === 'readme') {
        return readme_changelog_section($text, $version);
    }

    return markdown_changelog_section($text, $version);
}

function markdown_changelog_section(string $text, string $version): ?string
{
    $lines = preg_split("/\r\n|\n|\r/", $text);
    if (!is_array($lines)) {
        return null;
    }
    $start = null;
    $level = 0;
    foreach ($lines as $index => $line) {
        if (preg_match('/^(#{1,6})\s+(.*?)\s*#*$/', $line, $match) !== 1) {
            continue;
        }
        if (!heading_is_version($match[2], $version)) {
            continue;
        }
        $start = $index + 1;
        $level = strlen($match[1]);
        break;
    }
    if ($start === null) {
        return null;
    }
    $body = [];
    $count = count($lines);
    for ($index = $start; $index < $count; $index++) {
        if (preg_match('/^(#{1,6})\s+/', $lines[$index], $match) === 1 && strlen($match[1]) <= $level) {
            break;
        }
        $body[] = $lines[$index];
    }
    $section = trim(implode("\n", $body));

    return $section === '' ? null : $section;
}

function readme_changelog_section(string $text, string $version): ?string
{
    $quoted = preg_quote($version, '/');
    $pattern = '/^=[ \t]*v?' . $quoted . '[ \t]*=[ \t]*$/m';
    if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }
    $start = (int) $match[0][1] + strlen((string) $match[0][0]);
    $rest = substr($text, $start);
    if (preg_match('/\R={1,2}[ \t]*[^=\r\n].*$/m', $rest, $end, PREG_OFFSET_CAPTURE) === 1) {
        $rest = substr($rest, 0, (int) $end[0][1]);
    }
    $section = trim(str_replace("\r\n", "\n", $rest));

    return $section === '' ? null : $section;
}

function heading_is_version(string $text, string $version): bool
{
    $quoted = preg_quote($version, '/');

    return preg_match('/^\[?v?' . $quoted . '\]?(?:\s+[-–—].*)?$/', trim($text)) === 1;
}

function header_value(string $text, string $name): ?string
{
    $pattern = '/^[ \t\/*#@]*' . preg_quote($name, '/') . ':[ \t]*(.*)$/mi';
    if (preg_match($pattern, $text, $match) !== 1) {
        return null;
    }
    $value = trim((string) $match[1]);
    $stripped = preg_replace('/\s*(?:\*\/|\?>).*/', '', $value);
    if (!is_string($stripped)) {
        return null;
    }

    return trim($stripped);
}

/**
 * @param array{version: string, requires_wp: ?string, requires_php: ?string} $headers
 */
function assert_version(array $headers, string $version): void
{
    if ($headers['version'] !== $version) {
        fwrite(STDERR, "plugin header Version {$headers['version']} does not equal tag version {$version}\n");
        exit(1);
    }
}

function build_zip(string $source, string $slug, string $out): void
{
    if (!class_exists('ZipArchive')) {
        fwrite(STDERR, "php zip extension is required\n");
        exit(1);
    }
    $source = rtrim($source, '/');
    $rules = new Distignore(is_file($source . '/.distignore') ? (string) file_get_contents($source . '/.distignore') : '');
    $dir = dirname($out);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "could not create zip directory\n");
        exit(1);
    }
    if (is_file($out)) {
        unlink($out);
    }
    $zip = new ZipArchive();
    if ($zip->open($out, ZipArchive::CREATE) !== true) {
        fwrite(STDERR, "could not create zip\n");
        exit(1);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $added = 0;
    foreach ($iterator as $item) {
        $full = $item->getPathname();
        $relative = substr($full, strlen($source) + 1);
        $relative = str_replace('\\', '/', (string) $relative);
        if ($item->isDir()) {
            if ($rules->excluded($relative)) {
                continue;
            }
            continue;
        }
        if (!$item->isFile() || $rules->excluded($relative)) {
            continue;
        }
        $zip->addFile($full, $slug . '/' . $relative);
        $added++;
    }
    $zip->close();
    if ($added === 0) {
        fwrite(STDERR, "zip is empty\n");
        exit(1);
    }
    fwrite(STDOUT, "zip {$slug} files={$added}\n");
}

final class Distignore
{
    /** @var list<array{neg: bool, pattern: string}> */
    private $rules;

    public function __construct(string $text)
    {
        $this->rules = [
            ['neg' => false, 'pattern' => '/.git'],
            ['neg' => false, 'pattern' => '.git'],
        ];
        foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
            if (!is_string($line)) {
                continue;
            }
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $neg = false;
            if ($line[0] === '!') {
                $neg = true;
                $line = substr($line, 1);
            }
            if ($line === '') {
                continue;
            }
            $this->rules[] = ['neg' => $neg, 'pattern' => $line];
        }
    }

    public function excluded(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $excluded = false;
        foreach ($this->rules as $rule) {
            if ($this->matches($path, $rule['pattern'])) {
                $excluded = !$rule['neg'];
            }
        }

        return $excluded;
    }

    private function matches(string $path, string $pattern): bool
    {
        $anchored = false;
        if ($pattern !== '' && $pattern[0] === '/') {
            $anchored = true;
            $pattern = substr($pattern, 1);
        }
        $dirOnly = false;
        if ($pattern !== '' && substr($pattern, -1) === '/') {
            $dirOnly = true;
            $pattern = substr($pattern, 0, -1);
        }
        if ($pattern === '') {
            return false;
        }
        if (!$anchored && strpos($pattern, '/') === false) {
            $anchored = false;
        } elseif (strpos($pattern, '/') !== false) {
            $anchored = true;
        }
        $regex = self::to_regex($pattern);
        if ($anchored) {
            if (preg_match('#^' . $regex . '$#', $path) === 1) {
                return true;
            }

            return preg_match('#^' . $regex . '/#', $path) === 1;
        }
        if (preg_match('#(^|/)' . $regex . '$#', $path) === 1) {
            return !$dirOnly || $path === $pattern || substr($path, -strlen($pattern) - 1) === '/' . $pattern;
        }

        return preg_match('#(^|/)' . $regex . '/#', $path) === 1;
    }

    private static function to_regex(string $pattern): string
    {
        $out = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '*' && ($i + 1) < $length && $pattern[$i + 1] === '*') {
                $out .= '.*';
                $i++;
                if (($i + 1) < $length && $pattern[$i + 1] === '/') {
                    $i++;
                }
                continue;
            }
            if ($char === '*') {
                $out .= '[^/]*';
                continue;
            }
            if ($char === '?') {
                $out .= '[^/]';
                continue;
            }
            $out .= preg_quote($char, '#');
        }

        return $out;
    }
}
