<?php
/**
 * Plugin Name: Skroutz Order Automator
 * Description: A plugin to handle incoming webhooks and create WooCommerce orders automatically. Provides an admin setup page for configuration.
 * Version: 1.5
 * Author: Tasos Paraskevakis
 * Text Domain: Skroutz Order Automator
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ==========================================================================
   ACTIVATION & DATABASE SETUP
   ========================================================================== */

/**
 * Plugin Activation Hook - Create custom table.
 */
register_activation_hook( __FILE__, 'soa_create_webhook_table' );
function soa_create_webhook_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'webhook_data';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        data LONGTEXT NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

/* ==========================================================================
   CUSTOM ORDER STATUS & STOCK HANDLING
   ========================================================================== */

/**
 * Add custom styles for order status in admin.
 */
add_action( 'admin_head', 'soa_add_custom_order_status_styles' );
function soa_add_custom_order_status_styles() {
    echo '<style>
        .order-status.status-skroutz {
            background: #f68b24;
            color: black;
        }
    </style>';
}

/**
 * Register custom order status.
 */
add_action( 'init', 'soa_register_skroutz_order_status' );
function soa_register_skroutz_order_status() {
    register_post_status( 'wc-skroutz', array(
        'label'                     => esc_html__( 'Skroutz', 'Skroutz Order Automator' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        // translators: %s is the number of Skroutz orders.
        'label_count'               => _n_noop( 'Skroutz (%s)', 'Skroutz (%s)', 'Skroutz Order Automator' )
    ) );
}

/**
 * Add custom order status to WooCommerce statuses.
 */
add_filter( 'wc_order_statuses', 'soa_add_skroutz_to_order_statuses' );
function soa_add_skroutz_to_order_statuses( $order_statuses ) {
    $new_order_statuses = array();
    foreach ( $order_statuses as $key => $status ) {
        $new_order_statuses[ $key ] = $status;
        if ( 'wc-processing' === $key ) {
            $new_order_statuses['wc-skroutz'] = esc_html__( 'Skroutz', 'Skroutz Order Automator' );
        }
    }
    return $new_order_statuses;
}

/**
 * Reduce stock when order status changes to skroutz.
 */
add_action( 'woocommerce_order_status_skroutz', 'soa_reduce_stock_for_skroutz_status' );
function soa_reduce_stock_for_skroutz_status( $order_id ) {
    if ( ! $order_id ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    $stock_changes = array();
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( $product && $product->managing_stock() ) {
            $qty       = $item->get_quantity();
            $old_stock = $product->get_stock_quantity();
            $new_stock = $old_stock - $qty;
            $product->set_stock_quantity( $new_stock );
            $product->save();
            $stock_changes[] = sprintf( '%s (%s) %d→%d',
                esc_html( $product->get_name() ),
                esc_html( $product->get_sku() ),
                $old_stock,
                $new_stock
            );
        }
    }
    if ( ! empty( $stock_changes ) ) {
        $stock_change_note = esc_html__( 'Τα επίπεδα αποθέματος μειώθηκαν: ', 'Skroutz Order Automator' ) . implode( ', ', $stock_changes );
        $order->add_order_note( $stock_change_note );
    }
}

/**
 * Restore stock when order is cancelled.
 */
add_action( 'woocommerce_order_status_cancelled', 'soa_restore_stock_for_cancelled_order' );
function soa_restore_stock_for_cancelled_order( $order_id ) {
    if ( ! $order_id ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    $stock_changes = array();
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( $product && $product->managing_stock() ) {
            $qty       = $item->get_quantity();
            $old_stock = $product->get_stock_quantity();
            $new_stock = $old_stock + $qty;
            $product->set_stock_quantity( $new_stock );
            $product->save();
            $stock_changes[] = sprintf( '%s (%s) %d→%d',
                esc_html( $product->get_name() ),
                esc_html( $product->get_sku() ),
                $old_stock,
                $new_stock
            );
        }
    }
    if ( ! empty( $stock_changes ) ) {
        $stock_change_note = esc_html__( 'Τα επίπεδα αποθέματος αυξήθηκαν: ', 'Skroutz Order Automator' ) . implode( ', ', $stock_changes );
        $order->add_order_note( $stock_change_note );
    }
}

/* ==========================================================================
   REST API ENDPOINT & WEBHOOK HANDLER
   ========================================================================== */

/**
 * Register custom REST API endpoint for receiving webhooks.
 */
add_action( 'rest_api_init', function () {
    $slug = get_option( 'soa_webhook_slug', 'receive' );
    register_rest_route( 'custom-webhook/v1', '/' . $slug, array(
        'methods'             => 'POST',
        'callback'            => 'soa_handle_webhook',
        'permission_callback' => '__return_true',
    ) );
} );

/**
 * Handle incoming webhook data.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function soa_handle_webhook( WP_REST_Request $request ) {
    global $wpdb;

    // If a secret is set in settings, verify it.
    $stored_secret = get_option( 'soa_webhook_secret', '' );
    if ( ! empty( $stored_secret ) ) {
        $provided_secret = $request->get_param( 'secret' );
        if ( $provided_secret !== $stored_secret ) {
            return new WP_REST_Response( array(
                'status'  => 'error',
                'message' => esc_html__( 'Invalid secret provided.', 'Skroutz Order Automator' )
            ), 403 );
        }
    }

    // Get the JSON parameters.
    $data = $request->get_json_params();

    // If no data is received, return error.
    if ( empty( $data ) ) {
        return new WP_REST_Response( array(
            'status'  => 'error',
            'message' => esc_html__( 'No data received or data is invalid JSON', 'Skroutz Order Automator' )
        ), 400 );
    }

    // Encode the data back to JSON and save it in the database.
    $json_data  = wp_json_encode( $data );
    $table_name = esc_sql( $wpdb->prefix . 'webhook_data' );
    $result     = $wpdb->insert(//db call ok
        $table_name,
        array(
            'data' => $json_data,
        )
    );

    if ( $result === false ) {
        return new WP_REST_Response( array(
            'status'  => 'error',
            'message' => esc_html__( 'Failed to insert data', 'Skroutz Order Automator' )
        ), 500 );
    }

    // Process the order creation.
    soa_create_order_from_webhook_data( $data );

    return new WP_REST_Response( array( 'status' => 'success' ), 200 );
}

/**
 * Create a WooCommerce order from the webhook data.
 *
 * @param array $order_data
 */
function soa_create_order_from_webhook_data( $order_data ) {
    // Ensure WooCommerce functions are available.
    if ( ! function_exists( 'wc_create_order' ) ) {
        return;
    }

    // Check for new order event.
    if ( ! isset( $order_data['event_type'] ) || $order_data['event_type'] !== 'new_order' ) {
        echo esc_html__( 'Invalid JSON data or not a new order event.', 'Skroutz Order Automator' );
        return;
    }

    // Avoid duplicate orders using a unique ID.
    if ( isset( $order_data['unique_order_id'] ) && ! empty( $order_data['unique_order_id'] ) ) {
        $existing_order_id = wc_get_order_id_by_unique_meta( $order_data['unique_order_id'] );
        if ( $existing_order_id ) {
            echo esc_html__( 'Duplicate order detected.', 'Skroutz Order Automator' );
            return;
        }
    }

    // Create a new order.
    $order = wc_create_order();

    // Set customer information.
    $customer_data = $order_data['order']['customer'];

    $billing_address = array(
        'first_name' => isset( $customer_data['first_name'] ) ? $customer_data['first_name'] : '',
        'last_name'  => isset( $customer_data['last_name'] ) ? $customer_data['last_name'] : '',
        'address_1'  => ( isset( $customer_data['address']['street_name'] ) && isset( $customer_data['address']['street_number'] ) )
            ? $customer_data['address']['street_name'] . ' ' . $customer_data['address']['street_number']
            : '',
        'city'       => isset( $customer_data['address']['city'] ) ? $customer_data['address']['city'] : '',
        'state'      => isset( $customer_data['address']['region'] ) ? $customer_data['address']['region'] : '',
        'postcode'   => isset( $customer_data['address']['zip'] ) ? $customer_data['address']['zip'] : '',
        'country'    => isset( $customer_data['address']['country_code'] ) ? $customer_data['address']['country_code'] : '',
        'email'      => isset( $customer_data['id'] ) ? $customer_data['id'] . '@auto.skroutz' : '',
        'phone'      => isset( $customer_data['phone'] ) ? $customer_data['phone'] : '',
    );

    // If invoice is true, adjust billing and shipping addresses.
    if ( isset( $order_data['order']['invoice'] ) && $order_data['order']['invoice'] === true ) {
        $billing_address['VAT'] = isset( $order_data['order']['invoice_details']['vat_number'] ) ? $order_data['order']['invoice_details']['vat_number'] : '';
        $shipping_address = $billing_address;
        $billing_address['address_1'] = isset( $order_data['order']['invoice_details']['address']['street_name'] )
            ? $order_data['order']['invoice_details']['address']['street_name']
            : $billing_address['address_1'];
        $order->set_address( $shipping_address, 'shipping' );
    }
    $order->set_address( $billing_address, 'billing' );

    // Add line items to the order.
    if ( isset( $order_data['order']['line_items'] ) && is_array( $order_data['order']['line_items'] ) ) {
        foreach ( $order_data['order']['line_items'] as $item ) {
            // Assuming SKU matches shop_uid.
            $product_id = wc_get_product_id_by_sku( $item['shop_uid'] );
            if ( $product_id ) {
                $product   = wc_get_product( $product_id );
                $tax_rates = array(
                    ''             => 1.24,
                    'reduced-rate' => 1.13,
                    'low-rate'     => 1.06,
                );

                // Get the tax class of the product.
                $vat_rate = ( $product->get_tax_status() === 'taxable' ) ? $product->get_tax_class() : '';
                $tax_rate = isset( $tax_rates[ $vat_rate ] ) ? $tax_rates[ $vat_rate ] : 1;

                // Calculate the price excluding VAT.
                $price_incl_vat = isset( $item['unit_price'] ) ? $item['unit_price'] : 0;
                $price_excl_vat = $price_incl_vat / $tax_rate;

                // Add product to the order.
                $order->add_product( $product, $item['quantity'], array(
                    'subtotal' => $price_excl_vat * $item['quantity'],
                    'total'    => $price_excl_vat * $item['quantity']
                ) );
            } else {
                echo sprintf(
                    // translators: %s is the product shop_uid that was not found.
                    esc_html__( 'Product with shop_uid %s not found.', 'Skroutz Order Automator' ),
                    esc_html( $item['shop_uid'] )
                );
                return;
            }
        }
    }

    // Add custom meta data.
    $order->update_meta_data( '_billing_done', 0 );
    $order->update_meta_data( '_skroutz_id', isset( $order_data['order']['code'] ) ? $order_data['order']['code'] : '' );
    // Set custom order status.
    $order->set_status( 'wc-skroutz' );
    // Calculate totals and save the order.
    $order->calculate_totals();
    $order->save();

    // Save unique order ID if available.
    if ( isset( $order_data['unique_order_id'] ) && ! empty( $order_data['unique_order_id'] ) ) {
        $order->update_meta_data( '_unique_order_id', $order_data['unique_order_id'] );
    }

    $order_id = $order->get_id();
    if ( $order_id ) {
        echo sprintf(
            // translators: %d is the WooCommerce order ID.
            esc_html__( 'Order created successfully with ID: %d', 'Skroutz Order Automator' ),
            absint( $order_id )
        );
    } else {
        echo esc_html__( 'Failed to create order.', 'Skroutz Order Automator' );
    }
}

/* ==========================================================================
   ADMIN SETTINGS PAGE
   ========================================================================== */

/**
 * Register the plugin settings page in the admin menu.
 */
add_action( 'admin_menu', 'soa_register_admin_menu' );
function soa_register_admin_menu() {
    add_menu_page(
        esc_html__( 'Skroutz Webhook Settings', 'Skroutz Order Automator' ),
        esc_html__( 'Skroutz Webhook Settings', 'Skroutz Order Automator' ),
        'manage_options',
        'soa-settings',
        'soa_settings_page',
        'dashicons-admin-generic'
    );
}

/**
 * Render the settings page.
 */
function soa_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'Skroutz Order Automator' ) );
    }

    // Handle form submission.
    if ( isset( $_POST['soa_settings_nonce'] ) && wp_verify_nonce( esc_url_raw(wp_unslash( $_POST['soa_settings_nonce'] )), 'soa_save_settings' ) ) {
        $webhook_secret = isset( $_POST['soa_webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['soa_webhook_secret'] ) ) : '';
        update_option( 'soa_webhook_secret', $webhook_secret );

        $webhook_slug = isset( $_POST['soa_webhook_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['soa_webhook_slug'] ) ) : '';
        update_option( 'soa_webhook_slug', $webhook_slug );

        echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'Skroutz Order Automator' ) . '</p></div>';
    }

    $webhook_secret = get_option( 'soa_webhook_secret', '' );
    $webhook_slug   = get_option( 'soa_webhook_slug', 'receive' );
    // Build the webhook URL.
    $webhook_url = esc_url( rest_url( 'custom-webhook/v1/' . $webhook_slug ) );
    if ( ! empty( $webhook_secret ) ) {
        $webhook_url .= '?secret=' . urlencode( $webhook_secret );
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Skroutz Webhook Settings', 'Skroutz Order Automator' ); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field( 'soa_save_settings', 'soa_settings_nonce' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="soa_webhook_secret"><?php echo esc_html__( 'Webhook Secret', 'Skroutz Order Automator' ); ?></label>
                    </th>
                    <td>
                        <input type="text" name="soa_webhook_secret" id="soa_webhook_secret" value="<?php echo esc_attr( $webhook_secret ); ?>" class="regular-text" />
                        <p class="description"><?php echo esc_html__( 'Optional secret key to secure your webhook endpoint. If set, the incoming webhook must include the secret (e.g. via ?secret=yoursecret).', 'Skroutz Order Automator' ); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="soa_webhook_slug"><?php echo esc_html__( 'Webhook Endpoint Slug', 'Skroutz Order Automator' ); ?></label>
                    </th>
                    <td>
                        <input type="text" name="soa_webhook_slug" id="soa_webhook_slug" value="<?php echo esc_attr( $webhook_slug ); ?>" class="regular-text" />
                        <p class="description">
                            <?php echo esc_html__( 'Set the slug portion of the webhook endpoint URL. For example, if you enter "neworder", your endpoint will be:', 'Skroutz Order Automator' ); ?>
                            <code><?php echo esc_url( rest_url( 'custom-webhook/v1/neworder' ) ); ?></code>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <hr>
        <h2><?php echo esc_html__( 'Webhook Endpoint', 'Skroutz Order Automator' ); ?></h2>
        <p><?php echo esc_html__( 'Your webhook endpoint URL is:', 'Skroutz Order Automator' ); ?></p>
        <div style="display:flex; align-items: center;">
            <code id="webhookUrl" style="margin-right: 10px;"><?php echo esc_url( $webhook_url ); ?></code>
            <button type="button" id="copyWebhookUrl" class="button"><?php echo esc_html__( 'Copy', 'Skroutz Order Automator' ); ?></button>
        </div>
    </div>
    <script>
    document.getElementById('copyWebhookUrl').addEventListener('click', function() {
        var webhookUrl = document.getElementById('webhookUrl').innerText;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(webhookUrl).then(function() {
                alert('<?php echo esc_js( __( 'Copied to clipboard!', 'Skroutz Order Automator' ) ); ?>');
            }, function(err) {
                alert('<?php echo esc_js( __( 'Error copying text: ', 'Skroutz Order Automator' ) ); ?>' + err);
            });
        } else {
            // Fallback for browsers that don't support navigator.clipboard
            var tempInput = document.createElement('input');
            tempInput.style.position = 'absolute';
            tempInput.style.left = '-9999px';
            tempInput.value = webhookUrl;
            document.body.appendChild(tempInput);
            tempInput.select();
            try {
                var successful = document.execCommand('copy');
                if(successful){
                    alert('<?php echo esc_js( __( 'Copied to clipboard!', 'Skroutz Order Automator' ) ); ?>');
                } else {
                    alert('<?php echo esc_js( __( 'Error copying text.', 'Skroutz Order Automator' ) ); ?>');
                }
            } catch (err) {
                alert('<?php echo esc_js( __( 'Error copying text: ', 'Skroutz Order Automator' ) ); ?>' + err);
            }
            document.body.removeChild(tempInput);
        }
    });
    </script>
    <?php
}