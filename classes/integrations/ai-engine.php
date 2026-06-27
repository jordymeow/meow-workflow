<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * AI Engine integration. Calls into the public AI Engine PHP API
 * (`global $mwai` — instance of `Meow_MWAI_API`).
 *
 * Inputs marked with `options_source` pull their dropdown values from
 * the `mwflow.aiEngine` object localised by classes/admin.php. They render
 * empty unless AI Engine is active, but the field is optional and the
 * runner falls back to AI Engine's configured defaults when blank.
 *
 * This file is the canonical example for any plugin that wants to expose
 * itself to Meow Workflow. Copy the structure: register on the filter,
 * gate on a class_exists check, declare actions with typed inputs/outputs,
 * call the engine's public API in the callbacks.
 */
class Meow_MWFLOW_Integrations_Ai_Engine {

  public function __construct( $core ) {
    add_filter( 'mwflow_register_integration', [ $this, 'register' ] );
  }

  public function register( $integrations ) {
    if ( !$this->is_available() ) {
      return $integrations;
    }

    $advanced_model = Meow_MWFLOW_SDK::input( 'model', __( 'Model', 'meow-workflow' ), 'select', [
      'description'    => __( 'Leave blank to use the AI Engine default model.', 'meow-workflow' ),
      'options_source' => 'ai.models',
      'advanced'       => true,
    ] );
    $advanced_env = Meow_MWFLOW_SDK::input( 'env_id', __( 'Environment', 'meow-workflow' ), 'select', [
      'description'    => __( 'Pick an AI Engine environment (its API keys/settings). Leave blank for the default.', 'meow-workflow' ),
      'options_source' => 'ai.envs',
      'advanced'       => true,
    ] );
    $advanced_temperature = Meow_MWFLOW_SDK::input( 'temperature', __( 'Temperature', 'meow-workflow' ), 'number', [
      'min' => 0, 'max' => 2, 'step' => 0.1, 'default' => 0.7,
      'advanced' => true,
    ] );

    $integrations[] = Meow_MWFLOW_SDK::integration( [
      'id'          => 'ai-engine',
      'name'        => __( 'AI Engine', 'meow-workflow' ),
      'description' => __( 'Text, image, vision and JSON generation via AI Engine.', 'meow-workflow' ),
      'color'       => '#00e28e',
      'version'     => defined( 'MWAI_VERSION' ) ? MWAI_VERSION : '',
      'logo_url'    => defined( 'MWAI_URL' ) ? MWAI_URL . 'images/icon.png' : '',
      'actions'     => [
        Meow_MWFLOW_SDK::action( [
          'id'          => 'text',
          'name'        => __( 'Text completion', 'meow-workflow' ),
          'description' => __( 'Generate text from a prompt. Uses AI Engine defaults unless you override them in Advanced.', 'meow-workflow' ),
          'icon'        => 'pencil',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'message', __( 'Prompt', 'meow-workflow' ), 'longtext', [ 'required' => true ] ),
            $advanced_model,
            $advanced_env,
            $advanced_temperature,
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'text', __( 'Text', 'meow-workflow' ), 'longtext' ) ],
          'callback'    => [ __CLASS__, 'callback_text' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'fast_text',
          'name'        => __( 'Fast text completion', 'meow-workflow' ),
          'description' => __( 'Generate text using the "fast" model configured in AI Engine.', 'meow-workflow' ),
          'icon'        => 'zap',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'message', __( 'Prompt', 'meow-workflow' ), 'longtext', [ 'required' => true ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'text', __( 'Text', 'meow-workflow' ), 'longtext' ) ],
          'callback'    => [ __CLASS__, 'callback_fast_text' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'image',
          'name'        => __( 'Image generation', 'meow-workflow' ),
          'description' => __( 'Generate an image from a prompt.', 'meow-workflow' ),
          'icon'        => 'image-plus',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'message', __( 'Prompt', 'meow-workflow' ), 'longtext', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'model', __( 'Model', 'meow-workflow' ), 'select', [
              'description'    => __( 'Defaults to your AI Engine image model.', 'meow-workflow' ),
              'options_source' => 'ai.models',
              'advanced'       => true,
            ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'result', __( 'Image markdown / URL', 'meow-workflow' ), 'string' ) ],
          'callback'    => [ __CLASS__, 'callback_image' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'vision',
          'name'        => __( 'Vision (describe an image)', 'meow-workflow' ),
          'description' => __( 'Ask the AI a question about an image URL.', 'meow-workflow' ),
          'icon'        => 'scan-eye',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'message', __( 'Prompt', 'meow-workflow' ), 'longtext', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'url', __( 'Image URL', 'meow-workflow' ), 'url', [ 'required' => true ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'text', __( 'Answer', 'meow-workflow' ), 'longtext' ) ],
          'callback'    => [ __CLASS__, 'callback_vision' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'json',
          'name'        => __( 'JSON output', 'meow-workflow' ),
          'description' => __( 'Get a structured JSON answer from the AI.', 'meow-workflow' ),
          'icon'        => 'braces',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'message', __( 'Prompt', 'meow-workflow' ), 'longtext', [ 'required' => true ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'data', __( 'JSON', 'meow-workflow' ), 'json' ) ],
          'callback'    => [ __CLASS__, 'callback_json' ],
        ] ),
      ],
    ] );
    return $integrations;
  }

  private function is_available() {
    return class_exists( 'Meow_MWAI_API' ) && isset( $GLOBALS['mwai'] );
  }

  private static function api() {
    if ( empty( $GLOBALS['mwai'] ) ) {
      throw new Exception( 'AI Engine is not active.' );
    }
    return $GLOBALS['mwai'];
  }

  public static function callback_text( $inputs ) {
    $params = [];
    foreach ( [ 'model', 'temperature', 'env_id' ] as $key ) {
      if ( isset( $inputs[ $key ] ) && $inputs[ $key ] !== '' ) {
        $params[ $key === 'env_id' ? 'envId' : $key ] = $inputs[ $key ];
      }
    }
    $text = self::api()->simpleTextQuery( (string) $inputs['message'], $params );
    return [ 'text' => (string) $text ];
  }

  public static function callback_fast_text( $inputs ) {
    $text = self::api()->simpleFastTextQuery( (string) $inputs['message'], [] );
    return [ 'text' => (string) $text ];
  }

  public static function callback_image( $inputs ) {
    $params = [];
    if ( !empty( $inputs['model'] ) ) { $params['model'] = $inputs['model']; }
    $result = self::api()->simpleImageQuery( (string) $inputs['message'], $params );
    return [ 'result' => (string) $result ];
  }

  public static function callback_vision( $inputs ) {
    $text = self::api()->simpleVisionQuery(
      (string) $inputs['message'],
      (string) $inputs['url'],
      null,
      []
    );
    return [ 'text' => (string) $text ];
  }

  public static function callback_json( $inputs ) {
    $data = self::api()->simpleJsonQuery( (string) $inputs['message'], null, null, [] );
    return [ 'data' => $data ];
  }
}
