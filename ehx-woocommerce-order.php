<?php

/**
 * Plugin Name: EHx WooCommerce Order
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

        add_action('wp_ajax_reset_product_sync', array($this, 'reset_product_sync_ajax'));

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
        //  echo __('Configure settings for sending order quotes to the API:', 'textdomain');
    }

    public function sync_section_callback()
    {
        //  echo __('Configure settings for syncing products from the API:', 'textdomain');
    }

    public function auth_section_callback()
    {
        //   echo __('Authentication settings used for both quote and product sync APIs:', 'textdomain');
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
        <!-- <p class="description">Enter the full URL of your quote API endpoint</p> -->
    <?php
    }

    public function product_endpoint_render()
    {
        $endpoint = get_option('ehx_wc_product_endpoint', '');
    ?>
        <input type='url' name='ehx_wc_product_endpoint' value='<?php echo esc_attr($endpoint); ?>' style='width: 400px;' required>
        <!-- <p class="description">Enter the full URL of your product API endpoint</p> -->
    <?php
    }

    public function bearer_token_render()
    {
        $bearer_token = get_option('ehx_wc_bearer_token', '');
    ?>
        <input type='password' name='ehx_wc_bearer_token' value='<?php echo esc_attr($bearer_token); ?>' style='width: 500px;' required>
        <!-- <p class="description">Enter your API Bearer Token (e.g., 5|XFQGvYYXQWK5QjhOvUqzHNVDVGfpilHcIbuatKBI)</p> -->
    <?php
    }

    public function location_key_render()
    {
        $location_key = get_option('ehx_wc_location_key', '');
    ?>
        <input type='text' name='ehx_wc_location_key' value='<?php echo esc_attr($location_key); ?>' style='width: 300px;' required>
        <!-- <p class="description">Enter your location identifier (e.g., store ID, branch code, location name)</p> -->
    <?php
    }

    public function quote_interval_render()
    {
        $interval = get_option('ehx_wc_quote_interval', 30);
    ?>
        <input type='number' name='ehx_wc_quote_interval' value='<?php echo esc_attr($interval); ?>' min='1' max='1440' required>
        <!-- <p class="description">How often to process order quotes (in minutes). Default: 30 minutes</p> -->
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
            <h1>EHx WooCommerce Integration Settings</h1>
            <form action='options.php' method='post'>
                <?php
                settings_fields($this->option_group);
                do_settings_sections('ehx_wc_integration');
                submit_button();
                ?>
            </form>

            <div class="card" style="border: none;  box-shadow: none; margin-top: 20px; max-width: 100%; padding: 20px;">

                <?php
                // Schedule info
                $next_order_processing = wp_next_scheduled('ehx_wc_process_orders');
                $next_product_sync = wp_next_scheduled('ehx_wc_sync_products');

                // if ($next_order_processing || $next_product_sync) {
                //     echo "<div class='ehx-schedule-info' style='margin-bottom: 20px; padding: 12px; background: #fff8e3; border: 1px solid #fff8e3;'>";
                //     if ($next_order_processing) {
                //         echo "<p style='margin-top: 0;'><strong>Next Order Processing:</strong> " . date('Y-m-d H:i:s', $next_order_processing) . "</p>";
                //     }
                //     if ($next_product_sync) {
                //         echo "<p style='margin: 0px;'><strong>Next Product Sync:</strong> " . date('Y-m-d H:i:s', $next_product_sync) . "</p>";
                //     }
                //     echo "</div>";
                // } 
                ?>
                <h2>Manual Actions</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Sync Orders</th>
                        <td>
                            <form method="post" action="" style="display: inline;">
                                <?php wp_nonce_field('ehx_wc_manual_process', 'ehx_wc_nonce'); ?>
                                <input type="hidden" name="action" value="manual_process">
                                <input type="submit" class="button button-secondary" value="Sync Orders Now">
                            </form>
                            <p class="description">Process all pending order quotes immediately</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sync Products</th>
                        <td>
                            <button id="manual-sync" class="button button-primary">Sync Products Now</button>
                            <button id="reset-sync" class="button button-secondary" style="margin-left: 10px;">Reset Sync</button>
                            <div id="sync-status"></div>
                            <p class="description">Manually sync products from the API</p>
                            <?php if (get_option('ehx_wc_product_total_number', 0) > 0): ?>
                                <p class="description">Sync Stats : Total Products Stored: <?php echo get_option('ehx_wc_product_stored_number', 0); ?>, Total Express Products in API: <?php echo get_option('ehx_wc_product_total_number', 0); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card" style="border: none;  box-shadow: none; margin-top: 20px; max-width: 100%;">
                <h2>Order Queue Status</h2>
                <?php $this->display_queue_status(); ?>
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

                // Reset sync button
                $('#reset-sync').click(function() {
                    if (!confirm('Are you sure you want to reset the product sync? This will start the sync from page 1 again.')) {
                        return;
                    }

                    var button = $(this);
                    button.prop('disabled', true).text('Resetting...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'reset_product_sync',
                            nonce: '<?php echo wp_create_nonce('reset_sync_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#sync-status').html('<p style="color: green;">' + response.data.message + '</p>');
                                // Reload page to show updated stats
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            } else {
                                $('#sync-status').html('<p style="color: red;">Error: ' + response.data + '</p>');
                            }
                        },
                        error: function() {
                            $('#sync-status').html('<p style="color: red;">Ajax error occurred</p>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Reset Sync');
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
    //===========================start=================================
    private function display_queue_status()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ehx_wc_order_queue';

        // Handle AJAX update for processed status
        if (isset($_POST['update_processed']) && wp_verify_nonce($_POST['ehx_wc_nonce'], 'ehx_wc_update_processed')) {
            $queue_id = intval($_POST['queue_id']);
            $processed = intval($_POST['processed']);

            $updated = $wpdb->update(
                $table_name,
                array('processed' => $processed),
                array('id' => $queue_id),
                array('%d'),
                array('%d')
            );

            if ($updated !== false) {
                echo '<div class="notice notice-success is-dismissible"><p>Queue item updated successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Failed to update queue item.</p></div>';
            }
        }

        // Handle bulk actions
        if (isset($_POST['bulk_action']) && $_POST['bulk_action'] !== '' && !empty($_POST['queue_items'])) {
            if (wp_verify_nonce($_POST['ehx_wc_bulk_nonce'], 'ehx_wc_bulk_action')) {
                $action = sanitize_text_field($_POST['bulk_action']);
                $queue_ids = array_map('intval', $_POST['queue_items']);
                $placeholders = implode(',', array_fill(0, count($queue_ids), '%d'));

                if ($action === 'mark_processed') {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $table_name SET processed = 1 WHERE id IN ($placeholders)",
                        ...$queue_ids
                    ));
                    echo '<div class="notice notice-success is-dismissible"><p>Selected items marked as processed!</p></div>';
                } elseif ($action === 'mark_unprocessed') {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE $table_name SET processed = 0 WHERE id IN ($placeholders)",
                        ...$queue_ids
                    ));
                    // echo '<div class="notice notice-success is-dismissible"><p>Selected items marked as unprocessed!</p></div>';
                } elseif ($action === 'delete') {
                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM $table_name WHERE id IN ($placeholders)",
                        ...$queue_ids
                    ));
                    echo '<div class="notice notice-success is-dismissible"><p>Selected items deleted!</p></div>';
                }
            }
        }

        // Get search parameters
        $search_term = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';

        // Build WHERE clause for search and filters
        $where_conditions = array();
        $where_values = array();

        if (!empty($search_term)) {
            $where_conditions[] = "(q.order_id LIKE %s OR q.order_data LIKE %s OR p.post_title LIKE %s)";
            $search_like = '%' . $wpdb->esc_like($search_term) . '%';
            $where_values[] = $search_like;
            $where_values[] = $search_like;
            $where_values[] = $search_like;
        }

        if ($status_filter !== '') {
            $where_conditions[] = "q.processed = %d";
            $where_values[] = intval($status_filter);
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        // Get queue data with pagination and search
        $per_page = 8;
        $current_page = isset($_GET['queue_page']) ? max(1, intval($_GET['queue_page'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        // Count total items with search
        $count_sql = "SELECT COUNT(*) FROM $table_name q LEFT JOIN {$wpdb->posts} p ON q.order_id = p.ID $where_clause";
        if (!empty($where_values)) {
            $total_items = $wpdb->get_var($wpdb->prepare($count_sql, ...$where_values));
        } else {
            $total_items = $wpdb->get_var($count_sql);
        }
        $total_pages = ceil($total_items / $per_page);

        // Get queue items with search
        $query_sql = "SELECT q.*, p.post_title as order_title 
                  FROM $table_name q 
                  LEFT JOIN {$wpdb->posts} p ON q.order_id = p.ID 
                  $where_clause
                  ORDER BY q.created_at DESC 
                  LIMIT %d OFFSET %d";

        $query_values = array_merge($where_values, array($per_page, $offset));
        $queue_items = $wpdb->get_results($wpdb->prepare($query_sql, ...$query_values));

        // Get counts for stats (without search filter)
        $pending = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE processed = 0");
        $processed = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE processed = 1");
        $total_all = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        // Summary stats
        echo "<div class='ehx-queue-stats' style='display: flex; gap: 20px; margin-bottom: 20px;'>";
        echo "<div class='stat-box' style='padding: 15px; background: #f9f9f9; border-left: 2px solid #0073aa;'>";
        echo "<strong>Total Orders:</strong> $total_all";
        echo "</div>";
        echo "<div class='stat-box' style='padding: 15px; background: #f9f9f9; border-left: 2px solid #d63638;'>";
        echo "<strong>Pending:</strong> $pending";
        echo "</div>";
        echo "<div class='stat-box' style='padding: 15px; background: #f9f9f9; border-left: 2px solid #00a32a;'>";
        echo "<strong>Processed:</strong> $processed";
        echo "</div>";
        if (!empty($search_term) || $status_filter !== '') {
            echo "<div class='stat-box' style='padding: 15px; background: #fff3cd; border-left: 2px solid #856404;'>";
            echo "<strong>Search Results:</strong> $total_items";
            echo "</div>";
        }
        echo "</div>";

        // Search and Filter Form
        echo "<div class='ehx-search-container' style='background: #f9f9f9; padding: 15px; margin-bottom: 20px; border-radius: 5px;'>";
        echo "<form method='get' style='display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;'>";

        // Preserve other GET parameters
        foreach ($_GET as $key => $value) {
            if (!in_array($key, ['s', 'status_filter', 'queue_page'])) {
                echo "<input type='hidden' name='" . esc_attr($key) . "' value='" . esc_attr($value) . "'>";
            }
        }

        echo "<div style='flex: 1; min-width: 200px;'>";
        echo "<label for='search-input' style='display: block; font-weight: bold; margin-bottom: 5px;'>Search:</label>";
        echo "<input type='text' id='search-input' name='s' value='" . esc_attr($search_term) . "' placeholder='Search by Order ID, Customer Name, Email, Company...' style='width: 100%; padding: 8px;'>";
        echo "</div>";

        echo "<div style='min-width: 150px;'>";
        echo "<label for='status-filter' style='display: block; font-weight: bold; margin-bottom: 5px;'>Status:</label>";
        echo "<select name='status_filter' id='status-filter' style='width: 100%; padding: 8px;'>";
        echo "<option value=''" . ($status_filter === '' ? ' selected' : '') . ">All Statuses</option>";
        echo "<option value='0'" . ($status_filter === '0' ? ' selected' : '') . ">Pending</option>";
        echo "<option value='1'" . ($status_filter === '1' ? ' selected' : '') . ">Processed</option>";
        echo "</select>";
        echo "</div>";

        echo "<div>";
        echo "<button type='submit' class='button button-primary' style='padding: 8px 15px; height: auto; margin-top: 20px;'>Search</button>";
        if (!empty($search_term) || $status_filter !== '') {
            $clear_url = remove_query_arg(['s', 'status_filter', 'queue_page']);
            echo "<a href='" . esc_url($clear_url) . "' class='button' style='padding: 8px 15px; height: auto; margin-left: 5px; margin-top: 20px;'>Clear</a>";
        }
        echo "</div>";

        echo "</form>";
        echo "</div>";

        if (empty($queue_items)) {
            if (!empty($search_term) || $status_filter !== '') {
                echo "<p>No orders found matching your search criteria.</p>";
            } else {
                echo "<p>No orders in queue.</p>";
            }
            return;
        }

        // Display search info
        if (!empty($search_term) || $status_filter !== '') {
            echo "<div class='search-info' style='background: #e7f3ff; padding: 10px; margin-bottom: 15px; border-left: 4px solid #0073aa;'>";
            $info_parts = array();
            if (!empty($search_term)) {
                $info_parts[] = "Search: '<strong>" . esc_html($search_term) . "</strong>'";
            }
            if ($status_filter !== '') {
                $status_text = $status_filter === '1' ? 'Processed' : 'Pending';
                $info_parts[] = "Status: <strong>$status_text</strong>";
            }
            echo "Showing results for " . implode(' | ', $info_parts) . " ($total_items items found)";
            echo "</div>";
        }

        // Bulk actions form
        echo "<form method='post' id='ehx-queue-form'>";
        wp_nonce_field('ehx_wc_bulk_action', 'ehx_wc_bulk_nonce');
        echo "<div class='tablenav top' style='margin-bottom: 10px;'>";
        echo "<div class='alignleft actions bulkactions'>";
        echo "<select name='bulk_action'>";
        echo "<option value=''>Bulk Actions</option>";
        // echo "<option value='mark_processed'>Mark as Processed</option>";
        echo "<option value='mark_unprocessed'>Mark as Unprocessed</option>";
        echo "<option value='delete'>Delete</option>";
        echo "</select>";
        echo "<input type='submit' class='button action' value='Apply'>";
        echo "</div>";
        echo "</div>";

        // Queue table
        echo "<table class='wp-list-table widefat fixed striped' style='margin-top: 10px;'>";
        echo "<thead>";
        echo "<tr>";
        echo "<td class='manage-column column-cb check-column'><input type='checkbox' id='cb-select-all'></td>";
        echo "<th class='manage-column'>SL</th>";
        echo "<th class='manage-column'>Order ID</th>";
        echo "<th class='manage-column'>Customer Info</th>";
        echo "<th class='manage-column'>Created</th>";
        echo "<th class='manage-column'>Status</th>";
        echo "<th class='manage-column'>Actions</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";
        $counter = ($current_page - 1) * $per_page + 1;

        foreach ($queue_items as $item) {
            $order_data = json_decode($item->order_data, true);
            $status_class = $item->processed ? 'processed' : 'pending';
            $status_text = $item->processed ? 'Processed' : 'Pending';
            $status_color = $item->processed ? '#00a32a' : '#d63638';

            echo "<tr class='queue-item-{$status_class}'>";
            echo "<th class='check-column'><input type='checkbox' name='queue_items[]' value='{$item->id}'></th>";
            echo "<td><strong>" . $counter . "</strong></td>";
            echo "<td>";
            echo "#{$item->order_id} ";
            echo "</td>";
            echo "<td>";
            if (isset($order_data['name'])) {
                echo "<strong>" . esc_html($order_data['name']) . "</strong><br>";
                echo "<small>" . esc_html($order_data['email'] ?? '') . "</small>";
                if (!empty($order_data['company'])) {
                    echo "<br><small>" . esc_html($order_data['company']) . "</small>";
                }
            }
            echo "</td>";
            echo "<td>";
            echo "<small>" . date('Y-m-d<\b\\r>H:i:s', strtotime($item->created_at)) . "</small>";
            echo "</td>";
            echo "<td>";
            echo "<span style='color: {$status_color}; font-weight: bold;'>{$status_text}</span>";
            echo "</td>";
            echo "<td>";

            if ($item->processed) {
                // Quick status toggle
                echo "<form method='post' style='display: inline-block; margin-right: 5px;'>";
                wp_nonce_field('ehx_wc_update_processed', 'ehx_wc_nonce');
                echo "<input type='hidden' name='update_processed' value='1'>";
                echo "<input type='hidden' name='queue_id' value='{$item->id}'>";
                echo "<input type='hidden' name='processed' value='" . ($item->processed ? 0 : 1) . "'>";
                $toggle_text = $item->processed ? 'Mark Pending' : 'Mark Processed';
                $toggle_class = $item->processed ? 'button-secondary' : 'button-primary';
                echo "<input type='submit' class='button {$toggle_class}' value='{$toggle_text}' style='font-size: 11px; padding: 2px 8px; margin-bottom: 8px;'>";
                echo "</form>";
            }
            // View order data button
            echo "<button type='button' class='button button-small view-order-data' data-order-id='{$item->id}' style='font-size: 11px; padding: 2px 8px;'>View Data</button>";

            echo "</td>";
            echo "</tr>";

            // Hidden row for order data
            echo "<tr id='order-data-{$item->id}' class='order-data-row' style='display: none;'>";
            echo "<td colspan='7' style='padding: 0;'>";
            echo "<div style='background: #f9f9f9; border-bottom: 1px solid #ddd; border-top: 1px solid #ddd; padding: 15px; '>";
            echo "<h4>Order Data for Queue ID #{$item->id}</h4>";

            // Use $order_data directly (it's already decoded above)
            if ($order_data && is_array($order_data)) {
                // Customer Information Section
                echo "<div style='background: white; padding: 15px; margin-bottom: 15px; border-radius: 5px;'>";
                echo "<h5 style='color: #0073aa; font-size: 14px; margin: 0 0 10px 0; padding-bottom: 5px; border-bottom: 1px solid #0073aa;'>Customer Information</h5>";
                echo "<table style='width: 100%; border-collapse: collapse;'>";

                echo "<tr><td style='padding: 5px 10px ; font-weight: bold; width: 150px;'>Name:</td><td style='padding: 5px 10px;'>" . esc_html($order_data['name'] ?? '') . "</td></tr>";
                echo "<tr style='background: #f5f5f5;'><td style='padding: 5px 10px; font-weight: bold;'>Email:</td><td style='padding: 5px 10px;'>" . esc_html($order_data['email'] ?? '') . "</td></tr>";
                echo "<tr><td style='padding: 5px 10px; font-weight: bold;'>Phone:</td><td style='padding: 5px 10px;'>" . esc_html($order_data['telephone'] ?? '') . "</td></tr>";
                echo "<tr style='background: #f5f5f5;'><td style='padding: 5px 10px; font-weight: bold;'>Company:</td><td style='padding: 5px 10px;'>" . esc_html($order_data['company']) . "</td></tr>";
                echo "<tr><td style='padding: 5px 10px; font-weight: bold; width: 150px;'>Reference:</td><td style='padding: 5px 10px;'>" . esc_html($order_data['referance'] ?? '') . "</td></tr>";
                echo "<tr style='background: #f5f5f5;'><td style='padding: 5px 10px; font-weight: bold;'>Payment Method:</td><td style='padding: 5px 10px;'>" . esc_html($order_data['payment_method'] ?? '') . "</td></tr>";
                echo "<tr><td style='padding: 5px 10px; font-weight: bold;'>Location Key:</td><td style='padding: 5px 10px;'>" . esc_html($order_data['location_key'] ?? '') . "</td></tr>";

                echo "</table>";
                echo "</div>";

                // Items Section
                if (!empty($order_data['items']) && is_array($order_data['items'])) {
                    echo "<div style='background: white; padding: 15px;  border-radius: 5px;'>";
                    echo "<h5 style='color: #0073aa; font-size: 14px; margin: 0 0 10px 0; padding-bottom: 5px; border-bottom: 1px solid #0073aa;'>Order Items</h5>";

                    foreach ($order_data['items'] as $index => $orderItem) {
                        echo "<div style='padding: 10px;  margin-bottom: 10px; '>";
                        echo "<strong >Item " . ($index + 1) . ":</strong><br>";
                        echo "<table style='width: 100%; border-collapse: collapse; margin-left: 25px; margin-top: 5px;'>";

                        echo "<tr><td style='padding: 3px 10px; font-weight: bold; width: 120px;'>Product:</td><td style='padding: 3px 10px;'>" . esc_html($orderItem['product'] ?? '') . "</td></tr>";
                        echo "<tr><td style='padding: 3px 10px; font-weight: bold;'>Quantity:</td><td style='padding: 3px 10px;'>" . esc_html($orderItem['quantity'] ?? '') . "</td></tr>";
                        echo "<tr><td style='padding: 3px 10px; font-weight: bold;'>Setup Price:</td><td style='padding: 3px 10px;'>$" . esc_html($orderItem['setup_price'] ?? '0') . "</td></tr>";
                        echo "<tr><td style='padding: 3px 10px; font-weight: bold;'>Color:</td><td style='padding: 3px 10px;'>" . esc_html($orderItem['color']) . "</td></tr>";
                        echo "<tr><td style='padding: 3px 10px; font-weight: bold;'>Quantity Color:</td><td style='padding: 3px 10px;'>" . esc_html($orderItem['quantity_color']) . "</td></tr>";
                        echo "<tr><td style='padding: 3px 10px; font-weight: bold;'>Size:</td><td style='padding: 3px 10px;'>" . esc_html($orderItem['size']) . "</td></tr>";
                        echo "<tr><td style='padding: 3px 10px; font-weight: bold;'>Fitting:</td><td style='padding: 3px 10px;'>" . esc_html($orderItem['fitting']) . "</td></tr>";

                        echo "</table>";
                        echo "</div>";
                    }
                    echo "</div>";
                }
            } else {
                // Fallback to raw JSON if parsing fails
                echo "<pre style='background: white; padding: 10px; border: 1px solid #ddd; overflow-x: auto; max-height: 300px;'>";
                echo esc_html(json_encode(json_decode($item->order_data), JSON_PRETTY_PRINT));
                echo "</pre>";
            }

            echo "</div>";
            echo "</td>";
            echo "</tr>";

            $counter++;
        }

        echo "</tbody>";
        echo "</table>";
        echo "</form>";

        // Pagination
        if ($total_pages > 1) {
            echo "<div class='tablenav bottom'>";
            echo "<div class='tablenav-pages'>";
            echo "<span class='displaying-num'>" . sprintf('%d items', $total_items) . "</span>";

            // Build pagination URLs with search parameters
            $pagination_args = array();
            if (!empty($search_term)) {
                $pagination_args['s'] = $search_term;
            }
            if ($status_filter !== '') {
                $pagination_args['status_filter'] = $status_filter;
            }

            if ($current_page > 1) {
                $prev_args = array_merge($pagination_args, array('queue_page' => $current_page - 1));
                echo "<a class='button' href='" . esc_url(add_query_arg($prev_args)) . "'>&laquo; Previous</a> ";
            }

            // Show page numbers
            for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++) {
                if ($i == $current_page) {
                    echo "<span class='button button-primary'>{$i}</span> ";
                } else {
                    $page_args = array_merge($pagination_args, array('queue_page' => $i));
                    echo "<a class='button' href='" . esc_url(add_query_arg($page_args)) . "'>{$i}</a> ";
                }
            }

            if ($current_page < $total_pages) {
                $next_args = array_merge($pagination_args, array('queue_page' => $current_page + 1));
                echo "<a class='button' href='" . esc_url(add_query_arg($next_args)) . "'>Next &raquo;</a>";
            }

            echo "</div>";
            echo "</div>";
        }

        // JavaScript for interactions
        ?>
        <script>
            jQuery(document).ready(function($) {
                // Select all checkbox
                $('#cb-select-all').change(function() {
                    $('input[name="queue_items[]"]').prop('checked', this.checked);
                });

                // Individual checkboxes
                $('input[name="queue_items[]"]').change(function() {
                    var total = $('input[name="queue_items[]"]').length;
                    var checked = $('input[name="queue_items[]"]:checked').length;
                    $('#cb-select-all').prop('checked', total === checked);
                });

                // View order data toggle
                $('.view-order-data').click(function() {
                    var orderId = $(this).data('order-id');
                    var dataRow = $('#order-data-' + orderId);

                    if (dataRow.is(':visible')) {
                        dataRow.hide();
                        $(this).text('View Data');
                    } else {
                        dataRow.show();
                        $(this).text('Hide Data');
                    }
                });

                // Confirm bulk delete
                $('#ehx-queue-form').submit(function(e) {
                    var action = $('select[name="bulk_action"]').val();
                    if (action === 'delete') {
                        var checkedItems = $('input[name="queue_items[]"]:checked').length;
                        if (checkedItems > 0) {
                            if (!confirm('Are you sure you want to delete ' + checkedItems + ' selected item(s)? This action cannot be undone.')) {
                                e.preventDefault();
                            }
                        }
                    }
                });

                // Search functionality enhancements
                $('#search-input').on('keypress', function(e) {
                    if (e.which === 13) { // Enter key
                        $(this).closest('form').submit();
                    }
                });

                // Auto-focus search input if there's a search term
                <?php if (!empty($search_term)): ?>
                    $('#search-input').focus().get(0).setSelectionRange(<?php echo strlen($search_term); ?>, <?php echo strlen($search_term); ?>);
                <?php endif; ?>
            });
        </script>

        <style>
            .ehx-queue-stats .stat-box {
                min-width: 120px;
                text-align: center;
            }

            .queue-item-processed {
                background-color: #f0f9ff;
            }

            tr.queue-item-processed:nth-child(odd) {
                background-color: #fff;
            }

            tr.queue-item-processed:nth-child(odd):hover {
                background-color: #f6f7f7;
            }

            .order-data-row td {
                /* padding: 0 !important; */
            }

            .tablenav-pages .button {
                margin-right: 5px;
                text-decoration: none;
            }

            .wp-list-table th,
            .wp-list-table td {
                vertical-align: top;
            }

            .ehx-search-container {
                border: 1px solid #ddd;
            }

            .ehx-search-container input[type="text"],
            .ehx-search-container select {
                border: 1px solid #ddd;
                border-radius: 3px;
            }

            .search-info {
                border-radius: 3px;
            }

            @media (max-width: 768px) {
                .ehx-search-container form {
                    flex-direction: column;
                }

                .ehx-search-container form>div {
                    width: 100% !important;
                    min-width: auto !important;
                }
            }
        </style>
