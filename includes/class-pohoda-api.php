<?php
if (!defined('ABSPATH')) {
    exit;
}

// Include all service classes
require_once plugin_dir_path(__FILE__) . 'pohoda-services/class-pohoda-api-client.php';
require_once plugin_dir_path(__FILE__) . 'pohoda-services/class-pohoda-db-manager.php';
require_once plugin_dir_path(__FILE__) . 'pohoda-services/class-pohoda-image-service.php';
require_once plugin_dir_path(__FILE__) . 'pohoda-services/class-pohoda-product-service.php';
require_once plugin_dir_path(__FILE__) . 'pohoda-services/class-pohoda-order-service.php';
require_once plugin_dir_path(__FILE__) . 'pohoda-services/class-pohoda-store-service.php';
require_once plugin_dir_path(__FILE__) . 'pohoda-logger.php'; // Include the custom logger

class Pohoda_API {
    private $options;
    private $api_client;
    private $db_manager;
    private $image_service;
    private $product_service;
    private $order_service;
    private $store_service;

    public function __construct() {
        global $wpdb;
        $this->options = get_option('pohoda_settings');

        $this->api_client = new Pohoda_API_Client($this->options);
        $this->db_manager = new Pohoda_DB_Manager($wpdb);
        // Pohoda_Image_Service needs $wpdb, $api_client, and $db_manager (for create_images_table call)
        $this->image_service = new Pohoda_Image_Service($wpdb, $this->api_client, $this->db_manager);
        // Pohoda_Product_Service needs $wpdb, $api_client, $image_service, and $db_manager
        $this->product_service = new Pohoda_Product_Service($wpdb, $this->api_client, $this->image_service, $this->db_manager);
        $this->order_service = new Pohoda_Order_Service($this->api_client);
        $this->store_service = new Pohoda_Store_Service($this->api_client);
    }

    // --- Methods delegated to Pohoda_API_Client --- //
    public function get_ico() {
        return $this->api_client->get_ico();
    }

    public function get_last_curl() {
        return $this->api_client->get_last_curl();
    }
    
    public function get_api_client() {
        return $this->api_client;
    }

    public function get_image_service() {
        return $this->image_service;
    }

    // Keep format_xml public if it was used externally, or make it internal to api_client if not.
    // Based on original class, it was private, so api_client's public format_xml is fine.
    // We can expose it via Pohoda_API if needed for some reason.
    // public function format_xml($xml) {
    // return $this->api_client->format_xml($xml);
    // }

    // --- Test Connection --- //
    public function test_connection() {
        if (!$this->api_client->validate_connection()) {
            return ['success' => false, 'data' => 'Invalid connection settings provided in WordPress.', 'raw' => ''];
        }

        // Test basic status endpoint
        $status_response = $this->api_client->make_request('/status');
        if ($status_response === false) {
            return ['success' => false, 'data' => 'No response from Pohoda server status endpoint.', 'raw' => 'Curl error: ' . ($this->api_client->get_last_curl() ? curl_error($this->api_client->get_last_curl()) : 'N/A')];
        }

        // Test company detail endpoint
        $company_info_response = $this->api_client->make_request('/status?companyDetail');
        // No explicit check for false here, assuming if status was fine, this might also pass or return empty.
        // The original code did not check $company_info for falseness before formatting.

        return [
            'success' => true,
            'status' => $status_response, // Already formatted by api_client if XML
            'company_info' => $company_info_response // Already formatted by api_client if XML
        ];
    }

    // --- Methods delegated to Pohoda_Product_Service --- //
    public function get_products($params = array()) {
        return $this->product_service->get_products_from_api($params);
    }

    public function sync_products_to_db($batch_size = 100, $start_id = 0) {
        pohoda_debug_log("Pohoda_API: sync_products_to_db called. Batch size: {$batch_size}, Start ID: {$start_id}");
        try {
            $result = $this->product_service->sync_products_to_db($batch_size, $start_id);
            pohoda_debug_log("Pohoda_API: sync_products_to_db finished. Result: " . print_r($result, true));
            return $result; // Ensure it's an array
        } catch (Exception $e) {
            pohoda_debug_log("Pohoda_API: Exception in sync_products_to_db: " . $e->getMessage());
            return ['success' => false, 'data' => 'An exception occurred: ' . $e->getMessage(), 'errors' => [$e->getMessage()]];
        }
    }

    public function get_products_from_db($params = []) {
        return $this->product_service->get_products_from_db($params);
    }

    public function check_woocommerce_products($products_from_pohoda) {
        // This method was complex. It's now check_woocommerce_products_status in ProductService.
        // It takes products fetched from Pohoda API and adds WC status.
        return $this->product_service->check_woocommerce_products_status($products_from_pohoda);
    }

    public function refresh_woocommerce_data() {
        return $this->product_service->refresh_woocommerce_data_for_all_products();
    }

    // --- Methods delegated to Pohoda_Order_Service --- //
    public function get_orders() {
        return $this->order_service->get_orders_from_api();
    }

    // --- Methods delegated to Pohoda_Store_Service --- //
    public function get_stores() {
        return $this->store_service->get_stores_from_api();
    }

    // --- Methods delegated to Pohoda_DB_Manager --- //
    public function create_db_tables() { // This now calls specific table creations
        $product_table_created = $this->db_manager->create_products_table();
        $image_table_created = $this->db_manager->create_images_table();
        return ['products' => $product_table_created, 'images' => $image_table_created]; // Or just a general success/status
    }
    
    // create_images_table is specific and can be called directly if needed, or via create_db_tables.
    // For direct access if required by other parts of the plugin:
    public function create_images_table_explicitly() {
        return $this->db_manager->create_images_table();
    }

    public function check_db_tables_status() {
        return $this->db_manager->check_db_tables_status();
    }

    public function force_create_tables() {
        return $this->db_manager->force_create_tables();
    }

    // --- Methods delegated to Pohoda_Image_Service --- //
    public function get_product_image($filename) { // This was get_product_image from API
        return $this->image_service->get_product_image_from_api($filename);
    }

    public function sync_product_images($product_id, $pictures) {
        return $this->image_service->sync_product_images($product_id, $pictures);
    }

    public function sync_image_to_woocommerce($image_db_id, $wc_product_id) {
        return $this->image_service->sync_single_image_to_woocommerce($image_db_id, $wc_product_id);
    }

    public function sync_pending_images($limit = 20) {
        return $this->image_service->sync_pending_images_to_woocommerce($limit);
    }

    public function display_product_image($filename) {
        // This method in Image_Service directly outputs and exits.
        $this->image_service->display_product_image($filename);
    }

    public function get_product_image_base64($filename) {
        return $this->image_service->get_product_image_base64($filename);
    }

    public function get_product_images_from_db($product_id) {
        return $this->image_service->get_product_images_from_db($product_id);
    }

    public function get_images_for_sync($params = []) {
        return $this->image_service->get_images_for_sync_overview($params);
    }
    
    // --- Deprecated/Internal methods from original class that are now handled within services or removed --- //
    // private $credentials; (now in api_client)
    // private $last_curl; (now in api_client)
    // private function validate_connection() (now in api_client)
    // private function make_request(...) (now in api_client)
    // public function send_xml($xml) (now in api_client)
    // private function format_xml($xml) (now in api_client, public there)
    // The product parsing (DOMXPath) logic is now within ProductService get_products_from_api
    // The WooCommerce check logic within get_products is now part of ProductService check_woocommerce_products_status
    // DB table creation for products is now in DBManager
    // Image table creation is now in DBManager
    // Image fetching via cURL specific to images is now in ImageService get_product_image_from_api

} 