<?php
require_once(dirname(__FILE__) . '/../../wordpress/wp-load.php');

// Ensure we have the necessary classes
if (!class_exists('Pohoda_Product_Service')) {
    die('Pohoda_Product_Service class not found');
}

// Initialize required services
$db_manager = new Pohoda_DB_Manager($wpdb);
$api_client = null; // Not needed for this test
$image_service = null; // Not needed for this test

// Create product service instance
$product_service = new Pohoda_Product_Service($wpdb, $api_client, $image_service, $db_manager);

// Run the analysis
$result = $product_service->analyze_local_db_vs_wc();

// Output results
echo "Analysis Results:\n";
echo "----------------\n";
if (is_wp_error($result)) {
    echo "Error: " . $result->get_error_message() . "\n";
} else {
    echo "Message: " . $result['message'] . "\n";
    echo "Data:\n";
    print_r($result['data']);
} 