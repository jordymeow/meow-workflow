<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * WooCommerce integration — order triggers and order actions, the most common
 * automation needs of a WordPress shop. Gated on WooCommerce being active;
 * uses only stable public hooks and wc_get_order().
 */
class Meow_MWFLOW_Integrations_Woocommerce {

  public function __construct( $core ) {
    add_filter( 'mwflow_register_integration', [ $this, 'register' ] );
  }

  public function register( $integrations ) {
    if ( !class_exists( 'WooCommerce' ) ) { return $integrations; }

    $integrations[] = Meow_MWFLOW_SDK::integration( [
      'id'          => 'woocommerce',
      'name'        => __( 'WooCommerce', 'meow-workflow' ),
      'description' => __( 'Orders and shop events.', 'meow-workflow' ),
      'color'       => '#7f54b3',
      'version'     => defined( 'WC_VERSION' ) ? WC_VERSION : '',
      'triggers'    => [
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'on_new_order',
          'name'    => __( 'When a new order comes in', 'meow-workflow' ),
          'hook'    => 'woocommerce_new_order',
          'args'    => 1,
          'outputs' => [
            Meow_MWFLOW_SDK::output( 'order_id', __( 'Order ID', 'meow-workflow' ), 'number' ),
          ],
          'mapper'  => [ __CLASS__, 'map_order_id' ],
        ] ),
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'on_order_completed',
          'name'    => __( 'When an order is completed', 'meow-workflow' ),
          'hook'    => 'woocommerce_order_status_completed',
          'args'    => 1,
          'outputs' => [
            Meow_MWFLOW_SDK::output( 'order_id', __( 'Order ID', 'meow-workflow' ), 'number' ),
          ],
          'mapper'  => [ __CLASS__, 'map_order_id' ],
        ] ),
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'on_order_status_changed',
          'name'    => __( 'When an order changes status', 'meow-workflow' ),
          'hook'    => 'woocommerce_order_status_changed',
          'args'    => 3,
          'outputs' => [
            Meow_MWFLOW_SDK::output( 'order_id', __( 'Order ID', 'meow-workflow' ), 'number' ),
            Meow_MWFLOW_SDK::output( 'from_status', __( 'Old status', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'to_status', __( 'New status', 'meow-workflow' ), 'string' ),
          ],
          'mapper'  => [ __CLASS__, 'map_status_change' ],
        ] ),
      ],
      'actions'     => [
        Meow_MWFLOW_SDK::action( [
          'id'          => 'get_order',
          'name'        => __( 'Get order', 'meow-workflow' ),
          'description' => __( 'Load an order — total, status, customer, and a ready-to-paste summary of its items.', 'meow-workflow' ),
          'icon'        => 'shopping-cart',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'order_id', __( 'Order ID', 'meow-workflow' ), 'number', [ 'required' => true, 'placeholder' => '{{ trigger.order_id }}' ] ),
          ],
          'outputs'     => [
            Meow_MWFLOW_SDK::output( 'id', __( 'ID', 'meow-workflow' ), 'number' ),
            Meow_MWFLOW_SDK::output( 'status', __( 'Status', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'total', __( 'Total', 'meow-workflow' ), 'number' ),
            Meow_MWFLOW_SDK::output( 'currency', __( 'Currency', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'customer_name', __( 'Customer name', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'customer_email', __( 'Customer email', 'meow-workflow' ), 'email' ),
            Meow_MWFLOW_SDK::output( 'item_count', __( 'Number of items', 'meow-workflow' ), 'number' ),
            Meow_MWFLOW_SDK::output( 'items_summary', __( 'Items (bulleted list)', 'meow-workflow' ), 'longtext' ),
            Meow_MWFLOW_SDK::output( 'payment_method', __( 'Payment method', 'meow-workflow' ), 'string' ),
          ],
          'callback'    => [ __CLASS__, 'callback_get_order' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'add_order_note',
          'name'        => __( 'Add order note', 'meow-workflow' ),
          'description' => __( 'Add a note to an order — private by default, or visible to the customer.', 'meow-workflow' ),
          'icon'        => 'file-text',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'order_id', __( 'Order ID', 'meow-workflow' ), 'number', [ 'required' => true, 'placeholder' => '{{ trigger.order_id }}' ] ),
            Meow_MWFLOW_SDK::input( 'note', __( 'Note', 'meow-workflow' ), 'longtext', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'customer_note', __( 'Visible to the customer', 'meow-workflow' ), 'boolean', [ 'default' => false ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'note_id', __( 'Note ID', 'meow-workflow' ), 'number' ) ],
          'callback'    => [ __CLASS__, 'callback_add_order_note' ],
        ] ),
      ],
    ] );
    return $integrations;
  }

  public static function map_order_id( $args ) {
    return [ 'order_id' => (int) ( $args[0] ?? 0 ) ];
  }

  public static function map_status_change( $args ) {
    return [
      'order_id'    => (int) ( $args[0] ?? 0 ),
      'from_status' => (string) ( $args[1] ?? '' ),
      'to_status'   => (string) ( $args[2] ?? '' ),
    ];
  }

  public static function callback_get_order( $inputs ) {
    $order = wc_get_order( (int) $inputs['order_id'] );
    if ( !$order ) { throw new Exception( 'Order not found.' ); }
    $lines = [];
    $count = 0;
    foreach ( $order->get_items() as $item ) {
      $qty = (int) $item->get_quantity();
      $count += $qty;
      $lines[] = sprintf( '- %s × %d (%s)', $item->get_name(), $qty,
        html_entity_decode( wp_strip_all_tags( wc_price( $item->get_total() ) ) ) );
    }
    return [
      'id'             => $order->get_id(),
      'status'         => $order->get_status(),
      'total'          => (float) $order->get_total(),
      'currency'       => $order->get_currency(),
      'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
      'customer_email' => $order->get_billing_email(),
      'item_count'     => $count,
      'items_summary'  => implode( "\n", $lines ),
      'payment_method' => $order->get_payment_method_title(),
    ];
  }

  public static function callback_add_order_note( $inputs ) {
    $order = wc_get_order( (int) $inputs['order_id'] );
    if ( !$order ) { throw new Exception( 'Order not found.' ); }
    $note_id = $order->add_order_note( (string) $inputs['note'], !empty( $inputs['customer_note'] ) ? 1 : 0 );
    return [ 'note_id' => (int) $note_id ];
  }
}
