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
                // Only search by name if specifically requested or if we're not doing a code-only search
                if (!isset($params['code_only']) || !$params['code_only']) {
                    $xmlRequest .= '<ftr:name>' . $search . '</ftr:name>';
                }
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
                    $sellingRateVAT = $xpath->query('./stk:sellingRateVAT', $stockHeader)->item(0);
                    
                    // Determine VAT rate percentage
                    $vatRate = 21; // Default 21%
                    if ($sellingRateVAT) {
                        $vatRateValue = $sellingRateVAT->nodeValue;
                        if ($vatRateValue === 'high') {
                            $vatRate = 21; // high rate in Czech Republic
                        } elseif ($vatRateValue === 'low') {
                            $vatRate = 15; // low rate in Czech Republic
                        } elseif ($vatRateValue === 'third') {
                            $vatRate = 10; // third rate in Czech Republic
                        } elseif ($vatRateValue === 'none') {
                            $vatRate = 0; // no VAT
                        }
                    }
                    
                    // Get related files
                    $relatedFiles = array();
                    $relatedFilesNode = $xpath->query('./stk:relatedFiles/stk:relatedFile', $item);
                    if ($relatedFilesNode && $relatedFilesNode->length > 0) {
                        foreach ($relatedFilesNode as $fileNode) {
                            $filepath = $xpath->query('./stk:filepath', $fileNode)->item(0);
                            $description = $xpath->query('./stk:description', $fileNode)->item(0);
                            $order = $xpath->query('./stk:order', $fileNode)->item(0);
                            
                            $relatedFiles[] = array(
                                'filepath' => $filepath ? $filepath->nodeValue : '',
                                'description' => $description ? $description->nodeValue : '',
                                'order' => $order ? (int)$order->nodeValue : 0
                            );
                        }
                    }
                    
                    // Get pictures
                    $pictures = array();
                    $picturesNode = $xpath->query('./stk:pictures/stk:picture', $item);
                    if ($picturesNode && $picturesNode->length > 0) {
                        foreach ($picturesNode as $pictureNode) {
                            $pictureId = $xpath->query('./stk:id', $pictureNode)->item(0);
                            $filepath = $xpath->query('./stk:filepath', $pictureNode)->item(0);
                            $description = $xpath->query('./stk:description', $pictureNode)->item(0);
                            $order = $xpath->query('./stk:order', $pictureNode)->item(0);
                            $default = $pictureNode->getAttribute('default') === 'true';
                            
                            $pictures[] = array(
                                'id' => $pictureId ? (int)$pictureId->nodeValue : 0,
                                'filepath' => $filepath ? $filepath->nodeValue : '',
                                'description' => $description ? $description->nodeValue : '',
                                'order' => $order ? (int)$order->nodeValue : 0,
                                'default' => $default
                            );
                        }
                    }
                    
                    // Get categories
                    $categories = array();
                    $categoriesNode = $xpath->query('./stk:categories/stk:idCategory', $item);
                    if ($categoriesNode && $categoriesNode->length > 0) {
                        foreach ($categoriesNode as $categoryNode) {
                            $categories[] = (int)$categoryNode->nodeValue;
                        }
                    }
                    
                    // Get related stocks
                    $relatedStocks = array();
                    $relatedStocksNode = $xpath->query('./stk:relatedStocks/stk:idStocks', $item);
                    if ($relatedStocksNode && $relatedStocksNode->length > 0) {
                        foreach ($relatedStocksNode as $stockNode) {
                            $relatedStocks[] = (int)$stockNode->nodeValue;
                        }
                    }
                    
                    // Get alternative stocks
                    $alternativeStocks = array();
                    $alternativeStocksNode = $xpath->query('./stk:alternativeStocks/stk:idStocks', $item);
                    if ($alternativeStocksNode && $alternativeStocksNode->length > 0) {
                        foreach ($alternativeStocksNode as $stockNode) {
                            $alternativeStocks[] = (int)$stockNode->nodeValue;
                        }
                    }
                    
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
                        'vat_rate' => $vatRate,
                        'related_files' => $relatedFiles,
                        'pictures' => $pictures,
                        'categories' => $categories,
                        'related_stocks' => $relatedStocks,
                        'alternative_stocks' => $alternativeStocks,
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

    /**
     * Create local database tables for storing products
     */
    public function create_db_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'pohoda_products';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
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
     * Sync all products from Pohoda to local database
     * 
     * @param int $batch_size Number of products to fetch per API call
     * @param int $start_id ID to start from (for resuming batch operations)
     * @return array Result of the sync operation
     */
    public function sync_products_to_db($batch_size = 100, $start_id = 0) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pohoda_products';
        
        // Ensure the table exists
        $this->create_db_tables();
        
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
            
            $response = $this->get_products($params);
            
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
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE id = %d", $product['id']));
                
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
                        $table_name,
                        $data,
                        ['id' => $product['id']],
                        $format,
                        ['%d']
                    );
                    $results['total_updated']++;
                } else {
                    // Insert new record
                    $wpdb->insert(
                        $table_name,
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
     * Get products from local database with filtering and pagination
     * 
     * @param array $params Query parameters
     * @return array Products with pagination info
     */
    public function get_products_from_db($params = []) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pohoda_products';
        
        // Default parameters
        $defaults = [
            'search' => '',
            'type' => '',
            'storage' => '',
            'per_page' => 10,
            'page' => 1,
            'check_woocommerce' => true,
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
            "SELECT * FROM $table_name $where_clause ORDER BY {$params['order_by']} $order LIMIT %d OFFSET %d",
            array_merge($where_values, [$params['per_page'], $offset])
        );
        
        // Get total count for pagination
        $count_query = "SELECT COUNT(*) FROM $table_name $where_clause";
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
     */
    public function refresh_woocommerce_data() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pohoda_products';
        
        // Get all product codes
        $products = $wpdb->get_results("SELECT id, code FROM $table_name", ARRAY_A);
        
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
                    $pohoda_data = $wpdb->get_row($wpdb->prepare(
                        "SELECT count, selling_price FROM $table_name WHERE code = %s",
                        $product['code']
                    ), ARRAY_A);
                    
                    if ($pohoda_data) {
                        $stock_diff = abs(($wc_stock !== null ? (float)$wc_stock : 0) - (float)$pohoda_data['count']);
                        $stock_match = $stock_diff <= 0.001;
                        
                        $price_diff = abs(($wc_price !== '' ? (float)$wc_price : 0) - (float)$pohoda_data['selling_price']);
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
                $table_name,
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
} 