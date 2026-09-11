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
 * Each step pops one node off the queue, executes it, appends any unblocked
 * children, and persists the new state, so a run can always pick up where it
 * left off. Three paths drive that loop: in-request (sync, "Test once"), one
 * step per REST call (stepwise, so the canvas can animate), and wp_cron
 * (async: webhook, wp_hook, schedule and RSS triggers).
 *
 * An async pass drains as many nodes as fit in PASS_BUDGET_SECONDS before
 * handing the rest to the next pass, and a watchdog picks up runs whose chain
 * of cron events was broken. Both matter more than they look: see the comments
 * on PASS_BUDGET_SECONDS and watchdog().
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

  /** Recurring wp_cron hook that rescues runs whose step chain broke. */
  const WATCHDOG_HOOK = 'mwflow_watchdog';

  /** Custom cron interval the watchdog runs on. */
  const WATCHDOG_SCHEDULE = 'mwflow_five_minutes';

  /**
   * A run counts as stalled when its heartbeat (`updated_at`) is older than this
   * AND no step event is pending. Deliberately far longer than any node should
   * take: the heartbeat is refreshed before every node, so only a SINGLE node
   * running longer than this can produce a false positive, and a false positive
   * means resuming a run that is in fact still executing, which would run its
   * remaining steps twice. Half an hour is beyond what any host lets a PHP
   * process live, so the cost is only a slower rescue when a chain really did
   * break. Not a knob to lower casually.
   */
  const STALL_SECONDS = 1800;

  /** How many times the watchdog will resurrect one run before failing it. */
  const MAX_RESUMES = 3;

  /** Safety cap on how many steps a single sync run will execute. */
  const SYNC_STEP_LIMIT = 500;

  /**
   * Wall-clock budget for one async (wp_cron) pass, in seconds. WP-Cron only
   * executes events that were already due when the pass started, so an event
   * scheduled *during* a pass waits for the next one. Running a single node per
   * pass therefore means one node per cron tick, five minutes per node on a
   * typical server cron. We instead drain as much of the queue as fits in the
   * budget, and only reschedule when we run out of time. Clamped against PHP's
   * own max_execution_time so we hand control back before the process is killed.
   */
  const PASS_BUDGET_SECONDS = 20;

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

  // Wait step: live runs are parked for up to 30 days; Test once only sits
  // through short waits (see execute_next).
  const MAX_WAIT_SECONDS = 30 * DAY_IN_SECONDS;
  const TEST_WAIT_MAX_SECONDS = 5;

  private $core;

  /** Run currently being advanced, so execute_next() can checkpoint mid-run. */
  private $active_run_id = null;

  /** Label of the node we are inside right now, or null. Read on shutdown. */
  private $active_node_label = null;

  private $shutdown_registered = false;

  public function __construct( $core ) {
    $this->core = $core;
    add_action( self::STEP_HOOK, [ $this, 'run_step' ], 10, 1 );
    add_action( self::WATCHDOG_HOOK, [ $this, 'watchdog' ] );
    add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );
  }

  public function add_cron_schedule( $schedules ) {
    if ( !isset( $schedules[ self::WATCHDOG_SCHEDULE ] ) ) {
      $schedules[ self::WATCHDOG_SCHEDULE ] = [
        'interval' => 5 * MINUTE_IN_SECONDS,
        'display'  => __( 'Every five minutes (Meow Workflow)', 'meow-workflow' ),
      ];
    }
    return $schedules;
  }

  /** Called on init: makes sure the watchdog event exists. */
  public function ensure_watchdog_scheduled() {
    if ( !wp_next_scheduled( self::WATCHDOG_HOOK ) ) {
      wp_schedule_event( time() + MINUTE_IN_SECONDS, self::WATCHDOG_SCHEDULE, self::WATCHDOG_HOOK );
    }
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

    $state = $this->init_state( $definition, $entry['id'], $this->trigger_context( $flow['trigger_type'] ?? '', $payload ) );
    // The watchdog treats these very differently: an async run is driven by
    // wp_cron and can be safely resumed, while a stepwise/sync run is driven by
    // the browser and must never resurrect itself once the user has walked away.
    $state['mode'] = $stepwise ? 'stepwise' : ( $sync ? 'sync' : 'async' );
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

    // Async: hand the run to wp_cron. The pass that picks it up drains as much
    // of the queue as it can, and only reschedules if it runs out of time.
    wp_schedule_single_event( time(), self::STEP_HOOK, [ $run_id ] );
    return [
      'run_id'          => $run_id,
      'status'          => 'running',
      'current_step_id' => $state['queue'][0] ?? null,
      'steps'           => [],
    ];
  }

  /**
   * WP-cron action handler. Advances an in-progress run as far as it can within
   * one pass, then reschedules itself if work remains.
   */
  public function run_step( $run_id ) {
    $run_id = (int) $run_id;
    $loaded = $this->core->load_run_state( $run_id );
    if ( !$loaded || !in_array( $loaded['status'], [ 'running', 'waiting' ], true ) ) { return; }
    if ( $loaded['status'] === 'waiting' ) {
      // The Wait step's resume event: back to running, the queue already
      // holds the steps that follow the wait.
      $this->core->resume_run( $run_id );
    }

    // Async continuations arrive via WP-Cron with no logged-in user — adopt
    // the flow author's identity here too (see run() for the rationale).
    if ( !get_current_user_id() && !empty( $loaded['flow_id'] ) ) {
      $owner_flow = $this->core->get_flow( (int) $loaded['flow_id'] );
      if ( $owner_flow && !empty( $owner_flow['created_by'] ) ) {
        wp_set_current_user( (int) $owner_flow['created_by'] );
      }
    }

    $state = $loaded['state'];
    $this->active_run_id = $run_id;
    $deadline = microtime( true ) + $this->pass_budget();

    try {
      while ( true ) {
        if ( empty( $state['queue'] ) && !$this->maybe_continue_loops( $state ) ) { break; }
        $this->execute_next( $state );
        // A Wait step parked the run: store it as waiting and come back at
        // the resume time. The watchdog leaves waiting runs alone.
        if ( !empty( $state['pause_until'] ) ) {
          $resume_at = (int) $state['pause_until'];
          unset( $state['pause_until'] );
          $this->core->pause_run( $run_id, $state, $resume_at );
          wp_schedule_single_event( $resume_at, self::STEP_HOOK, [ $run_id ] );
          return;
        }
        // A drained queue may just mean the current For Each iteration ended.
        if ( empty( $state['queue'] ) ) {
          $this->maybe_continue_loops( $state );
        }
        $this->core->save_run_state( $run_id, $state );
        if ( empty( $state['queue'] ) ) { break; }
        // Out of budget: hand the rest to the next cron pass. The queue is
        // already persisted, so nothing is lost.
        if ( microtime( true ) >= $deadline ) {
          wp_schedule_single_event( time(), self::STEP_HOOK, [ $run_id ] );
          return;
        }
      }
      $this->core->finalize_run( $run_id, 'done', $state );
      $this->core->logging->info( 'Flow completed.', [
        'flow_id' => $loaded['flow_id'], 'run_id' => $run_id,
      ] );
    }
    catch ( \Throwable $e ) {
      $error = $e->getMessage();
      $this->core->finalize_run( $run_id, 'failed', $state, $error );
      $this->core->logging->error( 'Flow failed.', [
        'flow_id' => $loaded['flow_id'], 'run_id' => $run_id, 'error' => $error,
      ] );
    }
    finally {
      $this->active_run_id = null;
      $this->disarm_fatal_guard();
    }
  }

  /**
   * Seconds one cron pass may spend executing nodes. Kept comfortably under
   * PHP's max_execution_time so we reschedule instead of being killed mid-node.
   */
  private function pass_budget() {
    $budget = (int) apply_filters( 'mwflow_pass_budget_seconds', self::PASS_BUDGET_SECONDS );
    $limit = (int) ini_get( 'max_execution_time' );
    if ( $limit > 0 ) {
      $budget = min( $budget, max( 5, (int) floor( $limit * 0.6 ) ) );
    }
    return max( 5, $budget );
  }

  /* ------------------------------------------------------------------ */
  /* Watchdog: rescues runs whose step chain broke                      */
  /* ------------------------------------------------------------------ */

  /**
   * A run advances by scheduling the next step as a wp_cron event. If that
   * event is ever lost (the process was killed mid-node, the cron option was
   * clobbered by an overlapping pass, an object cache served a stale copy),
   * nothing reschedules it and the run sits in `running` forever. This is the
   * single most confusing failure a user can hit, because every recorded step
   * looks fine. So: any run whose heartbeat went quiet and that has no pending
   * step event gets pushed again, a few times, then honestly marked failed.
   */
  public function watchdog() {
    // A waiting run whose resume event got lost would sleep forever. Anything
    // past its resume time by a few minutes with no event pending is pushed.
    foreach ( $this->core->get_overdue_waits( 300 ) as $row ) {
      $run_id = (int) $row->id;
      if ( wp_next_scheduled( self::STEP_HOOK, [ $run_id ] ) ) { continue; }
      wp_schedule_single_event( time(), self::STEP_HOOK, [ $run_id ] );
      $this->core->logging->info( 'Resuming a wait whose event was lost.', [
        'flow_id' => (int) $row->flow_id, 'run_id' => $run_id,
      ] );
    }

    // Never let a filter shorten this below the constant: see STALL_SECONDS.
    $stall = max( self::STALL_SECONDS,
      (int) apply_filters( 'mwflow_stall_seconds', self::STALL_SECONDS ) );
    foreach ( $this->core->get_stalled_runs( $stall ) as $row ) {
      $run_id = (int) $row->id;
      // Still queued: the pass just hasn't come around yet. Leave it alone.
      if ( wp_next_scheduled( self::STEP_HOOK, [ $run_id ] ) ) { continue; }

      $loaded = $this->core->load_run_state( $run_id );
      if ( !$loaded || $loaded['status'] !== 'running' ) { continue; }

      $state = $loaded['state'];

      // Stepwise ("Test once") and sync runs are driven by a browser request.
      // If one went quiet, the user closed the tab or the request died. Do NOT
      // silently finish it in the background, just close the books on it.
      // Runs started before this version carry no mode marker; they land here
      // too, on purpose: resuming a day-old run that posts and emails is worse
      // than admitting it never finished.
      if ( ( $state['mode'] ?? '' ) !== 'async' ) {
        $this->core->finalize_run( $run_id, 'failed', $state,
          __( 'The run was interrupted before it could finish.', 'meow-workflow' ) );
        continue;
      }

      $resumes = (int) ( $state['resumes'] ?? 0 );
      if ( $resumes >= self::MAX_RESUMES ) {
        $error = sprintf(
          /* translators: %d: number of resume attempts. */
          __( 'The run was interrupted and could not be resumed after %d attempts.', 'meow-workflow' ),
          self::MAX_RESUMES
        );
        $this->core->finalize_run( $run_id, 'failed', $state, $error );
        $this->core->logging->error( 'Flow abandoned after repeated interruptions.', [
          'flow_id' => $loaded['flow_id'], 'run_id' => $run_id,
        ] );
        continue;
      }

      $state['resumes'] = $resumes + 1;
      $this->core->save_run_state( $run_id, $state );
      wp_schedule_single_event( time(), self::STEP_HOOK, [ $run_id ] );
      $this->core->logging->info( 'Resuming an interrupted run.', [
        'flow_id' => $loaded['flow_id'], 'run_id' => $run_id, 'attempt' => $state['resumes'],
      ] );
    }
  }

  /* ------------------------------------------------------------------ */
  /* Fatal guard: a node that kills PHP must not leave a zombie run     */
  /* ------------------------------------------------------------------ */

  /**
   * Armed right before a node executes, disarmed as soon as it returns. If PHP
   * dies in between (max_execution_time, memory_limit, a fatal in third-party
   * code), the shutdown handler is our only chance to record what happened.
   */
  private function arm_fatal_guard( $label ) {
    $this->active_node_label = $label;
    if ( !$this->shutdown_registered ) {
      $this->shutdown_registered = true;
      register_shutdown_function( [ $this, 'on_shutdown' ] );
    }
  }

  private function disarm_fatal_guard() {
    $this->active_node_label = null;
  }

  public function on_shutdown() {
    if ( $this->active_node_label === null || !$this->active_run_id ) { return; }
    $error = error_get_last();
    $fatal = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
    if ( !$error || !in_array( $error['type'], $fatal, true ) ) { return; }
    try {
      // No state passed: finalize_run reloads the checkpoint we wrote just
      // before the node started, so the timeline still shows where it died.
      $this->core->finalize_run( $this->active_run_id, 'failed', null, sprintf(
        /* translators: 1: step name, 2: PHP error message. */
        __( 'The run was interrupted while executing "%1$s": %2$s', 'meow-workflow' ),
        $this->active_node_label, $error['message']
      ) );
    }
    catch ( \Throwable $e ) {
      // Shutting down after a fatal, nothing useful left to do.
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

    $this->active_run_id = $run_id;
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
    finally {
      $this->active_run_id = null;
      $this->disarm_fatal_guard();
    }
  }

  /* ------------------------------------------------------------------ */
  /* Sync path — loop in-request                                        */
  /* ------------------------------------------------------------------ */

  private function advance_inline( $run_id, $flow_id, $state ) {
    $i = 0;
    $this->active_run_id = $run_id;
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
    finally {
      $this->active_run_id = null;
      $this->disarm_fatal_guard();
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

  /**
   * Webhook payload fields live at the top level ({{ trigger.url }}), like
   * every other trigger. The editor hint in 0.1.x told people to write
   * {{ trigger.body.url }}, so the whole payload is also exposed as `body`
   * (unless the caller sent a field with that name) to keep those flows working.
   */
  private function trigger_context( $trigger_type, $payload ) {
    if ( $trigger_type === 'webhook' && is_array( $payload ) && !empty( $payload ) && !array_key_exists( 'body', $payload ) ) {
      $payload['body'] = $payload;
    }
    return $payload;
  }

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
    // A step with two parents is queued once per finished parent. Without this
    // check it ran twice (two emails, two posts) whenever branches joined back.
    if ( $this->already_handled( $node_id, $state ) ) { return; }

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

    // Persist the "running" marker BEFORE the node executes. If PHP dies inside
    // it, the run row still names the node it died on. Otherwise the state is
    // only written afterwards and a crashed node looks like it never started.
    // Also refreshes the heartbeat, so the watchdog gives long nodes their time.
    if ( $this->active_run_id ) {
      $this->core->save_run_state( $this->active_run_id, $state );
    }
    $this->arm_fatal_guard( $node['data']['label'] ?? $node_id );

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
        else if ( $this->is_delay( $node ) ) {
          $seconds = $this->delay_seconds( $params, $state['context'] );
          if ( ( $state['mode'] ?? 'sync' ) !== 'async' ) {
            // Test once: nobody wants to sit through a two-day wait. Short
            // waits are honoured so demos still feel real, longer ones skipped.
            if ( $seconds > 0 && $seconds <= self::TEST_WAIT_MAX_SECONDS ) {
              sleep( $seconds );
              $output = [ 'waited' => $seconds, 'resume_at' => null ];
            }
            else {
              $output = [ 'waited' => 0, 'resume_at' => null, 'skipped' => $seconds > 0 ];
            }
          }
          else if ( $seconds > 0 ) {
            // Live run: park it. run_step() sees pause_until, stores the run as
            // "waiting" and schedules the next pass for that time.
            $resume_at = time() + $seconds;
            $state['pause_until'] = $resume_at;
            $output = [ 'waited' => $seconds, 'resume_at' => wp_date( 'Y-m-d H:i:s', $resume_at ) ];
          }
          else {
            $output = [ 'waited' => 0, 'resume_at' => null ];
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
        $this->disarm_fatal_guard();
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
    $this->disarm_fatal_guard();
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

  private function is_delay( $node ) {
    return ( $node['data']['integration'] ?? '' ) === 'core'
      && ( $node['data']['action'] ?? '' ) === 'delay';
  }

  /** Total seconds a Wait step asks for. Honours the pre-0.1.5 `seconds` param. */
  private function delay_seconds( $params, $context ) {
    $amount = $this->resolve_value( $params['amount'] ?? ( $params['seconds'] ?? 0 ), $context );
    $units = [ 'seconds' => 1, 'minutes' => 60, 'hours' => 3600, 'days' => 86400 ];
    $multiplier = $units[ (string) ( $params['unit'] ?? 'seconds' ) ] ?? 1;
    $seconds = (int) round( (float) $amount * $multiplier );
    return max( 0, min( self::MAX_WAIT_SECONDS, $seconds ) );
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
        // Name the reference that came back empty: "title is missing" alone sends
        // people guessing at paths when the step ID or the path is simply wrong.
        $hint = ( is_string( $raw ) && strpos( $raw, '{{' ) !== false )
          ? sprintf( ' The reference %s did not return a value. Check the step ID and the path: run the flow once, then click that step to see its actual output.', trim( $raw ) )
          : '';
        throw new Exception( sprintf( 'Required input "%s" is missing.%s', $id, $hint ) );
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
    // A JSON template ({ "content": "{{ scrape.markdown }}" }) needs the
    // references inside its quoted strings escaped, or the first quote or
    // newline in the data breaks the document. Nobody guesses "| json".
    if ( $this->is_json_template( $value, $pattern ) ) {
      return $this->interpolate_json_template( $value, $context );
    }
    // Embedded expression(s) → interpolate into the surrounding string.
    return preg_replace_callback( $pattern, function ( $m ) use ( $context ) {
      return $this->stringify( $this->resolve_expression( trim( $m[1] ), $context ) );
    }, $value );
  }

  private function stringify( $resolved ) {
    if ( is_array( $resolved ) || is_object( $resolved ) ) {
      return wp_json_encode( $resolved );
    }
    if ( is_bool( $resolved ) ) {
      return $resolved ? 'true' : 'false';
    }
    return (string) $resolved;
  }

  /**
   * True when the string is a JSON object/array once every reference is
   * replaced by a literal. "0" is valid both quoted and bare, so a reference
   * used as a value or inside a string both pass; one used as a key does not.
   */
  private function is_json_template( $value, $pattern ) {
    $first = substr( ltrim( $value ), 0, 1 );
    if ( $first !== '{' && $first !== '[' ) {
      return false;
    }
    json_decode( preg_replace( $pattern, '0', $value ) );
    return json_last_error() === JSON_ERROR_NONE;
  }

  /**
   * Walk the template once, tracking whether we are inside a JSON string.
   * A reference inside quotes is inserted as escaped string content; outside
   * quotes it behaves exactly like plain interpolation, so bodies that already
   * use "| json" keep working unchanged.
   */
  private function interpolate_json_template( $value, $context ) {
    $out = '';
    $in_string = false;
    $len = strlen( $value );
    for ( $i = 0; $i < $len; $i++ ) {
      $ch = $value[ $i ];
      if ( $ch === '{' && preg_match( '/\G\{\{\s*(.+?)\s*\}\}/', $value, $m, 0, $i ) ) {
        $text = $this->stringify( $this->resolve_expression( trim( $m[1] ), $context ) );
        $out .= $in_string ? $this->escape_json_string( $text ) : $text;
        $i += strlen( $m[0] ) - 1;
        continue;
      }
      if ( $in_string && $ch === '\\' ) {
        $out .= $ch . ( $value[ $i + 1 ] ?? '' );
        $i++;
        continue;
      }
      if ( $ch === '"' ) {
        $in_string = !$in_string;
      }
      $out .= $ch;
    }
    return $out;
  }

  private function escape_json_string( $text ) {
    $encoded = wp_json_encode( (string) $text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    return is_string( $encoded ) ? substr( $encoded, 1, -1 ) : (string) $text;
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
    // Accept JS-style indices too: a[0].b and a["b"] both become a.0.b.
    // People coming from any API doc write brackets first.
    $path = preg_replace( '/\[\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]]*))\s*\]/', '.$1$2$3', $path );
    $parts = explode( '.', $path );
    $value = $context;
    foreach ( $parts as $key ) {
      $key = trim( $key );
      if ( $key === '' ) { continue; }
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
      case 'string':
      case 'longtext':
      case 'url':
      case 'email':
        // A whole-string reference to a JSON output ({{ gemini.json }}) hands
        // an array to a text field. Without this it lands as "Array".
        return ( is_array( $value ) || is_object( $value ) ) ? wp_json_encode( $value ) : $value;
      default:
        return $value;
    }
  }
}
