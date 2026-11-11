<?php

/**
 * Assertions against the self-test stub. Prints nothing secret.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

$command = isset($argv[1]) ? (string) $argv[1] : '';
$base = getenv('WPBR_SERVER_URL');
if (!is_string($base) || $base === '') {
    fwrite(STDERR, "WPBR_SERVER_URL is required\n");
    exit(1);
}
$base = rtrim($base, '/');

if ($command === 'sha') {
    $file = require_arg(2);
    $sha = hash_file('sha256', $file);
    if (!is_string($sha)) {
        fwrite(STDERR, "could not hash the zip\n");
        exit(1);
    }
    echo $sha, "\n";
    exit(0);
}

if ($command === 'stored-sha') {
    echo only_release()['sha256'], "\n";
    exit(0);
}

if ($command === 'reset') {
    $state = state_path();
    $empty = json_encode([
        'next_id' => 1,
        'releases' => [],
        'requests' => [],
        'versions' => [],
    ], JSON_UNESCAPED_SLASHES);
    file_put_contents($state, $empty === false ? '{}' : $empty);
    $storage = getenv('WPBR_STUB_STORAGE');
    if (is_string($storage) && is_dir($storage)) {
        foreach (glob($storage . '/*.zip') ?: [] as $file) {
            unlink($file);
        }
    }
    exit(0);
}

if ($command === 'expect-no-upload') {
    $state = read_state();
    foreach ($state['requests'] as $request) {
        if (($request['path'] ?? '') === '/wp-json/wpbr-license/v1/releases') {
            fwrite(STDERR, "release endpoint was called\n");
            exit(1);
        }
    }
    if (count($state['releases']) !== 0) {
        fwrite(STDERR, "a release was stored\n");
        exit(1);
    }
    echo "no upload\n";
    exit(0);
}

if ($command === 'expect-draft') {
    $sha = require_arg(2);
    $slug = require_arg(3);
    $release = only_release();
    if ($release['status'] !== 'draft' || $release['sha256'] !== $sha || $release['product_slug'] !== $slug) {
        fwrite(STDERR, "draft release does not match\n");
        exit(1);
    }
    $info = post_json('/wp-json/wpbr-license/v1/update-info', update_body($slug, '1.0.0'));
    if ($info['status'] !== 200 || !is_array($info['json']) || ($info['json']['update_available'] ?? true) !== false) {
        fwrite(STDERR, "draft was offered by update-info\n");
        exit(1);
    }
    if (array_key_exists('package', $info['json']) || array_key_exists('sha256', $info['json'])) {
        fwrite(STDERR, "update-info included a package for a draft\n");
        exit(1);
    }
    $public = get_json('/wp-json/wpbr-license/v1/info/' . $slug);
    if ($public['status'] !== 200 || !is_array($public['json']) || $public['json']['version'] !== null) {
        fwrite(STDERR, "info exposed a draft version\n");
        exit(1);
    }
    if (strpos($public['body'], 'package') !== false) {
        fwrite(STDERR, "info included a package\n");
        exit(1);
    }
    echo "draft hidden sha256={$sha}\n";
    exit(0);
}

if ($command === 'expect-conflict') {
    $state = read_state();
    if (count($state['releases']) !== 1 || $state['releases'][0]['status'] !== 'draft') {
        fwrite(STDERR, "conflict stored or published a release\n");
        exit(1);
    }
    $saw = false;
    foreach ($state['requests'] as $request) {
        if (($request['path'] ?? '') === '/wp-json/wpbr-license/v1/releases' && (int) ($request['status'] ?? 0) === 409) {
            $saw = true;
        }
    }
    if (!$saw) {
        fwrite(STDERR, "409 was not recorded\n");
        exit(1);
    }
    $slug = (string) $state['releases'][0]['product_slug'];
    $info = post_json('/wp-json/wpbr-license/v1/update-info', update_body($slug, '1.0.0'));
    if ($info['status'] !== 200 || !is_array($info['json']) || ($info['json']['update_available'] ?? true) !== false) {
        fwrite(STDERR, "conflict left a published offer\n");
        exit(1);
    }
    echo "409 kept the draft unpublished\n";
    exit(0);
}

if ($command === 'publish-and-expect') {
    $sha = require_arg(2);
    $slug = require_arg(3);
    $id = (int) only_release()['id'];
    $published = request('POST', '/wp-json/wpbr-license/v1/releases/' . $id . '/publish', '', true);
    $payload = json_decode($published['body'], true);
    if ($published['status'] !== 200 || !is_array($payload) || ($payload['status'] ?? '') !== 'published') {
        fwrite(STDERR, "publish stand-in failed\n");
        exit(1);
    }
    if (!header_has($published['headers'], 'X-WPBR-Test-Only: publish-stand-in')) {
        fwrite(STDERR, "publish stand-in was not labeled test-only\n");
        exit(1);
    }
    $info = post_json('/wp-json/wpbr-license/v1/update-info', update_body($slug, '1.0.0'));
    if ($info['status'] !== 200 || !is_array($info['json']) || ($info['json']['update_available'] ?? false) !== true) {
        fwrite(STDERR, "published release was not offered\n");
        exit(1);
    }
    if (($info['json']['sha256'] ?? '') !== $sha || ($info['json']['allow_downgrade'] ?? true) !== false) {
        fwrite(STDERR, "offered sha256 did not match\n");
        exit(1);
    }
    $package = $info['json']['package'] ?? '';
    if (!is_string($package) || strpos($package, 'dom=example.com') === false) {
        fwrite(STDERR, "package URL was not signed for the normalized domain\n");
        exit(1);
    }
    $download = request('GET', (string) (parse_url($package, PHP_URL_PATH) . '?' . parse_url($package, PHP_URL_QUERY)), '', false);
    if ($download['status'] !== 200 || hash('sha256', $download['body']) !== $sha) {
        fwrite(STDERR, "downloaded zip sha256 did not match\n");
        exit(1);
    }
    echo "test-only publish stand-in offered sha256={$sha}\n";
    exit(0);
}

if ($command === 'expect-stored') {
    $expect = json_decode(require_arg(2), true);
    if (!is_array($expect)) {
        fwrite(STDERR, "expected metadata JSON is invalid\n");
        exit(1);
    }
    $release = only_release();
    foreach ($expect as $key => $value) {
        if (!is_string($key) || ($release[$key] ?? null) !== $value) {
            fwrite(STDERR, "stored {$key} mismatch\n");
            exit(1);
        }
    }
    echo "stored metadata matched\n";
    exit(0);
}

if ($command === 'expect-metadata-file') {
    $decoded = json_decode((string) file_get_contents(require_arg(2)), true);
    $expect = json_decode(require_arg(3), true);
    if (!is_array($decoded) || !is_array($expect)) {
        fwrite(STDERR, "metadata JSON is invalid\n");
        exit(1);
    }
    foreach ($expect as $key => $value) {
        if (!is_string($key) || ($decoded[$key] ?? null) !== $value) {
            fwrite(STDERR, "metadata file {$key} mismatch\n");
            exit(1);
        }
    }
    echo "metadata file matched\n";
    exit(0);
}

if ($command === 'reject-bad-sha') {
    $slug = require_arg(2);
    $before = count(read_state()['releases']);
    $zip = require_arg(3);
    $meta = json_encode([
        'product_slug' => $slug,
        'version' => '9.9.9',
        'changelog' => 'bad',
        'requires_wp' => '6.5',
        'requires_php' => '7.4',
        'tested_wp' => '6.7',
        'sha256' => str_repeat('a', 64),
    ], JSON_UNESCAPED_SLASHES);
    $response = post_release((string) $meta, $zip, $slug . '-9.9.9.zip');
    if ($response['status'] !== 400 || ($response['json']['code'] ?? '') !== 'invalid_request' || count(read_state()['releases']) !== $before) {
        fwrite(STDERR, "bad sha256 was stored or not rejected (HTTP {$response['status']})\n");
        exit(1);
    }
    echo "bad sha256 rejected\n";
    exit(0);
}

if ($command === 'reject-unknown-field') {
    $slug = require_arg(2);
    $before = count(read_state()['releases']);
    $zip = require_arg(3);
    $meta = (string) json_encode([
        'product_slug' => $slug,
        'version' => '9.9.6',
        'changelog' => 'unknown',
        'requires_wp' => '6.5',
        'requires_php' => '7.4',
        'tested_wp' => '6.7',
        'sha256' => hash_file('sha256', $zip),
        'note' => 'no',
    ], JSON_UNESCAPED_SLASHES);
    $response = post_release($meta, $zip, $slug . '-9.9.6.zip');
    if ($response['status'] !== 400 || ($response['json']['code'] ?? '') !== 'invalid_request' || count(read_state()['releases']) !== $before) {
        fwrite(STDERR, "unknown metadata field was accepted (HTTP {$response['status']})\n");
        exit(1);
    }
    echo "unknown metadata field rejected\n";
    exit(0);
}

if ($command === 'reject-wrong-part') {
    $slug = require_arg(2);
    $before = count(read_state()['releases']);
    $zip = require_arg(3);
    $meta = (string) json_encode([
        'product_slug' => $slug,
        'version' => '9.9.8',
        'changelog' => 'wrong part',
        'requires_wp' => '6.5',
        'requires_php' => '7.4',
        'tested_wp' => '6.7',
        'sha256' => hash_file('sha256', $zip),
    ], JSON_UNESCAPED_SLASHES);
    $response = post_release($meta, $zip, $slug . '-9.9.8.zip', 'file');
    if ($response['status'] !== 400 || ($response['json']['code'] ?? '') !== 'invalid_request' || count(read_state()['releases']) !== $before) {
        fwrite(STDERR, "part named file was accepted (HTTP {$response['status']})\n");
        exit(1);
    }
    echo "part file rejected\n";
    exit(0);
}

if ($command === 'reject-loose-fields') {
    $slug = require_arg(2);
    $before = count(read_state()['releases']);
    $zip = require_arg(3);
    $sha = hash_file('sha256', $zip);
    $ch = curl_init($base . '/wp-json/wpbr-license/v1/releases');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, 'wpbr-release:stub-only-not-a-secret');
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'product_slug' => $slug,
        'version' => '9.9.7',
        'changelog' => 'loose',
        'requires_wp' => '6.5',
        'requires_php' => '7.4',
        'tested_wp' => '6.7',
        'sha256' => $sha,
        'zip' => new CURLFile($zip, 'application/zip', $slug . '-9.9.7.zip'),
    ]);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Expect:']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if ($http !== 400 || !is_array($decoded) || ($decoded['code'] ?? '') !== 'invalid_request' || count(read_state()['releases']) !== $before) {
        fwrite(STDERR, "loose form fields were accepted (HTTP {$http})\n");
        exit(1);
    }
    echo "loose form fields rejected\n";
    exit(0);
}

if ($command === 'reject-unauthorized') {
    $before = count(read_state()['releases']);
    $response = request('POST', '/wp-json/wpbr-license/v1/releases', '', false);
    if ($response['status'] !== 401 || count(read_state()['releases']) !== $before) {
        fwrite(STDERR, "missing credentials were accepted\n");
        exit(1);
    }
    echo "unauthorized rejected\n";
    exit(0);
}

fwrite(STDERR, "unknown stub-call command\n");
exit(1);

function require_arg(int $index): string
{
    global $argv;
    if (!isset($argv[$index]) || $argv[$index] === '') {
        fwrite(STDERR, "missing argument\n");
        exit(1);
    }

    return (string) $argv[$index];
}

/**
 * @return array<string, mixed>
 */
