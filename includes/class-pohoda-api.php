<?php
if (!defined('ABSPATH')) {
    exit;
}

class Pohoda_API {
    private $options;
    private $credentials;
    private $last_curl;

    public function __construct() {
        $this->options = get_option('pohoda_settings');
        if (!empty($this->options['login']) && !empty($this->options['password'])) {
            $this->credentials = base64_encode($this->options['login'] . ':' . $this->options['password']);
        }
    }

    public function get_ico() {
        return !empty($this->options['ico']) ? $this->options['ico'] : '';
    }

    public function get_last_curl() {
        return $this->last_curl;
    }

    public function test_connection() {
        if (!$this->validate_connection()) {
            return ['success' => false, 'data' => 'Invalid connection settings', 'raw' => ''];
        }

        $response = $this->make_request('/status');
        if (!$response) {
            return ['success' => false, 'data' => 'No response from server', 'raw' => ''];
        }

        $company_info = $this->make_request('/status?companyDetail');
        
        return [
            'success' => true,
            'status' => $response,
            'company_info' => $company_info
        ];
    }

    public function get_products($params = array()) {
        if (!$this->validate_connection()) {
            return array('success' => false, 'data' => 'Invalid connection settings');
        }

        // Configure pagination
        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $per_page = isset($params['per_page']) ? min(100, max(1, intval($params['per_page']))) : 10;
        $id_from = isset($params['id_from']) ? intval($params['id_from']) : 0;
        
        $xmlRequest = '<?xml version="1.0" encoding="Windows-1250"?>
<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" xmlns:stk="http://www.stormware.cz/schema/version_2/stock.xsd" xmlns:ftr="http://www.stormware.cz/schema/version_2/filter.xsd" xmlns:lStk="http://www.stormware.cz/schema/version_2/list_stock.xsd" xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" id="Za001" ico="' . $this->get_ico() . '" application="StwTest" version="2.0" note="Export zasob">
<dat:dataPackItem id="a55" version="2.0">
<lStk:listStockRequest version="2.0" stockVersion="2.0">
<lStk:limit>
<ftr:idFrom>' . $id_from . '</ftr:idFrom>
<ftr:count>' . $per_page . '</ftr:count>
</lStk:limit>
<lStk:requestStock>
';

        // Only add filter if at least one filter parameter is provided
        if (!empty($params['search']) || !empty($params['type']) || !empty($params['storage'])) {
            $xmlRequest .= '<ftr:filter>';
            
            if (!empty($params['search'])) {
                $search = iconv('UTF-8', 'Windows-1250//TRANSLIT', $params['search']);
                $search = htmlspecialchars($search, ENT_XML1, 'Windows-1250');
                $xmlRequest .= '<ftr:code>' . $search . '</ftr:code>';
                $xmlRequest .= '<ftr:name>' . $search . '</ftr:name>';
            }
            
            if (!empty($params['type'])) {
                $type = iconv('UTF-8', 'Windows-1250//TRANSLIT', $params['type']);
                $xmlRequest .= '<ftr:type>' . htmlspecialchars($type, ENT_XML1, 'Windows-1250') . '</ftr:type>';
            }
            
            if (!empty($params['storage'])) {
                $storage = iconv('UTF-8', 'Windows-1250//TRANSLIT', $params['storage']);
                $xmlRequest .= '<ftr:storage>' . htmlspecialchars($storage, ENT_XML1, 'Windows-1250') . '</ftr:storage>';
            }
            
            $xmlRequest .= '</ftr:filter>';
        }

        $xmlRequest .= '
</lStk:requestStock>
</lStk:listStockRequest>
</dat:dataPackItem>
</dat:dataPack>';

        $response = $this->send_xml($xmlRequest);
        $responseXml = $response;

        if (!$response) {
            return array('success' => false, 'data' => 'No response from server', 'raw' => '');
        }

        try {
            // Make sure we have a valid response that looks like XML
            if (!preg_match('/<.*>/', $response)) {
                return array('success' => false, 'data' => 'Invalid response format (not XML)', 'raw' => $responseXml);
            }
            
            // Skip the conversion and encoding checks - process the raw XML as is
            libxml_use_internal_errors(true);
            
            // Check if the response contains error text (sometimes POHODA returns HTML error pages)
            if (stripos($response, '<html') !== false || stripos($response, '<body') !== false) {
                return array('success' => false, 'data' => 'Server returned HTML instead of XML', 'raw' => $responseXml);
            }
            
            // Just use the DOM to parse the XML directly without encoding conversion
            $dom = new DOMDocument('1.0', 'Windows-1250');
            $dom->loadXML($response, LIBXML_NOWARNING | LIBXML_NOERROR);
            
            if ($dom === false) {
                $errors = libxml_get_errors();
                $error_msg = '';
                foreach ($errors as $error) {
                    $error_msg .= "XML Error: {$error->message} at line {$error->line}\n";
                }
                libxml_clear_errors();
                return array('success' => false, 'data' => 'Failed to parse XML response: ' . $error_msg, 'raw' => $responseXml);
            }
            
            // Process the DOM to extract products
            $products = array();
            $lastId = $id_from;
            
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('rsp', 'http://www.stormware.cz/schema/version_2/response.xsd');
            $xpath->registerNamespace('dat', 'http://www.stormware.cz/schema/version_2/data.xsd');
            $xpath->registerNamespace('lStk', 'http://www.stormware.cz/schema/version_2/list_stock.xsd');
            $xpath->registerNamespace('stk', 'http://www.stormware.cz/schema/version_2/stock.xsd');
            $xpath->registerNamespace('typ', 'http://www.stormware.cz/schema/version_2/type.xsd');
            $xpath->registerNamespace('ftr', 'http://www.stormware.cz/schema/version_2/filter.xsd');
            
            // Get stock items
            $stockItems = $xpath->query('//lStk:stock');
            
            if ($stockItems->length === 0) {
                // If no stock items found, check for error message in response
                $errorMsg = $xpath->query('//message');
                $errorText = $errorMsg->length > 0 ? $errorMsg->item(0)->nodeValue : 'No products found';
                
                return array(
                    'success' => true,
                    'data' => array(), 
                    'raw' => $responseXml,
                    'message' => $errorText,
                    'pagination' => array(
                        'total' => 0,
                        'per_page' => $per_page,
                        'current_page' => $page,
                        'last_page' => 0,
                        'from' => 1,
                        'to' => 0,
                        'last_id' => $id_from
                    )
                );
            }
            
            // Iterate through stock items
            foreach ($stockItems as $item) {
                $stockHeader = $xpath->query('./stk:stockHeader', $item)->item(0);
                if (!$stockHeader) {
                    continue;
                }
                
                $id = $xpath->query('./stk:id', $stockHeader)->item(0)->nodeValue;
                if (!empty($id)) {
                    $lastId = max($lastId, (int)$id);
                    
                    // Extract data from XML
                    $code = $xpath->query('./stk:code', $stockHeader)->item(0);
                    $name = $xpath->query('./stk:name', $stockHeader)->item(0);
                    $unit = $xpath->query('./stk:unit', $stockHeader)->item(0);
                    $type = $xpath->query('./stk:stockType', $stockHeader)->item(0);
                    $storage = $xpath->query('./stk:storage/typ:ids', $stockHeader)->item(0);
                    $count = $xpath->query('./stk:count', $stockHeader)->item(0);
                    $purchasingPrice = $xpath->query('./stk:purchasingPrice', $stockHeader)->item(0);
                    $sellingPrice = $xpath->query('./stk:sellingPrice', $stockHeader)->item(0);
                    
                    $product = array(
                        'id' => $id,
                        'code' => $code ? $code->nodeValue : '',
                        'name' => $name ? $name->nodeValue : '',
                        'unit' => $unit ? $unit->nodeValue : '',
                        'type' => $type ? $type->nodeValue : '',
                        'storage' => $storage ? $storage->nodeValue : '',
                        'count' => $count ? (float)$count->nodeValue : 0,
                        'purchasing_price' => $purchasingPrice ? (float)$purchasingPrice->nodeValue : 0,
                        'selling_price' => $sellingPrice ? (float)$sellingPrice->nodeValue : 0,
                        'price_variants' => array()
                    );
                    
                    // Get price variants
                    $stockPriceItem = $xpath->query('./stk:stockPriceItem', $item)->item(0);
                    if ($stockPriceItem) {
                        $priceVariants = $xpath->query('./stk:stockPrice', $stockPriceItem);
                        foreach ($priceVariants as $variant) {
                            $variantId = $xpath->query('./typ:id', $variant)->item(0);
                            $variantName = $xpath->query('./typ:ids', $variant)->item(0);
                            $variantPrice = $xpath->query('./typ:price', $variant)->item(0);
                            
                            $product['price_variants'][] = array(
                                'id' => $variantId ? $variantId->nodeValue : '',
                                'name' => $variantName ? $variantName->nodeValue : '',
                                'price' => $variantPrice ? (float)$variantPrice->nodeValue : 0
                            );
                        }
                    }
                    
                    $products[] = $product;
                }
            }

            // Check if products exist in WooCommerce
            if (isset($params['check_woocommerce']) && $params['check_woocommerce']) {
                $products = $this->check_woocommerce_products($products);
            }

            $totalCount = count($products);
            $totalEstimate = $id_from > 0 ? ($page - 1) * $per_page + $totalCount : $totalCount;
            $hasMore = $totalCount >= $per_page;
            
            // If we have a full page, we might have more products available
            if ($hasMore) {
                $totalEstimate += $per_page; // Add an estimate for next page
            }

            return array(
                'success' => true,
                'data' => $products,
                'raw' => $responseXml,
                'pagination' => array(
                    'total' => $totalEstimate,
                    'per_page' => $per_page,
                    'current_page' => $page,
                    'last_page' => ceil($totalEstimate / $per_page),
                    'from' => (($page - 1) * $per_page) + 1,
                    'to' => (($page - 1) * $per_page) + $totalCount,
                    'last_id' => $lastId,
                    'has_more' => $hasMore
                )
            );
        } catch (Exception $e) {
            return array('success' => false, 'data' => 'Error processing response: ' . $e->getMessage(), 'raw' => $responseXml);
        }
    }

