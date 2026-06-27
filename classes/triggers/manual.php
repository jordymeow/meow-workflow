<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * Manual trigger: no automatic activation. The flow runs only via the
 * "Test once" button in the editor, which hits POST /flows/<id>/run.
 */
class Meow_MWFLOW_Triggers_Manual {
  private $core;
  public function __construct( $core ) { $this->core = $core; }
  public function register_for_flows( $flows ) {}
  public function unregister() {}
}
