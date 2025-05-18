<?php
if (!defined('ABSPATH')) {
    exit;
}

class Pohoda_API_Client {
    private $options;
    private $credentials;
    private $last_curl;

    public function __construct($options) {
        $this->options = $options;
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

    public function validate_connection() {
        if (empty($this->options['ip_address']) || empty($this->options['port']) || empty($this->options['login']) || empty($this->options['password']) || empty($this->options['ico'])) {
            return false;
        }
        return true;
    }

    /**
     * Format XML string to be more readable
     *
     * @param string $xml The XML string to format
     * @return string Formatted XML
     */
    public function format_xml($xml) {
        if (empty($xml)) {
            return $xml;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml, LIBXML_NOWARNING | LIBXML_NOERROR);

        if ($dom->documentElement !== null) {
            return $dom->saveXML();
        }

        return $xml;
    }

    public function make_request($endpoint, $method = 'GET', $data = null) {
        if (!$this->validate_connection()) {
            // Consider throwing an exception or returning a WP_Error object
            error_log("Pohoda API Client Error: Connection not validated for endpoint {$endpoint}");
            return false;
        }

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
            CURLOPT_VERBOSE => true // Consider making this conditional (e.g., if WP_DEBUG)
        ]);

        if ($data) {
            // Convert data to Windows-1250 if needed
            if (mb_detect_encoding($data, 'UTF-8', true)) {
                $converted_data = iconv('UTF-8', 'CP1250//TRANSLIT', $data);
                if ($converted_data === false) {
                    error_log("Pohoda API Client Error: Failed to convert data to Windows-1250 for endpoint {$endpoint}");
                    curl_close($curl);
                    return false; // Or handle error appropriately
                }
                $data = $converted_data;
            }
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            // Add Content-Length for POST/PUT requests with body
            if ($method === 'POST' || $method === 'PUT') {
                 $headers = curl_getinfo($curl, CURLINFO_HEADER_OUT);
                 // This is tricky as CURLOPT_HTTPHEADER is set before.
                 // It might be better to rebuild headers or ensure Content-Length is managed correctly.
                 // For now, we rely on cURL to set it if not explicitly provided for POST.
            }
        }
        
        $this->last_curl = $curl; // Store curl handle before exec, if needed for debugging post-execution
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($err) {
            error_log("Pohoda API Client cURL Error: " . $err . " (HTTP Code: " . $httpCode . ") for URL: http://{$this->options['ip_address']}:{$this->options['port']}{$endpoint}");
            curl_close($curl);
            return false;
        }
        
        if ($httpCode >= 400) {
            error_log("Pohoda API Client HTTP Error: Code " . $httpCode . " for URL: http://{$this->options['ip_address']}:{$this->options['port']}{$endpoint}. Response: " . $response);
            // Optionally, return the response here if it contains useful error details from Pohoda
            // For now, matching existing behavior of returning false.
            curl_close($curl);
            return false;
        }

        curl_close($curl);

        // Format XML for better readability if it's valid XML
        // Ensure this check is robust, as not all Pohoda responses are XML (e.g. images)
        if (!empty($response) && strpos($response, '<?xml') !== false && (strpos($endpoint, '/xml') !== false || strpos($endpoint, '/status') !== false) ) {
            return $this->format_xml($response);
        }

        return $response;
    }

    public function send_xml($xml) {
        if (!$this->validate_connection()) {
            error_log("Pohoda API Client Error: Connection not validated for send_xml");
            return false;
        }

        // Convert XML to Windows-1250 if needed
        if (mb_detect_encoding($xml, 'UTF-8', true)) {
            $converted_xml = iconv('UTF-8', 'CP1250//TRANSLIT', $xml);
            if ($converted_xml === false) {
                error_log("Pohoda API Client Error: Failed to convert XML to Windows-1250 encoding for send_xml");
                return false;
            }
            $xml = $converted_xml;
        }

        $this->last_curl = curl_init(); // For get_last_curl() compatibility
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
                "Content-Type: application/xml; charset=Windows-1250", // Explicitly Windows-1250
                "Content-Length: " . strlen($xml)
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_VERBOSE => true // Consider making this conditional
        ]);

        $response = curl_exec($this->last_curl);
        $err = curl_error($this->last_curl);
        $httpCode = curl_getinfo($this->last_curl, CURLINFO_HTTP_CODE);

        if ($err) {
            error_log("Pohoda API Client cURL Error (send_xml): " . $err . " (HTTP Code: " . $httpCode . ")");
            curl_close($this->last_curl);
            return false;
        }

        if ($httpCode >= 400) {
            error_log("Pohoda API Client HTTP Error (send_xml): Code " . $httpCode . ". Response: " . $response);
            curl_close($this->last_curl);
            return false;
        }

        curl_close($this->last_curl); // Ensure curl is closed

        // Log the request and response for debugging (conditional)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Pohoda API Client Request (send_xml): " . $xml);
            error_log("Pohoda API Client Response (send_xml): " . $response);
        }

        if (!empty($response) && strpos($response, '<?xml') !== false) {
            return $this->format_xml($response);
        }

        return $response;
    }

    public function get_options() {
        return $this->options;
    }

    public function get_credentials_basic_auth_string() {
        return $this->credentials; // This is already the base64 encoded string
    }
} 