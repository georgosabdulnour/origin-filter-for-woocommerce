<?php
/**
 * Plugin Name: of
 * Plugin URI:  https://github.com/georgosabdulnour/origin-filter-for-woocommerce
 * Description: Adds a filter to WooCommerce orders to filter by order origin and displays total sales for the filtered origin and month.
 * Version:     1.0
 * Text Domain: origin-filter-for-woocommerce
 * Author:      Georgos Abdulnour
 * Author URI: https://github.com/georgosabdulnour
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Adds a dropdown to filter orders by origin on the orders page.
 */
add_action('restrict_manage_posts', 'ofwc_filter_orders_by_origin');

function ofwc_filter_orders_by_origin() {
    global $typenow;

    if ('shop_order' === $typenow) {
        $nonce = wp_create_nonce('ofwc_filter_orders_by_origin_nonce');
        $current_origin = '';
        if (isset($_GET['order_origin']) && isset($_GET['ofwc_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['ofwc_nonce'])), 'ofwc_nonce_action')) {
            $current_origin = sanitize_text_field($_GET['order_origin']);
        }

        echo '<input type="hidden" name="filter_orders_by_origin_nonce" value="' . esc_attr($nonce) . '">';

        global $wpdb;

        $cache_key = 'ofwc_origins_cache';
        $origins = wp_cache_get($cache_key);

        if (false === $origins) {
            $origins = $wpdb->get_col(
                $wpdb->prepare("SELECT DISTINCT meta_value FROM {$wpdb->prefix}postmeta pm INNER JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id WHERE meta_key = %s AND p.post_type = %s AND pm.meta_value != ''",
                    '_wc_order_attribution_utm_source',
                    'shop_order'
                )
            );

            wp_cache_set($cache_key, $origins, 'ofwc_cache_group', 3600); // cache for 1 hour
        }

        // Display the origin filter dropdown
        ?>
        <select name="order_origin" id="order_origin">
            <option value=""><?php echo 'All Origins'; ?></option>
            <?php foreach ($origins as $origin) : ?>
                <option value="<?php echo esc_attr($origin); ?>"><?php echo esc_html($origin); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }
}

/**
 * Modifies the query to filter orders by origin.
 *
 * @param WP_Query $query The current query object.
 */
add_action('pre_get_posts', 'ofwc_filter_orders_by_origin_query');

function ofwc_filter_orders_by_origin_query($query)
{
    global $typenow, $pagenow;

    if ('shop_order' === $typenow && 'edit.php' === $pagenow && $query->is_main_query()) {
        if (isset($_GET['order_origin']) && !empty($_GET['order_origin']) && isset($_GET['filter_orders_by_origin_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['filter_orders_by_origin_nonce'])), 'ofwc_filter_orders_by_origin_nonce')) {
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
add_action('admin_notices', 'ofwc_display_total_sales_by_origin_and_month');

function ofwc_display_total_sales_by_origin_and_month()
{
    global $pagenow, $typenow;

    if (!current_user_can('manage_woocommerce') || 'edit.php' !== $pagenow || 'shop_order' !== $typenow) {
        return;
    }

    if (isset($_GET['order_origin']) && !empty($_GET['order_origin']) && isset($_GET['filter_orders_by_origin_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['filter_orders_by_origin_nonce'])), 'ofwc_filter_orders_by_origin_nonce')) {
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
                $message = sprintf(__('Origin Filter applied successfully. Total of %1$s: <b>%2$s</b>', 'origin-filter-for-woocommerce'), esc_html($origin), $total_sales);
                echo wp_kses_post($message);
                ?></p>
            </div>
            <?php
        } else {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php
                // Translators: %s is the origin filter applied
                echo wp_kses_post(sprintf(__('No orders found for %s', 'origin-filter-for-woocommerce'), esc_html($origin)));
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
add_action('woocommerce_checkout_update_order_meta', 'ofwc_save_order_origin');

function ofwc_save_order_origin($order_id) {
    if (
        isset($_POST['ofwc_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ofwc_nonce'])), 'ofwc_nonce_action')
    ) {
        if (isset($_POST['order_origin'])) {
            $order_origin = sanitize_text_field($_POST['order_origin']);
            if (!empty($order_origin)) {
                update_post_meta($order_id, '_wc_order_attribution_utm_source', $order_origin);
            }
        }
    }
}
?>
