<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . '../pohoda-logger.php'; // Include the custom logger

class Pohoda_Product_Service {
    private $wpdb;
    private $api_client;
    private $image_service;
    private $db_manager;

    public function __construct(wpdb $wpdb, Pohoda_API_Client $api_client, Pohoda_Image_Service $image_service, Pohoda_DB_Manager $db_manager) {
        $this->wpdb = $wpdb;
        $this->api_client = $api_client;
        $this->image_service = $image_service;
        $this->db_manager = $db_manager;
    }

    public function get_products_from_api($params = array()) {
        pohoda_debug_log("Pohoda_Product_Service: get_products_from_api called. Params: " . print_r($params, true));

        if (!$this->api_client->validate_connection()) {
            pohoda_debug_log("Pohoda_Product_Service: API connection not valid in get_products_from_api.");
            return array('success' => false, 'data' => 'Invalid API connection settings', 'raw' => '');
        }

        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $per_page = isset($params['per_page']) ? min(100, max(1, intval($params['per_page']))) : 10;
        $id_from = isset($params['id_from']) ? intval($params['id_from']) : 0;

        $xmlRequest = '<?xml version="1.0" encoding="Windows-1250"?>
<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" xmlns:stk="http://www.stormware.cz/schema/version_2/stock.xsd" xmlns:ftr="http://www.stormware.cz/schema/version_2/filter.xsd" xmlns:lStk="http://www.stormware.cz/schema/version_2/list_stock.xsd" xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" id="Za001" ico="' . $this->api_client->get_ico() . '" application="StwTest" version="2.0" note="Export zasob">
<dat:dataPackItem id="a55" version="2.0">
<lStk:listStockRequest version="2.0" stockVersion="2.0">
<lStk:limit>
<ftr:idFrom>' . $id_from . '</ftr:idFrom>
<ftr:count>' . $per_page . '</ftr:count>
</lStk:limit>
<lStk:requestStock>';

        if (!empty($params['search']) || !empty($params['type']) || !empty($params['storage'])) {
            $xmlRequest .= '<ftr:filter>';
            if (!empty($params['search'])) {
                $search = iconv('UTF-8', 'Windows-1250//TRANSLIT', $params['search']);
                $search = htmlspecialchars($search, ENT_XML1, 'Windows-1250');
                $xmlRequest .= '<ftr:code>' . $search . '</ftr:code>';
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

        $xmlRequest .= '</lStk:requestStock>
</lStk:listStockRequest>
</dat:dataPackItem>
</dat:dataPack>';

        $response = $this->api_client->send_xml($xmlRequest);
        $responseXml = $response; // Keep the raw response for debugging
        pohoda_debug_log("Pohoda_Product_Service: Raw XML response from API in get_products_from_api: " . $responseXml);

        if ($response === false) { // send_xml returns false on error
            pohoda_debug_log("Pohoda_Product_Service: API client send_xml failed in get_products_from_api.");
            return array('success' => false, 'data' => 'No response or error from API client', 'raw' => $this->api_client->get_last_curl() ? curl_error($this->api_client->get_last_curl()) : 'Unknown cURL error');
        }
        if (empty(trim($response))){
             return array('success' => false, 'data' => 'Empty response from server', 'raw' => $responseXml);
        }

        try {
            if (!preg_match('/<.*>/', $response)) {
                return array('success' => false, 'data' => 'Invalid response format (not XML)', 'raw' => $responseXml);
            }
            libxml_use_internal_errors(true);
            if (stripos($response, '<html') !== false || stripos($response, '<body') !== false) {
                return array('success' => false, 'data' => 'Server returned HTML instead of XML', 'raw' => $responseXml);
            }

            $dom = new DOMDocument('1.0', 'Windows-1250');
            if (!$dom->loadXML($response, LIBXML_NOWARNING | LIBXML_NOERROR)) {
                 $errors = libxml_get_errors(); $error_msg = '';
                 foreach ($errors as $error) { $error_msg .= "XML Error: {$error->message} at line {$error->line}\n"; }
                 libxml_clear_errors();
                 return array('success' => false, 'data' => 'Failed to parse XML response: ' . $error_msg, 'raw' => $responseXml);
            }

            $products = array(); $lastId = $id_from;
            $xpath = new DOMXPath($dom);
            $this->register_namespaces($xpath);
            $stockItems = $xpath->query('//lStk:stock');

            if ($stockItems->length === 0) {
                $errorMsg = $xpath->query('//rsp:responsePackItem[@state="error"]/rsp:message | //responseState[@state="error"]/message');
                $errorText = $errorMsg->length > 0 ? $errorMsg->item(0)->nodeValue : 'No products found or error in response.';
                 return array('success' => true, 'data' => [], 'raw' => $responseXml, 'message' => $errorText, 'pagination' => $this->get_empty_pagination($page, $per_page, $id_from));
            }

            foreach ($stockItems as $item) {
                $stockHeader = $xpath->query('./stk:stockHeader', $item)->item(0);
                if (!$stockHeader) continue;

                $id = $xpath->query('./stk:id', $stockHeader)->item(0)->nodeValue;
                if (!empty($id)) {
                    $lastId = max($lastId, (int)$id);
                    $code = $xpath->query('./stk:code', $stockHeader)->item(0);
                    $name = $xpath->query('./stk:name', $stockHeader)->item(0);
                    $unit = $xpath->query('./stk:unit', $stockHeader)->item(0);
                    $type = $xpath->query('./stk:stockType', $stockHeader)->item(0);
                    $storage = $xpath->query('./stk:storage/typ:ids', $stockHeader)->item(0);
                    $count = $xpath->query('./stk:count', $stockHeader)->item(0);
                    $purchasingPrice = $xpath->query('./stk:purchasingPrice', $stockHeader)->item(0);
                    $sellingPrice = $xpath->query('./stk:sellingPrice', $stockHeader)->item(0);
                    $sellingRateVAT = $xpath->query('./stk:sellingRateVAT', $stockHeader)->item(0);
                    $vatRate = $this->determine_vat_rate($sellingRateVAT);

                    $relatedFiles = $this->extract_related_files($xpath, $item);
                    $pictures = $this->extract_pictures($xpath, $item);
                    $categories = $this->extract_categories($xpath, $item);
                    $relatedStocks = $this->extract_related_stocks($xpath, $item, 'relatedStocks');
                    $alternativeStocks = $this->extract_related_stocks($xpath, $item, 'alternativeStocks');
                    
                    $product = [
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
                        'price_variants' => $this->extract_price_variants($xpath, $item)
                    ];
                    $products[] = $product;
                }
            }

            pohoda_debug_log("Pohoda_Product_Service: Parsed products in get_products_from_api: " . print_r($products, true));

            if (isset($params['check_woocommerce']) && $params['check_woocommerce']) {
                $products = $this->check_woocommerce_products_status($products);
            }

            $totalCount = count($products);
            $hasMore = $totalCount >= $per_page;
            // Attempt to get total count from response if available
            $responsePack = $xpath->query('/dat:dataPack/rsp:responsePackItem')->item(0);
            $totalItemsFromResponse = null;
            if ($responsePack) {
                $totalItemsFromResponse = $responsePack->getAttribute('total');
            }
            $totalEstimate = $totalItemsFromResponse ? (int)$totalItemsFromResponse : ($id_from > 0 ? ($page - 1) * $per_page + $totalCount + ($hasMore ? $per_page : 0) : $totalCount + ($hasMore ? $per_page : 0) );


            return [
                'success' => true, 'data' => $products, 'raw' => $responseXml,
                'pagination' => [
                    'total' => $totalEstimate,
                    'per_page' => $per_page, 'current_page' => $page,
                    'last_page' => $totalEstimate > 0 && $per_page > 0 ? ceil($totalEstimate / $per_page) : 0,
                    'from' => (($page - 1) * $per_page) + 1,
                    'to' => (($page - 1) * $per_page) + $totalCount,
                    'last_id' => $lastId, 'has_more' => $hasMore
                ]
            ];
        } catch (Exception $e) {
            return array('success' => false, 'data' => 'Error processing response: ' . $e->getMessage(), 'raw' => $responseXml);
        }
    }

    private function register_namespaces(DOMXPath $xpath) {
        $xpath->registerNamespace('rsp', 'http://www.stormware.cz/schema/version_2/response.xsd');
        $xpath->registerNamespace('dat', 'http://www.stormware.cz/schema/version_2/data.xsd');
        $xpath->registerNamespace('lStk', 'http://www.stormware.cz/schema/version_2/list_stock.xsd');
        $xpath->registerNamespace('stk', 'http://www.stormware.cz/schema/version_2/stock.xsd');
        $xpath->registerNamespace('typ', 'http://www.stormware.cz/schema/version_2/type.xsd');
        $xpath->registerNamespace('ftr', 'http://www.stormware.cz/schema/version_2/filter.xsd');
    }
    
    private function get_empty_pagination($page, $per_page, $id_from) {
        return [
            'total' => 0, 'per_page' => $per_page, 'current_page' => $page, 'last_page' => 0,
            'from' => 0, 'to' => 0, 'last_id' => $id_from, 'has_more' => false
        ];
    }

    private function determine_vat_rate($sellingRateVATNode) {
        if (!$sellingRateVATNode) return 21; // Default
        $vatRateValue = $sellingRateVATNode->nodeValue;
        switch ($vatRateValue) {
            case 'high': return 21;
            case 'low': return 15;
            case 'third': return 10;
            case 'none': return 0;
            default: return 21;
        }
    }

    private function extract_related_files(DOMXPath $xpath, DOMElement $item) {
        $files = [];
        $nodes = $xpath->query('./stk:relatedFiles/stk:relatedFile', $item);
        if ($nodes) {
            foreach ($nodes as $node) {
                $filepath = $xpath->query('./stk:filepath', $node)->item(0);
                $description = $xpath->query('./stk:description', $node)->item(0);
                $order = $xpath->query('./stk:order', $node)->item(0);
                $files[] = [
                    'filepath' => $filepath ? $filepath->nodeValue : '',
                    'description' => $description ? $description->nodeValue : '',
                    'order' => $order ? (int)$order->nodeValue : 0
                ];
            }
        }
        return $files;
    }

    private function extract_pictures(DOMXPath $xpath, DOMElement $item) {
        $pictures = [];
        $nodes = $xpath->query('./stk:stockHeader/stk:pictures/stk:picture', $item);
        pohoda_debug_log("Pohoda_Product_Service: extract_pictures - Querying for pictures with XPath './stk:stockHeader/stk:pictures/stk:picture' on item. Found nodes: " . ($nodes ? $nodes->length : 'null'));

        if ($nodes) {
            foreach ($nodes as $node) {
                $id = $xpath->query('./stk:id', $node)->item(0);
                $filepath = $xpath->query('./stk:filepath', $node)->item(0);
                $description = $xpath->query('./stk:description', $node)->item(0);
                $order = $xpath->query('./stk:order', $node)->item(0);
                $default = $node->getAttribute('default') === 'true';
                $pictures[] = [
                    'id' => $id ? (int)$id->nodeValue : 0,
                    'filepath' => $filepath ? $filepath->nodeValue : '',
                    'description' => $description ? $description->nodeValue : '',
                    'order' => $order ? (int)$order->nodeValue : 0,
                    'default' => $default
                ];
            }
        }
        pohoda_debug_log("Pohoda_Product_Service: extract_pictures - Extracted pictures data: " . print_r($pictures, true));
        return $pictures;
    }

    private function extract_categories(DOMXPath $xpath, DOMElement $item) {
        $categories = [];
        $nodes = $xpath->query('./stk:categories/stk:idCategory', $item);
        if ($nodes) {
            foreach ($nodes as $node) {
                $categories[] = (int)$node->nodeValue;
            }
        }
        return $categories;
    }

    private function extract_related_stocks(DOMXPath $xpath, DOMElement $item, $stockTypeNodeName) {
        $stocks = [];
        $nodes = $xpath->query("./stk:{$stockTypeNodeName}/stk:idStocks", $item);
        if ($nodes) {
            foreach ($nodes as $node) {
                $stocks[] = (int)$node->nodeValue;
            }
        }
        return $stocks;
    }

    private function extract_price_variants(DOMXPath $xpath, DOMElement $item) {
        $variants = [];
        $stockPriceItem = $xpath->query('./stk:stockPriceItem', $item)->item(0);
        if ($stockPriceItem) {
            $priceNodes = $xpath->query('./stk:stockPrice', $stockPriceItem);
            foreach ($priceNodes as $variantNode) {
                $id = $xpath->query('./typ:id', $variantNode)->item(0);
                $name = $xpath->query('./typ:ids', $variantNode)->item(0);
                $price = $xpath->query('./typ:price', $variantNode)->item(0);
                $variants[] = [
                    'id' => $id ? $id->nodeValue : '',
                    'name' => $name ? $name->nodeValue : '',
                    'price' => $price ? (float)$price->nodeValue : 0
                ];
            }
        }
        return $variants;
    }

    public function check_woocommerce_products_status($products) {
        if (!is_array($products) || empty($products) || !function_exists('wc_get_product')) return $products;

        $codes = array_column(array_filter($products, function($p){ return !empty($p['code']); }), 'code');
        if (empty($codes)) return $products;

        $codes_placeholders = implode(',', array_fill(0, count($codes), '%s'));
        $prepared_query = $this->wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS sku
            FROM {$this->wpdb->posts} p
            JOIN {$this->wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku' AND pm.meta_value IN ($codes_placeholders)
            AND p.post_type IN ('product', 'product_variation') AND p.post_status = 'publish'",
            $codes
        );
        $wc_products_db = $this->wpdb->get_results($prepared_query, ARRAY_A);
        $sku_to_wc_id = array_column($wc_products_db, 'ID', 'sku');

        foreach ($products as &$product) {
            if (!empty($product['code']) && isset($sku_to_wc_id[$product['code']])) {
                $wc_id = $sku_to_wc_id[$product['code']];
                $product['woocommerce_exists'] = true;
                $product['woocommerce_id'] = $wc_id;
                $product['woocommerce_url'] = get_edit_post_link($wc_id, '');
                $wc_product_obj = wc_get_product($wc_id);
                if ($wc_product_obj) {
                    $wc_stock = $wc_product_obj->get_stock_quantity();
                    $product['woocommerce_stock'] = $wc_stock !== null ? $wc_stock : '';
                    $wc_price = $wc_product_obj->get_regular_price();
                    $product['woocommerce_price'] = $wc_price;

                    $stock_match = abs(($wc_stock !== null ? (float)$wc_stock : 0) - (float)$product['count']) <= 0.001;
                    $price_match = abs(($wc_price !== '' ? (float)$wc_price : 0) - (float)$product['selling_price']) <= 0.01;

                    $product['comparison_status'] = ($stock_match && $price_match) ? 'match' : 'mismatch';
                    if (!$stock_match || !$price_match) {
                        $product['mismatches'] = [];
                        if (!$stock_match) $product['mismatches'][] = ['field' => 'stock', 'pohoda' => $product['count'], 'woocommerce' => $wc_stock];
                        if (!$price_match) $product['mismatches'][] = ['field' => 'price', 'pohoda' => $product['selling_price'], 'woocommerce' => $wc_price];
                    }
                } else {
                    $product['comparison_status'] = 'unknown'; // WC product couldn't be loaded
                    $product['woocommerce_stock'] = ''; $product['woocommerce_price'] = '';
                }
            } else {
                $product['woocommerce_exists'] = false; $product['woocommerce_id'] = 0;
                $product['woocommerce_url'] = ''; $product['woocommerce_stock'] = '';
                $product['woocommerce_price'] = ''; $product['comparison_status'] = 'missing';
            }
        }
        return $products;
    }

    public function sync_products_to_db($batch_size = 100, $start_id = 0) {
        pohoda_debug_log("Pohoda_Product_Service: sync_products_to_db called. Batch: {$batch_size}, Start ID: {$start_id}");
        $this->db_manager->create_products_table();
        $this->db_manager->create_images_table(); // Ensure images table is also ready
        $table_name = $this->wpdb->prefix . 'pohoda_products';

        $results = ['total_fetched' => 0, 'total_inserted' => 0, 'total_updated' => 0, 'last_id' => $start_id, 'has_more' => true, 'errors' => [], 'images_synced_total' => 0];

        try {
            $api_params = ['per_page' => $batch_size, 'page' => 1, 'id_from' => $start_id, 'check_woocommerce' => true];
            $response = $this->get_products_from_api($api_params);
            pohoda_debug_log("Pohoda_Product_Service: Response from get_products_from_api in sync_products_to_db: " . print_r($response, true));

            if (!$response || !isset($response['success']) || !$response['success']) {
                $results['errors'][] = 'Failed to get products from Pohoda API. Message: ' . ($response['data'] ?? 'Unknown API error');
                pohoda_debug_log("Pohoda_Product_Service: Failed to get products from API in sync_products_to_db. Error: " . ($response['data'] ?? 'Unknown API error'));
                $results['has_more'] = false;
                return $results;
            }

            $products = $response['data'];
            $results['total_fetched'] = count($products);
            if (empty($products)) {
                $results['has_more'] = false;
                return $results;
            }

            if (isset($response['pagination'])) {
                $results['last_id'] = $response['pagination']['last_id'] ?? $start_id;
                $results['has_more'] = $response['pagination']['has_more'] ?? false;
            }

            foreach ($products as $product) {
                pohoda_debug_log("Pohoda_Product_Service: Processing product for DB sync. API ID: {$product['id']}, Code: {$product['code']}");
                $db_data = [
                    'id' => $product['id'], 'code' => $product['code'], 'name' => $product['name'],
                    'unit' => $product['unit'], 'type' => $product['type'], 'storage' => $product['storage'],
                    'count' => $product['count'], 'purchasing_price' => $product['purchasing_price'],
                    'selling_price' => $product['selling_price'], 'vat_rate' => $product['vat_rate'],
                    'related_files' => !empty($product['related_files']) ? json_encode($product['related_files']) : null,
                    'pictures' => !empty($product['pictures']) ? json_encode($product['pictures']) : null,
                    'categories' => !empty($product['categories']) ? json_encode($product['categories']) : null,
                    'related_stocks' => !empty($product['related_stocks']) ? json_encode($product['related_stocks']) : null,
                    'alternative_stocks' => !empty($product['alternative_stocks']) ? json_encode($product['alternative_stocks']) : null,
                    'price_variants' => !empty($product['price_variants']) ? json_encode($product['price_variants']) : null,
                    'woocommerce_exists' => isset($product['woocommerce_exists']) ? $product['woocommerce_exists'] : 0,
                    'woocommerce_id' => isset($product['woocommerce_id']) ? $product['woocommerce_id'] : 0,
                    'woocommerce_url' => isset($product['woocommerce_url']) ? $product['woocommerce_url'] : '',
                    'woocommerce_stock' => isset($product['woocommerce_stock']) ? strval($product['woocommerce_stock']) : '',
                    'woocommerce_price' => isset($product['woocommerce_price']) ? strval($product['woocommerce_price']) : '',
                    'comparison_status' => isset($product['comparison_status']) ? $product['comparison_status'] : 'missing',
                    'last_updated' => current_time('mysql')
                ];
                $format = ['%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s'];

                // Try to find existing product by Pohoda ID first
                $existing_by_id = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $product['id']));

                if ($existing_by_id) {
                    pohoda_debug_log("Pohoda_Product_Service: Product found by API ID {$product['id']}. Updating.");
                    if($this->wpdb->update($table_name, $db_data, ['id' => $product['id']], $format, ['%d']) !== false) {
                        $results['total_updated']++;
                    } else {
                        $results['errors'][] = "Failed to update product by ID {$product['id']}: " . $this->wpdb->last_error;
                        pohoda_debug_log("Pohoda_Product_Service: DB UPDATE by ID FAILED for API ID {$product['id']}. Error: " . $this->wpdb->last_error);
                    }
                } else {
                    // If not found by ID, check if a product with the same CODE exists
                    pohoda_debug_log("Pohoda_Product_Service: Product with API ID {$product['id']} not found. Checking by code: {$product['code']}.");
                    $existing_by_code = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM $table_name WHERE code = %s", $product['code']));
                    
                    if ($existing_by_code) {
                        // Product with this code exists, but with a different Pohoda ID. Update this record.
                        // This assumes the API data (including the new Pohoda ID) is the source of truth for this code.
                        pohoda_debug_log("Pohoda_Product_Service: Product found by CODE {$product['code']} (API ID {$product['id']}, DB ID {$existing_by_code->id}). Updating this record with new API ID and data.");
                        // We need to update the where clause to target the existing row by its current ID or code.
                        // And the $db_data already contains the new $product['id'] from the API.
                        if($this->wpdb->update($table_name, $db_data, ['id' => $existing_by_code->id], $format, ['%d']) !== false) {
                            $results['total_updated']++;
                        } else {
                            $results['errors'][] = "Failed to update product by CODE {$product['code']} (old ID {$existing_by_code->id} to new ID {$product['id']}): " . $this->wpdb->last_error;
                            pohoda_debug_log("Pohoda_Product_Service: DB UPDATE by CODE FAILED for code {$product['code']}. Error: " . $this->wpdb->last_error);
                        }
                    } else {
                        // Product not found by ID or Code, so it's a new insert
                        pohoda_debug_log("Pohoda_Product_Service: Product not found by ID or code. Inserting new product API ID {$product['id']}, Code {$product['code']}.");
                        if($this->wpdb->insert($table_name, $db_data, $format) !== false) {
                            $results['total_inserted']++;
                        } else {
                            $results['errors'][] = "Failed to insert product ID {$product['id']} (Code {$product['code']}): " . $this->wpdb->last_error;
                            pohoda_debug_log("Pohoda_Product_Service: DB INSERT FAILED for API ID {$product['id']}. Error: " . $this->wpdb->last_error);
                        }
                    }
                }

                if (!empty($product['pictures'])) {
                    pohoda_debug_log("Pohoda_Product_Service: Syncing images for product ID {$product['id']}. Pictures data: " . print_r($product['pictures'], true));
                    $image_sync_result = $this->image_service->sync_product_images($product['id'], $product['pictures']);
                    pohoda_debug_log("Pohoda_Product_Service: Image sync result for product ID {$product['id']}: " . print_r($image_sync_result, true));
                    if ($image_sync_result['success']) {
                        $results['images_synced_total'] += $image_sync_result['synced_to_wc'];
                    }
                    if (!empty($image_sync_result['errors'])) {
                        $results['errors'] = array_merge($results['errors'], array_map(function($err) use ($product) { return "Img sync err for product {$product['id']}: {$err}"; }, $image_sync_result['errors']));
                    }
                }
            }
        } catch (Exception $e) {
            $results['errors'][] = 'Exception during product sync: ' . $e->getMessage();
            $results['has_more'] = false; // Stop if general exception occurs
            pohoda_debug_log("Pohoda_Product_Service: Exception in sync_products_to_db: " . $e->getMessage());
        }
        pohoda_debug_log("Pohoda_Product_Service: sync_products_to_db finished. Results: " . print_r($results, true));
        return $results;
    }

