<?php
/**
 * Plugin Name: Origin Filter for WooCommerce
 * Plugin URI:  https://github.com/georgosabdulnour/origin-filter-for-woocommerce
 * Description: Adds a filter to WooCommerce orders to filter by order origin and displays total sales for the filtered origin and month.
 * Version:     1.0
 * Author:      Georgos Abdulnour
 * Author URI: https://github.com/georgosabdulnour
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Adds a dropdown to filter orders by origin on the orders page.
 */
add_action('restrict_manage_posts', 'filter_orders_by_origin');

function filter_orders_by_origin()
{
    global $typenow;

    if ('shop_order' === $typenow) {
        $nonce = wp_create_nonce('filter_orders_by_origin_nonce');
        $current_origin = isset($_GET['order_origin']) ? sanitize_text_field($_GET['order_origin']) : '';

        echo '<input type="hidden" name="filter_orders_by_origin_nonce" value="' . esc_attr($nonce) . '">';

        // Retrieve origins from the database with caching
        $cache_key = 'origin_filter_origins';
        $cache_group = 'origin_filter';
        $origins = wp_cache_get($cache_key, $cache_group);

        if (false === $origins) {
            global $wpdb;
            
            // Prepare the query with proper placeholders
            $query = "
                SELECT DISTINCT pm.meta_value as origin
                FROM {$wpdb->prefix}postmeta pm
                INNER JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id
                WHERE pm.meta_key = %s
                AND p.post_type = %s
                AND pm.meta_value != ''
            ";
            
            // Execute the query
            $origins = $wpdb->get_col($wpdb->prepare($query, '_wc_order_attribution_utm_source', 'shop_order'));
        
            // Cache the results
            wp_cache_set($cache_key, $origins, $cache_group, 12 * HOUR_IN_SECONDS);
        }
        
        // At this point, $origins should contain the dropdown values
        
        
        
        

        // Display the origin filter dropdown
        ?>
        <select name="order_origin" id="order_origin">
            <option value=""><?php esc_html_e('All Origins', 'woocommerce');?></option>
            <?php
            foreach ($origins as $origin) {
                $selected_attr = selected($current_origin, $origin, false);
                echo '<option value="' . esc_attr($origin) . '" ' . esc_attr($selected_attr) . '>' . esc_html($origin) . '</option>';
            }
            ?>
        </select>
        <?php
    }
}

/**
 * Modifies the query to filter orders by origin.
 *
 * @param WP_Query $query The current query object.
 */
add_action('pre_get_posts', 'filter_orders_by_origin_query');

function filter_orders_by_origin_query($query)
{
    global $typenow, $pagenow;

    if ('shop_order' === $typenow && 'edit.php' === $pagenow && $query->is_main_query()) {
        if (isset($_GET['order_origin']) && !empty($_GET['order_origin']) && isset($_GET['filter_orders_by_origin_nonce']) && wp_verify_nonce($_GET['filter_orders_by_origin_nonce'], 'filter_orders_by_origin_nonce')) {
            $meta_query = array(
                array(
                    'key' => '_wc_order_attribution_utm_source',
                    'value' => sanitize_text_field($_GET['order_origin']),
                    'compare' => '=',
                ),
            );
            $query->set('meta_query', $meta_query);
        }
    }
}

/**
 * Displays total sales for the filtered origin and month on the admin orders page.
 */
add_action('admin_notices', 'display_total_sales_by_origin_and_month');

function display_total_sales_by_origin_and_month()
{
    global $pagenow, $typenow;

    if (!current_user_can('manage_woocommerce') || 'edit.php' !== $pagenow || 'shop_order' !== $typenow) {
        return;
    }

    if (isset($_GET['order_origin']) && !empty($_GET['order_origin']) && isset($_GET['filter_orders_by_origin_nonce']) && wp_verify_nonce($_GET['filter_orders_by_origin_nonce'], 'filter_orders_by_origin_nonce')) {
        $origin = sanitize_text_field($_GET['order_origin']);
        $order_statuses = array('wc-completed', 'wc-processing');

        // Initialize query arguments
        $args = array(
            'post_type' => 'shop_order',
            'meta_query' => array(
                array(
                    'key' => '_wc_order_attribution_utm_source',
                    'value' => $origin,
                    'compare' => '=',
                ),
            ),
            'posts_per_page' => -1,
            'post_status' => $order_statuses,
        );

        // Add date query if month is specified
        if (isset($_GET['m']) && !empty($_GET['m'])) {
            $yearmonth = sanitize_text_field($_GET['m']);
            if (preg_match('/^\d{6}$/', $yearmonth)) {
                $year = substr($yearmonth, 0, 4);
                $month = substr($yearmonth, 4, 2);

                $args['date_query'] = array(
                    array(
                        'year' => $year,
                        'monthnum' => $month,
                    ),
                );
            }
        }

        // Perform the query
        $query = new WP_Query($args);
        $orders = $query->posts;

        // Calculate the total sales
        $total_sales = 0;
        foreach ($orders as $order_post) {
            $order = wc_get_order($order_post->ID);
            if ($order) {
                $total_sales += $order->get_total();
            }
        }
        $total_sales = wc_price($total_sales);

        if (!empty($orders)) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php
                // Translators: %1$s is the origin filter applied, %2$s is the total sales amount
                $message = sprintf(__('Origin Filter applied successfully. Total of %1$s: <b>%2$s</b>', 'woocommerce'), esc_html($origin), $total_sales);
                echo wp_kses_post($message);
                ?></p>
            </div>
            <?php
        } else {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php
                // Translators: %s is the origin filter applied
                echo wp_kses_post(sprintf(__('No orders found for %s', 'woocommerce'), esc_html($origin)));
                ?></p>
            </div>
            <?php
        }
    }
}

/**
 * Saves the order origin when the order is created.
 *
 * @param int $order_id The order ID.
 */
add_action('woocommerce_checkout_update_order_meta', 'save_order_origin');

function save_order_origin($order_id)
{
    if (isset($_POST['order_origin'])) {
        $order_origin = sanitize_text_field($_POST['order_origin']);
        if (!empty($order_origin)) {
            update_post_meta($order_id, '_wc_order_attribution_utm_source', $order_origin);
        }
    }
}
?>
