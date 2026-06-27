<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * AI-driven workflow authoring.
 *
 * The single feature that justifies the plugin's existence: a user describes
 * what they want in plain English, AI Engine writes the flow definition.
 *
 * Strategy:
 *  1. Build a compact schema of all registered triggers and actions (id, name,
 *     description, required inputs) — the AI must only reference real things.
 *  2. Send it as the system instructions to `simpleJsonQuery`.
 *  3. Ask the AI to produce a *linear* chain of steps (simple intermediate
 *     representation — way easier for the model to get right than the verbose
 *     node+edge graph the runtime uses).
 *  4. Expand the linear chain into the runtime's node+edge graph here, placing
 *     nodes left-to-right with sensible spacing.
 *  5. Validate every reference; on validation failure send the errors back to
 *     the model for one repair pass.
 *
 * Modes:
 *  - 'new'     — create a fresh flow from a prompt
 *  - 'extend'  — given an existing flow + prompt, add steps to it
 *  - 'explain' — given an existing flow, return a plain-English summary
 *  - 'suggest' — given an existing flow + cursor step, propose the next step
 *  - 'fix'     — given a flow + recent error, propose a repair patch
 */
class Meow_MWFLOW_AI_Authoring {

  const MODE_NEW     = 'new';
  const MODE_EXTEND  = 'extend';
  const MODE_EXPLAIN = 'explain';
  const MODE_SUGGEST = 'suggest';
  const MODE_FIX     = 'fix';

  // Error categories surfaced to the UI so it can render a friendly summary
  // and decide what next step to suggest.
  const ERR_AI_UNAVAILABLE   = 'ai_unavailable';
  const ERR_AI_FAILED        = 'ai_failed';        // rate limit, network, provider error
  const ERR_INVALID_OUTPUT   = 'invalid_output';   // AI didn't return valid JSON
  const ERR_VALIDATION       = 'validation';       // AI returned schema-invalid steps
  const ERR_EMPTY_PROMPT     = 'empty_prompt';
  const ERR_NO_FLOW          = 'no_flow';

  /** Node layout in the generated graph. The canvas flows top-to-bottom (nodes
   *  use top/bottom handles), so steps are stacked vertically — matching the
   *  editor's own "add step below" placement. NODE_DY mirrors that 150px gap. */
  const NODE_X0 = 80;
  const NODE_Y0 = 80;
  const NODE_DY = 150;

  private $core;

  public function __construct( $core ) {
    $this->core = $core;
  }

  /**
   * Main entry point.
   *
   * @param string     $prompt  Natural-language description.
   * @param string     $mode    One of the MODE_* constants.
   * @param array|null $context Optional context: existing flow definition, sample payload, last error.
   *
   * @return array { definition?, name?, trigger_type?, trigger_config?, message?, errors? }
   * @throws Exception On AI Engine unavailable or unrecoverable validation failure.
   */
  public function author( $prompt, $mode = self::MODE_NEW, $context = null ) {
    if ( !$this->is_ai_available() ) {
      throw new Meow_MWFLOW_AI_Authoring_Exception(
        __( 'AI authoring needs the AI Engine plugin. Install or activate AI Engine first.', 'meow-workflow' ),
        self::ERR_AI_UNAVAILABLE
      );
    }
    $prompt = trim( (string) $prompt );
    if ( $prompt === '' && $mode !== self::MODE_EXPLAIN ) {
      throw new Meow_MWFLOW_AI_Authoring_Exception(
        __( 'Tell me what you want — a sentence or two is enough.', 'meow-workflow' ),
        self::ERR_EMPTY_PROMPT
      );
    }

    switch ( $mode ) {
      case self::MODE_EXPLAIN:
        return $this->run_explain( $context );
      case self::MODE_SUGGEST:
        return $this->run_suggest( $prompt, $context );
      case self::MODE_FIX:
        return $this->run_fix( $prompt, $context );
      case self::MODE_EXTEND:
      case self::MODE_NEW:
      default:
        return $this->run_build( $prompt, $context, $mode === self::MODE_EXTEND );
    }
  }

