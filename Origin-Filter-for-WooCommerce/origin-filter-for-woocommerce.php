<?php
/**
 * Plugin Name: Origin Filter for Woocommerce
 * Plugin URI:  https://github.com/georgosabdulnour/origin-filter-for-woocommerce
 * Description: Adds a filter to WooCommerce orders to filter by order origin and displays total sales for the filtered origin.
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
        $nonce = wp_create_nonce('filter_orders_by_origin_nonce'); // Create a nonce
        $current_origin = isset($_GET['order_origin']) ? sanitize_text_field($_GET['order_origin']) : '';

        // Output nonce as a hidden input
        echo '<input type="hidden" name="filter_orders_by_origin_nonce" value="' . esc_attr($nonce) . '">';

        // Use caching to avoid direct database calls
        $cache_key = 'origin_filter_origins';
        $cache_group = 'origin_filter';
        $origins = wp_cache_get($cache_key, $cache_group);

        if (false === $origins) {
            global $wpdb;

            $query = "
                SELECT DISTINCT pm.meta_value as origin
                FROM {$wpdb->prefix}postmeta pm
                INNER JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id
                WHERE pm.meta_key = '_wc_order_attribution_utm_source'
                AND p.post_type = 'shop_order'
                AND pm.meta_value != ''
            ";

            $origins = $wpdb->get_col($query);
            $origins = array_unique($origins);
            wp_cache_set($cache_key, $origins, $cache_group, 12 * HOUR_IN_SECONDS);
        }

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
 * Define a function to get order total from cache or database
 */
function get_order_total($order_id)
{
    $order_total = get_post_meta($order_id, '_order_total', true);
    return $order_total;
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

    if (
        'shop_order' === $typenow &&
        'edit.php' === $pagenow &&
        isset($_GET['order_origin']) &&
        !empty($_GET['order_origin']) &&
        isset($_GET['filter_orders_by_origin_nonce']) &&
        wp_verify_nonce($_GET['filter_orders_by_origin_nonce'], 'filter_orders_by_origin_nonce') // Verify the nonce
    ) {
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

/**
 * Displays total sales for the filtered origin on the admin orders page.
 */
add_action('admin_notices', 'display_total_sales_by_origin');

function display_total_sales_by_origin()
{
    global $pagenow, $typenow;

    if (!current_user_can('manage_woocommerce') || 'edit.php' !== $pagenow || 'shop_order' !== $typenow) {
        return;
    }

    if (isset($_GET['order_origin']) && !empty($_GET['order_origin']) && isset($_GET['filter_orders_by_origin_nonce']) && wp_verify_nonce($_GET['filter_orders_by_origin_nonce'], 'filter_orders_by_origin_nonce')) {
        $origin = sanitize_text_field($_GET['order_origin']);

        // Define the order statuses you want to include
        $order_statuses = array('wc-completed', 'wc-processing'); // Add more statuses if needed

        $args = array(
            'post_type' => 'shop_order',
            'meta_query' => array(
                array(
                    'key' => '_wc_order_attribution_utm_source',
                    'value' => $origin,
                    'compare' => '=',
                ),
            ),
            'posts_per_page' => -1, // Fetch all orders without pagination
            'post_status' => $order_statuses, // Include only the specified statuses
        );

        $query = new WP_Query($args);
        $orders = $query->posts;

        $order_ids = array();
        foreach ($orders as $order) {
            $order_ids[] = $order->ID;
        }

        error_log('Order IDs for origin ' . $origin . ': ' . print_r($order_ids, true));

        if (!empty($order_ids)) {
            $total_sales = 0;
            foreach ($order_ids as $order_id) {
                // Ensure the order total is fetched correctly
                $order = wc_get_order($order_id);
                if ($order) {
                    $order_total = $order->get_total();
                    $total_sales += floatval($order_total);
                }
            }
            $total_sales = wc_price($total_sales);

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
    // Check nonce for security
    if (!isset($_POST['save_order_origin_nonce']) || !wp_verify_nonce($_POST['save_order_origin_nonce'], 'save_order_origin_action')) {
        return;
    }

    if (isset($_POST['order_origin'])) {
        $order_origin = sanitize_text_field($_POST['order_origin']);

        if (!empty($order_origin)) {
            update_post_meta($order_id, '_wc_order_attribution_utm_source', $order_origin);
        } else {
            error_log('Order origin is empty');
        }
    } else {
        error_log('Order origin is not set');
    }
}

?>