    public function get_products_from_db($params = []) {
        $this->db_manager->create_products_table(); // Ensure table exists
        $table_name = $this->wpdb->prefix . 'pohoda_products';
        $defaults = ['search' => '', 'type' => '', 'storage' => '', 'per_page' => 10, 'page' => 1, 'order_by' => 'id', 'order' => 'ASC', 'comparison_status' => ''];
        $params = wp_parse_args($params, $defaults);

        $where = []; $where_values = [];
        if (!empty($params['search'])) {
            $where[] = '(code LIKE %s OR name LIKE %s)';
            $search_term = '%' . $this->wpdb->esc_like($params['search']) . '%';
            $where_values[] = $search_term; $where_values[] = $search_term;
        }
        if (!empty($params['type'])) { $where[] = 'type = %s'; $where_values[] = $params['type']; }
        if (!empty($params['storage'])) { $where[] = 'storage = %s'; $where_values[] = $params['storage']; }
        if (!empty($params['comparison_status'])) { $where[] = 'comparison_status = %s'; $where_values[] = $params['comparison_status']; }
        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $allowed_columns = ['id', 'code', 'name', 'count', 'selling_price', 'comparison_status', 'last_updated'];
        $order_by = in_array($params['order_by'], $allowed_columns) ? $params['order_by'] : 'id';
        $order = strtoupper($params['order']) === 'DESC' ? 'DESC' : 'ASC';
        $offset = ($params['page'] - 1) * $params['per_page'];

        $query_sql = "SELECT * FROM $table_name $where_clause ORDER BY {$order_by} $order LIMIT %d OFFSET %d";
        $query = $this->wpdb->prepare($query_sql, array_merge($where_values, [$params['per_page'], $offset]));

        $count_query_sql = "SELECT COUNT(*) FROM $table_name $where_clause";
        $total_items = $this->wpdb->get_var(!empty($where_values) ? $this->wpdb->prepare($count_query_sql, $where_values) : $count_query_sql);
        $products_db = $this->wpdb->get_results($query, ARRAY_A);

        $processed_products = [];
        foreach ($products_db as $product) {
            $json_fields = ['related_files', 'pictures', 'categories', 'related_stocks', 'alternative_stocks', 'price_variants'];
            foreach ($json_fields as $field) {
                $product[$field] = !empty($product[$field]) ? json_decode($product[$field], true) : [];
            }
            $numeric_fields = ['count', 'purchasing_price', 'selling_price'];
            foreach($numeric_fields as $nf) $product[$nf] = (float) $product[$nf];
            $product['woocommerce_exists'] = (bool) $product['woocommerce_exists'];
            $product['woocommerce_id'] = (int) $product['woocommerce_id'];
            $processed_products[] = $product;
        }

        $total_pages = $params['per_page'] > 0 ? ceil($total_items / $params['per_page']) : 0;
        return [
            'success' => true, 'data' => $processed_products,
            'pagination' => [
                'total' => (int)$total_items, 'per_page' => (int)$params['per_page'],
                'current_page' => (int)$params['page'], 'last_page' => $total_pages,
                'from' => $offset + 1, 'to' => min($offset + $params['per_page'], $total_items),
                'has_more' => (int)$params['page'] < $total_pages
            ]
        ];
    }