    public function get_orders() {
        if (!$this->validate_connection()) {
            return ['success' => false, 'data' => 'Invalid connection settings', 'raw' => ''];
        }

        $xmlRequest = <<<XML
<?xml version="1.0" encoding="Windows-1250"?>
<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" xmlns:ftr="http://www.stormware.cz/schema/version_2/filter.xsd" xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd" xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" id="001" ico="{$this->get_ico()}" application="StwTest" version="2.0" note="Požadavek na export výběru objednávek">
<dat:dataPackItem id="li1" version="2.0">
<lst:listOrderRequest version="2.0" orderType="receivedOrder" orderVersion="2.0">
<lst:requestOrder>
<ftr:filter>
</ftr:filter>
</lst:requestOrder>
</lst:listOrderRequest>
</dat:dataPackItem>
</dat:dataPack>
XML;

        // Convert XML to Windows-1250 if needed
        if (mb_detect_encoding($xmlRequest, 'UTF-8', true)) {
            $xmlRequest = iconv('UTF-8', 'CP1250//TRANSLIT', $xmlRequest);
        }

        $response = $this->make_request('/xml', 'POST', $xmlRequest);
        
        if (!$response) {
            return ['success' => false, 'data' => 'No response from server', 'raw' => ''];
        }
        
        return ['success' => true, 'data' => $response, 'raw' => $response];
    }

