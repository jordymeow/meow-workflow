<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * Schedule trigger: each active scheduled flow gets its own WP-Cron event
 * (one event per flow ID so multiple flows can run on different schedules).
 *
 * trigger_config shape:
 *   recurrence: 'hourly' | 'twicedaily' | 'daily' | 'weekly'
 *   time:       'HH:MM'    (used to compute the first run when relevant)
 *   day:        'monday' … 'sunday'  (weekly only — anchors the first run so
 *               "every Monday at 8" really means Monday, not 7 days from
 *               whenever the flow was activated)
 */
class Meow_MWFLOW_Triggers_Schedule {
  const HOOK = 'mwflow_schedule_tick';

  private $core;
  private $tracked = [];

  public function __construct( $core ) {
    $this->core = $core;
    add_action( self::HOOK, [ $this, 'fire' ], 10, 1 );
  }

  public function register_for_flows( $flows ) {
    foreach ( $flows as $flow ) {
      if ( $flow['trigger_type'] !== 'schedule' ) { continue; }
      $this->tracked[] = $flow['id'];
      $recurrence = $flow['trigger_config']['recurrence'] ?? 'daily';
      $time = $flow['trigger_config']['time'] ?? '09:00';
      $day = $recurrence === 'weekly' ? ( $flow['trigger_config']['day'] ?? null ) : null;
      $args = [ (int) $flow['id'] ];
      if ( !wp_next_scheduled( self::HOOK, $args ) ) {
        wp_schedule_event( $this->compute_first_run( $time, $day ), $recurrence, self::HOOK, $args );
      }
    }
  }

  public function unregister() {
    foreach ( $this->tracked as $flow_id ) {
      $timestamp = wp_next_scheduled( self::HOOK, [ $flow_id ] );
      if ( $timestamp ) {
        wp_unschedule_event( $timestamp, self::HOOK, [ $flow_id ] );
      }
    }
    $this->tracked = [];
  }

  public function fire( $flow_id ) {
    $flow = $this->core->get_flow( (int) $flow_id );
    if ( !$flow || !$flow['is_active'] || $flow['trigger_type'] !== 'schedule' ) {
      // Stale event for a flow that's no longer scheduled — clean it up.
      $this->unschedule_flow( (int) $flow_id );
      return;
    }
    $this->core->runner->run( (int) $flow_id, [ 'fired_at' => current_time( 'mysql' ) ] );
  }

  private function unschedule_flow( $flow_id ) {
    $args = [ $flow_id ];
    $timestamp = wp_next_scheduled( self::HOOK, $args );
    if ( $timestamp ) {
      wp_unschedule_event( $timestamp, self::HOOK, $args );
    }
  }

  /**
   * Compute the next occurrence of HH:MM in the site's timezone — optionally
   * on a specific weekday (weekly recurrence).
   */
  private function compute_first_run( $hhmm, $day = null ) {
    if ( !preg_match( '/^(\d{1,2}):(\d{2})$/', $hhmm, $m ) ) {
      return time() + 60;
    }
    $tz = wp_timezone();
    $now = new DateTime( 'now', $tz );
    $target = clone $now;
    $target->setTime( (int) $m[1], (int) $m[2], 0 );

    $days = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
    if ( $day !== null && in_array( strtolower( (string) $day ), $days, true ) ) {
      $day = strtolower( (string) $day );
      if ( strtolower( $target->format( 'l' ) ) !== $day || $target <= $now ) {
        // PHP's "next monday" resets the time to 00:00 — re-apply HH:MM after.
        $target->modify( 'next ' . $day );
        $target->setTime( (int) $m[1], (int) $m[2], 0 );
      }
    }
    else if ( $target <= $now ) {
      $target->modify( '+1 day' );
    }
    return $target->getTimestamp();
  }
}