    public function refresh_woocommerce_data_for_all_products() {
        $table_name = $this->wpdb->prefix . 'pohoda_products';
        $products_in_db = $this->wpdb->get_results("SELECT id, code, count, selling_price FROM $table_name", ARRAY_A);
        if (empty($products_in_db) || !function_exists('wc_get_product')) {
            return ['success' => false, 'message' => 'No products in DB or WooCommerce not active', 'updated' => 0];
        }

        $codes = array_column($products_in_db, 'code');
        if(empty($codes)) return ['success' => true, 'message' => 'No product codes to process.', 'updated' => 0];
        
        $codes_placeholders = implode(',', array_fill(0, count($codes), '%s'));
        $query = $this->wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS sku
            FROM {$this->wpdb->posts} p JOIN {$this->wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku' AND pm.meta_value IN ($codes_placeholders)
            AND p.post_type IN ('product', 'product_variation') AND p.post_status = 'publish'",
            $codes
        );
        $wc_products_map = $this->wpdb->get_results($query, ARRAY_A);
        $sku_to_wc_id = array_column($wc_products_map, 'ID', 'sku');
        $total_updated = 0;

        foreach ($products_in_db as $p_db) {
            $wc_exists = isset($sku_to_wc_id[$p_db['code']]);
            $update_data = ['woocommerce_exists' => $wc_exists ? 1 : 0, 'last_updated' => current_time('mysql')];

            if ($wc_exists) {
                $wc_id = $sku_to_wc_id[$p_db['code']];
                $wc_product = wc_get_product($wc_id);
                if ($wc_product) {
                    $update_data['woocommerce_id'] = $wc_id;
                    $update_data['woocommerce_url'] = get_edit_post_link($wc_id, '');
                    $wc_stock = $wc_product->get_stock_quantity();
                    $update_data['woocommerce_stock'] = $wc_stock !== null ? (string)$wc_stock : '';
                    $wc_price = $wc_product->get_regular_price();
                    $update_data['woocommerce_price'] = $wc_price !== '' ? (string)$wc_price : '';

                    $stock_match = abs(($wc_stock !== null ? (float)$wc_stock : 0) - (float)$p_db['count']) <= 0.001;
                    $price_match = abs(($wc_price !== '' ? (float)$wc_price : 0) - (float)$p_db['selling_price']) <= 0.01;
                    $update_data['comparison_status'] = ($stock_match && $price_match) ? 'match' : 'mismatch';
                } else {
                    $update_data['comparison_status'] = 'unknown'; // WC product not loadable
                }
            } else {
                $update_data['woocommerce_id'] = 0; $update_data['woocommerce_url'] = '';
                $update_data['woocommerce_stock'] = ''; $update_data['woocommerce_price'] = '';
                $update_data['comparison_status'] = 'missing';
            }
            if($this->wpdb->update($table_name, $update_data, ['id' => $p_db['id']]) !== false) $total_updated++;
        }
        return ['success' => true, 'updated' => $total_updated];
    }

