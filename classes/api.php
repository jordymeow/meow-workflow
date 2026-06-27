<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * Public PHP API. Third-party code can do `global $mwflow; $mwflow->run_flow( $id );`
 * the same way the other engines expose `$mwai`, `$mwseo`, `$mwcode`, `$mwse`.
 */
class Meow_MWFLOW_API {
  private $core;

  public function __construct( $core ) {
    $this->core = $core;
    $GLOBALS['mwflow'] = $this;
  }

  public function run_flow( $flow_id, $payload = [] ) {
    return $this->core->runner->run( (int) $flow_id, (array) $payload );
  }

  public function get_flow( $flow_id ) {
    return $this->core->get_flow( (int) $flow_id );
  }

  public function get_flows() {
    return $this->core->get_flows();
  }

  public function save_flow( $data, $flow_id = null ) {
    return $this->core->save_flow( (array) $data, $flow_id ? (int) $flow_id : null );
  }

  public function delete_flow( $flow_id ) {
    $this->core->delete_flow( (int) $flow_id );
  }

  public function get_integrations() {
    return $this->core->registry->get_integrations_for_ui();
  }
}
