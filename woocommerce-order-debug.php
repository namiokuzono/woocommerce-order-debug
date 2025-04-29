<?php
/**
 * Plugin Name: WooCommerce Order Debug
 * Description: Debug tool for tracking WooCommerce order creation and processing
 * Version: 1.0.0
 * Author: Debug Helper
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
function wc_order_debug_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wc_order_debug_missing_wc_notice');
        return false;
    }
    return true;
}

// Display WooCommerce missing notice
function wc_order_debug_missing_wc_notice() {
    ?>
    <div class="error">
        <p><?php _e('WooCommerce Order Debug requires WooCommerce to be installed and active.', 'wc-order-debug'); ?></p>
    </div>
    <?php
}

class WC_Order_Debug {
    private static $instance = null;
    private $log_file;
    private $enabled = true;
    private $order_ids = array();
    private $debug_options = array();

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/wc-order-debug.log';
        
        // Load options
        $this->debug_options = get_option('wc_order_debug_options', array(
            'log_backtrace' => true,
            'log_duplicates' => true,
            'log_payment' => true,
            'log_cart' => true,
            'log_checkout' => true,
            'log_emails' => true,
            'log_meta_changes' => true,
            'log_status_changes' => true,
            'log_actions' => array(),
            'log_filters' => array()
        ));

        $this->setup_hooks();
    }

    private function setup_hooks() {
        // Core WooCommerce hooks
        add_action('woocommerce_new_order', array($this, 'log_new_order'), 10, 1);
        add_action('woocommerce_checkout_order_processed', array($this, 'log_order_processed'), 10, 3);
        add_action('woocommerce_payment_complete', array($this, 'log_payment_complete'), 10, 1);
        add_action('woocommerce_order_status_changed', array($this, 'log_status_change'), 10, 4);
        
        // Cart and checkout hooks
        add_action('woocommerce_add_to_cart', array($this, 'log_add_to_cart'), 10, 6);
        add_action('woocommerce_after_checkout_validation', array($this, 'log_checkout_validation'), 10, 2);
        
        // Email hooks
        add_action('woocommerce_email_before_order_table', array($this, 'log_email_sent'), 10, 4);
        
        // Meta changes
        add_action('updated_post_meta', array($this, 'log_meta_update'), 10, 4);
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function register_settings() {
        register_setting('wc_order_debug_options', 'wc_order_debug_options');
        
        add_settings_section(
            'wc_order_debug_main',
            'Debug Settings',
            array($this, 'settings_section_callback'),
            'wc-order-debug'
        );
        
        add_settings_field(
            'log_backtrace',
            'Log Backtrace',
            array($this, 'checkbox_callback'),
            'wc-order-debug',
            'wc_order_debug_main',
            array('label_for' => 'log_backtrace')
        );
        
        add_settings_field(
            'log_duplicates',
            'Log Duplicate Orders',
            array($this, 'checkbox_callback'),
            'wc-order-debug',
            'wc_order_debug_main',
            array('label_for' => 'log_duplicates')
        );
        
        add_settings_field(
            'log_payment',
            'Log Payment Processing',
            array($this, 'checkbox_callback'),
            'wc-order-debug',
            'wc_order_debug_main',
            array('label_for' => 'log_payment')
        );
        
        add_settings_field(
            'log_cart',
            'Log Cart Changes',
            array($this, 'checkbox_callback'),
            'wc-order-debug',
            'wc_order_debug_main',
            array('label_for' => 'log_cart')
        );
        
        add_settings_field(
            'log_checkout',
            'Log Checkout Process',
            array($this, 'checkbox_callback'),
            'wc-order-debug',
            'wc_order_debug_main',
            array('label_for' => 'log_checkout')
        );
        
        add_settings_field(
            'log_emails',
            'Log Email Sending',
            array($this, 'checkbox_callback'),
            'wc-order-debug',
            'wc_order_debug_main',
            array('label_for' => 'log_emails')
        );
        
        add_settings_field(
            'log_meta_changes',
            'Log Meta Changes',
            array($this, 'checkbox_callback'),
            'wc-order-debug',
            'wc_order_debug_main',
            array('label_for' => 'log_meta_changes')
        );
        
        add_settings_field(
            'log_status_changes',
            'Log Status Changes',
            array($this, 'checkbox_callback'),
            'wc-order-debug',
            'wc_order_debug_main',
            array('label_for' => 'log_status_changes')
        );
    }

    public function settings_section_callback() {
        echo '<p>Configure what information to log during order processing.</p>';
    }

    public function checkbox_callback($args) {
        $option = $args['label_for'];
        $checked = isset($this->debug_options[$option]) ? $this->debug_options[$option] : false;
        echo '<input type="checkbox" id="' . esc_attr($option) . '" name="wc_order_debug_options[' . esc_attr($option) . ']" ' . checked($checked, true, false) . '>';
    }

    public function add_admin_menu() {
        add_menu_page(
            'WC Order Debug',
            'WC Order Debug',
            'manage_options',
            'wc-order-debug',
            array($this, 'render_admin_page'),
            'dashicons-search'
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save settings if form is submitted
        if (isset($_POST['submit'])) {
            $this->debug_options = array_merge($this->debug_options, $_POST['wc_order_debug_options']);
            update_option('wc_order_debug_options', $this->debug_options);
        }

        // Clear log if requested
        if (isset($_POST['clear_log']) && file_exists($this->log_file)) {
            unlink($this->log_file);
        }

        ?>
        <div class="wrap">
            <h1>WooCommerce Order Debug</h1>
            
            <form method="post" action="">
                <?php
                settings_fields('wc_order_debug_options');
                do_settings_sections('wc-order-debug');
                submit_button('Save Settings');
                ?>
            </form>

            <form method="post" action="">
                <input type="submit" name="clear_log" class="button" value="Clear Log">
            </form>

            <h2>Debug Log</h2>
            <div style="background: #fff; padding: 20px; border: 1px solid #ccc; max-height: 500px; overflow-y: auto;">
                <pre><?php
                if (file_exists($this->log_file)) {
                    echo esc_html(file_get_contents($this->log_file));
                } else {
                    echo 'No log entries yet.';
                }
                ?></pre>
            </div>
        </div>
        <?php
    }

    public function log($message, $data = array(), $type = 'info') {
        if (!$this->enabled) {
            return;
        }

        $timestamp = current_time('mysql');
        $log_message = sprintf(
            "[%s] [%s] %s\n",
            $timestamp,
            strtoupper($type),
            $message
        );

        if (!empty($data)) {
            $log_message .= "Data: " . print_r($data, true) . "\n";
        }

        if ($this->debug_options['log_backtrace']) {
            $log_message .= "Backtrace:\n" . $this->get_backtrace() . "\n";
        }

        $log_message .= "----------------------------------------\n";

        error_log($log_message, 3, $this->log_file);
    }

    private function get_backtrace() {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $trace = array();
        
        foreach ($backtrace as $i => $call) {
            if ($i === 0) continue; // Skip this method
            $trace[] = sprintf(
                "#%d %s:%d - %s%s%s()",
                $i,
                basename($call['file']),
                $call['line'],
                $call['class'] ?? '',
                $call['type'] ?? '',
                $call['function']
            );
        }
        
        return implode("\n", $trace);
    }

    public function log_new_order($order_id) {
        if ($this->debug_options['log_duplicates'] && in_array($order_id, $this->order_ids)) {
            $this->log(
                sprintf('DUPLICATE ORDER DETECTED: #%d', $order_id),
                array(
                    'order_type' => get_post_type($order_id),
                    'payment_method' => get_post_meta($order_id, '_payment_method', true),
                    'items' => $this->get_order_items_info($order_id)
                ),
                'error'
            );
        } else {
            $this->order_ids[] = $order_id;
            $this->log(
                sprintf('New order created: #%d', $order_id),
                array(
                    'order_type' => get_post_type($order_id),
                    'payment_method' => get_post_meta($order_id, '_payment_method', true),
                    'items' => $this->get_order_items_info($order_id)
                )
            );
        }
    }

    public function log_order_processed($order_id, $posted_data, $order) {
        $this->log(
            sprintf('Order processed: #%d', $order_id),
            array(
                'posted_data' => $posted_data,
                'order_type' => $order->get_type(),
                'payment_method' => $order->get_payment_method(),
                'items' => $this->get_order_items_info($order_id)
            )
        );
    }

    public function log_payment_complete($order_id) {
        if (!$this->debug_options['log_payment']) return;
        
        $this->log(
            sprintf('Payment completed for order: #%d', $order_id),
            array(
                'order_status' => get_post_status($order_id),
                'payment_method' => get_post_meta($order_id, '_payment_method', true)
            )
        );
    }

    public function log_status_change($order_id, $old_status, $new_status, $order) {
        if (!$this->debug_options['log_status_changes']) return;
        
        $this->log(
            sprintf('Order status changed: #%d from %s to %s', $order_id, $old_status, $new_status),
            array(
                'order_type' => $order->get_type(),
                'payment_method' => $order->get_payment_method()
            )
        );
    }

    public function log_checkout_validation($data, $errors) {
        if (!$this->debug_options['log_checkout']) return;
        
        $cart_items = WC()->cart->get_cart();
        $this->log(
            'Checkout validation',
            array(
                'cart_items' => $this->get_cart_items_info($cart_items),
                'has_errors' => $errors->has_errors(),
                'errors' => $errors->get_error_messages()
            )
        );
    }

    public function log_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        if (!$this->debug_options['log_cart']) return;
        
        $product = wc_get_product($product_id);
        $this->log(
            'Product added to cart',
            array(
                'product_id' => $product_id,
                'name' => $product->get_name(),
                'type' => $product->get_type(),
                'quantity' => $quantity,
                'variation_id' => $variation_id,
                'cart_item_data' => $cart_item_data
            )
        );
    }

    public function log_email_sent($order, $sent_to_admin, $plain_text, $email) {
        if (!$this->debug_options['log_emails']) return;
        
        $email_data = array(
            'email_type' => $email->id,
            'sent_to_admin' => $sent_to_admin,
            'recipient' => $email->get_recipient(),
            'subject' => $email->get_subject(),
            'headers' => $email->get_headers(),
            'attachments' => $email->get_attachments(),
            'order_id' => $order->get_id(),
            'order_status' => $order->get_status(),
            'customer_email' => $order->get_billing_email(),
            'customer_name' => $order->get_formatted_billing_full_name()
        );

        $this->log(
            sprintf('Email sent: %s for order #%d', $email->id, $order->get_id()),
            $email_data,
            'email'
        );
    }

    public function log_meta_update($meta_id, $post_id, $meta_key, $meta_value) {
        if (!$this->debug_options['log_meta_changes']) return;
        
        if (get_post_type($post_id) === 'shop_order') {
            $this->log(
                sprintf('Order meta updated: #%d', $post_id),
                array(
                    'meta_key' => $meta_key,
                    'meta_value' => $meta_value
                )
            );
        }
    }

    private function get_order_items_info($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return array();

        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = array(
                'product_id' => $item->get_product_id(),
                'name' => $item->get_name(),
                'type' => $product ? $product->get_type() : 'unknown',
                'quantity' => $item->get_quantity()
            );
        }
        return $items;
    }

    private function get_cart_items_info($cart_items) {
        $items = array();
        foreach ($cart_items as $cart_item) {
            $product = $cart_item['data'];
            $items[] = array(
                'product_id' => $product->get_id(),
                'name' => $product->get_name(),
                'type' => $product->get_type(),
                'quantity' => $cart_item['quantity']
            );
        }
        return $items;
    }
}

// Initialize the plugin only if WooCommerce is active
function wc_order_debug_init() {
    if (wc_order_debug_check_woocommerce()) {
        WC_Order_Debug::get_instance();
    }
}
add_action('plugins_loaded', 'wc_order_debug_init'); 