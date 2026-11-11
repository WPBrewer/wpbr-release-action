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

if ($command === 'reject-forbidden') {
    $response = release_as('wpbr-nocap:stub-only-not-a-secret', '');
    if ($response['status'] !== 403 || ($response['json']['code'] ?? '') !== 'forbidden') {
        fwrite(STDERR, "nocap create was HTTP {$response['status']}\n");
        exit(1);
    }
    echo "forbidden without manage_wpbr_releases\n";
    exit(0);
}

if ($command === 'pack-plugin') {
    $slug = require_arg(2);
    $version = require_arg(3);
    $out = require_arg(4);
    $marker = isset($argv[5]) ? (string) $argv[5] : '';
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version) !== 1) {
        fwrite(STDERR, "pack-plugin slug or version is invalid\n");
        exit(1);
    }
    if (strpbrk($marker, "\r\n") !== false) {
        fwrite(STDERR, "pack-plugin marker is invalid\n");
        exit(1);
    }
    $php = "<?php\n/**\n * Plugin Name: Rollback Fixture\n * Version: {$version}\n * Requires at least: 6.5\n * Requires PHP: 7.4\n */\n";
    if ($marker !== '') {
        $php .= '/* ' . $marker . " */\n";
    }
    $dir = dirname($out);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "could not create the zip directory\n");
        exit(1);
    }
    $zip = new ZipArchive();
    if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "could not create the zip\n");
        exit(1);
    }
    $zip->addFromString($slug . '/' . $slug . '.php', $php);
    $zip->close();
    echo $out, "\n";
    exit(0);
}

if ($command === 'upload') {
    $slug = require_arg(2);
    $version = require_arg(3);
    $zip = require_arg(4);
    $sha = hash_file('sha256', $zip);
    if (!is_string($sha)) {
        fwrite(STDERR, "could not hash the zip\n");
        exit(1);
    }
    $meta = json_encode([
        'product_slug' => $slug,
        'version' => $version,
        'changelog' => 'Release ' . $version . '.',
        'requires_wp' => '6.5',
        'requires_php' => '7.4',
        'tested_wp' => '6.7',
        'sha256' => $sha,
    ], JSON_UNESCAPED_SLASHES);
    $response = post_release((string) $meta, $zip, $slug . '-' . $version . '.zip');
    if ($response['status'] !== 200 || !is_array($response['json']) || !isset($response['json']['id'])) {
        $code = is_array($response['json']) && isset($response['json']['code']) ? (string) $response['json']['code'] : '';
        fwrite(STDERR, "upload HTTP {$response['status']}" . ($code !== '' ? " code={$code}" : '') . "\n");
        exit(1);
    }
    echo $response['json']['id'], "\n";
    exit(0);
}

if ($command === 'publish') {
    $id = require_arg(2);
    if (preg_match('/^[1-9][0-9]*$/', $id) !== 1) {
        fwrite(STDERR, "publish id is invalid\n");
        exit(1);
    }
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
    echo "published id={$id}\n";
    exit(0);
}

if ($command === 'id-of') {
    $release = find_release(require_arg(2), require_arg(3));
    echo $release['id'], "\n";
    exit(0);
}

if ($command === 'status-of') {
    $release = find_release(require_arg(2), require_arg(3));
    echo $release['status'], "\n";
    exit(0);
}

if ($command === 'expect-count') {
    $want = (int) require_arg(2);
    $got = count(read_state()['releases']);
    if ($got !== $want) {
        fwrite(STDERR, "release count is {$got}, expected {$want}\n");
        exit(1);
    }
    echo "release count={$got}\n";
    exit(0);
}

if ($command === 'expect-tombstone') {
    $key = require_arg(2) . "\t" . require_arg(3);
    $state = read_state();
    if (!isset($state['versions']) || !is_array($state['versions']) || !in_array($key, $state['versions'], true)) {
        fwrite(STDERR, "version was not tombstoned\n");
        exit(1);
    }
    echo "tombstoned\n";
    exit(0);
}

if ($command === 'drop-release') {
    $state = read_state();
    $state['releases'] = [];
    write_state($state);
    echo "dropped release rows\n";
    exit(0);
}

if ($command === 'set-license') {
    $status = require_arg(2);
    if (!in_array($status, ['active', 'revoked', 'expired'], true)) {
        fwrite(STDERR, "bad license status\n");
        exit(1);
    }
    $pin = null;
    if (isset($argv[3]) && $argv[3] !== '') {
        $pin = (string) $argv[3];
    }
    $state = read_state();
    $state['licenses'] = [
        '0123456789abcdef0123456789abcdef' => [
            'status' => $status,
            'pinned_version' => $pin,
            'expires_at' => $status === 'expired' ? '2020-01-01T00:00:00Z' : null,
            'sites_used' => 1,
            'sites_limit' => 0,
        ],
    ];
    write_state($state);
    echo "license {$status}\n";
    exit(0);
}

