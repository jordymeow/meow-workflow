<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

class Meow_MWFLOW_Core {
  const DB_VERSION = '8';

  public $registry;
  public $runner;
  public $api;
  public $admin;
  public $rest;
  public $logging;
  public $is_rest = false;
  public $triggers = [];

  public function __construct() {
    self::maybe_install();

    // Lazily registers the shared meow-common/v1 routes (Dashboard tools,
    // speed test) on REST requests — mirrors AI Engine / Code Engine.
    $this->is_rest = MeowKit_MWFLOW_Helpers::is_rest();

    $this->logging  = new Meow_MWFLOW_Logging();
    $this->registry = new Meow_MWFLOW_Registry( $this );
    $this->runner   = new Meow_MWFLOW_Runner( $this );
    $this->api      = new Meow_MWFLOW_API( $this );

    if ( is_admin() ) {
      $this->admin = new Meow_MWFLOW_Admin( $this );
    }
    $this->rest = new Meow_MWFLOW_Rest( $this );

    // Built-in integrations: registered as the canonical example of how
    // a third-party plugin should hook into Meow Workflow. We bind to the
    // same `mwflow_register_integration` filter we ask other plugins to use.
    $this->load_builtin_integrations();

    // Triggers: each handler watches its slice of flows and asks the runner
    // to execute when its condition fires.
    $this->triggers['manual']   = new Meow_MWFLOW_Triggers_Manual( $this );
    $this->triggers['schedule'] = new Meow_MWFLOW_Triggers_Schedule( $this );
    $this->triggers['webhook']  = new Meow_MWFLOW_Triggers_Webhook( $this );
    $this->triggers['hook']     = new Meow_MWFLOW_Triggers_Hook( $this );
    $this->triggers['rss']      = new Meow_MWFLOW_Triggers_RSS( $this );

    add_action( 'init', [ $this, 'register_triggers' ], 20 );
  }

  private function load_builtin_integrations() {
    // Always-on: core (built-in logic nodes) + wordpress (post/user/email helpers).
    new Meow_MWFLOW_Integrations_Core( $this );
    new Meow_MWFLOW_Integrations_Wordpress( $this );

    // Conditional: only load if the underlying plugin is active. Each integration
    // checks for its target class internally so the integration file is safe to
    // include even when the plugin is missing.
    new Meow_MWFLOW_Integrations_Ai_Engine( $this );
    new Meow_MWFLOW_Integrations_Seo_Engine( $this );
    new Meow_MWFLOW_Integrations_Code_Engine( $this );
    new Meow_MWFLOW_Integrations_Social_Engine( $this );
    new Meow_MWFLOW_Integrations_Woocommerce( $this );
  }

  public function register_triggers() {
    $flows = $this->get_active_flows();
    foreach ( $this->triggers as $trigger ) {
      $trigger->register_for_flows( $flows );
    }
    // Not a trigger, but it belongs to the same "keep our cron events alive"
    // pass: the watchdog that rescues runs whose step chain broke.
    $this->runner->ensure_watchdog_scheduled();
  }

  public function get_active_flows() {
    global $wpdb;
    // Runtime triggers always read the PUBLISHED definition. Draft changes
    // never affect live behaviour until the user clicks Publish.
    $rows = $wpdb->get_results(
      "SELECT id, name, definition_json, trigger_type, trigger_config_json
       FROM {$wpdb->prefix}mwflow_flows
       WHERE is_active = 1 AND definition_json IS NOT NULL"
    );
    $flows = [];
    foreach ( (array) $rows as $row ) {
      $flows[] = [
        'id'             => (int) $row->id,
        'name'           => $row->name,
        'definition'     => json_decode( $row->definition_json, true ) ?: [],
        'trigger_type'   => $row->trigger_type,
        'trigger_config' => json_decode( $row->trigger_config_json, true ) ?: [],
      ];
    }
    return $flows;
  }

  /**
   * Comparable form of a definition for the "unpublished changes" flag. The
   * editor strips the trigger node's derived label/event and ReactFlow's
   * runtime fields before saving, while templates and new flows are published
   * with them, so a raw JSON comparison flagged untouched flows as "not live"
   * after their first auto-save (even just toggling Active).
   */
  private function normalise_definition( $definition ) {
    $nodes = [];
    foreach ( (array) ( $definition['nodes'] ?? [] ) as $node ) {
      $data = (array) ( $node['data'] ?? [] );
      unset( $data['_lastRun'] );
      if ( ( $node['type'] ?? '' ) === 'trigger' || ( $data['kind'] ?? '' ) === 'trigger' ) {
        unset( $data['label'], $data['event'] );
      }
      $nodes[] = [
        'id'       => $node['id'] ?? '',
        'type'     => $node['type'] ?? '',
        'position' => $node['position'] ?? null,
        'data'     => $data,
      ];
    }
    $edges = [];
    foreach ( (array) ( $definition['edges'] ?? [] ) as $edge ) {
      $edges[] = [
        'source'       => $edge['source'] ?? '',
        'target'       => $edge['target'] ?? '',
        'sourceHandle' => $edge['sourceHandle'] ?? null,
      ];
    }
    return wp_json_encode( [ 'nodes' => $nodes, 'edges' => $edges ] );
  }

