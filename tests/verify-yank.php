<?php

/**
 * Checks the artifact written by a use-stub yank workflow run.
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
if (($result['status'] ?? '') !== 'yanked' || ($result['http_status'] ?? 0) !== 200 || ($result['id'] ?? 0) !== 1) {
    fwrite(STDERR, "workflow result was not a yank of id 1\n");
    exit(1);
}
$releases = $state['releases'] ?? [];
if (!is_array($releases) || count($releases) !== 1) {
    fwrite(STDERR, "stub did not keep exactly one release\n");
    exit(1);
}
$release = $releases[0];
if (($release['id'] ?? 0) !== 1 || ($release['status'] ?? '') !== 'yanked') {
    fwrite(STDERR, "stub release was not yanked\n");
    exit(1);
}
$versions = $state['versions'] ?? [];
if (!is_array($versions) || !in_array("wpbr-seed\t0.0.1", $versions, true)) {
    fwrite(STDERR, "yank dropped the version tombstone\n");
    exit(1);
}
echo "workflow yank id=1 status=yanked\n";