if ($command === 'expect-update') {
    $slug = require_arg(2);
    $version = require_arg(3);
    $expect = json_decode(require_arg(4), true);
    if (!is_array($expect)) {
        fwrite(STDERR, "expected update JSON is invalid\n");
        exit(1);
    }
    $info = post_json('/wp-json/wpbr-license/v1/update-info', update_body($slug, $version));
    if ($info['status'] !== 200 || !is_array($info['json'])) {
        fwrite(STDERR, "update-info HTTP {$info['status']} {$info['body']}\n");
        exit(1);
    }
    $json = $info['json'];
    foreach ($expect as $key => $value) {
        if (!is_string($key)) {
            fwrite(STDERR, "expected update key is invalid\n");
            exit(1);
        }
        if ($key === 'license') {
            if (!is_array($value) || !isset($json['license']) || !is_array($json['license'])) {
                fwrite(STDERR, "license summary missing\n");
                exit(1);
            }
            foreach ($value as $licenseKey => $licenseValue) {
                if (!is_string($licenseKey) || ($json['license'][$licenseKey] ?? null) !== $licenseValue) {
                    fwrite(STDERR, "license {$licenseKey} mismatch\n");
                    exit(1);
                }
            }
            continue;
        }
        if (!array_key_exists($key, $json) || $json[$key] !== $value) {
            fwrite(STDERR, "update-info {$key} mismatch\n{$info['body']}\n");
            exit(1);
        }
    }
    if (($json['update_available'] ?? null) === false) {
        foreach (['package', 'sha256', 'allow_downgrade'] as $banned) {
            if (array_key_exists($banned, $json)) {
                fwrite(STDERR, "update-info included {$banned} without an offer\n");
                exit(1);
            }
        }
        echo "update-info update_available=false\n";
        exit(0);
    }
    if (($json['update_available'] ?? null) !== true) {
        fwrite(STDERR, "update_available missing\n");
        exit(1);
    }
    $package = $json['package'] ?? '';
    if (!is_string($package) || preg_match('#/wpbr-download/([1-9][0-9]*)\?exp=[1-9][0-9]*&dom=[a-z0-9.-]+&sig=[a-f0-9]{64}$#', $package, $match) !== 1) {
        fwrite(STDERR, "package URL is not a signed download\n");
        exit(1);
    }
    $offeredVersion = $json['version'] ?? '';
    $found = null;
    foreach (read_state()['releases'] as $release) {
        if (!is_array($release)) {
            continue;
        }
        if (($release['product_slug'] ?? '') === $slug && ($release['version'] ?? '') === $offeredVersion && ($release['status'] ?? '') === 'published') {
            $found = $release;
        }
        if (($release['product_slug'] ?? '') === $slug && ($release['status'] ?? '') === 'yanked' && (string) ($release['id'] ?? '') === $match[1]) {
            fwrite(STDERR, "yanked release was offered\n");
            exit(1);
        }
    }
    if ($found === null || (string) $found['id'] !== $match[1] || ($json['sha256'] ?? '') !== $found['sha256']) {
        fwrite(STDERR, "offered package is not the published release\n");
        exit(1);
    }
    $path = parse_url($package, PHP_URL_PATH);
    $query = parse_url($package, PHP_URL_QUERY);
    if (!is_string($path) || !is_string($query)) {
        fwrite(STDERR, "package URL could not be parsed\n");
        exit(1);
    }
    $download = request('GET', $path . '?' . $query, '', false);
    if ($download['status'] !== 200 || hash('sha256', $download['body']) !== $found['sha256']) {
        fwrite(STDERR, "downloaded zip did not match the offered release\n");
        exit(1);
    }
    $allow = ($json['allow_downgrade'] ?? false) === true ? 'true' : 'false';
    echo "update-info version={$offeredVersion} allow_downgrade={$allow}\n";
    exit(0);
}

if ($command === 'expect-expired') {
    $info = post_json('/wp-json/wpbr-license/v1/update-info', update_body(require_arg(2), require_arg(3)));
    if ($info['status'] !== 403 || !is_array($info['json']) || ($info['json']['code'] ?? '') !== 'license_expired') {
        fwrite(STDERR, "expired license was HTTP {$info['status']}\n");
        exit(1);
    }
    if (strpos($info['body'], 'package') !== false) {
        fwrite(STDERR, "expired response included a package\n");
        exit(1);
    }
    echo "license_expired\n";
    exit(0);
}

