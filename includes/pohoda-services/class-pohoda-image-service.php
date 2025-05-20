<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . '../pohoda-logger.php'; // Include the custom logger

class Pohoda_Image_Service {
    private $wpdb;
    private $api_client;
    private $db_manager; // For ensuring tables exist

    public function __construct(wpdb $wpdb, Pohoda_API_Client $api_client, Pohoda_DB_Manager $db_manager) {
        $this->wpdb = $wpdb;
        $this->api_client = $api_client;
        $this->db_manager = $db_manager;
    }

    /**
     * Get product image from Pohoda API
     *
     * @param string $filename The image filename
     * @return array The image data or error message
     */
    public function get_product_image_from_api($filename) {
        if (!$this->api_client->validate_connection()) {
            return [
                'success' => false,
                'message' => 'Invalid API connection settings'
            ];
        }

        // Construct the URL for fetching images, assuming options are in api_client
        // This might need adjustment based on how options are accessed.
        // For now, assuming api_client has a method to get options or direct access.
        // This is a placeholder, actual implementation will depend on Pohoda_API_Client structure.
        $options = $this->api_client->get_options(); // Assuming such a method exists or direct access.
                                                 // If not, $options need to be passed or fetched differently.
        if (empty($options['ip_address']) || empty($options['port'])) {
             return ['success' => false, 'message' => 'API IP address or port not configured.'];
        }

        // Ensure $filename is URL encoded to handle special characters like spaces, diacritics, etc.
        // However, path segments should NOT be fully encoded. We only want to encode the filename part.
        // If $filename can contain directory paths from Pohoda, this needs careful handling.
        // Assuming $filename is just the file name for now.
        $encoded_filename = rawurlencode($filename); // Use rawurlencode for path components

        $url = "http://{$options['ip_address']}:{$options['port']}/documents/Obrázky/{$encoded_filename}";
        
        // Log the constructed URL for debugging
        pohoda_debug_log("Pohoda_Image_Service: Attempting to fetch image from URL: " . $url . " (Original filename: " . $filename . ")");

        $credentials = $this->api_client->get_credentials_basic_auth_string(); // Corrected method name

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "STW-Authorization: Basic {$credentials}",
                "STW-Application: eShop",
                "STW-Instance: imageDisplay"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            return [
                'success' => true,
                'data' => $response,
                'content_type' => $contentType ?: 'image/jpeg'
            ];
        } else {
            return [
                'success' => false,
                'message' => $error ?: "Failed to load image (HTTP $httpCode) from {$url}",
                'http_code' => $httpCode
            ];
        }
    }


    /**
     * Sync product images from Pohoda to local storage and WooCommerce
     *
     * @param int $product_id Pohoda product ID (from pohoda_products table)
     * @param array $pictures Array of picture data from Pohoda API response for this product
     * @return array Result of the sync operation
     */
    public function sync_product_images($product_id, $pictures) {
        $this->db_manager->create_images_table(); // Ensure table exists
        $table_name = $this->wpdb->prefix . 'pohoda_images';

        pohoda_debug_log("Pohoda_Image_Service: sync_product_images called. Product ID: {$product_id}. Pictures data: " . print_r($pictures, true));

        if (empty($product_id) || !is_array($pictures)) {
            pohoda_debug_log("Pohoda Image Service: sync_product_images called for product ID {$product_id} but picture data is invalid or not an array.");
            return ['success' => false, 'message' => 'Invalid product ID or picture data format', 'total' => 0, 'inserted' => 0, 'updated' => 0, 'synced_to_wc' => 0, 'errors' => ['Invalid product ID or picture data format']];
        }

        $picture_count = count($pictures);
        pohoda_debug_log("Pohoda Image Service: sync_product_images for product ID {$product_id} with {$picture_count} picture(s).");

        if (empty($pictures)) {
            return ['success' => true, 'message' => 'No pictures to process', 'total' => 0, 'inserted' => 0, 'updated' => 0, 'synced_to_wc' => 0, 'errors' => []];
        }

        $results = ['success' => true, 'total' => $picture_count, 'inserted' => 0, 'updated' => 0, 'synced_to_wc' => 0, 'errors' => []];

        $wc_product_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT woocommerce_id FROM {$this->wpdb->prefix}pohoda_products WHERE id = %d",
            $product_id
        ));

        foreach ($pictures as $picture) {
            if (!isset($picture['id']) || !isset($picture['filepath']) || empty($picture['filepath'])) {
                $error_msg = "Missing required picture data (id or filepath) for product ID {$product_id}. Data: " . json_encode($picture);
                $results['errors'][] = $error_msg;
                pohoda_debug_log("Pohoda Image Service Warning: {$error_msg}");
                continue;
            }

            $existing = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM $table_name WHERE product_id = %d AND pohoda_id = %d",
                $product_id, $picture['id']
            ));

            $image_data = [
                'product_id' => $product_id,
                'pohoda_id' => $picture['id'],
                'filepath' => $picture['filepath'],
                'description' => $picture['description'] ?? '',
                'is_default' => isset($picture['default']) && $picture['default'] ? 1 : 0,
                'order_num' => $picture['order'] ?? 0,
                'sync_status' => 'pending'
            ];
            $image_db_id = null;

            if ($existing) {
                pohoda_debug_log("Pohoda_Image_Service: Attempting to update image for product ID {$product_id}, Pohoda image ID {$picture['id']}. Data: " . print_r($image_data, true));
                if ($this->wpdb->update($table_name, $image_data, ['id' => $existing->id]) !== false) {
                    $results['updated']++;
                    $image_db_id = $existing->id;
                } else {
                    $db_error = $this->wpdb->last_error;
                    $results['errors'][] = "DB update failed for image Pohoda ID {$picture['id']}: " . $db_error;
                    pohoda_debug_log("Pohoda_Image_Service: DB update FAILED for image Pohoda ID {$picture['id']}. Error: " . $db_error . " Data: " . print_r($image_data, true));
                }
            } else {
                pohoda_debug_log("Pohoda_Image_Service: Attempting to insert image for product ID {$product_id}, Pohoda image ID {$picture['id']}. Data: " . print_r($image_data, true));
                if ($this->wpdb->insert($table_name, $image_data) !== false) {
                    $image_db_id = $this->wpdb->insert_id;
                    $results['inserted']++;
                } else {
                     $db_error = $this->wpdb->last_error;
                     $results['errors'][] = "DB insert failed for image Pohoda ID {$picture['id']}: " . $db_error;
                     pohoda_debug_log("Pohoda_Image_Service: DB insert FAILED for image Pohoda ID {$picture['id']}. Error: " . $db_error . " Data: " . print_r($image_data, true));
                }
            }

            if ($wc_product_id && $image_db_id) {
                $sync_result = $this->sync_single_image_to_woocommerce($image_db_id, $wc_product_id);
                if ($sync_result['success']) {
                    $results['synced_to_wc']++;
                } else {
                    $results['errors'][] = "WC Sync failed for DB image ID {$image_db_id}: {$sync_result['message']}";
                }
            }
        }
        if (!empty($results['errors'])) $results['success'] = false;
        pohoda_debug_log("Pohoda_Image_Service: sync_product_images finished for product ID {$product_id}. Results: " . print_r($results, true));
        return $results;
    }

    /**
     * Sync a single image (already in DB) to WooCommerce
     *
     * @param int $image_db_id ID of the image in the pohoda_images table
     * @param int $wc_product_id WooCommerce product ID
     * @return array Result of the sync operation
     */
    public function sync_single_image_to_woocommerce($image_db_id, $wc_product_id) {
        $table_name = $this->wpdb->prefix . 'pohoda_images';
        $image = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $image_db_id));

        if (!$image) return ['success' => false, 'message' => 'Image not found in DB'];

        // Fetch image from Pohoda API using the corrected method name
        $image_response = $this->get_product_image_from_api($image->filepath);
        if (!$image_response['success']) {
            $this->wpdb->update($table_name, ['sync_status' => 'error', 'last_synced' => current_time('mysql')], ['id' => $image_db_id]);
            return ['success' => false, 'message' => "API fetch error: " . ($image_response['message'] ?? 'Unknown API error')];
        }

        $upload_dir = wp_upload_dir();
        $product_image_dir = $upload_dir['basedir'] . '/pohoda-images';
        if (!file_exists($product_image_dir)) wp_mkdir_p($product_image_dir);

        $extension = 'jpg';
        if (!empty($image_response['content_type'])) {
            if (strpos($image_response['content_type'], 'png') !== false) $extension = 'png';
            elseif (strpos($image_response['content_type'], 'gif') !== false) $extension = 'gif';
            elseif (strpos($image_response['content_type'], 'webp') !== false) $extension = 'webp';
        }
        $filename = "pohoda-{$image->product_id}-{$image->pohoda_id}.{$extension}";
        $file_path = $product_image_dir . '/' . $filename;

        if (file_put_contents($file_path, $image_response['data']) === false) {
            $this->wpdb->update($table_name, ['sync_status' => 'error', 'last_synced' => current_time('mysql')], ['id' => $image_db_id]);
            return ['success' => false, 'message' => 'Failed to save image file locally.'];
        }

        $file_data = ['name' => $filename, 'type' => $image_response['content_type'], 'tmp_name' => $file_path, 'error' => 0, 'size' => filesize($file_path)];
        $attachment_id = media_handle_sideload($file_data, $wc_product_id, $image->description);

        if (is_wp_error($attachment_id)) {
            $this->wpdb->update($table_name, ['sync_status' => 'error', 'last_synced' => current_time('mysql')], ['id' => $image_db_id]);
            // Optionally remove $file_path if media_handle_sideload fails and doesn't clean up
            // if (file_exists($file_path)) unlink($file_path);
            return ['success' => false, 'message' => $attachment_id->get_error_message()];
        }
        
        // if (file_exists($file_path)) unlink($file_path); // Clean up temp file after successful sideload

        if ($image->is_default) {
            set_post_thumbnail($wc_product_id, $attachment_id);
        } else {
            $product = wc_get_product($wc_product_id);
            if ($product) {
                $gallery_image_ids = $product->get_gallery_image_ids();
                if (!in_array($attachment_id, $gallery_image_ids)) {
                    $gallery_image_ids[] = $attachment_id;
                    $product->set_gallery_image_ids($gallery_image_ids);
                    $product->save();
                }
            }
        }

        $this->wpdb->update($table_name, ['woocommerce_id' => $attachment_id, 'sync_status' => 'synced', 'last_synced' => current_time('mysql')], ['id' => $image_db_id]);
        return ['success' => true, 'attachment_id' => $attachment_id, 'is_default' => (bool)$image->is_default];
    }

    /**
     * Sync all product images in database that are pending.
     *
     * @param int $limit Maximum number of images to sync in one batch
     * @return array Result of the sync operation
     */
    public function sync_pending_images_to_woocommerce($limit = 20) {
        $this->db_manager->create_images_table(); // Ensure table exists
        $table_name = $this->wpdb->prefix . 'pohoda_images';

        $query = $this->wpdb->prepare(
            "SELECT i.*, p.woocommerce_id as wc_product_id
            FROM $table_name i
            JOIN {$this->wpdb->prefix}pohoda_products p ON i.product_id = p.id
            WHERE i.sync_status = 'pending' AND p.woocommerce_id > 0
            LIMIT %d",
            $limit
        );
        $images = $this->wpdb->get_results($query);

        $results = ['success' => true, 'total_pending_found' => count($images), 'synced_now' => 0, 'errors' => []];
        if (empty($images)) return $results;

        foreach ($images as $image) {
            $sync_result = $this->sync_single_image_to_woocommerce($image->id, $image->wc_product_id);
            if ($sync_result['success']) {
                $results['synced_now']++;
            } else {
                $results['errors'][] = "Failed to sync DB image ID {$image->id}: " . ($sync_result['message'] ?? 'Unknown error');
            }
        }
        if (!empty($results['errors'])) $results['success'] = false;
        return $results;
    }

    /**
     * Display a product image directly from Pohoda API
     *
     * @param string $filename The image filename
     * @return void Outputs the image directly or error message
     */
    public function display_product_image($filename) {
        // Note: This method directly outputs headers and content.
        // Consider if this is appropriate for a service class or if it should return data for a controller to handle.
        $image_response = $this->get_product_image_from_api($filename);

        if ($image_response['success']) {
            header("Content-Type: " . ($image_response['content_type'] ?: "image/jpeg"));
            echo $image_response['data'];
            exit; // Important to prevent further WordPress output
        } else {
            // Determine appropriate HTTP response code based on API response
            $http_code = $image_response['http_code'] ?? 500;
            if ($http_code == 404 || strpos($image_response['message'], 'Failed to load image (HTTP 404)') !== false) {
                 http_response_code(404);
            } else if ($http_code == 403 || strpos($image_response['message'], 'Invalid API connection settings') !== false){
                 http_response_code(403);
            }
            else {
                 http_response_code(500); // Generic server error if not 404
            }
            echo "Error displaying image: " . ($image_response['message'] ?? 'Unknown error');
            exit;
        }
    }

    /**
     * Get a product image as base64 encoded data string
     *
     * @param string $filename The image filename
     * @return array ['success' => bool, 'data_uri' => string|null, 'message' => string|null]
     */
    public function get_product_image_base64($filename) {
        $image_response = $this->get_product_image_from_api($filename);

        if (!$image_response['success']) {
            return ['success' => false, 'data_uri' => null, 'message' => $image_response['message'] ?? 'Failed to fetch image from API'];
        }

        $base64 = base64_encode($image_response['data']);
        $data_uri = 'data:' . ($image_response['content_type'] ?: 'image/jpeg') . ';base64,' . $base64;

        return [
            'success' => true,
            'data_uri' => $data_uri,
            'content_type' => $image_response['content_type']
        ];
    }

    /**
     * Get product images from local database for a specific product
     *
     * @param int $product_id Pohoda product ID (from pohoda_products table)
     * @return array Images with their sync status and WC URLs if available
     */
    public function get_product_images_from_db($product_id) {
        $this->db_manager->create_images_table(); // Ensure table exists
        $table_name = $this->wpdb->prefix . 'pohoda_images';

        $images = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM $table_name WHERE product_id = %d ORDER BY is_default DESC, order_num ASC",
            $product_id
        ), ARRAY_A);

        if (empty($images)) {
            return ['success' => true, 'data' => [], 'count' => 0];
        }

        foreach ($images as &$image) {
            $image['is_default'] = (bool)$image['is_default'];
            $image['has_woocommerce_attachment'] = !empty($image['woocommerce_id']);
            $image['woocommerce_attachment_url'] = $image['has_woocommerce_attachment'] ? wp_get_attachment_url($image['woocommerce_id']) : '';
            $image['woocommerce_thumbnail_url'] = $image['has_woocommerce_attachment'] ? wp_get_attachment_image_url($image['woocommerce_id'], 'thumbnail') : '';
        }

        return ['success' => true, 'data' => $images, 'count' => count($images)];
    }

    /**
     * Get all product images from DB that match filter criteria (e.g., for sync admin page)
     *
     * @param array $params Optional parameters for filtering (status, limit, page, product_id)
     * @return array Images with their details, status, and pagination
     */
    public function get_images_for_sync_overview($params = []) {
        $this->db_manager->create_images_table(); // Ensure table exists
        $table_name = $this->wpdb->prefix . 'pohoda_images';
        $products_table_name = $this->wpdb->prefix . 'pohoda_products';

        $defaults = ['status' => 'all', 'limit' => 50, 'page' => 1, 'product_id' => null];
        $params = wp_parse_args($params, $defaults);

        $where = [];
        $where_values = [];

        if (!empty($params['status']) && $params['status'] !== 'all') {
            $where[] = 'i.sync_status = %s';
            $where_values[] = $params['status'];
        }
        if (!empty($params['product_id'])) {
            $where[] = 'i.product_id = %d';
            $where_values[] = $params['product_id'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $limit = (int)$params['limit'];
        $offset = ((int)$params['page'] - 1) * $limit;

        $query = $this->wpdb->prepare(
            "SELECT i.*, p.code as product_code, p.name as product_name, p.woocommerce_id as wc_product_id_from_products_table
            FROM $table_name i
            LEFT JOIN $products_table_name p ON i.product_id = p.id
            $where_clause
            ORDER BY i.product_id ASC, i.is_default DESC, i.order_num ASC
            LIMIT %d OFFSET %d",
            array_merge($where_values, [$limit, $offset])
        );

        $count_query_sql = "SELECT COUNT(i.id) FROM $table_name i LEFT JOIN $products_table_name p ON i.product_id = p.id $where_clause";
        $total_items = $this->wpdb->get_var(empty($where_values) ? $count_query_sql : $this->wpdb->prepare($count_query_sql, $where_values));
        
        $images = $this->wpdb->get_results($query, ARRAY_A);

        foreach ($images as &$image) {
            $image['is_default'] = (bool)$image['is_default'];
            $image['has_woocommerce_attachment'] = !empty($image['woocommerce_id']);
            $image['woocommerce_attachment_url'] = $image['has_woocommerce_attachment'] ? wp_get_attachment_url($image['woocommerce_id']) : '';
            $image['woocommerce_thumbnail_url'] = $image['has_woocommerce_attachment'] ? wp_get_attachment_image_url($image['woocommerce_id'], 'thumbnail') : '';
        }

        $total_pages = ceil($total_items / $limit);
        return [
            'success' => true,
            'data' => $images,
            'pagination' => [
                'total_items' => (int)$total_items,
                'per_page' => $limit,
                'current_page' => (int)$params['page'],
                'total_pages' => $total_pages,
                'has_more' => (int)$params['page'] < $total_pages
            ]
        ];
    }
}

// Add placeholder methods to Pohoda_API_Client if they don't exist, for the above to work.
// This would ideally be done in the Pohoda_API_Client file itself.
/*
In class Pohoda_API_Client:

public function get_options() {
    return $this->options;
}

public function get_credentials_basic_auth_string() {
    return $this->credentials; // Assuming $this->credentials is the base64 encoded string
}
*/ 