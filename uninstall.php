<?php

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mwflow_flows" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mwflow_runs" );

delete_option( 'mwflow_options' );
delete_option( 'mwflow_settings' );
delete_option( 'mwflow_version' );

// Per-flow RSS seen-lists (mwflow_rss_seen_<flow_id>).
$wpdb->query( $wpdb->prepare(
  "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
  $wpdb->esc_like( 'mwflow_rss_seen_' ) . '%'
) );

// Pending cron events. Plugin classes are not loaded here, so hook names are literal.
wp_unschedule_hook( 'mwflow_run_step' );
wp_unschedule_hook( 'mwflow_schedule_tick' );
wp_unschedule_hook( 'mwflow_rss_tick' );
wp_unschedule_hook( 'mwflow_watchdog' );
