<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

class Meow_MWFLOW_Admin extends MeowKit_MWFLOW_Admin {

  public $core;

  public function __construct( $core ) {
    $this->core = $core;

    // Boots the shared Meow Apps menu, ratings, news and issue checks — same as
    // every other plugin in the family. Must run before we add our submenu.
    parent::__construct( MWFLOW_PREFIX, MWFLOW_ENTRY, MWFLOW_DOMAIN, class_exists( 'MeowPro_MWFLOW_Core' ) );

    if ( is_admin() ) {
      add_action( 'admin_menu', [ $this, 'admin_menu' ] );
      add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }
  }

  public function admin_menu() {
    add_submenu_page(
      'meowapps-main-menu',
      __( 'Workflow', 'meow-workflow' ),
      __( 'Workflow', 'meow-workflow' ),
      'manage_options',
      'mwflow_dashboard',
      [ $this, 'render' ]
    );
  }

  public function enqueue( $hook ) {
    $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
    if ( $page !== 'mwflow_dashboard' ) { return; }

    $entry = MWFLOW_PATH . '/app/dashboard.js';
    $version = file_exists( $entry ) ? filemtime( $entry ) : MWFLOW_VERSION;

    wp_register_script(
      'mwflow-dashboard',
      MWFLOW_URL . 'app/dashboard.js',
      [ 'wp-element', 'wp-i18n' ],
      $version,
      true
    );

    wp_localize_script( 'mwflow-dashboard', 'mwflow', [
      'restUrl'      => esc_url_raw( rest_url( 'meow-workflow/v1' ) ),
      'restNonce'    => wp_create_nonce( 'wp_rest' ),
      'pluginUrl'    => MWFLOW_URL,
      'version'      => MWFLOW_VERSION,
      'aiEngine'     => $this->ai_engine_metadata(),
      'codeEngine'   => $this->code_engine_metadata(),
      'integrations' => $this->integrations_summary(),
    ] );

    wp_enqueue_script( 'mwflow-dashboard' );
  }

  public function render() {
    echo '<div id="mwflow-app" class="mwflow-app-root"></div>';
  }

  /**
   * Surface AI Engine's configured envs + models to the JS app so nodes can
   * render real dropdowns instead of asking the user to type IDs.
   * Returns an empty payload when AI Engine is not active.
   */
  private function ai_engine_metadata() {
    if ( !isset( $GLOBALS['mwai_core'] ) || empty( $GLOBALS['mwai_core'] ) ) {
      return [ 'available' => false, 'envs' => [], 'models' => [], 'defaults' => [] ];
    }
    $mwai_core = $GLOBALS['mwai_core'];

    $envs = [];
    $models = [];
    try {
      $raw_envs = $mwai_core->get_option( 'ai_envs' );
      if ( is_array( $raw_envs ) ) {
        foreach ( $raw_envs as $env ) {
          if ( !is_array( $env ) || empty( $env['id'] ) ) { continue; }
          $envs[] = [
            'id'   => (string) $env['id'],
            'name' => (string) ( $env['name'] ?? $env['id'] ),
            'type' => (string) ( $env['type'] ?? 'openai' ),
          ];
          if ( !empty( $env['customModels'] ) && is_array( $env['customModels'] ) ) {
            foreach ( $env['customModels'] as $m ) {
              if ( !empty( $m['model'] ) ) {
                $models[] = [
                  'id'    => (string) $m['model'],
                  'name'  => (string) ( $m['name'] ?? $m['model'] ),
                  'envId' => (string) $env['id'],
                ];
              }
            }
          }
        }
      }
    }
    catch ( Throwable $e ) {
      // AI Engine internals can shift between versions — degrade gracefully.
    }

    $defaults = [
      'env'         => (string) $mwai_core->get_option( 'ai_default_env' ),
      'model'       => (string) $mwai_core->get_option( 'ai_default_model' ),
      'fastModel'   => (string) $mwai_core->get_option( 'ai_fast_default_model' ),
      'visionModel' => (string) $mwai_core->get_option( 'ai_vision_default_model' ),
      'visionEnv'   => (string) $mwai_core->get_option( 'ai_vision_default_env' ),
    ];

    return [
      'available' => true,
      'envs'      => $envs,
      'models'    => $models,
      'defaults'  => $defaults,
    ];
  }

  /**
   * Surface Code Engine's snippets so the Execute Snippet steps render real
   * dropdowns instead of asking the user to type IDs or exact names.
   * Returns an empty payload when Code Engine is not active.
   */
  private function code_engine_metadata() {
    if ( !class_exists( 'Meow_MWCODE_API' ) || empty( $GLOBALS['mwcode'] ) ) {
      return [ 'available' => false, 'snippets' => [] ];
    }
    $snippets = [];
    try {
      foreach ( (array) $GLOBALS['mwcode']->getSnippets( true ) as $s ) {
        $id = (int) ( $s['snippetId'] ?? ( $s['id'] ?? 0 ) );
        if ( !$id ) { continue; }
        $snippets[] = [
          'id'           => $id,
          'name'         => (string) ( $s['name'] ?? ( 'Snippet #' . $id ) ),
          'functionName' => (string) ( $s['functionName'] ?? '' ),
        ];
      }
    }
    catch ( Throwable $e ) {
      // Code Engine internals can shift between versions — degrade gracefully.
    }
    return [ 'available' => true, 'snippets' => $snippets ];
  }

  /**
   * Minimal integration summary so the Settings screen + node renderer can
   * resolve labels without forcing a second REST call on first paint. We
   * include id+name+icon for actions (the canvas nodes render the icon on
   * first paint from this bootstrap) but drop inputs/outputs to keep it small.
   */
  private function integrations_summary() {
    $list = $this->core->registry->get_integrations_for_ui();
    return array_map( function ( $i ) {
      $actions = array_map( function ( $a ) {
        return [ 'id' => $a['id'], 'name' => $a['name'], 'icon' => $a['icon'] ?? null ];
      }, $i['actions'] ?? [] );
      $triggers = array_map( function ( $t ) {
        return [ 'id' => $t['id'], 'name' => $t['name'] ];
      }, $i['triggers'] ?? [] );
      return [
        'id'           => $i['id'],
        'name'         => $i['name'],
        'description'  => $i['description'] ?? '',
        'color'        => $i['color'] ?? '#999',
        'version'      => $i['version'] ?? '',
        'logo_url'     => $i['logo_url'] ?? '',
        'actions'      => $actions,
        'triggers'     => $triggers,
        'action_count' => count( $actions ),
        'trigger_count'=> count( $triggers ),
      ];
    }, $list );
  }
}
