<?php

/**
 * Local stand-in for wpbr-license/v1 /releases, /releases/{id}/yank, and /update-info.
 *
 * Speaks the da084ce JSON schemas under schema/. POST /releases is multipart
 * parts `metadata` (application/json) and `zip` only. A create returns HTTP 200
 * and status draft. A product_slug+version that was accepted once is tombstoned:
 * a later upload is 409 invalid_request, including after a yank, after the row
 * is removed, and when the sha256 is identical. Drafts and yanked releases are
 * never offered. Yanking the latest published release offers the previous
 * published one. allow_downgrade is true only when that offer is older than
 * the client.
 *
 * POST /releases/{id}/yank is the real route: numeric id, Basic auth with
 * manage_wpbr_releases, body empty or {} or {"reason":"..."}. Customer tokens
 * are 401. A draft yank is 409. An unknown id is release_not_found. Repeating
 * a yank is 200.
 *
 * TEST ONLY: POST /releases/{id}/publish stands in for the WPBR releases screen
 * (an admin POST with a nonce). It is not a REST route on the real server.
 * The workflow never calls it. The response sets X-WPBR-Test-Only: publish-stand-in.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' && PHP_SAPI !== 'cli') {
    http_response_code(500);
    echo 'cli-server only';
    exit;
}

const WPBR_STUB_USER = 'wpbr-release';
const WPBR_STUB_NOCAP_USER = 'wpbr-nocap';
const WPBR_STUB_PASSWORD = 'stub-only-not-a-secret';
const WPBR_STUB_TOKEN = '0123456789abcdef0123456789abcdef';
const WPBR_STUB_HMAC = 'stub-download-secret';

handle_request();

function handle_request(): void
{
    $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';

    if ($method === 'GET' && $path === '/health') {
        text_response(200, 'ok');
        return;
    }

    $state = load_state();
    $status = 500;
    $body = '';
    $contentType = 'application/json';
    $testOnlyPublish = false;

    if ($method === 'POST' && $path === '/wp-json/wpbr-license/v1/releases') {
        [$status, $body] = create_release($state);
    } elseif ($method === 'POST' && preg_match('#^/wp-json/wpbr-license/v1/releases/([1-9][0-9]*)/publish$#', $path, $match) === 1) {
        $testOnlyPublish = true;
        [$status, $body] = publish_release($state, (int) $match[1]);
    } elseif ($method === 'POST' && preg_match('#^/wp-json/wpbr-license/v1/releases/([1-9][0-9]*)/yank$#', $path, $match) === 1) {
        [$status, $body] = yank_release($state, (int) $match[1]);
    } elseif ($method === 'POST' && $path === '/wp-json/wpbr-license/v1/update-info') {
        [$status, $body] = update_info($state);
    } elseif ($method === 'GET' && preg_match('#^/wp-json/wpbr-license/v1/info/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $path, $match) === 1) {
        [$status, $body] = product_info($state, $match[1]);
    } elseif ($method === 'GET' && preg_match('#^/wpbr-download/([1-9][0-9]*)$#', $path, $match) === 1) {
        [$status, $body, $contentType] = download_release($state, (int) $match[1]);
    } else {
        $status = 404;
        $body = error_body(404, 'release_not_found', 'Not found.');
    }

    $state['requests'][] = [
        'method' => $method,
        'path' => $path,
        'status' => $status,
    ];
    save_state($state);

    if ($testOnlyPublish) {
        header('X-WPBR-Test-Only: publish-stand-in');
    }

    if ($contentType === 'application/zip') {
        $release = release_by_id($state, (int) $match[1]);
        if ($release === null) {
            header('Content-Type: application/json');
            http_response_code(404);
            echo error_body(404, 'release_not_found', 'Release not found.');
            return;
        }
        $file = (string) $release['file'];
        http_response_code(200);
        header('Content-Type: application/zip');
        header('Content-Length: ' . (string) filesize($file));
        header('Cache-Control: private, no-store');
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return;
        }
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            echo $chunk;
        }
        fclose($handle);
        return;
    }

    header('Content-Type: application/json');
    http_response_code($status);
    echo $body;
}

/**
 * @param array<string, mixed> $state
 * @return array{0: int, 1: string}
 */
