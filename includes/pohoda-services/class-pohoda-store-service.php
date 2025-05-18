<?php
if (!defined('ABSPATH')) {
    exit;
}

class Pohoda_Store_Service {
    private $api_client;

    public function __construct(Pohoda_API_Client $api_client) {
        $this->api_client = $api_client;
    }

    public function get_stores_from_api() { // Renamed for clarity
        if (!$this->api_client->validate_connection()) {
            // Original returned false, let's return a structured error
            return ['success' => false, 'data' => 'Invalid API connection settings', 'raw' => ''];
        }

        $xmlRequest = <<<XML
<?xml version="1.0" encoding="Windows-1250"?>
<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" xmlns:sto="http://www.stormware.cz/schema/version_2/store.xsd" xmlns:ftr="http://www.stormware.cz/schema/version_2/filter.xsd" xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd" xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" id="Za001" ico="{$this->api_client->get_ico()}" application="StwTest" version="2.0" note="Export skladů">
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
        // As with orders, assuming api_client->make_request handles encoding if necessary.
        // The heredoc itself is likely Windows-1250 based on the declaration.

        $response = $this->api_client->make_request('/xml', 'POST', $xmlRequest);
        $responseXml = $response; // Keep for raw output

        if ($response === false) {
            error_log('Pohoda Store Service: No response from API client for get_stores_from_api.');
            // Log more details if available from api_client, e.g., last curl error
            return [
                'success' => false,
                'data' => 'No response from server. Check API client logs.',
                'raw' => '' // Or perhaps $this->api_client->get_last_error_details();
            ];
        }
        if (empty(trim($response))){
             error_log('Pohoda Store Service: Empty response from API client for get_stores_from_api.');
             return ['success' => false, 'data' => 'Empty response from server.', 'raw' => $responseXml];
        }

        // Parse XML using SimpleXML as in the original
        // Suppress errors for load_string and check manually
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response);
        if ($xml === false) {
            $xml_errors = libxml_get_errors();
            $error_messages = array_map(function($error) { return trim($error->message); }, $xml_errors);
            error_log('Pohoda Store Service: Failed to parse XML response for get_stores_from_api. Errors: ' . implode("; ", $error_messages) . ' Response: ' . $response);
            libxml_clear_errors();
            return [
                'success' => false,
                'data' => 'Failed to parse XML response: ' . implode("; ", $error_messages),
                'raw' => $responseXml
            ];
        }
        libxml_clear_errors();

        $xml->registerXPathNamespace('rsp', 'http://www.stormware.cz/schema/version_2/response.xsd');
        $xml->registerXPathNamespace('lst', 'http://www.stormware.cz/schema/version_2/list.xsd');
        $xml->registerXPathNamespace('sto', 'http://www.stormware.cz/schema/version_2/store.xsd');
        $xml->registerXPathNamespace('typ', 'http://www.stormware.cz/schema/version_2/type.xsd');

        // Check for error response from Pohoda
        $responseState = $xml->xpath('//rsp:responsePackItem[@state="error"] | //responseState[@state="error"]');
        if (!empty($responseState)) {
            $errorMessage = $xml->xpath('//rsp:responsePackItem[@state="error"]/rsp:message | //responseState[@state="error"]/message');
            $pohodaError = !empty($errorMessage) ? (string)$errorMessage[0] : 'Unknown error from Pohoda.';
            error_log('Pohoda Store Service: Pohoda API returned an error for get_stores_from_api: ' . $pohodaError);
            return [
                'success' => false,
                'data' => 'Pohoda API error: ' . $pohodaError,
                'raw' => $responseXml
            ];
        }

        $listStoreNodes = $xml->xpath('//lst:listStore');
        if (empty($listStoreNodes)) {
            error_log('Pohoda Store Service: No listStore element found in response for get_stores_from_api. Response: ' . $response);
            return [
                'success' => false,
                'data' => 'No listStore element found in response',
                'raw' => $responseXml
            ];
        }
        $listStore = $listStoreNodes[0];

        $stores = [];
        $storeItems = $listStore->xpath('.//lst:store');

        if (empty($storeItems)) {
             // This might not be an error, could be simply no stores defined.
             // Original code treated this as an error, let's return success with empty data for consistency of API method calls returning data.
            return [
                'success' => true,
                'data' => [],
                'message' => 'No stores found in response.', // Informative message
                'raw' => $responseXml
            ];
        }

        foreach ($storeItems as $item) {
            $store = [
                'id' => (string)$item->xpath('.//sto:id')[0],
                'name' => (string)$item->xpath('.//sto:name')[0],
                'text' => (string)$item->xpath('.//sto:text')[0],
                // PLU details might not always exist, check first
                'usePLU' => false, 'lowerLimit' => 0, 'upperLimit' => 0
            ];
            $pluNode = $item->xpath('.//sto:PLU');
            if(!empty($pluNode)){
                $usePLUNode = $pluNode[0]->xpath('.//sto:usePLU');
                if(!empty($usePLUNode)) $store['usePLU'] = (string)$usePLUNode[0] === 'true';
                
                $lowerLimitNode = $pluNode[0]->xpath('.//sto:lowerLimit');
                if(!empty($lowerLimitNode)) $store['lowerLimit'] = (int)$lowerLimitNode[0];

                $upperLimitNode = $pluNode[0]->xpath('.//sto:upperLimit');
                if(!empty($upperLimitNode)) $store['upperLimit'] = (int)$upperLimitNode[0];
            }

            $storekeeper = $item->xpath('.//sto:storekeeper/typ:ids');
            if (!empty($storekeeper)) {
                $store['storekeeper'] = (string)$storekeeper[0];
            }
            $stores[] = $store;
        }

        return [
            'success' => true,
            'data' => $stores,
            'raw' => $responseXml // Include raw XML for debugging if needed
        ];
    }
} 