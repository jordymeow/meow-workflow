<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

class Meow_MWFLOW_Rest {
  private $namespace = 'meow-workflow/v1';
  private $core;

  public function __construct( $core ) {
    $this->core = $core;
    add_action( 'rest_api_init', [ $this, 'register_routes' ] );
  }

  private function can_manage() {
    return apply_filters( 'mwflow_allow_setup', current_user_can( 'manage_options' ) );
  }

  public function register_routes() {
    $auth = function () { return $this->can_manage(); };

    register_rest_route( $this->namespace, '/integrations', [
      'methods'             => 'GET',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'get_integrations' ],
    ] );

    register_rest_route( $this->namespace, '/flows', [
      'methods'             => 'GET',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'get_flows' ],
    ] );

    register_rest_route( $this->namespace, '/flows', [
      'methods'             => 'POST',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'create_flow' ],
    ] );

    register_rest_route( $this->namespace, '/flows/(?P<id>\d+)', [
      'methods'             => 'GET',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'get_flow' ],
    ] );

    register_rest_route( $this->namespace, '/flows/(?P<id>\d+)', [
      'methods'             => 'PUT',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'update_flow' ],
    ] );

    register_rest_route( $this->namespace, '/flows/(?P<id>\d+)', [
      'methods'             => 'DELETE',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'delete_flow' ],
    ] );

    register_rest_route( $this->namespace, '/flows/(?P<id>\d+)/run', [
      'methods'             => 'POST',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'run_flow' ],
    ] );

    register_rest_route( $this->namespace, '/flows/(?P<id>\d+)/publish', [
      'methods'             => 'POST',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'publish_flow' ],
    ] );

    register_rest_route( $this->namespace, '/flows/(?P<id>\d+)/capture-sample', [
      'methods'             => 'POST',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'toggle_capture_sample' ],
    ] );

    register_rest_route( $this->namespace, '/runs', [
      'methods'             => 'GET',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'get_runs' ],
    ] );

    register_rest_route( $this->namespace, '/runs/(?P<id>\d+)', [
      'methods'             => 'GET',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'get_run' ],
    ] );

    register_rest_route( $this->namespace, '/runs/(?P<id>\d+)/advance', [
      'methods'             => 'POST',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'advance_run' ],
    ] );

    register_rest_route( $this->namespace, '/logs', [
      'methods'             => 'GET',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'get_logs' ],
    ] );

    register_rest_route( $this->namespace, '/logs', [
      'methods'             => 'DELETE',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'clear_logs' ],
    ] );

    // Webhook trigger entry (no auth — flows control their own token).
    register_rest_route( $this->namespace, '/hook/(?P<token>[a-zA-Z0-9]+)', [
      'methods'             => [ 'GET', 'POST' ],
      'permission_callback' => '__return_true',
      'callback'            => [ $this, 'handle_webhook' ],
    ] );

    register_rest_route( $this->namespace, '/templates', [
      'methods'             => 'GET',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'get_templates' ],
    ] );

    register_rest_route( $this->namespace, '/templates/(?P<id>[a-z0-9_]+)/install', [
      'methods'             => 'POST',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'install_template' ],
    ] );

    register_rest_route( $this->namespace, '/author', [
      'methods'             => 'POST',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'author_flow' ],
    ] );

    register_rest_route( $this->namespace, '/maintenance/export', [
      'methods'             => 'GET',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'export_settings' ],
    ] );

    register_rest_route( $this->namespace, '/maintenance/import', [
      'methods'             => 'POST',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'import_settings' ],
    ] );

    register_rest_route( $this->namespace, '/maintenance/reset', [
      'methods'             => 'POST',
      'permission_callback' => $auth,
      'callback'            => [ $this, 'reset_settings' ],
    ] );
  }

  public function get_integrations() {
    return rest_ensure_response( $this->core->registry->get_integrations_for_ui() );
  }

  public function get_flows() {
    return rest_ensure_response( $this->core->get_flows() );
  }

  public function get_flow( $req ) {
    $flow = $this->core->get_flow( (int) $req['id'] );
    if ( !$flow ) {
      return new WP_Error( 'mwflow_not_found', __( 'Flow not found.', 'meow-workflow' ), [ 'status' => 404 ] );
    }
    return rest_ensure_response( $flow );
  }

  public function create_flow( $req ) {
    $data = $req->get_json_params() ?: [];
    $id = $this->core->save_flow( $data );
    return rest_ensure_response( $this->core->get_flow( $id ) );
  }

  public function update_flow( $req ) {
    $data = $req->get_json_params() ?: [];
    $id = $this->core->save_flow( $data, (int) $req['id'] );
    return rest_ensure_response( $this->core->get_flow( $id ) );
  }

  public function delete_flow( $req ) {
    $this->core->delete_flow( (int) $req['id'] );
    return rest_ensure_response( [ 'deleted' => true ] );
  }

  public function publish_flow( $req ) {
    $id = (int) $req['id'];
    if ( !$this->core->publish_flow( $id ) ) {
      return new WP_Error( 'mwflow_publish_failed', __( 'Could not publish — flow not found.', 'meow-workflow' ), [ 'status' => 404 ] );
    }
    return rest_ensure_response( $this->core->get_flow( $id ) );
  }

  public function toggle_capture_sample( $req ) {
    $body = $req->get_json_params() ?: [];
    $enabled = !empty( $body['enabled'] );
    $this->core->set_capture_sample( (int) $req['id'], $enabled );
    return rest_ensure_response( $this->core->get_flow( (int) $req['id'] ) );
  }

  public function run_flow( $req ) {
    // "Test once" from the editor. Stepwise mode creates the run and returns
    // immediately; the client then advances it one step per request so the
    // canvas can show live progress. Legacy callers (whole body = payload)
    // still get the old single-request sync run.
    $body = $req->get_json_params() ?: [];
    $stepwise = !empty( $body['stepwise'] );
    $payload = $stepwise
      ? ( is_array( $body['payload'] ?? null ) ? $body['payload'] : [] )
      : $body;
    $flow = $this->core->get_flow( (int) $req['id'] );
    if ( !$flow ) {
      return new WP_Error( 'mwflow_not_found', __( 'Flow not found.', 'meow-workflow' ), [ 'status' => 404 ] );
    }
    $options = $stepwise ? [ 'stepwise' => true ] : [ 'sync' => true ];
    $run = $this->core->runner->run( (int) $req['id'], $payload, $options );
    return rest_ensure_response( $run );
  }

  /**
   * Advance a stepwise run by exactly one step (editor "Test once" loop).
   */
  public function advance_run( $req ) {
    $result = $this->core->runner->advance( (int) $req['id'] );
    if ( !$result ) {
      return new WP_Error( 'mwflow_not_found', __( 'Run not found.', 'meow-workflow' ), [ 'status' => 404 ] );
    }
    return rest_ensure_response( $result );
  }

  public function get_runs( $req ) {
    $flow_id = $req->get_param( 'flow_id' );
    $runs = $this->core->get_runs( $flow_id ? (int) $flow_id : null );
    return rest_ensure_response( $runs );
  }

  public function get_run( $req ) {
    $run = $this->core->get_run( (int) $req['id'] );
    if ( !$run ) {
      return new WP_Error( 'mwflow_not_found', __( 'Run not found.', 'meow-workflow' ), [ 'status' => 404 ] );
    }
    return rest_ensure_response( $run );
  }

  public function get_logs() {
    return rest_ensure_response( [ 'log' => $this->core->logging->tail( 500 ) ] );
  }

  public function clear_logs() {
    $this->core->logging->clear();
    return rest_ensure_response( [ 'cleared' => true ] );
  }

  public function handle_webhook( $req ) {
    $token = (string) $req['token'];
    $flows = $this->core->get_active_flows();
    foreach ( $flows as $flow ) {
      if ( $flow['trigger_type'] !== 'webhook' ) { continue; }
      $expected = $flow['trigger_config']['token'] ?? '';
      if ( $expected && hash_equals( $expected, $token ) ) {
        // Rate-limit per flow so an exposed webhook URL can't be hammered into a
        // run flood or an SSRF/email amplifier. Fixed window of 1 minute;
        // filterable (return 0 to disable) for genuinely high-volume endpoints.
        $limit = (int) apply_filters( 'mwflow_webhook_rate_limit', 60, $flow['id'] );
        if ( $limit > 0 ) {
          $rl_key = 'mwflow_hook_rl_' . (int) $flow['id'];
          $count  = (int) get_transient( $rl_key );
          if ( $count >= $limit ) {
            return new WP_Error(
              'mwflow_rate_limited',
              __( 'Too many requests — webhook rate limit exceeded.', 'meow-workflow' ),
              [ 'status' => 429 ]
            );
          }
          set_transient( $rl_key, $count + 1, MINUTE_IN_SECONDS );
        }
        $payload = array_merge(
          (array) $req->get_query_params(),
          (array) ( $req->get_json_params() ?: [] )
        );
        // Sample-capture mode: store the payload and skip execution this once.
        // Lets users scaffold their flow against a real shape instead of guessing.
        if ( $this->core->capture_trigger_sample( $flow['id'], $payload ) ) {
          return rest_ensure_response( [ 'ok' => true, 'captured' => true ] );
        }
        // Webhook callers must not be blocked by long-running steps (e.g. AI
        // calls). The async runner queues each step via wp_cron and the caller
        // gets an immediate ack with the run id.
        $run = $this->core->runner->run( $flow['id'], $payload );
        return rest_ensure_response( [ 'ok' => true, 'run' => $run['run_id'] ?? null ] );
      }
    }
    return new WP_Error( 'mwflow_invalid_token', __( 'Invalid webhook token.', 'meow-workflow' ), [ 'status' => 404 ] );
  }

  public function get_templates() {
    // Enrich each template with whether its required integrations are
    // currently active. The UI uses this to enable/disable the Install button.
    $integrations = $this->core->registry->get_integrations_for_ui();
    $active = [];
    foreach ( $integrations as $i ) { $active[ $i['id'] ] = true; }

    $templates = Meow_MWFLOW_Templates::all();
    foreach ( $templates as &$tpl ) {
      $missing = [];
      foreach ( ( $tpl['requires'] ?? [] ) as $req ) {
        if ( empty( $active[ $req ] ) ) { $missing[] = $req; }
      }
      $tpl['missing_requires'] = $missing;
      $tpl['can_install']      = empty( $missing );
    }
    return rest_ensure_response( $templates );
  }

  public function install_template( $req ) {
    $template_id = (string) $req['id'];
    $found = null;
    foreach ( Meow_MWFLOW_Templates::all() as $tpl ) {
      if ( ( $tpl['id'] ?? '' ) === $template_id ) { $found = $tpl; break; }
    }
    if ( !$found ) {
      return new WP_Error( 'mwflow_template_not_found',
        __( 'Template not found.', 'meow-workflow' ),
        [ 'status' => 404 ] );
    }
    // Refuse to install if a required plugin isn't active — the flow would
    // fail at runtime anyway and the broken flow would just clutter the list.
    $missing = [];
    $active = [];
    foreach ( $this->core->registry->get_integrations_for_ui() as $i ) { $active[ $i['id'] ] = true; }
    foreach ( ( $found['requires'] ?? [] ) as $req ) {
      if ( empty( $active[ $req ] ) ) { $missing[] = $req; }
    }
    if ( !empty( $missing ) ) {
      return new WP_Error( 'mwflow_template_missing_deps',
        sprintf(
          __( 'This template needs: %s. Install or activate those first.', 'meow-workflow' ),
          implode( ', ', $missing )
        ),
        [ 'status' => 400, 'missing' => $missing ]
      );
    }

    $flow_id = $this->core->save_flow( [
      'name'           => $found['name'],
      'definition'     => $found['definition'],
      'trigger_type'   => $found['trigger_type'],
      'trigger_config' => $found['trigger_config'] ?? [],
      'is_active'      => false,
    ] );
    return rest_ensure_response( $this->core->get_flow( (int) $flow_id ) );
  }

  /**
   * Export all flows as a portable JSON bundle. Settings (none yet) and
   * trigger samples are intentionally excluded — they are environment-
   * specific (webhook tokens, captured payloads).
   */
  public function export_settings() {
    $flows = $this->core->get_flows();
    $payload = [
      'plugin'    => 'meow-workflow',
      'version'   => defined( 'MWFLOW_VERSION' ) ? MWFLOW_VERSION : '',
      'exported'  => current_time( 'mysql' ),
      'flows'     => array_map( function ( $f ) {
        $full = $this->core->get_flow( $f['id'] );
        return [
          'name'           => $full['name'] ?? '',
          'trigger_type'   => $full['trigger_type'] ?? 'manual',
          'trigger_config' => $full['trigger_config'] ?? [],
          'definition'     => $full['definition'] ?? [ 'nodes' => [], 'edges' => [] ],
        ];
      }, $flows ),
    ];
    return rest_ensure_response( $payload );
  }

  /**
   * Import a bundle from export_settings. Skips entries that reference
   * integrations not currently active so the user gets a clear report.
   */
  public function import_settings( $req ) {
    $body = $req->get_json_params() ?: [];
    $flows_in = is_array( $body['flows'] ?? null ) ? $body['flows'] : null;
    if ( $flows_in === null ) {
      return new WP_Error( 'mwflow_import_invalid',
        __( 'Import file is missing a `flows` array.', 'meow-workflow' ),
        [ 'status' => 400 ] );
    }
    $imported = 0;
    $skipped = [];
    foreach ( $flows_in as $entry ) {
      if ( !is_array( $entry ) || empty( $entry['definition'] ) ) {
        $skipped[] = [ 'reason' => 'malformed' ];
        continue;
      }
      $trigger_config = is_array( $entry['trigger_config'] ?? null ) ? $entry['trigger_config'] : [];
      // Never trust an imported webhook token — a crafted import could otherwise
      // pin a known/guessable token onto a flow. Drop it so the webhook trigger
      // mints a fresh random one when the flow is saved.
      unset( $trigger_config['token'] );
      $this->core->save_flow( [
        'name'           => $entry['name'] ?? __( 'Untitled workflow', 'meow-workflow' ),
        'trigger_type'   => $entry['trigger_type'] ?? 'manual',
        'trigger_config' => $trigger_config,
        'definition'     => $entry['definition'],
        'is_active'      => false, // never auto-activate on import.
      ] );
      $imported++;
    }
    return rest_ensure_response( [ 'imported' => $imported, 'skipped' => $skipped ] );
  }

  /**
   * Delete every workflow + run + log. Irreversible — the UI confirms before
   * calling this.
   */
  public function reset_settings() {
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->prefix}mwflow_runs" );
    $wpdb->query( "DELETE FROM {$wpdb->prefix}mwflow_flows" );
    $wpdb->query( $wpdb->prepare(
      "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
      $wpdb->esc_like( Meow_MWFLOW_Triggers_RSS::SEEN_OPTION_PREFIX ) . '%'
    ) );
    // Pending step events belong to runs that no longer exist.
    wp_unschedule_hook( Meow_MWFLOW_Runner::STEP_HOOK );
    $this->core->logging->clear();
    // Re-register triggers so the now-empty active set unbinds anything live.
    foreach ( $this->core->triggers as $trigger ) { $trigger->unregister(); }
    $this->core->register_triggers();
    return rest_ensure_response( [ 'reset' => true ] );
  }

  /**
   * AI-driven workflow authoring entry point.
   *
   * Body shape:
   *   { prompt: string, mode?: 'new'|'extend'|'explain'|'suggest'|'fix',
   *     context?: { definition?, sample?, error?, after_step_id? },
   *     persist?: bool (default true for 'new', false for the rest) }
   *
   * Response on success (new/extend/fix):
   *   { flow_id?, definition, name, trigger_type, trigger_config, rationale? }
   *
   * Response on validation failure after repair pass:
   *   { errors: string[], partial: object }
   */
  public function author_flow( $req ) {
    $body    = $req->get_json_params() ?: [];
    $prompt  = (string) ( $body['prompt'] ?? '' );
    $mode    = (string) ( $body['mode'] ?? 'new' );
    $context = is_array( $body['context'] ?? null ) ? $body['context'] : [];
    $persist = array_key_exists( 'persist', $body )
      ? (bool) $body['persist']
      : in_array( $mode, [ 'new', 'extend', 'fix' ], true );

    try {
      $authoring = new Meow_MWFLOW_AI_Authoring( $this->core );
      $result = $authoring->author( $prompt, $mode, $context );
    }
    catch ( Meow_MWFLOW_AI_Authoring_Exception $e ) {
      // Tagged authoring failure — give the UI a category so it can render a
      // helpful explanation, not just dump the raw message.
      return new WP_Error(
        'mwflow_authoring_failed',
        $e->getMessage(),
        [
          'status'     => 400,
          'error_kind' => $e->kind,
          'message'    => $e->getMessage(),
        ]
      );
    }
    catch ( Throwable $e ) {
      return new WP_Error(
        'mwflow_authoring_failed',
        $e->getMessage(),
        [
          'status'     => 500,
          'error_kind' => 'unknown',
          'message'    => $e->getMessage(),
        ]
      );
    }

    // explain / suggest never persist; just return the AI's output.
    if ( !empty( $result['message'] ) || !empty( $result['suggestion'] ) ) {
      return rest_ensure_response( $result );
    }

    // Validation failure path — caller can show the errors and let user retry.
    if ( !empty( $result['errors'] ) ) {
      return rest_ensure_response( $result );
    }

    if ( $persist && !empty( $result['definition'] ) ) {
      $flow_id = $this->core->save_flow( [
        'name'           => $result['name'] ?? __( 'Untitled workflow', 'meow-workflow' ),
        'definition'     => $result['definition'],
        'trigger_type'   => $result['trigger_type'] ?? 'manual',
        'trigger_config' => $result['trigger_config'] ?? [],
      ] );
      $result['flow_id'] = (int) $flow_id;
    }

    return rest_ensure_response( $result );
  }
}