function create_release(array &$state): array
{
    $authError = release_auth_error();
    if ($authError !== null) {
        return $authError;
    }
    $shape = multipart_shape_error();
    if ($shape !== null) {
        return $shape;
    }
    $meta = metadata_json();
    if ($meta === null) {
        return [400, error_body(400, 'invalid_request', 'metadata must be JSON.')];
    }
    $required = ['product_slug', 'version', 'changelog', 'requires_wp', 'requires_php', 'tested_wp', 'sha256'];
    foreach ($required as $field) {
        if (!isset($meta[$field]) || !is_string($meta[$field])) {
            return [400, error_body(400, 'invalid_request', 'metadata field ' . $field . ' is required.')];
        }
    }
    if (count(array_diff(array_keys($meta), array_merge($required, ['channel']))) !== 0) {
        return [400, error_body(400, 'invalid_request', 'metadata has an unknown field.')];
    }
    if (isset($meta['channel']) && $meta['channel'] !== 'stable') {
        return [400, error_body(400, 'invalid_request', 'channel must be stable.')];
    }
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $meta['product_slug']) !== 1) {
        return [400, error_body(400, 'invalid_request', 'product_slug is invalid.')];
    }
    foreach (['version', 'requires_wp', 'requires_php', 'tested_wp'] as $field) {
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $meta[$field]) !== 1) {
            return [400, error_body(400, 'invalid_request', $field . ' is invalid.')];
        }
    }
    if (strlen($meta['changelog']) > 100000 || preg_match('/^[a-f0-9]{64}$/', $meta['sha256']) !== 1) {
        return [400, error_body(400, 'invalid_request', 'changelog or sha256 is invalid.')];
    }
    $file = uploaded_zip();
    if ($file === null) {
        return [400, error_body(400, 'invalid_request', 'zip must be a zip file.')];
    }
    $actual = hash_file('sha256', $file);
    if (!is_string($actual) || !hash_equals($meta['sha256'], $actual)) {
        return [400, error_body(400, 'invalid_request', 'sha256 does not match the zip.')];
    }

    $key = $meta['product_slug'] . "\t" . $meta['version'];
    if (in_array($key, $state['versions'], true)) {
        return [409, error_body(409, 'invalid_request', 'Version already exists.')];
    }

    $id = (int) $state['next_id'];
    $state['next_id'] = $id + 1;
    $storage = storage_dir();
    $dest = $storage . '/' . $id . '.zip';
    if (!copy($file, $dest)) {
        return [500, error_body(500, 'server_error', 'Could not store the zip.')];
    }
    $created = gmdate('Y-m-d\TH:i:s\Z');
    $release = [
        'id' => $id,
        'product_slug' => $meta['product_slug'],
        'version' => $meta['version'],
        'channel' => 'stable',
        'sha256' => $actual,
        'size' => (int) filesize($dest),
        'status' => 'draft',
        'created_at' => $created,
        'changelog' => $meta['changelog'],
        'requires_wp' => $meta['requires_wp'],
        'requires_php' => $meta['requires_php'],
        'tested_wp' => $meta['tested_wp'],
        'file' => $dest,
    ];
    $state['releases'][] = $release;
    $state['versions'][] = $key;

    $encoded = json_encode(public_release($release), JSON_UNESCAPED_SLASHES);

    return [200, is_string($encoded) ? $encoded : ''];
}

/**
 * TEST ONLY. Not a route on the real server. Publishing there is an admin POST
 * on the WPBR releases screen, with a nonce. This exists so the self-test can
 * show that update-info stays empty until that happens.
 *
 * @param array<string, mixed> $state
 * @return array{0: int, 1: string}
 */
