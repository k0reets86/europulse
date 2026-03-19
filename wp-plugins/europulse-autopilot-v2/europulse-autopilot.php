<?php
/**
 * Plugin Name: EuroPulse AutoPilot
 * Plugin URI: https://europulse.today
 * Description: Configurable source ingestion, queue, AI rewrite, compliance and multilingual publishing engine for EuroPulse.
 * Version: 2.0.0-alpha1
 * Author: EuroPulse
 * License: GPL v2 or later
 * Text Domain: europulse-autopilot-v2
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

if (! defined('ABSPATH')) {
	exit;
}

define('EPV2_VERSION', '2.0.0-alpha1');
define('EPV2_PLUGIN_FILE', __FILE__);
define('EPV2_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EPV2_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EPV2_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once EPV2_PLUGIN_DIR . 'includes/bootstrap.php';

EPV2_Bootstrap::init();
