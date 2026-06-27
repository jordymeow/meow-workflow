<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * Webhook trigger: a public REST endpoint at /meow-workflow/v1/hook/<token>
 * gets routed to the matching flow. The endpoint itself is registered in
 * classes/rest.php; this class only owns token allocation and metadata.
 *
 * trigger_config shape:
 *   token: 24-char alphanumeric string (generated on first save if missing)
 */
class Meow_MWFLOW_Triggers_Webhook {
  private $core;

  public function __construct( $core ) {
    $this->core = $core;
  }

  public function register_for_flows( $flows ) {
    // Routing happens in classes/rest.php::handle_webhook. We make sure each
    // webhook flow has a token (generate one if missing).
    foreach ( $flows as $flow ) {
      if ( $flow['trigger_type'] !== 'webhook' ) { continue; }
      if ( empty( $flow['trigger_config']['token'] ) ) {
        $flow['trigger_config']['token'] = wp_generate_password( 24, false );
        $this->core->save_flow( [
          'name'           => $flow['name'],
          'definition'     => $flow['definition'],
          'is_active'      => true,
          'trigger_type'   => 'webhook',
          'trigger_config' => $flow['trigger_config'],
        ], $flow['id'] );
      }
    }
  }

  public function unregister() {}
}
