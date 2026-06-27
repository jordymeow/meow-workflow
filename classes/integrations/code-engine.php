<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * Code Engine integration. Calls `global $mwcode` (instance of Meow_MWCODE_API).
 * Reference: /Users/meow/plugins/code-engine-pro/classes/api.php
 *
 * Every callable (function-scope snippet) is registered as its OWN action, so
 * users see their functions directly in the Add-step picker — this is how
 * "every plugin is an integration" becomes tangible: write a function in Code
 * Engine (or let its AI write it) and it's instantly a workflow step.
 */
class Meow_MWFLOW_Integrations_Code_Engine {

  public function __construct( $core ) {
    add_filter( 'mwflow_register_integration', [ $this, 'register' ] );
  }

  public function register( $integrations ) {
    if ( !$this->is_available() ) { return $integrations; }

    $integrations[] = Meow_MWFLOW_SDK::integration( [
      'id'          => 'code-engine',
      'name'        => __( 'Code Engine', 'meow-workflow' ),
      'description' => __( 'Your own functions, as workflow steps.', 'meow-workflow' ),
      'color'       => '#7c3aed',
      'version'     => defined( 'MWCODE_VERSION' ) ? MWCODE_VERSION : '',
      'logo_url'    => defined( 'MWCODE_URL' ) ? MWCODE_URL . 'images/icon.png' : '',
      'actions'     => array_merge( $this->callable_actions(), [
        Meow_MWFLOW_SDK::action( [
          'id'          => 'execute_snippet',
          'name'        => __( 'Execute snippet (by ID)', 'meow-workflow' ),
          'description' => __( 'Run a Code Engine snippet by its ID and capture what it returns.', 'meow-workflow' ),
          'icon'        => 'play',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'id', __( 'Snippet', 'meow-workflow' ), 'number', [ 'required' => true, 'options_source' => 'code.snippets', 'empty_label' => __( '— Pick a snippet —', 'meow-workflow' ), 'description' => __( 'The Code Engine snippet to run.', 'meow-workflow' ) ] ),
            Meow_MWFLOW_SDK::input( 'args', __( 'Arguments (optional)', 'meow-workflow' ), 'json', [ 'placeholder' => '{ "name": "value" }', 'description' => __( 'A JSON object of arguments passed to the snippet. Leave empty if it takes none.', 'meow-workflow' ) ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'result', __( 'Snippet return value', 'meow-workflow' ), 'string' ) ],
          'callback'    => [ __CLASS__, 'callback_execute_snippet' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'execute_snippet_by_name',
          'name'        => __( 'Execute snippet (by name)', 'meow-workflow' ),
          'description' => __( 'Run a Code Engine snippet by its name and capture what it returns.', 'meow-workflow' ),
          'icon'        => 'play',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'name', __( 'Snippet', 'meow-workflow' ), 'string', [ 'required' => true, 'options_source' => 'code.functions', 'empty_label' => __( '— Pick a snippet —', 'meow-workflow' ), 'description' => __( 'The Code Engine function snippet to run.', 'meow-workflow' ) ] ),
            Meow_MWFLOW_SDK::input( 'args', __( 'Arguments (optional)', 'meow-workflow' ), 'json', [ 'placeholder' => '{ "name": "value" }', 'description' => __( 'A JSON object of arguments passed to the snippet. Leave empty if it takes none.', 'meow-workflow' ) ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'result', __( 'Snippet return value', 'meow-workflow' ), 'string' ) ],
          'callback'    => [ __CLASS__, 'callback_execute_snippet_by_name' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'get_snippet',
          'name'        => __( 'Get snippet metadata', 'meow-workflow' ),
          'description' => __( 'Look up a snippet\'s details (name, description, status) by its ID.', 'meow-workflow' ),
          'icon'        => 'file-code',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'id', __( 'Snippet ID', 'meow-workflow' ), 'number', [ 'required' => true, 'description' => __( 'Find it in Code Engine → Snippets.', 'meow-workflow' ) ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'snippet', __( 'Snippet', 'meow-workflow' ), 'json' ) ],
          'callback'    => [ __CLASS__, 'callback_get_snippet' ],
        ] ),
      ] ),
    ] );
    return $integrations;
  }

  /**
   * One action per callable — every function-scope snippet becomes a step,
   * named after the snippet, with one input per declared argument.
   */
  private function callable_actions() {
    $out = [];
    try {
      $snippets = $GLOBALS['mwcode']->getSnippets( true, 'function' );
    }
    catch ( Throwable $e ) {
      return $out;
    }
    foreach ( (array) $snippets as $s ) {
      $fn = (string) ( $s['functionName'] ?? '' );
      if ( $fn === '' ) { continue; }
      if ( isset( $s['active'] ) && !$s['active'] ) { continue; }
      $display = (string) ( $s['name'] ?? $fn );
      $desc = trim( (string) ( $s['desc'] ?? ( $s['description'] ?? '' ) ) );

      $inputs = [];
      $args = is_array( $s['args'] ?? null ) ? $s['args'] : [];
      $args_data = is_array( $s['argsData'] ?? null ) ? $s['argsData'] : [];
      if ( empty( $args ) && !empty( $args_data ) ) {
        $args = array_keys( $args_data );
      }
      foreach ( $args as $arg ) {
        if ( !is_string( $arg ) || $arg === '' ) { continue; }
        $meta = is_array( $args_data[ $arg ] ?? null ) ? $args_data[ $arg ] : [];
        $extra = [];
        if ( !empty( $meta['description'] ) ) { $extra['description'] = (string) $meta['description']; }
        if ( isset( $meta['default'] ) && $meta['default'] !== '' ) { $extra['default'] = $meta['default']; }
        $inputs[] = Meow_MWFLOW_SDK::input( $arg, $arg, 'string', $extra );
      }

      $out[] = Meow_MWFLOW_SDK::action( [
        'id'          => 'fn_' . sanitize_key( $fn ),
        'name'        => $display,
        'description' => $desc !== ''
          ? $desc
          : sprintf( __( 'Runs your %s function from Code Engine.', 'meow-workflow' ), $fn ),
        'icon'        => 'square-function',
        'inputs'      => $inputs,
        'outputs'     => [ Meow_MWFLOW_SDK::output( 'result', __( 'Result', 'meow-workflow' ), 'string' ) ],
        'callback'    => function ( $fn_inputs ) use ( $fn ) {
          $args = is_array( $fn_inputs ) ? $fn_inputs : [];
          return [ 'result' => self::api()->executeSnippetByName( $fn, $args ) ];
        },
      ] );
    }
    return $out;
  }

  private function is_available() {
    return class_exists( 'Meow_MWCODE_API' ) && isset( $GLOBALS['mwcode'] );
  }

  private static function api() {
    if ( empty( $GLOBALS['mwcode'] ) ) {
      throw new Exception( 'Code Engine is not active.' );
    }
    return $GLOBALS['mwcode'];
  }

  public static function callback_execute_snippet( $inputs ) {
    $args = $inputs['args'] ?? [];
    if ( !is_array( $args ) ) { $args = []; }
    return [ 'result' => self::api()->executeSnippet( (int) $inputs['id'], $args ) ];
  }

  public static function callback_execute_snippet_by_name( $inputs ) {
    $args = $inputs['args'] ?? [];
    if ( !is_array( $args ) ) { $args = []; }
    return [ 'result' => self::api()->executeSnippetByName( (string) $inputs['name'], $args ) ];
  }

  public static function callback_get_snippet( $inputs ) {
    return [ 'snippet' => self::api()->getSnippet( (int) $inputs['id'] ) ];
  }
}
