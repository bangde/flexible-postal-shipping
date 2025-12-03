<?php
/**
 * Plugin Name: Asia Postal & Table Rate Shipping (Fixed)
 * Plugin URI:  https://example.com/asia-post-shipping
 * Description: A specialized shipping method for Asian Postal Carriers (Japan Post, China Post, Pos Indonesia, etc.) featuring a tree-table rate logic engine.
 * Version:     2.9.1
 * Author:      S.J Consulting Group Asia
 * Author URI:  https://google.com
 * Text Domain: Asia-Postal-Shipping
 * Domain Path: /languages
 *
 * WC requires at least: 3.0.0
 * WC tested up to: 8.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Declare compatibility with HPOS
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/**
 * Enqueue Scripts
 */
add_action( 'admin_enqueue_scripts', 'asia_post_admin_scripts' );
function asia_post_admin_scripts() {
    wp_enqueue_script( 'jquery-ui-sortable' );
    // Force load WC Enhanced Select for Select2 support
    if ( function_exists( 'WC' ) ) {
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_enqueue_style( 'woocommerce_admin_styles' );
    }
}

/**
 * Admin Notices
 */
add_action( 'admin_notices', 'asia_post_admin_notices' );
function asia_post_admin_notices() {
    if ( isset( $_GET['asia_import'] ) ) {
        if ( $_GET['asia_import'] === 'success' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '✅ Shipping rules imported successfully.', 'Asia-Postal-Shipping' ) . '</p></div>';
        } elseif ( $_GET['asia_import'] === 'error' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( '❌ Error: File upload failed or invalid file.', 'Asia-Postal-Shipping' ) . '</p></div>';
        }
    }
}

/**
 * Helper: CSV Headers
 */
function asia_shipping_get_csv_headers() {
    return array(
        'Label', 'Country', 'State', 'Postcode', 'Shipping Class',
        'Est. Delivery', 'Min Weight', 'Max Weight', 'Min Total', 'Max Total',
        'Min Qty', 'Max Qty', 'Base Weight', 'Base Cost', 'Per Kg', 'Enabled'
    );
}

/**
 * Helper: Carrier Database
 */
