<?php
if (!defined('ABSPATH')) {
    exit;
}

class Pohoda_Admin {
    private $options;
    private $api;
    private $db;
    private $product_service;
    private $image_service;

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
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/pohoda-services/class-pohoda-db-manager.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/pohoda-services/class-pohoda-image-service.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/pohoda-services/class-pohoda-product-service.php';

        global $wpdb;
        $this->options = get_option('pohoda_settings');
        
        // Initialize core services
        $this->api = new Pohoda_API();
        $this->db = new Pohoda_DB();
        
        // Initialize DB Manager
        $db_manager = new Pohoda_DB_Manager($wpdb);
        
        // Initialize Image Service
        $api_client = $this->api->get_api_client();
        $this->image_service = new Pohoda_Image_Service($wpdb, $api_client, $db_manager);
        
        // Initialize Product Service
        $this->product_service = new Pohoda_Product_Service($wpdb, $api_client, $this->image_service, $db_manager);
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
        
        // Check for direct step execution
        if (isset($_GET['step']) && $_GET['step'] === 'pohoda_analyze') {
            $this->handle_direct_analyze_step();
            return;
        }
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
                            <li data-step="sync_rest_products">
                                Synchronizace zbylých produktů...
                                <div class="sub-progress-container" style="margin: 10px 0 0 0;border: 1px #ccc solid; padding: 5px; display: none;">
                                    <span class="sub-progress-label"></span>
                                    <div class="progress-bar-container" style="width: 90%; background-color: #e0e0e0; margin-top: 5px; height: 15px;">
                                        <div class="progress-bar-inner sub-progress-bar" style="height: 100%; width: 0%; background-color: #1e88e5;"></div>
                                    </div>
                                </div>
                            </li>
                            <li data-step="update_pohoda_db">Aktualizace Pohoda databáze...</li>
                            <li data-step="update_prices">Aktualizace cen...</li>
                            <li data-step="update_stock">Aktualizace skladových zásob...</li>
                            <li data-step="reupload_images">
                                Nahrávání obrázků...
                                <div class="sub-progress-container" style="margin-left: 20px; display: none;">
                                    <span class="sub-progress-label"></span>
                                    <div class="progress-bar-container" style="width: 90%; background-color: #e0e0e0; margin-top: 5px; height: 15px;">
                                        <div class="progress-bar-inner sub-progress-bar" style="height: 100%; width: 0%; background-color: #1e88e5;"></div>
                                    </div>
                                </div>
                            </li>
                            <li data-step="create_missing_products">
                                Vytváření chybějících produktů...
                                <div class="sub-progress-container" style="margin-left: 20px; display: none;">
                                    <span class="sub-progress-label"></span>
                                    <div class="progress-bar-container" style="width: 90%; background-color: #e0e0e0; margin-top: 5px; height: 15px;">
                                        <div class="progress-bar-inner sub-progress-bar" style="height: 100%; width: 0%; background-color: #1e88e5;"></div>
                                    </div>
                                </div>
                            </li>
                            <li data-step="check_orphan_products_and_hide">
                                Kontrola osiřelých produktů ve WooCommerce (a skrytí)...
                                <div class="sub-progress-container" style="margin-left: 20px; display: none;">
                                    <span class="sub-progress-label"></span>
                                    <div class="progress-bar-container" style="width: 90%; background-color: #e0e0e0; margin-top: 5px; height: 15px;">
                                        <div class="progress-bar-inner sub-progress-bar" style="height: 100%; width: 0%; background-color: #1e88e5;"></div>
                                    </div>
                                </div>
                            </li>
                            <li data-step="check_no_price_products_and_hide">
                                Kontrola produktů bez ceny (a skrytí)...
                                <div class="sub-progress-container" style="margin-left: 20px; display: none;">
                                    <span class="sub-progress-label"></span>
                                    <div class="progress-bar-container" style="width: 90%; background-color: #e0e0e0; margin-top: 5px; height: 15px;">
                                        <div class="progress-bar-inner sub-progress-bar" style="height: 100%; width: 0%; background-color: #1e88e5;"></div>
                                    </div>
                                </div>
                            </li>
                            <li data-step="check_no_image_products_and_hide">
                                Kontrola produktů bez obrázků (a skrytí)...
                                <div class="sub-progress-container" style="margin-left: 20px; display: none;">
                                    <span class="sub-progress-label"></span>
                                    <div class="progress-bar-container" style="width: 90%; background-color: #e0e0e0; margin-top: 5px; height: 15px;">
                                        <div class="progress-bar-inner sub-progress-bar" style="height: 100%; width: 0%; background-color: #1e88e5;"></div>
                                    </div>
                                </div>
                            </li>
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
                $first_batch_size = 25; 
                $result = $this->_handle_sync_db_products_logic($first_batch_size, 0); 
                if (isset($result['success']) && $result['success']) {
                    wp_send_json_success($result); 
                } else {
                    $error_message = isset($result['message']) ? $result['message'] : 'Failed during download_first_products step.';
                    if (isset($result['errors']) && !empty($result['errors'])) {
                        $error_message .= " Details: " . implode(", ", $result['errors']);
                    }
                    pohoda_debug_log("PohodaAdmin Error in download_first_products: " . print_r($result, true));
                    wp_send_json_error(['message' => $error_message, 'original_data' => ($result['data'] ?? []), 'full_response' => $result]);
                }
                break;
            case 'sync_rest_products':
                $this->handle_sync_rest_products_step();
                break;
            case 'update_pohoda_db':
                $this->handle_analyze_db_vs_wc_step();
                break;
            case 'update_prices':
                $this->handle_update_prices_step();
                break;
            case 'update_stock':
                $this->handle_update_stock_step();
                break;
            case 'reupload_images':
                $this->handle_reupload_images_step();
                break;
            case 'create_missing_products':
                $this->handle_create_missing_products_step();
                break;
            case 'check_orphan_products_and_hide':
                $this->handle_check_orphan_products_step();
                break;
            case 'check_no_price_products_and_hide':
                $this->handle_no_price_products_step();
                break;
            case 'check_no_image_products_and_hide':
                $this->handle_no_image_products_step();
                break;
            case 'finished':
                wp_send_json_success(['message' => 'Krok finished zavolán.']);
                break;
            default:
                wp_send_json_error(['message' => 'Neznámý krok synchronizace: ' . esc_html($step)], 400);
                break;
        }
    }

    /**
     * Handles the 'check_settings' step of the sync wizard.
     */
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
     * Handles the 'sync_rest_products' step of the sync wizard.
     * Loops through remaining products in batches.
     */
    private function handle_sync_rest_products_step() {
        check_ajax_referer('pohoda_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }

        $start_id = isset($_POST['pohoda_last_sync_id']) ? intval($_POST['pohoda_last_sync_id']) : 0;
        $initial_has_more = isset($_POST['pohoda_sync_has_more']) ? filter_var($_POST['pohoda_sync_has_more'], FILTER_VALIDATE_BOOLEAN) : true;
        $processed_in_step = isset($_POST['processed_in_step']) ? intval($_POST['processed_in_step']) : 0;

        if (!$initial_has_more && $start_id > 0) { 
            wp_send_json_success([
                'message' => 'No more products to sync based on prior step.',
                'batch_fetched' => 0, 'batch_inserted' => 0, 'batch_updated' => 0, 
                'batch_images_synced' => 0, 'errors' => [], 'current_last_id' => $start_id,
                'current_has_more' => false, // Explicitly false
                'processed_total_in_step' => $processed_in_step 
            ]);
            return;
        }
        
        $batch_size_rest = 100; 
        pohoda_debug_log("PohodaAdmin: handle_sync_rest_products_step (single batch). Start ID: {$start_id}");

        $batch_result = $this->_handle_sync_db_products_logic($batch_size_rest, $start_id);

        if (!$batch_result || !isset($batch_result['success'])) {
             pohoda_debug_log("PohodaAdmin: sync_rest_products critical error in single batch. Result: " . print_r($batch_result, true));
            wp_send_json_error([
                'message' => "Critical error processing batch at start_id {$start_id}.", 
                'errors' => ["Critical error processing batch at start_id {$start_id}."],
                'current_last_id' => $start_id, 
                'current_has_more' => $initial_has_more, // Propagate old has_more on critical failure
                'processed_total_in_step' => $processed_in_step
            ]);
            return;
        }

        $current_processed_in_step = $processed_in_step + ($batch_result['total_fetched'] ?? 0);

        $response_data = [
            'message' => sprintf("Batch from ID %d processed. Fetched: %d, Inserted: %d, Updated: %d.", 
                                $start_id, 
                                $batch_result['total_fetched'] ?? 0, 
                                $batch_result['total_inserted'] ?? 0, 
                                $batch_result['total_updated'] ?? 0
                            ),
            'batch_fetched' => $batch_result['total_fetched'] ?? 0,
            'batch_inserted' => $batch_result['total_inserted'] ?? 0,
            'batch_updated' => $batch_result['total_updated'] ?? 0,
            'batch_images_synced' => $batch_result['images_synced_total'] ?? 0,
            'errors' => $batch_result['errors'] ?? [],
            'current_last_id' => $batch_result['last_id'] ?? $start_id,
            'current_has_more' => isset($batch_result['has_more']) ? $batch_result['has_more'] : false,
            'success' => $batch_result['success'], // Propagate success status from the batch logic
            'processed_total_in_step' => $current_processed_in_step
        ];
        
        pohoda_debug_log("PohodaAdmin: handle_sync_rest_products_step (single batch) response: " . print_r($response_data, true));

        if ($response_data['success']) {
            wp_send_json_success($response_data);
        } else {
            // If batch_result success is false, but we have data, send it as error data
            wp_send_json_error($response_data); 
        }
    }

    /**
     * Handles the 'update_pohoda_db' step of the sync wizard, 
     * which now analyzes the local DB against WooCommerce.
     */
    public function handle_analyze_db_vs_wc_step() {
        pohoda_debug_log("PohodaAdmin: Starting analyze_db_vs_wc step");
        
        if (!$this->ensure_product_service()) {
            pohoda_debug_log("PohodaAdmin Error: Could not ensure product service for analyze_db_vs_wc step");
            wp_send_json_error(['message' => 'Product service not available.'], 500);
            return;
        }

        try {
            pohoda_debug_log("PohodaAdmin: Analyzing local database against WooCommerce");
            $result = $this->product_service->analyze_local_db_vs_wc();
            
            if (is_wp_error($result)) {
                pohoda_debug_log("PohodaAdmin Error: " . $result->get_error_message());
                wp_send_json_error(['message' => $result->get_error_message()], 500);
                return;
            }
            
            pohoda_debug_log("PohodaAdmin: Analysis completed successfully");
            wp_send_json_success([
                'message' => 'Analysis completed successfully',
                'data' => $result
            ]);
            
        } catch (Exception $e) {
            pohoda_debug_log("PohodaAdmin Error: " . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Handles the 'update_prices' step of the sync wizard.
     */
    private function handle_update_prices_step() {
        check_ajax_referer('pohoda_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }
        pohoda_debug_log("PohodaAdmin: Starting 'update_prices' step.");

        if (!$this->ensure_product_service()) { // Helper to ensure service is ready
            return; // ensure_product_service sends JSON error on failure
        }

        // This method should exist in Pohoda_Product_Service
        // It should iterate all relevant products and update their prices in WC.
        // It should return stats like { success: true/false, updated_count: X, failed_count: Y, errors: [...] }
        $result = $this->product_service->update_all_wc_prices_from_db(); 

        if (isset($result['success']) && $result['success']) {
            $message = sprintf("Aktualizace cen dokončena. Aktualizováno: %d, Selhalo: %d.", 
                $result['updated_count'] ?? 0, 
                $result['failed_count'] ?? 0
            );
            if (!empty($result['errors'])) {
                $message .= " Chyby: " . implode("; ", $result['errors']);
            }
            pohoda_debug_log("PohodaAdmin: 'update_prices' step completed. " . $message);
            wp_send_json_success(['message' => $message, 'data' => $result]);
        } else {
            $error_message = "Chyba při aktualizaci cen.";
            if (isset($result['message'])) $error_message = $result['message'];
            else if (!empty($result['errors'])) $error_message .= " Detaily: " . implode("; ", $result['errors']);
            pohoda_debug_log("PohodaAdmin: 'update_prices' step failed. " . $error_message);
            wp_send_json_error(['message' => $error_message, 'data' => $result]);
        }
    }

    /**
     * Handles the 'update_stock' step of the sync wizard.
     */
    private function handle_update_stock_step() {
        check_ajax_referer('pohoda_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }
        pohoda_debug_log("PohodaAdmin: Starting 'update_stock' step.");

        if (!$this->ensure_product_service()) { 
            return; 
        }

        // This method should exist in Pohoda_Product_Service
        // It should iterate all relevant products and update their stock in WC.
        // It should return stats like { success: true/false, updated_count: X, failed_count: Y, errors: [...] }
        $result = $this->product_service->update_all_wc_stock_from_db();

        if (isset($result['success']) && $result['success']) {
            $message = sprintf("Aktualizace skladů dokončena. Aktualizováno: %d, Selhalo: %d.", 
                $result['updated_count'] ?? 0, 
                $result['failed_count'] ?? 0
            );
            if (!empty($result['errors'])) {
                $message .= " Chyby: " . implode("; ", $result['errors']);
            }
            pohoda_debug_log("PohodaAdmin: 'update_stock' step completed. " . $message);
            wp_send_json_success(['message' => $message, 'data' => $result]);
        } else {
            $error_message = "Chyba při aktualizaci skladů.";
            if (isset($result['message'])) $error_message = $result['message'];
            else if (!empty($result['errors'])) $error_message .= " Detaily: " . implode("; ", $result['errors']);
            pohoda_debug_log("PohodaAdmin: 'update_stock' step failed. " . $error_message);
            wp_send_json_error(['message' => $error_message, 'data' => $result]);
        }
    }
    
    /**
     * Handles the 'reupload_images' step of the sync wizard.
     * Loops through pending images in batches.
     */
    private function handle_reupload_images_step() {
        check_ajax_referer('pohoda_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }
        pohoda_debug_log("PohodaAdmin: Starting 'reupload_images' step.");

        if (!$this->ensure_image_service()) { // Helper to ensure image service is ready
            return; // ensure_image_service sends JSON error on failure
        }

        $batch_limit = isset($_POST['batch_limit']) ? intval($_POST['batch_limit']) : 20; // Use limit from service default or POST
        $processed_in_step = isset($_POST['processed_in_step']) ? intval($_POST['processed_in_step']) : 0;
        $total_synced_in_step = isset($_POST['total_synced_in_step']) ? intval($_POST['total_synced_in_step']) : 0;
        $total_failed_in_step = isset($_POST['total_failed_in_step']) ? intval($_POST['total_failed_in_step']) : 0;

        // Call the service method which processes one batch of pending images
        $result = $this->image_service->sync_pending_images_to_woocommerce($batch_limit);

        if (isset($result['success'])) { // Service method should always return success key
            $current_batch_synced = $result['synced_now'] ?? 0;
            $current_batch_failed = count($result['errors'] ?? []);
            $pending_found_in_batch = $result['total_pending_found'] ?? 0; // How many were pending before this batch ran

            $total_synced_in_step += $current_batch_synced;
            $total_failed_in_step += $current_batch_failed;
            // processed_in_step could be the number of images attempted in this call by the service, which is $pending_found_in_batch if <= $batch_limit
            // or $batch_limit if $pending_found_in_batch > $batch_limit.
            // A simpler way is to count images that were pending for this batch.
            $processed_this_batch = $pending_found_in_batch; //This is the number of items the service attempted from the pending queue.
            $processed_in_step += $processed_this_batch; 

            $message = sprintf("Spracovaná dávka obrázkov. Synchronizované v tejto dávke: %d. Chyby v tejto dávke: %d.", 
                $current_batch_synced, 
                $current_batch_failed
            );

            $response_data = [
                'message' => $message,
                'batch_synced' => $current_batch_synced,
                'batch_failed' => $current_batch_failed,
                'batch_attempted' => $processed_this_batch, // How many items the service looked at for this batch
                'total_synced_in_step' => $total_synced_in_step,
                'total_failed_in_step' => $total_failed_in_step,
                'processed_total_in_step' => $processed_in_step, // Total items looked at across all batches for this step
                'errors' => $result['errors'] ?? [],
                // If pending_found_in_batch > 0 (or == batch_limit), it implies there might be more.
                // The service method returns total_pending_found for *this specific call*, not overall.
                // So, if total_pending_found for this batch was equal to the batch_limit, assume there are more.
                'current_has_more' => ($pending_found_in_batch >= $batch_limit && $batch_limit > 0),
                'success' => $result['success'] // Overall success of this batch operation from service
            ];
            pohoda_debug_log("PohodaAdmin: 'reupload_images' batch completed. " . print_r($response_data, true));
            wp_send_json_success($response_data);

        } else {
            $error_message = "Chyba pri synchronizácii obrázkov.";
            if (isset($result['message'])) $error_message = $result['message'];
            else if (!empty($result['errors'])) $error_message .= " Detaily: " . implode("; ", $result['errors']);
            pohoda_debug_log("PohodaAdmin: 'reupload_images' step failed. " . $error_message);
            wp_send_json_error(['message' => $error_message, 'data' => $result]);
        }
    }
    
    /**
     * Helper function to ensure $this->product_service is initialized.
     * Sends JSON error and returns false if service cannot be initialized.
     * @return bool True if service is ready, false otherwise.
     */
    private function ensure_product_service() {
        if (isset($this->product_service) && $this->product_service instanceof Pohoda_Product_Service) {
            return true;
        }

        if (isset($this->api) && method_exists($this->api, 'get_product_service') && $this->api->get_product_service() instanceof Pohoda_Product_Service) {
            $this->product_service = $this->api->get_product_service();
            return true;
        } 
        
        if (class_exists('Pohoda_Product_Service') && class_exists('Pohoda_DB_Manager') && class_exists('Pohoda_API_Client')) {
            global $wpdb;

            $db_manager_instance = null;
            if (isset($this->db) && method_exists($this->db, 'get_db_manager') && $this->db->get_db_manager() instanceof Pohoda_DB_Manager) {
                 $db_manager_instance = $this->db->get_db_manager();
            } else {
                 $db_manager_instance = new Pohoda_DB_Manager($wpdb);
            }

            $api_client_instance = null;
            if (isset($this->api) && method_exists($this->api, 'get_api_client')) {
                $api_client_instance = $this->api->get_api_client();
            }

            if (!$api_client_instance) {
                pohoda_debug_log("PohodaAdmin Error: Could not obtain a valid Pohoda_API_Client instance for Product Service.");
                wp_send_json_error(['message' => 'API client for Product Service not available.'], 500);
                return false;
            }

            if (!$db_manager_instance) {
                pohoda_debug_log("PohodaAdmin Error: Could not obtain a valid DB Manager instance for Product Service.");
                wp_send_json_error(['message' => 'DB Manager for Product Service not available.'], 500);
                return false;
            }

            // Ensure image service is available
            if (!$this->ensure_image_service()) {
                return false;
            }
            
            // Initialize Product Service with required dependencies
            $this->product_service = new Pohoda_Product_Service($wpdb, $api_client_instance, $this->image_service, $db_manager_instance);
            return true;
        } else {
            $missing_classes = [];
            if (!class_exists('Pohoda_Product_Service')) $missing_classes[] = 'Pohoda_Product_Service';
            if (!class_exists('Pohoda_DB_Manager')) $missing_classes[] = 'Pohoda_DB_Manager';
            if (!class_exists('Pohoda_API_Client')) $missing_classes[] = 'Pohoda_API_Client';

            pohoda_debug_log("PohodaAdmin Error: Pohoda_Product_Service or its core dependencies not available. Missing: " . implode(", ", $missing_classes));
            wp_send_json_error(['message' => 'Product Service or its core dependencies not available. Missing: ' . implode(", ", $missing_classes)], 500);
            return false;
        }
    }

    /**
     * Helper function to ensure $this->image_service is initialized.
     * Sends JSON error and returns false if service cannot be initialized.
     * @return bool True if service is ready, false otherwise.
     */
    private function ensure_image_service() {
        if (isset($this->image_service) && $this->image_service instanceof Pohoda_Image_Service) {
            return true;
        }

        if (class_exists('Pohoda_Image_Service')) {
            global $wpdb;
            if (!$this->api || !method_exists($this->api, 'get_api_client')) {
                pohoda_debug_log("PohodaAdmin Error: API client not available for Image Service");
                wp_send_json_error(['message' => 'API client not available for Image Service.'], 500);
                return false;
            }
            if (!$this->db || !method_exists($this->db, 'get_db_manager')) {
                pohoda_debug_log("PohodaAdmin Error: DB Manager not available for Image Service");
                wp_send_json_error(['message' => 'DB Manager not available for Image Service.'], 500);
                return false;
            }

            $api_client = $this->api->get_api_client();
            $db_manager = $this->db->get_db_manager();
            $this->image_service = new Pohoda_Image_Service($wpdb, $api_client, $db_manager);
            return true;
        }

        pohoda_debug_log("PohodaAdmin Error: Pohoda_Image_Service not available");
        wp_send_json_error(['message' => 'Image service not available.'], 500);
        return false;
    }

    /**
     * AJAX handler to get product categories from the database.
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

    /**
     * Check if WooCommerce is active
     *
     * @return bool True if WooCommerce is active, false otherwise
     */
    private function woocommerce_active() {
        return class_exists('WooCommerce');
    }

    /**
     * Handles the 'create_missing_products' step of the sync wizard.
     * Can fetch a list of missing products or process a batch to create them.
     */
    private function handle_create_missing_products_step() {
        check_ajax_referer('pohoda_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }

        if (!$this->ensure_product_service()) { 
            return; 
        }

        $sub_action = isset($_POST['sub_action']) ? sanitize_text_field($_POST['sub_action']) : 'fetch_list';
        pohoda_debug_log("PohodaAdmin: Starting 'create_missing_products' step. Sub-action: {$sub_action}");

        if ($sub_action === 'fetch_list') {
            // This service method needs to be implemented in Pohoda_Product_Service
            // It should return an array like ['success' => true, 'products' => [...list of product data...], 'total' => count]
            $result = $this->product_service->get_all_products_missing_in_wc(); 

            if (isset($result['success']) && $result['success']) {
                pohoda_debug_log("PohodaAdmin: Fetched list of missing products. Count: " . ($result['total'] ?? count($result['products'] ?? [])));
                wp_send_json_success([
                    'message' => 'Seznam chybějících produktů načten.',
                    'products_to_create' => $result['products'] ?? [],
                    'total_missing' => $result['total'] ?? count($result['products'] ?? [])
                ]);
            } else {
                $error_msg = "Chyba při načítání seznamu chybějících produktů: " . ($result['message'] ?? 'Neznámá chyba služby.');
                pohoda_debug_log("PohodaAdmin: Error fetching list of missing products - {$error_msg}");
                wp_send_json_error(['message' => $error_msg]);
            }
        } elseif ($sub_action === 'process_batch') {
            $products_batch = isset($_POST['products_batch']) && is_array($_POST['products_batch']) ? $_POST['products_batch'] : [];
            $current_offset = isset($_POST['current_offset']) ? intval($_POST['current_offset']) : 0;
            // $total_being_processed = isset($_POST['total_to_process']) ? intval($_POST['total_to_process']) : count($products_batch); // Total from the list JS is iterating

            if (empty($products_batch)) {
                wp_send_json_error(['message' => 'Žádné produkty v dávce ke zpracování.']);
                return;
            }

            $created_count = 0;
            $failed_count = 0;
            $batch_errors = [];

            foreach ($products_batch as $product_data) {
                if (empty($product_data['code']) || empty($product_data['name'])) {
                    $failed_count++;
                    $batch_errors[] = "Produkt ID {$product_data['id']}: Chybí kód nebo název.";
                    continue;
                }

                $existing_wc_id = wc_get_product_id_by_sku($product_data['code']);
                if ($existing_wc_id) {
                    $failed_count++;
                    $batch_errors[] = "Produkt '{$product_data['name']}' (kód: {$product_data['code']}): Již existuje ve WooCommerce (WC ID: {$existing_wc_id}).";
                    // Update local DB to reflect that this product exists in WC
                    $this->product_service->update_local_product_wc_status_after_check($product_data['id'], $existing_wc_id);
                    continue;
                }
                
                $wc_product = new WC_Product_Simple();
                $wc_product->set_name($product_data['name']);
                $wc_product->set_sku($product_data['code']);
                
                $vat_rate = isset($product_data['vat_rate']) ? (float)$product_data['vat_rate'] : 21.0;
                $price_with_vat = (float)($product_data['selling_price'] ?? 0) * (1 + ($vat_rate / 100));
                $wc_product->set_regular_price(wc_format_decimal($price_with_vat, wc_get_price_decimals()));

                $stock_quantity = isset($product_data['count']) ? (int)$product_data['count'] : 0;
                $wc_product->set_manage_stock(true);
                $wc_product->set_stock_quantity($stock_quantity);
                $wc_product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
                $wc_product->set_status('publish');

                try {
                    $new_wc_product_id = $wc_product->save();
                    if ($new_wc_product_id) {
                        $created_count++;
                        $this->product_service->update_local_product_wc_status_after_check($product_data['id'], $new_wc_product_id);
                        // Potentially sync images for this new product if desired immediately
                        // if (!empty($product_data['pictures'])) {
                        //    $this->ensure_image_service();
                        //    if($this->image_service) $this->image_service->sync_product_images($product_data['id'], $product_data['pictures']);
                        // }
                    } else {
                        $failed_count++;
                        $batch_errors[] = "Produkt '{$product_data['name']}' (kód: {$product_data['code']}): Nepodařilo se uložit do WooCommerce.";
                    }
                } catch (Exception $e) {
                    $failed_count++;
                    $batch_errors[] = "Produkt '{$product_data['name']}' (kód: {$product_data['code']}): Výjimka při ukládání - " . $e->getMessage();
                }
            }
            pohoda_debug_log("PohodaAdmin: 'create_missing_products' batch processed. Created: {$created_count}, Failed: {$failed_count}");
            wp_send_json_success([
                'message' => sprintf("Dávka zpracována. Vytvořeno: %d, Selhalo: %d.", $created_count, $failed_count),
                'batch_created' => $created_count,
                'batch_failed' => $failed_count,
                'batch_errors' => $batch_errors
            ]);

        } else {
            wp_send_json_error(['message' => 'Neznámá sub-akce pro vytváření produktů.']);
        }
    }

    /**
     * Handles checking for orphan products in WooCommerce and hiding them.
     */
    private function handle_check_orphan_products_step() {
        check_ajax_referer('pohoda_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }
        if (!$this->ensure_product_service()) { return; }

        $sub_action = isset($_POST['sub_action']) ? sanitize_text_field($_POST['sub_action']) : 'fetch_list';
        pohoda_debug_log("PohodaAdmin: 'check_orphan_products_and_hide' step. Sub-action: {$sub_action}");

        if ($sub_action === 'fetch_list') {
            // Method to be implemented in Pohoda_Product_Service
            // Should return ['success' => true, 'product_ids' => [id1, id2,...], 'total' => count]
            $result = $this->product_service->get_orphan_woocommerce_product_ids(); 
            if (isset($result['success']) && $result['success']) {
                wp_send_json_success([
                    'message' => 'Seznam osiřelých produktů načten.',
                    'product_ids_to_hide' => $result['product_ids'] ?? [],
                    'total_to_hide' => $result['total'] ?? count($result['product_ids'] ?? [])
                ]);
            } else {
                wp_send_json_error(['message' => 'Chyba při načítání osiřelých produktů: ' . ($result['message'] ?? 'Neznámá chyba služby.')]);
            }
        } elseif ($sub_action === 'process_batch') {
            $product_ids_batch = isset($_POST['product_ids_batch']) && is_array($_POST['product_ids_batch']) ? array_map('intval', $_POST['product_ids_batch']) : [];
            if (empty($product_ids_batch)) {
                wp_send_json_error(['message' => 'Žádné ID produktů v dávce ke zpracování.']);
                return;
            }
            $hidden_count = 0; $failed_count = 0; $batch_errors = [];

            foreach ($product_ids_batch as $product_id) {
                if (wc_get_product($product_id)) {
                    wp_update_post(['ID' => $product_id, 'post_status' => 'private']);
                    // Optionally: $product->set_catalog_visibility('hidden'); $product->save();
                    $hidden_count++;
                } else {
                    $failed_count++;
                    $batch_errors[] = "Produkt ID {$product_id} nenalezen pro skrytí.";
                }
            }
            wp_send_json_success([
                'message' => sprintf("Dávka osiřelých produktů zpracována. Skryto: %d, Selhalo: %d.", $hidden_count, $failed_count),
                'batch_hidden' => $hidden_count, 'batch_failed' => $failed_count, 'batch_errors' => $batch_errors
            ]);
        } else {
            wp_send_json_error(['message' => 'Neznámá sub-akce pro kontrolu osiřelých produktů.']);
        }
    }

    /**
     * Handles checking for products with no price and hiding them.
     */
    private function handle_no_price_products_step() {
        check_ajax_referer('pohoda_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Unauthorized'], 403); return; }
        if (!$this->ensure_product_service()) { return; }

        $sub_action = isset($_POST['sub_action']) ? sanitize_text_field($_POST['sub_action']) : 'fetch_list';
        pohoda_debug_log("PohodaAdmin: 'check_no_price_products_and_hide' step. Sub-action: {$sub_action}");

        if ($sub_action === 'fetch_list') {
            // Method to be implemented in Pohoda_Product_Service
            // Should return ['success' => true, 'product_ids' => [id1, id2,...], 'total' => count]
            $result = $this->product_service->get_wc_product_ids_with_no_price();
             if (isset($result['success']) && $result['success']) {
                wp_send_json_success([
                    'message' => 'Seznam produktů bez ceny načten.',
                    'product_ids_to_hide' => $result['product_ids'] ?? [],
                    'total_to_hide' => $result['total'] ?? count($result['product_ids'] ?? [])
                ]);
            } else {
                wp_send_json_error(['message' => 'Chyba při načítání produktů bez ceny: ' . ($result['message'] ?? 'Neznámá chyba služby.')]);
            }
        } elseif ($sub_action === 'process_batch') {
            $product_ids_batch = isset($_POST['product_ids_batch']) && is_array($_POST['product_ids_batch']) ? array_map('intval', $_POST['product_ids_batch']) : [];
            if (empty($product_ids_batch)) { wp_send_json_error(['message' => 'Žádné ID produktů v dávce ke zpracování.']); return; }
            $hidden_count = 0; $failed_count = 0; $batch_errors = [];
            foreach ($product_ids_batch as $product_id) {
                if (wc_get_product($product_id)) {
                    wp_update_post(['ID' => $product_id, 'post_status' => 'private']);
                    $hidden_count++;
                } else { $failed_count++; $batch_errors[] = "Produkt ID {$product_id} nenalezen pro skrytí (bez ceny)."; }
            }
            wp_send_json_success([
                'message' => sprintf("Dávka produktů bez ceny zpracována. Skryto: %d, Selhalo: %d.", $hidden_count, $failed_count),
                'batch_hidden' => $hidden_count, 'batch_failed' => $failed_count, 'batch_errors' => $batch_errors
            ]);
        } else {
            wp_send_json_error(['message' => 'Neznámá sub-akce pro kontrolu produktů bez ceny.']);
        }
    }

    /**
     * Handles checking for products with no images and hiding them.
     */
    private function handle_no_image_products_step() {
        check_ajax_referer('pohoda_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Unauthorized'], 403); return; }
        if (!$this->ensure_product_service()) { return; } // Product service might not be strictly needed if all logic is WC direct

        $sub_action = isset($_POST['sub_action']) ? sanitize_text_field($_POST['sub_action']) : 'fetch_list';
        pohoda_debug_log("PohodaAdmin: 'check_no_image_products_and_hide' step. Sub-action: {$sub_action}");

        if ($sub_action === 'fetch_list') {
            // Method to be implemented in Pohoda_Product_Service or directly here
            // Should return ['success' => true, 'product_ids' => [id1, id2,...], 'total' => count]
            $result = $this->product_service->get_wc_product_ids_with_no_images(); // Assumes method in product service
             if (isset($result['success']) && $result['success']) {
                wp_send_json_success([
                    'message' => 'Seznam produktů bez obrázků načten.',
                    'product_ids_to_hide' => $result['product_ids'] ?? [],
                    'total_to_hide' => $result['total'] ?? count($result['product_ids'] ?? [])
                ]);
            } else {
                wp_send_json_error(['message' => 'Chyba při načítání produktů bez obrázků: ' . ($result['message'] ?? 'Neznámá chyba služby.')]);
            }
        } elseif ($sub_action === 'process_batch') {
            $product_ids_batch = isset($_POST['product_ids_batch']) && is_array($_POST['product_ids_batch']) ? array_map('intval', $_POST['product_ids_batch']) : [];
            if (empty($product_ids_batch)) { wp_send_json_error(['message' => 'Žádné ID produktů v dávce ke zpracování.']); return; }
            $hidden_count = 0; $failed_count = 0; $batch_errors = [];
            foreach ($product_ids_batch as $product_id) {
                $product = wc_get_product($product_id);
                if ($product && !$product->get_image_id()) { // Check if image ID is empty
                    wp_update_post(['ID' => $product_id, 'post_status' => 'private']);
                    $hidden_count++;
                } else if (!$product) {
                    $failed_count++; $batch_errors[] = "Produkt ID {$product_id} nenalezen pro skrytí (bez obrázku).";
                } else {
                    // Product exists and has an image, so don't count as failed if the goal is to hide *only those without*
                }
            }
            wp_send_json_success([
                'message' => sprintf("Dávka produktů bez obrázků zpracována. Skryto: %d, Selhalo (nenalezeno): %d.", $hidden_count, $failed_count),
                'batch_hidden' => $hidden_count, 'batch_failed' => $failed_count, 'batch_errors' => $batch_errors
            ]);
        } else {
            wp_send_json_error(['message' => 'Neznámá sub-akce pro kontrolu produktů bez obrázků.']);
        }
    }

    /**
     * Handles direct execution of analyze step via URL
     */
    private function handle_direct_analyze_step() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        if (!$this->ensure_product_service()) {
            wp_die('Product service not available');
        }

        try {
            $result = $this->product_service->analyze_local_db_vs_wc();
            
            if (is_wp_error($result)) {
                wp_die($result->get_error_message());
            }
            
            // Extract the key statistics
            $stats = $result['data'] ?? [];
            ?>
            <div class="wrap">
                <h1>Aktualizace Pohoda databáze</h1>
                <div class="card">
                    <table class="widefat">
                        <tr>
                            <td><strong>Celkem produktů:</strong></td>
                            <td><?php echo esc_html($stats['total_products'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Shodující se:</strong></td>
                            <td><?php echo esc_html($stats['matching'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Neshodující se:</strong></td>
                            <td><?php echo esc_html($stats['mismatch'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Chybějící:</strong></td>
                            <td><?php echo esc_html($stats['missing'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Neznámé:</strong></td>
                            <td><?php echo esc_html($stats['unknown'] ?? 0); ?></td>
                        </tr>
                    </table>
                </div>
                
                <p><a href="<?php echo admin_url('admin.php?page=pohoda-settings'); ?>" class="button">Zpět na nastavení</a></p>
            </div>
            <?php
        } catch (Exception $e) {
            wp_die('Error during analysis: ' . $e->getMessage());
        }
    }
} 