<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * Social Engine integration. Calls `global $mwse` (instance of Meow_SCLEGN_API).
 * Reference: /Users/meow/plugins/social-engine-pro/classes/api.php
 */
class Meow_MWFLOW_Integrations_Social_Engine {

  public function __construct( $core ) {
    add_filter( 'mwflow_register_integration', [ $this, 'register' ] );
  }

  public function register( $integrations ) {
    if ( !$this->is_available() ) { return $integrations; }

    $integrations[] = Meow_MWFLOW_SDK::integration( [
      'id'          => 'social-engine',
      'name'        => __( 'Social Engine', 'meow-workflow' ),
      'description' => __( 'Publish or draft social-media posts via Social Engine.', 'meow-workflow' ),
      'color'       => '#ec4899',
      'version'     => defined( 'SCLEGN_VERSION' ) ? SCLEGN_VERSION : '',
      'logo_url'    => defined( 'SCLEGN_URL' ) ? SCLEGN_URL . 'images/icon.png' : '',
      'actions'     => [
        Meow_MWFLOW_SDK::action( [
          'id'          => 'create_post',
          'name'        => __( 'Create social post', 'meow-workflow' ),
          'description' => __( 'Draft or publish a post to a connected social account via Social Engine.', 'meow-workflow' ),
          'icon'        => 'send',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'text', __( 'Text', 'meow-workflow' ), 'longtext', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'media', __( 'Attachment ID(s)', 'meow-workflow' ), 'string', [
              'description' => __( 'Optional. A single media ID or a comma-separated list of attachment IDs.', 'meow-workflow' ),
            ] ),
            Meow_MWFLOW_SDK::input( 'publish', __( 'Publish immediately', 'meow-workflow' ), 'boolean', [
              'default'     => false,
              'description' => __( 'Off saves it as a draft in Social Engine; on posts it right away.', 'meow-workflow' ),
            ] ),
            Meow_MWFLOW_SDK::input( 'account_name', __( 'Account name (optional)', 'meow-workflow' ), 'string', [
              'placeholder' => __( 'e.g. My Twitter', 'meow-workflow' ),
              'description' => __( 'Match by the account name shown in Social Engine. Leave empty to use the default account.', 'meow-workflow' ),
            ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'post_id', __( 'Social post ID', 'meow-workflow' ), 'number' ) ],
          'callback'    => [ __CLASS__, 'callback_create_post' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'list_accounts',
          'name'        => __( 'List accounts', 'meow-workflow' ),
          'description' => __( 'List the social accounts connected in Social Engine.', 'meow-workflow' ),
          'icon'        => 'users',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'type', __( 'Service type filter (optional)', 'meow-workflow' ), 'string', [
              'placeholder' => __( 'e.g. twitter', 'meow-workflow' ),
              'description' => __( 'Optional. Filter by network, e.g. twitter, facebook, linkedin. Leave empty for all.', 'meow-workflow' ),
            ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'accounts', __( 'Accounts', 'meow-workflow' ), 'json' ) ],
          'callback'    => [ __CLASS__, 'callback_list_accounts' ],
        ] ),
      ],
    ] );
    return $integrations;
  }

  private function is_available() {
    return class_exists( 'Meow_SCLEGN_API' ) && isset( $GLOBALS['mwse'] );
  }

  private static function api() {
    if ( empty( $GLOBALS['mwse'] ) ) {
      throw new Exception( 'Social Engine is not active.' );
    }
    return $GLOBALS['mwse'];
  }

  public static function callback_create_post( $inputs ) {
    $api = self::api();
    $account = [];
    if ( !empty( $inputs['account_name'] ) ) {
      $matches = $api->list_accounts( null, $inputs['account_name'] );
      $account = $matches[0] ?? [];
    }

    $media = [];
    if ( !empty( $inputs['media'] ) ) {
      $raw = is_array( $inputs['media'] ) ? $inputs['media'] : explode( ',', (string) $inputs['media'] );
      $media = array_filter( array_map( 'intval', $raw ) );
    }

    $result = $api->post(
      $account,
      [ 'text' => (string) $inputs['text'], 'media' => $media ],
      !empty( $inputs['publish'] )
    );

    if ( is_wp_error( $result ) ) {
      throw new Exception( $result->get_error_message() );
    }
    return [ 'post_id' => (int) $result ];
  }

  public static function callback_list_accounts( $inputs ) {
    return [ 'accounts' => self::api()->list_accounts( $inputs['type'] ?? null ) ];
  }
}