function asia_get_carriers() {
    return array(
        'East Asia' => array(
            'china_post'    => array('code' => 'CN', 'label' => __( '🇨🇳 China Post', 'Asia-Postal-Shipping' )),
            'chunghwa_post' => array('code' => 'TW', 'label' => __( '🇹🇼 Chunghwa Post (Taiwan)', 'Asia-Postal-Shipping' )),
            'hongkong_post' => array('code' => 'HK', 'label' => __( '🇭🇰 Hong Kong Post', 'Asia-Postal-Shipping' )),
            'japan_post'    => array('code' => 'JP', 'label' => __( '🇯🇵 Japan Post', 'Asia-Postal-Shipping' )),
            'korea_post_kr' => array('code' => 'KR', 'label' => __( '🇰🇷 Korea Post (South)', 'Asia-Postal-Shipping' )),
            'macau_post'    => array('code' => 'MO', 'label' => __( '🇲🇴 CTT Macau', 'Asia-Postal-Shipping' )),
            'mongol_post'   => array('code' => 'MN', 'label' => __( '🇲🇳 Mongol Post', 'Asia-Postal-Shipping' )),
        ),
        'Southeast Asia' => array(
            'phlpost'       => array('code' => 'PH', 'label' => __( '🇵🇭 PHLPost (Philippines)', 'Asia-Postal-Shipping' )),
            'pos_indonesia' => array('code' => 'ID', 'label' => __( '🇮🇩 Pos Indonesia', 'Asia-Postal-Shipping' )),
            'pos_malaysia'  => array('code' => 'MY', 'label' => __( '🇲🇾 Pos Malaysia', 'Asia-Postal-Shipping' )),
            'singpost'      => array('code' => 'SG', 'label' => __( '🇸🇬 Singapore Post', 'Asia-Postal-Shipping' )),
            'thailand_post' => array('code' => 'TH', 'label' => __( '🇹🇭 Thailand Post', 'Asia-Postal-Shipping' )),
            'vietnam_post'  => array('code' => 'VN', 'label' => __( '🇻🇳 Vietnam Post', 'Asia-Postal-Shipping' )),
        ),
        'South Asia' => array(
            'india_post'    => array('code' => 'IN', 'label' => __( '🇮🇳 India Post', 'Asia-Postal-Shipping' )),
            'pakistan_post' => array('code' => 'PK', 'label' => __( '🇵🇰 Pakistan Post', 'Asia-Postal-Shipping' )),
            'srilanka_post' => array('code' => 'LK', 'label' => __( '🇱🇰 Sri Lanka Post', 'Asia-Postal-Shipping' )),
        ),
        'West Asia & Middle East' => array(
            'emirates_post' => array('code' => 'AE', 'label' => __( '🇦🇪 Emirates Post', 'Asia-Postal-Shipping' )),
            'ptt_turkey'    => array('code' => 'TR', 'label' => __( '🇹🇷 PTT (Turkey)', 'Asia-Postal-Shipping' )),
            'saudi_post'    => array('code' => 'SA', 'label' => __( '🇸🇦 Saudi Post (SPL)', 'Asia-Postal-Shipping' )),
        ),
    );
}

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

    /**
     * Admin Post Actions (Export/Import)
     */
    add_action( 'admin_post_asia_shipping_export', 'asia_shipping_handle_export' );
    add_action( 'admin_post_asia_shipping_import', 'asia_shipping_handle_import' );

    function asia_shipping_handle_export() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'asia_shipping_export_action', 'asia_shipping_nonce' );

        $instance_id = isset($_REQUEST['instance_id']) ? absint($_REQUEST['instance_id']) : 0;
        $option_key = 'woocommerce_asia_post_table_rate_' . $instance_id . '_settings';
        $settings = get_option($option_key);
        $rules = isset($settings['rules']) ? json_decode($settings['rules'], true) : array();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=asia-shipping-rules-' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, asia_shipping_get_csv_headers());

        if ( ! empty( $rules ) ) {
            foreach ( $rules as $rule ) {
                fputcsv($output, array(
                    isset($rule['label']) ? $rule['label'] : '',
                    isset($rule['country']) ? $rule['country'] : '',
                    isset($rule['state']) ? $rule['state'] : '*',
                    isset($rule['postcode']) ? $rule['postcode'] : '*',
                    isset($rule['shipping_class']) ? $rule['shipping_class'] : '*',
                    isset($rule['delivery_time']) ? $rule['delivery_time'] : '',
                    isset($rule['min_weight']) ? $rule['min_weight'] : '',
                    isset($rule['max_weight']) ? $rule['max_weight'] : '',
                    isset($rule['min_total']) ? $rule['min_total'] : '',
                    isset($rule['max_total']) ? $rule['max_total'] : '',
                    isset($rule['min_qty']) ? $rule['min_qty'] : '',
                    isset($rule['max_qty']) ? $rule['max_qty'] : '',
                    isset($rule['base_weight']) ? $rule['base_weight'] : 0,
                    isset($rule['base_cost']) ? $rule['base_cost'] : 0,
                    isset($rule['per_kg']) ? $rule['per_kg'] : 0,
                    isset($rule['enabled']) ? $rule['enabled'] : 'yes',
                ));
            }
        }
        fclose($output);
        exit;
    }

    function asia_shipping_handle_import() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'asia_shipping_import_action', 'asia_shipping_nonce' );

        $instance_id = isset($_REQUEST['instance_id']) ? absint($_REQUEST['instance_id']) : 0;
        $redirect_url = isset($_REQUEST['redirect_url']) ? esc_url_raw($_REQUEST['redirect_url']) : admin_url('admin.php?page=wc-settings&tab=shipping');

        // Added error checking for file upload
        if ( isset( $_FILES['import_csv'] ) && ! empty( $_FILES['import_csv']['name'] ) && $_FILES['import_csv']['error'] === UPLOAD_ERR_OK ) {
            $file = $_FILES['import_csv']['tmp_name'];
            $file_info = pathinfo($_FILES['import_csv']['name']);
            if ( strtolower($file_info['extension']) !== 'csv' ) wp_die( 'Invalid file format. Please upload a CSV file.' );

            $handle = fopen($file, "r");
            if ($handle !== FALSE) {
                $header = fgetcsv($handle, 1000, ",");
                $expected_header = asia_shipping_get_csv_headers();
                // Simple Header Validation
                if ( ! $header || count($header) !== count($expected_header) || $header[0] !== 'Label' ) {
                    fclose($handle);
                    wp_die( __( '❌ Error: Invalid CSV format. Please export a new template to ensure headers match.', 'Asia-Postal-Shipping' ) );
                }

                $rules = array();
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $rules[] = array(
                        'label'         => sanitize_text_field($data[0]),
                        'country'       => sanitize_text_field($data[1]),
                        'state'         => sanitize_text_field($data[2]),
                        'postcode'      => isset($data[3]) ? sanitize_text_field($data[3]) : '*',
                        'shipping_class'=> isset($data[4]) ? sanitize_text_field($data[4]) : '*',
                        'delivery_time' => sanitize_text_field($data[5]),
                        'min_weight'    => floatval($data[6]),
                        'max_weight'    => sanitize_text_field($data[7]),
                        'min_total'     => floatval($data[8]),
                        'max_total'     => sanitize_text_field($data[9]),
                        'min_qty'       => floatval($data[10]),
                        'max_qty'       => sanitize_text_field($data[11]),
                        'base_weight'   => floatval($data[12]),
                        'base_cost'     => floatval($data[13]),
                        'per_kg'        => floatval($data[14]),
                        'enabled'       => isset($data[15]) ? sanitize_text_field($data[15]) : 'yes'
                    );
                }
                fclose($handle);
                $option_key = 'woocommerce_asia_post_table_rate_' . $instance_id . '_settings';
                $current_settings = get_option($option_key, array());
                $current_settings['rules'] = json_encode($rules);
                update_option($option_key, $current_settings);
                $redirect_url = add_query_arg( 'asia_import', 'success', $redirect_url );
            } else {
                 $redirect_url = add_query_arg( 'asia_import', 'error', $redirect_url );
            }
        } else {
            $redirect_url = add_query_arg( 'asia_import', 'error', $redirect_url );
        }
        wp_redirect( $redirect_url );
        exit;
    }

    function asia_post_shipping_init() {
        if ( ! class_exists( 'WC_Asia_Post_Shipping_Method' ) ) {
            class WC_Asia_Post_Shipping_Method extends WC_Shipping_Method {
                public $rules;

                public function __construct( $instance_id = 0 ) {
                    $this->id                 = 'asia_post_table_rate';
                    $this->instance_id        = absint( $instance_id );
                    $this->method_title       = __( 'Asia Post / Table Rate', 'Asia-Postal-Shipping' );
                    $this->method_description = __( 'Tree-table rate logic specialized for Asian Postal Carriers.', 'Asia-Postal-Shipping' );
                    $this->supports = array( 'shipping-zones', 'instance-settings' );
                    $this->init();
                }

                public function init() {
                    $this->init_form_fields();
                    $this->init_settings();
                    $this->title = $this->get_option( 'title' );
                    $this->rules = $this->get_option( 'rules' );
                    add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
                }

                public function init_form_fields() {
                    $currency_symbol = get_woocommerce_currency_symbol();

                    $raw_carriers = asia_get_carriers();
                    $carrier_options = array(
                        'generic' => __( '🌏 Generic / Custom', 'Asia-Postal-Shipping' )
                    );
                    foreach($raw_carriers as $region => $carriers) {
                        $carrier_options[$region] = array();
                        foreach($carriers as $key => $data) {
                            $carrier_options[$region][$key] = $data['label'];
                        }
                    }

                    $this->instance_form_fields = array(
                        'title' => array(
                            'title'       => __( 'Method Title', 'Asia-Postal-Shipping' ),
                            'type'        => 'text',
                            'description' => __( 'This controls the title which the user sees during checkout.', 'Asia-Postal-Shipping' ),
                            'default'     => __( 'Asia Post', 'Asia-Postal-Shipping' ),
                            'desc_tip'    => true,
                        ),
                        'carrier_brand' => array(
                            'title'       => __( 'Postal Carrier', 'Asia-Postal-Shipping' ),
                            'type'        => 'select',
                            'description' => __( 'Select a carrier to enable smart defaults.', 'Asia-Postal-Shipping' ),
                            'default'     => 'generic',
                            'options'     => $carrier_options,
                            'desc_tip'    => true,
                        ),
                        'exchange_rate' => array(
                            'title'             => __( 'Exchange Rate', 'Asia-Postal-Shipping' ),
                            'type'              => 'number',
                            'custom_attributes' => array( 'step' => '0.000001' ),
                            'default'           => '1',
                            'description'       => __( 'Multiplier to convert the carrier rate to your store currency. Example: If rules are in THB and store is USD, enter 0.028. Leave as 1 if currencies match.', 'Asia-Postal-Shipping' ),
                            'desc_tip'          => true,
                        ),
                        'custom_carrier_label' => array(
                            'title'       => __( 'Custom Carrier Name', 'Asia-Postal-Shipping' ),
                            'type'        => 'text',
                            'default'     => __( 'Standard', 'Asia-Postal-Shipping' ),
                            'placeholder' => __( 'e.g. Standard', 'Asia-Postal-Shipping' ),
                            'description' => __( 'This name will be used as the label for new rules if "Generic / Custom" is selected.', 'Asia-Postal-Shipping' ),
                            'desc_tip'    => true,
                        ),
                        'carrier_icon' => array(
                            'title'       => __( 'Custom Icon URL', 'Asia-Postal-Shipping' ),
                            'type'        => 'text',
                            'description' => __( 'Only used if "Generic / Custom" is selected.', 'Asia-Postal-Shipping' ),
                            'default'     => '',
                            'desc_tip'    => true,
                        ),
                        'tracking_url_pattern' => array(
                            'title'       => __( 'Tracking URL Pattern', 'Asia-Postal-Shipping' ),
                            'type'        => 'text',
                            'description' => __( 'Placeholder: {tracking_number}', 'Asia-Postal-Shipping' ),
                            'default'     => '',
                            'desc_tip'    => true,
                        ),
                        'calculation_mode' => array(
                            'title'       => __( 'Calculation Mode', 'Asia-Postal-Shipping' ),
                            'type'        => 'select',
                            'description' => __( 'Choose "Single Best Match" to stop at the first rule. Choose "Show All Matching Rates" to display multiple options (e.g. EMS vs Airmail).', 'Asia-Postal-Shipping' ),
                            'default'     => 'single',
                            'options'     => array(
                                'single' => __( 'Single Best Match', 'Asia-Postal-Shipping' ),
                                'all'    => __( 'Show All Matching Rates', 'Asia-Postal-Shipping' ),
                            ),
                            'desc_tip'    => true,
                        ),
                        'weight_step' => array(
                            'title'       => __( 'Weight Rounding Step (kg)', 'Asia-Postal-Shipping' ),
                            'type'        => 'number',
                            'custom_attributes' => array( 'step' => '0.01' ),
                            'default'     => '0',
                            'placeholder' => '0.5',
                            'description' => __( 'Round the final weight UP to the nearest step. E.g., if set to 0.5, a 1.1kg package becomes 1.5kg. Set to 0 to disable.', 'Asia-Postal-Shipping' ),
                            'desc_tip'    => true,
                        ),
                        'volumetric_divisor' => array(
                            'title'       => __( 'Volumetric Divisor', 'Asia-Postal-Shipping' ),
                            'type'        => 'number',
                            'default'     => '0',
                            'placeholder' => '5000',
                            'description' => __( 'Divisor for volumetric weight. Formula: (L x W x H) / Divisor. Common values: 5000 (DHL/FedEx), 6000 (Postal). Set to 0 to disable.', 'Asia-Postal-Shipping' ),
                            'desc_tip'    => true,
                        ),
                        'handling_fee' => array(
                            'title'       => sprintf( __( 'Handling / Service Fee (%s)', 'Asia-Postal-Shipping' ), $currency_symbol ),
                            'type'        => 'price',
                            'default'     => '0',
                            'description' => __( 'A fixed fee added to the total shipping cost (in your store currency).', 'Asia-Postal-Shipping' ),
                            'desc_tip'    => true,
                        ),
                        'free_shipping_threshold' => array(
                            'title'       => sprintf( __( 'Free Shipping Threshold (%s)', 'Asia-Postal-Shipping' ), $currency_symbol ),
                            'type'        => 'price',
                            'default'     => '0',
                            'description' => __( 'If the cart total exceeds this amount, shipping becomes free.', 'Asia-Postal-Shipping' ),
                            'desc_tip'    => true,
                        ),
                        'debug_mode' => array(
                            'title'       => __( 'Debug Mode', 'Asia-Postal-Shipping' ),
                            'label'       => __( 'Enable Debugging', 'Asia-Postal-Shipping' ),
                            'type'        => 'checkbox',
                            'default'     => 'no',
                            'description' => __( 'If enabled, admins will see a log of shipping calculations on the cart page. Useful for troubleshooting rules.', 'Asia-Postal-Shipping' ),
                            'desc_tip'    => true,
                        ),
                        'tax_status' => array(
                            'title'   => __( 'Tax Status', 'Asia-Postal-Shipping' ),
                            'type'    => 'select',
                            'class'   => 'wc-enhanced-select',
                            'default' => 'taxable',
                            'options' => array(
                                'taxable' => __( 'Taxable', 'Asia-Postal-Shipping' ),
                                'none'    => __( 'None', 'Asia-Postal-Shipping' ),
                            ),
                        ),
                        'rules' => array(
                            'type'        => 'rules_table',
                            'title'       => __( 'Shipping Rules', 'Asia-Postal-Shipping' ),
                            'description' => __( 'Define your logic tree here.', 'Asia-Postal-Shipping' ),
                        ),
                    );
                }

                private function debug_msg( $msg ) {
                    if ( $this->get_option('debug_mode') === 'yes' && current_user_can('manage_options') ) {
                        wc_add_notice( '<strong>Asia Post Debug:</strong> ' . $msg, 'notice' );
                    }
                }

                private function check_postcode( $user_postcode, $rule_postcode ) {
                    if ( empty( $rule_postcode ) || $rule_postcode === '*' ) return true;
                    $user_postcode = strtoupper( trim( $user_postcode ) );
                    $conditions = explode( ',', strtoupper( $rule_postcode ) );
                    foreach ( $conditions as $condition ) {
                        $condition = trim( $condition );
                        if ( strpos( $condition, '...' ) !== false ) {
                            $range = explode( '...', $condition );
                            if ( count($range) === 2 ) {
                                $min = preg_replace( '/[^0-9]/', '', $range[0] );
                                $max = preg_replace( '/[^0-9]/', '', $range[1] );
                                
                                // FIX: Be careful stripping non-numerics from user input if ranges are intended for numeric zones
                                // but the user is from a country with alphanumeric codes.
                                // For this plugin (Asia Post), assuming numeric ranges is mostly safe, but we should verify.
                                $user_numeric = preg_replace( '/[^0-9]/', '', $user_postcode );
                                
                                if ( is_numeric($min) && is_numeric($max) && is_numeric($user_numeric) && $user_numeric !== '' ) {
                                    if ( $user_numeric >= $min && $user_numeric <= $max ) return true;
                                }
                            }
                        } elseif ( strpos( $condition, '*' ) !== false ) {
                            $prefix = str_replace( '*', '', $condition );
                            if ( strpos( $user_postcode, $prefix ) === 0 ) return true;
                        } elseif ( $user_postcode === $condition ) {
                            return true;
                        }
                    }
                    return false;
                }

                public function calculate_shipping( $package = array() ) {
                    $rules_json = $this->get_option( 'rules' );
                    if ( empty( $rules_json ) ) return;

                    $rules = json_decode( $rules_json, true );
                    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $rules ) ) return;

                    $handling_fee = floatval( $this->get_option( 'handling_fee' ) );
                    $free_shipping_threshold = floatval( $this->get_option( 'free_shipping_threshold' ) );
                    $volumetric_divisor = floatval( $this->get_option( 'volumetric_divisor' ) );
                    $weight_step = floatval( $this->get_option( 'weight_step' ) );
                    $carrier_icon = $this->get_option( 'carrier_icon' );
                    $carrier_brand = $this->get_option( 'carrier_brand' );
                    $calculation_mode = $this->get_option( 'calculation_mode' );
                    
                    // --- EXCHANGE RATE LOGIC ---
                    $exchange_rate = floatval( $this->get_option( 'exchange_rate' ) );
                    if ( $exchange_rate <= 0 ) {
                        $exchange_rate = 1;
                    }

                    $icon_url = '';
                    if ( $carrier_brand === 'generic' ) {
                        $icon_url = $this->get_option( 'carrier_icon' );
                    } else {
                        $filename = $carrier_brand . '.png';
                        // Safety: ensure filename doesn't traverse directories (though generic brand list comes from hardcoded array)
                        if ( file_exists( plugin_dir_path( __FILE__ ) . 'icons/' . $filename ) ) {
                            $icon_url = plugin_dir_url( __FILE__ ) . 'icons/' . $filename;
                        }
                    }

                    $debug = $this->get_option( 'debug_mode' ) === 'yes';
                    $icon_html = !empty( $icon_url ) ? '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $this->title ) . '" class="asia-post-carrier-icon" style="vertical-align:middle; margin-right:5px; max-height:24px;" /> ' : '';

                    $total_actual_weight = 0;
                    $total_volumetric_weight = 0;
                    $cost   = 0;
                    $qty    = 0;
                    $destination_country = $package['destination']['country'];
                    $destination_state   = $package['destination']['state'];
                    $destination_postcode = $package['destination']['postcode'];
                    $cart_classes = array();

                    if ( $debug && is_cart() ) wc_clear_notices();

                    foreach ( $package['contents'] as $item_id => $values ) {
                        $_product = $values['data'];
                        $q = $values['quantity'];
                        if ( $q > 0 && $_product->needs_shipping() ) {
                            $total_actual_weight += $_product->get_weight() * $q;
                            $cost += $values['line_total'];
                            $qty += $q;
                            $class_id = $_product->get_shipping_class_id();
                            if ( $class_id ) {
                                $term = get_term( $class_id, 'product_shipping_class' );
                                if ( $term && ! is_wp_error( $term ) ) $cart_classes[] = $term->slug;
                            }
                            if ( $volumetric_divisor > 0 ) {
                                $l = (float) $_product->get_length();
                                $w = (float) $_product->get_width();
                                $h = (float) $_product->get_height();
                                if( $l > 0 && $w > 0 && $h > 0 ) {
                                    $vol_weight = ($l * $w * $h) / $volumetric_divisor;
                                    $total_volumetric_weight += ($vol_weight * $q);
                                }
                            }
                        }
                    }

                    $final_weight = ($volumetric_divisor > 0 && $total_volumetric_weight > $total_actual_weight) ? $total_volumetric_weight : $total_actual_weight;
                    if ( $weight_step > 0 ) $final_weight = ceil( $final_weight / $weight_step ) * $weight_step;

                    if ( $debug ) {
                        $class_list = empty($cart_classes) ? 'None' : implode(', ', array_unique($cart_classes));
                        $this->debug_msg( "Dest: $destination_country | Items: $qty | W: $final_weight | Classes: $class_list | Exch Rate: $exchange_rate" );
                    }

                    foreach ( $rules as $index => $rule ) {
                        $rule_num = $index + 1;

                        if ( isset($rule['enabled']) && $rule['enabled'] === 'no' ) continue;

                        $rule_countries = array_map('trim', explode(',', strtoupper( isset($rule['country']) ? $rule['country'] : '*' )));
                        if ( ! in_array( '*', $rule_countries ) && ! in_array( $destination_country, $rule_countries ) ) {
                            if($debug) $this->debug_msg("Rule #$rule_num skipped: Country mismatch.");
                            continue;
                        }

                        if ( ! empty( $rule['state'] ) && $rule['state'] !== '*' ) {
                            $rule_states = array_map('trim', explode(',', $rule['state']));
                            if ( ! in_array( '*', $rule_states ) && ! in_array( $destination_state, $rule_states ) ) {
                                if($debug) $this->debug_msg("Rule #$rule_num skipped: State mismatch.");
                                continue;
                            }
                        }

                        $rule_postcode = isset($rule['postcode']) ? $rule['postcode'] : '*';
                        if ( ! $this->check_postcode( $destination_postcode, $rule_postcode ) ) {
                            if($debug) $this->debug_msg("Rule #$rule_num skipped: Postcode mismatch.");
                            continue;
                        }

                        $rule_class = isset($rule['shipping_class']) ? $rule['shipping_class'] : '*';
                        if ( $rule_class !== '*' && ! in_array( $rule_class, $cart_classes ) ) {
                            if($debug) $this->debug_msg("Rule #$rule_num skipped: Class mismatch.");
                            continue;
                        }

                        $min_w = floatval( $rule['min_weight'] );
                        $max_w = ( isset($rule['max_weight']) && $rule['max_weight'] !== '*' && $rule['max_weight'] !== '' ) ? floatval( $rule['max_weight'] ) : 999999;
                        if ( $final_weight < $min_w || $final_weight > $max_w ) {
                            if($debug) $this->debug_msg("Rule #$rule_num skipped: Weight mismatch.");
                            continue;
                        }

                        $min_t = floatval( $rule['min_total'] );
                        $max_t = ( isset($rule['max_total']) && $rule['max_total'] !== '*' && $rule['max_total'] !== '' ) ? floatval( $rule['max_total'] ) : 999999999;
                        if ( $cost < $min_t || $cost > $max_t ) {
                            if($debug) $this->debug_msg("Rule #$rule_num skipped: Cost mismatch.");
                            continue;
                        }

                        $min_q = floatval( isset($rule['min_qty']) ? $rule['min_qty'] : 0 );
                        $max_q = ( isset($rule['max_qty']) && $rule['max_qty'] !== '*' && $rule['max_qty'] !== '' ) ? floatval( $rule['max_qty'] ) : 999999;
                        if ( $qty < $min_q || $qty > $max_q ) {
                            if($debug) $this->debug_msg("Rule #$rule_num skipped: Qty mismatch.");
                            continue;
                        }

                        // MATCH
                        $base_c = floatval( $rule['base_cost'] );
                        $per_kg = floatval( $rule['per_kg'] );
                        $base_w = floatval( isset($rule['base_weight']) ? $rule['base_weight'] : 0 );

                        $chargeable_excess = max( 0, $final_weight - $base_w );
                        $carrier_cost = $base_c + ( $per_kg * $chargeable_excess );
                        
                        // Apply Exchange Rate to Carrier Cost
                        $converted_cost = $carrier_cost * $exchange_rate;
                        
                        // Add Handling Fee (assumed to be in store currency)
                        $shipping_total = $converted_cost + $handling_fee;

                        if($debug) $this->debug_msg("<strong>Rule #$rule_num MATCHED!</strong> Carrier Cost: $carrier_cost | Converted: $converted_cost | Total: $shipping_total");

                        $label = $icon_html . $this->title . ' (' . $rule['label'] . ')';
                        if ( ! empty( $rule['delivery_time'] ) ) $label .= ' (' . $rule['delivery_time'] . ')';
                        if ( $free_shipping_threshold > 0 && $cost >= $free_shipping_threshold ) {
                            $shipping_total = 0;
                            $label .= ' ' . __( '- Free', 'Asia-Postal-Shipping' );
                        }

                        $this->add_rate( array(
                            'id'      => $this->get_rate_id() . '_' . $rule_num,
                            'label'   => $label,
                            'cost'    => $shipping_total,
                            'package' => $package,
                        ));

                        if ( $calculation_mode !== 'all' ) break;
                    }
                }

                public function generate_rules_table_html( $key, $data ) {
                    $field_key = $this->get_field_key( $key );
                    // DYNAMICALLY CALCULATE IDs FOR JS
                    $id_prefix = 'woocommerce_' . $this->id . '_' . $this->instance_id . '_';
                    $js_carrier_id = $id_prefix . 'carrier_brand';
                    $js_custom_label_id = $id_prefix . 'custom_carrier_label';
                    $js_carrier_icon_id = $id_prefix . 'carrier_icon';

                    $currency_symbol = get_woocommerce_currency_symbol();
                    
                    // --- START CUSTOM CURRENCY LOGIC ---
                    $saved_carrier = $this->get_option( 'carrier_brand' );
                    $carrier_currencies = array(
                        'china_post' => '¥', 'chunghwa_post' => 'NT$', 'hongkong_post' => 'HK$', 'japan_post' => '¥',
                        'korea_post_kr' => '₩', 'macau_post' => 'MOP$', 'mongol_post' => '₮',
                        'phlpost' => '₱', 'pos_indonesia' => 'Rp', 'pos_malaysia' => 'RM', 'singpost' => 'S$',
                        'thailand_post' => '฿', 'vietnam_post' => '₫',
                        'india_post' => '₹', 'pakistan_post' => '₨', 'srilanka_post' => 'Rs',
                        'emirates_post' => 'AED', 'ptt_turkey' => '₺', 'saudi_post' => 'SAR'
                    );
                    
                    // --- FLAG LOGIC ---
                    $carrier_flags = array(
                        'china_post' => '🇨🇳', 'chunghwa_post' => '🇹🇼', 'hongkong_post' => '🇭🇰', 'japan_post' => '🇯🇵',
                        'korea_post_kr' => '🇰🇷', 'macau_post' => '🇲🇴', 'mongol_post' => '🇲🇳',
                        'phlpost' => '🇵🇭', 'pos_indonesia' => '🇮🇩', 'pos_malaysia' => '🇲🇾', 'singpost' => '🇸🇬',
                        'thailand_post' => '🇹🇭', 'vietnam_post' => '🇻🇳',
                        'india_post' => '🇮🇳', 'pakistan_post' => '🇵🇰', 'srilanka_post' => '🇱🇰',
                        'emirates_post' => '🇦🇪', 'ptt_turkey' => '🇹🇷', 'saudi_post' => '🇸🇦'
                    );
                    
                    if ( isset( $carrier_currencies[ $saved_carrier ] ) ) {
                        // Use carrier specific currency with flag
                        $currency_symbol = $carrier_currencies[ $saved_carrier ];
                        $currency_flag = isset($carrier_flags[$saved_carrier]) ? $carrier_flags[$saved_carrier] : '';
                    } else {
                        // If Generic/Custom, default to empty to avoid showing wrong store currency (e.g. $)
                        $currency_symbol = ''; 
                        $currency_flag = '';
                    }
                    // --- END CUSTOM CURRENCY LOGIC ---

                    $defaults  = array( 'title' => '', 'description' => '' );
                    $data  = wp_parse_args( $data, $defaults );
                    $value = $this->get_option( $key );
                    $rules = json_decode( $value, true );
                    if ( ! $rules ) $rules = array();

                    // Dependencies
                    $shipping_classes = get_terms( array( 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ) );
                    $wc_shipping_classes = array( '*' => __( 'Any Class (*)', 'Asia-Postal-Shipping' ) );
                    foreach ( $shipping_classes as $class ) $wc_shipping_classes[ $class->slug ] = $class->name;

                    // Get Countries (Moved Inside Function)
                    $wc_countries = WC()->countries->get_countries();

                    // Supported Countries & States
                    $supported = array('CN', 'HK', 'JP', 'MO', 'MN', 'KR', 'TW', 'ID', 'MY', 'PH', 'SG', 'TH', 'VN', 'IN', 'PK', 'LK', 'AE', 'SA', 'TR');
                    $states_json = array();
                    foreach($supported as $cc) {
                        $s = WC()->countries->get_states( $cc );
                        if ( ! empty($s) ) $states_json[$cc] = $s;
                    }

                    // Presets from JSON
                    $presets = array();
                    $json_file = plugin_dir_path( __FILE__ ) . 'zones.json';
                    if ( file_exists( $json_file ) ) {
                        $json_content = file_get_contents( $json_file );
                        $presets = json_decode( $json_content, true );
                    }

                    // FLATTEN CARRIER MAP FOR JS LOOKUP
                    $raw_carriers = asia_get_carriers();
                    $flat_carrier_map = array();
                    foreach($raw_carriers as $group) {
                        foreach($group as $key => $val) {
                            $flat_carrier_map[$key] = $val;
                        }
                    }

                    ob_start();
                    ?>
                    <tr valign="top">
                    <td colspan="2" style="padding:0; border:0;">
                    <style>
                    .asia-shipping-wrapper { padding: 20px; background: #fff; border: 1px solid #c3c4c7; overflow-x: auto; }
                    .asia-rules-table { width: 100%; border-collapse: collapse; border: 1px solid #c3c4c7; margin-top: 10px; counter-reset: row-num; }
                    .asia-rules-table th { position: sticky; top: 0; z-index: 10; background: #f0f0f1; padding: 12px; text-align: left; font-weight: 600; border-bottom: 2px solid #c3c4c7; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
                    .asia-rules-table td { padding: 10px; border-bottom: 1px solid #f0f0f1; vertical-align: top; }
                    .asia-rule-row { counter-increment: row-num; }
                    .asia-row-index { font-size: 10px; color: #666; display: block; font-weight:bold; }
                    .asia-row-index::before { content: "#" counter(row-num); }
                    .asia-sort-handle { cursor: move; color: #999; text-align: center; vertical-align: middle; }
                    .asia-input-group { display: flex; gap: 5px; align-items: center; }
                    .asia-rule-input { width: 100%; padding: 5px; border: 1px solid #8c8f94; border-radius: 4px; }
                    .asia-rule-small { width: 70px !important; min-width: 60px; text-align: right; }
                    .select2-container { width: 100% !important; }
                    .asia-action-cell { text-align: center; vertical-align: middle; white-space: nowrap; }
                    .asia-duplicate-btn { color: #2271b1; cursor: pointer; margin-right: 8px; }
                    .asia-remove-btn { color: #d63638; cursor: pointer; }
                    .asia-input-compact { max-width: 50px !important; text-align: center; }
                    .rule-country-input { max-width: 50px !important; text-align: center; }
                    input[name="rule_base_weight[]"] { max-width: 60px !important; text-align: center; }
                    #asia-bulk-actions { float: left; margin-right: 10px; display: none; }
                    .country-cell { min-width: 150px; }
                    </style>

                    <div class="asia-shipping-wrapper">
                    <h3><?php echo esc_html( $data['title'] ); ?></h3>
                    <p class="description"><?php echo wp_kses_post( $data['description'] ); ?></p>

                    <div style="margin-bottom:15px; text-align:right; display:flex; justify-content:flex-end; gap:10px; align-items:center;">
                    <div id="asia-service-wrapper" style="display:none; display:flex; gap:5px; align-items:center;">
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e( '<h3>Service Guide</h3><ul><li><strong>EMS:</strong> Fastest (3-7 days), expensive, 20-30kg limit.</li><li><strong>ePacket:</strong> Budget option, under 2kg, tracked.</li><li><strong>Airmail:</strong> Standard speed.</li><li><strong>Surface:</strong> Sea freight (1-3 months), cheapest for heavy items.</li></ul>', 'Asia-Postal-Shipping' ); ?>"></span>
                    <select id="asia-service-select" style="max-width:200px;"></select>
                    <button type="button" id="asia-load-presets" class="button button-secondary tips" data-tip="<?php esc_attr_e('Auto-fill the table with official zones for the selected Carrier and Service. Warning: Overwrites existing rules.', 'Asia-Postal-Shipping'); ?>">
                    <span class="dashicons dashicons-update"></span> <?php esc_html_e('Load Zones', 'Asia-Postal-Shipping'); ?>
                    </button>
                    </div>
                    <div style="border-left:1px solid #ccc; padding-left:10px; display:flex; gap:10px;">
                    <a href="<?php echo admin_url('admin-post.php?action=asia_shipping_export&instance_id=' . $this->instance_id . '&asia_shipping_nonce=' . wp_create_nonce('asia_shipping_export_action')); ?>" class="button button-secondary tips" data-tip="<?php esc_attr_e('Download your current rules to a CSV file. Perfect for backups or editing in Excel.', 'Asia-Postal-Shipping'); ?>">
                    <span class="dashicons dashicons-download"></span> <?php esc_html_e('Export', 'Asia-Postal-Shipping'); ?>
                    </a>
                    <button type="button" class="button button-secondary tips" onclick="document.getElementById('asia-import-form').style.display = (document.getElementById('asia-import-form').style.display == 'none' ? 'block' : 'none');" data-tip="<?php esc_attr_e('Upload a CSV file to overwrite your current rules. Useful for restoring backups or bulk updates.', 'Asia-Postal-Shipping'); ?>">
                    <span class="dashicons dashicons-upload"></span> <?php esc_html_e('Import', 'Asia-Postal-Shipping'); ?>
                    </button>
                    </div>
                    </div>

                    <div id="asia-import-form-wrapper" style="display:none;">
                    <div id="asia-import-form" style="padding:15px; background:#f0f0f1; border:1px solid #ccc; margin-bottom:15px;">
                    <h4><?php esc_html_e('Import Rules from CSV', 'Asia-Postal-Shipping'); ?></h4>
                    <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="asia_shipping_import">
                    <input type="hidden" name="instance_id" value="<?php echo esc_attr($this->instance_id); ?>">
                    <input type="hidden" name="redirect_url" value="<?php echo esc_url(add_query_arg(array())); ?>">
                    <?php wp_nonce_field('asia_shipping_import_action', 'asia_shipping_nonce'); ?>
                    <input type="file" name="import_csv" accept=".csv" required>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Upload & Import', 'Asia-Postal-Shipping'); ?></button>
                    </form>
                    </div>
                    </div>

                    <table class="asia-rules-table">
                    <thead>
                    <tr>
                    <th style="width: 2%; text-align:center;"><input type="checkbox" id="asia-select-all"></th>
                    <th style="width: 2%;"></th>
                    <th style="width: 9%;">
                    <?php esc_html_e('Label', 'Asia-Postal-Shipping'); ?>
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Internal label. Rules are prioritized from Top to Bottom.', 'Asia-Postal-Shipping'); ?>"></span>
                    </th>
                    <th style="width: 10%;">
                    <?php esc_html_e('Country', 'Asia-Postal-Shipping'); ?>
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('2-letter country code (e.g. JP, CN).', 'Asia-Postal-Shipping'); ?>"></span>
                    </th>
                    <th style="width: 10%;">
                    <?php esc_html_e('Province/State', 'Asia-Postal-Shipping'); ?>
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Enter * for all, or select specific states.', 'Asia-Postal-Shipping'); ?>"></span>
                    </th>
                    <th style="width: 9%;">
                    <?php esc_html_e('Postcode/Zip', 'Asia-Postal-Shipping'); ?>
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Enter specific codes, ranges (1000...2000), or wildcards (90*).', 'Asia-Postal-Shipping'); ?>"></span>
                    </th>
                    <th style="width: 9%;">
                    <?php esc_html_e('Shipping Class', 'Asia-Postal-Shipping'); ?>
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Rule applies only if cart contains this class.', 'Asia-Postal-Shipping'); ?>"></span>
                    </th>
                    <th style="width: 8%;">
                    <?php esc_html_e('Delivery', 'Asia-Postal-Shipping'); ?>
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Text displayed to customer (e.g. 3-7 Days).', 'Asia-Postal-Shipping'); ?>"></span>
                    </th>
                    <th style="width: 10%;">
                    <?php esc_html_e('Weight (kg)', 'Asia-Postal-Shipping'); ?>
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Apply rule only if cart weight is within this range.', 'Asia-Postal-Shipping'); ?>"></span>
                    </th>
                    <th style="width: 9%;" class="asia-header-total">
                    <span class="asia-header-label">
                    <?php 
                        esc_html_e('Total', 'Asia-Postal-Shipping');
                        if ( ! empty( $currency_symbol ) ) echo ' (' . esc_html( $currency_flag . ' ' . $currency_symbol ) . ')';
                    ?>
                    </span>
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Apply rule only if cart total value is within this range.', 'Asia-Postal-Shipping'); ?>"></span>
                    </th>
                    <th style="width: 8%;">
                    <?php esc_html_e('Qty (Min-Max)', 'Asia-Postal-Shipping'); ?>
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Apply rule only if total item count is within this range. Leave empty or use * for any quantity.', 'Asia-Postal-Shipping'); ?>"></span>
                    </th>
                    <th style="width: 6%;">
                    <?php esc_html_e('Base Kg', 'Asia-Postal-Shipping'); ?>
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Weight included in the Base Cost.', 'Asia-Postal-Shipping'); ?>"></span>
                    </th>
                    <th style="width: 14%;" class="asia-header-cost">
                    <span class="asia-header-label">
                    <?php 
                        esc_html_e('Cost', 'Asia-Postal-Shipping');
                        if ( ! empty( $currency_symbol ) ) echo ' (' . esc_html( $currency_flag . ' ' . $currency_symbol ) . ')';
                    ?>
                    </span>
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Formula: Base Cost + (Per Kg * (Total Weight - Base Weight))', 'Asia-Postal-Shipping'); ?>"></span>
                    </th>
                    <th style="width: 6%;"></th>
                    </tr>
                    </thead>
                    <tbody id="asia_post_rules_tbody">
                    <?php if ( ! empty( $rules ) ) : foreach ( $rules as $index => $rule ) : ?>
                    <tr class="asia-rule-row">
                    <td style="text-align:center; vertical-align:middle;"><input type="checkbox" class="asia-select-row"></td>
                    <td class="asia-sort-handle"><span class="dashicons dashicons-menu"></span><span class="asia-row-index"></span></td>
                    <td><input type="text" class="asia-rule-input" name="rule_label[]" value="<?php echo esc_attr( $rule['label'] ); ?>"></td>
                    <td class="country-cell"><input type="text" class="asia-rule-input rule-country-input" name="rule_country[]" value="<?php echo esc_attr( $rule['country'] ); ?>"></td>
                    <td class="state-cell"><input type="text" class="asia-rule-input rule-state-input" name="rule_state[]" value="<?php echo esc_attr( isset($rule['state']) ? $rule['state'] : '*' ); ?>" placeholder="*"></td>
                    <td><input type="text" class="asia-rule-input" name="rule_postcode[]" value="<?php echo esc_attr( isset($rule['postcode']) ? $rule['postcode'] : '*' ); ?>" placeholder="*"></td>
                    <td>
                    <select class="asia-rule-input" name="rule_shipping_class[]">
                    <?php foreach($wc_shipping_classes as $slug => $name): ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected( isset($rule['shipping_class']) ? $rule['shipping_class'] : '*', $slug ); ?>><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                    </select>
                    </td>
                    <td><input type="text" class="asia-rule-input" name="rule_delivery_time[]" value="<?php echo esc_attr( isset($rule['delivery_time']) ? $rule['delivery_time'] : '' ); ?>"></td>
                    <td><div class="asia-input-group"><input type="number" step="0.01" class="asia-rule-input asia-rule-small" name="rule_min_weight[]" value="<?php echo esc_attr( $rule['min_weight'] ); ?>"><span>-</span><input type="text" class="asia-rule-input asia-rule-small" name="rule_max_weight[]" value="<?php echo esc_attr( $rule['max_weight'] ); ?>"></div></td>
                    <td><div class="asia-input-group"><input type="number" step="0.01" class="asia-rule-input asia-rule-small" name="rule_min_total[]" value="<?php echo esc_attr( $rule['min_total'] ); ?>"><span>-</span><input type="text" class="asia-rule-input asia-rule-small" name="rule_max_total[]" value="<?php echo esc_attr( $rule['max_total'] ); ?>"></div></td>
                    <td><div class="asia-input-group"><input type="number" step="1" class="asia-rule-input asia-rule-small" name="rule_min_qty[]" value="<?php echo esc_attr( isset($rule['min_qty']) ? $rule['min_qty'] : '' ); ?>" placeholder="0"><span>-</span><input type="text" class="asia-rule-input asia-rule-small" name="rule_max_qty[]" value="<?php echo esc_attr( isset($rule['max_qty']) ? $rule['max_qty'] : '' ); ?>" placeholder="*"></div></td>
                    <td><input type="number" step="0.01" class="asia-rule-input" name="rule_base_weight[]" value="<?php echo esc_attr( isset($rule['base_weight']) ? $rule['base_weight'] : '0' ); ?>"></td>
                    <td><div style="display:grid; gap:5px;">
                    <div class="asia-input-group"><span style="white-space:nowrap;" class="asia-label-base">
                    <?php 
                        esc_html_e( 'Base', 'Asia-Postal-Shipping' );
                        echo ( ! empty( $currency_symbol ) ) ? ' (' . esc_html( $currency_flag . ' ' . $currency_symbol ) . '):' : ':';
                    ?>
                    </span><input type="number" step="0.01" class="asia-rule-input asia-rule-small" name="rule_base_cost[]" value="<?php echo esc_attr( $rule['base_cost'] ); ?>"></div>
                    <div class="asia-input-group"><span style="white-space:nowrap;" class="asia-label-perkg">
                    <?php 
                        esc_html_e( '+ /kg', 'Asia-Postal-Shipping' );
                        echo ( ! empty( $currency_symbol ) ) ? ' (' . esc_html( $currency_flag . ' ' . $currency_symbol ) . '):' : ':';
                    ?>
                    </span><input type="number" step="0.01" class="asia-rule-input asia-rule-small" name="rule_per_kg[]" value="<?php echo esc_attr( $rule['per_kg'] ); ?>"></div>
                    </div></td>
                    <td class="asia-action-cell">
                    <span class="dashicons dashicons-admin-page asia-duplicate-btn duplicate-row tips" data-tip="<?php esc_attr_e('Duplicate', 'Asia-Postal-Shipping'); ?>"></span>
                    <span class="dashicons dashicons-trash asia-remove-btn remove-row tips" data-tip="<?php esc_attr_e('Remove', 'Asia-Postal-Shipping'); ?>"></span>
                    </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    </table>

                    <div class="asia-footer-actions">
                    <div id="asia-bulk-actions">
                    <button type="button" class="button" id="asia-delete-selected" style="color: #a00; border-color: #a00;"><?php esc_html_e( 'Delete Selected', 'Asia-Postal-Shipping' ); ?></button>
                    </div>
                    <button type="button" class="button button-primary" id="add_asia_rule"><?php esc_html_e( 'Add New Rule', 'Asia-Postal-Shipping' ); ?></button>
                    </div>
                    <input type="hidden" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( $value ); ?>">

                    <script type="text/javascript">
                    var asia_states_data = <?php echo json_encode( $states_json ); ?>;
                    var asia_countries_data = <?php echo json_encode( $wc_countries ); ?>; // Pass full country list
                    var asia_carrier_map = <?php echo json_encode( $flat_carrier_map ); ?>; // Use Flat Map!
                    var asia_presets = <?php echo json_encode( $presets ); ?>;
                    var asia_shipping_classes = <?php echo json_encode( $wc_shipping_classes ); ?>;
                    var asia_currencies = <?php echo json_encode( $carrier_currencies ); ?>;
                    var asia_flags = <?php echo json_encode( $carrier_flags ); ?>;

                    // DYNAMIC IDs from PHP
                    var carrier_field_id = '<?php echo esc_js( $id_prefix . 'carrier_brand' ); ?>';
                    var custom_label_field_id = '<?php echo esc_js( $id_prefix . 'custom_carrier_label' ); ?>';
                    var carrier_icon_field_id = '<?php echo esc_js( $id_prefix . 'carrier_icon' ); ?>';

                    // JS Currency Logic
                    var js_currency_symbol = '<?php echo esc_js( $currency_symbol ); ?>';
                    var js_currency_flag = '<?php echo esc_js( $currency_flag ); ?>';
                    var js_base_label = '<?php echo esc_js( __( 'Base', 'Asia-Postal-Shipping' ) ); ?>';
                    var js_per_kg_label = '<?php echo esc_js( __( '+ /kg', 'Asia-Postal-Shipping' ) ); ?>';
                    var js_total_label = '<?php echo esc_js( __( 'Total', 'Asia-Postal-Shipping' ) ); ?>';
                    var js_cost_label = '<?php echo esc_js( __( 'Cost', 'Asia-Postal-Shipping' ) ); ?>';
                    
                    if ( js_currency_symbol !== '' ) {
                        js_base_label += ' (' + js_currency_flag + ' ' + js_currency_symbol + '):';
                        js_per_kg_label += ' (' + js_currency_flag + ' ' + js_currency_symbol + '):';
                    } else {
                        js_base_label += ':';
                        js_per_kg_label += ':';
                    }

                    var asia_i18n = {
                        standard: '<?php echo esc_js( __( 'Standard', 'Asia-Postal-Shipping' ) ); ?>',
                        any_state: '<?php echo esc_js( __( 'Any Province/State (*)', 'Asia-Postal-Shipping' ) ); ?>',
                        any_country: '<?php echo esc_js( __( 'Any Country (*)', 'Asia-Postal-Shipping' ) ); ?>',
                        remove_confirm: '<?php echo esc_js( __( 'Remove rule?', 'Asia-Postal-Shipping' ) ); ?>',
                        preset_confirm: '<?php echo esc_js( __( 'Warning: This will replace all current rules with the standard zones. \n\nPro Tip: Export your current rules to CSV first as a backup.\n\nContinue?', 'Asia-Postal-Shipping' ) ); ?>',
                        base: js_base_label,
                        per_kg: js_per_kg_label,
                        total: js_total_label,
                        cost: js_cost_label,
                        duplicate_tip: '<?php echo esc_js( __( 'Duplicate Rule', 'Asia-Postal-Shipping' ) ); ?>',
                        remove_tip: '<?php echo esc_js( __( 'Remove Rule', 'Asia-Postal-Shipping' ) ); ?>',
                        bulk_delete_confirm: '<?php echo esc_js( __( 'Are you sure you want to delete the selected rules?', 'Asia-Postal-Shipping' ) ); ?>'
                    };

                    jQuery(document).ready(function($) {
                        $('#asia-import-form-wrapper').appendTo('body');
                        function initTooltips() { $(document.body).trigger('init_tooltips'); }
                        initTooltips();

                        $('#asia_post_rules_tbody').sortable({
                            handle: '.asia-sort-handle', cursor: 'move', axis: 'y',
                            update: function(event, ui) { serializeRules(); }
                        });

                        function toggleCustomLabel() {
                            // FUZZY SELECTOR: Finds select ending in carrier_brand
                            var $select = $('select').filter(function() {
                                return this.id.match(/carrier_brand$/);
                            });

                            var selected = $select.val();

                            // Update Currency Symbols Live
                            var symbol = asia_currencies[selected] || '';
                            var flag = asia_flags[selected] || '';
                            var newTotalText = asia_i18n.total;
                            var newCostText = asia_i18n.cost;
                            var newBaseLabel = '<?php echo esc_js( __( 'Base', 'Asia-Postal-Shipping' ) ); ?>';
                            var newPerKgLabel = '<?php echo esc_js( __( '+ /kg', 'Asia-Postal-Shipping' ) ); ?>';

                            if ( symbol !== '' ) {
                                newTotalText += ' (' + flag + ' ' + symbol + ')';
                                newCostText += ' (' + flag + ' ' + symbol + ')';
                                newBaseLabel += ' (' + flag + ' ' + symbol + '):';
                                newPerKgLabel += ' (' + flag + ' ' + symbol + '):';
                            } else {
                                newBaseLabel += ':';
                                newPerKgLabel += ':';
                            }

                            // Update Headers
                            $('.asia-header-total .asia-header-label').text(newTotalText);
                            $('.asia-header-cost .asia-header-label').text(newCostText);

                            // Update Rows (Live)
                            $('.asia-label-base').text(newBaseLabel);
                            $('.asia-label-perkg').text(newPerKgLabel);

                            // Update global i18n for new rows added via JS
                            asia_i18n.base = newBaseLabel;
                            asia_i18n.per_kg = newPerKgLabel;

                            // Also find custom label and icon inputs similarly
                            var $customLabelInput = $('input').filter(function() {
                                return this.id.match(/custom_carrier_label$/);
                            });
                            var $iconInput = $('input').filter(function() {
                                return this.id.match(/carrier_icon$/);
                            });

                            var customRow = $customLabelInput.closest('tr');
                            var iconRow = $iconInput.closest('tr');
                            var presetBtn = $('#asia-load-presets');
                            var serviceWrapper = $('#asia-service-wrapper');

                            if ( selected === 'generic' ) {
                                customRow.show(); iconRow.show(); serviceWrapper.hide();
                                return;
                            } else {
                                customRow.hide(); iconRow.hide();
                                if ( asia_presets.hasOwnProperty(selected) ) {
                                    var services = asia_presets[selected];
                                    var serviceSelect = $('#asia-service-select');
                                    serviceSelect.empty();
                                    var count = 0;
                                    for (var key in services) {
                                        if (services.hasOwnProperty(key)) {
                                            var label = key.toUpperCase().replace('_', ' ');
                                            serviceSelect.append($('<option>', { value: key, text: label }));
                                            count++;
                                        }
                                    }
                                    if (count > 0) serviceWrapper.css('display', 'flex'); else serviceWrapper.hide();
                                } else {
                                    serviceWrapper.hide();
                                }
                            }
                        }

                        // Init
                        toggleCustomLabel();
                        // Bind change event to fuzzy selector
                        $('select').filter(function() {
                            return this.id.match(/carrier_brand$/);
                        }).change(function() { toggleCustomLabel(); });

                        function serializeRules() {
                            var rules = [];
                            var $rows = $('#asia_post_rules_tbody tr');
                            $rows.each(function() {
                                var row = $(this);
                                var stateVal = row.find('.rule-state-input').val();
                                if ( Array.isArray(stateVal) ) stateVal = stateVal.join(',');
                                else if ( stateVal === null ) stateVal = '';

                            var countryVal = row.find('.rule-country-input').val();
                                if ( Array.isArray(countryVal) ) countryVal = countryVal.join(',');
                                else if ( countryVal === null ) countryVal = '';

                            rules.push({
                                label: row.find('input[name="rule_label[]"]').val(),
                                       country: countryVal,
                                       state: stateVal,
                                       postcode: row.find('input[name="rule_postcode[]"]').val(),
                                       shipping_class: row.find('select[name="rule_shipping_class[]"]').val(),
                                       delivery_time: row.find('input[name="rule_delivery_time[]"]').val(),
                                       min_weight: row.find('input[name="rule_min_weight[]"]').val(),
                                       max_weight: row.find('input[name="rule_max_weight[]"]').val(),
                                       min_total: row.find('input[name="rule_min_total[]"]').val(),
                                       max_total: row.find('input[name="rule_max_total[]"]').val(),
                                       min_qty: row.find('input[name="rule_min_qty[]"]').val(),
                                       max_qty: row.find('input[name="rule_max_qty[]"]').val(),
                                       base_weight: row.find('input[name="rule_base_weight[]"]').val(),
                                       base_cost: row.find('input[name="rule_base_cost[]"]').val(),
                                       per_kg: row.find('input[name="rule_per_kg[]"]').val(),
                                       enabled: 'yes'
                            });
                            });
                            $('#<?php echo esc_attr( $field_key ); ?>').val(JSON.stringify(rules));

                            if ( $rows.length > 0 ) {
                                $('#asia-bulk-actions').show();
                            } else {
                                $('#asia-bulk-actions').hide();
                                $('#asia-select-all').prop('checked', false);
                            }
                        }

                        function updateStateInput(row) {
                            var countryCode = row.find('.rule-country-input').val();
                            var stateCell = row.find('.state-cell');
                            var currentStateInput = row.find('.rule-state-input');
                            var currentValue = currentStateInput.val();

                            if ( currentStateInput.hasClass('select2-hidden-accessible') ) currentStateInput.select2('destroy');

                            // CHECK MULTIPLE COUNTRIES
                            var isMulti = false;
                            var singleCode = '';

                    // Check if countryCode is Array or String
                    if ( Array.isArray(countryCode) ) {
                        if ( countryCode.length > 1 ) isMulti = true;
                        else if ( countryCode.length === 1 ) singleCode = countryCode[0];
                    } else if ( typeof countryCode === 'string' ) {
                        if ( countryCode.indexOf(',') > -1 ) isMulti = true;
                        else if ( countryCode !== '' && countryCode !== '*' ) singleCode = countryCode.trim(); // ADDED TRIM HERE FOR SAFETY
                    }

                    if ( isMulti ) {
                        // RENDER DISABLED TEXT INPUT FOR MULTI-COUNTRY
                        var disabledInput = '<input type="text" class="asia-rule-input rule-state-input" name="rule_state[]" value="*" placeholder="*" disabled style="background-color:#f0f0f1; cursor:not-allowed;" title="<?php _e("State selection disabled for multiple countries", "Asia-Postal-Shipping"); ?>">';
                    stateCell.html(disabledInput);
                    }
                    else if ( singleCode && asia_states_data.hasOwnProperty(singleCode) ) {
                        // RENDER SELECT FOR SINGLE COUNTRY
                        var states = asia_states_data[singleCode];
                        var selectHtml = '<select multiple="multiple" class="asia-rule-input rule-state-input wc-enhanced-select" name="rule_state[]">';
                    selectHtml += '<option value="*">' + asia_i18n.any_state + '</option>';
                    var selectedValues = [];
                    if ( typeof currentValue === 'string' ) selectedValues = currentValue.split(',');
                    else if ( Array.isArray(currentValue) ) selectedValues = currentValue;

                    $.each(states, function(code, name) {
                        var isSelected = ( $.inArray(code, selectedValues) !== -1 ) ? 'selected' : '';
                    selectHtml += '<option value="'+code+'" '+isSelected+'>'+name+'</option>';
                    });
                    selectHtml += '</select>';
                    stateCell.html(selectHtml);
                    row.find('.rule-state-input').select2({ placeholder: asia_i18n.any_state, width: '100%' });
                    } else {
                        // RENDER TEXT INPUT (DEFAULT)
                        if ( currentStateInput.is('select') || currentStateInput.prop('disabled') ) {
                            stateCell.html('<input type="text" class="asia-rule-input rule-state-input" name="rule_state[]" value="*" placeholder="*">');
                        }
                    }
                    serializeRules();
                        }

                        function updateCountryInput(row) {
                            var cell = row.find('.country-cell');
                            var input = row.find('.rule-country-input');
                            var currentVal = input.val();

                            if ( input.hasClass('select2-hidden-accessible') ) input.select2('destroy');

                            // If input is text, convert to select
                            if ( input.is('input') ) {
                                var selectHtml = '<select multiple="multiple" class="asia-rule-input rule-country-input wc-enhanced-select" name="rule_country[]">';
                    selectHtml += '<option value="*">' + asia_i18n.any_country + '</option>';

                    var selectedValues = [];
                    if ( typeof currentVal === 'string' ) {
                        // CRITICAL FIX: TRIM SPACES SO " LA" BECOMES "LA"
                        selectedValues = currentVal.split(',').map(function(s) { return s.trim(); });
                    }
                    else if ( Array.isArray(currentVal) ) selectedValues = currentVal;

                    $.each(asia_countries_data, function(code, name) {
                        var isSelected = ( $.inArray(code, selectedValues) !== -1 ) ? 'selected' : '';
                    selectHtml += '<option value="'+code+'" '+isSelected+'>'+name+'</option>';
                    });
                    selectHtml += '</select>';
                    cell.html(selectHtml);

                    // Re-bind and init
                    row.find('.rule-country-input').select2({
                        placeholder: asia_i18n.any_country,
                        width: '100%'
                    });
                            } else {
                                // Already select, re-init if needed
                                row.find('.rule-country-input').select2({
                                    placeholder: asia_i18n.any_country,
                                    width: '100%'
                                });
                            }
                        }

                        // Helper to escape HTML to prevent XSS
                        function esc_attr(str) {
                            if (!str) return '';
                            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                        }

                        // Build Row HTML Helper
                        function buildRowHtml( data ) {
                            var classOptions = '';
                            $.each(asia_shipping_classes, function(slug, name) {
                                var selected = (data.shipping_class == slug) ? 'selected' : '';
                                classOptions += '<option value="'+slug+'" '+selected+'>'+name+'</option>';
                            });
                            
                            var d = Object.assign({
                                label: '', country: '', state: '*', postcode: '*',
                                delivery_time: '', min_weight: 0, max_weight: '*',
                                min_total: 0, max_total: '*', min_qty: 0, max_qty: '*',
                                base_weight: 0, base_cost: 0, per_kg: 0
                            }, data);

                            // FIXED: Use esc_attr to sanitize values before inserting into HTML
                            return '<tr class="asia-rule-row">' +
                            '<td style="text-align:center; vertical-align:middle;"><input type="checkbox" class="asia-select-row"></td>' +
                            '<td class="asia-sort-handle"><span class="dashicons dashicons-menu"></span><span class="asia-row-index"></span></td>' +
                            '<td><input type="text" class="asia-rule-input" name="rule_label[]" value="' + esc_attr(d.label) + '"></td>' +
                            '<td class="country-cell"><input type="text" class="asia-rule-input rule-country-input" name="rule_country[]" value="' + esc_attr(d.country) + '" placeholder="JP"></td>' +
                            '<td class="state-cell"><input type="text" class="asia-rule-input rule-state-input" name="rule_state[]" value="' + esc_attr(d.state) + '" placeholder="*"></td>' +
                            '<td><input type="text" class="asia-rule-input" name="rule_postcode[]" value="' + esc_attr(d.postcode) + '" placeholder="*"></td>' +
                            '<td><select class="asia-rule-input" name="rule_shipping_class[]">' + classOptions + '</select></td>' +
                            '<td><input type="text" class="asia-rule-input" name="rule_delivery_time[]" value="' + esc_attr(d.delivery_time) + '"></td>' +
                            '<td><div class="asia-input-group"><input type="number" step="0.01" class="asia-rule-input asia-rule-small" name="rule_min_weight[]" placeholder="0" value="' + esc_attr(d.min_weight) + '"><span>-</span><input type="text" class="asia-rule-input asia-rule-small" name="rule_max_weight[]" placeholder="*" value="' + esc_attr(d.max_weight) + '"></div></td>' +
                            '<td><div class="asia-input-group"><input type="number" step="0.01" class="asia-rule-input asia-rule-small" name="rule_min_total[]" placeholder="0" value="' + esc_attr(d.min_total) + '"><span>-</span><input type="text" class="asia-rule-input asia-rule-small" name="rule_max_total[]" placeholder="*" value="' + esc_attr(d.max_total) + '"></div></td>' +
                            '<td><div class="asia-input-group"><input type="number" step="1" class="asia-rule-input asia-rule-small" name="rule_min_qty[]" placeholder="0" value="' + esc_attr(d.min_qty) + '"><span>-</span><input type="text" class="asia-rule-input asia-rule-small" name="rule_max_qty[]" placeholder="*" value="' + esc_attr(d.max_qty) + '"></div></td>' +
                            '<td><input type="number" step="0.01" class="asia-rule-input" name="rule_base_weight[]" value="' + esc_attr(d.base_weight) + '"></td>' +
                            '<td><div style="display:grid; gap:5px;"><div class="asia-input-group"><span style="white-space:nowrap;" class="asia-label-base">' + asia_i18n.base + '</span><input type="number" step="0.01" class="asia-rule-input asia-rule-small" name="rule_base_cost[]" value="' + esc_attr(d.base_cost) + '"></div><div class="asia-input-group"><span style="white-space:nowrap;" class="asia-label-perkg">' + asia_i18n.per_kg + '</span><input type="number" step="0.01" class="asia-rule-input asia-rule-small" name="rule_per_kg[]" value="' + esc_attr(d.per_kg) + '"></div></div></td>' +
                            '<td class="asia-action-cell">' +
                            '<span class="dashicons dashicons-admin-page asia-duplicate-btn duplicate-row tips" data-tip="' + asia_i18n.duplicate_tip + '"></span>' +
                            '<span class="dashicons dashicons-trash asia-remove-btn remove-row tips" data-tip="' + asia_i18n.remove_tip + '"></span>' +
                            '</td>' +
                            '</tr>';
                        }

                        $(document).on('change keyup blur', '.asia-rule-input, .rule-state-input', function() { serializeRules(); });

                        // Listen for Country Change to trigger State Update
                        $(document).on('change', '.rule-country-input', function() {
                            updateStateInput($(this).closest('tr'));
                            serializeRules();
                        });

                        $(document).on('click', '.duplicate-row', function() {
                            var row = $(this).closest('tr');
                            var data = {
                                label: row.find('input[name="rule_label[]"]').val(),
                                       country: row.find('.rule-country-input').val(), // Get val from select2/input
                                       state: row.find('.rule-state-input').val(),
                                       postcode: row.find('input[name="rule_postcode[]"]').val(),
                                       shipping_class: row.find('select[name="rule_shipping_class[]"]').val(),
                                       delivery_time: row.find('input[name="rule_delivery_time[]"]').val(),
                                       min_weight: row.find('input[name="rule_min_weight[]"]').val(),
                                       max_weight: row.find('input[name="rule_max_weight[]"]').val(),
                                       min_total: row.find('input[name="rule_min_total[]"]').val(),
                                       max_total: row.find('input[name="rule_max_total[]"]').val(),
                                       min_qty: row.find('input[name="rule_min_qty[]"]').val(),
                                       max_qty: row.find('input[name="rule_max_qty[]"]').val(),
                                       base_weight: row.find('input[name="rule_base_weight[]"]').val(),
                                       base_cost: row.find('input[name="rule_base_cost[]"]').val(),
                                       per_kg: row.find('input[name="rule_per_kg[]"]').val()
                            };
                            if ( Array.isArray(data.country) ) data.country = data.country.join(',');
                            if ( Array.isArray(data.state) ) data.state = data.state.join(',');

                            var newRow = $(buildRowHtml(data));
                            row.after(newRow);
                            updateCountryInput(newRow); // Init Country Select2
                            updateStateInput(newRow); // Init State Select2
                            serializeRules();
                            initTooltips();
                        });

                        $('#asia-load-presets').click(function() {
                            // Get selected carrier from the fuzzy selector
                            var $select = $('select').filter(function() {
                                return this.id.match(/carrier_brand$/);
                            });
                            var carrier = $select.val();
                            var service = $('#asia-service-select').val();
                            if ( asia_presets.hasOwnProperty(carrier) && asia_presets[carrier].hasOwnProperty(service) ) {
                                if ( confirm(asia_i18n.preset_confirm) ) {
                                    $('#asia_post_rules_tbody').empty();
                                    var zones = asia_presets[carrier][service];

                                    // BATCH INSERT: Create all rows first, append once
                                    var fragment = document.createDocumentFragment();
                                    $.each(zones, function(index, zone) {
                                        // Zone comes with spaces "TH, VN", we leave it as string here.
                                        // updateCountryInput will handle the trim.
                                        var newRow = $(buildRowHtml(zone));
                                        $('#asia_post_rules_tbody').append(newRow); // Append individually to init select2 on each
                                        updateCountryInput(newRow); // Init Country Select2 (Handles trim)
                                    updateStateInput(newRow); // Init State Select2
                                    });

                                    // SAVE ONLY ONCE AT THE END
                                    serializeRules();
                                    initTooltips();
                                }
                            }
                        });

                        $('#add_asia_rule').click(function() {
                            var $select = $('select').filter(function() {
                                return this.id.match(/carrier_brand$/);
                            });
                            var selectedCarrier = $select.val();
                            var $customLabelInput = $('input').filter(function() {
                                return this.id.match(/custom_carrier_label$/);
                            });
                            var customLabel = $customLabelInput.val();
                            var defaultLabel = asia_i18n.standard;
                            var defaultCountry = '';

                        if ( asia_carrier_map.hasOwnProperty(selectedCarrier) ) {
                            defaultLabel = asia_carrier_map[selectedCarrier].label;
                            defaultCountry = asia_carrier_map[selectedCarrier].code;
                        } else if ( selectedCarrier === 'generic' && customLabel.trim() !== '' ) {
                            defaultLabel = customLabel;
                        }

                        var newRow = $(buildRowHtml({
                            label: defaultLabel,
                            country: defaultCountry,
                            state: '*',
                            postcode: '*',
                            shipping_class: '*',
                            delivery_time: '',
                            base_weight: 0,
                            base_cost: 0,
                            per_kg: 0
                        }));

                        $('#asia_post_rules_tbody').append(newRow);
                        updateCountryInput(newRow); // Init Country Select2
                        updateStateInput(newRow); // Init State Select2
                        serializeRules();
                        initTooltips();
                        });

                        $(document).on('click', '.remove-row', function() {
                            if(confirm(asia_i18n.remove_confirm)) { $(this).closest('tr').remove(); serializeRules(); }
                        });

                        $('#asia-select-all').change(function() {
                            $('.asia-select-row').prop('checked', $(this).prop('checked'));
                        });

                        $('#asia-delete-selected').click(function() {
                            var selected = $('.asia-select-row:checked');
                            if ( selected.length > 0 ) {
                                if ( confirm(asia_i18n.bulk_delete_confirm) ) {
                                    selected.closest('tr').remove();
                                    $('#asia-select-all').prop('checked', false);
                                    serializeRules();
                                }
                            } else {
                                alert('No rules selected.');
                            }
                        });

                        // Init existing rows
                        $('.asia-rule-row').each(function() {
                            updateCountryInput($(this));
                            updateStateInput($(this));
                        });
                        serializeRules();
                    });
                    </script>
                    <?php
                    return ob_get_clean();
                }
            }
        }
    }
    add_action( 'woocommerce_shipping_init', 'asia_post_shipping_init' );
    function add_asia_post_shipping_method( $methods ) {
        $methods['asia_post_table_rate'] = 'WC_Asia_Post_Shipping_Method';
        return $methods;
    }
    add_filter( 'woocommerce_shipping_methods', 'add_asia_post_shipping_method' );
}

add_action( 'add_meta_boxes', 'asia_post_add_tracking_meta_box' );
function asia_post_add_tracking_meta_box() {
    add_meta_box( 'asia_post_tracking_box', __( 'Asia Post Tracking Generator', 'Asia-Postal-Shipping' ), 'asia_post_render_tracking_box', 'shop_order', 'side', 'default' );
}
function asia_post_render_tracking_box( $post ) {
    $order = wc_get_order( $post->ID );
    if ( ! $order ) return;
    $pattern = '';
    foreach ( $order->get_shipping_methods() as $shipping_item ) {
        if ( $shipping_item->get_method_id() === 'asia_post_table_rate' ) {
            $instance_id = $shipping_item->get_instance_id();
            $settings = get_option( 'woocommerce_asia_post_table_rate_' . $instance_id . '_settings' );
            if ( ! empty( $settings['tracking_url_pattern'] ) ) {
                $pattern = $settings['tracking_url_pattern'];
                break;
            }
        }
    }
    if ( empty( $pattern ) ) {
        echo '<p>' . __( 'No tracking pattern configured or Asia Post not used for this order.', 'Asia-Postal-Shipping' ) . '</p>';
        return;
    }
    ?>
    <div class="asia-tracking-generator">
    <p><label for="asia_tracking_num" style="font-weight:bold; display:block; margin-bottom:5px;"><?php _e('Tracking Number:', 'Asia-Postal-Shipping'); ?></label>
    <input type="text" id="asia_tracking_num" style="width:100%; padding:5px;" placeholder="e.g. EB123456789TH"></p>
    <p><label style="font-weight:bold; display:block; margin-bottom:5px;"><?php _e('Generated Link:', 'Asia-Postal-Shipping'); ?></label>
    <textarea id="asia_tracking_result" style="width:100%; height:60px; font-family:monospace;" readonly></textarea></p>
    <p><button type="button" class="button" id="asia_copy_link"><?php _e('Copy Link', 'Asia-Postal-Shipping'); ?></button>
    <span id="asia_copy_msg" style="color:green; display:none; margin-left:5px; font-weight:bold;"><?php _e('Copied!', 'Asia-Postal-Shipping'); ?></span></p>
    </div>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var pattern = '<?php echo esc_js( $pattern ); ?>';
    $('#asia_tracking_num').on('keyup change', function() {
        var num = $(this).val().trim();
        if(num) { var link = pattern.replace('{tracking_number}', num); $('#asia_tracking_result').val(link); }
        else { $('#asia_tracking_result').val(''); }
    });
    $('#asia_copy_link').click(function() {
        var copyText = document.getElementById("asia_tracking_result");
        copyText.select(); document.execCommand("copy"); $('#asia_copy_msg').fadeIn().delay(1000).fadeOut();
    });
    });
    </script>
    <?php
}
?>
