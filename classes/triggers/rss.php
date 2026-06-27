<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * RSS trigger: fires the flow once for every NEW item that appears in a feed.
 *
 * Each active RSS flow gets its own WP-Cron event (like the schedule trigger).
 * On every tick we fetch the feed with WordPress core's fetch_feed() (SimplePie)
 * and compare item GUIDs against the ones we've already seen for this flow
 * (stored in an option, capped). The first tick after activation only primes
 * the seen-list — it never fires a storm of runs for the feed's backlog.
 *
 * trigger_config shape:
 *   feed_url:   the RSS/Atom feed URL (required)
 *   recurrence: 'hourly' | 'twicedaily' | 'daily'   (default 'hourly')
 *
 * Payload exposed to the flow (one run per new item):
 *   title, link, content, date, author, guid
 */
class Meow_MWFLOW_Triggers_RSS {
  const HOOK = 'mwflow_rss_tick';
  const SEEN_OPTION_PREFIX = 'mwflow_rss_seen_';
  const MAX_SEEN = 200;   // guids remembered per flow
  const MAX_RUNS_PER_TICK = 3; // safety cap so a bursty feed can't flood the site

  private $core;
  private $tracked = [];

  public function __construct( $core ) {
    $this->core = $core;
    add_action( self::HOOK, [ $this, 'fire' ], 10, 1 );
  }

  public function register_for_flows( $flows ) {
    foreach ( $flows as $flow ) {
      if ( $flow['trigger_type'] !== 'rss' ) { continue; }
      if ( empty( $flow['trigger_config']['feed_url'] ) ) { continue; }
      $this->tracked[] = $flow['id'];
      $recurrence = $flow['trigger_config']['recurrence'] ?? 'hourly';
      if ( !in_array( $recurrence, [ 'hourly', 'twicedaily', 'daily' ], true ) ) {
        $recurrence = 'hourly';
      }
      $args = [ (int) $flow['id'] ];
      if ( !wp_next_scheduled( self::HOOK, $args ) ) {
        // First check shortly after activation so the seen-list primes quickly.
        wp_schedule_event( time() + 60, $recurrence, self::HOOK, $args );
      }
    }
  }

  public function unregister() {
    foreach ( $this->tracked as $flow_id ) {
      $this->unschedule_flow( (int) $flow_id );
    }
    $this->tracked = [];
  }

  public function fire( $flow_id ) {
    $flow = $this->core->get_flow( (int) $flow_id );
    if ( !$flow || !$flow['is_active'] || $flow['trigger_type'] !== 'rss' ) {
      $this->unschedule_flow( (int) $flow_id );
      return;
    }
    $feed_url = $flow['trigger_config']['feed_url'] ?? '';
    if ( !$feed_url ) { return; }

    if ( !function_exists( 'fetch_feed' ) ) {
      include_once ABSPATH . WPINC . '/feed.php';
    }
    $feed = fetch_feed( esc_url_raw( $feed_url ) );
    if ( is_wp_error( $feed ) ) {
      $this->core->logging->warn( sprintf( 'RSS trigger: could not fetch %s — %s', $feed_url, $feed->get_error_message() ) );
      return;
    }

    $items = $feed->get_items( 0, 10 );
    if ( empty( $items ) ) { return; }

    $option = self::SEEN_OPTION_PREFIX . (int) $flow_id;
    $seen   = get_option( $option, null );

    // First run after activation: prime the seen-list silently so the flow
    // only ever fires for items published AFTER it was switched on.
    if ( !is_array( $seen ) ) {
      $seen = [];
      foreach ( $items as $item ) { $seen[] = (string) $item->get_id(); }
      update_option( $option, array_slice( $seen, 0, self::MAX_SEEN ), false );
      return;
    }

    $fresh = [];
    foreach ( $items as $item ) {
      if ( !in_array( (string) $item->get_id(), $seen, true ) ) {
        $fresh[] = $item;
      }
    }
    if ( empty( $fresh ) ) { return; }

    // Oldest new item first, capped per tick.
    $fresh = array_reverse( $fresh );
    $fresh = array_slice( $fresh, 0, self::MAX_RUNS_PER_TICK );

    foreach ( $fresh as $item ) {
      $payload = $this->item_payload( $item );
      $seen[]  = (string) $item->get_id();

      // "Capture next call" support — store the payload as the trigger sample
      // instead of running, exactly like webhook/hook triggers.
      if ( $this->core->capture_trigger_sample( (int) $flow_id, $payload ) ) {
        continue;
      }
      $this->core->runner->run( (int) $flow_id, $payload );
    }

    update_option( $option, array_slice( array_values( $seen ), -self::MAX_SEEN ), false );
  }

  /**
   * Flatten a SimplePie item into the friendly payload the flow sees.
   * Content is plain-text and capped so AI steps get clean input.
   */
  private function item_payload( $item ) {
    $author  = $item->get_author();
    $content = (string) ( $item->get_content() ?: $item->get_description() );
    $content = trim( wp_strip_all_tags( $content ) );
    if ( strlen( $content ) > 4000 ) {
      $content = substr( $content, 0, 4000 ) . '…';
    }
    return [
      'title'   => (string) $item->get_title(),
      'link'    => (string) $item->get_permalink(),
      'content' => $content,
      'date'    => (string) $item->get_date( 'Y-m-d H:i:s' ),
      'author'  => $author ? (string) $author->get_name() : '',
      'guid'    => (string) $item->get_id(),
    ];
  }

  private function unschedule_flow( $flow_id ) {
    $args = [ $flow_id ];
    $timestamp = wp_next_scheduled( self::HOOK, $args );
    if ( $timestamp ) {
      wp_unschedule_event( $timestamp, self::HOOK, $args );
    }
  }
}