if ($command === 'expect-info') {
    $version = require_arg(3);
    $public = get_json('/wp-json/wpbr-license/v1/info/' . require_arg(2));
    $want = $version === 'null' ? null : $version;
    $got = is_array($public['json']) && array_key_exists('version', $public['json']) ? $public['json']['version'] : 'missing';
    if ($public['status'] !== 200 || $got !== $want) {
        fwrite(STDERR, "info version mismatch\n");
        exit(1);
    }
    if (strpos($public['body'], 'package') !== false) {
        fwrite(STDERR, "info included a package\n");
        exit(1);
    }
    echo 'info version=' . ($want === null ? 'null' : $want) . "\n";
    exit(0);
}

if ($command === 'expect-download-blocked') {
    $id = require_arg(2);
    $response = request('GET', '/wpbr-download/' . $id . '?exp=1893456000&dom=example.com&sig=' . str_repeat('ab', 32), '', false);
    if ($response['status'] !== 403) {
        fwrite(STDERR, "yanked download was HTTP {$response['status']}\n");
        exit(1);
    }
    echo "download blocked\n";
    exit(0);
}

if ($command === 'yank-auth') {
    $mode = require_arg(2);
    $id = require_arg(3);
    $expectHttp = (int) require_arg(4);
    $expectCode = require_arg(5);
    $body = '{}';
    if ($mode === 'empty') {
        $body = '';
    } elseif ($mode === 'bad-body') {
        $body = '{"extra":true}';
    } elseif ($mode === 'reason') {
        $body = '{"reason":"again"}';
    }
    $response = yank_http($mode, $id, $body);
    if ($response['status'] !== $expectHttp) {
        fwrite(STDERR, "yank {$mode} was HTTP {$response['status']}\n");
        exit(1);
    }
    if ($expectHttp === 200) {
        if (!is_array($response['json']) || ($response['json']['status'] ?? '') !== 'yanked' || (int) ($response['json']['id'] ?? 0) !== (int) $id) {
            fwrite(STDERR, "yank {$mode} body was not yanked\n");
            exit(1);
        }
        $keys = array_keys($response['json']);
        sort($keys);
        if ($keys !== ['id', 'status']) {
            fwrite(STDERR, "yank body has extra fields\n");
            exit(1);
        }
    } elseif (!is_array($response['json']) || ($response['json']['code'] ?? '') !== $expectCode) {
        fwrite(STDERR, "yank {$mode} code mismatch\n");
        exit(1);
    }
    echo "yank {$mode} HTTP {$expectHttp}\n";
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

/**
 * @param array<string, mixed> $state
 */
function write_state(array $state): void
{
    $encoded = json_encode($state, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || file_put_contents(state_path(), $encoded) === false) {
        fwrite(STDERR, "could not write stub state\n");
        exit(1);
    }
}

/**
 * @return array<string, mixed>
 */
function find_release(string $slug, string $version): array
{
    foreach (read_state()['releases'] as $release) {
        if (is_array($release) && ($release['product_slug'] ?? '') === $slug && ($release['version'] ?? '') === $version) {
            return $release;
        }
    }
    fwrite(STDERR, "release not found\n");
    exit(1);
}

/**
 * @return array{status: int, json: mixed}
 */
function release_as(string $userpwd, string $body): array
{
    global $base;
    $ch = curl_init($base . '/wp-json/wpbr-license/v1/releases');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Expect:']);
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
 * @return array{status: int, json: mixed}
 */
function yank_http(string $mode, string $id, string $body): array
{
    global $base;
    $ch = curl_init($base . '/wp-json/wpbr-license/v1/releases/' . $id . '/yank');
    $headers = ['Accept: application/json', 'Expect:'];
    if ($body !== '') {
        $headers[] = 'Content-Type: application/json';
    }
    $userpwd = null;
    if ($mode === 'bearer') {
        $headers[] = 'Authorization: Bearer 0123456789abcdef0123456789abcdef';
    } elseif ($mode === 'nocap') {
        $userpwd = 'wpbr-nocap:stub-only-not-a-secret';
    } elseif ($mode === 'customer-password') {
        $userpwd = 'wpbr-release:0123456789abcdef0123456789abcdef';
    } elseif ($mode === 'ok' || $mode === 'bad-body' || $mode === 'empty' || $mode === 'reason') {
        $userpwd = 'wpbr-release:stub-only-not-a-secret';
    } elseif ($mode !== 'none') {
        fwrite(STDERR, "unknown yank auth mode\n");
        exit(1);
    }
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($userpwd !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    }
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $http,
        'json' => is_string($raw) ? json_decode($raw, true) : null,
    ];
}
