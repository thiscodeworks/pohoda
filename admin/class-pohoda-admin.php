<?php
if (!defined('ABSPATH')) {
    exit;
}

class Pohoda_Admin {
    private $options;
    private $api;
    private $db;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_init', array($this, 'page_init'));
        
        // Register all AJAX endpoints
        $this->register_ajax_endpoints();
        
        // Enqueue styles and scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-pohoda-api.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-pohoda-db.php';
        $this->api = new Pohoda_API();
        $this->db = new Pohoda_DB();
    }

    public function add_plugin_page() {
        add_menu_page(
            'Pohoda Settings',
            'Pohoda',
            'manage_options',
            'pohoda-settings',
            array($this, 'create_admin_page'),
            'dashicons-database',
            100
        );
    }

    public function create_admin_page() {
        $this->options = get_option('pohoda_settings');
        // Determine the active tab, default to 'sync'
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'sync';
        ?>
        <div class="wrap">
            <h1>Pohoda Sync</h1>
            <h2 class="nav-tab-wrapper">
                <a href="?page=pohoda-settings&tab=sync" class="nav-tab <?php echo $active_tab == 'sync' ? 'nav-tab-active' : ''; ?>">Synchronizace</a>
                <a href="?page=pohoda-settings&tab=history" class="nav-tab <?php echo $active_tab == 'history' ? 'nav-tab-active' : ''; ?>">Historie synchronizací</a>
                <a href="?page=pohoda-settings&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Nastavení</a>
            </h2>

            <?php if ($active_tab == 'sync') : ?>
                <div id="sync-main-page">
                    <h2>Spustit novou synchronizaci</h2>
                    <p>Kliknutím na tlačítko níže spustíte kompletní proces synchronizace.</p>
                    <button id="start-full-sync" class="button button-primary">Spustit synchronizaci</button>
                    
                    <div id="sync-wizard-progress" style="margin-top: 20px; display: none;">
                        <h3>Průběh synchronizace:</h3>
                        <ul id="sync-steps">
                            <li data-step="check_settings">Kontrola nastavení...</li>
                            <li data-step="download_first_products">Stahování úvodních produktů...</li>
                            <li data-step="sync_rest_products">Synchronizace zbylých produktů...</li>
                            <li data-step="update_pohoda_db">Aktualizace Pohoda databáze...</li>
                            <li data-step="update_prices">Aktualizace cen...</li>
                            <li data-step="update_stock">Aktualizace skladových zásob...</li>
                            <li data-step="reupload_images">Nahrávání obrázků...</li>
                            <li data-step="create_missing_products">Vytváření chybějících produktů...</li>
                            <li data-step="check_no_price_products">Kontrola produktů bez ceny...</li>
                            <li data-step="check_no_image_products">Kontrola produktů bez obrázků...</li>
                            <li data-step="finished">Dokončeno</li>
                        </ul>
                        <div class="progress-bar-container" style="width: 100%; background-color: #f0f0f0; border-radius: 4px; margin-top:10px;">
                            <div id="wizard-progress-bar-inner" class="progress-bar-inner" style="height: 20px; width: 0%; background-color: #0073aa; text-align: center; color: white; line-height:20px;">0%</div>
                        </div>
                    </div>
                </div>
            <?php elseif ($active_tab == 'history') : ?>
                <div id="sync-history-page">
                    <h2>Historie synchronizací</h2>
                    <p>Zde bude tabulka s historií provedených synchronizací.</p>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Datum spuštění</th>
                                <th>Stav</th>
                                <th>Počet akcí</th>
                                <th>Akce</th>
                            </tr>
                        </thead>
                        <tbody id="sync-history-table-body">
                            <!-- History rows will be loaded here by JavaScript -->
                            <tr><td colspan="5">Načítání historie...</td></tr>
                        </tbody>
                    </table>
                    <div id="sync-history-details" style="margin-top: 20px; display:none;">
                        <h3>Detaily synchronizace č. <span id="history-details-sync-id"></span></h3>
                        <ul id="history-details-action-list">
                            <!-- Action details will be loaded here -->
                        </ul>
                    </div>
                </div>
            <?php elseif($active_tab == 'settings') : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('pohoda_option_group');
                    do_settings_sections('pohoda-settings');
                    submit_button();
                    ?>
                </form>
                <hr>
                <h2>Test Connection</h2>
                <button id="test-connection" class="button button-primary">Test Connection</button>
                <div id="connection-result" style="margin-top: 10px;"></div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function page_init() {
        register_setting(
            'pohoda_option_group',
            'pohoda_settings',
            array($this, 'sanitize')
        );

        add_settings_section(
            'pohoda_setting_section',
            'Connection Settings',
            array($this, 'section_info'),
            'pohoda-settings'
        );

        add_settings_field(
            'ip_address',
            'IP Address',
            array($this, 'ip_address_callback'),
            'pohoda-settings',
            'pohoda_setting_section'
        );

        add_settings_field(
            'port',
            'Port',
            array($this, 'port_callback'),
            'pohoda-settings',
            'pohoda_setting_section'
        );

        add_settings_field(
            'login',
            'Login',
            array($this, 'login_callback'),
            'pohoda-settings',
            'pohoda_setting_section'
        );

        add_settings_field(
            'password',
            'Password',
            array($this, 'password_callback'),
            'pohoda-settings',
            'pohoda_setting_section'
        );

        add_settings_field(
            'ico',
            'IČO',
            array($this, 'ico_callback'),
            'pohoda-settings',
            'pohoda_setting_section'
        );
    }

    public function sanitize($input) {
        $new_input = array();
        
        if(isset($input['ip_address']))
            $new_input['ip_address'] = sanitize_text_field($input['ip_address']);

        if(isset($input['port']))
            $new_input['port'] = absint($input['port']);

        if(isset($input['login']))
            $new_input['login'] = sanitize_text_field($input['login']);

        if(isset($input['password']))
            $new_input['password'] = sanitize_text_field($input['password']);

        if(isset($input['ico']))
            $new_input['ico'] = sanitize_text_field($input['ico']);

        return $new_input;
    }

    public function section_info() {
        echo 'Enter your Pohoda mServer connection details below:';
    }

    public function ip_address_callback() {
        printf(
            '<input type="text" id="ip_address" name="pohoda_settings[ip_address]" value="%s" />',
            isset($this->options['ip_address']) ? esc_attr($this->options['ip_address']) : ''
        );
    }

    public function port_callback() {
        printf(
            '<input type="number" id="port" name="pohoda_settings[port]" value="%s" />',
            isset($this->options['port']) ? esc_attr($this->options['port']) : ''
        );
    }

    public function login_callback() {
        printf(
            '<input type="text" id="login" name="pohoda_settings[login]" value="%s" />',
            isset($this->options['login']) ? esc_attr($this->options['login']) : ''
        );
    }

    public function password_callback() {
        printf(
            '<input type="password" id="password" name="pohoda_settings[password]" value="%s" />',
            isset($this->options['password']) ? esc_attr($this->options['password']) : ''
        );
    }

    public function ico_callback() {
        printf(
            '<input type="text" id="ico" name="pohoda_settings[ico]" value="%s" />',
            isset($this->options['ico']) ? esc_attr($this->options['ico']) : ''
        );
    }

    public function test_connection() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $result = $this->api->test_connection();
        if (!$result || isset($result['success']) && !$result['success']) {
            if (isset($result['data'])) {
                wp_send_json_error($result['data']);
            } else {
                wp_send_json_error('Connection failed');
            }
        }

        $status_xml = simplexml_load_string($result['status']);
        
        if ($status_xml === false) {
            wp_send_json_error('Failed to parse XML response');
        }

        $output = "Status: " . (string)$status_xml->status . "<br>";
        $output .= "Message: " . (string)$status_xml->message . "<br>";
        $output .= "Server: " . (string)$status_xml->server . "<br>";
        $output .= "Processing: " . (string)$status_xml->processing . "<br>";
        
        // Only process company_info if it's not empty
        if (!empty($result['company_info'])) {
            $company_xml = simplexml_load_string($result['company_info']);
            
            if ($company_xml !== false) {
                $output .= "<br><br>Company Info:<br>";
                $output .= "Company: " . (string)$company_xml->companyDetail->company . "<br>";
                $output .= "Database: " . (string)$company_xml->companyDetail->databaseName . "<br>";
                $output .= "Year: " . (string)$company_xml->companyDetail->year . "<br>";
                $output .= "Period: " . (string)$company_xml->companyDetail->period . "<br>";
            }
        }

        wp_send_json_success($output);
    }

    public function load_stores() {
        check_ajax_referer('pohoda_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $response = $this->api->get_stores();

        if ($response['success']) {
            // Save stores data to WordPress options
            update_option('pohoda_stores', $response['data']);
            wp_send_json_success($response['data']);
        } else {
            wp_send_json_error($response['data']);
        }
    }

    public function load_orders() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $response = $this->api->get_orders();
        if (!$response || isset($response['success']) && !$response['success']) {
            if (isset($response['data'])) {
                wp_send_json_error($response['data']);
            } else {
                wp_send_json_error('Failed to load orders');
            }
        }

        $xml_response = isset($response['data']) ? $response['data'] : $response;
        $xml = simplexml_load_string($xml_response);
        if ($xml === false) {
            wp_send_json_error('Failed to parse XML response');
        }

        $orders = [];
        foreach ($xml->responsePackItem->listOrder->order as $order) {
            $isExecuted = (string)$order->orderHeader->isExecuted;
            $isDelivered = (string)$order->orderHeader->isDelivered;
            $isReserved = (string)$order->orderHeader->isReserved;
            
            $status = [];
            if ($isExecuted == 'true') $status[] = 'Executed';
            if ($isDelivered == 'true') $status[] = 'Delivered';
            if ($isReserved == 'true') $status[] = 'Reserved';
            
            $orders[] = [
                'id' => (string)$order->orderHeader->id,
                'number' => (string)$order->orderHeader->number->numberRequested,
                'date' => (string)$order->orderHeader->date,
                'partner' => (string)$order->orderHeader->partnerIdentity->address->company,
                'total' => (string)$order->orderSummary->homeCurrency->priceHighSum,
                'status' => implode(', ', $status)
            ];
        }

        wp_send_json_success([
            'raw' => $xml_response,
            'orders' => $orders
        ]);
    }

    public function send_xml() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (empty($_POST['xml'])) {
            wp_send_json_error('No XML request provided');
        }

        $xml = urldecode($_POST['xml']);
        
        // Ensure XML declaration has Windows-1250 encoding
        $xml = preg_replace('/<\?xml[^>]+\?>/', '<?xml version="1.0" encoding="Windows-1250"?>', $xml);
        
        $response = $this->api->send_xml($xml);
        if ($response === false) {
            wp_send_json_error('Failed to send XML request: ' . curl_error($this->api->get_last_curl()));
        }

        wp_send_json_success($response);
    }

    /**
     * Sync products from Pohoda to database
     */
    public function sync_db_products() {
        check_ajax_referer('pohoda_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }

        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 25;
        $start_id = isset($_POST['start_id']) ? intval($_POST['start_id']) : 0;

        $result = $this->_handle_sync_db_products_logic($batch_size, $start_id);

        if (isset($result['success']) && $result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(isset($result['data']) ? $result['data'] : ['message' => 'Failed to sync DB products']);
        }
    }

    /**
     * Internal logic for syncing DB products, returns result array.
     */
    private function _handle_sync_db_products_logic($batch_size, $start_id) {
        // Ensure DB class is available
        if (!$this->db) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-pohoda-db.php';
            $this->db = new Pohoda_DB();
        }
        // Ensure API class is available (db->sync_products might need it indirectly or directly)
        if (!$this->api) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-pohoda-api.php';
            $this->api = new Pohoda_API(); 
        }
        return $this->db->sync_products($this->api, $batch_size, $start_id);
    }

    /**
     * Get products from database
     */
    public function get_db_products() {
        check_ajax_referer('pohoda_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $params = array(
            'search' => isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '',
            'type' => isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '',
            'storage' => isset($_POST['storage']) ? sanitize_text_field($_POST['storage']) : '',
            'comparison_status' => isset($_POST['comparison_status']) ? sanitize_text_field($_POST['comparison_status']) : '',
            'per_page' => isset($_POST['per_page']) ? intval($_POST['per_page']) : 10,
            'page' => isset($_POST['page']) ? intval($_POST['page']) : 1,
            'order_by' => isset($_POST['order_by']) ? sanitize_text_field($_POST['order_by']) : 'id',
            'order' => isset($_POST['order']) ? sanitize_text_field($_POST['order']) : 'ASC'
        );

        $response = $this->db->get_products($params);
        wp_send_json_success($response);
    }

    /**
     * Refresh WooCommerce data in the database
     */
    public function refresh_wc_data() {
        check_ajax_referer('pohoda_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $result = $this->db->refresh_woocommerce_data();
        wp_send_json_success($result);
    }

    /**
     * Sync a database product with WooCommerce
     */
    public function sync_db_product() {
        check_ajax_referer('pohoda_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $vat_rate = isset($_POST['vat_rate']) ? floatval($_POST['vat_rate']) : 21; // Default to 21% if not provided
        
        if (empty($product_id)) {
            wp_send_json_error('Product ID is required');
            return;
        }
        
        $result = $this->db->sync_product_to_woocommerce($product_id, $vat_rate);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Create a new WooCommerce product from Pohoda data
     */
    public function create_wc_product() {
        check_ajax_referer('pohoda_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $product_code = isset($_POST['product_code']) ? sanitize_text_field($_POST['product_code']) : '';
        $product_name = isset($_POST['product_name']) ? sanitize_text_field(urldecode($_POST['product_name'])) : '';
        $product_price = isset($_POST['product_price']) ? floatval($_POST['product_price']) : 0;
        $product_stock = isset($_POST['product_stock']) ? floatval($_POST['product_stock']) : 0;
        
        if (empty($product_code) || empty($product_name)) {
            wp_send_json_error('Product code and name are required');
            return;
        }
        
        // Check if product with this SKU already exists
        $existing_product_id = wc_get_product_id_by_sku($product_code);
        if ($existing_product_id) {
            wp_send_json_error('A product with this SKU already exists in WooCommerce');
            return;
        }
        
        // Create new product
        $product = new WC_Product_Simple();
        $product->set_name($product_name);
        $product->set_sku($product_code);
        $product->set_regular_price($product_price);
        $product->set_stock_quantity($product_stock);
        $product->set_stock_status($product_stock > 0 ? 'instock' : 'outofstock');
        $product->set_sold_individually(false);
        $product->set_manage_stock(true);
        $product->set_status('publish');
        
        // Save the product
        $wc_product_id = $product->save();
        
        if ($wc_product_id) {
            wp_send_json_success([
                'success' => true,
                'product_id' => $wc_product_id,
                'edit_url' => get_edit_post_link($wc_product_id, ''),
                'message' => 'Product created successfully'
            ]);
        } else {
            wp_send_json_error('Failed to create product');
        }
    }

    /**
     * Create all missing WooCommerce products
     */
    public function create_all_missing_products() {
        check_ajax_referer('pohoda_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        // Get batch size and starting index from request (for batch processing)
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
        $start_index = isset($_POST['start_index']) ? intval($_POST['start_index']) : 0;
        $products = isset($_POST['products']) ? $_POST['products'] : null;
        $total = isset($_POST['total']) ? intval($_POST['total']) : 0;
        
        // First request - collect all missing products
        if (!$products) {
            // This endpoint just returns the IDs and will be handled by the client
            return;
        }

        // Process a batch of products
        $end_index = min($start_index + $batch_size, count($products));
        $batch_products = array_slice($products, $start_index, $batch_size);
        
        $created = 0;
        $failed = 0;
        $errors = [];

        foreach ($batch_products as $product) {
            // Skip if code or name is empty
            if (empty($product['code']) || empty($product['name'])) {
                $failed++;
                $errors[] = "Product ID {$product['id']}: Missing code or name";
                continue;
            }
            
            // Check if product with this SKU already exists
            $existing_product_id = wc_get_product_id_by_sku($product['code']);
            if ($existing_product_id) {
                $failed++;
                $errors[] = "Product '{$product['name']}' (code: {$product['code']}): Already exists in WooCommerce";
                // Update local DB to reflect that this product exists in WC
                if (isset($this->api) && is_object($this->api) && isset($this->api->product_service) && is_object($this->api->product_service)) {
                    $this->api->product_service->update_local_product_wc_status_after_check($product['id'], $existing_product_id);
                } else {
                    if (function_exists('pohoda_debug_log')) {
                        pohoda_debug_log("Pohoda_Admin: Critical - product_service not available in create_all_missing_products (existing product).");
                    }
                }
                continue;
            }

            // Calculate price with VAT
            $vatRate = $product['vat_rate'] ? (float)$product['vat_rate'] : 21;
            $priceWithVat = $product['selling_price'] * (1 + ($vatRate / 100));
            $priceWithVat = ceil($priceWithVat);
            
            // Create new product
            $wc_product = new WC_Product_Simple();
            $wc_product->set_name($product['name']);
            $wc_product->set_sku($product['code']);
            $wc_product->set_regular_price($priceWithVat);
            $wc_product->set_stock_quantity($product['count']);
            $wc_product->set_stock_status($product['count'] > 0 ? 'instock' : 'outofstock');
            $wc_product->set_sold_individually(false);
            $wc_product->set_manage_stock(true);
            $wc_product->set_status('publish');
            
            $wc_product_id = $wc_product->save();
            
            if ($wc_product_id) {
                $created++;
                // Update local DB to reflect that this product now exists in WC
                if (isset($this->api) && is_object($this->api) && isset($this->api->product_service) && is_object($this->api->product_service)) {
                    $this->api->product_service->update_local_product_wc_status_after_check($product['id'], $wc_product_id);
                } else {
                    if (function_exists('pohoda_debug_log')) {
                        pohoda_debug_log("Pohoda_Admin: Critical - product_service not available in create_all_missing_products (newly created product).");
                    }
                }
            } else {
                $failed++;
                $errors[] = "Product '{$product['name']}' (code: {$product['code']}): Failed to create";
            }
        }
        
        // Return information about this batch
        wp_send_json_success([
            'success' => true,
            'created' => $created,
            'failed' => $failed,
            'errors' => $errors,
            'start_index' => $start_index,
            'end_index' => $end_index,
            'total' => $total,
            'is_complete' => $end_index >= count($products),
            'message' => "Processed batch {$start_index}-{$end_index} of {$total}. Created: {$created}, Failed: {$failed}."
        ]);
    }

    /**
     * Get all mismatched products for syncing
     */
    public function get_all_mismatched_products() {
        check_ajax_referer('pohoda_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $all_products = [];
        $page = 1;
        $has_more = true;
        $per_page = 1000; // Use large batch size for efficiency
        $debug_info = [
            'pages_processed' => 0,
            'page_results' => []
        ];
        $all_status_counts = [
            'match' => 0,
            'mismatch' => 0,
            'missing' => 0,
            'unknown' => 0
        ];

        // Iterate through all pages until we've collected all mismatched products
        while ($has_more) {
            $params = array(
                'comparison_status' => 'mismatch',
                'per_page' => $per_page,
                'page' => $page
            );

            $response = $this->db->get_products($params);
            $debug_info['pages_processed']++;
            
            if (!$response['success']) {
                $debug_info['error'] = 'Failed to retrieve products on page ' . $page;
                wp_send_json_error([
                    'message' => 'Failed to retrieve products',
                    'debug' => $debug_info
                ]);
                return;
            }

            // Add product counts to debug info
            $debug_info['page_results'][] = [
                'page' => $page,
                'products_count' => count($response['data']),
                'pagination' => $response['pagination'],
                'status_counts' => isset($response['status_counts']) ? $response['status_counts'] : null
            ];

            // Add products to our collection
            if (!empty($response['data'])) {
                $all_products = array_merge($all_products, $response['data']);
            }
            
            // Add to status counts
            if (isset($response['status_counts'])) {
                foreach ($response['status_counts'] as $status => $count) {
                    $all_status_counts[$status] += $count;
                }
            }

            // Check if there are more pages
            $pagination = $response['pagination'];
            $has_more = $pagination['current_page'] < $pagination['last_page'];
            $page++;

            // Safety check to prevent infinite loops
            if ($page > 100) {
                $debug_info['max_pages_reached'] = true;
                break;
            }
        }

        $debug_info['total_products_found'] = count($all_products);
        $debug_info['all_status_counts'] = $all_status_counts;

        wp_send_json_success([
            'products' => $all_products,
            'total' => count($all_products),
            'status_counts' => $all_status_counts,
            'debug' => $debug_info
        ]);
    }

    /**
     * Get all missing products (not in WooCommerce)
     */
    public function get_all_missing_products() {
        check_ajax_referer('pohoda_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $all_products = [];
        $page = 1;
        $has_more = true;
        $per_page = 1000; // Use large batch size for efficiency

        // Iterate through all pages until we've collected all missing products
        while ($has_more) {
            $params = array(
                'comparison_status' => 'missing',
                'per_page' => $per_page,
                'page' => $page
            );

            $response = $this->db->get_products($params);
            
            if (!$response['success']) {
                wp_send_json_error('Failed to retrieve products');
                return;
            }

            // Add products to our collection
            if (!empty($response['data'])) {
                $all_products = array_merge($all_products, $response['data']);
            }

            // Check if there are more pages
            $pagination = $response['pagination'];
            $has_more = $pagination['current_page'] < $pagination['last_page'];
            $page++;

            // Safety check to prevent infinite loops
            if ($page > 100) {
                break;
            }
        }

        wp_send_json_success([
            'products' => $all_products,
            'total' => count($all_products)
        ]);
    }

    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_pohoda-settings' !== $hook) {
            return;
        }

        wp_enqueue_style('pohoda-admin', plugin_dir_url(__FILE__) . 'css/pohoda-admin.css', array(), '1.0.0');
        wp_enqueue_style('dashicons');
        
        // Get fresh nonce 
        $nonce = wp_create_nonce('pohoda_nonce');
        
        wp_enqueue_script('pohoda-admin', plugin_dir_url(__FILE__) . 'js/pohoda-admin.js', array('jquery'), '1.0.1', true);
        wp_enqueue_script('pohoda-db-products', plugin_dir_url(__FILE__) . 'assets/js/db-products.js', array('jquery'), '1.0.1', true);
        
        wp_localize_script('pohoda-admin', 'pohodaAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => $nonce,
            'ico' => !empty($this->options['ico']) ? $this->options['ico'] : ''
        ));
        
        // Also localize the same data to the DB products script
        wp_localize_script('pohoda-db-products', 'pohodaAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => $nonce,
            'ico' => !empty($this->options['ico']) ? $this->options['ico'] : ''
        ));
    }

    public function enqueue_styles() {
        wp_enqueue_style('pohoda-admin', plugin_dir_url(__FILE__) . 'css/admin.css', array(), $this->version, 'all');
        
        // Add inline styles for the buttons
        $custom_css = '
            .button-warning {
                background-color: #f0ad4e !important;
                border-color: #eea236 !important;
                color: #fff !important;
                text-decoration: none !important;
            }
            .button-warning:hover {
                background-color: #ec971f !important;
                border-color: #d58512 !important;
            }
            .delete-wc-product { 
                background-color: #d9534f !important; 
                color: white !important; 
                border-color: #d43f3a !important; 
            }
            .delete-wc-product:hover { 
                background-color: #c9302c !important; 
                border-color: #ac2925 !important; 
            }
            .hide-wc-product { 
                background-color: #5bc0de !important; 
                color: white !important; 
                border-color: #46b8da !important; 
            }
            .hide-wc-product:hover { 
                background-color: #31b0d5 !important; 
                border-color: #269abc !important; 
            }
            .pohoda-section {
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 1px solid #ddd;
            }
            /* Zero stock highlighting */
            tr.zero-stock {
                background-color: #ffeeee !important;
            }
            tr.zero-stock:nth-child(odd) {
                background-color: #ffe8e8 !important;
            }
            /* Progress bar styling */
            .progress-bar-container {
                height: 20px;
                background-color: #f0f0f0;
                border-radius: 4px;
                margin: 10px 0;
                overflow: hidden;
                border: 1px solid #ddd;
            }
            .progress-bar-inner {
                height: 100%;
                background-color: #0073aa;
                transition: width 0.3s ease;
            }
            .progress-text {
                margin-bottom: 15px;
                font-weight: 500;
            }
        ';
        wp_add_inline_style('pohoda-admin', $custom_css);
    }

    public function display_product_management_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <!-- Search Form -->
            <div class="card">
                <h2 class="title">Search Products</h2>
                <div class="inside">
                    <form id="pohoda-search-form" class="form-horizontal">
                        <div class="form-group">
                            <input type="text" id="pohoda-search" class="form-control" placeholder="Search by name or code...">
                            <button type="submit" class="button button-primary">Search</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Products Table -->
            <div class="card">
                <h2 class="title">Products</h2>
                <div class="inside">
                    <table id="pohoda-product-table" class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Stock</th>
                                <th>Purchase Price</th>
                                <th>Selling Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="6" class="text-center">Loading products...</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <div class="tablenav bottom">
                        <div class="alignleft actions">
                            <span id="pohoda-pagination-status">Loading...</span>
                        </div>
                        <div class="tablenav-pages">
                            <span class="pagination-links">
                                <button id="pohoda-prev-page" class="button pohoda-pagination-btn disabled">« Previous</button>
                                <button id="pohoda-next-page" class="button pohoda-pagination-btn disabled">Next »</button>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Raw Response -->
            <div id="pohoda-raw-response-wrap" class="card" style="display: none;">
                <h2 class="title">Raw XML Response (for debugging)</h2>
                <div class="inside">
                    <pre id="pohoda-raw-response" style="max-height: 300px; overflow: auto; background: #f0f0f0; padding: 10px; font-size: 12px;"></pre>
                </div>
            </div>
        </div>
        <?php
    }

    // Register AJAX endpoints for the plugin
    public function register_ajax_endpoints() {
        add_action('wp_ajax_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_load_stores', array($this, 'load_stores'));
        add_action('wp_ajax_load_orders', array($this, 'load_orders'));
        add_action('wp_ajax_send_xml', array($this, 'send_xml'));
        add_action('wp_ajax_sync_db_products', array($this, 'sync_db_products'));
        add_action('wp_ajax_get_db_products', array($this, 'get_db_products'));
        add_action('wp_ajax_refresh_wc_data', array($this, 'refresh_wc_data'));
        add_action('wp_ajax_sync_db_product', array($this, 'sync_db_product'));
        add_action('wp_ajax_create_wc_product', array($this, 'create_wc_product'));
        add_action('wp_ajax_create_all_missing_products', array($this, 'create_all_missing_products'));
        add_action('wp_ajax_get_all_mismatched_products', array($this, 'get_all_mismatched_products'));
        add_action('wp_ajax_get_all_missing_products', array($this, 'get_all_missing_products'));
        add_action('wp_ajax_find_orphan_wc_products', array($this, 'find_orphan_wc_products'));
        add_action('wp_ajax_delete_orphan_wc_product', array($this, 'delete_orphan_wc_product'));
        add_action('wp_ajax_hide_orphan_wc_product', array($this, 'hide_orphan_wc_product'));
        add_action('wp_ajax_check_db_status', array($this, 'check_db_status'));
        add_action('wp_ajax_force_create_tables', array($this, 'force_create_tables'));

        // New AJAX action for running individual sync wizard steps
        add_action('wp_ajax_pohoda_run_sync_step', array($this, 'run_sync_step'));
    }

    // New method to handle sync wizard steps
    public function run_sync_step() {
        check_ajax_referer('pohoda_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }

        $step = isset($_POST['step_name']) ? sanitize_text_field($_POST['step_name']) : '';
        $this->options = get_option('pohoda_settings'); // Ensure options are loaded

        // Log received step for debugging
        // error_log('Pohoda Sync Step: ' . $step);

        switch ($step) {
            case 'check_settings':
                $this->handle_check_settings_step();
                break;
            case 'download_first_products':
                // Call the internal logic with a defined small batch for the first sync
                $first_batch_size = 25; // Define how many products for the "first" download
                $result = $this->_handle_sync_db_products_logic($first_batch_size, 0);
                if (isset($result['success']) && $result['success']) {
                    wp_send_json_success($result); // Send the full result which might include counts etc.
                } else {
                    wp_send_json_error(isset($result['data']) ? $result['data'] : ['message' => 'Failed during download_first_products step'], 500);
                }
                break;
            case 'sync_rest_products':
                // Placeholder: This will also use _handle_sync_db_products_logic but needs to manage pagination/offset
                // For now, let's just call it once like the first step to ensure it works.
                // We'll need to pass state (next start_id) between JS and PHP for true pagination here.
                $result = $this->_handle_sync_db_products_logic(100, 0); // Example, assuming next batch after first
                if (isset($result['success']) && $result['success']) {
                     wp_send_json_success(['message' => 'Krok sync_rest_products zavolán (s testovací dávkou).', 'data' => $result]);
                } else {
                     wp_send_json_error(isset($result['data']) ? $result['data'] : ['message' => 'Failed during sync_rest_products step'], 500);
                }
                break;
            case 'update_pohoda_db':
                wp_send_json_success(['message' => 'Krok update_pohoda_db zavolán.']);
                break;
            case 'update_prices':
                wp_send_json_success(['message' => 'Krok update_prices zavolán.']);
                break;
            case 'update_stock':
                wp_send_json_success(['message' => 'Krok update_stock zavolán.']);
                break;
            case 'reupload_images':
                wp_send_json_success(['message' => 'Krok reupload_images zavolán.']);
                break;
            case 'create_missing_products':
                wp_send_json_success(['message' => 'Krok create_missing_products zavolán.']);
                break;
            case 'check_no_price_products':
                wp_send_json_success(['message' => 'Krok check_no_price_products zavolán.']);
                break;
            case 'check_no_image_products':
                wp_send_json_success(['message' => 'Krok check_no_image_products zavolán.']);
                break;
            case 'finished':
                wp_send_json_success(['message' => 'Krok finished zavolán.']);
                break;
            default:
                wp_send_json_error(['message' => 'Neznámý krok synchronizace: ' . esc_html($step)], 400);
                break;
        }
    }

    private function handle_check_settings_step() {
        // Check if essential settings are present
        $required_settings = ['ip_address', 'port', 'login', 'password', 'ico'];
        $missing_settings = [];

        foreach ($required_settings as $setting_key) {
            if (empty($this->options[$setting_key])) {
                $missing_settings[] = $setting_key;
            }
        }

        if (!empty($missing_settings)) {
            wp_send_json_error([
                'message' => 'Chybějící nastavení: ' . implode(', ', $missing_settings),
                'missing' => $missing_settings
            ], 400);
        } else {
            // Optionally, could perform a quick test connection here if desired
            // For now, just confirming settings exist is enough for this step
            wp_send_json_success(['message' => 'Nastavení úspěšně zkontrolováno.']);
        }
    }

    /**
     * Check if WooCommerce is active
     *
     * @return bool True if WooCommerce is active, false otherwise
     */
    private function woocommerce_active() {
        return class_exists('WooCommerce');
    }

    /**
     * Find WooCommerce products that are not in Pohoda database
     */
    public function find_orphan_wc_products() {
        // Log when the function is called
        error_log('find_orphan_wc_products called');
        
        // Set proper content type
        header('Content-Type: application/json');
        
        // Check nonce with more specific error reporting
        if (!check_ajax_referer('pohoda_nonce', 'nonce', false)) {
            $response = array(
                'success' => false,
                'message' => 'Security check failed. Invalid nonce.',
                'debug' => array(
                    'received_nonce' => isset($_POST['nonce']) ? $_POST['nonce'] : 'not set',
                    'current_user' => get_current_user_id()
                )
            );
            echo json_encode($response);
            wp_die();
        }

        if (!current_user_can('manage_options')) {
            $response = array(
                'success' => false,
                'message' => 'Insufficient permissions'
            );
            echo json_encode($response);
            wp_die();
        }

        // Verify WooCommerce is active
        if (!class_exists('WooCommerce')) {
            $response = array(
                'success' => false,
                'message' => 'WooCommerce is not active'
            );
            echo json_encode($response);
            wp_die();
        }

        try {
            // Get all WooCommerce product IDs
            $args = array(
                'status' => 'publish',
                'limit' => -1,
                'return' => 'ids',
            );
            
            $wc_product_ids = wc_get_products($args);
            error_log('Found ' . count($wc_product_ids) . ' WooCommerce products');

            if (empty($wc_product_ids)) {
                $response = array(
                    'success' => true,
                    'products' => array(),
                    'message' => 'No WooCommerce products found',
                    'count' => 0
                );
                echo json_encode($response);
                wp_die();
            }

            // Get all products from Pohoda DB
            global $wpdb;
            $pohoda_table = $wpdb->prefix . 'pohoda_products';
            
            // First check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$pohoda_table}'");
            if (!$table_exists) {
                $response = array(
                    'success' => false,
                    'message' => 'Pohoda products table does not exist. Please sync products first.'
                );
                echo json_encode($response);
                wp_die();
            }

            // Get all WooCommerce IDs from Pohoda DB
            $pohoda_wc_ids = $wpdb->get_col("
                SELECT woocommerce_id 
                FROM {$pohoda_table}
                WHERE woocommerce_id IS NOT NULL AND woocommerce_id != 0
            ");
            
            error_log('Found ' . count($pohoda_wc_ids) . ' WooCommerce IDs in Pohoda');

            // Convert IDs to integers for comparison
            $wc_product_ids = array_map('intval', $wc_product_ids);
            $pohoda_wc_ids = array_map('intval', $pohoda_wc_ids);

            // Find WooCommerce products not in Pohoda
            $orphan_ids = array_diff($wc_product_ids, $pohoda_wc_ids);
            error_log('Found ' . count($orphan_ids) . ' orphaned WooCommerce products');

            // Now get full details for these products
            $orphan_products = array();
            foreach ($orphan_ids as $id) {
                $product = wc_get_product($id);
                if ($product) {
                    $orphan_products[] = array(
                        'id'       => $id,
                        'name'     => $product->get_name(),
                        'sku'      => $product->get_sku(),
                        'price'    => $product->get_price(),
                        'stock'    => $product->get_stock_quantity() ?? 'n/a',
                        'edit_url' => get_edit_post_link($id, 'raw')
                    );
                }
            }

            $response = array(
                'success' => true,
                'products' => $orphan_products,
                'count' => count($orphan_products),
                'debug' => array(
                    'wc_count' => count($wc_product_ids),
                    'pohoda_wc_count' => count($pohoda_wc_ids),
                    'orphan_count' => count($orphan_ids)
                )
            );
            
            echo json_encode($response);
            wp_die();
            
        } catch (Exception $e) {
            error_log('Error in find_orphan_wc_products: ' . $e->getMessage());
            
            $response = array(
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            );
            
            echo json_encode($response);
            wp_die();
        }
    }

    /**
     * Delete an orphaned WooCommerce product
     */
    public function delete_orphan_wc_product() {
        check_ajax_referer('pohoda_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error('Invalid product ID');
            return;
        }

        // Verify WooCommerce is active
        if (!$this->woocommerce_active()) {
            wp_send_json_error('WooCommerce is not active');
            return;
        }

        // Check if product exists
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Product not found');
            return;
        }

        // Delete the product with force - permanently deletes it
        $result = wp_delete_post($product_id, true);

        if ($result) {
            wp_send_json_success(array(
                'message' => 'Product deleted successfully'
            ));
        } else {
            wp_send_json_error('Failed to delete product');
        }
    }

    /**
     * Hide an orphaned WooCommerce product (set to private)
     */
    public function hide_orphan_wc_product() {
        check_ajax_referer('pohoda_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error('Invalid product ID');
            return;
        }

        // Verify WooCommerce is active
        if (!$this->woocommerce_active()) {
            wp_send_json_error('WooCommerce is not active');
            return;
        }

        // Check if product exists
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Product not found');
            return;
        }

        // Set product to private
        wp_update_post(array(
            'ID' => $product_id,
            'post_status' => 'private'
        ));

        // Update product visibility in WooCommerce
        $product->set_catalog_visibility('hidden');
        $product->save();

        wp_send_json_success(array(
            'message' => 'Product hidden successfully'
        ));
    }

    /**
     * Check database tables status
     */
    public function check_db_status() {
        check_ajax_referer('pohoda_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $debug_info = $this->api->check_db_tables_status();
        wp_send_json_success($debug_info);
    }

    /**
     * Force create database tables
     */
    public function force_create_tables() {
        check_ajax_referer('pohoda_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $result = $this->api->force_create_tables();
        wp_send_json_success($result);
    }
} 