function publish_release(array &$state, int $id): array
{
    $authError = release_auth_error();
    if ($authError !== null) {
        return $authError;
    }
    foreach ($state['releases'] as $index => $release) {
        if ((int) $release['id'] !== $id) {
            continue;
        }
        $state['releases'][$index]['status'] = 'published';

        return [200, json_encode(public_release($state['releases'][$index]), JSON_UNESCAPED_SLASHES)];
    }

    return [404, error_body(404, 'release_not_found', 'Release not found.')];
}

/**
 * POST /releases/{id}/yank. Idempotent for an already-yanked release.
 * A draft is 409 invalid_request. The version stays tombstoned.
 *
 * @param array<string, mixed> $state
 * @return array{0: int, 1: string}
 */
function yank_release(array &$state, int $id): array
{
    $authError = release_auth_error();
    if ($authError !== null) {
        return $authError;
    }
    $parsed = yank_body();
    if ($parsed === null) {
        return [400, error_body(400, 'invalid_request', 'Yank body must be empty, {}, or {"reason":"..."}.')];
    }
    foreach ($state['releases'] as $index => $release) {
        if ((int) $release['id'] !== $id) {
            continue;
        }
        if ($release['status'] === 'draft') {
            return [409, error_body(409, 'invalid_request', 'Publish or delete this draft. Yank applies to a published release.')];
        }
        $state['releases'][$index]['status'] = 'yanked';
        $encoded = json_encode([
            'id' => $id,
            'status' => 'yanked',
        ], JSON_UNESCAPED_SLASHES);

        return [200, is_string($encoded) ? $encoded : ''];
    }

    return [404, error_body(404, 'release_not_found', 'Release not found.')];
}

/**
 * Empty, {}, or {"reason":"..."}. Anything else is rejected.
 */
function yank_body(): ?array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw)) {
        return null;
    }
    $raw = trim($raw);
    if ($raw === '' || $raw === '{}') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    $keys = [];
    foreach (array_keys($decoded) as $key) {
        if (!is_string($key)) {
            return null;
        }
        $keys[] = $key;
    }
    if ($keys === []) {
        return [];
    }
    if ($keys !== ['reason'] || !isset($decoded['reason']) || !is_string($decoded['reason'])) {
        return null;
    }

    return ['reason' => $decoded['reason']];
}

/**
 * @param array<string, mixed> $state
 * @return array{0: int, 1: string}
 */