    public function send_xml($xml) {
        if (!$this->validate_connection()) {
            return false;
        }

        // Convert XML to Windows-1250 if needed
        if (mb_detect_encoding($xml, 'UTF-8', true)) {
            $xml = iconv('UTF-8', 'CP1250//TRANSLIT', $xml);
            if ($xml === false) {
                error_log("Pohoda API Error: Failed to convert XML to Windows-1250 encoding");
                return false;
            }
        }

        $this->last_curl = curl_init();
        curl_setopt_array($this->last_curl, [
            CURLOPT_PORT => $this->options['port'],
            CURLOPT_URL => "http://{$this->options['ip_address']}:{$this->options['port']}/xml",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => [
                "STW-Authorization: Basic {$this->credentials}",
                "Content-Type: application/xml; charset=Windows-1250",
                "Content-Length: " . strlen($xml)
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_VERBOSE => true
        ]);
        
        $response = curl_exec($this->last_curl);
        $err = curl_error($this->last_curl);
        $httpCode = curl_getinfo($this->last_curl, CURLINFO_HTTP_CODE);
        
        if ($err) {
            error_log("Pohoda API Error: " . $err);
            error_log("HTTP Code: " . $httpCode);
            error_log("URL: http://{$this->options['ip_address']}:{$this->options['port']}/xml");
            curl_close($this->last_curl);
            return false;
        }
        
        if ($httpCode >= 400) {
            error_log("Pohoda API Error: HTTP Code " . $httpCode);
            error_log("Response: " . $response);
            curl_close($this->last_curl);
            return false;
        }
        
        curl_close($this->last_curl);
        
        // Log the request and response for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Pohoda API Request: " . $xml);
            error_log("Pohoda API Response: " . $response);
        }
        
        return $response;
    }

