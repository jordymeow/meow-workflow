<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * Helpers third-party plugins can use to declare their integration without
 * memorising the full schema. Loaded eagerly so engines can call these from
 * any context (including their own `mwflow_register_integration` callbacks).
 *
 * Usage:
 *
 *   add_filter( 'mwflow_register_integration', function ( $integrations ) {
 *     $integrations[] = Meow_MWFLOW_SDK::integration( [
 *       'id'      => 'my-plugin',
 *       'name'    => 'My Plugin',
 *       'actions' => [
 *         Meow_MWFLOW_SDK::action( [
 *           'id'       => 'do_thing',
 *           'name'     => 'Do Thing',
 *           'inputs'   => [
 *             Meow_MWFLOW_SDK::input( 'name', 'Name', 'string', [ 'required' => true ] ),
 *           ],
 *           'outputs'  => [ Meow_MWFLOW_SDK::output( 'result', 'Result', 'string' ) ],
 *           'callback' => function ( $inputs ) { return [ 'result' => 'ok' ]; },
 *         ] ),
 *       ],
 *     ] );
 *     return $integrations;
 *   } );
 */
class Meow_MWFLOW_SDK {
  const TYPES = [
    'string', 'longtext', 'number', 'boolean', 'select',
    'post_id', 'user_id', 'attachment_id', 'url', 'email', 'json', 'expression',
    'conditions', 'branches',
  ];

  public static function sanitise_type( $type ) {
    $type = (string) $type;
    return in_array( $type, self::TYPES, true ) ? $type : 'string';
  }

  public static function integration( array $args ) {
    return wp_parse_args( $args, [
      'id'          => '',
      'name'        => '',
      'description' => '',
      'logo_url'    => '',
      'color'       => '#3b82f6',
      'version'     => '',
      'actions'     => [],
      'triggers'    => [],
    ] );
  }

  public static function action( array $args ) {
    return wp_parse_args( $args, [
      'id'          => '',
      'name'        => '',
      'description' => '',
      'icon'        => '',
      'inputs'      => [],
      'outputs'     => [],
      'callback'    => null,
    ] );
  }

  public static function trigger( array $args ) {
    return wp_parse_args( $args, [
      'id'          => '',
      'name'        => '',
      'description' => '',
      'hook'        => '',
      'priority'    => 10,
      'args'        => 1,
      'outputs'     => [],
      'mapper'      => null,
    ] );
  }

  public static function input( $id, $name, $type = 'string', array $extra = [] ) {
    return array_merge( [
      'id'       => $id,
      'name'     => $name,
      'type'     => $type,
      'required' => false,
    ], $extra );
  }

  public static function output( $id, $name, $type = 'string' ) {
    return [ 'id' => $id, 'name' => $name, 'type' => $type ];
  }
}