function read_state(): array
{
    $decoded = json_decode((string) file_get_contents(state_path()), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "stub state is unreadable\n");
        exit(1);
    }

    return $decoded;
}

function state_path(): string
{
    $path = getenv('WPBR_STUB_STATE');
    if (!is_string($path) || $path === '') {
        fwrite(STDERR, "WPBR_STUB_STATE is required\n");
        exit(1);
    }

    return $path;
}

/**
 * @return array<string, mixed>
 */
function only_release(): array
{
    $state = read_state();
    if (count($state['releases']) !== 1) {
        fwrite(STDERR, "expected one release\n");
        exit(1);
    }

    return $state['releases'][0];
}

function update_body(string $slug, string $version): string
{
    $encoded = json_encode([
        'instance_token' => '0123456789abcdef0123456789abcdef',
        'domain' => 'https://Example.com/path',
        'product_slug' => $slug,
        'version' => $version,
    ], JSON_UNESCAPED_SLASHES);

    return is_string($encoded) ? $encoded : '';
}

/**
 * @return array{status: int, body: string, json: mixed}
 */
function post_json(string $path, string $body): array
{
    $response = request('POST', $path, $body, false);
    $response['json'] = json_decode($response['body'], true);

    return $response;
}

/**
 * @return array{status: int, body: string, json: mixed}
 */
