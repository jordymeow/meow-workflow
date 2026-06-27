<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * WordPress-hook trigger: binds the flow to any `do_action` in WordPress
 * (e.g. `publish_post`, `comment_post`, `user_register`). The hook's args are
 * captured as the trigger payload (keyed `arg0`, `arg1`, …).
 *
 * trigger_config shape:
 *   hook:      string (required)
 *   priority:  int (default 10)
 *   args:      int (default 1)
 */
class Meow_MWFLOW_Triggers_Hook {
  private $core;
  private $bindings = [];

  public function __construct( $core ) {
    $this->core = $core;
  }

  public function register_for_flows( $flows ) {
    foreach ( $flows as $flow ) {
      if ( $flow['trigger_type'] !== 'hook' ) { continue; }
      $hook = $flow['trigger_config']['hook'] ?? '';
      if ( !$hook ) { continue; }
      $priority = (int) ( $flow['trigger_config']['priority'] ?? 10 );
      $args     = max( 1, (int) ( $flow['trigger_config']['args'] ?? 1 ) );
      $flow_id  = (int) $flow['id'];

      // If a known WordPress event maps to this hook, we'll enrich the payload
      // with friendly named fields (post_id, comment_id, …) via its mapper.
      $known = $this->core->registry->find_trigger_by_hook( $hook );
      $mapper = $known['mapper'] ?? null;

      $callback = function ( ...$incoming ) use ( $flow_id, $mapper ) {
        $payload = [];
        foreach ( $incoming as $index => $arg ) {
          // Keep raw args available but only when scalar — objects/arrays
          // bloat the payload and aren't useful as {{ trigger.argN }}.
          $payload[ 'arg' . $index ] = is_scalar( $arg ) ? $arg : null;
        }
        // Common helpful alias for the first scalar arg.
        if ( isset( $payload['arg0'] ) ) { $payload['value'] = $payload['arg0']; }

        // Named-event enrichment: e.g. publish_post → { post_id: 123 }.
        if ( is_callable( $mapper ) ) {
          $mapped = call_user_func( $mapper, $incoming );
          if ( is_array( $mapped ) ) {
            $payload = array_merge( $payload, $mapped );
          }
        }

        // Sample-capture mode: store the payload and skip this one execution
        // so the user can scaffold the flow against real data.
        if ( $this->core->capture_trigger_sample( $flow_id, $payload ) ) {
          return $incoming[0] ?? null;
        }
        $this->core->runner->run( $flow_id, $payload );
        // Don't break the original hook chain when used as a filter.
        return $incoming[0] ?? null;
      };
      add_action( $hook, $callback, $priority, $args );
      $this->bindings[] = [ 'hook' => $hook, 'priority' => $priority, 'callback' => $callback ];
    }
  }

  public function unregister() {
    foreach ( $this->bindings as $binding ) {
      remove_action( $binding['hook'], $binding['callback'], $binding['priority'] );
    }
    $this->bindings = [];
  }
}
