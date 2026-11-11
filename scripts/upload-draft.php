<?php

/**
 * POST a zip and metadata to wpbr-license/v1 /releases.
 *
 * Credentials come from the environment. This script does not print them.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "php curl extension is required\n");
    exit(1);
}

$zip = isset($argv[1]) ? (string) $argv[1] : '';
$metadataPath = isset($argv[2]) ? (string) $argv[2] : '';
$user = getenv('WPBR_RELEASE_USER');
$password = getenv('WPBR_RELEASE_APP_PASSWORD');
$server = getenv('WPBR_SERVER_URL');
$resultPath = getenv('WPBR_RESULT_FILE');

if ($zip === '' || $metadataPath === '' || !is_string($user) || $user === '' || !is_string($password) || $password === '' || !is_string($server) || $server === '') {
    fwrite(STDERR, "zip, metadata, and release credentials are required\n");
    exit(1);
}
if (!is_file($zip) || !is_file($metadataPath)) {
    fwrite(STDERR, "zip or metadata file is missing\n");
    exit(1);
}
if (preg_match('#^https?://#', $server) !== 1) {
    fwrite(STDERR, "WPBR_SERVER_URL must start with http:// or https://\n");
    exit(1);
}

$metadata = (string) file_get_contents($metadataPath);
$decoded = json_decode($metadata, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "metadata JSON is invalid\n");
    exit(1);
}
$allowed = ['product_slug', 'version', 'changelog', 'requires_wp', 'requires_php', 'tested_wp', 'sha256', 'channel'];
foreach (array_keys($decoded) as $key) {
    if (!is_string($key) || !in_array($key, $allowed, true)) {
        fwrite(STDERR, "metadata has an unknown field before upload\n");
        exit(1);
    }
}
foreach (['product_slug', 'version', 'changelog', 'requires_wp', 'requires_php', 'tested_wp', 'sha256'] as $field) {
    if (!isset($decoded[$field]) || !is_string($decoded[$field]) || $decoded[$field] === '') {
        fwrite(STDERR, "metadata {$field} is missing before upload\n");
        exit(1);
    }
}
if (isset($decoded['channel']) && $decoded['channel'] !== 'stable') {
    fwrite(STDERR, "metadata channel is not stable\n");
    exit(1);
}
if (strlen($decoded['changelog']) > 100000 || preg_match('/^[a-f0-9]{64}$/', $decoded['sha256']) !== 1) {
    fwrite(STDERR, "metadata changelog or sha256 is invalid\n");
    exit(1);
}
$localSha = hash_file('sha256', $zip);
if (!is_string($localSha) || !hash_equals($decoded['sha256'], $localSha)) {
    fwrite(STDERR, "metadata sha256 does not match the zip\n");
    exit(1);
}

$url = rtrim($server, '/') . '/wp-json/wpbr-license/v1/releases';
$filename = $decoded['product_slug'] . '-' . $decoded['version'] . '.zip';
$boundary = '';
$body = multipart_body($metadata, $zip, $filename, $boundary);
if ($body === null) {
    fwrite(STDERR, "could not build the multipart body\n");
    exit(1);
}

$ch = curl_init($url);
if ($ch === false) {
    fwrite(STDERR, "could not start the upload request\n");
    exit(1);
}
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $password);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Expect:',
    'Content-Type: multipart/form-data; boundary=' . $boundary,
]);

$body = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false || $errno !== 0) {
    fwrite(STDERR, "upload request failed: {$error}\n");
    exit(1);
}
if (!is_string($body)) {
    fwrite(STDERR, "upload response was empty\n");
    exit(1);
}

if ($http !== 200) {
    fail_http($http, $body);
}

$payload = json_decode($body, true);
if (!is_array($payload)) {
    fwrite(STDERR, "upload response was not JSON\n");
    exit(1);
}
$expectedKeys = ['id', 'product_slug', 'version', 'channel', 'sha256', 'size', 'status', 'created_at'];
foreach (array_keys($payload) as $key) {
    if (!is_string($key) || !in_array($key, $expectedKeys, true)) {
        fwrite(STDERR, "upload response has an unknown field\n");
        exit(1);
    }
}
foreach ($expectedKeys as $field) {
    if (!array_key_exists($field, $payload)) {
        fwrite(STDERR, "upload response missing {$field}\n");
        exit(1);
    }
}
if (!is_int($payload['id']) || $payload['id'] < 1 || !is_int($payload['size']) || $payload['size'] < 0) {
    fwrite(STDERR, "upload response id or size is invalid\n");
    exit(1);
}
if (!is_string($payload['created_at']) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/', $payload['created_at']) !== 1) {
    fwrite(STDERR, "upload response created_at is invalid\n");
    exit(1);
}
if ($payload['status'] !== 'draft') {
    fwrite(STDERR, "server status is {$payload['status']}; expected draft. Refusing to treat this as unpublished.\n");
    exit(1);
}
if (!is_string($payload['sha256']) || !hash_equals($localSha, $payload['sha256']) || $payload['product_slug'] !== $decoded['product_slug'] || $payload['version'] !== $decoded['version']) {
    fwrite(STDERR, "upload response does not match the zip we sent\n");
    exit(1);
}
if ($payload['channel'] !== 'stable') {
    fwrite(STDERR, "upload response channel is not stable\n");
    exit(1);
}

$result = [
    'id' => $payload['id'],
    'product_slug' => $payload['product_slug'],
    'version' => $payload['version'],
    'sha256' => $payload['sha256'],
    'status' => $payload['status'],
    'http_status' => $http,
];
$encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($encoded)) {
    fwrite(STDERR, "could not encode the result\n");
    exit(1);
}
if (is_string($resultPath) && $resultPath !== '') {
    if (file_put_contents($resultPath, $encoded . "\n") === false) {
        fwrite(STDERR, "could not write the result file\n");
        exit(1);
    }
}

echo 'draft id=' . $payload['id']
    . ' slug=' . $payload['product_slug']
    . ' version=' . $payload['version']
    . ' sha256=' . $payload['sha256']
    . ' status=draft'
    . "\n";

function fail_http(int $http, string $body): void
{
    $decoded = json_decode($body, true);
    $code = '';
    $message = '';
    if (is_array($decoded)) {
        if (isset($decoded['code']) && is_string($decoded['code'])) {
            $code = $decoded['code'];
        }
        if (isset($decoded['message']) && is_string($decoded['message'])) {
            $message = preg_replace('/[^\P{C}\n\t]/u', '', $decoded['message']);
            $message = is_string($message) ? $message : '';
            if (strlen($message) > 500) {
                $message = substr($message, 0, 500);
            }
        }
    }
    if ($code === '') {
        fwrite(STDERR, "upload failed with HTTP {$http}\n");
    } else {
        fwrite(STDERR, "upload failed with HTTP {$http} code={$code}\n");
    }
    if ($http === 409) {
        fwrite(STDERR, "this version was already used; nothing new was published\n");
    }
    if ($message !== '') {
        fwrite(STDERR, $message . "\n");
    }
    exit(1);
}

function multipart_body(string $metadata, string $zipPath, string $filename, string &$boundary): ?string
{
    $zipBytes = file_get_contents($zipPath);
    if (!is_string($zipBytes)) {
        return null;
    }
    $filename = str_replace(["\r", "\n", '"'], '', $filename);
    $boundary = '';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = 'wpbr' . bin2hex(random_bytes(16));
        if (strpos($metadata, $candidate) === false && strpos($zipBytes, $candidate) === false) {
            $boundary = $candidate;
            break;
        }
    }
    if ($boundary === '') {
        return null;
    }

    return "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"metadata\"\r\n"
        . "Content-Type: application/json\r\n\r\n"
        . $metadata . "\r\n"
        . "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"zip\"; filename=\"{$filename}\"\r\n"
        . "Content-Type: application/zip\r\n\r\n"
        . $zipBytes . "\r\n"
        . "--{$boundary}--\r\n";
}
