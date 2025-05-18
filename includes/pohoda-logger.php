<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('pohoda_debug_log')) {
    function pohoda_debug_log($message) {
        $log_file = WP_CONTENT_DIR . '/pohoda-debug.log';
        $timestamp = current_time('mysql');
        $initial_message = "[{$timestamp}] pohoda_debug_log function called.\n";
        // Try to write an initial message regardless of WP_DEBUG to see if function is called at all and path is writable
        // file_put_contents($log_file, $initial_message, FILE_APPEND); 
        // Commenting out the above line for now to avoid duplicate initial messages if WP_DEBUG is true.
        // If still no log, uncommenting it could be a further debug step.

        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            $debug_active_message = "[{$timestamp}] WP_DEBUG is true. Logging message.\n";
            file_put_contents($log_file, $debug_active_message, FILE_APPEND); 
            $formatted_message = "[{$timestamp}] " . (is_array($message) || is_object($message) ? print_r($message, true) : $message) . "\n";
            file_put_contents($log_file, $formatted_message, FILE_APPEND);
        } else {
            // $debug_inactive_message = "[{$timestamp}] WP_DEBUG is false or not defined. Not logging normally.\n";
            // file_put_contents($log_file, $debug_inactive_message, FILE_APPEND); // Log if WP_DEBUG is false
        }
    }
} 