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
        add_action('wp_ajax_test_pohoda_connection', array($this, 'test_connection'));
        add_action('wp_ajax_load_stores', array($this, 'load_stores'));
        add_action('wp_ajax_load_pohoda_orders', array($this, 'load_orders'));
        add_action('wp_ajax_send_pohoda_xml', array($this, 'send_xml'));
        add_action('wp_ajax_sync_db_product', array($this, 'sync_db_product'));
        add_action('wp_ajax_sync_db_products', array($this, 'sync_db_products'));
        add_action('wp_ajax_get_db_products', array($this, 'get_db_products'));
        add_action('wp_ajax_refresh_wc_data', array($this, 'refresh_wc_data'));
        add_action('wp_ajax_create_wc_product', array($this, 'create_wc_product'));
        add_action('wp_ajax_create_all_missing_products', array($this, 'create_all_missing_products'));
        add_action('wp_ajax_get_all_mismatched_products', array($this, 'get_all_mismatched_products'));
        add_action('wp_ajax_get_all_missing_products', array($this, 'get_all_missing_products'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

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
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';
        ?>
        <div class="wrap">
            <h1>Pohoda Settings</h1>
            <h2 class="nav-tab-wrapper">
                <a href="?page=pohoda-settings&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Nastavení</a>
                <a href="?page=pohoda-settings&tab=products" class="nav-tab <?php echo $active_tab == 'products' ? 'nav-tab-active' : ''; ?>">Produkty</a>
                <a href="?page=pohoda-settings&tab=stores" class="nav-tab <?php echo $active_tab == 'stores' ? 'nav-tab-active' : ''; ?>">Sklady</a>
                <a href="?page=pohoda-settings&tab=orders" class="nav-tab <?php echo $active_tab == 'orders' ? 'nav-tab-active' : ''; ?>">Objednávky</a>
                <a href="?page=pohoda-settings&tab=pohyby" class="nav-tab <?php echo $active_tab == 'pohyby' ? 'nav-tab-active' : ''; ?>">Pohyby</a>
                <a href="?page=pohoda-settings&tab=test" class="nav-tab <?php echo $active_tab == 'test' ? 'nav-tab-active' : ''; ?>">Test</a>
            </h2>

            <?php if($active_tab == 'settings') { ?>
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
            <?php } elseif($active_tab == 'products') { ?>
                <h2>Products</h2>
                <div class="pohoda-products-container">
                    <div class="pohoda-actions">
                        <button id="sync-all-products" class="button button-primary">Sync All Products from Pohoda</button>
                        <button id="sync-all-mismatched" class="button button-warning">Sync All Mismatched</button>
                        <button id="create-all-missing" class="button button-primary">Create All Missing</button>
                        <button id="refresh-wc-data" class="button">WooCommerce Status</button>
                    </div>
                    <div id="sync-progress" style="margin-top: 10px; display: none;">
                        <div class="progress-bar">
                            <div class="progress-bar-inner" style="width: 0%"></div>
                        </div>
                        <div class="progress-status">
                            Processing: <span id="progress-current">0</span> / <span id="progress-total">0</span>
                        </div>
                    </div>
                    <hr>
                    <div class="pohoda-filters">
                        <div class="filter-group">
                            <label for="db-product-search">Search:</label>
                            <input type="text" id="db-product-search" placeholder="Search by name or code">
                        </div>
                        <div class="filter-group">
                            <label for="db-product-type">Type:</label>
                            <select id="db-product-type">
                                <option value="">All Types</option>
                                <option value="material">Material</option>
                                <option value="product">Product</option>
                                <option value="service">Service</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="db-product-storage">Storage:</label>
                            <select id="db-product-storage">
                                <option value="">All Storages</option>
                                <?php
                                $stores = get_option('pohoda_stores', array());
                                foreach ($stores as $store) {
                                    echo '<option value="' . esc_attr($store['id']) . '">' . esc_html($store['name']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="db-comparison-status">Status:</label>
                            <select id="db-comparison-status">
                                <option value="">All Statuses</option>
                                <option value="match">Matched</option>
                                <option value="mismatch">Mismatched</option>
                                <option value="missing">Missing in WooCommerce</option>
                                <option value="unknown">Unknown</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="db-products-per-page">Items per page:</label>
                            <select id="db-products-per-page">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="1000">Show All</option>
                            </select>
                        </div>
                        <button id="load-db-products" class="button button-primary">Load Products</button>
                    </div>
                    <div id="products-result" style="margin-top: 20px;"></div>
                    <div class="pohoda-pagination" style="margin-top: 20px; display: none;">
                        <button class="button" id="prev-page">Previous</button>
                        <span id="page-info">Page 1 of 1</span>
                        <button class="button" id="next-page">Next</button>
                    </div>
                </div>
                <style>
                    .pohoda-products-container {
                        margin-top: 20px;
                    }
                    .pohoda-actions {
                        display: flex;
                        gap: 10px;
                        margin-bottom: 20px;
                    }
                    .pohoda-filters {
                        display: flex;
                        flex-wrap: wrap;
                        gap: 15px;
                        margin-bottom: 20px;
                        padding: 15px;
                        background: #f8f9fa;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                    }
                    .filter-group {
                        display: flex;
                        flex-direction: column;
                        gap: 5px;
                    }
                    .filter-group label {
                        font-weight: 600;
                    }
                    .filter-group input,
                    .filter-group select {
                        min-width: 200px;
                    }
                    .pohoda-pagination {
                        display: flex;
                        align-items: center;
                        gap: 10px;
                        justify-content: center;
                    }
                    #page-info {
                        min-width: 100px;
                        text-align: center;
                    }
                    .progress-bar {
                        height: 20px;
                        background-color: #f0f0f0;
                        border-radius: 4px;
                        margin-bottom: 10px;
                        overflow: hidden;
                    }
                    .progress-bar-inner {
                        height: 100%;
                        background-color: #0073aa;
                        transition: width 0.3s ease;
                    }
                    .progress-status {
                        text-align: center;
                        font-weight: bold;
                    }
                    /* Button styles */
                    .view-woo-button, .edit-woo-button {
                        padding: 4px !important;
                        line-height: 1 !important;
                        height: auto !important;
                        min-height: 30px !important;
                        vertical-align: middle !important;
                    }
                    .dashicons {
                        line-height: 1.5 !important;
                        width: 18px !important;
                        height: 18px !important;
                        font-size: 18px !important;
                        vertical-align: middle !important;
                    }
                    .button-warning {
                        background-color: #ffb900 !important;
                        border-color: #d39700 !important;
                        color: #000 !important;
                    }
                    .button-warning:hover, .button-warning:focus {
                        background-color: #f7a600 !important;
                        border-color: #c08600 !important;
                    }
                </style>
            <?php } elseif($active_tab == 'stores') { ?>
                <h2>Sklady</h2>
                <div class="pohoda-controls">
                    <button id="load-stores" class="button button-primary">Načíst sklady</button>
                    <div id="store-count" class="pohoda-count"></div>
                </div>
                <div id="stores-table-container"></div>
            <?php } elseif($active_tab == 'orders') { ?>
                <h2>Orders</h2>
                <button id="load-orders" class="button button-primary">Load Orders</button>
                <div id="orders-result" style="margin-top: 10px;"></div>
                <div id="orders-raw" style="margin-top: 10px; display: none;">
                    <h3>Raw Response</h3>
                    <pre style="background: #f0f0f0; padding: 10px; overflow: auto;"></pre>
                </div>
            <?php } elseif($active_tab == 'pohyby') { ?>
                <h2>Pohyby</h2>
                <div class="pohyby-form">
                    <form id="pohyby-form">
                        <table class="form-table">
                            <tr>
                                <th><label for="agenda">Agenda</label></th>
                                <td>
                                    <select name="agenda" id="agenda" required>
                                        <option value="stock">Sklad</option>
                                        <option value="order">Objednávka</option>
                                        <option value="invoice">Faktura</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="stockType">Typ zásoby</label></th>
                                <td>
                                    <select name="stockType" id="stockType">
                                        <option value="material">Materiál</option>
                                        <option value="product">Zboží</option>
                                        <option value="service">Služba</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="stockItem">Skladová položka</label></th>
                                <td>
                                    <input type="text" name="stockItem" id="stockItem" required>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="unit">Měrná jednotka</label></th>
                                <td>
                                    <input type="text" name="unit" id="unit">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="date">Datum pohybu</label></th>
                                <td>
                                    <input type="date" name="date" id="date" required>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="movementType">Typ pohybu</label></th>
                                <td>
                                    <select name="movementType" id="movementType" required>
                                        <option value="expense">Výdej</option>
                                        <option value="receipt">Příjem</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="quantity">Množství</label></th>
                                <td>
                                    <input type="number" name="quantity" id="quantity" step="0.01" required>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="unitPrice">Jednotková cena</label></th>
                                <td>
                                    <input type="number" name="unitPrice" id="unitPrice" step="0.01">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="price">Celkem</label></th>
                                <td>
                                    <input type="number" name="price" id="price" step="0.01">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="number">Číslo dokladu</label></th>
                                <td>
                                    <input type="text" name="number" id="number" required>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="regNumber">Evidenční číslo</label></th>
                                <td>
                                    <input type="text" name="regNumber" id="regNumber" maxlength="48" required>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" class="button button-primary">Odeslat</button>
                        </p>
                    </form>
                    <div id="pohyby-response" style="margin-top: 20px;">
                        <h3>Response</h3>
                        <pre style="background: #f0f0f0; padding: 10px; overflow: auto; min-height: 200px;"></pre>
                    </div>
                </div>
            <?php } elseif($active_tab == 'test') { ?>
                <h2>Test XML Request</h2>
                <div style="margin-bottom: 20px;">
                    <textarea id="xml-request" style="width: 100%; height: 200px; font-family: monospace;"><?php echo htmlspecialchars('<?xml version="1.0" encoding="UTF-8"?>
<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" xmlns:stk="http://www.stormware.cz/schema/version_2/stock.xsd" xmlns:ftr="http://www.stormware.cz/schema/version_2/filter.xsd" xmlns:lStk="http://www.stormware.cz/schema/version_2/list_stock.xsd" xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" id="Za001" ico="' . (!empty($this->options['ico']) ? $this->options['ico'] : '') . '" application="StwTest" version="2.0" note="Export zásob">
<dat:dataPackItem id="a55" version="2.0">
<lStk:listStockRequest version="2.0" stockVersion="2.0">
<lStk:requestStock>
<ftr:filter>
</ftr:filter>
</lStk:requestStock>
</lStk:listStockRequest>
</dat:dataPackItem>
</dat:dataPack>'); ?></textarea>
                </div>
                <button id="send-xml" class="button button-primary">Send Request</button>
                <div id="xml-response" style="margin-top: 20px;">
                    <h3>Response</h3>
                    <pre style="background: #f0f0f0; padding: 10px; overflow: auto; min-height: 200px;"></pre>
                </div>
            <?php } ?>
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
            wp_send_json_error('Unauthorized');
            return;
        }

        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 25;
        $start_id = isset($_POST['start_id']) ? intval($_POST['start_id']) : 0;

        $result = $this->db->sync_products($this->api, $batch_size, $start_id);

        wp_send_json_success($result);
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
        wp_enqueue_script('pohoda-admin', plugin_dir_url(__FILE__) . 'assets/js/admin.js', array('jquery'), '1.0.0', true);
        wp_enqueue_script('pohoda-db-products', plugin_dir_url(__FILE__) . 'assets/js/db-products.js', array('jquery'), '1.0.0', true);
        wp_localize_script('pohoda-admin', 'pohodaAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pohoda_nonce'),
            'ico' => !empty($this->options['ico']) ? $this->options['ico'] : ''
        ));
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
} 