<?php
/*
Plugin Name: Progy Purchase Orders
description: Transform your online store into a multifunctional platform that allows you to create POs, manage inventory receipts, create purchase orders and more.
Version: 0.51
Author: Progymedia Inc
Author URI: https://www.progymedia.com
License: GPLv2 or later
Text Domain: progy-purchase-orders
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Set constants
 */
if ( ! defined( 'PROGY_PO_FILE' ) ) {
	define( 'PROGY_PO_FILE', __FILE__ );
}

if ( ! defined( 'PROGY_PO_BASE' ) ) {
	define( 'PROGY_PO_BASE', plugin_basename( PROGY_PO_FILE ) );
}

if ( ! defined( 'PROGY_PO_DIR' ) ) {
	define( 'PROGY_PO_DIR', plugin_dir_path( PROGY_PO_FILE ) );
}

if ( ! defined( 'PROGY_PO_URI' ) ) {
	define( 'PROGY_PO_URI', plugins_url( '/', PROGY_PO_FILE ) );
}

require_once WP_PLUGIN_DIR . '/woocommerce/includes/admin/meta-boxes/class-wc-meta-box-order-items.php';
require_once WP_PLUGIN_DIR . '/woocommerce/includes/admin/meta-boxes/class-wc-meta-box-order-data.php';
require_once WP_PLUGIN_DIR . '/woocommerce/includes/interfaces/class-wc-order-data-store-interface.php';
require_once WP_PLUGIN_DIR . '/woocommerce/includes/interfaces/class-wc-abstract-order-data-store-interface.php';
require_once WP_PLUGIN_DIR . '/woocommerce/includes/interfaces/class-wc-object-data-store-interface.php';
require_once WP_PLUGIN_DIR . '/woocommerce/includes/data-stores/class-wc-data-store-wp.php';
require_once WP_PLUGIN_DIR . '/woocommerce/includes/data-stores/abstract-wc-order-data-store-cpt.php';
require_once WP_PLUGIN_DIR . '/woocommerce/includes/data-stores/class-wc-order-data-store-cpt.php';

class ProgyPurchaseOrders {

    const _POST_TYPE_ = "pmpurchaseorder";

    public function __construct(){
        add_action( 'init', array( $this, 'createStorePostType' ) );
        add_action( 'add_meta_boxes', array( $this, 'removeMetaBoxes' ), 100 );
        add_filter( 'woocommerce_data_stores' , array( $this, 'addPurchaseOrdersDataStore' ), 100);

        // Load correct list table classes for current screen.
        add_action( 'current_screen', array( $this, 'setupScreen' ), 1 );
        add_action( 'check_ajax_referer', array( $this, 'setupScreen' ), 1 );

        add_filter( 'wp_insert_post_data', array( $this, 'insertPostData' ) );
        add_filter( 'oembed_response_data', array( $this, 'filter_oembed_response_data' ), 10, 2 );
        add_filter( 'admin_body_class', array( $this, 'admin_body_class' ));
        add_filter( 'wc_order_statuses', array( $this, 'wc_order_statuses'));
        //woocommerce_new_order
        //woocommerce_update_order

        add_action( 'init', array($this, 'load_plugin_language') );
    }