function update_info(array $state): array
{
    $raw = (string) file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        return [400, error_body(400, 'invalid_request', 'Invalid request.')];
    }
    $allowed = ['instance_token', 'domain', 'product_slug', 'version'];
    foreach (array_keys($body) as $key) {
        if (!is_string($key) || !in_array($key, $allowed, true)) {
            return [400, error_body(400, 'invalid_request', 'Invalid request.')];
        }
    }
    foreach ($allowed as $field) {
        if (!isset($body[$field]) || !is_string($body[$field])) {
            return [400, error_body(400, 'invalid_request', 'Invalid request.')];
        }
    }
    if (preg_match('/^[A-Za-z0-9_-]{32,255}$/', $body['instance_token']) !== 1) {
        return [401, error_body(401, 'invalid_token', 'Invalid token.')];
    }
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $body['product_slug']) !== 1) {
        return [400, error_body(400, 'invalid_request', 'Invalid request.')];
    }
    if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $body['version']) !== 1) {
        return [400, error_body(400, 'invalid_request', 'Invalid request.')];
    }
    $license = license_record($state, $body['instance_token']);
    if ($license === null) {
        return [401, error_body(401, 'invalid_token', 'Invalid token.')];
    }
    $domain = normalize_domain($body['domain']);
    if ($domain === '' || preg_match('/^[a-z0-9.-]+$/', $domain) !== 1) {
        return [400, error_body(400, 'invalid_request', 'Invalid domain.')];
    }

    $effective = effective_status($license);
    if ($effective === 'expired') {
        return [403, error_body(403, 'license_expired', 'License expired.')];
    }
    $summary = [
        'status' => $effective,
        'expires_at' => $license['expires_at'],
        'sites_used' => $license['sites_used'],
        'sites_limit' => $license['sites_limit'],
    ];
    $offered = null;
    if ($effective === 'active') {
        $offered = select_offer($state, $body['product_slug'], $license['pinned_version']);
    }
    if ($offered === null || version_compare($body['version'], (string) $offered['version'], 'eq')) {
        $encoded = json_encode([
            'update_available' => false,
            'license' => $summary,
        ], JSON_UNESCAPED_SLASHES);

        return [200, is_string($encoded) ? $encoded : ''];
    }

    $exp = time() + 900;
    $sig = hash_hmac('sha256', $offered['id'] . '|' . $domain . '|' . $exp, hmac_secret());
    $package = public_base() . '/wpbr-download/' . $offered['id'] . '?exp=' . $exp . '&dom=' . $domain . '&sig=' . $sig;
    $encoded = json_encode([
        'update_available' => true,
        'version' => $offered['version'],
        'package' => $package,
        'sha256' => $offered['sha256'],
        'requires_wp' => $offered['requires_wp'],
        'requires_php' => $offered['requires_php'],
        'tested' => $offered['tested_wp'],
        'changelog' => $offered['changelog'],
        'allow_downgrade' => version_compare((string) $offered['version'], $body['version'], 'lt'),
        'license' => $summary,
    ], JSON_UNESCAPED_SLASHES);

    return [200, is_string($encoded) ? $encoded : ''];
}

/**
 * @param array<string, mixed> $state
 * @return array{0: int, 1: string}
 */
function product_info(array $state, string $slug): array
{
    $offered = latest_published($state, $slug);
    if ($offered === null) {
        $payload = [
            'slug' => $slug,
            'name' => $slug,
            'version' => null,
            'changelog' => '',
            'requires_wp' => null,
            'requires_php' => null,
            'tested' => null,
        ];
    } else {
        $payload = [
            'slug' => $slug,
            'name' => $slug,
            'version' => $offered['version'],
            'changelog' => $offered['changelog'],
            'requires_wp' => $offered['requires_wp'],
            'requires_php' => $offered['requires_php'],
            'tested' => $offered['tested_wp'],
        ];
    }

    return [200, json_encode($payload, JSON_UNESCAPED_SLASHES)];
}

/**
 * @param array<string, mixed> $state
 * @return array{0: int, 1: string, 2: string}
 */
function download_release(array $state, int $id): array
{
    $release = release_by_id($state, $id);
    if ($release === null || $release['status'] !== 'published') {
        return [403, error_body(403, 'forbidden', 'Forbidden.'), 'application/json'];
    }
    $exp = isset($_GET['exp']) ? (string) $_GET['exp'] : '';
    $dom = isset($_GET['dom']) ? (string) $_GET['dom'] : '';
    $sig = isset($_GET['sig']) ? (string) $_GET['sig'] : '';
    if (preg_match('/^[1-9][0-9]*$/', $exp) !== 1 || preg_match('/^[a-z0-9.-]+$/', $dom) !== 1 || preg_match('/^[a-f0-9]{64}$/', $sig) !== 1) {
        return [403, error_body(403, 'forbidden', 'Forbidden.'), 'application/json'];
    }
    if ((int) $exp < time()) {
        return [403, error_body(403, 'forbidden', 'Forbidden.'), 'application/json'];
    }
    $expected = hash_hmac('sha256', $id . '|' . $dom . '|' . $exp, hmac_secret());
    if (!hash_equals($expected, $sig)) {
        return [403, error_body(403, 'forbidden', 'Forbidden.'), 'application/json'];
    }
    if (!is_file((string) $release['file'])) {
        return [404, error_body(404, 'release_not_found', 'Release not found.'), 'application/json'];
    }

    return [200, '', 'application/zip'];
}

