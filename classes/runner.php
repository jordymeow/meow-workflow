<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * Workflow runner — step-based state machine.
 *
 * A flow run is no longer one unbroken recursive call stack. Instead the runner
 * maintains a working state on the run row:
 *
 *   state = {
 *     queue:    [ node_id, … ],           // FIFO of nodes still to execute
 *     order:    [ node_id, … ],           // execution order so far (timeline)
 *     states:   { node_id: { status, output, error, started_at, finished_at, attempts, kind } },
 *     context:  { trigger: payload, node_id: output, … },
 *     edges:    [ {source,target,sourceHandle}, … ],   // copied at start, immutable
 *     nodes:    { node_id: node },                       // copied at start, immutable
 *   }
 *
 * One `run_step` call pops one node off the queue, executes it, appends any
 * unblocked children to the queue, and persists the new state. The next call
 * picks up where it left off — works both for in-request loops (sync, e.g.
 * "Test once") and for `wp_schedule_single_event`-driven async runs (webhook,
 * wp_hook, schedule triggers).
 *
 * Special node kinds handled directly here (no integration callback needed):
 *   - "trigger"   — entry point; emits the trigger payload as its output
 *   - "condition" — branches by evaluating the If/else conditions array
 *   - "router"    — picks a branch by string match on params.value
 *
 * All other nodes resolve through the integration registry.
 */
class Meow_MWFLOW_Runner {

  /** wp action hook name fired by wp_cron to advance an async run. */
  const STEP_HOOK = 'mwflow_run_step';

  /** Safety cap on how many steps a single sync run will execute. */
  const SYNC_STEP_LIMIT = 500;

  /**
   * Hard ceiling on TOTAL steps executed across a whole run, on every path
   * (sync, stepwise, and async/cron). SYNC_STEP_LIMIT only bounds the in-request
   * sync loop; without this, an async run whose graph loops (a For Each that
   * re-reaches an earlier node, or a hand-drawn cycle) reschedules itself via
   * wp-cron forever. Enforced in execute_next() so no path can bypass it. Set
   * well above any legitimate run (FOREACH_MAX_ITEMS-bounded fan-outs included).
   */
  const MAX_TOTAL_STEPS = 10000;

  /** Hard ceiling on For Each iterations, whatever the step's limit says. */
  const FOREACH_MAX_ITEMS = 100;

  private $core;

  public function __construct( $core ) {
    $this->core = $core;
    add_action( self::STEP_HOOK, [ $this, 'run_step' ], 10, 1 );
  }

  /* ------------------------------------------------------------------ */
  /* Public entry points                                                */
  /* ------------------------------------------------------------------ */

  /**
   * Start a run for the given flow.
   *
   * @param int   $flow_id
   * @param array $payload Trigger payload — exposed as `{{ trigger.* }}` in steps.
   * @param array $options {
   *   @type bool $sync If true, executes the whole run in-process and returns
   *                    the final state. If false (default), schedules the run
   *                    via wp_cron and returns immediately with status 'running'.
   * }
   * @return array { run_id, status, steps, error?, current_step_id? }
   */
  public function run( $flow_id, $payload = [], $options = [] ) {
    $sync = !empty( $options['sync'] );
    $stepwise = !empty( $options['stepwise'] );
    // Sync / stepwise = "Test once" from the editor → run the DRAFT so the
    // user can verify their edits. Async / live triggers always run the
    // PUBLISHED definition so an in-progress edit can't accidentally hit
    // production.
    $variant = ( $sync || $stepwise ) ? 'draft' : 'published';

    $flow = $this->core->get_flow( $flow_id, $variant );
    if ( !$flow ) {
      return [ 'status' => 'failed', 'error' => 'Flow not found.' ];
    }

    // Cron and webhook runs execute with no logged-in user, which makes
    // AI Engine treat the flow as a guest (guest limits apply!) and leaves
    // created posts without an author. A flow does exactly what its author
    // configured, so run it under the author's identity.
    if ( !get_current_user_id() && !empty( $flow['created_by'] ) ) {
      wp_set_current_user( (int) $flow['created_by'] );
    }

    // Test runs with an empty payload should default to the captured
    // trigger sample (if any). Lets users press "Test once" on a webhook /
    // wp_hook flow and have it run against the real payload shape. With no
    // sample, WordPress-event flows get a payload synthesized from real site
    // data — so "Test once" works out of the box on a fresh template instead
    // of failing with "required input missing".
    if ( ( $sync || $stepwise ) && empty( $payload ) ) {
      if ( !empty( $flow['trigger_sample'] ) ) {
        $payload = $flow['trigger_sample'];
      }
      else if ( ( $flow['trigger_type'] ?? '' ) === 'hook' ) {
        $payload = $this->synthesize_hook_sample( $flow['trigger_config'] ?? [] );
      }
    }

    $definition = $flow['definition'];
    $entry = $this->find_entry_node( $definition['nodes'] ?? [] );
    if ( !$entry ) {
      $error = 'Flow has no entry node.';
      $this->core->logging->error( $error, [ 'flow_id' => $flow_id ] );
      $run_id = $this->core->record_run( $flow_id, $payload, [], 'failed', $error );
      return [ 'run_id' => $run_id, 'status' => 'failed', 'error' => $error, 'steps' => [] ];
    }

    $state = $this->init_state( $definition, $entry['id'], $payload );
    $run_id = $this->core->start_run( $flow_id, $payload, $state );

    // Stepwise = the editor's "Test once": the run is created but nothing
    // executes yet — the client advances it one step at a time via
    // POST /runs/<id>/advance so the canvas can show live progress.
    if ( $stepwise ) {
      return [
        'run_id'          => $run_id,
        'status'          => 'running',
        'current_step_id' => $state['queue'][0] ?? null,
        'steps'           => [],
      ];
    }

    if ( $sync ) {
      return $this->advance_inline( $run_id, $flow_id, $state );
    }

    // Async: schedule the first step. Each step schedules the next.
    wp_schedule_single_event( time(), self::STEP_HOOK, [ $run_id ] );
    return [
      'run_id'          => $run_id,
      'status'          => 'running',
      'current_step_id' => $state['queue'][0] ?? null,
      'steps'           => [],
    ];
  }