<?php
    }

    /**
     * Also add this method to handle AJAX updates (optional - for real-time updates)
     */
    public function ajax_update_queue_status()
    {
        check_ajax_referer('ehx_wc_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $queue_id = intval($_POST['queue_id']);
        $processed = intval($_POST['processed']);

        global $wpdb;
        $table_name = $wpdb->prefix . 'ehx_wc_order_queue';

        $updated = $wpdb->update(
            $table_name,
            array('processed' => $processed),
            array('id' => $queue_id),
            array('%d'),
            array('%d')
        );

        if ($updated !== false) {
            wp_send_json_success('Status updated successfully');
        } else {
            wp_send_json_error('Failed to update status');
        }
    }
    //===================================finish====================================
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
                'processed' => 0,
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

        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;

            $item_data = array(
                'product' => $product->get_slug(),
                'quantity' => $item->get_quantity(),
                'setup_price' => 0,
            );

            $meta_data = $item->get_meta_data();
            foreach ($meta_data as $meta) {
                $key = strtolower(str_replace(' ', '_', trim($meta->key)));

                switch ($key) {
                    case 'color':
                    case 'colour':
                    case 'pa_color':
                    case 'pa_colour':
                        $item_data['color'] = ucfirst(strtolower(sanitize_text_field($meta->value)));
                        break;
                    case 'quantity_color':
                    case 'quantity_colour':
                        $item_data['quantity_color'] = ucfirst(strtolower(sanitize_text_field($meta->value)));
                        break;
                    case 'size':
                    case 'pa_size':
                        $item_data['size'] = sanitize_text_field($meta->value);
                        break;
                    case 'fitting':
                    case 'pa_fitting':
                        $item_data['fitting'] = ucwords(strtolower(sanitize_text_field($meta->value)));
                        break;
                }
            }

            if ($product->is_type('variation')) {
                $variation_attributes = $product->get_variation_attributes();
                foreach ($variation_attributes as $attr_name => $attr_value) {
                    $clean_attr_name = str_replace('attribute_', '', $attr_name);
                    $clean_attr_name = str_replace('pa_', '', $clean_attr_name);

                    switch (strtolower($clean_attr_name)) {
                        case 'color':
                        case 'colour':
                            if (empty($item_data['color'])) {
                                $item_data['color'] = ucfirst(strtolower(sanitize_text_field($attr_value)));
                            }
                            break;
                        case 'size':
                            if (empty($item_data['size'])) {
                                $item_data['size'] = sanitize_text_field($attr_value);
                            }
                            break;
                        case 'fitting':
                            if (empty($item_data['fitting'])) {
                                $item_data['fitting'] = ucwords(strtolower(sanitize_text_field($attr_value)));
                            }
                            break;
                    }
                }
            }

            if ($product->get_parent_id()) {
                $parent_product = wc_get_product($product->get_parent_id());
                if ($parent_product) {
                    $product_attributes = $parent_product->get_attributes();
                    foreach ($product_attributes as $attr_name => $attribute) {
                        $clean_attr_name = str_replace('pa_', '', $attr_name);

                        switch (strtolower($clean_attr_name)) {
                            case 'color':
                            case 'colour':
                                if (empty($item_data['color']) && $attribute->is_variation()) {
                                    $selected_value = $product->get_attribute($attr_name);
                                    if ($selected_value) {
                                        $item_data['color'] = ucfirst(strtolower(sanitize_text_field($selected_value)));
                                    }
                                }
                                break;
                            case 'size':
                                if (empty($item_data['size']) && $attribute->is_variation()) {
                                    $selected_value = $product->get_attribute($attr_name);
                                    if ($selected_value) {
                                        $item_data['size'] = sanitize_text_field($selected_value);
                                    }
                                }
                                break;
                            case 'fitting':
                                if (empty($item_data['fitting']) && $attribute->is_variation()) {
                                    $selected_value = $product->get_attribute($attr_name);
                                    if ($selected_value) {
                                        $item_data['fitting'] = ucwords(strtolower(sanitize_text_field($selected_value)));
                                    }
                                }
                                break;
                        }
                    }
                }
            }

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
            update_option('ehx_wc_product_last_sync', current_time('mysql'));
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

        $current_page = get_option('ehx_wc_current_page', 1);
        $per_page = 100;

        // Build API URL with pagination parameters
        $api_url = add_query_arg(array(
            'page' => $current_page,
            'per_page' => $per_page,
            'express' => 'true' // Filter for express products if API supports it
        ), $endpoint);

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $api_url,
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
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($error) {
            return array('success' => false, 'message' => 'cURL Error: ' . $error);
        }

        if ($http_code !== 200) {
            return array('success' => false, 'message' => 'HTTP Error: ' . $http_code);
        }

        $response_data = json_decode($data, true);

        if (!$response_data || !isset($response_data['data'])) {
            return array('success' => false, 'message' => 'Invalid API response');
        }
        if (isset($response_data['total'])) {
            update_option('ehx_wc_product_total_number', $response_data['total']);
        } elseif (isset($response_data['meta']['total'])) {
            update_option('ehx_wc_product_total_number', $response_data['meta']['total']);
        }

        $products_data = $response_data['data'];

        // If no products returned, we've reached the end
        if (empty($products_data)) {
            // Reset for next complete sync
            update_option('ehx_wc_current_page', 1);
            return array('success' => false, 'message' => 'No more products to sync');
        }

        $created_count = 0;
        $updated_count = 0;
        $errors = array();
        $skipped_count = 0;

        foreach ($products_data as $product_data) {
            // Double-check express filter if API doesn't support it
            if (empty($product_data['express']) || $product_data['express'] !== true) {
                $skipped_count++;
                continue;
            }

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

        if ($created_count > 0 || $updated_count > 0) {
            $current_stored = get_option('ehx_wc_product_stored_number', 0);
            $new_stored = $current_stored + $created_count + $updated_count;
            update_option('ehx_wc_product_stored_number', $new_stored);
        }

        // Update page number for next batch
        update_option('ehx_wc_current_page', $current_page + 1);

        $total_processed = ($current_page - 1) * $per_page + count($products_data);

        $message = sprintf(
            'Page %d sync completed. Created: %d, Updated: %d, Skipped: %d, Errors: %d (Total processed so far: %d)',
            $current_page,
            $created_count,
            $updated_count,
            $skipped_count,
            count($errors),
            $total_processed
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
                'skipped' => $skipped_count,
                'errors' => $errors,
                'current_page' => $current_page,
                'total_processed' => $total_processed
            )
        );
    }

    /**
     * AJAX handler for resetting product sync
     */
    public function reset_product_sync_ajax()
    {
        check_ajax_referer('reset_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Reset the current page to 1
        update_option('ehx_wc_current_page', 1);

        // Reset sync stats
        update_option('ehx_wc_product_stored_number', 0);
        update_option('ehx_wc_product_total_number', 0);

        // Clear last sync time
        delete_option('ehx_wc_product_last_sync');

        wp_send_json_success(array(
            'message' => 'Product sync has been reset. Next sync will start from page 1.'
        ));
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