<?php
/**
 * Plugin Name:     Log High TTFB
 * Plugin URI:      https://github.com/mateusz-zadorozny
 * Description:     Monitors front-end TTFB metrics via the browser and stores slow requests for analysis.
 * Author:          Mateusz Zadorożny
 * Author URI:      https://zadorozny.rocks
 * Text Domain:     log-high-ttfb
 * Domain Path:     /languages
 * Version:         0.1.1
 *
 * @package         Log_High_TTFB
 */

defined('ABSPATH') || exit;

define('LOG_HIGH_TTFB_PLUGIN_FILE', __FILE__);

require_once __DIR__ . '/includes/autoloader.php';

Log_High_TTFB\Plugin::init();

register_activation_hook(__FILE__, [Log_High_TTFB\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Log_High_TTFB\Plugin::class, 'deactivate']);