    function load_plugin_language(){
        $domain = 'progy-purchase-orders';
        $locale = apply_filters( 'plugin_locale', get_locale(), $domain );
        $mo_file = trailingslashit(WP_LANG_DIR) . $domain . '/' . $domain . '-' . $locale . '.mo';
        load_textdomain( $domain, $mo_file );
        load_plugin_textdomain( $domain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    public function createStorePostType() {
        $labels = array(
            'name'                  => __( 'Purchase Orders', 'progy-purchase-orders' ),
            'singular_name'         => _x( 'Purchase Order', 'shop_order post type singular name', 'progy-purchase-orders' ),
            'add_new'               => __( 'Add order', 'progy-purchase-orders' ),
            'add_new_item'          => __( 'Add new order', 'progy-purchase-orders' ),
            'edit'                  => __( 'Edit', 'progy-purchase-orders' ),
            'edit_item'             => __( 'Edit order', 'progy-purchase-orders' ),
            'new_item'              => __( 'New order', 'progy-purchase-orders' ),
            'view_item'             => __( 'View order', 'progy-purchase-orders' ),
            'search_items'          => __( 'Search orders', 'progy-purchase-orders' ),
            'not_found'             => __( 'No orders found', 'progy-purchase-orders' ),
            'not_found_in_trash'    => __( 'No orders found in trash', 'progy-purchase-orders' ),
            'parent'                => __( 'Parent orders', 'progy-purchase-orders' ),
            'menu_name'             => __( 'Purchase Orders', 'progy-purchase-orders' ),
            'filter_items_list'     => __( 'Filter orders', 'progy-purchase-orders' ),
            'items_list_navigation' => __( 'Orders navigation', 'progy-purchase-orders' ),
            'items_list'            => __( 'Orders list', 'progy-purchase-orders' ),
        );

        $args = array(
            'labels'              => $labels,
            'description'         => __( 'This is where store orders are stored.', 'progy-purchase-orders' ),
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'capability_type'     => 'shop_order',
            'map_meta_cap'        => true,
            'exclude_from_search' => true,
            'show_in_menu'        => current_user_can( 'manage_woocommerce' ) ? 'woocommerce' : true,
            'hierarchical'        => false,
            'show_in_nav_menus'   => false,
            'rewrite'             => false,
            'query_var'           => false,
            'supports'            => array( 'title', 'comments', 'custom-fields' ),
            'has_archive'         => false,
        );

        wc_register_order_type(
            ProgyPurchaseOrders::_POST_TYPE_,
            apply_filters(
                'woocommerce_register_post_type_purchase_order',
                $args
            )
        );

        register_post_status( 'wc-po-completed' , array(
            'label' => _x( 'Purchase Received' , 'Order status' , 'progymedia' ) ,
            'public' => true ,
            'exclude_from_search' => false ,
            'show_in_admin_all_list' => true ,
            'show_in_admin_status_list' => true ,
            'label_count' => _n_noop( 'Purchase Received <span class="count">(%s)</span>' ,
                'Purchase Received <span class="count">(%s)</span>' , 'progy-purchase-orders' )
        ) );

        register_post_status( 'wc-po-cancelled' , array(
            'label' => _x( 'Purchase Cancelled' , 'Order status' , 'progy-purchase-orders' ) ,
            'public' => true ,
            'exclude_from_search' => false ,
            'show_in_admin_all_list' => true ,
            'show_in_admin_status_list' => true ,
            'label_count' => _n_noop( 'Purchase Cancelled <span class="count">(%s)</span>' ,
                'Purchase Cancelled <span class="count">(%s)</span>' , 'progy-purchase-orders' )
        ) );

        register_post_status( 'wc-po-returned' , array(
            'label' => _x( 'Purchase Returned' , 'Order status' , 'progy-purchase-orders' ) ,
            'public' => true ,
            'exclude_from_search' => false ,
            'show_in_admin_all_list' => true ,
            'show_in_admin_status_list' => true ,
            'label_count' => _n_noop( 'Purchase Returned <span class="count">(%s)</span>' ,
                'Purchase Returned <span class="count">(%s)</span>' , 'progy-purchase-orders' )
        ) );
    }

    function addPurchaseOrdersDataStore($stores){
        $stores[ProgyPurchaseOrders::_POST_TYPE_] = 'PM_Purchase_Order_Data_Store_CPT';
        return $stores;
    }

    public function removeMetaBoxes() {
        remove_meta_box( 'woocommerce-order-downloads', ProgyPurchaseOrders::_POST_TYPE_, 'normal' );

        remove_all_actions('wp_ajax_woocommerce_add_order_item');
    }

    public function setupScreen(){
        global $wc_list_table;
        if ( function_exists( 'get_current_screen' ) ) {
            $screen    = get_current_screen();
            $screen_id = isset( $screen, $screen->id ) ? $screen->id : '';

            if($screen_id == 'edit-pmpurchaseorder'){
                $wc_list_table = new WC_Admin_List_Table_Purchase_Orders();
            }
        }
    }

    public function insertPostData($data){
        if ( ProgyPurchaseOrders::_POST_TYPE_ === $data['post_type'] && isset( $data['post_date'] ) ) {
            $order_title = 'Order';
            if ( $data['post_date'] ) {
                $order_title .= ' &ndash; ' . date_i18n( 'F j, Y @ h:i A', strtotime( $data['post_date'] ) );
            }
            $data['post_title'] = $order_title;
        }
        return $data;
    }

    public static function filter_oembed_response_data( $data, $post ) {
        if ( in_array( $post->post_type, array( ProgyPurchaseOrders::_POST_TYPE_ ), true ) ) {
            return array();
        }
        return $data;
    }

    //Copy css class from shop_order to purchase order
    public function admin_body_class($classes){
        if ( function_exists( 'get_current_screen' ) ) {
            $screen    = get_current_screen();
            $screen_id = isset( $screen, $screen->id ) ? $screen->id : '';

            if($screen_id == 'edit-pmpurchaseorder'){
                $classes .= ' post-type-shop_order ';
            }
        }
        return $classes;
    }

    public function wc_order_statuses($order_statuses){
        global $post;

        $po_order_statuses = array(
            //'wc-pending'        => _x( 'Pending', 'Order status', 'progy-purchase-orders' ),
            'wc-po-completed'   => _x( 'Received', 'Order status', 'progy-purchase-orders' ),
            'wc-po-cancelled'   => _x( 'Cancelled', 'Order status', 'progy-purchase-orders' ),
            'wc-po-returned'    => _x( 'Returned', 'Order status', 'progy-purchase-orders' )
        );

        $po_order_statuses = apply_filters('progymedia_purchase_orders_statuses',$po_order_statuses);

        if($post->post_type == ProgyPurchaseOrders::_POST_TYPE_){
            //On Order Detail Page
            $order_statuses = $po_order_statuses;
        }else if ( function_exists( 'get_current_screen' ) ) {
            //On Order List Page
            $screen    = get_current_screen();
            $screen_id = isset( $screen, $screen->id ) ? $screen->id : '';

            if($screen_id == 'edit-pmpurchaseorder'){
                $order_statuses = $po_order_statuses;
            }
        }

        return $order_statuses;
    }

}
$progyPurchaseOrders = new ProgyPurchaseOrders();

class PM_Purchase_Order_Data_Store_CPT extends WC_Order_Data_Store_CPT{
}

include_once WP_PLUGIN_DIR . '/woocommerce/includes/admin/list-tables/class-wc-admin-list-table-orders.php';
class WC_Admin_List_Table_Purchase_Orders extends WC_Admin_List_Table_Orders{
    protected $list_table_type = ProgyPurchaseOrders::_POST_TYPE_;
}

add_action( 'woocommerce_order_status_po-completed', 'po_maybe_increase_stock_levels' );
//The is the same function as wc_maybe_reduce_stock_levels except we increase the stock
function po_maybe_increase_stock_levels( $order_id ) {
    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        return;
    }

    $stock_reduced  = $order->get_data_store()->get_stock_reduced( $order_id );
    $trigger_reduce =  apply_filters( 'woocommerce_payment_complete_reduce_order_stock', ! $stock_reduced, $order_id );

    if ( ! $trigger_reduce ) {
        return;
    }

    po_increase_stock_levels( $order );
    $order->get_data_store()->set_stock_reduced( $order_id, true );
}
function po_increase_stock_levels( $order_id ) {
    if ( is_a( $order_id, 'WC_Order' ) ) {
        $order    = $order_id;
        $order_id = $order->get_id();
    } else {
        $order = wc_get_order( $order_id );
    }

    // We need an order, and a store with stock management to continue.
    if ( ! $order || 'yes' !== get_option( 'woocommerce_manage_stock' ) || ! apply_filters( 'woocommerce_can_restore_order_stock', true, $order ) ) {
        return;
    }

    $changes = array();

    // Loop over all items.
    foreach ( $order->get_items() as $item ) {

        if ( ! $item->is_type( 'line_item' ) ) {
            continue;
        }

        // Only reduce stock once for each item.
        $product            = $item->get_product();
        $item_stock_reduced = $item->get_meta( '_reduced_stock', true );

        if ( $item_stock_reduced || ! $product || ! $product->managing_stock() ) {
            continue;
        }

        $qty       = apply_filters( 'purchase_order_item_quantity', $item->get_quantity(), $order, $item );
        $item_name = $product->get_formatted_name();
        $new_stock = wc_update_product_stock( $product, $qty, 'increase' );

        if ( is_wp_error( $new_stock ) ) {
            /* translators: %s item name. */
            $order->add_order_note( sprintf( __( 'Unable to add stock for item %s.', 'progy-purchase-orders' ), $item_name ) );
            continue;
        }

        $item->add_meta_data( '_reduced_stock', $qty, true );
        $item->save();

        $changes[] = $item_name . ' ' . ( $new_stock - $qty ) . '&rarr;' . $new_stock;
    }

    if ( $changes ) {
        $order->add_order_note( __( 'Stock levels increased:', 'progy-purchase-orders' ) . ' ' . implode( ', ', $changes ) );
    }

    do_action( 'woocommerce_restore_order_stock', $order );
}


add_action( 'woocommerce_order_status_po-cancelled', 'po_maybe_reduce_stock_levels' );
function po_maybe_reduce_stock_levels( $order_id ) {
    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        return;
    }