/**
 * @param array<string, mixed> $release
 * @return array<string, mixed>
 */
function public_release(array $release): array
{
    return [
        'id' => $release['id'],
        'product_slug' => $release['product_slug'],
        'version' => $release['version'],
        'channel' => 'stable',
        'sha256' => $release['sha256'],
        'size' => $release['size'],
        'status' => $release['status'],
        'created_at' => $release['created_at'],
    ];
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>|null
 */
function latest_published(array $state, string $slug): ?array
{
    $best = null;
    foreach ($state['releases'] as $release) {
        if ($release['product_slug'] !== $slug || $release['status'] !== 'published') {
            continue;
        }
        if ($best === null || version_compare((string) $release['version'], (string) $best['version'], 'gt')) {
            $best = $release;
        }
    }

    return $best;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>|null
 */
function release_by_id(array $state, int $id): ?array
{
    foreach ($state['releases'] as $release) {
        if ((int) $release['id'] === $id) {
            return $release;
        }
    }

    return null;
}

/**
 * @return array{0: int, 1: string}|null
 */
function multipart_shape_error(): ?array
{
    foreach (array_keys($_FILES) as $key) {
        if ($key !== 'zip' && $key !== 'metadata') {
            return [400, error_body(400, 'invalid_request', 'Unexpected multipart part.')];
        }
    }
    foreach (array_keys($_POST) as $key) {
        if ($key !== 'metadata') {
            return [400, error_body(400, 'invalid_request', 'Loose form fields are not accepted.')];
        }
    }
    $metaFile = isset($_FILES['metadata']);
    $metaField = array_key_exists('metadata', $_POST);
    if ($metaFile && $metaField) {
        return [400, error_body(400, 'invalid_request', 'metadata part is duplicated.')];
    }
    if (!$metaFile && !$metaField) {
        return [400, error_body(400, 'invalid_request', 'Multipart is missing the metadata part.')];
    }
    if (!isset($_FILES['zip'])) {
        return [400, error_body(400, 'invalid_request', 'Multipart is missing the zip part.')];
    }
    if ($metaFile) {
        $type = isset($_FILES['metadata']['type']) ? (string) $_FILES['metadata']['type'] : '';
        if (stripos($type, 'application/json') !== 0) {
            return [400, error_body(400, 'invalid_request', 'metadata part must be application/json.')];
        }
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function metadata_json(): ?array
{
    $raw = '';
    if (isset($_POST['metadata']) && is_string($_POST['metadata'])) {
        $raw = $_POST['metadata'];
    } elseif (isset($_FILES['metadata']['tmp_name']) && is_string($_FILES['metadata']['tmp_name']) && is_file($_FILES['metadata']['tmp_name'])) {
        $raw = (string) file_get_contents($_FILES['metadata']['tmp_name']);
    }
    if ($raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    foreach (array_keys($decoded) as $key) {
        if (!is_string($key)) {
            return null;
        }
    }

    return $decoded;
}

function uploaded_zip(): ?string
{
    if (!isset($_FILES['zip']['tmp_name'], $_FILES['zip']['error'])) {
        return null;
    }
    if ((int) $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = $_FILES['zip']['tmp_name'];
    if (!is_string($tmp) || !is_file($tmp)) {
        return null;
    }
    $handle = fopen($tmp, 'rb');
    if ($handle === false) {
        return null;
    }
    $magic = fread($handle, 2);
    fclose($handle);
    if ($magic !== 'PK') {
        return null;
    }

    return $tmp;
}

/**
 * @return array{0: int, 1: string}|null
 */
function release_auth_error(): ?array
{
    $status = auth_status();
    if ($status === 'ok') {
        return null;
    }
    if ($status === 'forbidden') {
        return [403, error_body(403, 'forbidden', 'manage_wpbr_releases is required.')];
    }

    return [401, error_body(401, 'unauthorized', 'Unauthorized.')];
}

/**
 * ok, forbidden (authenticated, no manage_wpbr_releases), or unauthorized.
 * A Bearer customer token is unauthorized, not a WordPress user.
 */
function auth_status(): string
{
    $header = authorization_header();
    if (stripos($header, 'Bearer ') === 0) {
        return 'unauthorized';
    }
    $creds = basic_credentials();
    if ($creds === null) {
        return 'unauthorized';
    }
    if (same_secret($creds[0], expected_user()) && same_secret($creds[1], expected_password())) {
        return 'ok';
    }
    if (same_secret($creds[0], WPBR_STUB_NOCAP_USER) && same_secret($creds[1], expected_password())) {
        return 'forbidden';
    }

    return 'unauthorized';
}

function authorization_header(): string
{
    if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0 && is_string($value)) {
                    return $value;
                }
            }
        }
    }

    return '';
}

/**
 * @return array{0: string, 1: string}|null
 */
function basic_credentials(): ?array
{
    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) && is_string($_SERVER['PHP_AUTH_USER']) && is_string($_SERVER['PHP_AUTH_PW'])) {
        return [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']];
    }
    $header = authorization_header();
    if (stripos($header, 'Basic ') !== 0) {
        return null;
    }
    $decoded = base64_decode(substr($header, 6), true);
    if (!is_string($decoded) || strpos($decoded, ':') === false) {
        return null;
    }
    $parts = explode(':', $decoded, 2);

    return [$parts[0], $parts[1]];
}

/**
 * @param array<string, mixed> $state
 * @return array{status: string, pinned_version: mixed, expires_at: mixed, sites_used: int, sites_limit: int}|null
 */
function license_record(array $state, string $token): ?array
{
    if (!isset($state['licenses']) || !is_array($state['licenses'])) {
        if (strlen($token) === strlen(WPBR_STUB_TOKEN) && hash_equals(WPBR_STUB_TOKEN, $token)) {
            return [
                'status' => 'active',
                'pinned_version' => null,
                'expires_at' => null,
                'sites_used' => 1,
                'sites_limit' => 0,
            ];
        }

        return null;
    }
    if (!isset($state['licenses'][$token]) || !is_array($state['licenses'][$token])) {
        return null;
    }
    $row = $state['licenses'][$token];
    $expires = array_key_exists('expires_at', $row) ? $row['expires_at'] : null;

    return [
        'status' => isset($row['status']) && is_string($row['status']) ? $row['status'] : 'active',
        'pinned_version' => $row['pinned_version'] ?? null,
        'expires_at' => $expires,
        'sites_used' => isset($row['sites_used']) ? (int) $row['sites_used'] : 1,
        'sites_limit' => isset($row['sites_limit']) ? (int) $row['sites_limit'] : 0,
    ];
}

/**
 * Revoked wins over expiry. A past expires_at is expired.
 *
 * @param array{status: string, pinned_version: mixed, expires_at: mixed, sites_used: int, sites_limit: int} $license
 */
function effective_status(array $license): string
{
    if ($license['status'] === 'revoked') {
        return 'revoked';
    }
    if ($license['status'] === 'expired') {
        return 'expired';
    }
    $expires = $license['expires_at'];
    if (is_string($expires) && $expires !== '') {
        $stamp = strtotime($expires);
        if ($stamp !== false && $stamp < time()) {
            return 'expired';
        }
    }

    return 'active';
}

/**
 * Newest published stable release, capped at pinned_version.
 * An invalid pin offers nothing. Drafts and yanked releases are ignored.
 *
 * @param array<string, mixed> $state
 * @return array<string, mixed>|null
 */
function select_offer(array $state, string $slug, $pin): ?array
{
    if ($pin !== null && $pin !== '') {
        if (!is_string($pin) || preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $pin) !== 1) {
            return null;
        }
    } else {
        $pin = null;
    }
    $best = null;
    foreach ($state['releases'] as $release) {
        if (!is_array($release)) {
            continue;
        }
        if (($release['product_slug'] ?? '') !== $slug || ($release['status'] ?? '') !== 'published') {
            continue;
        }
        if (($release['channel'] ?? 'stable') !== 'stable') {
            continue;
        }
        if ($pin !== null && version_compare((string) $release['version'], $pin, 'gt')) {
            continue;
        }
        if ($best === null || version_compare((string) $release['version'], (string) $best['version'], 'gt')) {
            $best = $release;
        }
    }

    return $best;
}

