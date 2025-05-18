<?php
if (!defined('ABSPATH')) {
    exit;
}

class Pohoda_DB_Manager {
    private $wpdb;

    public function __construct(wpdb $wpdb) {
        $this->wpdb = $wpdb;
    }

    /**
     * Create local database tables for storing products
     */
    public function create_products_table() {
        $charset_collate = $this->wpdb->get_charset_collate();
        $table_name = $this->wpdb->prefix . 'pohoda_products';

        if ($this->wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
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
     * Create local database table for storing product images
     */
    public function create_images_table() {
        $charset_collate = $this->wpdb->get_charset_collate();
        $table_name = $this->wpdb->prefix . 'pohoda_images';

        if ($this->wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                product_id bigint(20) NOT NULL,
                pohoda_id bigint(20) NOT NULL,
                filepath varchar(255) NOT NULL,
                description text DEFAULT NULL,
                is_default tinyint(1) DEFAULT 0,
                order_num int(11) DEFAULT 0,
                woocommerce_id bigint(20) DEFAULT 0,
                sync_status varchar(50) DEFAULT 'pending',
                last_synced datetime DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY product_id (product_id),
                KEY filepath (filepath),
                KEY sync_status (sync_status),
                UNIQUE KEY product_pohoda_id (product_id, pohoda_id)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            return true;
        }
        return false;
    }

    /**
     * Check database tables status and return debug information
     *
     * @return array Debug information about database tables
     */
    public function check_db_tables_status() {
        $debug = [];

        // Check products table
        $products_table = $this->wpdb->prefix . 'pohoda_products';
        $products_exists = $this->wpdb->get_var("SHOW TABLES LIKE '$products_table'") == $products_table;
        $debug['products_table'] = [
            'name' => $products_table,
            'exists' => $products_exists
        ];

        if ($products_exists) {
            $products_count = $this->wpdb->get_var("SELECT COUNT(*) FROM $products_table");
            $debug['products_table']['count'] = (int)$products_count;

            $products_columns = $this->wpdb->get_results("DESCRIBE $products_table");
            $debug['products_table']['columns'] = [];
            foreach ($products_columns as $column) {
                $debug['products_table']['columns'][] = $column->Field;
            }
        }

        // Check images table
        $images_table = $this->wpdb->prefix . 'pohoda_images';
        $images_exists = $this->wpdb->get_var("SHOW TABLES LIKE '$images_table'") == $images_table;
        $debug['images_table'] = [
            'name' => $images_table,
            'exists' => $images_exists
        ];

        if ($images_exists) {
            $images_count = $this->wpdb->get_var("SELECT COUNT(*) FROM $images_table");
            $debug['images_table']['count'] = (int)$images_count;

            $images_columns = $this->wpdb->get_results("DESCRIBE $images_table");
            $debug['images_table']['columns'] = [];
            foreach ($images_columns as $column) {
                $debug['images_table']['columns'][] = $column->Field;
            }

            $products_with_images = $this->wpdb->get_col("SELECT DISTINCT product_id FROM $images_table");
            $debug['images_table']['products_with_images'] = count($products_with_images);

            $status_counts = $this->wpdb->get_results("SELECT sync_status, COUNT(*) as count FROM $images_table GROUP BY sync_status");
            $debug['images_table']['status_counts'] = [];
            foreach ($status_counts as $status) {
                $debug['images_table']['status_counts'][$status->sync_status] = (int)$status->count;
            }
        }
        
        // Check if create_images_table was called (this seems redundant here, but kept for consistency with original)
        // $debug['create_images_table_result'] = $this->create_images_table(); // This would attempt to create it again.

        return $debug;
    }

    /**
     * Force create tables (for debugging)
     *
     * @return array Result of table creation
     */
    public function force_create_tables() {
        $result = [
            'products_table_created' => $this->create_products_table(),
            'images_table_created' => $this->create_images_table(),
            // 'status' => $this->check_db_tables_status() // Call this separately if needed after creation
        ];
        return $result;
    }
} 