  /**
   * WP-cron action handler. Advances an in-progress run by one step.
   * Schedules itself again if more work remains.
   */
  public function run_step( $run_id ) {
    $run_id = (int) $run_id;
    $loaded = $this->core->load_run_state( $run_id );
    if ( !$loaded || $loaded['status'] !== 'running' ) { return; }

    // Async continuations arrive via WP-Cron with no logged-in user — adopt
    // the flow author's identity here too (see run() for the rationale).
    if ( !get_current_user_id() && !empty( $loaded['flow_id'] ) ) {
      $owner_flow = $this->core->get_flow( (int) $loaded['flow_id'] );
      if ( $owner_flow && !empty( $owner_flow['created_by'] ) ) {
        wp_set_current_user( (int) $owner_flow['created_by'] );
      }
    }

    $state = $loaded['state'];
    if ( empty( $state['queue'] ) && !$this->maybe_continue_loops( $state ) ) {
      $this->core->finalize_run( $run_id, 'done', $state );
      return;
    }

    try {
      $this->execute_next( $state );
      // A drained queue may just mean the current For Each iteration ended.
      if ( empty( $state['queue'] ) ) {
        $this->maybe_continue_loops( $state );
      }
      $this->core->save_run_state( $run_id, $state );
      if ( !empty( $state['queue'] ) ) {
        wp_schedule_single_event( time(), self::STEP_HOOK, [ $run_id ] );
      }
      else {
        $this->core->finalize_run( $run_id, 'done', $state );
        $this->core->logging->info( 'Flow completed.', [
          'flow_id' => $loaded['flow_id'], 'run_id' => $run_id,
        ] );
      }
    }
    catch ( \Throwable $e ) {
      $error = $e->getMessage();
      $this->core->finalize_run( $run_id, 'failed', $state, $error );
      $this->core->logging->error( 'Flow failed.', [
        'flow_id' => $loaded['flow_id'], 'run_id' => $run_id, 'error' => $error,
      ] );
    }
  }

  /**
   * Execute exactly one step of a stepwise run and persist the new state.
   * Called by the editor (POST /runs/<id>/advance) in a loop, one request per
   * step, so the canvas can light nodes up as they run. Returns the run
   * summary after the step, or null when the run doesn't exist.
   */
  public function advance( $run_id ) {
    $run_id = (int) $run_id;
    $loaded = $this->core->load_run_state( $run_id );
    if ( !$loaded ) { return null; }

    $state = $loaded['state'];
    if ( $loaded['status'] !== 'running' ) {
      return [
        'run_id' => $run_id,
        'status' => $loaded['status'],
        'steps'  => $this->state_to_steps_for_return( $state ),
      ];
    }

    try {
      if ( !empty( $state['queue'] ) ) {
        $this->execute_next( $state );
      }
      // A drained queue may just mean the current For Each iteration ended.
      if ( empty( $state['queue'] ) ) {
        $this->maybe_continue_loops( $state );
      }
      if ( empty( $state['queue'] ) ) {
        $this->core->finalize_run( $run_id, 'done', $state );
        $status = 'done';
      }
      else {
        $this->core->save_run_state( $run_id, $state );
        $status = 'running';
      }
      return [
        'run_id'          => $run_id,
        'status'          => $status,
        'current_step_id' => $state['queue'][0] ?? null,
        'steps'           => $this->state_to_steps_for_return( $state ),
      ];
    }
    catch ( \Throwable $e ) {
      $error = $e->getMessage();
      $this->core->finalize_run( $run_id, 'failed', $state, $error );
      $this->core->logging->error( 'Flow failed.', [
        'flow_id' => $loaded['flow_id'], 'run_id' => $run_id, 'error' => $error,
      ] );
      return [
        'run_id' => $run_id,
        'status' => 'failed',
        'error'  => $error,
        'steps'  => $this->state_to_steps_for_return( $state ),
      ];
    }
  }

