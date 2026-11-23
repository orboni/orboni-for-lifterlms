<?php

/**
 * Plugin Name:       Orboni for LifterLMS
 * Plugin URI:        https://orboni.com/docs/lifterlms
 * Description:       Sell courses and memberships for crypto — USDT, USDC, BTC, ETH and more — paid straight to your own wallets. A payment gateway for LifterLMS's checkout; Orboni never holds your money.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  lifterlms
 * Author:            Orboni
 * Author URI:        https://orboni.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       orboni-for-lifterlms
 */
defined('ABSPATH') || exit;

define('ORBONI_LLMS_VERSION', '1.0.0');
define('ORBONI_LLMS_FILE', __FILE__);
define('ORBONI_LLMS_DIR', plugin_dir_path(__FILE__));
define('ORBONI_LLMS_URL', plugin_dir_url(__FILE__));

/** The LifterLMS whose checkout and payment gateways this is written against. */
define('ORBONI_LLMS_MIN_LIFTERLMS', '7.0.0');

/** The plugin's classes, loaded when first named. The gateway extends LifterLMS's own, so it is named only once LifterLMS is there. */
spl_autoload_register(static function ($class) {
    if (strpos($class, 'Orboni_LLMS_') !== 0) {
        return;
    }
    $file = ORBONI_LLMS_DIR.'includes/class-'.str_replace('_', '-', strtolower($class)).'.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

// Before LifterLMS builds its list of gateways, on its own `plugins_loaded` (priority 10) and after.
add_action('plugins_loaded', 'orboni_llms_init', 5);

/** Hooks in once LifterLMS 7.0+, with its payment gateways, is there. */
function orboni_llms_init()
{
    if (! defined('LLMS_PLUGIN_FILE') || ! function_exists('llms')) {
        add_action('admin_notices', 'orboni_llms_missing_notice');

        return;
    }

    Orboni_LLMS_Plugin::register();
}

/** Checked once LifterLMS has loaded: its version is set then. */
add_action('plugins_loaded', static function () {
    if (defined('LLMS_VERSION') && version_compare(LLMS_VERSION, ORBONI_LLMS_MIN_LIFTERLMS, '<')) {
        add_action('admin_notices', 'orboni_llms_missing_notice');
    }
}, 20);

/** Shown to whoever can activate plugins, while LifterLMS is missing or older than 7.0. */
function orboni_llms_missing_notice()
{
    if (! current_user_can('activate_plugins')) {
        return;
    }

    echo '<div class="notice notice-warning"><p>'.esc_html(sprintf(
        /* translators: %s: the least LifterLMS version, e.g. 7.0.0 */
        __('Orboni for LifterLMS needs LifterLMS %s or later, active.', 'orboni-for-lifterlms'),
        ORBONI_LLMS_MIN_LIFTERLMS
    )).'</p></div>';
}

add_filter('plugin_action_links_'.plugin_basename(__FILE__), static function ($links) {
    $url = admin_url('admin.php?page=llms-settings&tab=checkout&section=orboni');
    array_unshift($links, '<a href="'.esc_url($url).'">'.esc_html__('Settings', 'orboni-for-lifterlms').'</a>');

    return $links;
});
