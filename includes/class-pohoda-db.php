<?php
if (!defined('ABSPATH')) {
    exit;
}

class Pohoda_DB {
    private $products_table;
    
    public function __construct() {
        global $wpdb;
        $this->products_table = $wpdb->prefix . 'pohoda_products';
        $this->init();
    }
    
    /**
     * Initialize the database tables
     */
    public function init() {
        $this->create_tables();
    }
    
    /**
     * Create the necessary database tables if they don't exist
     * 
     * @return bool True if tables were created, false if they already exist
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->products_table}'") != $this->products_table) {
            $sql = "CREATE TABLE {$this->products_table} (
                id bigint(20) NOT NULL,
                code varchar(255) NOT NULL,
                name text NOT NULL,
                unit varchar(20) DEFAULT '',
                type varchar(50) DEFAULT '',
                storage varchar(50) DEFAULT '',
                count float DEFAULT 0,
                purchasing_price decimal(15,4) DEFAULT 0,
                selling_price decimal(15,4) DEFAULT 0,
                vat_rate int(3) DEFAULT 21,
                related_files longtext DEFAULT NULL,
                pictures longtext DEFAULT NULL,
                categories longtext DEFAULT NULL,
                related_stocks longtext DEFAULT NULL,
                alternative_stocks longtext DEFAULT NULL,
                price_variants longtext DEFAULT NULL,
                woocommerce_exists tinyint(1) DEFAULT 0,
                woocommerce_id bigint(20) DEFAULT 0,
                woocommerce_url varchar(255) DEFAULT '',
                woocommerce_stock varchar(50) DEFAULT '',
                woocommerce_price varchar(50) DEFAULT '',
                comparison_status varchar(50) DEFAULT 'missing',
                last_updated datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY code (code),
                KEY storage (storage),
                KEY type (type),
                KEY woocommerce_exists (woocommerce_exists),
                KEY comparison_status (comparison_status)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Sync products from Pohoda to local database
     * 
     * @param Pohoda_API $api The API instance to use for fetching products
     * @param int $batch_size Number of products to fetch per API call
     * @param int $start_id ID to start from (for resuming batch operations)
     * @return array Result of the sync operation
     */
    public function sync_products($api, $batch_size = 100, $start_id = 0) {
        global $wpdb;
        
        // Initialize results
        $results = [
            'total_fetched' => 0,
            'total_inserted' => 0,
            'total_updated' => 0,
            'last_id' => $start_id,
            'has_more' => true,
            'errors' => []
        ];
        
        try {
            // Fetch products from Pohoda in batches
            $params = [
                'per_page' => $batch_size,
                'page' => 1,
                'id_from' => $start_id,
                'check_woocommerce' => true
            ];
            
            $response = $api->get_products($params);
            
            if (!$response || !isset($response['success']) || !$response['success']) {
                $results['errors'][] = 'Failed to get products from Pohoda API';
                $results['has_more'] = false;
                return $results;
            }
            
            $products = $response['data'];
            $results['total_fetched'] = count($products);
            
            if (empty($products)) {
                $results['has_more'] = false;
                return $results;
            }
            
            // Update the last_id for the next batch
            if (isset($response['pagination']) && isset($response['pagination']['last_id'])) {
                $results['last_id'] = $response['pagination']['last_id'];
                $results['has_more'] = $response['pagination']['has_more'];
            }
            
            // Process each product
            foreach ($products as $product) {
                // Check if product exists
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->products_table} WHERE id = %d", $product['id']));
                
                // Prepare data for database
                $data = [
                    'id' => $product['id'],
                    'code' => $product['code'],
                    'name' => $product['name'],
                    'unit' => $product['unit'],
                    'type' => $product['type'],
                    'storage' => $product['storage'],
                    'count' => $product['count'],
                    'purchasing_price' => $product['purchasing_price'],
                    'selling_price' => $product['selling_price'],
                    'vat_rate' => $product['vat_rate'],
                    'related_files' => !empty($product['related_files']) ? json_encode($product['related_files']) : null,
                    'pictures' => !empty($product['pictures']) ? json_encode($product['pictures']) : null,
                    'categories' => !empty($product['categories']) ? json_encode($product['categories']) : null,
                    'related_stocks' => !empty($product['related_stocks']) ? json_encode($product['related_stocks']) : null,
                    'alternative_stocks' => !empty($product['alternative_stocks']) ? json_encode($product['alternative_stocks']) : null,
                    'price_variants' => !empty($product['price_variants']) ? json_encode($product['price_variants']) : null,
                    'woocommerce_exists' => isset($product['woocommerce_exists']) ? $product['woocommerce_exists'] : 0,
                    'woocommerce_id' => isset($product['woocommerce_id']) ? $product['woocommerce_id'] : 0,
                    'woocommerce_url' => isset($product['woocommerce_url']) ? $product['woocommerce_url'] : '',
                    'woocommerce_stock' => isset($product['woocommerce_stock']) ? $product['woocommerce_stock'] : '',
                    'woocommerce_price' => isset($product['woocommerce_price']) ? $product['woocommerce_price'] : '',
                    'comparison_status' => isset($product['comparison_status']) ? $product['comparison_status'] : 'missing',
                    'last_updated' => current_time('mysql')
                ];
                
                $format = [
                    '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%d',
                    '%s', '%s', '%s', '%s', '%s', '%s',
                    '%d', '%d', '%s', '%s', '%s', '%s', '%s'
                ];
                
                if ($exists) {
                    // Update existing record
                    $wpdb->update(
                        $this->products_table,
                        $data,
                        ['id' => $product['id']],
                        $format,
                        ['%d']
                    );
                    $results['total_updated']++;
                } else {
                    // Insert new record
                    $wpdb->insert(
                        $this->products_table,
                        $data,
                        $format
                    );
                    $results['total_inserted']++;
                }
            }
            
            return $results;
            
        } catch (Exception $e) {
            $results['errors'][] = 'Exception: ' . $e->getMessage();
            return $results;
        }
    }
    
    /**
     * Get products from the local database with filtering and pagination
     * 
     * @param array $params Query parameters
     * @return array Products with pagination info
     */
    public function get_products($params = []) {
        global $wpdb;
        
        // Default parameters
        $defaults = [
            'search' => '',
            'type' => '',
            'storage' => '',
            'per_page' => 10,
            'page' => 1,
            'order_by' => 'id',
            'order' => 'ASC',
            'comparison_status' => ''
        ];
        
        $params = wp_parse_args($params, $defaults);
        
        // Build query
        $where = [];
        $where_values = [];
        
        if (!empty($params['search'])) {
            $where[] = '(code LIKE %s OR name LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($params['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }
        
        if (!empty($params['type'])) {
            $where[] = 'type = %s';
            $where_values[] = $params['type'];
        }
        
        if (!empty($params['storage'])) {
            $where[] = 'storage = %s';
            $where_values[] = $params['storage'];
        }
        
        if (!empty($params['comparison_status'])) {
            $where[] = 'comparison_status = %s';
            $where_values[] = $params['comparison_status'];
        }
        
        $where_clause = '';
        if (!empty($where)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where);
        }
        
        // Validate order_by column
        $allowed_columns = ['id', 'code', 'name', 'count', 'selling_price', 'comparison_status', 'last_updated'];
        if (!in_array($params['order_by'], $allowed_columns)) {
            $params['order_by'] = 'id';
        }
        
        // Validate order direction
        $order = strtoupper($params['order']) === 'DESC' ? 'DESC' : 'ASC';
        
        // Calculate pagination
        $offset = ($params['page'] - 1) * $params['per_page'];
        
        // Prepare query
        $query = $wpdb->prepare(
            "SELECT * FROM {$this->products_table} $where_clause ORDER BY {$params['order_by']} $order LIMIT %d OFFSET %d",
            array_merge($where_values, [$params['per_page'], $offset])
        );
        
        // Get total count for pagination
        $count_query = "SELECT COUNT(*) FROM {$this->products_table} $where_clause";
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        $total_items = $wpdb->get_var($count_query);
        
        // Execute query
        $products = $wpdb->get_results($query, ARRAY_A);
        
        // Process products
        $processed_products = [];
        foreach ($products as $product) {
            // Convert JSON fields back to arrays
            $json_fields = ['related_files', 'pictures', 'categories', 'related_stocks', 'alternative_stocks', 'price_variants'];
            foreach ($json_fields as $field) {
                if (!empty($product[$field])) {
                    $product[$field] = json_decode($product[$field], true);
                } else {
                    $product[$field] = [];
                }
            }
            
            // Convert numeric values
            $product['count'] = (float) $product['count'];
            $product['purchasing_price'] = (float) $product['purchasing_price'];
            $product['selling_price'] = (float) $product['selling_price'];
            $product['woocommerce_exists'] = (bool) $product['woocommerce_exists'];
            $product['woocommerce_id'] = (int) $product['woocommerce_id'];
            
            $processed_products[] = $product;
        }
        
        // Prepare pagination data
        $total_pages = ceil($total_items / $params['per_page']);
        
        return [
            'success' => true,
            'data' => $processed_products,
            'pagination' => [
                'total' => (int) $total_items,
                'per_page' => (int) $params['per_page'],
                'current_page' => (int) $params['page'],
                'last_page' => $total_pages,
                'from' => $offset + 1,
                'to' => min($offset + $params['per_page'], $total_items),
                'has_more' => $params['page'] < $total_pages
            ]
        ];
    }
    
    /**
     * Refresh WooCommerce data for products in database
     * 
     * @return array Result of the refresh operation
     */
    public function refresh_woocommerce_data() {
        global $wpdb;
        
        // Get all product codes
        $products = $wpdb->get_results("SELECT id, code, count, selling_price FROM {$this->products_table}", ARRAY_A);
        
        if (empty($products)) {
            return [
                'success' => false,
                'message' => 'No products found in database'
            ];
        }
        
        $total_updated = 0;
        $codes = array_column($products, 'code');
        
        // Format codes for SQL IN clause
        $codes_placeholders = implode(',', array_fill(0, count($codes), '%s'));
        $query = $wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS sku
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku'
            AND pm.meta_value IN ($codes_placeholders)
            AND p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'",
            $codes
        );
        
        // Get all products with matching SKUs
        $wc_products = $wpdb->get_results($query, ARRAY_A);
        
        // Create lookup array for quick access
        $sku_to_product = [];
        foreach ($wc_products as $wc_product) {
            $sku_to_product[$wc_product['sku']] = $wc_product['ID'];
        }
        
        // Update each product in our database
        foreach ($products as $product) {
            $wc_exists = isset($sku_to_product[$product['code']]);
            $data = [
                'woocommerce_exists' => $wc_exists ? 1 : 0,
                'last_updated' => current_time('mysql')
            ];
            
            if ($wc_exists) {
                $wc_id = $sku_to_product[$product['code']];
                $wc_product = wc_get_product($wc_id);
                
                if ($wc_product) {
                    // Get WooCommerce product data
                    $data['woocommerce_id'] = $wc_id;
                    $data['woocommerce_url'] = get_edit_post_link($wc_id, '');
                    
                    // Get stock and price data
                    $wc_stock = $wc_product->get_stock_quantity();
                    $data['woocommerce_stock'] = $wc_stock !== null ? (string)$wc_stock : '';
                    
                    $wc_price = $wc_product->get_regular_price();
                    $data['woocommerce_price'] = $wc_price;
                    
                    // Compare with Pohoda data
                    $stock_diff = abs(($wc_stock !== null ? (float)$wc_stock : 0) - (float)$product['count']);
                    $stock_match = $stock_diff <= 0.001;
                    
                    $price_diff = abs(($wc_price !== '' ? (float)$wc_price : 0) - (float)$product['selling_price']);
                    $price_match = $price_diff <= 0.01;
                    
                    if ($stock_match && $price_match) {
                        $data['comparison_status'] = 'match';
                    } else {
                        $data['comparison_status'] = 'mismatch';
                    }
                } else {
                    $data['comparison_status'] = 'unknown';
                }
            } else {
                $data['woocommerce_id'] = 0;
                $data['woocommerce_url'] = '';
                $data['woocommerce_stock'] = '';
                $data['woocommerce_price'] = '';
                $data['comparison_status'] = 'missing';
            }
            
            // Update database
            $wpdb->update(
                $this->products_table,
                $data,
                ['id' => $product['id']]
            );
            
            $total_updated++;
        }
        
        return [
            'success' => true,
            'updated' => $total_updated
        ];
    }
    
    /**
     * Sync a specific product from local database to WooCommerce
     * 
     * @param int $product_id The Pohoda product ID
     * @return array Result of the sync operation
     */
    public function sync_product_to_woocommerce($product_id) {
        global $wpdb;
        
        // Get product data from database
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->products_table} WHERE id = %d",
            $product_id
        ), ARRAY_A);
        
        if (!$product) {
            return [
                'success' => false,
                'message' => 'Product not found in database'
            ];
        }
        
        // Check if product exists in WooCommerce
        $wc_product_id = wc_get_product_id_by_sku($product['code']);
        
        if (!$wc_product_id) {
            return [
                'success' => false,
                'message' => 'Product not found in WooCommerce'
            ];
        }
        
        $wc_product = wc_get_product($wc_product_id);
        
        // Update stock
        $wc_product->set_stock_quantity($product['count']);
        $wc_product->set_stock_status($product['count'] > 0 ? 'instock' : 'outofstock');
        
        // Calculate VAT multiplier based on the actual VAT rate from Pohoda
        $vat_multiplier = 1 + ($product['vat_rate'] / 100);
        $price_with_vat = $product['selling_price'] * $vat_multiplier;
        
        // Ceil to whole number
        $final_price = ceil($price_with_vat);
        
        // Update price
        $wc_product->set_regular_price($final_price);
        
        // Save the product
        $wc_product->save();
        
        // Update our database record
        $wpdb->update(
            $this->products_table,
            [
                'woocommerce_exists' => 1,
                'woocommerce_id' => $wc_product_id,
                'woocommerce_url' => get_edit_post_link($wc_product_id, ''),
                'woocommerce_stock' => (string)$product['count'],
                'woocommerce_price' => (string)$final_price,
                'comparison_status' => 'match',
                'last_updated' => current_time('mysql')
            ],
            ['id' => $product_id]
        );
        
        return [
            'success' => true,
            'message' => 'Product synced successfully',
            'stock' => $product['count'],
            'price' => $final_price,
            'vat_rate' => $product['vat_rate']
        ];
    }
} 