  /* ------------------------------------------------------------------ */
  /* Sync path — loop in-request                                        */
  /* ------------------------------------------------------------------ */

  private function advance_inline( $run_id, $flow_id, $state ) {
    $i = 0;
    try {
      while ( $i++ < self::SYNC_STEP_LIMIT ) {
        if ( empty( $state['queue'] ) && !$this->maybe_continue_loops( $state ) ) {
          break;
        }
        $this->execute_next( $state );
      }
      $this->core->finalize_run( $run_id, 'done', $state );
      $this->core->logging->info( 'Flow completed.', [ 'flow_id' => $flow_id, 'run_id' => $run_id ] );
      return [
        'run_id' => $run_id,
        'status' => 'done',
        'steps'  => $this->state_to_steps_for_return( $state ),
      ];
    }
    catch ( \Throwable $e ) {
      $error = $e->getMessage();
      $this->core->finalize_run( $run_id, 'failed', $state, $error );
      $this->core->logging->error( 'Flow failed.', [
        'flow_id' => $flow_id, 'run_id' => $run_id, 'error' => $error,
      ] );
      return [
        'run_id' => $run_id,
        'status' => 'failed',
        'error'  => $error,
        'steps'  => $this->state_to_steps_for_return( $state ),
      ];
    }
  }

  private function state_to_steps_for_return( $state ) {
    $steps = [];
    foreach ( $state['order'] ?? [] as $node_id ) {
      $s = $state['states'][ $node_id ] ?? null;
      if ( !$s ) { continue; }
      $steps[] = [
        'node_id' => $node_id,
        'kind'    => $s['kind'] ?? 'action',
        'status'  => $s['status'] ?? 'done',
        'output'  => $s['output'] ?? null,
        'error'   => $s['error'] ?? null,
      ];
    }
    return $steps;
  }

  /* ------------------------------------------------------------------ */
  /* State machine internals                                            */
  /* ------------------------------------------------------------------ */

  private function init_state( $definition, $entry_id, $payload ) {
    $nodes_by_id = [];
    foreach ( ( $definition['nodes'] ?? [] ) as $node ) {
      if ( !empty( $node['id'] ) ) {
        $nodes_by_id[ $node['id'] ] = $node;
      }
    }
    return [
      'queue'   => [ $entry_id ],
      'order'   => [],
      'states'  => [],
      'context' => array_merge( $this->site_globals(), [ 'trigger' => $payload ] ),
      'edges'   => $definition['edges'] ?? [],
      'nodes'   => $nodes_by_id,
    ];
  }

  /**
   * Build a realistic payload for a hook-trigger test run from the site's own
   * content: the latest post / user / comment depending on the bound event.
   * Only used when there's no captured sample — real captures always win.
   */
  private function synthesize_hook_sample( $config ) {
    $hook = (string) ( $config['hook'] ?? '' );
    if ( in_array( $hook, [ 'publish_post', 'post_updated', 'trashed_post' ], true ) ) {
      $posts = get_posts( [ 'numberposts' => 1, 'post_status' => 'publish' ] );
      return $posts ? [ 'post_id' => (int) $posts[0]->ID ] : [];
    }
    if ( $hook === 'user_register' ) {
      $users = get_users( [ 'number' => 1, 'orderby' => 'registered', 'order' => 'DESC' ] );
      return $users ? [ 'user_id' => (int) $users[0]->ID ] : [];
    }
    if ( in_array( $hook, [ 'comment_post', 'comment_unapproved_to_approved' ], true ) ) {
      $comments = get_comments( [ 'number' => 1 ] );
      return $comments ? [ 'comment_id' => (int) $comments[0]->comment_ID ] : [];
    }
    if ( strpos( $hook, 'woocommerce_' ) === 0 && function_exists( 'wc_get_orders' ) ) {
      $orders = wc_get_orders( [ 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ] );
      if ( !$orders ) { return []; }
      $sample = [ 'order_id' => (int) $orders[0]->get_id() ];
      if ( $hook === 'woocommerce_order_status_changed' ) {
        $sample['from_status'] = 'processing';
        $sample['to_status']   = $orders[0]->get_status();
      }
      return $sample;
    }
    return [];
  }