  /**
   * Pre-flight checks the editor chip and Publish rely on. Returns a list of
   * { node_id, field, level, message }. 'error' blocks publishing, 'warning'
   * only informs. Checks: a trigger exists and is configured, every step's
   * plugin is active, required inputs are filled, {{ references }} point at
   * something that exists, and every step is reachable from the trigger.
   */
  public function validate_definition( $definition, $trigger_type = '', $trigger_config = [] ) {
    $issues = [];
    $nodes = (array) ( $definition['nodes'] ?? [] );
    $edges = (array) ( $definition['edges'] ?? [] );
    $by_id = [];
    $trigger_id = null;
    foreach ( $nodes as $node ) {
      if ( empty( $node['id'] ) ) { continue; }
      $by_id[ $node['id'] ] = $node;
      if ( ( $node['type'] ?? '' ) === 'trigger' || ( $node['data']['kind'] ?? '' ) === 'trigger' ) {
        $trigger_id = $node['id'];
      }
    }

    if ( $trigger_id === null ) {
      $issues[] = $this->issue( null, null, 'error', __( 'The workflow has no trigger.', 'meow-workflow' ) );
    }
    else if ( $trigger_type === 'hook' && empty( $trigger_config['hook'] ) ) {
      $issues[] = $this->issue( $trigger_id, 'hook', 'error', __( 'Trigger: choose which WordPress event starts the workflow.', 'meow-workflow' ) );
    }
    else if ( $trigger_type === 'rss' && empty( $trigger_config['feed_url'] ) ) {
      $issues[] = $this->issue( $trigger_id, 'feed_url', 'error', __( 'Trigger: the RSS feed URL is missing.', 'meow-workflow' ) );
    }

    $known_roots = [ 'trigger', 'site_name', 'site_url', 'admin_email', 'now', 'today', 'today_human', 'year' ];
    foreach ( $by_id as $id => $node ) {
      if ( $id === $trigger_id ) { continue; }
      $action = $this->registry->get_action( $node['data']['integration'] ?? '', $node['data']['action'] ?? '' );
      if ( !$action ) {
        $issues[] = $this->issue( $id, null, 'error', sprintf(
          /* translators: %s is the step id */
          __( 'Step "%s" needs a plugin that is not active.', 'meow-workflow' ), $id
        ) );
        continue;
      }
      $label = sprintf( '%s (%s)', $action['name'] ?? $id, $id );
      $params = (array) ( $node['data']['params'] ?? [] );
      foreach ( (array) ( $action['inputs'] ?? [] ) as $field ) {
        if ( empty( $field['required'] ) || ( $field['type'] ?? '' ) === 'boolean' ) { continue; }
        $value = $params[ $field['id'] ] ?? ( $field['default'] ?? null );
        if ( $value === null || $value === '' || ( is_array( $value ) && empty( $value ) ) ) {
          $issues[] = $this->issue( $id, $field['id'], 'error', sprintf(
            /* translators: 1: step name and id, 2: field name */
            __( '%1$s: "%2$s" is required.', 'meow-workflow' ), $label, $field['name'] ?? $field['id']
          ) );
        }
      }
      $seen = [];
      foreach ( $params as $field_id => $value ) {
        foreach ( $this->reference_roots( $value ) as $root ) {
          if ( isset( $seen[ $root ] ) || in_array( $root, $known_roots, true ) || isset( $by_id[ $root ] ) ) { continue; }
          $seen[ $root ] = true;
          $issues[] = $this->issue( $id, (string) $field_id, 'error', sprintf(
            /* translators: 1: step name and id, 2: the referenced step id */
            __( '%1$s uses {{ %2$s }}, but there is no step called "%2$s".', 'meow-workflow' ), $label, $root
          ) );
        }
      }
    }

    if ( $trigger_id !== null ) {
      $reachable = [ $trigger_id => true ];
      $stack = [ $trigger_id ];
      while ( $stack ) {
        $current = array_pop( $stack );
        foreach ( $edges as $edge ) {
          $target = $edge['target'] ?? '';
          if ( ( $edge['source'] ?? '' ) === $current && $target !== '' && !isset( $reachable[ $target ] ) ) {
            $reachable[ $target ] = true;
            $stack[] = $target;
          }
        }
      }
      foreach ( $by_id as $id => $node ) {
        if ( !isset( $reachable[ $id ] ) ) {
          $issues[] = $this->issue( $id, null, 'warning', sprintf(
            /* translators: %s is the step id */
            __( 'Step "%s" is not connected to the workflow, so it will never run.', 'meow-workflow' ), $id
          ) );
        }
      }
    }
    return $issues;
  }

