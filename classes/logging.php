<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

class Meow_MWFLOW_Logging {
  const LOG_FILE = 'mwflow.log';
  const MAX_SIZE = 5242880; // 5MB

  private $file_path;
  private $enabled;

  public function __construct() {
    $upload = wp_upload_dir();
    $this->file_path = trailingslashit( $upload['basedir'] ) . self::LOG_FILE;
    $this->enabled   = (bool) get_option( 'mwflow_logging_enabled', true );
  }

  public function info( $message, $context = [] )  { $this->log( 'INFO', $message, $context ); }
  public function warn( $message, $context = [] )  { $this->log( 'WARN', $message, $context ); }
  public function error( $message, $context = [] ) { $this->log( 'ERROR', $message, $context ); }

  private function log( $level, $message, $context = [] ) {
    if ( !$this->enabled ) { return; }
    $line = sprintf(
      "[%s] %s %s%s\n",
      gmdate( 'Y-m-d H:i:s' ),
      $level,
      $message,
      !empty( $context ) ? ' ' . wp_json_encode( $context ) : ''
    );
    // Rotate if oversized.
    if ( file_exists( $this->file_path ) && filesize( $this->file_path ) > self::MAX_SIZE ) {
      @rename( $this->file_path, $this->file_path . '.1' );
    }
    @file_put_contents( $this->file_path, $line, FILE_APPEND | LOCK_EX );
  }

  public function tail( $lines = 200 ) {
    if ( !file_exists( $this->file_path ) ) { return ''; }
    $contents = @file( $this->file_path );
    if ( !$contents ) { return ''; }
    return implode( '', array_slice( $contents, -1 * max( 1, (int) $lines ) ) );
  }

  public function clear() {
    @unlink( $this->file_path );
    @unlink( $this->file_path . '.1' );
  }
}