    public function get_stores() {
        if (!$this->validate_connection()) {
            return false;
        }

        $xmlRequest = <<<XML
<?xml version="1.0" encoding="Windows-1250"?>
<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" xmlns:sto="http://www.stormware.cz/schema/version_2/store.xsd" xmlns:ftr="http://www.stormware.cz/schema/version_2/filter.xsd" xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd" xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" id="Za001" ico="{$this->get_ico()}" application="StwTest" version="2.0" note="Export skladů">
<dat:dataPackItem id="a55" version="2.0">
<lst:listStoreRequest version="2.0" storeVersion="2.0">
<lst:requestStore>
<ftr:filter>
</ftr:filter>
</lst:requestStore>
</lst:listStoreRequest>
</dat:dataPackItem>
</dat:dataPack>
XML;

        // Convert XML to Windows-1250 if needed
        if (mb_detect_encoding($xmlRequest, 'UTF-8', true)) {
            $xmlRequest = iconv('UTF-8', 'CP1250//TRANSLIT', $xmlRequest);
        }

        $response = $this->make_request('/xml', 'POST', $xmlRequest);
        $responseXml = $response;
        
        if (!$response) {
            error_log('Pohoda API: No response from server');
            error_log('Request URL: http://' . $this->options['ip_address'] . ':' . $this->options['port'] . '/xml');
            error_log('Request XML: ' . $xmlRequest);
            return [
                'success' => false,
                'data' => 'No response from server. Check server logs for details.',
                'raw' => ''
            ];
        }
        
        // Parse XML
        $xml = simplexml_load_string($response);
        if (!$xml) {
            error_log('Pohoda API: Failed to parse XML response');
            error_log('Response: ' . $response);
            return [
                'success' => false,
                'data' => 'Failed to parse XML response: ' . $response,
                'raw' => $responseXml
            ];
        }

        // Register namespaces
        $xml->registerXPathNamespace('rsp', 'http://www.stormware.cz/schema/version_2/response.xsd');
        $xml->registerXPathNamespace('lst', 'http://www.stormware.cz/schema/version_2/list.xsd');
        $xml->registerXPathNamespace('sto', 'http://www.stormware.cz/schema/version_2/store.xsd');
        $xml->registerXPathNamespace('typ', 'http://www.stormware.cz/schema/version_2/type.xsd');

        // Get the listStore element
        $listStore = $xml->xpath('//lst:listStore')[0];
        if (!$listStore) {
            error_log('Pohoda API: No listStore element found in response');
            error_log('Response: ' . $response);
            return [
                'success' => false,
                'data' => 'No listStore element found in response: ' . $response,
                'raw' => $responseXml
            ];
        }

        $stores = [];
        $storeItems = $listStore->xpath('.//lst:store');

        if (empty($storeItems)) {
            error_log('Pohoda API: No store items found in response');
            error_log('Response: ' . $response);
            return [
                'success' => false,
                'data' => 'No stores found in response: ' . $response,
                'raw' => $responseXml
            ];
        }

        foreach ($storeItems as $item) {
            $store = [
                'id' => (string)$item->xpath('.//sto:id')[0],
                'name' => (string)$item->xpath('.//sto:name')[0],
                'text' => (string)$item->xpath('.//sto:text')[0],
                'usePLU' => (string)$item->xpath('.//sto:PLU/sto:usePLU')[0] === 'true',
                'lowerLimit' => (int)$item->xpath('.//sto:PLU/sto:lowerLimit')[0],
                'upperLimit' => (int)$item->xpath('.//sto:PLU/sto:upperLimit')[0]
            ];

            // Get storekeeper if exists
            $storekeeper = $item->xpath('.//sto:storekeeper/typ:ids');
            if (!empty($storekeeper)) {
                $store['storekeeper'] = (string)$storekeeper[0];
            }

            $stores[] = $store;
        }

        return [
            'success' => true,
            'data' => $stores
        ];
    }

    private function validate_connection() {
        if (empty($this->options['ip_address']) || empty($this->options['port']) || empty($this->options['login']) || empty($this->options['password']) || empty($this->options['ico'])) {
            return false;
        }
        return true;
    }

