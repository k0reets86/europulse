<?php
/**
 * Plugin Name: EuroPulse AutoPilot V3
 * Description: Clean DE-first automation pipeline for EuroPulse.
 * Version: 0.1.0
 * Author: EuroPulse
 */

if (! defined('ABSPATH')) {
	exit;
}

define('EPV3_VERSION', '0.1.0');
define('EPV3_PLUGIN_FILE', __FILE__);
define('EPV3_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EPV3_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once EPV3_PLUGIN_DIR . 'includes/bootstrap.php';

EPV3_Bootstrap::init();