function same_secret(string $left, string $right): bool
{
    if (strlen($left) !== strlen($right)) {
        return false;
    }

    return hash_equals($right, $left);
}

function expected_user(): string
{
    $value = getenv('WPBR_STUB_USER');

    return is_string($value) && $value !== '' ? $value : WPBR_STUB_USER;
}

function expected_password(): string
{
    $value = getenv('WPBR_STUB_PASSWORD');

    return is_string($value) && $value !== '' ? $value : WPBR_STUB_PASSWORD;
}

function hmac_secret(): string
{
    $value = getenv('WPBR_STUB_SECRET');

    return is_string($value) && $value !== '' ? $value : WPBR_STUB_HMAC;
}

function public_base(): string
{
    $value = getenv('WPBR_STUB_PUBLIC_URL');
    if (is_string($value) && $value !== '') {
        return rtrim($value, '/');
    }
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '127.0.0.1:8765';

    return 'http://' . $host;
}

function normalize_domain(string $raw): string
{
    $raw = trim($raw);
    $raw = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $raw);
    $raw = (string) preg_replace('#/.*$#', '', $raw);
    $raw = (string) preg_replace('#:\d+$#', '', $raw);
    $raw = strtolower($raw);
    if (strpos($raw, 'www.') === 0) {
        $raw = substr($raw, 4);
    }

    return rtrim($raw, '.');
}

