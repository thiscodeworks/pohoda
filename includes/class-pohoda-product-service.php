<?php

class Pohoda_Product_Service {
    private $wpdb;
    private $api_client;
    private $image_service;
    private $db_manager;

    public function __construct($wpdb, $api_client, $image_service, $db_manager) {
        $this->wpdb = $wpdb;
        $this->api_client = $api_client;
        $this->image_service = $image_service;
        $this->db_manager = $db_manager;
    }

    public function analyze_local_db_vs_wc() {
        pohoda_debug_log("Pohoda_Product_Service: Starting analysis of local DB vs WooCommerce");
        
        // Call refresh_woocommerce_data_for_all_products
        $refresh_result = $this->refresh_woocommerce_data_for_all_products();
        
        if (!$refresh_result || (isset($refresh_result['success']) && !$refresh_result['success'])) {
            $error_msg = 'Failed to refresh WooCommerce comparison data in DB.';
            if(isset($refresh_result['message'])) $error_msg .= ' Details: ' . $refresh_result['message'];
            pohoda_debug_log("Pohoda_Product_Service: Error during refresh_woocommerce_data_for_all_products - " . $error_msg);
            return new WP_Error('refresh_failed', $error_msg);
        }
        
        // Query the DB for counts
        $table_name = $this->wpdb->prefix . 'pohoda_products';
        
        $total_local_products = $this->wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        $missing_in_woocommerce = $this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE comparison_status = %s", 'missing'));
        $mismatched_products = $this->wpdb->get_results($this->wpdb->prepare("SELECT count, selling_price, woocommerce_stock, woocommerce_price FROM {$table_name} WHERE comparison_status = %s", 'mismatch'), ARRAY_A);

        $price_mismatches = 0;
        $stock_mismatches = 0;

        if ($mismatched_products) {
            foreach ($mismatched_products as $prod) {
                // Check stock mismatch
                $wc_stock = ($prod['woocommerce_stock'] === '' || $prod['woocommerce_stock'] === null) ? null : (float)$prod['woocommerce_stock'];
                $pohoda_stock = (float)$prod['count'];
                if ( ($wc_stock === null && $pohoda_stock != 0) || ($wc_stock !== null && abs($wc_stock - $pohoda_stock) > 0.001) ) {
                    $stock_mismatches++;
                }

                // Check price mismatch
                $wc_price = ($prod['woocommerce_price'] === '' || $prod['woocommerce_price'] === null) ? null : (float)$prod['woocommerce_price'];
                $pohoda_price = (float)$prod['selling_price'];
                if ( ($wc_price === null && $pohoda_price != 0) || ($wc_price !== null && abs($wc_price - $pohoda_price) > 0.01) ) {
                    $price_mismatches++;
                }
            }
        }
        
        $stats_data = [
            'total_local_products' => (int)$total_local_products,
            'price_mismatches' => $price_mismatches,
            'stock_mismatches' => $stock_mismatches,
            'missing_in_woocommerce' => (int)$missing_in_woocommerce,
            'wc_updated_count' => isset($refresh_result['updated']) ? $refresh_result['updated'] : 'N/A',
            'errors' => []
        ];

        $message = sprintf("Analýza dokončena. Celkem lokálních produktů: %d. Chybí ve WooCommerce: %d. Cenové neshody: %d. Skladové neshody: %d.",
            $stats_data['total_local_products'],
            $stats_data['missing_in_woocommerce'],
            $stats_data['price_mismatches'],
            $stats_data['stock_mismatches']
        );

        pohoda_debug_log("Pohoda_Product_Service: DB vs WC Analysis Results: " . print_r($stats_data, true));
        return [
            'message' => $message,
            'data' => $stats_data
        ];
    }

    public function refresh_woocommerce_data_for_all_products() {
        pohoda_debug_log("Pohoda_Product_Service: Starting refresh of WooCommerce data for all products");
        
        $table_name = $this->wpdb->prefix . 'pohoda_products';
        $products = $this->wpdb->get_results("SELECT * FROM {$table_name}", ARRAY_A);
        
        if (!$products) {
            pohoda_debug_log("Pohoda_Product_Service: No products found in local database");
            return ['success' => true, 'updated' => 0];
        }
        
        $updated = 0;
        $errors = [];
        
        foreach ($products as $product) {
            try {
                $wc_product = wc_get_product($product['woocommerce_id']);
                
                if (!$wc_product) {
                    $this->wpdb->update(
                        $table_name,
                        [
                            'comparison_status' => 'missing',
                            'woocommerce_stock' => null,
                            'woocommerce_price' => null
                        ],
                        ['id' => $product['id']]
                    );
                    continue;
                }
                
                $wc_stock = $wc_product->get_stock_quantity();
                $wc_price = $wc_product->get_price();
                
                $comparison_status = 'match';
                if ($wc_stock !== (float)$product['count'] || abs($wc_price - (float)$product['selling_price']) > 0.01) {
                    $comparison_status = 'mismatch';
                }
                
                $this->wpdb->update(
                    $table_name,
                    [
                        'comparison_status' => $comparison_status,
                        'woocommerce_stock' => $wc_stock,
                        'woocommerce_price' => $wc_price
                    ],
                    ['id' => $product['id']]
                );
                
                $updated++;
                
            } catch (Exception $e) {
                $errors[] = "Error updating product {$product['id']}: " . $e->getMessage();
                pohoda_debug_log("Pohoda_Product_Service Error: " . $e->getMessage());
            }
        }
        
        pohoda_debug_log("Pohoda_Product_Service: Updated {$updated} products with WooCommerce data");
        
        return [
            'success' => true,
            'updated' => $updated,
            'errors' => $errors
        ];
    }
} 