  private function issue( $node_id, $field, $level, $message ) {
    return [ 'node_id' => $node_id, 'field' => $field, 'level' => $level, 'message' => $message ];
  }

  /** The first path segment of every {{ reference }} found in a value, recursively. */
  private function reference_roots( $value ) {
    $roots = [];
    if ( is_string( $value ) ) {
      if ( preg_match_all( '/\{\{\s*([A-Za-z0-9_]+)/', $value, $m ) ) {
        $roots = $m[1];
      }
    }
    else if ( is_array( $value ) ) {
      foreach ( $value as $item ) {
        $roots = array_merge( $roots, $this->reference_roots( $item ) );
      }
    }
    return $roots;
  }

  /**
   * Fetch a flow. By default returns the DRAFT definition (what the editor
   * is working on); pass $variant='published' to get the published runtime
   * definition. `has_unpublished_changes` is always returned so the UI can
   * decide when to show the Publish button.
   */
  public function get_flow( $id, $variant = 'draft' ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
      "SELECT * FROM {$wpdb->prefix}mwflow_flows WHERE id = %d",
      $id
    ) );
    if ( !$row ) { return null; }
    $published = json_decode( $row->definition_json, true ) ?: [];
    // Defensive against pre-migration rows that don't have the new columns yet.
    $draft_json = isset( $row->definition_draft_json ) ? $row->definition_draft_json : null;
    $draft = $draft_json ? ( json_decode( $draft_json, true ) ?: [] ) : $published;
    $definition = $variant === 'published' ? $published : $draft;
    $sample_json = isset( $row->trigger_sample_json ) ? $row->trigger_sample_json : null;
    $capture     = isset( $row->capture_sample ) ? (int) $row->capture_sample : 0;
    $published_at = isset( $row->published_at ) ? $row->published_at : null;
    return [
      'id'                       => (int) $row->id,
      'name'                     => $row->name,
      'definition'               => $definition,
      'trigger_type'             => $row->trigger_type,
      'trigger_config'           => json_decode( $row->trigger_config_json, true ) ?: [],
      'is_active'                => (int) $row->is_active === 1,
      'has_unpublished_changes'  => $this->normalise_definition( $draft ) !== $this->normalise_definition( $published ),
      'trigger_sample'           => $sample_json ? json_decode( $sample_json, true ) : null,
      'capture_sample'           => $capture === 1,
      'published_at'             => $published_at,
      'created_by'               => isset( $row->created_by ) ? (int) $row->created_by : 0,
      'created'                  => $row->created,
      'updated'                  => $row->updated,
    ];
  }

  /**
   * Toggle "capture next payload" mode for a flow. When enabled, the next real
   * webhook/wp_hook invocation will be stored as the trigger sample and the
   * flow will NOT execute that one call. Subsequent calls behave normally.
   */
  public function set_capture_sample( $flow_id, $enabled ) {
    global $wpdb;
    $wpdb->update(
      "{$wpdb->prefix}mwflow_flows",
      [ 'capture_sample' => $enabled ? 1 : 0 ],
      [ 'id' => (int) $flow_id ]
    );
  }

  /**
   * Store a captured trigger payload. Called by the webhook + wp_hook triggers
   * when `capture_sample` is on; that flag is cleared atomically as the sample
   * is written so the next invocation runs normally.
   *
   * @return bool true if the sample was captured (caller should skip execution).
   */
  public function capture_trigger_sample( $flow_id, $payload ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
      "SELECT capture_sample FROM {$wpdb->prefix}mwflow_flows WHERE id = %d", $flow_id
    ) );
    if ( !$row || (int) $row->capture_sample !== 1 ) { return false; }
    $wpdb->update(
      "{$wpdb->prefix}mwflow_flows",
      [
        'trigger_sample_json' => wp_json_encode( $payload ),
        'capture_sample'      => 0,
      ],
      [ 'id' => (int) $flow_id ]
    );
    return true;
  }

  public function get_flows() {
    global $wpdb;
    $rows = $wpdb->get_results(
      "SELECT id, name, is_active, trigger_type, updated,
              definition_json, definition_draft_json
       FROM {$wpdb->prefix}mwflow_flows
       ORDER BY updated DESC"
    );

    // Latest run status per flow, so the list can flag flows whose last run
    // failed — without it, a nightly failure is invisible until you open Runs.
    $last_runs = [];
    $run_rows = $wpdb->get_results(
      "SELECT r.flow_id, r.status
       FROM {$wpdb->prefix}mwflow_runs r
       INNER JOIN ( SELECT flow_id, MAX(id) AS max_id
                    FROM {$wpdb->prefix}mwflow_runs GROUP BY flow_id ) m
         ON r.id = m.max_id"
    );
    foreach ( (array) $run_rows as $rr ) {
      $last_runs[ (int) $rr->flow_id ] = $rr->status;
    }

    $flows = [];
    foreach ( (array) $rows as $row ) {
      // Show the draft's step count in the list — that's what the user sees
      // when they reopen the editor. Defensive against pre-migration rows.
      $draft_col = isset( $row->definition_draft_json ) ? $row->definition_draft_json : null;
      $source = $draft_col ?: $row->definition_json;
      $definition = json_decode( $source, true );
      $nodes = is_array( $definition['nodes'] ?? null ) ? $definition['nodes'] : [];
      $action_count = 0;
      foreach ( $nodes as $node ) {
        if ( ( $node['type'] ?? '' ) !== 'trigger' ) { $action_count++; }
      }
      $has_unpublished = $draft_col && $draft_col !== $row->definition_json;
      $flows[] = [
        'id'                      => (int) $row->id,
        'name'                    => $row->name,
        'is_active'               => (int) $row->is_active === 1,
        'trigger_type'            => $row->trigger_type,
        'updated'                 => $row->updated,
        'step_count'              => $action_count,
        'has_unpublished_changes' => $has_unpublished,
        'last_run_status'         => $last_runs[ (int) $row->id ] ?? null,
      ];
    }
    return $flows;
  }

  /**
   * Save a flow's draft state. Trigger settings (type, config, is_active)
   * apply immediately — those affect routing not behaviour. The step
   * definition itself goes into `definition_draft_json` and only takes
   * effect after publish_flow() is called.
   *
   * On first save (no $id) the draft and published copies are initialised
   * to the same definition so a brand-new flow can run right away.
   */
  public function save_flow( $data, $id = null ) {
    global $wpdb;
    $now = current_time( 'mysql' );
    $definition_json = wp_json_encode( $data['definition'] ?? [ 'nodes' => [], 'edges' => [] ] );

    $row = [
      'name'                  => sanitize_text_field( $data['name'] ?? __( 'Untitled flow', 'meow-workflow' ) ),
      'definition_draft_json' => $definition_json,
      'is_active'             => !empty( $data['is_active'] ) ? 1 : 0,
      'trigger_type'          => sanitize_key( $data['trigger_type'] ?? 'manual' ),
      'trigger_config_json'   => wp_json_encode( $data['trigger_config'] ?? new stdClass() ),
      'updated'               => $now,
    ];

    if ( $id ) {
      // Backfill the owner on flows created before created_by existed, so
      // their cron/webhook runs gain a proper identity after the next save.
      $existing_owner = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT created_by FROM {$wpdb->prefix}mwflow_flows WHERE id = %d", $id
      ) );
      if ( !$existing_owner && get_current_user_id() ) {
        $row['created_by'] = get_current_user_id();
      }
      $wpdb->update( "{$wpdb->prefix}mwflow_flows", $row, [ 'id' => $id ] );
      $flow_id = $id;
    }
    else {
      // Brand new flow: publish immediately so it can run from the start.
      $row['definition_json'] = $definition_json;
      $row['published_at']    = $now;
      $row['created']         = $now;
      $row['created_by']      = get_current_user_id();
      $wpdb->insert( "{$wpdb->prefix}mwflow_flows", $row );
      $flow_id = (int) $wpdb->insert_id;
    }

    // Re-register triggers since active set / trigger config might have changed.
    foreach ( $this->triggers as $trigger ) {
      $trigger->unregister();
    }
    $this->register_triggers();
    return $flow_id;
  }

  /**
   * Promote the draft definition to published. The runner reads only
   * `definition_json`, so this is what makes editor edits "go live".
   */
  public function publish_flow( $id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
      "SELECT definition_draft_json FROM {$wpdb->prefix}mwflow_flows WHERE id = %d", $id
    ) );
    if ( !$row || $row->definition_draft_json === null ) { return false; }
    $wpdb->update(
      "{$wpdb->prefix}mwflow_flows",
      [
        'definition_json' => $row->definition_draft_json,
        'published_at'    => current_time( 'mysql' ),
      ],
      [ 'id' => $id ]
    );
    // Trigger registrations are based on the published definition.
    foreach ( $this->triggers as $trigger ) {
      $trigger->unregister();
    }
    $this->register_triggers();
    return true;
  }

  public function delete_flow( $id ) {
    global $wpdb;
    $wpdb->delete( "{$wpdb->prefix}mwflow_flows", [ 'id' => $id ] );
    $wpdb->delete( "{$wpdb->prefix}mwflow_runs", [ 'flow_id' => $id ] );
    delete_option( Meow_MWFLOW_Triggers_RSS::SEEN_OPTION_PREFIX . (int) $id );
    foreach ( $this->triggers as $trigger ) {
      $trigger->unregister();
    }
    $this->register_triggers();
  }

  /**
   * Legacy single-shot recording (kept for callers that complete in one step).
   * New code should prefer start_run + save_run_state + finalize_run.
   */
  public function record_run( $flow_id, $payload, $steps, $status, $error = null ) {
    global $wpdb;
    $now = current_time( 'mysql' );
    $wpdb->insert( "{$wpdb->prefix}mwflow_runs", [
      'flow_id'              => $flow_id,
      'status'               => $status,
      'started_at'           => $now,
      'updated_at'           => $now,
      'finished_at'          => $now,
      'trigger_payload_json' => wp_json_encode( $payload ),
      'steps_json'           => wp_json_encode( $steps ),
      'error'                => $error ? substr( (string) $error, 0, 1000 ) : null,
    ] );
    return (int) $wpdb->insert_id;
  }

  /**
   * Create a fresh run row in `running` state. The step state machine writes
   * its working set into `step_state_json` and updates `current_step_id` as it
   * progresses. Used by the async runner.
   *
   * @param int   $flow_id
   * @param array $payload  Trigger payload — the run's `trigger` context.
   * @param array $state    Initial step state ({ queue, states, context, ... }).
   * @return int Run ID.
   */
  public function start_run( $flow_id, $payload, $state ) {
    global $wpdb;
    $now = current_time( 'mysql' );
    $wpdb->insert( "{$wpdb->prefix}mwflow_runs", [
      'flow_id'              => $flow_id,
      'status'               => 'running',
      'started_at'           => $now,
      'updated_at'           => $now,
      'finished_at'          => null,
      'trigger_payload_json' => wp_json_encode( $payload ),
      'steps_json'           => null,
      'current_step_id'      => $state['queue'][0] ?? null,
      'step_state_json'      => wp_json_encode( $state ),
      'error'                => null,
    ] );
    return (int) $wpdb->insert_id;
  }

  /**
   * Reads the working state of an in-progress run. Returns null if the run
   * has been finalised (no step_state_json) or doesn't exist.
   */
  public function load_run_state( $run_id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
      "SELECT id, flow_id, status, step_state_json, trigger_payload_json
       FROM {$wpdb->prefix}mwflow_runs WHERE id = %d", $run_id
    ) );
    if ( !$row ) { return null; }
    $state = json_decode( $row->step_state_json, true );
    if ( !is_array( $state ) ) { return null; }
    return [
      'run_id'  => (int) $row->id,
      'flow_id' => (int) $row->flow_id,
      'status'  => $row->status,
      'payload' => json_decode( $row->trigger_payload_json, true ),
      'state'   => $state,
    ];
  }

  /**
   * Persist the working state of a run. Optionally advances `current_step_id`
   * to the next node in the queue. `updated_at` doubles as the run's heartbeat:
   * the watchdog uses it to tell a slow node from an interrupted run.
   */
  public function save_run_state( $run_id, $state ) {
    global $wpdb;
    $wpdb->update(
      "{$wpdb->prefix}mwflow_runs",
      [
        'current_step_id' => $state['queue'][0] ?? null,
        'step_state_json' => wp_json_encode( $state ),
        'updated_at'      => current_time( 'mysql' ),
      ],
      [ 'id' => $run_id ]
    );
  }

  /**
   * Park a live run at a Wait step. The queue (already holding the steps
   * after the wait) is persisted with the state; `resume_at` tells the Runs
   * screen and the watchdog when it is due back.
   */
  public function pause_run( $run_id, $state, $resume_at_ts ) {
    global $wpdb;
    $wpdb->update(
      "{$wpdb->prefix}mwflow_runs",
      [
        'status'          => 'waiting',
        'resume_at'       => wp_date( 'Y-m-d H:i:s', (int) $resume_at_ts ),
        'current_step_id' => $state['queue'][0] ?? null,
        'step_state_json' => wp_json_encode( $state ),
        'updated_at'      => current_time( 'mysql' ),
      ],
      [ 'id' => $run_id ]
    );
  }

  public function resume_run( $run_id ) {
    global $wpdb;
    $wpdb->update(
      "{$wpdb->prefix}mwflow_runs",
      [ 'status' => 'running', 'resume_at' => null, 'updated_at' => current_time( 'mysql' ) ],
      [ 'id' => $run_id ]
    );
  }

  /** Waiting runs whose resume time passed more than $grace_seconds ago. */
  public function get_overdue_waits( $grace_seconds, $limit = 20 ) {
    global $wpdb;
    return (array) $wpdb->get_results( $wpdb->prepare(
      "SELECT id, flow_id, resume_at
       FROM {$wpdb->prefix}mwflow_runs
       WHERE status = 'waiting'
         AND resume_at IS NOT NULL
         AND resume_at < DATE_SUB( %s, INTERVAL %d SECOND )
       ORDER BY id ASC
       LIMIT %d",
      current_time( 'mysql' ), (int) $grace_seconds, (int) $limit
    ) );
  }

  /**
   * Runs still marked `running` whose heartbeat is older than $seconds.
   * Either the node is genuinely taking that long, or the process that was
   * advancing the run died (fatal, timeout, missed cron pass). The watchdog
   * in the runner decides which, by looking for a pending step event.
   */
  public function get_stalled_runs( $seconds, $limit = 20 ) {
    global $wpdb;
    return (array) $wpdb->get_results( $wpdb->prepare(
      "SELECT id, flow_id, started_at, updated_at
       FROM {$wpdb->prefix}mwflow_runs
       WHERE status = 'running'
         AND updated_at IS NOT NULL
         AND updated_at < DATE_SUB( %s, INTERVAL %d SECOND )
       ORDER BY id ASC
       LIMIT %d",
      current_time( 'mysql' ), (int) $seconds, (int) $limit
    ) );
  }

  /**
   * Mark a run done or failed. Writes finished_at, derives the legacy
   * `steps_json` summary from the state so existing UIs keep working.
   */
  public function finalize_run( $run_id, $status, $state = null, $error = null ) {
    global $wpdb;
    $now = current_time( 'mysql' );
    $update = [
      'status'      => $status,
      'finished_at' => $now,
      'updated_at'  => $now,
      'current_step_id' => null,
      'error'       => $error ? substr( (string) $error, 0, 1000 ) : null,
    ];
    // Callers that finalize from outside the step loop (the fatal guard) have
    // no state in hand. Fall back to the last checkpoint so the run still gets
    // a timeline instead of an empty one.
    if ( !is_array( $state ) ) {
      $loaded = $this->load_run_state( $run_id );
      $state = $loaded ? $loaded['state'] : null;
    }
    if ( is_array( $state ) ) {
      $update['step_state_json'] = wp_json_encode( $state );
      $update['steps_json'] = wp_json_encode( $this->state_to_steps( $state ) );
    }
    $wpdb->update( "{$wpdb->prefix}mwflow_runs", $update, [ 'id' => $run_id ] );

    if ( $status !== 'failed' && $status !== 'done' ) { return; }
    $flow_id = (int) $wpdb->get_var( $wpdb->prepare(
      "SELECT flow_id FROM {$wpdb->prefix}mwflow_runs WHERE id = %d", $run_id
    ) );
    $mode = is_array( $state ) ? ( $state['mode'] ?? '' ) : '';
    do_action( $status === 'failed' ? 'mwflow_run_failed' : 'mwflow_run_done', $run_id, $flow_id, $error, $mode );
    // Only live runs (cron, webhook, schedule) deserve an email: a failed
    // "Test once" is already on screen in front of the person who ran it.
    if ( $status === 'failed' && $mode === 'async' ) {
      $this->notify_run_failed( $run_id, $flow_id, $state, $error );
    }
  }

  /* ------------------------------------------------------------------ */
  /* Settings + failure notifications                                    */
  /* ------------------------------------------------------------------ */

  public function get_settings() {
    $defaults = [
      'notify_failures' => true,
      'notify_email'    => '',
    ];
    $stored = get_option( 'mwflow_settings', [] );
    return array_merge( $defaults, is_array( $stored ) ? $stored : [] );
  }

  public function update_settings( $data ) {
    $settings = $this->get_settings();
    if ( array_key_exists( 'notify_failures', $data ) ) {
      $settings['notify_failures'] = !empty( $data['notify_failures'] );
    }
    if ( array_key_exists( 'notify_email', $data ) ) {
      $email = sanitize_email( (string) $data['notify_email'] );
      $settings['notify_email'] = is_email( $email ) ? $email : '';
    }
    update_option( 'mwflow_settings', $settings, false );
    return $settings;
  }

  /**
   * Email the admin when a live workflow fails. Before this, a broken
   * scheduled or webhook flow only left a line in the log file, and people
   * found out weeks later. One email per flow per hour so a webhook flood
   * can't turn into an inbox flood.
   */
  private function notify_run_failed( $run_id, $flow_id, $state, $error ) {
    $settings = $this->get_settings();
    if ( empty( $settings['notify_failures'] ) ) { return; }
    $to = $settings['notify_email'] ?: get_option( 'admin_email' );
    if ( !is_email( $to ) ) { return; }
    $throttle_key = 'mwflow_fail_mail_' . (int) $flow_id;
    if ( get_transient( $throttle_key ) ) { return; }
    set_transient( $throttle_key, 1, HOUR_IN_SECONDS );

    $flow = $this->get_flow( $flow_id );
    $flow_name = $flow ? $flow['name'] : sprintf( '#%d', $flow_id );
    $failed_step = '';
    foreach ( (array) ( $state['states'] ?? [] ) as $node_id => $s ) {
      if ( ( $s['status'] ?? '' ) === 'failed' ) { $failed_step = $node_id; break; }
    }
    $runs_url = admin_url( 'admin.php?page=mwflow_dashboard&nekoTab=runs' );

    /* translators: %s is the workflow name */
    $subject = sprintf( __( '[%1$s] Workflow "%2$s" failed', 'meow-workflow' ), get_bloginfo( 'name' ), $flow_name );
    $lines = [
      sprintf( __( 'The workflow "%s" failed during a live run.', 'meow-workflow' ), $flow_name ),
      '',
    ];
    if ( $failed_step ) {
      $lines[] = sprintf( __( 'Step: %s', 'meow-workflow' ), $failed_step );
    }
    $lines[] = sprintf( __( 'Error: %s', 'meow-workflow' ), (string) $error );
    $lines[] = '';
    $lines[] = sprintf( __( 'See the run: %s', 'meow-workflow' ), $runs_url );
    $lines[] = '';
    $lines[] = __( 'You will get at most one of these per workflow per hour. Turn them off under Meow Apps → Workflow → Settings.', 'meow-workflow' );
    wp_mail( $to, $subject, implode( "\n", $lines ) );
  }

  /**
   * Project the step state machine's `states` map into the ordered `steps`
   * array shape that the UI Run timeline expects. Completed/failed nodes come
   * first in execution order; a currently-running node is appended at the end
   * so users see live progress on the Runs page.
   */
  private function state_to_steps( $state ) {
    $order  = $state['order'] ?? [];
    $states = $state['states'] ?? [];
    $steps = [];
    foreach ( $order as $node_id ) {
      $s = $states[ $node_id ] ?? null;
      if ( !$s ) { continue; }
      $steps[] = [
        'node_id'     => $node_id,
        'kind'        => $s['kind'] ?? 'action',
        'status'      => $s['status'] ?? 'done',
        'output'      => $s['output'] ?? null,
        'error'       => $s['error'] ?? null,
        'started_at'  => $s['started_at'] ?? null,
        'finished_at' => $s['finished_at'] ?? null,
        'attempts'    => $s['attempts'] ?? 1,
      ];
    }
    // Append any in-flight (running) node not yet in $order so the live UI
    // shows progress while async ticks are still in flight.
    $seen = array_flip( $order );
    foreach ( $states as $node_id => $s ) {
      if ( isset( $seen[ $node_id ] ) ) { continue; }
      if ( ( $s['status'] ?? '' ) !== 'running' ) { continue; }
      $steps[] = [
        'node_id'    => $node_id,
        'kind'       => $s['kind'] ?? 'action',
        'status'     => 'running',
        'started_at' => $s['started_at'] ?? null,
        'attempts'   => $s['attempts'] ?? 1,
      ];
    }
    return $steps;
  }

  public function get_runs( $flow_id = null, $limit = 50 ) {
    global $wpdb;
    $limit = max( 1, min( 500, (int) $limit ) );
    if ( $flow_id ) {
      $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, flow_id, status, started_at, finished_at, resume_at, error
         FROM {$wpdb->prefix}mwflow_runs
         WHERE flow_id = %d
         ORDER BY started_at DESC
         LIMIT %d", $flow_id, $limit
      ) );
    }
    else {
      $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, flow_id, status, started_at, finished_at, resume_at, error
         FROM {$wpdb->prefix}mwflow_runs
         ORDER BY started_at DESC
         LIMIT %d", $limit
      ) );
    }
    return array_map( function ( $r ) {
      return [
        'id'          => (int) $r->id,
        'flow_id'     => (int) $r->flow_id,
        'status'      => $r->status,
        'started_at'  => $r->started_at,
        'finished_at' => $r->finished_at,
        'resume_at'   => $r->resume_at,
        'error'       => $r->error,
      ];
    }, (array) $rows );
  }

  public function get_run( $id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
      "SELECT * FROM {$wpdb->prefix}mwflow_runs WHERE id = %d", $id
    ) );
    if ( !$row ) { return null; }
    // Prefer the live state machine snapshot for in-progress runs; fall back
    // to the legacy `steps_json` for older completed runs.
    $state = $row->step_state_json ? json_decode( $row->step_state_json, true ) : null;
    $steps = is_array( $state )
      ? $this->state_to_steps( $state )
      : ( json_decode( $row->steps_json, true ) ?: [] );
    return [
      'id'              => (int) $row->id,
      'flow_id'         => (int) $row->flow_id,
      'status'          => $row->status,
      'started_at'      => $row->started_at,
      'finished_at'     => $row->finished_at,
      'current_step_id' => $row->current_step_id,
      'payload'         => json_decode( $row->trigger_payload_json, true ),
      'steps'           => $steps,
      'error'           => $row->error,
    ];
  }

  public static function install() {
    self::install_tables();
    update_option( 'mwflow_version', MWFLOW_VERSION );
    update_option( 'mwflow_db_version', self::DB_VERSION );
  }

  public static function maybe_install() {
    if ( get_option( 'mwflow_db_version' ) !== self::DB_VERSION ) {
      self::install_tables();
      update_option( 'mwflow_db_version', self::DB_VERSION );
    }
  }

  private static function install_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    $flows_sql = "CREATE TABLE {$wpdb->prefix}mwflow_flows (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      name VARCHAR(190) NOT NULL DEFAULT '',
      definition_json LONGTEXT NULL,
      definition_draft_json LONGTEXT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 0,
      trigger_type VARCHAR(32) NOT NULL DEFAULT 'manual',
      trigger_config_json LONGTEXT NULL,
      trigger_sample_json LONGTEXT NULL,
      capture_sample TINYINT(1) NOT NULL DEFAULT 0,
      published_at DATETIME NULL,
      created_by BIGINT(20) UNSIGNED NULL,
      created DATETIME NULL,
      updated DATETIME NULL,
      PRIMARY KEY (id),
      KEY is_active (is_active),
      KEY trigger_type (trigger_type)
    ) $charset;";

    $runs_sql = "CREATE TABLE {$wpdb->prefix}mwflow_runs (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      flow_id BIGINT(20) UNSIGNED NOT NULL,
      status VARCHAR(16) NOT NULL DEFAULT 'queued',
      started_at DATETIME NULL,
      updated_at DATETIME NULL,
      finished_at DATETIME NULL,
      resume_at DATETIME NULL,
      trigger_payload_json LONGTEXT NULL,
      steps_json LONGTEXT NULL,
      current_step_id VARCHAR(64) NULL,
      step_state_json LONGTEXT NULL,
      error TEXT NULL,
      PRIMARY KEY (id),
      KEY flow_id (flow_id),
      KEY status (status),
      KEY updated_at (updated_at)
    ) $charset;";

    dbDelta( $flows_sql );
    dbDelta( $runs_sql );

    // On upgrade from v1/v2: backfill the new draft column from the existing
    // published one so every flow has a valid draft on first edit.
    $wpdb->query(
      "UPDATE {$wpdb->prefix}mwflow_flows
       SET definition_draft_json = definition_json
       WHERE definition_draft_json IS NULL"
    );

    // On upgrade to v7: `updated_at` is the runner's heartbeat, used by the
    // watchdog to spot interrupted runs. Seed it from started_at so pre-v7
    // rows don't all look stalled the moment the watchdog first runs.
    $wpdb->query(
      "UPDATE {$wpdb->prefix}mwflow_runs
       SET updated_at = COALESCE( finished_at, started_at )
       WHERE updated_at IS NULL"
    );
  }
}
