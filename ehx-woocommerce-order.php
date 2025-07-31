<?php

/**
 * Plugin Name: EHX WooCommerce Order
 * Description: Complete integration for WooCommerce - syncs products from API and sends order quotes to API
 * Version: 1.0.0
 * Author:  EH Studio
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class EHX_WooCommerce_Integration
{

    private $plugin_name = 'ehx_wc_integration';
    private $option_group = 'ehx_wc_integration_settings';

    public function __construct()
    {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));

        // Quote API hooks
        add_action('woocommerce_order_status_processing', array($this, 'queue_order_for_api'));
        add_action('woocommerce_order_status_completed', array($this, 'queue_order_for_api'));

        // Product sync hooks
        add_action('wp_ajax_sync_products', array($this, 'sync_products_ajax'));

        // Schedule cron jobs
        add_action('wp', array($this, 'schedule_crons'));
        add_action('ehx_wc_process_orders', array($this, 'process_queued_orders'));
        add_action('ehx_wc_sync_products', array($this, 'sync_products'));

        // Plugin activation/deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    public function init()
    {
        // Create database table if it doesn't exist
        $this->create_queue_table();
    }

    /**
     * Create database table for order queue
     */
    private function create_queue_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'ehx_wc_order_queue';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            order_data longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY order_id (order_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_options_page(
            'EHx WooCommerce Order',
            'EHx WooCommerce Order',
            'manage_options',
            'ehx_wc_integration',
            array($this, 'settings_page')
        );
    }

    /**
     * Initialize settings
     */
    public function settings_init()
    {
        // Register settings
        register_setting($this->option_group, 'ehx_wc_quote_endpoint');
        register_setting($this->option_group, 'ehx_wc_product_endpoint');
        register_setting($this->option_group, 'ehx_wc_bearer_token');
        register_setting($this->option_group, 'ehx_wc_location_key');
        register_setting($this->option_group, 'ehx_wc_quote_enabled');
        register_setting($this->option_group, 'ehx_wc_sync_enabled');
        register_setting($this->option_group, 'ehx_wc_quote_interval');
        register_setting($this->option_group, 'ehx_wc_sync_interval');

        // Quote API Section
        add_settings_section(
            'ehx_wc_quote_section',
            __('Quote API Configuration', 'textdomain'),
            array($this, 'quote_section_callback'),
            'ehx_wc_integration'
        );

        add_settings_field(
            'ehx_wc_quote_enabled',
            __('Enable Quote API', 'textdomain'),
            array($this, 'quote_enabled_render'),
            'ehx_wc_integration',
            'ehx_wc_quote_section'
        );

        add_settings_field(
            'ehx_wc_quote_endpoint',
            __('Quote API Endpoint URL', 'textdomain'),
            array($this, 'quote_endpoint_render'),
            'ehx_wc_integration',
            'ehx_wc_quote_section'
        );

        add_settings_field(
            'ehx_wc_quote_interval',
            __('Quote Processing Interval (minutes)', 'textdomain'),
            array($this, 'quote_interval_render'),
            'ehx_wc_integration',
            'ehx_wc_quote_section'
        );

        // Product Sync Section
        add_settings_section(
            'ehx_wc_sync_section',
            __('Product Sync Configuration', 'textdomain'),
            array($this, 'sync_section_callback'),
            'ehx_wc_integration'
        );

        add_settings_field(
            'ehx_wc_sync_enabled',
            __('Enable Product Sync', 'textdomain'),
            array($this, 'sync_enabled_render'),
            'ehx_wc_integration',
            'ehx_wc_sync_section'
        );

        add_settings_field(
            'ehx_wc_product_endpoint',
            __('Product API Endpoint URL', 'textdomain'),
            array($this, 'product_endpoint_render'),
            'ehx_wc_integration',
            'ehx_wc_sync_section'
        );

        add_settings_field(
            'ehx_wc_sync_interval',
            __('Sync Interval', 'textdomain'),
            array($this, 'sync_interval_render'),
            'ehx_wc_integration',
            'ehx_wc_sync_section'
        );

        // Authentication Section
        add_settings_section(
            'ehx_wc_auth_section',
            __('Authentication Settings', 'textdomain'),
            array($this, 'auth_section_callback'),
            'ehx_wc_integration'
        );

        add_settings_field(
            'ehx_wc_bearer_token',
            __('Bearer Token', 'textdomain'),
            array($this, 'bearer_token_render'),
            'ehx_wc_integration',
            'ehx_wc_auth_section'
        );

        add_settings_field(
            'ehx_wc_location_key',
            __('Location Key', 'textdomain'),
            array($this, 'location_key_render'),
            'ehx_wc_integration',
            'ehx_wc_auth_section'
        );
    }

    /**
     * Section callbacks
     */
    public function quote_section_callback()
    {
        echo __('Configure settings for sending order quotes to the API:', 'textdomain');
    }

    public function sync_section_callback()
    {
        echo __('Configure settings for syncing products from the API:', 'textdomain');
    }

    public function auth_section_callback()
    {
        echo __('Authentication settings used for both quote and product sync APIs:', 'textdomain');
    }

    /**
     * Field render methods
     */
    public function quote_enabled_render()
    {
        $enabled = get_option('ehx_wc_quote_enabled', 1);
?>
        <input type='checkbox' name='ehx_wc_quote_enabled' value='1' <?php checked($enabled, 1); ?>>
        <label for='ehx_wc_quote_enabled'>Enable automatic quote creation for orders</label>
    <?php
    }

    public function sync_enabled_render()
    {
        $enabled = get_option('ehx_wc_sync_enabled', 1);
    ?>
        <input type='checkbox' name='ehx_wc_sync_enabled' value='1' <?php checked($enabled, 1); ?>>
        <label for='ehx_wc_sync_enabled'>Enable automatic product synchronization</label>
    <?php
    }

    public function quote_endpoint_render()
    {
        $endpoint = get_option('ehx_wc_quote_endpoint', 'https://www.portal.immersivebrands.co.uk/api/quote');
    ?>
        <input type='url' name='ehx_wc_quote_endpoint' value='<?php echo esc_attr($endpoint); ?>' style='width: 400px;' required>
        <p class="description">Enter the full URL of your quote API endpoint</p>
    <?php
    }

    public function product_endpoint_render()
    {
        $endpoint = get_option('ehx_wc_product_endpoint', '');
    ?>
        <input type='url' name='ehx_wc_product_endpoint' value='<?php echo esc_attr($endpoint); ?>' style='width: 400px;' required>
        <p class="description">Enter the full URL of your product API endpoint</p>
    <?php
    }

    public function bearer_token_render()
    {
        $bearer_token = get_option('ehx_wc_bearer_token', '');
    ?>
        <input type='password' name='ehx_wc_bearer_token' value='<?php echo esc_attr($bearer_token); ?>' style='width: 500px;' required>
        <p class="description">Enter your API Bearer Token (e.g., 5|XFQGvYYXQWK5QjhOvUqzHNVDVGfpilHcIbuatKBI)</p>
    <?php
    }

    public function location_key_render()
    {
        $location_key = get_option('ehx_wc_location_key', '');
    ?>
        <input type='text' name='ehx_wc_location_key' value='<?php echo esc_attr($location_key); ?>' style='width: 300px;' required>
        <p class="description">Enter your location identifier (e.g., store ID, branch code, location name)</p>
    <?php
    }

    public function quote_interval_render()
    {
        $interval = get_option('ehx_wc_quote_interval', 30);
    ?>
        <input type='number' name='ehx_wc_quote_interval' value='<?php echo esc_attr($interval); ?>' min='1' max='1440' required>
        <p class="description">How often to process order quotes (in minutes). Default: 30 minutes</p>
    <?php
    }

    public function sync_interval_render()
    {
        $interval = get_option('ehx_wc_sync_interval', 'hourly');
        $intervals = array(
            'hourly' => 'Every Hour',
            'twicedaily' => 'Twice Daily',
            'daily' => 'Daily'
        );
    ?>
        <select name='ehx_wc_sync_interval' required>
            <?php foreach ($intervals as $key => $label): ?>
                <option value='<?php echo esc_attr($key); ?>' <?php selected($interval, $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description">How often to sync products from the API</p>
    <?php
    }

    /**
     * Settings page HTML
     */
    public function settings_page()
    {
    ?>
        <div class="wrap">
            <h1>EHX WooCommerce Integration Settings</h1>
            <form action='options.php' method='post'>
                <?php
                settings_fields($this->option_group);
                do_settings_sections('ehx_wc_integration');
                submit_button();
                ?>
            </form>

            <div class="card" style="margin-top: 20px;">
                <h2>Order Queue Status</h2>
                <?php $this->display_queue_status(); ?>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2>Manual Actions</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Process Orders</th>
                        <td>
                            <form method="post" action="" style="display: inline;">
                                <?php wp_nonce_field('ehx_wc_manual_process', 'ehx_wc_nonce'); ?>
                                <input type="hidden" name="action" value="manual_process">
                                <input type="submit" class="button button-secondary" value="Process Orders Now">
                            </form>
                            <p class="description">Process all pending order quotes immediately</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sync Products</th>
                        <td>
                            <button id="manual-sync" class="button button-primary">Sync Products Now</button>
                            <div id="sync-status"></div>
                            <p class="description">Manually sync products from the API</p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('#manual-sync').click(function() {
                    var button = $(this);
                    button.prop('disabled', true).text('Syncing...');
                    $('#sync-status').html('<p>Starting sync...</p>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'sync_products',
                            nonce: '<?php echo wp_create_nonce('sync_products_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#sync-status').html('<p style="color: green;">' + response.data.message + '</p>');
                            } else {
                                $('#sync-status').html('<p style="color: red;">Error: ' + response.data + '</p>');
                            }
                        },
                        error: function() {
                            $('#sync-status').html('<p style="color: red;">Ajax error occurred</p>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Sync Products Now');
                        }
                    });
                });
            });
        </script>