  /**
   * Always-available values seeded into every run's `{{ }}` context. Lets
   * beginners reference common site facts without an extra "get" step — e.g.
   * {{ admin_email }}, {{ site_name }}, {{ today }}. These are top-level keys
   * (no prefix) because that reads most naturally in a template.
   */
  private function site_globals() {
    $now_ts = current_time( 'timestamp' );
    return [
      'site_name'   => get_bloginfo( 'name' ),
      'site_url'    => home_url(),
      'admin_email' => get_option( 'admin_email' ),
      'now'         => current_time( 'mysql' ),               // 2026-05-26 14:03:00
      'today'       => wp_date( 'Y-m-d', $now_ts ),           // 2026-05-26
      'today_human' => wp_date( get_option( 'date_format' ), $now_ts ), // May 26, 2026
      'year'        => wp_date( 'Y', $now_ts ),
    ];
  }

  /**
   * Pop one node from the queue, execute it, append unblocked children.
   *
   * Honours per-step failure policy (`node.data.policy`):
   *   on_failure:        'stop' | 'continue' | 'go_to_failure_branch'
   *   retry_count:       0–3
   *   retry_delay_seconds: 0–60
   *
   * Mutates $state in place. Re-throws only when the policy says 'stop'.
   */
  private function execute_next( &$state ) {
    if ( empty( $state['queue'] ) ) { return; }
    $node_id = array_shift( $state['queue'] );
    $node = $state['nodes'][ $node_id ] ?? null;
    if ( !$node ) { return; }

    // Global runaway guard — counts every executed step across all run paths and
    // survives in the persisted state, so an async/cron loop can't outlive it.
    // Throwing here routes through the per-path catch blocks → run finalized as
    // failed with a clear message, instead of an endless wp-cron reschedule.
    $state['steps_executed'] = ( $state['steps_executed'] ?? 0 ) + 1;
    if ( $state['steps_executed'] > self::MAX_TOTAL_STEPS ) {
      throw new Exception( sprintf(
        'Workflow stopped after %d steps — it may contain a loop that never ends.',
        self::MAX_TOTAL_STEPS
      ) );
    }

    $kind = $node['data']['kind'] ?? 'action';
    $params = $node['data']['params'] ?? [];
    $policy = is_array( $node['data']['policy'] ?? null ) ? $node['data']['policy'] : [];
    $on_failure = $policy['on_failure'] ?? 'stop';
    $max_attempts = max( 1, min( 4, 1 + (int) ( $policy['retry_count'] ?? 0 ) ) );
    $retry_delay = max( 0, min( 60, (int) ( $policy['retry_delay_seconds'] ?? 0 ) ) );
    $branch = null;

    $prev_attempts = $state['states'][ $node_id ]['attempts'] ?? 0;
    $state['states'][ $node_id ] = [
      'kind'       => $kind,
      'status'     => 'running',
      'started_at' => current_time( 'mysql' ),
      'attempts'   => $prev_attempts + 1,
    ];

    $attempt = 0;
    $last_error = null;
    while ( $attempt < $max_attempts ) {
      $attempt++;
      try {
        if ( $kind === 'trigger' ) {
          $output = $state['context']['trigger'];
        }
        else if ( $this->is_condition( $node ) ) {
          $matched = $this->evaluate_branching( $params, $state['context'] );
          $output = [ 'matched' => $matched ? 'true' : 'false' ];
          $branch = $matched ? 'true' : 'false';
        }
        else if ( $this->is_foreach( $node ) ) {
          $items = $this->resolve_value( $params['items'] ?? '', $state['context'] );
          if ( !is_array( $items ) ) {
            $items = ( $items === null || $items === '' ) ? [] : [ $items ];
          }
          $items = array_values( $items );
          $limit = max( 1, min( self::FOREACH_MAX_ITEMS, (int) ( $params['limit'] ?? 25 ) ) );
          if ( count( $items ) > $limit ) { $items = array_slice( $items, 0, $limit ); }
          if ( empty( $items ) ) {
            $output = [ 'item' => null, 'index' => null, 'count' => 0 ];
            $branch = 'done';
          }
          else {
            // Register the loop (insertion order doubles as the nesting
            // stack — the most recent entry is the innermost active loop).
            $state['loops'][ $node_id ] = [ 'items' => $items, 'index' => 0 ];
            $output = [ 'item' => $items[0], 'index' => 0, 'count' => count( $items ) ];
            $branch = 'each';
          }
        }
        else if ( $this->is_router( $node ) ) {
          $value = $this->resolve_value( $params['value'] ?? '', $state['context'] );
          $matched = $this->match_router_branch( $value, $params['branches'] ?? null );
          if ( $matched === null ) {
            // No branch rows configured — legacy behaviour: route on the raw
            // value (edges labelled with the value itself).
            $output = [ 'branch' => (string) $value, 'value' => $value ];
            $branch = (string) $value;
          }
          else {
            $output = [ 'branch' => $matched['name'], 'value' => $value ];
            // Route on the branch row's stable id. On no match this is a
            // sentinel no edge carries, so only unlabelled (default) edges fire.
            $branch = $matched['id'];
          }
        }
        else {
          $integration_id = $node['data']['integration'] ?? '';
          $action_id      = $node['data']['action'] ?? '';
          $action = $this->core->registry->get_action( $integration_id, $action_id );
          if ( !$action ) {
            throw new Exception( sprintf( 'Action %s/%s not registered.',
              $integration_id ?: '?', $action_id ?: '?' ) );
          }
          $inputs = $this->resolve_inputs( $action['inputs'], $params, $state['context'] );
          $output = call_user_func( $action['callback'], $inputs, $state['context'] );
          if ( !is_array( $output ) ) { $output = [ 'result' => $output ]; }
        }

        // Success — record and enqueue children.
        $state['context'][ $node_id ] = $output;
        $state['states'][ $node_id ] = array_merge( $state['states'][ $node_id ], [
          'status'      => 'done',
          'output'      => $output,
          'finished_at' => current_time( 'mysql' ),
          'attempts'    => $prev_attempts + $attempt,
        ] );
        $state['order'][] = $node_id;

        foreach ( $this->next_node_ids( $node_id, $state['edges'], $branch ) as $next_id ) {
          if ( isset( $state['nodes'][ $next_id ] ) && !$this->already_handled( $next_id, $state ) ) {
            $state['queue'][] = $next_id;
          }
        }
        return;
      }
      catch ( \Throwable $e ) {
        $last_error = $e;
        if ( $attempt < $max_attempts && $retry_delay > 0 ) {
          // Inline retry sleep is fine here — runs in either sync (Test once)
          // or async (wp_cron tick) context, both safe to block briefly.
          sleep( $retry_delay );
        }
      }
    }

    // Permanent failure after all attempts. Apply on_failure policy.
    $state['states'][ $node_id ] = array_merge( $state['states'][ $node_id ], [
      'status'      => 'failed',
      'error'       => $last_error->getMessage(),
      'finished_at' => current_time( 'mysql' ),
      'attempts'    => $prev_attempts + $attempt,
    ] );
    $state['order'][] = $node_id;

    if ( $on_failure === 'continue' ) {
      // Follow normal downstream edges as if the step had succeeded.
      foreach ( $this->next_node_ids( $node_id, $state['edges'], null ) as $next_id ) {
        if ( isset( $state['nodes'][ $next_id ] ) && !$this->already_handled( $next_id, $state ) ) {
          $state['queue'][] = $next_id;
        }
      }
      return;
    }
    if ( $on_failure === 'go_to_failure_branch' ) {
      // Follow only edges with sourceHandle 'fail'. The user wires those
      // manually for error-recovery paths.
      foreach ( $this->next_node_ids( $node_id, $state['edges'], 'fail' ) as $next_id ) {
        if ( isset( $state['nodes'][ $next_id ] ) && !$this->already_handled( $next_id, $state ) ) {
          $state['queue'][] = $next_id;
        }
      }
      return;
    }
    // Default: stop the whole flow.
    throw $last_error;
  }