    public function update_local_product_wc_status_after_check($pohoda_db_row_pk_id, $wc_product_id_if_exists) {
        $table_name = $this->wpdb->prefix . 'pohoda_products';
        pohoda_debug_log("Pohoda_Product_Service: update_local_product_wc_status_after_check called for local DB PK ID: {$pohoda_db_row_pk_id}, WC Product ID (if exists): " . ($wc_product_id_if_exists ?? 'null'));

        $product_in_db = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $pohoda_db_row_pk_id), ARRAY_A);

        if (!$product_in_db) {
            pohoda_debug_log("Pohoda_Product_Service: update_local_product_wc_status_after_check - Product not found in local DB by PK ID: {$pohoda_db_row_pk_id}");
            return ['success' => false, 'message' => "Product with local DB ID {$pohoda_db_row_pk_id} not found."];
        }

        $update_data = ['last_updated' => current_time('mysql')];
        $wc_product_loaded = false;

        if ($wc_product_id_if_exists && function_exists('wc_get_product')) {
            $wc_product = wc_get_product($wc_product_id_if_exists);
            if ($wc_product) {
                $wc_product_loaded = true;
                $update_data['woocommerce_exists'] = 1;
                $update_data['woocommerce_id'] = $wc_product_id_if_exists;
                $update_data['woocommerce_url'] = get_edit_post_link($wc_product_id_if_exists, '');
                $wc_stock = $wc_product->get_stock_quantity();
                $update_data['woocommerce_stock'] = $wc_stock !== null ? (string)$wc_stock : '';
                
                // Use regular_price for comparison, assuming Pohoda selling_price is also without VAT.
                // Ensure this matches your pricing setup.
                $wc_price = $wc_product->get_regular_price(); 
                $update_data['woocommerce_price'] = $wc_price !== '' ? (string)$wc_price : '';

                // Perform comparison for 'comparison_status'
                $stock_match = abs(($wc_stock !== null ? (float)$wc_stock : 0) - (float)$product_in_db['count']) <= 0.001;
                
                // Compare Pohoda selling_price (typically without VAT) with WC regular_price (typically without VAT)
                $price_match = abs(($wc_price !== '' ? (float)$wc_price : 0) - (float)$product_in_db['selling_price']) <= 0.01;

                $update_data['comparison_status'] = ($stock_match && $price_match) ? 'match' : 'mismatch';
                pohoda_debug_log("Pohoda_Product_Service: Updating local product PK ID {$pohoda_db_row_pk_id}. WC ID: {$wc_product_id_if_exists}. Stock match: {$stock_match}, Price match: {$price_match}. Status: {$update_data['comparison_status']}");
            } else {
                // WC product ID provided, but product couldn't be loaded (e.g., deleted after ID was fetched)
                $update_data['woocommerce_exists'] = 1; // Still mark as existing because an ID was provided
                $update_data['woocommerce_id'] = $wc_product_id_if_exists;
                $update_data['comparison_status'] = 'unknown'; // Cannot compare details
                pohoda_debug_log("Pohoda_Product_Service: Updating local product PK ID {$pohoda_db_row_pk_id}. WC ID: {$wc_product_id_if_exists} provided, but wc_get_product failed. Status: unknown.");
            }
        } else {
            // No WC product ID provided, or wc_get_product doesn't exist; mark as missing in WooCommerce
            $update_data['woocommerce_exists'] = 0;
            $update_data['woocommerce_id'] = 0;
            $update_data['woocommerce_url'] = '';
            $update_data['woocommerce_stock'] = '';
            $update_data['woocommerce_price'] = '';
            $update_data['comparison_status'] = 'missing';
            if ($wc_product_id_if_exists && !function_exists('wc_get_product')) {
                 pohoda_debug_log("Pohoda_Product_Service: Updating local product PK ID {$pohoda_db_row_pk_id}. WC ID {$wc_product_id_if_exists} provided, but wc_get_product function missing. Status: missing.");
            } else {
                 pohoda_debug_log("Pohoda_Product_Service: Updating local product PK ID {$pohoda_db_row_pk_id}. No WC ID provided. Status: missing.");
            }
        }

        if (empty($update_data)) {
            pohoda_debug_log("Pohoda_Product_Service: No data to update for local product PK ID {$pohoda_db_row_pk_id}.");
            return ['success' => true, 'message' => 'No data to update.', 'updated' => false, 'status' => $product_in_db['comparison_status'] ?? 'unknown'];
        }
        
        $updated_rows = $this->wpdb->update($table_name, $update_data, ['id' => $pohoda_db_row_pk_id]);

        if ($updated_rows === false) {
            pohoda_debug_log("Pohoda_Product_Service: Failed to update local product PK ID {$pohoda_db_row_pk_id} WC status. DB Error: " . $this->wpdb->last_error);
            return ['success' => false, 'message' => 'DB Error: ' . $this->wpdb->last_error, 'updated' => false];
        }
        
        pohoda_debug_log("Pohoda_Product_Service: Successfully updated local product PK ID {$pohoda_db_row_pk_id}. Rows affected: {$updated_rows}. New status: {$update_data['comparison_status']}");
        return ['success' => true, 'updated' => $updated_rows > 0, 'new_status' => $update_data['comparison_status']];
    }
} 