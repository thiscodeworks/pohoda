<?php
if (!defined('ABSPATH')) {
    exit;
}

class Pohoda_Order_Service {
    private $api_client;

    public function __construct(Pohoda_API_Client $api_client) {
        $this->api_client = $api_client;
    }

    public function get_orders_from_api() { // Renamed for clarity
        if (!$this->api_client->validate_connection()) {
            return ['success' => false, 'data' => 'Invalid API connection settings', 'raw' => ''];
        }

        // XML Request construction from the original class
        $xmlRequest = <<<XML
<?xml version="1.0" encoding="Windows-1250"?>
<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" xmlns:ftr="http://www.stormware.cz/schema/version_2/filter.xsd" xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd" xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" id="001" ico="{$this->api_client->get_ico()}" application="StwTest" version="2.0" note="Požadavek na export výběru objednávek">
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
        // The original code had an iconv here for $xmlRequest, but it was already Windows-1250.
        // The api_client->make_request and api_client->send_xml handle encoding conversions if source is UTF-8.
        // So, this direct iconv might be redundant if the string is truly already Win-1250.
        // For safety, if $xmlRequest was built with UTF-8 components, it should be converted.
        // However, the heredoc itself should be Windows-1250 if the file is saved as such, or if PHP's internal encoding is set.
        // Assuming send_xml in api_client handles any necessary final conversion.

        $response = $this->api_client->send_xml($xmlRequest); // Using send_xml as it's for posting XML.
                                                           // Or make_request if that's preferred for this endpoint.
                                                           // Original used make_request('/xml', 'POST', $xmlRequest)
                                                           // Let's stick to make_request for consistency with original method.
        
        // Re-checking original: it used make_request for /xml POST.
        // $xmlRequest here is already Windows-1250 encoded string. make_request will handle the POST.
        // The iconv in the original get_orders was likely a safeguard.
        // The make_request method in Pohoda_API_Client already handles UTF-8 to CP1250 for $data.
        // Since $xmlRequest is already defined as Windows-1250, no conversion should be needed here before passing to make_request.
        
        $response = $this->api_client->make_request('/xml', 'POST', $xmlRequest);

        if ($response === false) {
            return ['success' => false, 'data' => 'No response from server or API client error', 'raw' => ''];
        }
        
        // The original class returned the raw response directly. We will too.
        // XML formatting is handled by make_request if the response is XML.
        return ['success' => true, 'data' => $response, 'raw' => $response]; // Raw is same as data here.
    }
} 