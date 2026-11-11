<?php

/**
 * Boots the throwaway WordPress from tests/wp-e2e.sh and checks a yank.
 *
 * The site is on 1.1.0. That release was yanked. update-info and the
 * update transient must offer 1.0.0 with allow_downgrade, and
 * Plugin_Upgrader must install that older package.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

$root = isset($argv[1]) ? (string) $argv[1] : '';
if ($root === '' || !is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "wordpress root is missing\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = '127.0.0.1:8095';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_SCHEME'] = 'http';
$_SERVER['REQUEST_URI'] = '/';

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

$log = WP_CONTENT_DIR . '/debug.log';
file_put_contents($log, '');

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$activated = activate_plugin('wpbr-rollback/wpbr-rollback.php');
if (is_wp_error($activated)) {
    fwrite(STDERR, $activated->get_error_message() . "\n");
    exit(1);
}

update_option('wpbr_rollback_license', [
    'instance_token' => '0123456789abcdef0123456789abcdef',
], false);
delete_option('wpbr_updater_wpbr-rollback');
delete_site_transient('update_plugins');

$server = getenv('WPBR_SERVER_URL');
$oldId = getenv('WPBR_OLD_ID');
$newId = getenv('WPBR_NEW_ID');
$oldSha = getenv('WPBR_OLD_SHA');
if (!is_string($server) || $server === '' || !is_string($oldId) || !is_string($newId) || !is_string($oldSha)) {
    fwrite(STDERR, "e2e server, ids, and sha are required\n");
    exit(1);
}

$domain = \WPBrewer\Updater\Domain::normalize((string) home_url());
$info = wp_remote_post(rtrim($server, '/') . '/wp-json/wpbr-license/v1/update-info', [
    'timeout' => 15,
    'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ],
    'body' => (string) wp_json_encode([
        'instance_token' => '0123456789abcdef0123456789abcdef',
        'domain' => $domain,
        'product_slug' => 'wpbr-rollback',
        'version' => '1.1.0',
    ]),
]);
if (is_wp_error($info)) {
    fwrite(STDERR, $info->get_error_code() . "\n");
    exit(1);
}
$raw = (string) wp_remote_retrieve_body($info);
$json = json_decode($raw, true);
if (!is_array($json) || ($json['update_available'] ?? null) !== true || ($json['version'] ?? '') !== '1.0.0' || ($json['allow_downgrade'] ?? null) !== true) {
    fwrite(STDERR, "update-info did not offer 1.0.0 with allow_downgrade\n{$raw}\n");
    exit(1);
}
if (($json['sha256'] ?? '') !== trim($oldSha)) {
    fwrite(STDERR, "update-info sha256 is not the previous zip\n");
    exit(1);
}
$package = (string) ($json['package'] ?? '');
if (strpos($package, '/wpbr-download/' . $oldId . '?') === false || strpos($package, '/wpbr-download/' . $newId . '?') !== false) {
    fwrite(STDERR, "update-info package is not the previous release\n");
    exit(1);
}
echo 'EVIDENCE update-info version=1.0.0 allow_downgrade=true sha256=' . $json['sha256'] . ' package=' . $package . "\n";

file_put_contents($log, '');
wp_update_plugins();
$transient = get_site_transient('update_plugins');
$basename = 'wpbr-rollback/wpbr-rollback.php';
$item = is_object($transient) && isset($transient->response[$basename]) ? $transient->response[$basename] : null;
if (!is_object($item) || (string) ($item->new_version ?? '') !== '1.0.0' || (string) ($item->package ?? '') === '') {
    fwrite(STDERR, "transient did not offer 1.0.0\n");
    exit(1);
}
if (strpos((string) $item->package, '/wpbr-download/' . $oldId . '?') === false) {
    fwrite(STDERR, "transient package is not the previous release\n");
    exit(1);
}
echo 'EVIDENCE transient new_version=1.0.0 package=' . $item->package . "\n";

$admin = get_user_by('login', 'admin');
if (!$admin instanceof WP_User) {
    fwrite(STDERR, "admin user missing\n");
    exit(1);
}
wp_set_current_user($admin->ID);

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

$skin = new class extends Automatic_Upgrader_Skin {
    public function header()
    {
    }

    public function footer()
    {
    }

    public function feedback($feedback, ...$args)
    {
    }
};

$level = ob_get_level();
ob_start();
$upgrader = new Plugin_Upgrader($skin);
$result = $upgrader->upgrade($basename);
while (ob_get_level() > $level) {
    ob_end_clean();
}
if ($result !== true) {
    $detail = is_wp_error($result) ? $result->get_error_code() : wp_json_encode($result);
    fwrite(STDERR, "upgrader refused the older package: {$detail}\n");
    if (is_wp_error($upgrader->result)) {
        fwrite(STDERR, $upgrader->result->get_error_code() . ' ' . $upgrader->result->get_error_message() . "\n");
    }
    exit(1);
}

wp_clean_plugins_cache();
$data = get_plugin_data(WP_PLUGIN_DIR . '/wpbr-rollback/wpbr-rollback.php', false, false);
$version = (string) ($data['Version'] ?? '');
if ($version !== '1.0.0') {
    fwrite(STDERR, "installed version is {$version}\n");
    exit(1);
}
echo "EVIDENCE upgrader installed=1.0.0\n";

$still = wp_remote_post(rtrim($server, '/') . '/wp-json/wpbr-license/v1/update-info', [
    'timeout' => 15,
    'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ],
    'body' => (string) wp_json_encode([
        'instance_token' => '0123456789abcdef0123456789abcdef',
        'domain' => $domain,
        'product_slug' => 'wpbr-rollback',
        'version' => '1.0.0',
    ]),
]);
if (is_wp_error($still)) {
    fwrite(STDERR, $still->get_error_code() . "\n");
    exit(1);
}
$stillJson = json_decode((string) wp_remote_retrieve_body($still), true);
if (!is_array($stillJson) || ($stillJson['update_available'] ?? true) !== false || array_key_exists('package', $stillJson)) {
    fwrite(STDERR, "yanked 1.1.0 was offered to a site on 1.0.0\n");
    exit(1);
}
echo "EVIDENCE yanked version not offered\n";

$noise = is_file($log) ? trim((string) file_get_contents($log)) : '';
if ($noise !== '') {
    fwrite(STDERR, "debug.log is not empty\n{$noise}\n");
    exit(1);
}
echo "EVIDENCE debug.log empty\n";
