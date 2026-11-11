<?php

/**
 * POST /wp-json/wpbr-license/v1/releases/{id}/yank.
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

$id = isset($argv[1]) ? (string) $argv[1] : '';
$user = getenv('WPBR_RELEASE_USER');
$password = getenv('WPBR_RELEASE_APP_PASSWORD');
$server = getenv('WPBR_SERVER_URL');
$reason = getenv('WPBR_YANK_REASON');
$resultPath = getenv('WPBR_RESULT_FILE');

if ($id === '' || !is_string($user) || $user === '' || !is_string($password) || $password === '' || !is_string($server) || $server === '') {
    fwrite(STDERR, "release id and release credentials are required\n");
    exit(1);
}
if (preg_match('/^[1-9][0-9]*$/', $id) !== 1) {
    fwrite(STDERR, "release id must be the numeric id\n");
    exit(1);
}
if (preg_match('#^https?://#', $server) !== 1) {
    fwrite(STDERR, "WPBR_SERVER_URL must start with http:// or https://\n");
    exit(1);
}

$payload = [];
if (is_string($reason) && $reason !== '') {
    $payload['reason'] = $reason;
}
$body = json_encode($payload, JSON_UNESCAPED_SLASHES);
if (!is_string($body)) {
    fwrite(STDERR, "could not encode the yank body\n");
    exit(1);
}

$url = rtrim($server, '/') . '/wp-json/wpbr-license/v1/releases/' . $id . '/yank';
$ch = curl_init($url);
if ($ch === false) {
    fwrite(STDERR, "could not start the yank request\n");
    exit(1);
}
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $password);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json',
    'Expect:',
]);

$raw = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $errno !== 0) {
    fwrite(STDERR, 'yank request failed: ' . scrub((string) $error, $user, $password, $server) . "\n");
    exit(1);
}
if (!is_string($raw)) {
    fwrite(STDERR, "yank response was empty\n");
    exit(1);
}

$decoded = json_decode($raw, true);
if ($http !== 200) {
    fail_http($http, is_array($decoded) ? $decoded : [], $user, $password, $server);
}

if (!is_array($decoded)) {
    fwrite(STDERR, "yank response was not JSON\n");
    exit(1);
}
$keys = [];
foreach (array_keys($decoded) as $key) {
    if (!is_string($key)) {
        fwrite(STDERR, "yank response has an unknown field\n");
        exit(1);
    }
    $keys[] = $key;
}
sort($keys);
if ($keys !== ['id', 'status']) {
    fwrite(STDERR, "yank response has an unknown field\n");
    exit(1);
}
if ((int) $decoded['id'] !== (int) $id || $decoded['status'] !== 'yanked') {
    fwrite(STDERR, "yank response was not status=yanked for this id\n");
    exit(1);
}

$result = [
    'id' => (int) $id,
    'status' => 'yanked',
    'http_status' => $http,
];
$encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($encoded)) {
    fwrite(STDERR, "could not encode the result\n");
    exit(1);
}
if (is_string($resultPath) && $resultPath !== '') {
    $dir = dirname($resultPath);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "could not create the result directory\n");
        exit(1);
    }
    if (file_put_contents($resultPath, $encoded . "\n") === false) {
        fwrite(STDERR, "could not write the result file\n");
        exit(1);
    }
}

echo 'yanked id=' . $id . " status=yanked\n";

/**
 * @param array<string, mixed> $decoded
 */
function fail_http(int $http, array $decoded, string $user, string $password, string $server): void
{
    $code = isset($decoded['code']) && is_string($decoded['code']) ? $decoded['code'] : '';
    $message = isset($decoded['message']) && is_string($decoded['message']) ? $decoded['message'] : '';
    $message = scrub($message, $user, $password, $server);
    if (strlen($message) > 500) {
        $message = substr($message, 0, 500);
    }
    if ($code === '') {
        fwrite(STDERR, "yank failed with HTTP {$http}\n");
    } else {
        fwrite(STDERR, "yank failed with HTTP {$http} code={$code}\n");
    }
    if ($http === 409) {
        fwrite(STDERR, "Yanking a draft is rejected. Publish or delete it on the server.\n");
    } elseif ($code === 'release_not_found') {
        fwrite(STDERR, "No release with that id. Read draft id= from the release log, or the numeric id on the WPBR releases screen.\n");
    } elseif ($http === 401) {
        fwrite(STDERR, "Application password was rejected.\n");
    } elseif ($http === 403) {
        fwrite(STDERR, "The user lacks manage_wpbr_releases.\n");
    }
    if ($message !== '') {
        fwrite(STDERR, $message . "\n");
    }
    exit(1);
}

function scrub(string $text, string $user, string $password, string $server): string
{
    foreach ([$password, $user, $server] as $secret) {
        if ($secret !== '') {
            $text = str_replace($secret, '[redacted]', $text);
        }
    }

    return $text;
}