  /**
   * Resolve which router branch matches $value. Branch config is either the
   * modern array of {id, name, value} rows (BranchesField) or the legacy
   * comma-separated string where each token is both name and value (its token
   * doubles as the row id, so edges wired before the rename survive).
   * Comparison is trimmed + case-insensitive — beginners shouldn't lose a run
   * to "Heads" vs "heads".
   *
   * Returns the matching row, a no-match sentinel row when branches are
   * configured but nothing matched, or null when no branches are configured.
   */
  private function match_router_branch( $value, $raw ) {
    $rows = [];
    if ( is_array( $raw ) ) {
      foreach ( $raw as $i => $b ) {
        if ( !is_array( $b ) ) { continue; }
        $name = trim( (string) ( $b['name'] ?? ( $b['value'] ?? '' ) ) );
        $val  = (string) ( array_key_exists( 'value', $b ) && $b['value'] !== '' ? $b['value'] : $name );
        if ( $name === '' && trim( $val ) === '' ) { continue; }
        $id = (string) ( $b['id'] ?? '' );
        if ( $id === '' ) { $id = $name !== '' ? $name : 'b' . $i; }
        $rows[] = [ 'id' => $id, 'name' => $name !== '' ? $name : $val, 'value' => $val ];
      }
    }
    else if ( is_string( $raw ) && trim( $raw ) !== '' ) {
      foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) as $token ) {
        $rows[] = [ 'id' => $token, 'name' => $token, 'value' => $token ];
      }
    }
    if ( empty( $rows ) ) { return null; }

    $needle = mb_strtolower( trim( (string) $value ) );
    foreach ( $rows as $row ) {
      if ( mb_strtolower( trim( $row['value'] ) ) === $needle ) { return $row; }
    }
    return [ 'id' => '__no_match__', 'name' => '' ];
  }

  private function already_handled( $node_id, $state ) {
    return isset( $state['states'][ $node_id ] )
      && in_array( $state['states'][ $node_id ]['status'] ?? '', [ 'done', 'failed' ], true );
  }

  private function find_entry_node( $nodes ) {
    foreach ( $nodes as $node ) {
      $type = $node['type'] ?? '';
      if ( $type === 'trigger' || ( $node['data']['kind'] ?? '' ) === 'trigger' ) {
        return $node;
      }
    }
    return reset( $nodes ) ?: null;
  }

  private function is_condition( $node ) {
    return ( $node['type'] ?? '' ) === 'condition'
      || ( $node['data']['kind'] ?? '' ) === 'condition'
      || ( $node['data']['action'] ?? '' ) === 'condition';
  }

  private function is_router( $node ) {
    return ( $node['type'] ?? '' ) === 'router'
      || ( $node['data']['kind'] ?? '' ) === 'router'
      || ( $node['data']['action'] ?? '' ) === 'router';
  }

  private function is_foreach( $node ) {
    return ( $node['data']['action'] ?? '' ) === 'foreach';
  }

  /**
   * Called whenever the queue runs dry: advance the innermost active For Each
   * loop (next item → reset its body → re-enqueue), or finish it (follow its
   * 'done' edges). Returns true when it queued more work — the run goes on.
   * LIFO over the loops map gives correct nested-loop semantics: the outer
   * loop only advances after the inner one has fully drained, and resetting
   * the outer body wipes the inner loop's record so it starts fresh.
   */
  private function maybe_continue_loops( &$state ) {
    while ( !empty( $state['loops'] ) ) {
      $ids = array_keys( $state['loops'] );
      $fid = end( $ids );
      $loop = $state['loops'][ $fid ];
      $count = count( $loop['items'] );
      $next = $loop['index'] + 1;

      if ( $next < $count ) {
        $state['loops'][ $fid ]['index'] = $next;
        $ctx = [ 'item' => $loop['items'][ $next ], 'index' => $next, 'count' => $count ];
        $state['context'][ $fid ] = $ctx;
        // Keep the node's recorded output on the current item so the editor
        // and Runs timeline show live loop progress.
        if ( isset( $state['states'][ $fid ] ) ) {
          $state['states'][ $fid ]['output'] = $ctx;
        }
        foreach ( $this->loop_body_ids( $fid, $state['edges'] ) as $bid ) {
          unset( $state['states'][ $bid ], $state['context'][ $bid ], $state['loops'][ $bid ] );
        }
        foreach ( $this->next_node_ids( $fid, $state['edges'], 'each' ) as $next_id ) {
          if ( isset( $state['nodes'][ $next_id ] ) && !$this->already_handled( $next_id, $state ) ) {
            $state['queue'][] = $next_id;
          }
        }
        if ( !empty( $state['queue'] ) ) { return true; }
        continue; // empty body — skim remaining iterations
      }

      unset( $state['loops'][ $fid ] );
      foreach ( $this->next_node_ids( $fid, $state['edges'], 'done' ) as $next_id ) {
        if ( isset( $state['nodes'][ $next_id ] ) && !$this->already_handled( $next_id, $state ) ) {
          $state['queue'][] = $next_id;
        }
      }
      if ( !empty( $state['queue'] ) ) { return true; }
    }
    return false;
  }

  /**
   * Every node reachable from a For Each through its loop edges (anything but
   * 'done') — the subgraph that re-runs per item, excluding the loop node.
   */
  private function loop_body_ids( $foreach_id, $edges ) {
    $body = [];
    $queue = [];
    foreach ( (array) $edges as $e ) {
      if ( ( $e['source'] ?? '' ) === $foreach_id && ( $e['sourceHandle'] ?? '' ) !== 'done' ) {
        $queue[] = $e['target'];
      }
    }
    while ( $queue ) {
      $id = array_shift( $queue );
      if ( $id === $foreach_id || isset( $body[ $id ] ) ) { continue; }
      $body[ $id ] = true;
      foreach ( (array) $edges as $e ) {
        if ( ( $e['source'] ?? '' ) === $id ) { $queue[] = $e['target']; }
      }
    }
    return array_keys( $body );
  }

  /**
   * Outgoing edge targets to walk for the given active branch.
   *
   * Two handles are RESERVED and exact-match only: 'fail' (error-recovery
   * paths) and 'done' (after a For Each finishes all items). They never fire
   * on a normal/catch-all walk, and their branches never fire other edges —
   * a loop-body edge must not also run after the loop, and vice versa.
   *
   * For ordinary branches, an unlabelled edge is the default/catch-all and
   * always fires; a labelled edge fires only when its label matches $branch.
   */
  private function next_node_ids( $node_id, $edges, $branch = null ) {
    $out = [];
    foreach ( (array) $edges as $edge ) {
      if ( ( $edge['source'] ?? '' ) !== $node_id ) { continue; }
      $handle = $edge['sourceHandle'] ?? '';
      if ( $branch === 'fail' || $branch === 'done' ) {
        if ( $handle !== $branch ) { continue; }
      }
      else {
        if ( $handle === 'fail' || $handle === 'done' ) { continue; }
        if ( $branch !== null && $handle !== '' && $handle !== $branch ) { continue; }
      }
      $out[] = $edge['target'];
    }
    return $out;
  }

  /* ------------------------------------------------------------------ */
  /* Input + expression resolution (unchanged behaviour)                */
  /* ------------------------------------------------------------------ */

  private function resolve_inputs( $schema, $params, $context ) {
    $out = [];
    foreach ( (array) $schema as $field ) {
      $id = $field['id'];
      $raw = $params[ $id ] ?? ( $field['default'] ?? null );
      $resolved = $this->resolve_value( $raw, $context );
      if ( $resolved === null && !empty( $field['required'] ) ) {
        throw new Exception( sprintf( 'Required input "%s" is missing.', $id ) );
      }
      $out[ $id ] = $this->coerce( $resolved, $field['type'] ?? 'string' );
    }
    return $out;
  }

  public function resolve_value( $value, $context ) {
    if ( !is_string( $value ) || strpos( $value, '{{' ) === false ) {
      return $value;
    }
    $pattern = '/\{\{\s*(.+?)\s*\}\}/';
    // Whole-string expression → preserve the native type (array/object/number).
    if ( preg_match( '/^\s*\{\{\s*(.+?)\s*\}\}\s*$/', $value, $m ) ) {
      return $this->resolve_expression( trim( $m[1] ), $context );
    }
    // Embedded expression(s) → interpolate into the surrounding string.
    return preg_replace_callback( $pattern, function ( $m ) use ( $context ) {
      $resolved = $this->resolve_expression( trim( $m[1] ), $context );
      if ( is_array( $resolved ) || is_object( $resolved ) ) {
        return wp_json_encode( $resolved );
      }
      if ( is_bool( $resolved ) ) {
        return $resolved ? 'true' : 'false';
      }
      return (string) $resolved;
    }, $value );
  }

  /**
   * Resolve one expression body: a dotted path optionally followed by pipe
   * filters, e.g. `post.title | upper` or `now | date:"F j, Y"`.
   *
   * Filters keep beginners out of code for the common formatting cases.
   * Supported: upper, lower, trim, default:"…", truncate:N, date:"FORMAT",
   * number_format, json. Unknown filters pass the value through unchanged.
   */
  private function resolve_expression( $expr, $context ) {
    // Split on top-level pipes. Pipes inside quotes are preserved.
    $segments = $this->split_pipes( $expr );
    $path = trim( array_shift( $segments ) );
    $value = $this->lookup_path( $path, $context );
    foreach ( $segments as $seg ) {
      $value = $this->apply_filter( trim( $seg ), $value );
    }
    return $value;
  }

  private function split_pipes( $expr ) {
    $out = [];
    $buf = '';
    $in_quote = false;
    $quote_char = '';
    $len = strlen( $expr );
    for ( $i = 0; $i < $len; $i++ ) {
      $ch = $expr[ $i ];
      if ( $in_quote ) {
        if ( $ch === $quote_char ) { $in_quote = false; }
        $buf .= $ch;
      }
      else if ( $ch === '"' || $ch === "'" ) {
        $in_quote = true; $quote_char = $ch; $buf .= $ch;
      }
      else if ( $ch === '|' ) {
        $out[] = $buf; $buf = '';
      }
      else {
        $buf .= $ch;
      }
    }
    $out[] = $buf;
    return $out;
  }

  private function apply_filter( $segment, $value ) {
    if ( $segment === '' ) { return $value; }
    // name:arg  — arg may be quoted.
    $name = $segment;
    $arg = null;
    if ( strpos( $segment, ':' ) !== false ) {
      list( $name, $arg ) = explode( ':', $segment, 2 );
      $name = trim( $name );
      $arg = trim( $arg );
      if ( strlen( $arg ) >= 2 && ( ( $arg[0] === '"' && substr( $arg, -1 ) === '"' ) || ( $arg[0] === "'" && substr( $arg, -1 ) === "'" ) ) ) {
        $arg = substr( $arg, 1, -1 );
      }
    }

    switch ( $name ) {
      case 'upper':
        return is_scalar( $value ) ? mb_strtoupper( (string) $value ) : $value;
      case 'lower':
        return is_scalar( $value ) ? mb_strtolower( (string) $value ) : $value;
      case 'ucfirst':
        return is_scalar( $value ) ? ucfirst( (string) $value ) : $value;
      case 'trim':
        return is_scalar( $value ) ? trim( (string) $value ) : $value;
      case 'default':
        $empty = ( $value === null || $value === '' || $value === false
          || ( is_array( $value ) && count( $value ) === 0 ) );
        return $empty ? ( $arg ?? '' ) : $value;
      case 'truncate':
        $limit = max( 0, (int) $arg );
        if ( is_scalar( $value ) && $limit > 0 && mb_strlen( (string) $value ) > $limit ) {
          return mb_substr( (string) $value, 0, $limit ) . '…';
        }
        return $value;
      case 'date':
        $ts = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
        if ( !$ts ) { return $value; }
        return wp_date( $arg ?: get_option( 'date_format' ), $ts );
      case 'number_format':
        return is_numeric( $value ) ? number_format_i18n( (float) $value, $arg !== null ? (int) $arg : 0 ) : $value;
      case 'strip_tags':
        return is_scalar( $value ) ? wp_strip_all_tags( (string) $value ) : $value;
      case 'json':
        return wp_json_encode( $value );
      default:
        return $value; // unknown filter — pass through
    }
  }

  private function lookup_path( $path, $context ) {
    $parts = explode( '.', $path );
    $value = $context;
    foreach ( $parts as $key ) {
      $key = trim( $key );
      if ( is_array( $value ) && array_key_exists( $key, $value ) ) {
        $value = $value[ $key ];
      }
      else if ( is_object( $value ) && isset( $value->$key ) ) {
        $value = $value->$key;
      }
      else {
        return null;
      }
    }
    return $value;
  }

  /**
   * Evaluate the "Condition" node — a list of {field, op, value} rows
   * combined by `match` = "all" (AND) or "any" (OR).
   *
   * Back-compat: a legacy `expression` param is treated as a single truthy check.
   */
  private function evaluate_branching( $params, $context ) {
    $conditions = $params['conditions'] ?? [];
    $match = ( $params['match'] ?? 'all' ) === 'any' ? 'any' : 'all';

    if ( !is_array( $conditions ) || empty( $conditions ) ) {
      if ( array_key_exists( 'expression', $params ) ) {
        return (bool) $this->resolve_value( $params['expression'], $context );
      }
      return false;
    }

    $evaluated = false;
    foreach ( $conditions as $cond ) {
      if ( !is_array( $cond ) ) { continue; }
      $left  = $this->resolve_value( $cond['field'] ?? '', $context );
      $right = $this->resolve_value( $cond['value'] ?? '', $context );
      $op    = (string) ( $cond['op'] ?? 'equals' );
      $result = $this->evaluate_condition( $left, $right, $op );
      if ( $match === 'any' && $result ) { return true; }
      if ( $match === 'all' && !$result ) { return false; }
      $evaluated = true;
    }
    // 'any' with no match → false. 'all' → true only if at least one valid
    // row was evaluated (all-malformed rows must not pass the condition).
    return $match === 'all' && $evaluated;
  }

  private function evaluate_condition( $left, $right, $op ) {
    switch ( $op ) {
      case 'equals':
        return (string) $left === (string) $right;
      case 'not_equals':
        return (string) $left !== (string) $right;
      case 'contains':
        return is_scalar( $left ) && strpos( (string) $left, (string) $right ) !== false;
      case 'not_contains':
        return !( is_scalar( $left ) && strpos( (string) $left, (string) $right ) !== false );
      case 'starts_with':
        return strpos( (string) $left, (string) $right ) === 0;
      case 'ends_with':
        $r = (string) $right;
        if ( $r === '' ) { return true; }
        return substr( (string) $left, -strlen( $r ) ) === $r;
      case 'is_empty':
        return $left === null || $left === '' || $left === false
          || ( is_array( $left ) && count( $left ) === 0 );
      case 'is_not_empty':
        return !( $left === null || $left === '' || $left === false
          || ( is_array( $left ) && count( $left ) === 0 ) );
      case 'greater_than':
        return is_numeric( $left ) && is_numeric( $right ) && ( $left + 0 ) > ( $right + 0 );
      case 'less_than':
        return is_numeric( $left ) && is_numeric( $right ) && ( $left + 0 ) < ( $right + 0 );
      case 'is_true':
        if ( is_string( $left ) ) {
          return in_array( strtolower( $left ), [ 'true', '1', 'yes', 'on' ], true );
        }
        return (bool) $left;
      case 'is_false':
        if ( is_string( $left ) ) {
          return !in_array( strtolower( $left ), [ 'true', '1', 'yes', 'on' ], true );
        }
        return !$left;
    }
    return false;
  }

  private function coerce( $value, $type ) {
    if ( $value === null ) { return null; }
    switch ( $type ) {
      case 'number':
        return is_numeric( $value ) ? $value + 0 : 0;
      case 'boolean':
        if ( is_string( $value ) ) {
          return in_array( strtolower( $value ), [ 'true', '1', 'yes', 'on' ], true );
        }
        return (bool) $value;
      case 'post_id':
      case 'user_id':
      case 'attachment_id':
        return (int) $value;
      case 'json':
        if ( is_string( $value ) ) {
          $decoded = json_decode( $value, true );
          return $decoded !== null ? $decoded : $value;
        }
        return $value;
      default:
        return $value;
    }
  }
}
