<?php
/**
 * Plugin Name: Origin Filter for WooCommerce
 * Plugin URI:  https://github.com/georgosabdulnour/origin-filter-for-woocommerce
 * Description: Adds advanced filters for WooCommerce orders, including origin (UTM source) and payment gateway filters.
 * Version:     2.0
 * Text Domain: origin-filter-for-woocommerce
 * Author:      Georgos Abdulnour
 * Author URI:  https://github.com/georgosabdulnour
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if WooCommerce is active.
 */
function waf_check_woocommerce_active() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'waf_woocommerce_missing_notice');
        return false;
    }
    return true;
}

/**
 * Display WooCommerce missing notice.
 */
function waf_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p>
            <?php
            /* translators: %s: Plugin name */
            printf(
                esc_html__('Origin Filter for WooCommerce requires WooCommerce to be installed and active. Please install and activate WooCommerce to use %s.', 'origin-filter-for-woocommerce'),
                '<strong>' . esc_html__('Origin Filter for WooCommerce', 'origin-filter-for-woocommerce') . '</strong>'
            );
            ?>
        </p>
    </div>
    <?php
}

/**
 * Initialize the plugin after WooCommerce is loaded.
 */
function waf_initialize_plugin() {
    // Check WooCommerce before loading the plugin.
    if (!waf_check_woocommerce_active()) {
        return;
    }

    // Load the rest of the plugin functionality.
    add_action('restrict_manage_posts', 'waf_add_order_filters');
    add_filter('request', 'waf_filter_orders_request');
    add_action('admin_notices', 'waf_display_filter_results');
    add_action('admin_notices', 'waf_add_donation_button');
}

// Hook the initialization function to 'plugins_loaded'.
add_action('plugins_loaded', 'waf_initialize_plugin');

/**
 * Add filters to the orders page.
 */
function waf_add_order_filters() {
    global $typenow;

    // Ensure we are on the shop_order page.
    if ($typenow !== 'shop_order') {
        return;
    }

    // Get unique UTM sources and payment gateways.
    $utm_sources = waf_get_unique_utm_sources();
    $gateways = waf_get_unique_payment_gateways();

    // UTM source filter.
    ?>
    <select name="utm_source" id="utm_source">
        <option value=""><?php esc_html_e('All UTM Sources', 'origin-filter-for-woocommerce'); ?></option>
        <?php foreach ($utm_sources as $source) : ?>
            <option value="<?php echo esc_attr($source); ?>" <?php selected($_GET['utm_source'], $source); ?>><?php echo esc_html($source); ?></option>
        <?php endforeach; ?>
    </select>
    <?php

    // Payment gateway filter.
    ?>
    <select name="payment_gateway" id="payment_gateway">
        <option value=""><?php esc_html_e('All Payment Gateways', 'origin-filter-for-woocommerce'); ?></option>
        <?php foreach ($gateways as $gateway) : ?>
            <option value="<?php echo esc_attr($gateway); ?>" <?php selected($_GET['payment_gateway'], $gateway); ?>><?php echo esc_html($gateway); ?></option>
        <?php endforeach; ?>
    </select>
    <?php
}

/**
 * Filter orders based on selected filters.
 */
function waf_filter_orders_request($vars) {
    global $typenow;

    if ($typenow !== 'shop_order') {
        return $vars;
    }

    // Initialize meta query.
    $meta_query = array();

    // Filter by UTM source.
    if (!empty($_GET['utm_source'])) {
        $meta_query[] = array(
            'key' => '_wc_order_attribution_utm_source',
            'value' => sanitize_text_field($_GET['utm_source']),
            'compare' => '='
        );
    }

    // Filter by payment gateway.
    if (!empty($_GET['payment_gateway'])) {
        $meta_query[] = array(
            'key' => '_payment_method_title',
            'value' => sanitize_text_field($_GET['payment_gateway']),
            'compare' => '='
        );
    }

    // Add meta query to the request.
    if (!empty($meta_query)) {
        $vars['meta_query'] = $meta_query;
    }

    // Filter by month (WooCommerce default filter).
    if (!empty($_GET['m'])) {
        $vars['m'] = sanitize_text_field($_GET['m']);
    }

    return $vars;
}

/**
 * Display filter results message.
 */
