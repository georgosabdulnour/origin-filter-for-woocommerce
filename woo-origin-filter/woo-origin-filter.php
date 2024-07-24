<?php
/**
 * Plugin Name: Woo Origin Filter
 * Plugin URI:  https://github.com/georgosabdulnour/woo-origin-filter
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

function filter_orders_by_origin() {
    global $typenow, $wpdb;

    if ('shop_order' === $typenow) {
        $origins = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT meta_value
            FROM {$wpdb->prefix}postmeta
            WHERE meta_key = %s
        ", '_wc_order_attribution_utm_source'));

        $current_origin = isset($_GET['order_origin']) ? sanitize_text_field($_GET['order_origin']) : '';

        ?>
        <select name="order_origin" id="order_origin">
            <option value=""><?php _e('All Origins', 'woocommerce'); ?></option>
            <?php
            foreach ($origins as $origin) {
                $selected = selected($current_origin, $origin, false);
                echo '<option value="' . esc_attr($origin) . '" ' . $selected . '>' . esc_html($origin) . '</option>';
            }
            ?>
        </select>
        <?php
    }
}