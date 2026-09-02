<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * "Core" integration — universal nodes that aren't tied to any external plugin:
 *
 *   Triggers : manual, schedule, webhook, wp_hook
 *   Actions  : condition (branch), router (multi-branch), delay, set_var,
 *              send_email, http_request, log
 *
 * Triggers are mostly metadata for the UI palette — the actual binding work
 * happens in classes/triggers/*.php based on the flow's trigger_type.
 */
class Meow_MWFLOW_Integrations_Core {

  public function __construct( $core ) {
    add_filter( 'mwflow_register_integration', [ $this, 'register' ] );
  }

  public function register( $integrations ) {
    $integrations[] = Meow_MWFLOW_SDK::integration( [
      'id'          => 'core',
      'name'        => __( 'Core', 'meow-workflow' ),
      'description' => __( 'Triggers and universal logic nodes built into Meow Workflow.', 'meow-workflow' ),
      'color'       => '#475569',
      'version'     => MWFLOW_VERSION,
      'triggers'    => [
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'manual',
          'name'    => __( 'Manual / Test once', 'meow-workflow' ),
          'hook'    => '__mwflow_manual__',
          'outputs' => [ Meow_MWFLOW_SDK::output( 'fired_at', __( 'Fired at', 'meow-workflow' ), 'string' ) ],
        ] ),
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'schedule',
          'name'    => __( 'On a schedule', 'meow-workflow' ),
          'hook'    => Meow_MWFLOW_Triggers_Schedule::HOOK,
          'outputs' => [ Meow_MWFLOW_SDK::output( 'fired_at', __( 'Fired at', 'meow-workflow' ), 'string' ) ],
        ] ),
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'webhook',
          'name'    => __( 'Webhook (HTTP)', 'meow-workflow' ),
          'hook'    => '__mwflow_webhook__',
          'outputs' => [ Meow_MWFLOW_SDK::output( 'body', __( 'Whole request body (each field is also available as trigger.field)', 'meow-workflow' ), 'json' ) ],
        ] ),
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'wp_hook',
          'name'    => __( 'WordPress hook', 'meow-workflow' ),
          'hook'    => '__dynamic__',
          'outputs' => [
            Meow_MWFLOW_SDK::output( 'arg0', __( 'First arg', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'arg1', __( 'Second arg', 'meow-workflow' ), 'string' ),
          ],
        ] ),
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'rss',
          'name'    => __( 'New RSS item', 'meow-workflow' ),
          'hook'    => '__mwflow_rss__',
          'outputs' => [
            Meow_MWFLOW_SDK::output( 'title', __( 'Item title', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'link', __( 'Item URL', 'meow-workflow' ), 'url' ),
            Meow_MWFLOW_SDK::output( 'content', __( 'Item content (plain text)', 'meow-workflow' ), 'longtext' ),
            Meow_MWFLOW_SDK::output( 'date', __( 'Published date', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'author', __( 'Author', 'meow-workflow' ), 'string' ),
          ],
        ] ),
      ],
      'actions' => [
        Meow_MWFLOW_SDK::action( [
          'id'          => 'condition',
          'name'        => __( 'Condition', 'meow-workflow' ),
          'description' => __( 'Branch the workflow based on simple conditions. The "true" branch runs when the conditions match; the "false" branch runs otherwise.', 'meow-workflow' ),
          'icon'        => 'git-branch',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'match', __( 'Match', 'meow-workflow' ), 'select', [
              'default'  => 'all',
              'options'  => [
                [ 'value' => 'all', 'label' => __( 'All conditions must match (AND)', 'meow-workflow' ) ],
                [ 'value' => 'any', 'label' => __( 'Any condition can match (OR)', 'meow-workflow' ) ],
              ],
              'required' => true,
            ] ),
            Meow_MWFLOW_SDK::input( 'conditions', __( 'Conditions', 'meow-workflow' ), 'conditions', [
              'required'    => true,
              'description' => __( 'Use the left side to pick the data to check, e.g. {{ trigger.value }} or {{ get_post1.title }}.', 'meow-workflow' ),
            ] ),
          ],
          'outputs'     => [
            Meow_MWFLOW_SDK::output( 'matched', __( 'Matched branch', 'meow-workflow' ), 'string' ),
          ],
          'callback'    => [ __CLASS__, 'callback_condition' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'router',
          'name'        => __( 'Branch by value', 'meow-workflow' ),
          'description' => __( 'Sends the workflow down a path matching the value below. Each outgoing arrow can be labelled — e.g. publish, draft, trash.', 'meow-workflow' ),
          'icon'        => 'split',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'value', __( 'Value to match', 'meow-workflow' ), 'expression', [
              'required'    => true,
              'placeholder' => '{{ trigger.status }}',
              'description' => __( 'The value to route on. Reference earlier data with {{ ... }}, e.g. {{ trigger.status }}.', 'meow-workflow' ),
            ] ),
            Meow_MWFLOW_SDK::input( 'branches', __( 'Branches', 'meow-workflow' ), 'branches', [
              'description' => __( 'One row per path: a short name (shown on the step) and the value that picks it. Matching is case-insensitive. Each branch becomes its own connector at the bottom of the step.', 'meow-workflow' ),
            ] ),
          ],
          'outputs'     => [
            Meow_MWFLOW_SDK::output( 'branch', __( 'Matched branch', 'meow-workflow' ), 'string' ),
          ],
          'callback'    => [ __CLASS__, 'callback_router' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'foreach',
          'name'        => __( 'For Each', 'meow-workflow' ),
          'description' => __( 'Runs the steps after it once for every item in a list — e.g. each post from Get Recent Posts. Wire the DONE connector to what should happen after the last item.', 'meow-workflow' ),
          'icon'        => 'repeat',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'items', __( 'Items', 'meow-workflow' ), 'expression', [
              'required'    => true,
              'placeholder' => '{{ recent.posts }}',
              'description' => __( 'A list from an earlier step. Inside the loop, reference the current entry by this step\'s id, e.g. {{ foreach1.item.title }}.', 'meow-workflow' ),
            ] ),
            Meow_MWFLOW_SDK::input( 'limit', __( 'Max items', 'meow-workflow' ), 'number', [
              'default' => 25, 'min' => 1, 'max' => 100, 'advanced' => true,
              'description' => __( 'Safety cap — extra items are skipped.', 'meow-workflow' ),
            ] ),
          ],
          'outputs'     => [
            Meow_MWFLOW_SDK::output( 'item', __( 'Current item', 'meow-workflow' ), 'json' ),
            Meow_MWFLOW_SDK::output( 'index', __( 'Index (starts at 0)', 'meow-workflow' ), 'number' ),
            Meow_MWFLOW_SDK::output( 'count', __( 'Total items', 'meow-workflow' ), 'number' ),
          ],
          'callback'    => [ __CLASS__, 'callback_foreach' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'random',
          'name'        => __( 'Random', 'meow-workflow' ),
          'description' => __( 'Generates a random value — one item picked from a list, or a number in a range. Pair with Branch By Value or Condition to make a flow act differently each run.', 'meow-workflow' ),
          'icon'        => 'dices',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'choices', __( 'Choices', 'meow-workflow' ), 'string', [
              'placeholder' => 'heads, tails',
              'description' => __( 'Optional. Comma-separated list — one item is picked at random. Leave empty to get a number between Min and Max instead.', 'meow-workflow' ),
            ] ),
            Meow_MWFLOW_SDK::input( 'min', __( 'Min', 'meow-workflow' ), 'number', [ 'default' => 1 ] ),
            Meow_MWFLOW_SDK::input( 'max', __( 'Max', 'meow-workflow' ), 'number', [ 'default' => 100 ] ),
          ],
          'outputs'     => [
            Meow_MWFLOW_SDK::output( 'value', __( 'Random value', 'meow-workflow' ), 'string' ),
          ],
          'callback'    => [ __CLASS__, 'callback_random' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'delay',
          'name'        => __( 'Delay', 'meow-workflow' ),
          'description' => __( 'Pauses execution for N seconds (capped at 30s in the sync runner).', 'meow-workflow' ),
          'icon'        => 'timer',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'seconds', __( 'Seconds', 'meow-workflow' ), 'number', [ 'default' => 1, 'min' => 0, 'max' => 30 ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'waited', __( 'Waited', 'meow-workflow' ), 'number' ) ],
          'callback'    => [ __CLASS__, 'callback_delay' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'set_var',
          'name'        => __( 'Save a value', 'meow-workflow' ),
          'description' => __( 'Saves a value so later steps can reference it by this step\'s id, e.g. {{ set_var1.value }} (the id is shown on the step).', 'meow-workflow' ),
          'icon'        => 'variable',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'value', __( 'Value', 'meow-workflow' ), 'expression', [
              'required'    => true,
              'placeholder' => '{{ get_post1.title }}',
              'description' => __( 'Type a fixed value, or reference earlier data with {{ ... }}.', 'meow-workflow' ),
            ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'value', __( 'Value', 'meow-workflow' ), 'string' ) ],
          'callback'    => [ __CLASS__, 'callback_set_var' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'send_email',
          'name'        => __( 'Send email', 'meow-workflow' ),
          'description' => __( 'Send an email via wp_mail. Plain text by default, or HTML for links and images.', 'meow-workflow' ),
          'icon'        => 'mail',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'to', __( 'To', 'meow-workflow' ), 'email', [
              'required'    => true,
              'default'     => '{{ admin_email }}',
              'description' => __( '{{ admin_email }} sends to the site admin — or type any address.', 'meow-workflow' ),
            ] ),
            Meow_MWFLOW_SDK::input( 'subject', __( 'Subject', 'meow-workflow' ), 'string', [
              'required'    => true,
              'placeholder' => __( 'Workflow alert', 'meow-workflow' ),
            ] ),
            Meow_MWFLOW_SDK::input( 'body', __( 'Body', 'meow-workflow' ), 'longtext', [
              'required'    => true,
              'placeholder' => __( "Hi,\n\nYour workflow just ran.\n", 'meow-workflow' ),
            ] ),
            Meow_MWFLOW_SDK::input( 'format', __( 'Format', 'meow-workflow' ), 'select', [
              'default'     => 'text',
              'options'     => [
                [ 'value' => 'text', 'label' => __( 'Plain text', 'meow-workflow' ) ],
                [ 'value' => 'html', 'label' => __( 'HTML', 'meow-workflow' ) ],
              ],
              'description' => __( 'HTML lets you write links and images, e.g. <a href="{{ get_post1.url }}">{{ get_post1.title }}</a>. Line breaks still become paragraphs.', 'meow-workflow' ),
            ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'sent', __( 'Email sent', 'meow-workflow' ), 'boolean' ) ],
          'callback'    => [ __CLASS__, 'callback_send_email' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'http_request',
          'name'        => __( 'HTTP request', 'meow-workflow' ),
          'description' => __( 'Call an external URL (e.g. another app\'s API) and capture the response status and body.', 'meow-workflow' ),
          'icon'        => 'globe',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'url', __( 'URL', 'meow-workflow' ), 'url', [ 'required' => true, 'placeholder' => 'https://example.com/api' ] ),
            Meow_MWFLOW_SDK::input( 'method', __( 'Method', 'meow-workflow' ), 'select', [
              'options' => [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ],
              'default' => 'GET',
            ] ),
            Meow_MWFLOW_SDK::input( 'body', __( 'Body', 'meow-workflow' ), 'longtext', [
              'placeholder' => '{ "message": "Hello" }',
              'description' => __( 'Sent as the request body for POST/PUT/PATCH. Usually JSON.', 'meow-workflow' ),
            ] ),
            Meow_MWFLOW_SDK::input( 'headers', __( 'Headers (optional)', 'meow-workflow' ), 'json', [
              'placeholder' => '{ "Authorization": "Bearer ..." }',
              'description' => __( 'Optional request headers as a JSON object.', 'meow-workflow' ),
            ] ),
          ],
          'outputs'     => [
            Meow_MWFLOW_SDK::output( 'status', __( 'Status code', 'meow-workflow' ), 'number' ),
            Meow_MWFLOW_SDK::output( 'body', __( 'Response body', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'json', __( 'Response JSON', 'meow-workflow' ), 'json' ),
          ],
          'callback'    => [ __CLASS__, 'callback_http_request' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'log',
          'name'        => __( 'Log message', 'meow-workflow' ),
          'description' => __( 'Writes a line to the diagnostics log (Settings → For developers).', 'meow-workflow' ),
          'icon'        => 'file-text',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'message', __( 'Message', 'meow-workflow' ), 'longtext', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'level', __( 'Level', 'meow-workflow' ), 'select', [
              'options' => [ 'info', 'warn', 'error' ],
              'default' => 'info',
            ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'logged', __( 'Logged', 'meow-workflow' ), 'boolean' ) ],
          'callback'    => [ __CLASS__, 'callback_log' ],
        ] ),
      ],
    ] );
    return $integrations;
  }

  public static function callback_condition( $inputs ) {
    // The runner short-circuits condition nodes (see runner.php::execute_node)
    // because branching needs to drive edge selection. This stub is kept only
    // for schema completeness — it should never actually be invoked.
    return [ 'matched' => 'false' ];
  }

  public static function callback_router( $inputs ) {
    return [ 'branch' => (string) ( $inputs['value'] ?? '' ), 'value' => $inputs['value'] ?? null ];
  }

  public static function callback_foreach( $inputs ) {
    // The runner short-circuits foreach nodes (see runner.php::execute_next)
    // because looping needs to drive the queue. Kept for schema completeness.
    return [ 'item' => null, 'index' => null, 'count' => 0 ];
  }

  public static function callback_random( $inputs ) {
    $choices = trim( (string) ( $inputs['choices'] ?? '' ) );
    if ( $choices !== '' ) {
      $list = array_values( array_filter( array_map( 'trim', explode( ',', $choices ) ), 'strlen' ) );
      if ( !empty( $list ) ) {
        return [ 'value' => $list[ wp_rand( 0, count( $list ) - 1 ) ] ];
      }
    }
    $min = (int) ( $inputs['min'] ?? 1 );
    $max = (int) ( $inputs['max'] ?? 100 );
    if ( $min > $max ) { list( $min, $max ) = [ $max, $min ]; }
    return [ 'value' => wp_rand( $min, $max ) ];
  }

  public static function callback_delay( $inputs ) {
    $seconds = max( 0, min( 30, (int) ( $inputs['seconds'] ?? 0 ) ) );
    if ( $seconds > 0 ) { sleep( $seconds ); }
    return [ 'waited' => $seconds ];
  }

  public static function callback_set_var( $inputs ) {
    return [ 'value' => $inputs['value'] ?? null ];
  }

  public static function callback_send_email( $inputs ) {
    $body    = (string) ( $inputs['body'] ?? '' );
    $headers = [];
    if ( ( $inputs['format'] ?? 'text' ) === 'html' ) {
      $headers[] = 'Content-Type: text/html; charset=UTF-8';
      // The body is typed in a textarea, so keep its line breaks meaningful
      // even when the user mixes in <a> or <img> tags.
      $body = wpautop( $body );
    }
    $sent = wp_mail( $inputs['to'] ?? '', $inputs['subject'] ?? '', $body, $headers );
    return [ 'sent' => (bool) $sent ];
  }

  public static function callback_http_request( $inputs ) {
    $args = [
      'method'  => $inputs['method'] ?? 'GET',
      'timeout' => 30,
    ];
    if ( !empty( $inputs['headers'] ) ) {
      $args['headers'] = is_array( $inputs['headers'] ) ? $inputs['headers'] : (array) json_decode( $inputs['headers'], true );
    }
    if ( !empty( $inputs['body'] ) ) {
      $args['body'] = is_string( $inputs['body'] ) ? $inputs['body'] : wp_json_encode( $inputs['body'] );
    }
    // SSRF guard — matters because a webhook-triggered flow can drive this URL
    // from an unauthenticated payload while running as the flow's admin author.
    // Two layers: wp_http_validate_url() enforces the http(s) scheme, blocks
    // loopback + RFC-1918 private ranges, and honours the site's own
    // WP_ACCESSIBLE_HOSTS / http_request_host_is_external overrides. But it does
    // NOT block link-local 169.254.x — the classic cloud-metadata SSRF target —
    // so we resolve the host and reject private/reserved IPs explicitly, while
    // exempting the site's own host (intranet installs may call themselves).
    $url = (string) ( $inputs['url'] ?? '' );
    if ( !wp_http_validate_url( $url ) ) {
      throw new Exception( sprintf(
        'Refused to request "%s" — only public http(s) URLs are allowed (loopback and private addresses are blocked).',
        $url
      ) );
    }
    $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    $home = strtolower( (string) wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) );
    if ( $host !== '' && $host !== $home ) {
      $ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
      if ( filter_var( $ip, FILTER_VALIDATE_IP )
        && !filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
        throw new Exception( sprintf(
          'Refused to request "%s" — it resolves to a private or reserved network address.',
          $url
        ) );
      }
    }
    $response = wp_remote_request( $url, $args );
    if ( is_wp_error( $response ) ) {
      throw new Exception( 'HTTP error: ' . $response->get_error_message() );
    }
    $body = wp_remote_retrieve_body( $response );
    $json = json_decode( $body, true );
    return [
      'status' => (int) wp_remote_retrieve_response_code( $response ),
      'body'   => $body,
      'json'   => $json !== null ? $json : null,
    ];
  }

  public static function callback_log( $inputs ) {
    global $mwflow_core;
    $level = $inputs['level'] ?? 'info';
    $message = (string) ( $inputs['message'] ?? '' );
    if ( $mwflow_core && $mwflow_core->logging ) {
      if ( $level === 'error' ) { $mwflow_core->logging->error( $message ); }
      else if ( $level === 'warn' ) { $mwflow_core->logging->warn( $message ); }
      else { $mwflow_core->logging->info( $message ); }
    }
    return [ 'logged' => true ];
  }
}