  /**
   * Authoring path: turn a prompt into a complete flow definition.
   */
  private function run_build( $prompt, $context, $is_extend ) {
    $schema = $this->build_schema();
    $instructions = $this->build_system_prompt( $schema, $is_extend );

    $user_message = $prompt;
    if ( $is_extend && !empty( $context['definition'] ) ) {
      $user_message .= "\n\n--- Current workflow (extend this) ---\n";
      $user_message .= wp_json_encode( $this->linearise_existing( $context['definition'] ), JSON_PRETTY_PRINT );
    }
    if ( !empty( $context['sample'] ) ) {
      $user_message .= "\n\n--- Sample trigger payload ---\n";
      $user_message .= wp_json_encode( $context['sample'], JSON_PRETTY_PRINT );
    }

    $linear = $this->call_ai_json( $user_message, $instructions );
    $errors = $this->validate_linear( $linear, $schema );

    if ( !empty( $errors ) ) {
      // One repair pass: hand the errors back to the model.
      $repair_message  = $user_message;
      $repair_message .= "\n\n--- Previous output had validation errors ---\n";
      $repair_message .= "Previous output:\n" . wp_json_encode( $linear, JSON_PRETTY_PRINT ) . "\n\n";
      $repair_message .= "Errors:\n" . implode( "\n", array_map( function ( $e ) { return '- ' . $e; }, $errors ) );
      $repair_message .= "\n\nPlease produce a corrected output that fixes every listed error.";
      $linear = $this->call_ai_json( $repair_message, $instructions );
      $errors = $this->validate_linear( $linear, $schema );
    }

    if ( !empty( $errors ) ) {
      return [
        'errors'     => $errors,
        'partial'    => $linear,
        'error_kind' => self::ERR_VALIDATION,
        'message'    => $this->summarise_validation_errors( $errors ),
      ];
    }

    $definition = $this->expand_linear_to_graph( $linear );
    return [
      'name'           => $this->humanise_name( $linear['name'] ?? '' ),
      'trigger_type'   => (string) ( $linear['trigger']['type'] ?? 'manual' ),
      'trigger_config' => is_array( $linear['trigger']['config'] ?? null ) ? $linear['trigger']['config'] : [],
      'definition'     => $definition,
      'rationale'      => (string) ( $linear['rationale'] ?? '' ),
    ];
  }

  /**
   * Safety net for AI-generated names that came back snake_case or with
   * stray underscores despite the prompt asking for Title Case. We want
   * "Webhook body summary to admin", not "webhook_body_summary_to_admin".
   */
  private function humanise_name( $name ) {
    $name = trim( (string) $name );
    if ( $name === '' ) {
      return __( 'Untitled workflow', 'meow-workflow' );
    }
    // Underscores → spaces (snake_case → words). Also collapse runs.
    if ( strpos( $name, '_' ) !== false ) {
      $name = str_replace( '_', ' ', $name );
      $name = preg_replace( '/\s+/', ' ', $name );
    }
    // If the result is all lowercase / titlecase it: "webhook body summary" → "Webhook body summary".
    if ( $name === strtolower( $name ) ) {
      $name = ucfirst( $name );
    }
    // Strip wrapping quotes if any.
    $name = trim( $name, '"\' ' );
    return $name !== '' ? $name : __( 'Untitled workflow', 'meow-workflow' );
  }

  private function run_explain( $context ) {
    if ( empty( $context['definition'] ) ) {
      throw new Exception( __( 'No flow to explain.', 'meow-workflow' ) );
    }
    $linear = $this->linearise_existing( $context['definition'] );
    $instructions = "You are a friendly assistant. Given a workflow definition, write a single short paragraph (max 4 sentences) explaining in plain English what this workflow does, when it runs, and what it produces. No JSON, no code blocks — just the paragraph.";
    $message = wp_json_encode( $linear, JSON_PRETTY_PRINT );
    $text = $this->call_ai_text( $message, $instructions );
    return [ 'message' => $text ];
  }

  private function run_suggest( $prompt, $context ) {
    if ( empty( $context['definition'] ) ) {
      throw new Exception( __( 'No flow to extend.', 'meow-workflow' ) );
    }
    $schema = $this->build_schema();
    $linear_existing = $this->linearise_existing( $context['definition'] );

    $instructions = "Given an existing workflow and an optional cursor step id, suggest ONE next step that would meaningfully extend the flow. ONLY use integrations and actions from the provided list. Output JSON: { \"step\": { id, integration, action, params }, \"why\": \"one short sentence\" }.\n\n" . $schema['ai_actions_text'];

    $user_message = "Existing workflow:\n" . wp_json_encode( $linear_existing, JSON_PRETTY_PRINT );
    if ( !empty( $context['after_step_id'] ) ) {
      $user_message .= "\n\nInsert the suggestion after step: " . $context['after_step_id'];
    }
    if ( $prompt !== '' ) {
      $user_message .= "\n\nUser hint: " . $prompt;
    }

    $reply = $this->call_ai_json( $user_message, $instructions );
    return [ 'suggestion' => $reply ];
  }

