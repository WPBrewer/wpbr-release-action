<?php

/**
 * Checks the artifact written by a use-stub workflow run.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

$statePath = isset($argv[1]) ? (string) $argv[1] : '';
$resultPath = isset($argv[2]) ? (string) $argv[2] : '';
$state = json_decode((string) file_get_contents($statePath), true);
$result = json_decode((string) file_get_contents($resultPath), true);
if (!is_array($state) || !is_array($result)) {
    fwrite(STDERR, "artifact JSON is invalid\n");
    exit(1);
}
if (($result['status'] ?? '') !== 'draft' || ($result['http_status'] ?? 0) !== 200) {
    fwrite(STDERR, "workflow result was not a draft create\n");
    exit(1);
}
$releases = $state['releases'] ?? [];
if (!is_array($releases) || count($releases) !== 1) {
    fwrite(STDERR, "stub did not keep exactly one release\n");
    exit(1);
}
$release = $releases[0];
if (($release['status'] ?? '') !== 'draft' || ($release['sha256'] ?? '') !== ($result['sha256'] ?? '')) {
    fwrite(STDERR, "stub sha256 does not match the workflow result\n");
    exit(1);
}
if (($release['product_slug'] ?? '') !== 'wpbr-dummy' || ($release['version'] ?? '') !== '1.2.3') {
    fwrite(STDERR, "stub release identity is wrong\n");
    exit(1);
}
echo 'workflow draft sha256=' . $result['sha256'] . "\n";
