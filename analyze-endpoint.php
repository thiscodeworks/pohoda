<?php
require_once(dirname(__FILE__) . '/../../wordpress/wp-load.php');

// Basic security check
if (!isset($_GET['key']) || $_GET['key'] !== 'pohoda_analyze') {
    die('Unauthorized');
}

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
header('Content-Type: application/json');
if (is_wp_error($result)) {
    echo json_encode([
        'success' => false,
        'error' => $result->get_error_message()
    ]);
} else {
    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'data' => $result['data']
    ]);
} 