  private function run_fix( $prompt, $context ) {
    if ( empty( $context['definition'] ) ) {
      throw new Exception( __( 'No flow to fix.', 'meow-workflow' ) );
    }
    $schema = $this->build_schema();
    $linear_existing = $this->linearise_existing( $context['definition'] );

    $instructions = "You are repairing a broken workflow. Output a corrected linear chain in the same format:\n\n" . $schema['ai_format_text'] . "\n\n" . $schema['ai_actions_text'];

    $user_message  = "Current workflow:\n" . wp_json_encode( $linear_existing, JSON_PRETTY_PRINT );
    if ( !empty( $context['error'] ) ) {
      $user_message .= "\n\nError observed:\n" . $context['error'];
    }
    if ( $prompt !== '' ) {
      $user_message .= "\n\nUser hint: " . $prompt;
    }

    $linear = $this->call_ai_json( $user_message, $instructions );
    $errors = $this->validate_linear( $linear, $schema );
    if ( !empty( $errors ) ) {
      return [ 'errors' => $errors, 'partial' => $linear ];
    }
    $definition = $this->expand_linear_to_graph( $linear );
    return [
      'name'           => (string) ( $linear['name'] ?? __( 'Untitled workflow', 'meow-workflow' ) ),
      'trigger_type'   => (string) ( $linear['trigger']['type'] ?? 'manual' ),
      'trigger_config' => is_array( $linear['trigger']['config'] ?? null ) ? $linear['trigger']['config'] : [],
      'definition'     => $definition,
    ];
  }

  /* ------------------------------------------------------------------ */
  /* Schema + prompt construction                                       */
  /* ------------------------------------------------------------------ */

