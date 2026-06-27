<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * SEO Engine integration. Calls `global $mwseo` (instance of Meow_MWSEO_API).
 * Reference: /Users/meow/plugins/seo-engine-pro/classes/api.php
 */
class Meow_MWFLOW_Integrations_Seo_Engine {

  public function __construct( $core ) {
    add_filter( 'mwflow_register_integration', [ $this, 'register' ] );
  }

  public function register( $integrations ) {
    if ( !$this->is_available() ) { return $integrations; }

    $integrations[] = Meow_MWFLOW_SDK::integration( [
      'id'          => 'seo-engine',
      'name'        => __( 'SEO Engine', 'meow-workflow' ),
      'description' => __( 'Scoring, titles, descriptions and insights via SEO Engine.', 'meow-workflow' ),
      'color'       => '#2c5cc5',
      'version'     => defined( 'MWSEO_VERSION' ) ? MWSEO_VERSION : '',
      'logo_url'    => defined( 'MWSEO_URL' ) ? MWSEO_URL . 'images/icon.png' : '',
      'actions'     => [
        Meow_MWFLOW_SDK::action( [
          'id'          => 'scan',
          'name'        => __( 'Run SEO scan', 'meow-workflow' ),
          'description' => __( 'Run an SEO analysis on a post and return the full result for later steps.', 'meow-workflow' ),
          'icon'        => 'search',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id', [ 'required' => true ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'result', __( 'Scan result', 'meow-workflow' ), 'json' ) ],
          'callback'    => [ __CLASS__, 'callback_scan' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'get_score',
          'name'        => __( 'Get SEO score', 'meow-workflow' ),
          'description' => __( 'Get a post\'s SEO score (0–100). Pair with Condition to act only on low-scoring posts.', 'meow-workflow' ),
          'icon'        => 'gauge',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id', [ 'required' => true ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'score', __( 'Score', 'meow-workflow' ), 'number' ) ],
          'callback'    => [ __CLASS__, 'callback_get_score' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'set_title',
          'name'        => __( 'Set SEO title', 'meow-workflow' ),
          'description' => __( 'Set the SEO title (meta title) shown in search results for a post.', 'meow-workflow' ),
          'icon'        => 'type',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'title', __( 'Title', 'meow-workflow' ), 'string', [ 'required' => true ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'updated', __( 'Updated', 'meow-workflow' ), 'boolean' ) ],
          'callback'    => [ __CLASS__, 'callback_set_title' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'set_excerpt',
          'name'        => __( 'Set SEO description', 'meow-workflow' ),
          'description' => __( 'Set the SEO description (meta description) shown under the title in search results.', 'meow-workflow' ),
          'icon'        => 'align-left',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'excerpt', __( 'Description', 'meow-workflow' ), 'longtext', [ 'required' => true ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'updated', __( 'Updated', 'meow-workflow' ), 'boolean' ) ],
          'callback'    => [ __CLASS__, 'callback_set_excerpt' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'get_insights',
          'name'        => __( 'Get SEO insights', 'meow-workflow' ),
          'description' => __( 'Get SEO Engine\'s detailed insights and suggestions for a post.', 'meow-workflow' ),
          'icon'        => 'line-chart',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id', [ 'required' => true ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'data', __( 'Insights', 'meow-workflow' ), 'json' ) ],
          'callback'    => [ __CLASS__, 'callback_get_insights' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'get_site_summary',
          'name'        => __( 'Get site-wide SEO summary', 'meow-workflow' ),
          'description' => __( 'Site-level SEO snapshot: scored-posts breakdown, monthly visitors, top posts. Useful for a daily/weekly email digest.', 'meow-workflow' ),
          'icon'        => 'bar-chart',
          'inputs'      => [],
          'outputs'     => [
            Meow_MWFLOW_SDK::output( 'good_count', __( 'Posts with good SEO', 'meow-workflow' ), 'number' ),
            Meow_MWFLOW_SDK::output( 'fair_count', __( 'Posts with fair SEO', 'meow-workflow' ), 'number' ),
            Meow_MWFLOW_SDK::output( 'poor_count', __( 'Posts with poor SEO', 'meow-workflow' ), 'number' ),
            Meow_MWFLOW_SDK::output( 'monthly_visitors', __( 'Monthly visitors', 'meow-workflow' ), 'number' ),
            Meow_MWFLOW_SDK::output( 'monthly_pageviews', __( 'Monthly pageviews', 'meow-workflow' ), 'number' ),
            Meow_MWFLOW_SDK::output( 'top_posts', __( 'Top posts (monthly)', 'meow-workflow' ), 'json' ),
            Meow_MWFLOW_SDK::output( 'summary_text', __( 'One-line summary', 'meow-workflow' ), 'string' ),
          ],
          'callback'    => [ __CLASS__, 'callback_get_site_summary' ],
        ] ),
      ],
    ] );
    return $integrations;
  }

  private function is_available() {
    return class_exists( 'Meow_MWSEO_API' ) && isset( $GLOBALS['mwseo'] );
  }

  private static function api() {
    if ( empty( $GLOBALS['mwseo'] ) ) {
      throw new Exception( 'SEO Engine is not active.' );
    }
    return $GLOBALS['mwseo'];
  }

  public static function callback_scan( $inputs ) {
    return [ 'result' => self::api()->do_seo_scan( (int) $inputs['post_id'] ) ];
  }

  public static function callback_get_score( $inputs ) {
    return [ 'score' => self::api()->get_seo_score( (int) $inputs['post_id'] ) ];
  }

  public static function callback_set_title( $inputs ) {
    self::api()->set_seo_title( (int) $inputs['post_id'], (string) $inputs['title'] );
    return [ 'updated' => true ];
  }

  public static function callback_set_excerpt( $inputs ) {
    self::api()->set_seo_excerpt( (int) $inputs['post_id'], (string) $inputs['excerpt'] );
    return [ 'updated' => true ];
  }

  public static function callback_get_insights( $inputs ) {
    return [ 'data' => self::api()->get_insights( (int) $inputs['post_id'] ) ];
  }

  /**
   * Aggregate site-level SEO + analytics into one tidy payload. Designed for
   * a daily/weekly email template where the AI summarises the numbers.
   */
  public static function callback_get_site_summary( $inputs ) {
    $api = self::api();

    // Scored posts → breakdown by quality bucket.
    $good = 0; $fair = 0; $poor = 0;
    try {
      $scored = $api->get_scored_posts();
      if ( is_array( $scored ) ) {
        foreach ( $scored as $row ) {
          $score = (int) ( is_array( $row ) ? ( $row['score'] ?? 0 ) : ( $row->score ?? 0 ) );
          if ( $score >= 80 )      { $good++; }
          else if ( $score >= 50 ) { $fair++; }
          else                     { $poor++; }
        }
      }
    } catch ( Throwable $e ) { /* analytics may not be set up — degrade silently */ }

    // Google Analytics monthly summary (visitors / pageviews).
    $visitors = 0; $pageviews = 0;
    try {
      $summary = $api->get_google_analytics_monthly_summary();
      if ( !empty( $summary['data'] ) ) {
        $d = $summary['data'];
        $visitors  = (int) ( $d['users'] ?? $d['visitors'] ?? $d['activeUsers'] ?? 0 );
        $pageviews = (int) ( $d['pageviews'] ?? $d['screenPageViews'] ?? $d['views'] ?? 0 );
      }
    } catch ( Throwable $e ) { /* GA not configured */ }

    // Top posts list (trimmed to a small payload to keep AI prompts cheap).
    $top_posts = [];
    try {
      $top = $api->get_google_analytics_monthly_top_posts();
      if ( !empty( $top['data'] ) && is_array( $top['data'] ) ) {
        foreach ( array_slice( $top['data'], 0, 5 ) as $row ) {
          $top_posts[] = [
            'title' => is_array( $row ) ? ( $row['title'] ?? $row['post_title'] ?? '' ) : '',
            'views' => is_array( $row ) ? (int) ( $row['pageviews'] ?? $row['views'] ?? $row['screenPageViews'] ?? 0 ) : 0,
            'url'   => is_array( $row ) ? ( $row['url'] ?? $row['permalink'] ?? '' ) : '',
          ];
        }
      }
    } catch ( Throwable $e ) { /* GA not configured */ }

    $summary_text = sprintf(
      __( 'SEO health — %1$d good, %2$d fair, %3$d poor. Monthly visitors: %4$d. Pageviews: %5$d.', 'meow-workflow' ),
      $good, $fair, $poor, $visitors, $pageviews
    );

    return [
      'good_count'        => $good,
      'fair_count'        => $fair,
      'poor_count'        => $poor,
      'monthly_visitors'  => $visitors,
      'monthly_pageviews' => $pageviews,
      'top_posts'         => $top_posts,
      'summary_text'      => $summary_text,
    ];
  }
}