function error_body(int $status, string $code, string $message): string
{
    $encoded = json_encode([
        'code' => $code,
        'message' => $message,
        'data' => ['status' => $status],
    ], JSON_UNESCAPED_SLASHES);

    return is_string($encoded) ? $encoded : '';
}

function text_response(int $status, string $body): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $body;
}

/**
 * @return array<string, mixed>
 */
function load_state(): array
{
    $path = state_path();
    if (!is_file($path)) {
        return [
            'next_id' => 1,
            'releases' => [],
            'requests' => [],
            'versions' => [],
        ];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [
            'next_id' => 1,
            'releases' => [],
            'requests' => [],
            'versions' => [],
        ];
    }
    if (!isset($decoded['next_id'])) {
        $decoded['next_id'] = 1;
    }
    if (!isset($decoded['releases']) || !is_array($decoded['releases'])) {
        $decoded['releases'] = [];
    }
    if (!isset($decoded['requests']) || !is_array($decoded['requests'])) {
        $decoded['requests'] = [];
    }
    if (!isset($decoded['versions']) || !is_array($decoded['versions'])) {
        $decoded['versions'] = [];
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $state
 */
function save_state(array $state): void
{
    $path = state_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $encoded = json_encode($state, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return;
    }
    $tmp = $path . '.tmp';
    file_put_contents($tmp, $encoded);
    rename($tmp, $path);
}

function state_path(): string
{
    $path = getenv('WPBR_STUB_STATE');
    if (is_string($path) && $path !== '') {
        return $path;
    }

    return sys_get_temp_dir() . '/wpbr-stub-state.json';
}

function storage_dir(): string
{
    $path = getenv('WPBR_STUB_STORAGE');
    if (!is_string($path) || $path === '') {
        $path = sys_get_temp_dir() . '/wpbr-stub-storage';
    }
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }

    return $path;
}
