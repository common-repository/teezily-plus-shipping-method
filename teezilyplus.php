<?php

/*
 * Plugin Name: Teezily Plus Shipping Method
 * Plugin URI: https://wordpress.org/plugins/teezilyplus-for-woocommerce/
 * Description: Calculate live shipping rates based on actual Teezily Plus shipping costs. This version of the Plugin is an Alpha version. There might be a small difference in the shipping prices displayed on WooCommerce and the final price. Some shipping prices for non-Teezily product could be overridden
 * Version: 0.1.0
 * Author: Teezily
 * Author URI: https://plus.teezily.com
 */
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))) ) {

    function teezilyplus_shipping_method_init() {
        if(!class_exists('Teezilyplus_Shipping_Method')) {
            require_once 'includes/teezilyplus-shipping-method.php';

            new Teezilyplus_Shipping_Method();
        }
    }

    add_action('woocommerce_shipping_init', 'teezilyplus_shipping_method_init');
}