    $stock_reduced  = $order->get_data_store()->get_stock_reduced( $order_id );
    $trigger_reduce = (bool) $stock_reduced;

    // Only continue if we're reducing stock.
    if ( ! $trigger_reduce ) {
        return;
    }

    po_reduce_stock_levels( $order );

    // Ensure stock is marked as "reduced" in case payment complete or other stock actions are called.
    $order->get_data_store()->set_stock_reduced( $order_id, false );
}
function po_reduce_stock_levels( $order_id ) {
    if ( is_a( $order_id, 'WC_Order' ) ) {
        $order    = $order_id;
        $order_id = $order->get_id();
    } else {
        $order = wc_get_order( $order_id );
    }

    // We need an order, and a store with stock management to continue.
    if ( ! $order || 'yes' !== get_option( 'woocommerce_manage_stock' ) || ! apply_filters( 'woocommerce_can_reduce_order_stock', true, $order ) ) {
        return;
    }

    $changes = array();

    // Loop over all items.
    foreach ( $order->get_items() as $item ) {
        if ( ! $item->is_type( 'line_item' ) ) {
            continue;
        }

        // Only reduce stock once for each item.
        $product            = $item->get_product();
        $item_stock_reduced = $item->get_meta( '_reduced_stock', true );

        if ( ! $item_stock_reduced || ! $product || ! $product->managing_stock() ) {
            continue;
        }

        $item_name = $product->get_formatted_name();
        $new_stock = wc_update_product_stock( $product, $item_stock_reduced, 'decrease' );

        if ( is_wp_error( $new_stock ) ) {
            /* translators: %s item name. */
            $order->add_order_note( sprintf( __( 'Unable to reduce stock for item %s.', 'woocommerce' ), $item_name ) );
            continue;
        }

        $item->delete_meta_data( '_reduced_stock' );
        $item->save();

        $changes[] = $item_name . ' ' . ( $new_stock + $item_stock_reduced ) . '&rarr;' . $new_stock;
    }

    if ( $changes ) {
        $order->add_order_note( __( 'Stock levels reduced:', 'woocommerce' ) . ' ' . implode( ', ', $changes ) );
    }

    do_action( 'woocommerce_reduce_order_stock', $order );
}

add_action( 'woocommerce_order_status_po-returned', 'wc_maybe_reduce_stock_levels' );