<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

spl_autoload_register( function ( $class ) {
  $file = null;

  if ( strpos( $class, 'Meow_MWFLOW_Triggers_' ) === 0 ) {
    $filename = strtolower( str_replace( 'Meow_MWFLOW_Triggers_', '', $class ) );
    $filename = str_replace( '_', '-', $filename );
    $file = MWFLOW_PATH . '/classes/triggers/' . $filename . '.php';
  }
  else if ( strpos( $class, 'Meow_MWFLOW_Nodes_' ) === 0 ) {
    $filename = strtolower( str_replace( 'Meow_MWFLOW_Nodes_', '', $class ) );
    $filename = str_replace( '_', '-', $filename );
    $file = MWFLOW_PATH . '/classes/nodes/' . $filename . '.php';
  }
  else if ( strpos( $class, 'Meow_MWFLOW_Integrations_' ) === 0 ) {
    $filename = strtolower( str_replace( 'Meow_MWFLOW_Integrations_', '', $class ) );
    $filename = str_replace( '_', '-', $filename );
    $file = MWFLOW_PATH . '/classes/integrations/' . $filename . '.php';
  }
  else if ( strpos( $class, 'Meow_MWFLOW_' ) === 0 ) {
    $filename = strtolower( str_replace( 'Meow_MWFLOW_', '', $class ) );
    $filename = str_replace( '_', '-', $filename );
    $file = MWFLOW_PATH . '/classes/' . $filename . '.php';
  }
  else if ( strpos( $class, 'MeowKit_MWFLOW_' ) === 0 ) {
    $filename = strtolower( str_replace( 'MeowKit_MWFLOW_', '', $class ) );
    $filename = str_replace( '_', '-', $filename );
    $file = MWFLOW_PATH . '/common/' . $filename . '.php';
  }

  if ( $file && file_exists( $file ) ) {
    require_once( $file );
  }
} );

require_once( MWFLOW_PATH . '/common/helpers.php' );

add_action( 'plugins_loaded', function () {
  global $mwflow_core;
  $mwflow_core = new Meow_MWFLOW_Core();
}, 5 );

register_activation_hook( MWFLOW_ENTRY, [ 'Meow_MWFLOW_Core', 'install' ] );