function waf_display_filter_results() {
    global $pagenow, $typenow;

    // Ensure we are on the shop_order page.
    if (!current_user_can('manage_woocommerce') || 'edit.php' !== $pagenow || 'shop_order' !== $typenow) {
        return;
    }

    // Check if any filter is applied.
    $is_filter_applied = !empty($_GET['utm_source']) || !empty($_GET['payment_gateway']) || !empty($_GET['m']);
    if (!$is_filter_applied) {
        return;
    }

    // Get applied filters.
    $applied_filters = array();
    if (!empty($_GET['utm_source'])) {
        $applied_filters[] = 'UTM Source: ' . sanitize_text_field($_GET['utm_source']);
    }
    if (!empty($_GET['payment_gateway'])) {
        $applied_filters[] = 'Payment Gateway: ' . sanitize_text_field($_GET['payment_gateway']);
    }
    if (!empty($_GET['m'])) {
        $yearmonth = sanitize_text_field($_GET['m']);
        if (preg_match('/^\d{6}$/', $yearmonth)) {
            $year = substr($yearmonth, 0, 4);
            $month = substr($yearmonth, 4, 2);
            $applied_filters[] = 'Month: ' . $year . '-' . $month; // Add hyphen between year and month.
        }
    }

    // Initialize query arguments.
    $args = array(
        'post_type' => 'shop_order',
        'posts_per_page' => -1,
        'post_status' => array('wc-processing', 'wc-completed'),
    );

    // Add UTM source filter.
    if (!empty($_GET['utm_source'])) {
        $args['meta_query'][] = array(
            'key' => '_wc_order_attribution_utm_source',
            'value' => sanitize_text_field($_GET['utm_source']),
            'compare' => '=',
        );
    }

    // Add payment gateway filter.
    if (!empty($_GET['payment_gateway'])) {
        $args['meta_query'][] = array(
            'key' => '_payment_method_title',
            'value' => sanitize_text_field($_GET['payment_gateway']),
            'compare' => '=',
        );
    }

    // Add month filter.
    if (!empty($_GET['m'])) {
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

    // Perform the query.
    $query = new WP_Query($args);
    $orders = $query->posts;

    // Calculate total orders and total amount.
    $total_orders = 0;
    $total_amount = 0;
    $currency = get_woocommerce_currency();

    foreach ($orders as $order_post) {
        $order = wc_get_order($order_post->ID);
        if ($order) {
            $total_orders++;
            $total_amount += (float) $order->get_total();
        }
    }

    // Display the message.
    if (!empty($applied_filters)) {
        $filters_text = implode(', ', $applied_filters);
        $message = sprintf(
            /* translators: %1$s: Applied filters, %2$d: Total orders, %3$s: Currency, %4$s: Total amount */
            __('Filters applied successfully: %1$s, %2$d orders, with a total amount of %3$s %4$s.', 'origin-filter-for-woocommerce'),
            $filters_text,
            $total_orders,
            $currency,
            number_format($total_amount, 2)
        );
        ?>
        <div class="notice notice-success">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }
}

/**
 * Add donation button to the admin dashboard.
 */
function waf_add_donation_button() {
    // Only show the button to users with the right capability.
    if (!current_user_can('manage_options')) {
        return;
    }

    // Donation link (replace with your donation page URL).
    $donation_url = 'https://buymeacoffee.com/georgos';

    // Button HTML and CSS.
    ?>
    <style>
        .waf-donation-notice {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px;
            background-color: #f8f9fa;
            border: none;
            border-left: 4px solid #c8d7e1;
        }
        .waf-donation-notice p {
            margin: 0;
            font-size: 14px;
            color: #1d2327;
        }
        .waf-donation-button {
            background-color: #4CAF50; /* Green background */
            color: white; /* White text */
            padding: 8px 16px; /* Padding */
            text-align: center; /* Center text */
            text-decoration: none; /* Remove underline */
            font-size: 14px; /* Font size */
            border-radius: 4px; /* Rounded corners */
            transition: background-color 0.3s ease; /* Smooth hover effect */
        }
        .waf-donation-button:hover {
            background-color: #45a049; /* Darker green on hover */
        }
    </style>
    <div class="notice notice-info waf-donation-notice">
        <p>
            <?php
            /* translators: %s: Plugin name */
            esc_html_e('Thank you for using Origin Filter for WooCommerce! If you find it helpful, please consider supporting its development.', 'origin-filter-for-woocommerce');
            ?>
        </p>
        <a href="<?php echo esc_url($donation_url); ?>" class="waf-donation-button" target="_blank">
            <?php esc_html_e('Donate Now', 'origin-filter-for-woocommerce'); ?>
        </a>
    </div>
    <?php
}

/**
 * Get unique UTM sources with caching.
 */
function waf_get_unique_utm_sources() {
    global $wpdb;

    // Check if cached data exists.
    $utm_sources = wp_cache_get('waf_unique_utm_sources', 'origin-filter-for-woocommerce');

    if (false === $utm_sources) {
        // Get unique UTM sources from the meta key '_wc_order_attribution_utm_source'.
        $utm_sources = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wc_order_attribution_utm_source' AND meta_value != ''");

        // Cache the results for 12 hours.
        wp_cache_set('waf_unique_utm_sources', $utm_sources, 'origin-filter-for-woocommerce', 12 * HOUR_IN_SECONDS);
    }

    return $utm_sources ? $utm_sources : array();
}

/**
 * Get unique payment gateways with caching.
 */
function waf_get_unique_payment_gateways() {
    global $wpdb;

    // Check if cached data exists.
    $gateways = wp_cache_get('waf_unique_payment_gateways', 'origin-filter-for-woocommerce');

    if (false === $gateways) {
        // Get unique payment gateways from the meta key '_payment_method_title'.
        $gateways = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_payment_method_title' AND meta_value != ''");

        // Cache the results for 12 hours.
        wp_cache_set('waf_unique_payment_gateways', $gateways, 'origin-filter-for-woocommerce', 12 * HOUR_IN_SECONDS);
    }

    return $gateways ? $gateways : array();
}