  /**
   * Pull a slim version of the registry for the model: just what the model
   * needs to know to compose valid references.
   */
  private function build_schema() {
    $integrations = $this->core->registry->get_integrations_for_ui();
    $triggers = [];
    $actions = [];

    foreach ( $integrations as $i ) {
      foreach ( ( $i['actions'] ?? [] ) as $a ) {
        $actions[] = [
          'integration' => $i['id'],
          'id'          => $a['id'],
          'name'        => $a['name'],
          'description' => $a['description'] ?? '',
          'inputs'      => array_map( function ( $f ) {
            return [
              'id'       => $f['id'],
              'type'     => $f['type'],
              'required' => !empty( $f['required'] ),
            ];
          }, $a['inputs'] ?? [] ),
          'outputs'     => array_map( function ( $f ) {
            return [ 'id' => $f['id'], 'name' => $f['name'] ];
          }, $a['outputs'] ?? [] ),
        ];
      }
      foreach ( ( $i['triggers'] ?? [] ) as $t ) {
        $triggers[] = [
          'integration' => $i['id'],
          'id'          => $t['id'],
          'name'        => $t['name'],
          'description' => $t['description'] ?? '',
        ];
      }
    }

    $ai_actions_text = "Available actions (these are the ONLY actions you may use; copy the integration and action ids EXACTLY as written):\n";
    foreach ( $actions as $a ) {
      $req = array_filter( $a['inputs'], function ( $i ) { return $i['required']; } );
      $req_names = implode( ', ', array_map( function ( $i ) { return $i['id']; }, $req ) );
      $out_names = implode( ', ', array_map( function ( $o ) { return $o['id']; }, $a['outputs'] ) );
      $ai_actions_text .= sprintf(
        "- integration=\"%s\"  action=\"%s\"  → %s%s%s\n",
        $a['integration'],
        $a['id'],
        $a['name'] . ( $a['description'] ? '. ' . $a['description'] : '' ),
        $req_names ? "  [required inputs: $req_names]" : '',
        $out_names ? "  [outputs: $out_names]" : ''
      );
    }
    // Real WordPress events come from the registry (wordpress + woocommerce +
    // any third-party integration), so new event triggers automatically become
    // available to the authoring AI. Core's pseudo-hooks (__mwflow_*__) are
    // internal and skipped.
    $events_text = '';
    foreach ( $integrations as $i ) {
      foreach ( ( $i['triggers'] ?? [] ) as $t ) {
        $hook = (string) ( $t['hook'] ?? '' );
        if ( $hook === '' || strpos( $hook, '__' ) === 0 ) { continue; }
        $outs = implode( ', ', array_map( function ( $o ) {
          return '{{ trigger.' . $o['id'] . ' }}';
        }, $t['outputs'] ?? [] ) );
        $events_text .= sprintf( "- hook \"%s\" (args %d) → %s%s\n",
          $hook, (int) ( $t['args'] ?? 1 ), $t['name'], $outs ? " → $outs" : '' );
      }
    }

    $ai_actions_text .= "\nEXAMPLES of correct step references (NEVER combine integration into the action field):\n";
    $ai_actions_text .= "  GOOD: { \"integration\": \"ai-engine\", \"action\": \"text\" }\n";
    $ai_actions_text .= "  GOOD: { \"integration\": \"core\", \"action\": \"send_email\" }\n";
    $ai_actions_text .= "  BAD:  { \"integration\": \"ai-engine\", \"action\": \"ai-engine/text\" }  ← wrong, the slash must NOT appear in 'action'\n";
    $ai_actions_text .= "  BAD:  { \"integration\": \"core\", \"action\": \"core/send_email\" }      ← wrong, the slash must NOT appear in 'action'\n";

    $ai_format_text = 'Output JSON in this format:
{
  "name": "Human-readable workflow name — like an email subject. Title Case. No identifiers, no snake_case, no underscores.",
  "rationale": "one sentence on why this design",
  "trigger": {
    "type": "manual" | "schedule" | "webhook" | "hook" | "rss",
    "config": { ...type-specific (see below)... },
    "label": "human-readable description of when it fires"
  },
  "steps": [
    {
      "id": "snake_case_identifier_unique_within_flow",
      "integration": "core" | "wordpress" | "ai-engine" | ...,
      "action": "action_id_from_list_above",
      "params": { "input_id": "value or {{ reference }}" }
    },
    ...
  ]
}

Workflow NAME rules (very important — this is the title the user sees):
- Title Case, sentence-style. 3–8 words. No underscores. No quotation marks.
- Describe what the workflow does, not its internals.
- Examples of GOOD names:
    "AI summary of incoming webhooks"
    "Weekly brief of latest posts"
    "Send email when a comment is posted"
    "Daily AI haiku to admin"
- Examples of BAD names (DO NOT do this):
    "webhook_body_summary_to_admin"   ← snake_case, looks like a function
    "weekly_brief_latest_post"         ← snake_case
    "ai_summary_v2"                    ← contains underscores
    "flow_001"                          ← generic identifier

Trigger config shapes:
- manual:   {} (no config)
- schedule: { "recurrence": "hourly|twicedaily|daily|weekly", "time": "HH:MM", "day": "monday|…|sunday" (weekly only) }
- webhook:  {} (token is generated automatically; request body keys become {{ trigger.<key> }})
- hook:     { "hook": "<wordpress_hook>", "args": <int> } — prefer a known WordPress event below.
- rss:      { "feed_url": "<feed url, or \"\" if the user didn\'t give one>", "recurrence": "hourly|twicedaily|daily" }
            Fires once per NEW feed item → {{ trigger.title }}, {{ trigger.link }}, {{ trigger.content }}, {{ trigger.date }}, {{ trigger.author }}.
            Use this whenever the user wants to react to news/articles/feeds (e.g. "summarize new articles from …", "RSS to draft post").

Known WordPress events (use these for "hook" triggers — each exposes friendly fields):
' . $events_text . '

Always-available values (reference directly, no step needed):
  {{ site_name }}, {{ site_url }}, {{ admin_email }}, {{ now }}, {{ today }}, {{ today_human }}, {{ year }}

Formatting filters (append with a pipe): {{ post.title | upper }}, {{ x | lower }}, {{ x | truncate:120 }},
  {{ x | default:"N/A" }}, {{ now | date:"F j, Y" }}, {{ n | number_format }}, {{ html | strip_tags }}

Step / data rules:
- ONLY use integrations and actions from the provided list.
- Step ids ARE short snake_case slugs (e.g. "fetch_post", "summary") — users see them in {{ references }}, keep them readable.
- Reference earlier step outputs as {{ step_id.output_field }}.
- For hook triggers, use the friendly field from the event table (e.g. {{ trigger.post_id }}), NOT {{ trigger.arg0 }}.
- Use {{ admin_email }} for "email me / the admin"; never invent an address.
- Keep flows short and focused — 2 to 5 steps is ideal. Do not invent steps.
- Required inputs MUST be filled. Optional inputs MAY be omitted.
- For ai-engine actions, prefer leaving model/env empty (uses configured defaults).

Loops ("for each …" requests) use the core/foreach action: its "items" input takes a list
reference (e.g. {{ recent.posts }}) and ALL FOLLOWING steps run once per item.
Inside the loop, reference {{ <foreach_step_id>.item }} / .index / .count
(e.g. {{ each_post.item.title }} when the foreach step\'s id is "each_post").

Conditions ("only if …" requests) use the core/condition action with EXACTLY this params shape:
  { "match": "all", "conditions": [ { "field": "{{ step_id.output }}", "op": "<operator>", "value": "<compare-to>" } ] }
- "match" is "all" (AND) or "any" (OR) — NEVER a {{ reference }}.
- Each condition row needs "field" (what to check, usually a {{ reference }}), "op", and "value".
- Operators: equals, not_equals, contains, not_contains, starts_with, ends_with, greater_than, less_than, is_empty, is_not_empty, is_true, is_false.
- Steps AFTER a condition only run when it matches (the true branch). Put the conditional work after it.';

    return [
      'integrations'    => $integrations,
      'actions'         => $actions,
      'triggers'        => $triggers,
      'ai_actions_text' => $ai_actions_text,
      'ai_format_text'  => $ai_format_text,
    ];
  }

  private function build_system_prompt( $schema, $is_extend ) {
    $intro = $is_extend
      ? "You extend an existing WordPress automation workflow based on the user's description."
      : "You design simple WordPress automation workflows based on the user's description.";

    return implode( "\n\n", [
      $intro,
      $schema['ai_format_text'],
      $schema['ai_actions_text'],
    ] );
  }

  /* ------------------------------------------------------------------ */
  /* AI Engine call wrappers                                            */
  /* ------------------------------------------------------------------ */

  private function call_ai_json( $message, $instructions ) {
    $mwai = $GLOBALS['mwai'] ?? null;
    if ( !$mwai ) {
      throw new Meow_MWFLOW_AI_Authoring_Exception(
        __( 'AI Engine is not active.', 'meow-workflow' ),
        self::ERR_AI_UNAVAILABLE
      );
    }
    try {
      $reply = $mwai->simpleJsonQuery( $message, null, null, [ 'instructions' => $instructions ] );
    }
    catch ( Throwable $e ) {
      // Anything from the AI Engine call itself: rate limit, provider error,
      // quota, network, bad API key. Surface the original message so users
      // can act on it ("you reached your limit" is actionable).
      throw new Meow_MWFLOW_AI_Authoring_Exception(
        $this->friendly_ai_error( $e->getMessage() ),
        self::ERR_AI_FAILED
      );
    }
    if ( !is_array( $reply ) ) {
      throw new Meow_MWFLOW_AI_Authoring_Exception(
        __( "The AI didn't return a workflow we could read. Try rewording your request — be specific about the trigger and the actions.", 'meow-workflow' ),
        self::ERR_INVALID_OUTPUT
      );
    }
    return $reply;
  }

  private function call_ai_text( $message, $instructions ) {
    $mwai = $GLOBALS['mwai'] ?? null;
    if ( !$mwai ) {
      throw new Meow_MWFLOW_AI_Authoring_Exception(
        __( 'AI Engine is not active.', 'meow-workflow' ),
        self::ERR_AI_UNAVAILABLE
      );
    }
    try {
      return (string) $mwai->simpleTextQuery( $message, [ 'instructions' => $instructions ] );
    }
    catch ( Throwable $e ) {
      throw new Meow_MWFLOW_AI_Authoring_Exception(
        $this->friendly_ai_error( $e->getMessage() ),
        self::ERR_AI_FAILED
      );
    }
  }

  /**
   * Lightly rephrase common AI Engine error strings so they sound like helpful
   * advice instead of a stack trace. Falls through to the original message.
   */
  private function friendly_ai_error( $msg ) {
    $msg = trim( (string) $msg );
    if ( $msg === '' ) {
      return __( "AI Engine didn't respond. Check your AI Engine configuration and try again.", 'meow-workflow' );
    }
    $lower = strtolower( $msg );
    if ( strpos( $lower, 'limit' ) !== false || strpos( $lower, 'quota' ) !== false || strpos( $lower, 'rate' ) !== false ) {
      return sprintf(
        __( '%s — check AI Engine → Limits, or try a different environment.', 'meow-workflow' ),
        $msg
      );
    }
    if ( strpos( $lower, 'api key' ) !== false || strpos( $lower, 'unauthor' ) !== false || strpos( $lower, '401' ) !== false ) {
      return sprintf(
        __( '%s — verify the API key for the active AI Engine environment.', 'meow-workflow' ),
        $msg
      );
    }
    if ( strpos( $lower, 'timeout' ) !== false ) {
      return __( "The AI took too long to respond. Try again, or simplify your prompt.", 'meow-workflow' );
    }
    return $msg;
  }

  /* ------------------------------------------------------------------ */
  /* Validation                                                         */
  /* ------------------------------------------------------------------ */

  /**
   * Validates the AI's linear output. Also NORMALISES in place ($linear is
   * by-ref): condition params drift the models produce gets coerced into the
   * runner's exact schema before graph expansion.
   */
  private function validate_linear( &$linear, $schema ) {
    $errors = [];
    if ( !is_array( $linear ) ) {
      return [ 'AI output was not a JSON object.' ];
    }

    $trigger = $linear['trigger'] ?? null;
    if ( !is_array( $trigger ) || empty( $trigger['type'] ) ) {
      $errors[] = 'Missing trigger.';
    }
    else if ( !in_array( $trigger['type'], [ 'manual', 'schedule', 'webhook', 'hook', 'rss' ], true ) ) {
      $errors[] = 'Trigger type "' . $trigger['type'] . '" is not one of: manual, schedule, webhook, hook, rss.';
    }

    if ( !is_array( $linear['steps'] ?? null ) ) {
      return array_merge( $errors, [ 'Steps must be an array.' ] );
    }

    $known_actions = [];
    foreach ( $schema['actions'] as $a ) {
      $known_actions[ $a['integration'] . '/' . $a['id'] ] = $a;
    }

    $seen_ids = [];
    foreach ( $linear['steps'] as $i => &$step ) {
      $where = 'step ' . ( $i + 1 );
      if ( !is_array( $step ) ) {
        $errors[] = "$where is not an object.";
        continue;
      }
      $id = $step['id'] ?? '';
      if ( !is_string( $id ) || $id === '' ) {
        $errors[] = "$where is missing 'id'.";
      }
      else if ( isset( $seen_ids[ $id ] ) ) {
        $errors[] = "$where uses duplicate id '$id'.";
      }
      else {
        $seen_ids[ $id ] = true;
      }

      $integration = $step['integration'] ?? '';
      $action      = $step['action'] ?? '';
      $key = $integration . '/' . $action;
      if ( !isset( $known_actions[ $key ] ) ) {
        $errors[] = "$where references unknown action '$key'. Pick from the list.";
        continue;
      }

      // Check required inputs are present.
      $spec = $known_actions[ $key ];
      $params = is_array( $step['params'] ?? null ) ? $step['params'] : [];
      foreach ( $spec['inputs'] as $input ) {
        if ( $input['required'] && ( !array_key_exists( $input['id'], $params ) || $params[ $input['id'] ] === '' || $params[ $input['id'] ] === null ) ) {
          $errors[] = "$where (id=$id) is missing required input '" . $input['id'] . "'.";
        }
      }

      // Condition steps: normalize the shapes models commonly drift into
      // (operator→op, symbol operators, match as a reference), and reject
      // rows with no field — those silently evaluate wrong at runtime.
      if ( $key === 'core/condition' ) {
        $errors = array_merge( $errors, $this->normalise_condition_step( $step, $where ) );
      }
    }
    unset( $step );

    return $errors;
  }

  /**
   * Coerce a condition step's params into the runner's schema in place, and
   * return validation errors for anything that can't be coerced. Mutating
   * here means a repaired-or-clean output expands straight into a runnable
   * graph without a second AI round-trip for cosmetic key differences.
   */
  private function normalise_condition_step( &$step, $where ) {
    $errors = [];
    $params = is_array( $step['params'] ?? null ) ? $step['params'] : [];
    $op_map = [
      '>' => 'greater_than', '>=' => 'greater_than', 'gt' => 'greater_than',
      '<' => 'less_than', '<=' => 'less_than', 'lt' => 'less_than',
      '=' => 'equals', '==' => 'equals', '===' => 'equals', 'eq' => 'equals',
      '!=' => 'not_equals', '!==' => 'not_equals', 'neq' => 'not_equals',
    ];
    $valid_ops = [
      'equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with',
      'greater_than', 'less_than', 'is_empty', 'is_not_empty', 'is_true', 'is_false',
    ];

    // A {{ reference }} in "match" is a misplaced field — remember it as a
    // fallback for rows that lack one, then force a valid mode.
    $stray_field = null;
    $match = $params['match'] ?? 'all';
    if ( is_string( $match ) && strpos( $match, '{{' ) !== false ) {
      $stray_field = trim( $match );
    }
    $params['match'] = in_array( $match, [ 'all', 'any' ], true ) ? $match : 'all';

    $rows = is_array( $params['conditions'] ?? null ) ? $params['conditions'] : [];
    foreach ( $rows as $i => &$row ) {
      if ( !is_array( $row ) ) { continue; }
      if ( !isset( $row['op'] ) && isset( $row['operator'] ) ) {
        $row['op'] = $row['operator'];
        unset( $row['operator'] );
      }
      $op = (string) ( $row['op'] ?? 'equals' );
      $row['op'] = $op_map[ $op ] ?? $op;
      if ( !in_array( $row['op'], $valid_ops, true ) ) {
        $errors[] = "$where condition row " . ( $i + 1 ) . " uses unknown operator '" . $op . "'. Use one of: " . implode( ', ', $valid_ops ) . '.';
      }
      if ( empty( $row['field'] ) && $stray_field !== null ) {
        $row['field'] = $stray_field;
      }
      if ( empty( $row['field'] ) ) {
        $errors[] = "$where condition row " . ( $i + 1 ) . " is missing 'field' (what to check, e.g. \"{{ step_id.output }}\").";
      }
    }
    unset( $row );
    $params['conditions'] = $rows;
    $step['params'] = $params;
    return $errors;
  }

  /* ------------------------------------------------------------------ */
  /* Graph expansion + reverse                                          */
  /* ------------------------------------------------------------------ */

  /**
   * Take the linear AI output and turn it into the runtime's node+edge graph.
   * Stacks nodes top-to-bottom at a fixed X so the chain reads down the canvas.
   */
  private function expand_linear_to_graph( $linear ) {
    $nodes = [];
    $edges = [];

    $trigger = $linear['trigger'] ?? [ 'type' => 'manual', 'config' => [], 'label' => 'Manual' ];
    $nodes[] = [
      'id'       => 'trigger',
      'type'     => 'trigger',
      'position' => [ 'x' => self::NODE_X0, 'y' => self::NODE_Y0 ],
      'data'     => [
        'kind'        => 'trigger',
        'integration' => 'core',
        'trigger'     => $trigger['type'] ?? 'manual',
        'label'       => $trigger['label'] ?? ucfirst( $trigger['type'] ?? 'manual' ),
        'params'      => [],
      ],
    ];

    $prev_id = 'trigger';
    $prev_was_condition = false;
    $y = self::NODE_Y0 + self::NODE_DY;
    foreach ( $linear['steps'] ?? [] as $step ) {
      $id = sanitize_key( $step['id'] );
      if ( $id === '' ) { continue; }
      $nodes[] = [
        'id'       => $id,
        'type'     => 'action',
        'position' => [ 'x' => self::NODE_X0, 'y' => $y ],
        'data'     => [
          'kind'        => 'action',
          'integration' => (string) $step['integration'],
          'action'      => (string) $step['action'],
          'params'      => is_array( $step['params'] ?? null ) ? $step['params'] : [],
        ],
      ];
      $edge = [
        'id'     => 'e_' . $prev_id . '_' . $id,
        'source' => $prev_id,
        'target' => $id,
      ];
      // In a linear chain, the step after a condition is its TRUE branch —
      // wire the handle so the flow stops (instead of continuing blindly)
      // when the condition doesn't match.
      if ( $prev_was_condition ) {
        $edge['sourceHandle'] = 'true';
      }
      $edges[] = $edge;
      $prev_was_condition = ( (string) $step['action'] === 'condition' );
      $prev_id = $id;
      $y += self::NODE_DY;
    }

    return [ 'nodes' => $nodes, 'edges' => $edges ];
  }

  /**
   * Reverse of expand_linear_to_graph: take a runtime definition and produce
   * the slim linear chain the AI works with.
   */
  private function linearise_existing( $definition ) {
    $nodes = is_array( $definition['nodes'] ?? null ) ? $definition['nodes'] : [];
    $edges = is_array( $definition['edges'] ?? null ) ? $definition['edges'] : [];

    // Build adjacency.
    $by_id = [];
    foreach ( $nodes as $n ) { $by_id[ $n['id'] ] = $n; }
    $children = [];
    foreach ( $edges as $e ) {
      $children[ $e['source'] ][] = $e['target'];
    }

    // Find trigger.
    $trigger_node = null;
    foreach ( $nodes as $n ) {
      if ( ( $n['type'] ?? '' ) === 'trigger' ) { $trigger_node = $n; break; }
    }

    $linear_steps = [];
    if ( $trigger_node ) {
      $cur = $trigger_node['id'];
      $seen = [ $cur => true ];
      while ( !empty( $children[ $cur ] ) ) {
        $next = $children[ $cur ][0]; // linear: take first child
        if ( isset( $seen[ $next ] ) ) { break; }
        $seen[ $next ] = true;
        $node = $by_id[ $next ] ?? null;
        if ( !$node ) { break; }
        $linear_steps[] = [
          'id'          => $node['id'],
          'integration' => $node['data']['integration'] ?? '',
          'action'      => $node['data']['action'] ?? '',
          'params'      => $node['data']['params'] ?? [],
        ];
        $cur = $next;
      }
    }

    $trigger_type = $trigger_node['data']['trigger'] ?? 'manual';
    return [
      'trigger' => [
        'type'   => $trigger_type,
        'config' => [], // unknown at this level — caller supplies if needed
        'label'  => $trigger_node['data']['label'] ?? ucfirst( $trigger_type ),
      ],
      'steps'   => $linear_steps,
    ];
  }

  private function is_ai_available() {
    return class_exists( 'Meow_MWAI_API' ) && isset( $GLOBALS['mwai'] );
  }

  /**
   * Translate a list of raw validator errors into a single user-facing
   * sentence. Most validator output ("step 1 references unknown action…") is
   * useful for debugging but reads like a stack trace to users.
   */
  private function summarise_validation_errors( $errors ) {
    $unknown_action = 0;
    $missing_input  = 0;
    $bad_trigger    = 0;
    foreach ( $errors as $e ) {
      $lower = strtolower( (string) $e );
      if ( strpos( $lower, 'unknown action' ) !== false )       { $unknown_action++; }
      else if ( strpos( $lower, 'missing required input' ) !== false ) { $missing_input++; }
      else if ( strpos( $lower, 'trigger' ) !== false )         { $bad_trigger++; }
    }
    if ( $unknown_action > 0 && $missing_input === 0 ) {
      return __( "The AI tried to use a step that isn't installed. Try being more specific — for example, mention 'AI Engine', 'WordPress post', or 'send email' — so the AI picks from the steps you actually have.", 'meow-workflow' );
    }
    if ( $missing_input > 0 && $unknown_action === 0 ) {
      return __( "The AI didn't fill in everything a step needs (like the email address, the prompt, or the URL). Try describing those details in your request.", 'meow-workflow' );
    }
    if ( $bad_trigger > 0 ) {
      return __( "The AI couldn't figure out when this workflow should fire. Try starting your sentence with 'When…' or 'Every…'.", 'meow-workflow' );
    }
    return __( "The AI's plan didn't quite line up with the available steps. Try rewording your request, or describe the trigger and each action separately.", 'meow-workflow' );
  }
}

/**
 * Tagged exception carrying an error `kind` so the REST layer can surface a
 * category to the UI without sniffing message strings. PHP doesn't have inner
 * classes — defined as a top-level class and referenced by full name above.
 */
class Meow_MWFLOW_AI_Authoring_Exception extends Exception {
  public $kind;
  public function __construct( $message, $kind = 'unknown', $previous = null ) {
    parent::__construct( (string) $message, 0, $previous );
    $this->kind = (string) $kind;
  }
}