    private function make_request($endpoint, $method = 'GET', $data = null) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_PORT => $this->options['port'],
            CURLOPT_URL => "http://{$this->options['ip_address']}:{$this->options['port']}{$endpoint}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                "STW-Authorization: Basic {$this->credentials}",
                "Content-Type: application/xml; charset=CP1250"
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_VERBOSE => true
        ]);

        if ($data) {
            // Convert data to Windows-1250 if needed
            if (mb_detect_encoding($data, 'UTF-8', true)) {
                $data = iconv('UTF-8', 'CP1250//TRANSLIT', $data);
            }
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        if ($err) {
            error_log("Pohoda API Error: " . $err);
            error_log("HTTP Code: " . $httpCode);
            error_log("URL: http://{$this->options['ip_address']}:{$this->options['port']}{$endpoint}");
            curl_close($curl);
            return false;
        }

        curl_close($curl);
        return $response;
    }

    /**
     * Check if products from Pohoda exist in WooCommerce and compare data
     * 
     * @param array $products Array of products from Pohoda
     * @return array Products with added woocommerce data and comparison status
     */
    public function check_woocommerce_products($products) {
        if (!is_array($products) || empty($products)) {
            return $products;
        }
        
        global $wpdb;
        
        // Extract all codes from products
        $codes = array();
        foreach ($products as $product) {
            if (!empty($product['code'])) {
                $codes[] = $product['code'];
            }
        }
        
        if (empty($codes)) {
            return $products;
        }
        
        // Format codes for SQL IN clause
        $codes_placeholders = implode(',', array_fill(0, count($codes), '%s'));
        $prepared_query = $wpdb->prepare(
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
        $wc_products = $wpdb->get_results($prepared_query, ARRAY_A);
        
        // Create lookup array for quick access
        $sku_to_product = array();
        foreach ($wc_products as $wc_product) {
            $sku_to_product[$wc_product['sku']] = $wc_product['ID'];
        }
        
        // Add WooCommerce info to each product
        foreach ($products as &$product) {
            if (!empty($product['code']) && isset($sku_to_product[$product['code']])) {
                $wc_id = $sku_to_product[$product['code']];
                $product['woocommerce_exists'] = true;
                $product['woocommerce_id'] = $wc_id;
                $product['woocommerce_url'] = get_edit_post_link($wc_id, '');
                
                // Get WooCommerce product data to compare
                $wc_product = wc_get_product($wc_id);
                if ($wc_product) {
                    // Get stock quantity
                    $wc_stock = $wc_product->get_stock_quantity();
                    $product['woocommerce_stock'] = $wc_stock !== null ? $wc_stock : '';
                    
                    // Get price
                    $wc_price = $wc_product->get_regular_price();
                    $product['woocommerce_price'] = $wc_price;
                    
                    // Compare data
                    $stock_diff = abs(($wc_stock !== null ? (float)$wc_stock : 0) - (float)$product['count']);
                    $stock_match = $stock_diff <= 0.001; // Allow tiny difference due to float comparison
                    
                    $price_diff = abs(($wc_price !== '' ? (float)$wc_price : 0) - (float)$product['selling_price']);
                    $price_match = $price_diff <= 0.01; // Allow 1 cent difference
                    
                    // Set comparison status
                    if ($stock_match && $price_match) {
                        $product['comparison_status'] = 'match'; // All good - green
                    } else {
                        $product['comparison_status'] = 'mismatch'; // Something's different - yellow
                        
                        // Detailed mismatch info
                        $product['mismatches'] = array();
                        if (!$stock_match) {
                            $product['mismatches'][] = array(
                                'field' => 'stock',
                                'pohoda' => $product['count'],
                                'woocommerce' => $wc_stock
                            );
                        }
                        if (!$price_match) {
                            $product['mismatches'][] = array(
                                'field' => 'price',
                                'pohoda' => $product['selling_price'],
                                'woocommerce' => $wc_price
                            );
                        }
                    }
                } else {
                    $product['comparison_status'] = 'unknown'; // WooCommerce product can't be loaded
                    $product['woocommerce_stock'] = '';
                    $product['woocommerce_price'] = '';
                }
            } else {
                $product['woocommerce_exists'] = false;
                $product['woocommerce_id'] = 0;
                $product['woocommerce_url'] = '';
                $product['woocommerce_stock'] = '';
                $product['woocommerce_price'] = '';
                $product['comparison_status'] = 'missing'; // Missing - red
            }
        }
        
        return $products;
    }
} 