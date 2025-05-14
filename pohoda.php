<?php
/*
Plugin Name: Pohoda Connection
Description: Connect to Pohoda mServer
Version: 1.0
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('POHODA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POHODA_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load required files
require_once POHODA_PLUGIN_DIR . 'includes/class-pohoda-api.php';
require_once POHODA_PLUGIN_DIR . 'admin/class-pohoda-admin.php';

// Initialize the plugin
function pohoda_init() {
    new Pohoda_Admin();
}
add_action('plugins_loaded', 'pohoda_init'); 