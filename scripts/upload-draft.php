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
if (!is_array($decoded) || !isset($decoded['sha256'], $decoded['product_slug'], $decoded['version'])) {
    fwrite(STDERR, "metadata JSON is invalid\n");
    exit(1);
}
$localSha = hash_file('sha256', $zip);
if (!is_string($localSha) || $localSha !== $decoded['sha256']) {
    fwrite(STDERR, "metadata sha256 does not match the zip\n");
    exit(1);
}

$url = rtrim($server, '/') . '/wp-json/wpbr-license/v1/releases';
$filename = $decoded['product_slug'] . '-' . $decoded['version'] . '.zip';
$file = new CURLFile($zip, 'application/zip', $filename);

$ch = curl_init($url);
if ($ch === false) {
    fwrite(STDERR, "could not start the upload request\n");
    exit(1);
}
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $password);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'metadata' => $metadata,
    'file' => $file,
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Expect:']);

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

if ($http === 409) {
    fwrite(STDERR, "server returned 409; this version was already used; nothing new was published\n");
    fwrite(STDERR, response_excerpt($body) . "\n");
    exit(1);
}
if ($http !== 200 && $http !== 201) {
    fwrite(STDERR, "upload failed with HTTP {$http}\n");
    fwrite(STDERR, response_excerpt($body) . "\n");
    exit(1);
}

$payload = json_decode($body, true);
if (!is_array($payload)) {
    fwrite(STDERR, "upload response was not JSON\n");
    exit(1);
}
foreach (['id', 'product_slug', 'version', 'channel', 'sha256', 'size', 'status', 'created_at'] as $field) {
    if (!array_key_exists($field, $payload)) {
        fwrite(STDERR, "upload response missing {$field}\n");
        exit(1);
    }
}
if ($payload['status'] !== 'draft') {
    fwrite(STDERR, "server status is {$payload['status']}; expected draft. Refusing to treat this as unpublished.\n");
    exit(1);
}
if ($payload['sha256'] !== $localSha || $payload['product_slug'] !== $decoded['product_slug'] || $payload['version'] !== $decoded['version']) {
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

function response_excerpt(string $body): string
{
    $body = preg_replace('/[^\P{C}\n\t]/u', '', $body);
    if (!is_string($body)) {
        return '';
    }
    if (strlen($body) > 500) {
        return substr($body, 0, 500);
    }

    return $body;
}