<?php

        // Handle manual order processing
        if (
            isset($_POST['action']) && $_POST['action'] === 'manual_process' &&
            wp_verify_nonce($_POST['ehx_wc_nonce'], 'ehx_wc_manual_process')
        ) {
            $this->process_queued_orders();
            echo '<div class="notice notice-success"><p>Orders processed successfully!</p></div>';
        }
    }

    /**
     * Display queue status
     */
    private function display_queue_status()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ehx_wc_order_queue';

        $pending = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE processed = 0");
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        echo "<p><strong>Pending Orders:</strong> $pending</p>";
        echo "<p><strong>Total Orders in Queue:</strong> $total</p>";

        $next_order_processing = wp_next_scheduled('ehx_wc_process_orders');
        $next_product_sync = wp_next_scheduled('ehx_wc_sync_products');

        if ($next_order_processing) {
            echo "<p><strong>Next Order Processing:</strong> " . date('Y-m-d H:i:s', $next_order_processing) . "</p>";
        }

        if ($next_product_sync) {
            echo "<p><strong>Next Product Sync:</strong> " . date('Y-m-d H:i:s', $next_product_sync) . "</p>";
        }
    }

    /**
     * Schedule cron jobs
     */
    public function schedule_crons()
    {
        // Schedule order processing
        if (!wp_next_scheduled('ehx_wc_process_orders')) {
            $interval = get_option('ehx_wc_quote_interval', 30) * 60; // Convert to seconds
            wp_schedule_event(time(), $this->get_quote_cron_interval(), 'ehx_wc_process_orders');
        }

        // Schedule product sync
        if (!wp_next_scheduled('ehx_wc_sync_products')) {
            $interval = get_option('ehx_wc_sync_interval', 'hourly');
            wp_schedule_event(time(), $interval, 'ehx_wc_sync_products');
        }
    }

    /**
     * Get quote processing cron interval
     */
    private function get_quote_cron_interval()
    {
        $interval_minutes = get_option('ehx_wc_quote_interval', 30);

        // Add custom interval if it doesn't exist
        add_filter('cron_schedules', function ($schedules) use ($interval_minutes) {
            $schedules['ehx_wc_quote_interval'] = array(
                'interval' => $interval_minutes * 60,
                'display' => sprintf(__('Every %d minutes'), $interval_minutes)
            );
            return $schedules;
        });

        return 'ehx_wc_quote_interval';
    }

    /**
     * Queue order for API processing
     */
    public function queue_order_for_api($order_id)
    {
        if (!get_option('ehx_wc_quote_enabled', 1)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $order_data = $this->prepare_order_data($order);

        global $wpdb;
        $table_name = $wpdb->prefix . 'ehx_wc_order_queue';

        // Insert or update order in queue
        $wpdb->replace(
            $table_name,
            array(
                'order_id' => $order_id,
                'order_data' => json_encode($order_data),
                'processed' => 0
            ),
            array('%d', '%s', '%d')
        );
    }

    /**
     * Prepare order data for API
     */
    private function prepare_order_data($order)
    {
        $billing = $order->get_address('billing');

        // Get order items
        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;

            $item_data = array(
                'product' => $product->get_slug(),
                'quantity' => $item->get_quantity(),
                'setup_price' => 0,
            );

            // Get product attributes/meta
            $meta_data = $item->get_meta_data();
            foreach ($meta_data as $meta) {
                $key = strtolower(str_replace(' ', '_', $meta->key));
                switch ($key) {
                    case 'color':
                        $item_data['color'] = $meta->value;
                        break;
                    case 'quantity_color':
                        $item_data['quantity_color'] = $meta->value;
                        break;
                    case 'size':
                        $item_data['size'] = $meta->value;
                        break;
                    case 'fitting':
                        $item_data['fitting'] = $meta->value;
                        break;
                }
            }

            // Set defaults if not provided
            $item_data['color'] = $item_data['color'] ?? '';
            $item_data['quantity_color'] = $item_data['quantity_color'] ?? '';
            $item_data['size'] = $item_data['size'] ?? '';
            $item_data['fitting'] = $item_data['fitting'] ?? '';

            $items[] = $item_data;
        }

        return array(
            'name' => trim($billing['first_name'] . ' ' . $billing['last_name']),
            'email' => $billing['email'],
            'telephone' => $billing['phone'],
            'company' => $billing['company'],
            'referance' => 'Order #' . $order->get_order_number(),
            'payment_method' => $order->get_payment_method_title(),
            'location_key' => get_option('ehx_wc_location_key', ''),
            'items' => $items
        );
    }

    /**
     * Process queued orders
     */
    public function process_queued_orders()
    {
        if (!get_option('ehx_wc_quote_enabled', 1)) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ehx_wc_order_queue';

        // Get pending orders
        $orders = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE processed = 0 ORDER BY created_at ASC"
        );

        if (empty($orders)) {
            return;
        }

        $endpoint = get_option('ehx_wc_quote_endpoint', 'https://www.portal.immersivebrands.co.uk/api/quote');
        $bearer_token = get_option('ehx_wc_bearer_token', '');
        $location_key = get_option('ehx_wc_location_key', '');

        foreach ($orders as $queue_item) {
            $order_data = json_decode($queue_item->order_data, true);

            // Prepare URL with location parameter
            $api_url = $endpoint;
            if (!empty($location_key)) {
                $api_url = add_query_arg('location_id', $location_key, $endpoint);
            }

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $api_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($order_data),
                CURLOPT_HTTPHEADER => array(
                    'Location-Identifire: ' . $location_key,
                    'Location-ID: ' . $location_key,
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $bearer_token
                ),
            ));

            $response = curl_exec($curl);
            curl_close($curl);

            if ($response) {
                // Mark as processed
                $wpdb->update(
                    $table_name,
                    array('processed' => 1),
                    array('id' => $queue_item->id),
                    array('%d'),
                    array('%d')
                );

                // Log success
                error_log("EHX Integration: Successfully processed order ID {$queue_item->order_id}");

                // Add order note
                $order = wc_get_order($queue_item->order_id);
                if ($order) {
                    $order->add_order_note('Quote sent to API successfully');
                }
            } else {
                // Log error
                error_log("EHX Integration: Error processing order ID {$queue_item->order_id}");
            }
        }
    }

    /**
     * AJAX handler for manual product sync
     */
    public function sync_products_ajax()
    {
        check_ajax_referer('sync_products_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $result = $this->sync_products();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Sync products from API
     */
    public function sync_products()
    {
        if (!get_option('ehx_wc_sync_enabled', 1)) {
            return array('success' => false, 'message' => 'Product sync is disabled');
        }

        $endpoint = get_option('ehx_wc_product_endpoint', '');
        $bearer_token = get_option('ehx_wc_bearer_token', '');
        $location_key = get_option('ehx_wc_location_key', '');

        if (empty($endpoint) || empty($bearer_token) || empty($location_key)) {
            return array('success' => false, 'message' => 'API settings not configured properly');
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $bearer_token,
                'Location-Identifire: ' . $location_key,
                'X-Location-Key: ' . $location_key,
                'Business-Location: ' . $location_key,
                'Location-ID: ' . $location_key,
            ],
        ]);

        $data = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            return array('success' => false, 'message' => 'cURL Error: ' . $error);
        }

        $created_count = 0;
        $updated_count = 0;
        $errors = array();

        $response_data = json_decode($data, true);

        if (!$response_data || !isset($response_data['data'])) {
            return array('success' => false, 'message' => 'Invalid API response');
        }

        $products_data = $response_data['data'];

        foreach ($products_data as $product_data) {
            $result = $this->create_or_update_product($product_data);

            if ($result['success']) {
                if ($result['action'] === 'created') {
                    $created_count++;
                } else {
                    $updated_count++;
                }
            } else {
                $errors[] = $result['message'];
            }
        }

        $message = sprintf(
            'Sync completed. Created: %d, Updated: %d, Errors: %d',
            $created_count,
            $updated_count,
            count($errors)
        );

        if (!empty($errors)) {
            $message .= ' | First error: ' . $errors[0];
        }

        return array(
            'success' => true,
            'message' => $message,
            'details' => array(
                'created' => $created_count,
                'updated' => $updated_count,
                'errors' => $errors
            )
        );
    }

    /**
     * Create or update a product
     */
    private function create_or_update_product($product_data)
    {
        if (!class_exists('WooCommerce')) {
            return array('success' => false, 'message' => 'WooCommerce not active');
        }

        try {
            // Check if product exists by slug
            $product_code = isset($product_data['product_code']) ? $product_data['product_code'] : '';

            $existing_product = get_page_by_path($product_data['slug'], OBJECT, 'product');

            if ($existing_product) {
                $product = wc_get_product($existing_product->ID);
                $action = 'updated';
            } else {
                $product = new WC_Product_Simple();
                $action = 'created';
            }

            // Set basic product data
            $product->set_name(trim($product_data['name']));
            $product->set_slug($product_data['slug']);
            $product->set_sku($product_code);

            if (!empty($product_data['short_description'])) {
                $product->set_short_description($product_data['short_description']);
            }

            // Set pricing
            $regular_price = floatval($product_data['unit_price']);
            if ($product_data['vat'] === 'yes' && !empty($product_data['vat_percentage'])) {
                $vat_multiplier = 1 + (floatval($product_data['vat_percentage']) / 100);
                $regular_price = $regular_price * $vat_multiplier;
            }

            $product->set_regular_price($regular_price);
            $product->set_price($regular_price);

            // Set stock management
            $product->set_manage_stock(true);
            $product->set_stock_quantity($product_data['max_qty']);
            $product->set_stock_status('instock');

            // Set product status
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');

            // Handle categories
            if (!empty($product_data['categories']['data']) && is_array($product_data['categories']['data'])) {
                $category_ids = $this->assign_product_categories($product_data['categories']['data']);
                if (!empty($category_ids)) {
                    $product->set_category_ids($category_ids);
                }
            }

            // Save product
            $product_id = $product->save();

            // Handle thumbnail image
            if (!empty($product_data['thumbnail_image'])) {
                $this->set_product_image($product_id, $product_data['thumbnail_image']);
            }

            // Handle additional images
            if (!empty($product_data['images']) && is_array($product_data['images'])) {
                $this->set_product_gallery($product_id, $product_data['images']);
            }

            // Store API metadata
            update_post_meta($product_id, '_api_min_price', $product_data['min_price']);
            update_post_meta($product_id, '_api_max_price', $product_data['max_price']);
            update_post_meta($product_id, '_api_min_qty', $product_data['min_qty']);
            update_post_meta($product_id, '_api_max_qty', $product_data['max_qty']);
            update_post_meta($product_id, '_api_setup_price', $product_data['setup_price']);
            update_post_meta($product_id, '_api_last_sync', current_time('mysql'));

            return array(
                'success' => true,
                'action' => $action,
                'product_id' => $product_id,
                'message' => ucfirst($action) . ' product: ' . $product_data['name']
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Error processing product ' . $product_data['name'] . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Helper function to assign product categories
     */
    private function assign_product_categories($categories_data)
    {
        $category_ids = array();

        foreach ($categories_data as $category_data) {
            if (empty($category_data['name'])) {
                continue;
            }

            $category_name = trim($category_data['name']);
            $category_slug = isset($category_data['slug']) ? $category_data['slug'] : sanitize_title($category_name);

            // Check if category exists
            $existing_category = get_term_by('slug', $category_slug, 'product_cat');

            if ($existing_category) {
                $category_ids[] = $existing_category->term_id;
            } else {
                // Create new category
                $new_category = wp_insert_term(
                    $category_name,
                    'product_cat',
                    array(
                        'slug' => $category_slug,
                        'description' => isset($category_data['description']) ? $category_data['description'] : ''
                    )
                );

                if (!is_wp_error($new_category)) {
                    $category_ids[] = $new_category['term_id'];
                }
            }
        }

        return $category_ids;
    }

    /**
     * Set product thumbnail image
     */
    private function set_product_image($product_id, $image_url)
    {
        $attachment_id = $this->upload_image_from_url($image_url, $product_id);
        if ($attachment_id) {
            set_post_thumbnail($product_id, $attachment_id);
        }
    }

    /**
     * Set product gallery images
     */
    private function set_product_gallery($product_id, $images)
    {
        $gallery_ids = array();

        foreach ($images as $image_url) {
            $attachment_id = $this->upload_image_from_url($image_url, $product_id);
            if ($attachment_id) {
                $gallery_ids[] = $attachment_id;
            }
        }

        if (!empty($gallery_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        }
    }

    /**
     * Upload image from URL and attach to product
     */
    private function upload_image_from_url($image_url, $product_id)
    {
        $upload_dir = wp_upload_dir();
        $filename = basename(parse_url($image_url, PHP_URL_PATH));

        // Check if image already exists
        $existing_attachment = get_posts(array(
            'post_type' => 'attachment',
            'meta_query' => array(
                array(
                    'key' => '_source_url',
                    'value' => $image_url,
                    'compare' => '='
                )
            )
        ));

        if (!empty($existing_attachment)) {
            return $existing_attachment[0]->ID;
        }

        $image_data = wp_remote_get($image_url);

        if (is_wp_error($image_data) || wp_remote_retrieve_response_code($image_data) !== 200) {
            return false;
        }

        $upload = wp_upload_bits($filename, null, wp_remote_retrieve_body($image_data));

        if (!$upload['error']) {
            $attachment = array(
                'post_mime_type' => wp_check_filetype($upload['file'])['type'],
                'post_title' => sanitize_file_name($filename),
                'post_content' => '',
                'post_status' => 'inherit'
            );

            $attachment_id = wp_insert_attachment($attachment, $upload['file'], $product_id);

            if (!is_wp_error($attachment_id)) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
                wp_update_attachment_metadata($attachment_id, $attachment_data);

                // Store source URL for future reference
                update_post_meta($attachment_id, '_source_url', $image_url);

                return $attachment_id;
            }
        }

        return false;
    }

    /**
     * Plugin activation
     */
    public function activate()
    {
        $this->create_queue_table();
        $this->schedule_crons();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate()
    {
        wp_clear_scheduled_hook('ehx_wc_process_orders');
        wp_clear_scheduled_hook('ehx_wc_sync_products');
    }
}

// Initialize the plugin
new EHX_WooCommerce_Integration();

?>