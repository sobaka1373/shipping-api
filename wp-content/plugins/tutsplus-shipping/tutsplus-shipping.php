<?php

/**
 * Plugin Name: TutsPlus Shipping
 * Description: Custom Shipping Method for WooCommerce
 * Version: 1.0.0
 * License: GPL-3.0+
 * Text Domain: tutsplus
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

use Shuchkin\SimpleXLSX;
require_once "simplexlsx-master/src/SimpleXLSX.php";
/*
 * Check if WooCommerce is active
 */
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

    function tutsplus_shipping_method() {
        if ( ! class_exists( 'TutsPlus_Shipping_Method' ) ) {
            class TutsPlus_Shipping_Method extends WC_Shipping_Method {

                /**
                 * Constructor for your shipping class
                 *
                 * @access public
                 * @return void
                 */
                public function __construct() {
                    $this->id                 = 'tutsplus';
                    $this->method_title       = __( 'TutsPlus Shipping', 'tutsplus' );
                    $this->method_description = __( 'Custom Shipping Method for TutsPlus', 'tutsplus' );

                    $this->init();

                    $this->enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'yes';
                    $this->title = isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'TutsPlus Shipping', 'tutsplus' );
                }

                /**
                 * Init your settings
                 *
                 * @access public
                 * @return void
                 */
                function init() {
                    // Load the settings API
                    $this->init_form_fields();
                    $this->init_settings();

                    // Save settings in admin if you have any defined
                    add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
                }

                /**
                 * Define settings field for this shipping
                 * @return void
                 */
                function init_form_fields() {

                    $this->form_fields = array(
                        'enabled' => array(
                            'title' => __( 'Enable', 'tutsplus' ),
                            'type' => 'checkbox',
                            'description' => __( 'Enable this shipping.', 'tutsplus' ),
                            'default' => 'yes'
                        ),
                        'title' => array(
                            'title' => __( 'Title', 'tutsplus' ),
                            'type' => 'text',
                            'description' => __( 'Title to be display on site', 'tutsplus' ),
                            'default' => __( 'TutsPlus Shipping', 'tutsplus' )
                        ),
                        'key' => array(
                            'title' => __( 'API key', 'tutsplus' ),
                            'type' => 'text',
                            'description' => __( 'Google API', 'tutsplus' ),
                        )
                    );
                }

                /**
                 *
                 * @access public
                 * @param mixed $package
                 * @return void
                 */
                public function calculate_shipping( $package = array() ) {
                    $user_id = $package['user']['ID'];
                    $billing_postcode = get_user_meta( $user_id, 'billing_postcode', true);
                    $billing_address_1 = get_user_meta( $user_id, 'billing_address_1', true );
                    $billing_city = get_user_meta( $user_id, 'billing_city', true );

                    $shipping_postcode = get_user_meta( $user_id, 'shipping_postcode', true);
                    $shipping_address_1 = get_user_meta( $user_id, 'shipping_address_1', true );
                    $shipping_city = get_user_meta( $user_id, 'shipping_city', true );

                    if ($shipping_postcode) {
                        $user_address = $shipping_address_1 ." ". $shipping_postcode ." ".
                            $shipping_city;
                    } else {
                        $user_address = $billing_address_1 ." ". $billing_postcode ." ".
                            $billing_city;
                    }

                    add_filter('acf/settings/current_language', function(){return 'de';});
                    $attachment_id = get_field('file_price',  'option', false);


                    if (!$attachment_id) {
                        return;
                    }

                    $file_id = (int)($attachment_id);
                    if (!$file_id) {
                        return;
                    }
                    $matrix = processFile($file_id);

                    $oil_count = null;
                    $fish_count = null;
                    $cost_oil = null;
                    $cost_fish = null;
                    $fish_cat_name = null;
                    $oil_cat_name = null;

                    foreach ($package['contents'] as $product) {
                        $product_cart = wc_get_product( $product['product_id'] );
                        $product_cart_categories = $product_cart->get_category_ids();
                        $cat = get_term_by( 'id', $product_cart_categories[0], 'product_cat' );
                        if ($cat->slug === 'oil' || $cat->slug === 'oil-en' || $cat->slug === 'petrole') { // oil
                            $oil_cat_name = $cat->name;
                            $oil_adr = get_field('oil_address',  'option', false);
                            $oil_count = $oil_count + $product['quantity'];
                            $distance = getDistance($oil_adr, $user_address);
                            if (!$distance || $distance === "This API project is not authorized to use this API.") {
                                return;
                            }
                            $distance = str_replace('km', '', $distance);
                            $distance = (int)str_replace(',', '', $distance);

                            $cost_oil = findCurrentCoast($distance, $matrix, $oil_count);
                        } elseif ($cat->slug === 'fisch' || $cat->slug === 'fish' || $cat->slug === 'fisch-fr') { // fish
                            $fish_cat_name = $cat->name;
                            $fish_adr = get_field('fish_address',  'option', false);
                            $fish_count = $fish_count + $product['quantity'];

                            $distance = getDistance($fish_adr, $user_address);
                            if (!$distance || $distance === "This API project is not authorized to use this API.") {
                                return;
                            }
                            $distance = str_replace('km', '', $distance);
                            $distance = (int)str_replace(',', '', $distance);

                            $cost_fish = findCurrentCoast($distance, $matrix, $fish_count);
                        } else {
                            return;
                        }
                    }

                    $full_cost = 0;
                    if ($oil_count) {
                        $full_cost = $full_cost + $cost_oil;
                    }
                    if ($fish_count) {
                        $full_cost = $full_cost + $cost_fish;
                    }

                    $cost_oil = round($cost_oil, 2);
                    $cost_fish = round($cost_fish, 2);

                    if ($oil_count && $fish_count) {
                        $text = __('Shipping', 'woocommerce-pdf-invoices-packing-slips' ) . " $fish_cat_name: €" .
                            $cost_fish . "[br]" . __('Shipping', 'woocommerce-pdf-invoices-packing-slips' ) .
                            " $oil_cat_name: €" . $cost_oil . "[br]" . __('Shipping', 'woocommerce-pdf-invoices-packing-slips' ) . " " ;
                        $rate = array(
                            'id' => $this->id,
                            'label' => $text,
                            'cost' => $full_cost
                        );
                    } else {
                        $rate = array(
                            'id' => $this->id,
                            'label' => $this->title,
                            'cost' => $full_cost
                        );
                    }

                    $this->add_rate( $rate );
                }
            }
        }
    }

    function processFile($file_id) {
        $file = get_attached_file($file_id);
        if ( $xlsx = SimpleXLSX::parseFile($file) ) {
            $dim = $xlsx->dimension();
            $num_cols = $dim[0];
            $num_rows = $dim[1];

            foreach ($xlsx->rows() as $index => $r) {
                for ($i = 0; $i < $num_cols; $i ++) {
                    if( !empty($r[ $i ])) {
                        $matrix[$index][$i] = $r[ $i ];
                    };
                }
            }
        } else {
            echo SimpleXLSX::parseError();
        }
        return $matrix;
    }

    function findCurrentCoast($distance, $matrix, $product_count) {
        foreach ($matrix as $index => $row) {
            if (intval($row[0]) && $distance < $row[0]) {
                $current_row = $row;
                break;
            } elseif(intval($row[0])) {
                $current_row = $row;
            }
        }

        if ($product_count < count($current_row)) {
            return $current_row[$product_count];
        } else {
            return end($current_row);
        }

    }

    function getDistance($addressFrom, $addressTo){
        // Google API key

        $TutsPlus_Shipping_Method = new TutsPlus_Shipping_Method();
        $apiKey = (string) $TutsPlus_Shipping_Method->settings['key'];

        // Change address format
        $formattedAddrFrom    = str_replace(' ', '+', $addressFrom);
        $formattedAddrTo     = str_replace(' ', '+', $addressTo);

        $geocodeFrom = file_get_contents('https://maps.googleapis.com/maps/api/geocode/json?address='.$formattedAddrFrom.'&sensor=false&key='.$apiKey);
        $outputFrom = json_decode($geocodeFrom);
        if(!empty($outputFrom->error_message)){
            return $outputFrom->error_message;
        }

        $geocodeTo = file_get_contents('https://maps.googleapis.com/maps/api/geocode/json?address='.$formattedAddrTo.'&sensor=false&key='.$apiKey);
        $outputTo = json_decode($geocodeTo);
        if(!empty($outputTo->error_message)){
            return $outputTo->error_message;
        }

        $result = file_get_contents('https://maps.googleapis.com/maps/api/distancematrix/json?origins=place_id:'. $outputFrom->results[0]->place_id .'&destinations=place_id:'.$outputTo->results[0]->place_id.'&mode=driving&transit_mode=bus&key='.$apiKey);
        $result = json_decode($result);
        return $result->rows[0]->elements[0]->distance->text; //value
    }

    function add_tutsplus_shipping_method( $methods ) {
        $methods[] = 'TutsPlus_Shipping_Method';
        return $methods;
    }

    add_action( 'woocommerce_shipping_init', 'tutsplus_shipping_method' );
    add_filter( 'woocommerce_shipping_methods', 'add_tutsplus_shipping_method' );
    add_action( 'woocommerce_after_checkout_validation', 'tutsplus_validate_order' , 10 );
    add_action( 'admin_menu', 'menu_item');


    add_action('acf/init', 'my_acf_op_init');
    function my_acf_op_init() {

        // Check function exists.
        if( function_exists('acf_add_options_page') ) {

            // Register options page.
            $option_page = acf_add_options_page(array(
                'page_title'    => __('Shipping price'),
                'menu_title'    => __('Shipping price'),
                'menu_slug'     => 'shipping-general-settings',
                'capability'    => 'edit_posts',
                'redirect'      => false
            ));
        }

        if( function_exists('acf_add_local_field_group') ):

            acf_add_local_field_group(array(
                'key' => 'group_62c2e4bb37c57',
                'title' => 'File price1',
                'fields' => array(
                    array(
                        'key' => 'field_62d15064bc9e4',
                        'label' => 'File Price',
                        'name' => 'file_price',
                        'type' => 'file',
                        'instructions' => '',
                        'required' => 0,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'return_format' => 'array',
                        'library' => 'all',
                        'min_size' => '',
                        'max_size' => '',
                        'mime_types' => '',
                        'wpml_cf_preferences' => 0,
                    ),
                    array(
                        'key' => 'field_62d15086bc9e5',
                        'label' => 'Fish address',
                        'name' => 'fish_address',
                        'type' => 'text',
                        'instructions' => '',
                        'required' => 0,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'wpml_cf_preferences' => 0,
                        'default_value' => '',
                        'placeholder' => '',
                        'prepend' => '',
                        'append' => '',
                        'maxlength' => '',
                    ),
                    array(
                        'key' => 'field_62d1509dbc9e6',
                        'label' => 'Oil address',
                        'name' => 'oil_address',
                        'type' => 'text',
                        'instructions' => '',
                        'required' => 0,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'wpml_cf_preferences' => 0,
                        'default_value' => '',
                        'placeholder' => '',
                        'prepend' => '',
                        'append' => '',
                        'maxlength' => '',
                    ),
                ),
                'location' => array(
                    array(
                        array(
                            'param' => 'options_page',
                            'operator' => '==',
                            'value' => 'shipping-general-settings',
                        ),
                    ),
                ),
                'menu_order' => 0,
                'position' => 'normal',
                'style' => 'default',
                'label_placement' => 'top',
                'instruction_placement' => 'label',
                'hide_on_screen' => '',
                'active' => true,
                'description' => '',
            ));

        endif;
    }

    function my_acf_load_value( $value, $post_id, $field )
    {
        if( $post_id == 'options_de' && !$value ){

            //set the current language to EN temporarily
            add_filter('acf/settings/current_language', function(){return 'de';});

            $value = get_field($field['name'], 'options', false);

            //set it back to DE
            add_filter('acf/settings/current_language', function(){return 'en';});
        }

        return $value;
    }

    add_filter('acf/load_value', 'my_acf_load_value', 20, 3);

    function filter_woocommerce_cart_shipping_method_full_label( $label, $method ) {
        $label = str_replace("[br]", "<br>", $label);
        return $label;
    }
    add_filter( 'woocommerce_cart_shipping_method_full_label', 'filter_woocommerce_cart_shipping_method_full_label', 15, 2 );
}