function get_json(string $path): array
{
    $response = request('GET', $path, '', false);
    $response['json'] = json_decode($response['body'], true);

    return $response;
}

/**
 * @param list<string> $headers
 */
function header_has(array $headers, string $needle): bool
{
    foreach ($headers as $header) {
        if (strcasecmp(trim($header), $needle) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{status: int, json: mixed}
 */
function post_release(string $metadata, string $zipPath, string $filename, string $zipPart = 'zip'): array
{
    global $base;
    $zipBytes = (string) file_get_contents($zipPath);
    $boundary = 'wpbr' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"metadata\"\r\n"
        . "Content-Type: application/json\r\n\r\n"
        . $metadata . "\r\n"
        . "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"{$zipPart}\"; filename=\"{$filename}\"\r\n"
        . "Content-Type: application/zip\r\n\r\n"
        . $zipBytes . "\r\n"
        . "--{$boundary}--\r\n";
    $ch = curl_init($base . '/wp-json/wpbr-license/v1/releases');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, 'wpbr-release:stub-only-not-a-secret');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Expect:',
        'Content-Type: multipart/form-data; boundary=' . $boundary,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $http,
        'json' => is_string($raw) ? json_decode($raw, true) : null,
    ];
}

/**
 * @return array{status: int, body: string, headers: list<string>}
 */
function request(string $method, string $path, string $body, bool $auth): array
{
    global $base;
    $url = strpos($path, 'http') === 0 ? $path : $base . $path;
    $headers = "Accept: application/json\r\n";
    if ($auth) {
        $headers .= 'Authorization: Basic ' . base64_encode('wpbr-release:stub-only-not-a-secret') . "\r\n";
    }
    if ($method === 'POST' && $body !== '') {
        $headers .= "Content-Type: application/json\r\n";
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $match) === 1) {
        $status = (int) $match[1];
    }

    $headers = [];
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (is_string($header)) {
                $headers[] = $header;
            }
        }
    }

    return ['status' => $status, 'body' => is_string($raw) ? $raw : '', 'headers' => $headers];
}
