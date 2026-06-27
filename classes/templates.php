<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * Built-in example workflows that the user can install from
 * Settings → Maintenance → Install example workflows.
 *
 * Each template is a pure PHP array shaped like a runtime flow definition
 * (`{ name, definition: { nodes, edges }, trigger_type, trigger_config }`)
 * plus metadata:
 *   - id          stable identifier
 *   - description one-paragraph plain English
 *   - tag         short category tag for the UI badge
 *   - requires    array of integration ids that must be active for the
 *                 template to actually run (used by the UI to enable/disable
 *                 the Install button, and by the REST endpoint to refuse
 *                 installing a flow whose dependencies aren't met).
 *
 * To add a new template: append a static method below and reference it in
 * Meow_MWFLOW_Templates::all().
 */
class Meow_MWFLOW_Templates {

  public static function all() {
    // Plugin-free templates first — a bare install should see usable
    // examples at the top, not a wall of disabled cards.
    return [
      self::notify_on_publish(),
      self::weekly_digest(),
      self::comment_alert(),
      self::coin_flip(),
      self::ai_draft_writer(),
      self::rss_to_draft(),
      self::ai_excerpt_on_publish(),
      self::ai_moderate_comments(),
      self::ai_welcome_new_user(),
      self::weekly_content_brief(),
      self::woo_order_alert(),
      self::woo_thank_you_note(),
      self::daily_seo_report(),
    ];
  }

  /**
   * P1. Email me when a post is published — the simplest possible real
   * workflow, zero extra plugins. The "hello world" of the gallery.
   */
  private static function notify_on_publish() {
    return [
      'id'           => 'notify_on_publish',
      'name'         => __( 'Email Me When a Post Is Published', 'meow-workflow' ),
      'description'  => __( 'Every time a post goes live, you get an email with its title and a link. Works out of the box — no other plugin needed.', 'meow-workflow' ),
      'tag'          => __( 'Basics', 'meow-workflow' ),
      'category'     => 'basics',
      'requires'     => [ 'wordpress' ],
      'trigger_type' => 'hook',
      'trigger_config' => [ 'hook' => 'publish_post', 'priority' => 10, 'args' => 2 ],
      'definition'   => [
        'nodes' => [
          self::trigger_node( __( 'When a post is published', 'meow-workflow' ), 'hook' ),
          self::action_node( 'fetch_post', 280, 'wordpress', 'get_post', [
            'post_id' => '{{ trigger.post_id }}',
          ] ),
          self::action_node( 'notify', 560, 'core', 'send_email', [
            'to'      => '{{ admin_email }}',
            'subject' => __( 'Published: {{ fetch_post.title }}', 'meow-workflow' ),
            'body'    => __( "A post was just published on {{ site_name }}:\n\n{{ fetch_post.title }}\n{{ fetch_post.url }}", 'meow-workflow' ),
          ] ),
        ],
        'edges' => self::chain_edges( [ 'trigger', 'fetch_post', 'notify' ] ),
      ],
    ];
  }

  /**
   * P2. Weekly digest of new posts — schedule + get_recent_posts + email.
   * Zero extra plugins.
   */
  private static function weekly_digest() {
    return [
      'id'           => 'weekly_digest',
      'name'         => __( 'Weekly Digest of New Posts', 'meow-workflow' ),
      'description'  => __( 'Every Monday morning, an email with everything published in the last 7 days. Works out of the box — no other plugin needed.', 'meow-workflow' ),
      'tag'          => __( 'Basics', 'meow-workflow' ),
      'category'     => 'basics',
      'requires'     => [ 'wordpress' ],
      'trigger_type' => 'schedule',
      'trigger_config' => [ 'recurrence' => 'weekly', 'day' => 'monday', 'time' => '08:00' ],
      'definition'   => [
        'nodes' => [
          self::trigger_node( __( 'Every Monday at 08:00', 'meow-workflow' ), 'schedule' ),
          self::action_node( 'recent', 280, 'wordpress', 'get_recent_posts', [
            'post_type' => 'post',
            'count'     => 10,
            'days'      => 7,
          ] ),
          self::action_node( 'email', 560, 'core', 'send_email', [
            'to'      => '{{ admin_email }}',
            'subject' => __( '{{ site_name }} — this week\'s posts', 'meow-workflow' ),
            'body'    => __( "Published on {{ site_name }} in the last 7 days ({{ recent.count }} posts):\n\n{{ recent.summary }}", 'meow-workflow' ),
          ] ),
        ],
        'edges' => self::chain_edges( [ 'trigger', 'recent', 'email' ] ),
      ],
    ];
  }

  /**
   * P3. Comment alert — comment_post + get_comment + email. Zero extra plugins.
   */
  private static function comment_alert() {
    return [
      'id'           => 'comment_alert',
      'name'         => __( 'Email Me About New Comments', 'meow-workflow' ),
      'description'  => __( 'When someone comments, you get an email with who wrote it and what they said. Works out of the box — no other plugin needed.', 'meow-workflow' ),
      'tag'          => __( 'Basics', 'meow-workflow' ),
      'category'     => 'basics',
      'requires'     => [ 'wordpress' ],
      'trigger_type' => 'hook',
      'trigger_config' => [ 'hook' => 'comment_post', 'priority' => 10, 'args' => 2 ],
      'definition'   => [
        'nodes' => [
          self::trigger_node( __( 'When a comment is posted', 'meow-workflow' ), 'hook' ),
          self::action_node( 'fetch_comment', 280, 'wordpress', 'get_comment', [
            'comment_id' => '{{ trigger.comment_id }}',
          ] ),
          self::action_node( 'notify', 560, 'core', 'send_email', [
            'to'      => '{{ admin_email }}',
            'subject' => __( 'New comment by {{ fetch_comment.author }}', 'meow-workflow' ),
            'body'    => __( "New comment on {{ site_name }}:\n\nFrom: {{ fetch_comment.author }} ({{ fetch_comment.author_email }})\n\n{{ fetch_comment.content }}", 'meow-workflow' ),
          ] ),
        ],
        'edges' => self::chain_edges( [ 'trigger', 'fetch_comment', 'notify' ] ),
      ],
    ];
  }

  /**
   * P4. Coin flip — the playful Branch By Value teacher. Press Test once,
   * watch only one branch light up. Core only.
   */
  private static function coin_flip() {
    $branch_y = 60 + 3 * 150;
    $log_heads = self::action_node( 'log_heads', 0, 'core', 'log', [
      'message' => __( '🪙 HEADS — the coin flip gave: {{ flip.value }}', 'meow-workflow' ),
      'level'   => 'info',
    ] );
    $log_heads['position'] = [ 'x' => 180, 'y' => $branch_y ];
    $log_tails = self::action_node( 'log_tails', 0, 'core', 'log', [
      'message' => __( '🪙 TAILS — the coin flip gave: {{ flip.value }}', 'meow-workflow' ),
      'level'   => 'info',
    ] );
    $log_tails['position'] = [ 'x' => 460, 'y' => $branch_y ];

    return [
      'id'           => 'coin_flip',
      'name'         => __( 'Coin Flip — Learn Branching', 'meow-workflow' ),
      'description'  => __( 'Press Test once: a coin is flipped and only the matching path runs. The fastest way to understand Branch By Value.', 'meow-workflow' ),
      'tag'          => __( 'Basics', 'meow-workflow' ),
      'category'     => 'basics',
      'requires'     => [],
      'trigger_type' => 'manual',
      'trigger_config' => [],
      'definition'   => [
        'nodes' => [
          self::trigger_node( __( 'Manual', 'meow-workflow' ), 'manual' ),
          self::action_node( 'flip', 280, 'core', 'random', [
            'choices' => 'heads, tails',
          ] ),
          self::action_node( 'branch', 560, 'core', 'router', [
            'value'    => '{{ flip.value }}',
            'branches' => [
              [ 'id' => 'heads', 'name' => 'heads', 'value' => 'heads' ],
              [ 'id' => 'tails', 'name' => 'tails', 'value' => 'tails' ],
            ],
          ] ),
          $log_heads,
          $log_tails,
        ],
        'edges' => array_merge(
          self::chain_edges( [ 'trigger', 'flip', 'branch' ] ),
          [
            [ 'id' => 'e_branch_heads', 'source' => 'branch', 'sourceHandle' => 'heads', 'target' => 'log_heads' ],
            [ 'id' => 'e_branch_tails', 'source' => 'branch', 'sourceHandle' => 'tails', 'target' => 'log_tails' ],
          ]
        ),
      ],
    ];
  }

  /**
   * W1. New order alert — WooCommerce order → details → email. The most
   * common shop automation.
   */
  private static function woo_order_alert() {
    return [
      'id'           => 'woo_order_alert',
      'name'         => __( 'Email Me on Every New Order', 'meow-workflow' ),
      'description'  => __( 'The moment an order comes in, you get an email with the customer, the items, and the total.', 'meow-workflow' ),
      'tag'          => __( 'WooCommerce', 'meow-workflow' ),
      'category'     => 'woocommerce',
      'requires'     => [ 'woocommerce' ],
      'trigger_type' => 'hook',
      'trigger_config' => [ 'hook' => 'woocommerce_new_order', 'priority' => 10, 'args' => 1, 'event' => 'on_new_order' ],
      'definition'   => [
        'nodes' => [
          self::trigger_node( __( 'When a new order comes in', 'meow-workflow' ), 'hook' ),
          self::action_node( 'order', 280, 'woocommerce', 'get_order', [
            'order_id' => '{{ trigger.order_id }}',
          ] ),
          self::action_node( 'notify', 560, 'core', 'send_email', [
            'to'      => '{{ admin_email }}',
            'subject' => __( 'New order #{{ order.id }} — {{ order.total }} {{ order.currency }}', 'meow-workflow' ),
            'body'    => __( "New order on {{ site_name }}!\n\nCustomer: {{ order.customer_name }} ({{ order.customer_email }})\nTotal: {{ order.total }} {{ order.currency }}\nPayment: {{ order.payment_method }}\n\nItems:\n{{ order.items_summary }}", 'meow-workflow' ),
          ] ),
        ],
        'edges' => self::chain_edges( [ 'trigger', 'order', 'notify' ] ),
      ],
    ];
  }

  /**
   * W2. AI thank-you note on completed orders — order completed → details →
   * AI writes a personal note → saved on the order, visible to the customer.
   */
  private static function woo_thank_you_note() {
    return [
      'id'           => 'woo_thank_you_note',
      'name'         => __( 'AI Thank-You Note on Completed Orders', 'meow-workflow' ),
      'description'  => __( 'When an order completes, AI Engine writes a short personal thank-you mentioning what they bought, saved on the order as a customer-visible note.', 'meow-workflow' ),
      'tag'          => __( 'WooCommerce', 'meow-workflow' ),
      'category'     => 'woocommerce',
      'requires'     => [ 'woocommerce', 'ai-engine' ],
      'trigger_type' => 'hook',
      'trigger_config' => [ 'hook' => 'woocommerce_order_status_completed', 'priority' => 10, 'args' => 1, 'event' => 'on_order_completed' ],
      'definition'   => [
        'nodes' => [
          self::trigger_node( __( 'When an order is completed', 'meow-workflow' ), 'hook' ),
          self::action_node( 'order', 280, 'woocommerce', 'get_order', [
            'order_id' => '{{ trigger.order_id }}',
          ] ),
          self::action_node( 'compose', 560, 'ai-engine', 'fast_text', [
            'message' => __( "Write a warm 2-sentence thank-you note for {{ order.customer_name }} who just received their order from \"{{ site_name }}\". Mention what they bought naturally. No greeting line, no sign-off.\n\nItems:\n{{ order.items_summary }}", 'meow-workflow' ),
          ] ),
          self::action_node( 'note', 840, 'woocommerce', 'add_order_note', [
            'order_id'      => '{{ trigger.order_id }}',
            'note'          => '{{ compose.text }}',
            'customer_note' => true,
          ] ),
        ],
        'edges' => self::chain_edges( [ 'trigger', 'order', 'compose', 'note' ] ),
      ],
    ];
  }

  /**
   * 0a. AI draft writer
   *
   * Schedule → AI writes a full post on your topic → saved as a DRAFT for
   * human review. The single most requested automation: hands-off content
   * production that never publishes on its own.
   */
  private static function ai_draft_writer() {
    return [
      'id'           => 'ai_draft_writer',
      'name'         => __( 'AI Draft Writer', 'meow-workflow' ),
      'category'     => 'ai',
      'description'  => __( 'On a schedule, AI Engine writes a full blog post about your topic and saves it as a draft for you to review. Edit the topic inside the AI step before activating.', 'meow-workflow' ),
      'tag'          => __( 'AI', 'meow-workflow' ),
      'requires'     => [ 'ai-engine', 'wordpress' ],
      'trigger_type' => 'schedule',
      'trigger_config' => [ 'recurrence' => 'weekly', 'day' => 'monday', 'time' => '08:00' ],
      'definition'   => [
        'nodes' => [
          self::trigger_node( __( 'Every week at 8:00 AM', 'meow-workflow' ), 'schedule' ),
          self::action_node( 'write_draft', 280, 'ai-engine', 'json', [
            'message' => __( "You write for the blog \"{{ site_name }}\".\n\nTOPIC (edit me!): practical tips our readers can use — pick one fresh, specific angle each time.\n\nWrite an original blog post on that topic: an engaging title and 4-6 short HTML paragraphs (<p>…</p>, optional <h2> subheadings). No invented facts.\n\nReturn ONLY JSON: {\"title\": \"…\", \"content\": \"…\"}", 'meow-workflow' ),
          ] ),
          self::action_node( 'save_draft', 560, 'wordpress', 'create_post', [
            'title'   => '{{ write_draft.data.title }}',
            'content' => '{{ write_draft.data.content }}',
            'status'  => 'draft',
          ] ),
        ],
        'edges' => self::chain_edges( [ 'trigger', 'write_draft', 'save_draft' ] ),
      ],
    ];
  }

  /**
   * 0b. RSS to draft
   *
   * New RSS item → AI summarises and rewrites it in the site's voice →
   * saved as a DRAFT with a link back to the source. The classic
   * news-to-content pipeline, kept honest: original words, human review.
   */
  private static function rss_to_draft() {
    return [
      'id'           => 'rss_to_draft',
      'name'         => __( 'Turn Feeds Into Drafts', 'meow-workflow' ),
      'category'     => 'ai',
      'description'  => __( 'When a new item appears in a feed you choose, AI Engine rewrites it in your site\'s voice and saves it as a draft, linking back to the source. Set the feed URL in the trigger before activating.', 'meow-workflow' ),
      'tag'          => __( 'AI', 'meow-workflow' ),
      'requires'     => [ 'ai-engine', 'wordpress' ],
      'trigger_type' => 'rss',
      'trigger_config' => [ 'feed_url' => '', 'recurrence' => 'hourly' ],
      'definition'   => [
        'nodes' => [
          self::trigger_node( __( 'When a new RSS item appears', 'meow-workflow' ), 'rss' ),
          self::action_node( 'rewrite', 280, 'ai-engine', 'json', [
            'message' => __( "You write for the blog \"{{ site_name }}\".\n\nBelow is a news item from another site. Write a SHORT original post about it in our own voice: summarise the key points in your own words (never copy sentences), 2-4 HTML paragraphs, and end with: <p>Source: <a href=\"{{ trigger.link }}\">{{ trigger.title }}</a></p>\n\nReturn ONLY JSON: {\"title\": \"…\", \"content\": \"…\"}\n\nSOURCE TITLE: {{ trigger.title }}\nSOURCE CONTENT:\n{{ trigger.content }}", 'meow-workflow' ),
          ] ),
          self::action_node( 'save_draft', 560, 'wordpress', 'create_post', [
            'title'   => '{{ rewrite.data.title }}',
            'content' => '{{ rewrite.data.content }}',
            'status'  => 'draft',
          ] ),
        ],
        'edges' => self::chain_edges( [ 'trigger', 'rewrite', 'save_draft' ] ),
      ],
    ];
  }

  /**
   * 1. AI excerpt on publish
   *
   * Hook: publish_post. Fetches the post, asks AI Engine for a short excerpt,
   * writes the excerpt back. Demonstrates the publish trigger + WordPress
   * get_post + AI fast_text + WordPress update_post.
   */
  private static function ai_excerpt_on_publish() {
    return [
      'id'           => 'ai_excerpt_on_publish',
      'name'         => __( 'Write Excerpts Automatically', 'meow-workflow' ),
      'category'     => 'ai',
      'description'  => __( 'Every time a post is published, AI Engine writes a one-sentence excerpt and saves it on the post.', 'meow-workflow' ),
      'tag'          => __( 'AI', 'meow-workflow' ),
      'requires'     => [ 'ai-engine', 'wordpress' ],
      'trigger_type' => 'hook',
      'trigger_config' => [ 'hook' => 'publish_post', 'priority' => 10, 'args' => 2 ],
      'definition'   => [
        'nodes' => [
          self::trigger_node( __( 'When a post is published', 'meow-workflow' ), 'hook' ),
          self::action_node( 'fetch_post', 280, 'wordpress', 'get_post', [
            'post_id' => '{{ trigger.post_id }}',
          ] ),
          self::action_node( 'write_excerpt', 560, 'ai-engine', 'fast_text', [
            'message' => __( "Write a single-sentence excerpt (max 160 chars, plain text, no quotes).\n\nTitle: {{ fetch_post.title }}\n\nContent:\n{{ fetch_post.content }}", 'meow-workflow' ),
          ] ),
          self::action_node( 'save_excerpt', 840, 'wordpress', 'update_post', [
            'post_id'  => '{{ trigger.post_id }}',
            'excerpt'  => '{{ write_excerpt.text }}',
          ] ),
        ],
        'edges' => self::chain_edges( [ 'trigger', 'fetch_post', 'write_excerpt', 'save_excerpt' ] ),
      ],
    ];
  }

  /**
   * 2. AI comment moderation
   *
   * Hook: comment_post. Reads the comment, asks AI Engine to classify the
   * actual text, then routes on the verdict: spam → marked as spam,
   * insulting → held for review, legitimate → just logged. Also the showcase
   * for Branch By Value.
   */
  private static function ai_moderate_comments() {
    $branch_y = 60 + 4 * 150;
    $mark_spam = self::action_node( 'mark_spam', 0, 'wordpress', 'set_comment_status', [
      'comment_id' => '{{ trigger.comment_id }}',
      'status'     => 'spam',
    ] );
    $mark_spam['position'] = [ 'x' => 100, 'y' => $branch_y ];
    $hold = self::action_node( 'hold_for_review', 0, 'wordpress', 'set_comment_status', [
      'comment_id' => '{{ trigger.comment_id }}',
      'status'     => 'hold',
    ] );
    $hold['position'] = [ 'x' => 320, 'y' => $branch_y ];
    $log_ok = self::action_node( 'log_verdict', 0, 'core', 'log', [
      'message' => __( "Comment #{{ trigger.comment_id }} by {{ fetch_comment.author }} classified as: {{ classify.text }}", 'meow-workflow' ),
      'level'   => 'info',
    ] );
    $log_ok['position'] = [ 'x' => 540, 'y' => $branch_y ];

    return [
      'id'           => 'ai_moderate_comments',
      'name'         => __( 'Moderate Comments With AI', 'meow-workflow' ),
      'category'     => 'ai',
      'description'  => __( 'When a comment is posted, AI Engine reads it and classifies it — spam is marked as spam, insulting comments are held for review, legitimate ones are logged.', 'meow-workflow' ),
      'tag'          => __( 'AI', 'meow-workflow' ),
      'requires'     => [ 'ai-engine', 'wordpress' ],
      'trigger_type' => 'hook',
      'trigger_config' => [ 'hook' => 'comment_post', 'priority' => 10, 'args' => 2 ],
      'definition'   => [
        'nodes' => [
          self::trigger_node( __( 'When a comment is posted', 'meow-workflow' ), 'hook' ),
          self::action_node( 'fetch_comment', 280, 'wordpress', 'get_comment', [
            'comment_id' => '{{ trigger.comment_id }}',
          ] ),
          self::action_node( 'classify', 560, 'ai-engine', 'fast_text', [
            'message' => __( "Classify this WordPress comment as exactly one word: legitimate, spam, or insulting. Reply with the single word only, lowercase.\n\nAuthor: {{ fetch_comment.author }}\nComment:\n{{ fetch_comment.content }}", 'meow-workflow' ),
          ] ),
          self::action_node( 'verdict', 840, 'core', 'router', [
            'value'    => '{{ classify.text }}',
            'branches' => [
              [ 'id' => 'spam',      'name' => 'spam',      'value' => 'spam' ],
              [ 'id' => 'insulting', 'name' => 'insulting', 'value' => 'insulting' ],
              [ 'id' => 'ok',        'name' => 'ok',        'value' => 'legitimate' ],
            ],
          ] ),
          $mark_spam,
          $hold,
          $log_ok,
        ],
        'edges' => array_merge(
          self::chain_edges( [ 'trigger', 'fetch_comment', 'classify', 'verdict' ] ),
          [
            [ 'id' => 'e_verdict_spam', 'source' => 'verdict', 'sourceHandle' => 'spam',      'target' => 'mark_spam' ],
            [ 'id' => 'e_verdict_hold', 'source' => 'verdict', 'sourceHandle' => 'insulting', 'target' => 'hold_for_review' ],
            [ 'id' => 'e_verdict_ok',   'source' => 'verdict', 'sourceHandle' => 'ok',        'target' => 'log_verdict' ],
          ]
        ),
      ],
    ];
  }

  /**
   * 3. AI-personalised welcome email for new users
   *
   * Hook: user_register. Looks up the user, asks AI Engine for a friendly
   * 3-sentence welcome, sends it.
   */
  private static function ai_welcome_new_user() {
    return [
      'id'           => 'ai_welcome_new_user',
      'name'         => __( 'Welcome New Users by Email', 'meow-workflow' ),
      'category'     => 'ai',
      'description'  => __( 'When someone registers, AI Engine drafts a friendly 3-sentence welcome email and sends it to them.', 'meow-workflow' ),
      'tag'          => __( 'AI', 'meow-workflow' ),
      'requires'     => [ 'ai-engine', 'wordpress' ],
      'trigger_type' => 'hook',
      'trigger_config' => [ 'hook' => 'user_register', 'priority' => 10, 'args' => 1 ],
      'definition'   => [
        'nodes' => [
          self::trigger_node( __( 'When a user registers', 'meow-workflow' ), 'hook' ),
          self::action_node( 'fetch_user', 280, 'wordpress', 'get_user', [
            'user_id' => '{{ trigger.user_id }}',
          ] ),
          self::action_node( 'compose', 560, 'ai-engine', 'fast_text', [
            'message' => __( "Write a friendly 3-sentence welcome email for a new user named {{ fetch_user.display_name }} who just registered on the site \"{{ site_name }}\". Warm but not pushy. No greeting line — start with the message itself.", 'meow-workflow' ),
          ] ),
          self::action_node( 'send', 840, 'core', 'send_email', [
            'to'      => '{{ fetch_user.email }}',
            'subject' => __( 'Welcome — glad to have you here!', 'meow-workflow' ),
            'body'    => "Hi {{ fetch_user.display_name }},\n\n{{ compose.text }}\n\nThanks!",
          ] ),
        ],
        'edges' => self::chain_edges( [ 'trigger', 'fetch_user', 'compose', 'send' ] ),
      ],
    ];
  }

  /**
   * 4. Weekly content brief
   *
   * Schedule: Mondays 08:00. Pulls a recent post and asks AI Engine to
   * suggest a follow-up angle, then emails the admin. Light single-post
   * version; could be extended to multiple posts once we have a get_recent_posts.
   */
  private static function weekly_content_brief() {
    return [
      'id'           => 'weekly_content_brief',
      'name'         => __( 'Weekly Content Brief', 'meow-workflow' ),
      'category'     => 'ai',
      'description'  => __( 'Every Monday at 8am, picks one recent post and emails you an AI-written brief with what worked and what to write next.', 'meow-workflow' ),
      'tag'          => __( 'Schedule', 'meow-workflow' ),
      'requires'     => [ 'ai-engine', 'wordpress' ],
      'trigger_type' => 'schedule',
      'trigger_config' => [ 'recurrence' => 'weekly', 'day' => 'monday', 'time' => '08:00' ],
      'definition'   => [
        'nodes' => [
          self::trigger_node( __( 'Every Monday at 08:00', 'meow-workflow' ), 'schedule' ),
          self::action_node( 'recent', 280, 'wordpress', 'get_recent_posts', [
            'post_type' => 'post',
            'count'     => 5,
            'days'      => 7,
          ] ),
          self::action_node( 'brief', 560, 'ai-engine', 'text', [
            'message' => __( "Here are the posts published on \"{{ site_name }}\" in the last 7 days:\n\n{{ recent.summary }}\n\nWrite a short brief for the site owner:\n1. A one-line recap of the week's publishing.\n2. Which topic looks most promising to expand, and why.\n3. Two concrete follow-up post titles to consider.\n\nKeep it under 150 words, plain text, no greeting line.", 'meow-workflow' ),
          ] ),
          self::action_node( 'email', 840, 'core', 'send_email', [
            'to'      => '{{ admin_email }}',
            'subject' => __( 'Weekly content brief — {{ today_human }}', 'meow-workflow' ),
            'body'    => "Your weekly brief for {{ site_name }}:\n\n{{ brief.text }}\n\n— sent by Meow Workflow",
          ] ),
        ],
        'edges' => self::chain_edges( [ 'trigger', 'recent', 'brief', 'email' ] ),
      ],
    ];
  }

  /**
   * 5. Daily SEO report
   *
   * Requires SEO Engine. Schedule: daily 09:00. Pulls the site-wide SEO
   * summary (scored posts breakdown + monthly analytics), asks AI Engine to
   * narrate it, emails the admin.
   */
  private static function daily_seo_report() {
    return [
      'id'           => 'daily_seo_report',
      'name'         => __( 'Daily SEO Report', 'meow-workflow' ),
      'category'     => 'seo',
      'description'  => __( 'Every morning, the SEO Engine site snapshot is summarised by AI Engine and emailed to you. Score breakdown, monthly visitors, top posts, and one concrete suggestion.', 'meow-workflow' ),
      'tag'          => __( 'SEO', 'meow-workflow' ),
      'requires'     => [ 'ai-engine', 'seo-engine' ],
      'trigger_type' => 'schedule',
      'trigger_config' => [ 'recurrence' => 'daily', 'time' => '09:00' ],
      'definition'   => [
        'nodes' => [
          self::trigger_node( __( 'Every morning at 09:00', 'meow-workflow' ), 'schedule' ),
          self::action_node( 'summary', 280, 'seo-engine', 'get_site_summary', [] ),
          self::action_node( 'narrate', 560, 'ai-engine', 'text', [
            'message' => __( "You are an SEO assistant emailing the site owner each morning. Be concise — 4 short paragraphs max.\n\n1. Site health: \"{{ summary.summary_text }}\".\n2. Top posts this month: {{ summary.top_posts }}.\n3. One concrete action they could take today, based on the numbers.\n4. Sign off with a single sentence of encouragement.\n\nPlain text, no markdown, no greeting line.", 'meow-workflow' ),
          ] ),
          self::action_node( 'email', 840, 'core', 'send_email', [
            'to'      => '{{ admin_email }}',
            'subject' => __( 'Daily SEO report — {{ summary.summary_text }}', 'meow-workflow' ),
            'body'    => '{{ narrate.text }}',
          ] ),
        ],
        'edges' => self::chain_edges( [ 'trigger', 'summary', 'narrate', 'email' ] ),
      ],
    ];
  }

  /* ------------------------------------------------------------------ */
  /* Builders                                                            */
  /* ------------------------------------------------------------------ */

  private static function trigger_node( $label, $kind ) {
    return [
      'id'       => 'trigger',
      'type'     => 'trigger',
      'position' => [ 'x' => 320, 'y' => 60 ],
      'data'     => [
        'kind'        => 'trigger',
        'integration' => 'core',
        'trigger'     => $kind,
        'label'       => $label,
        'params'      => [],
      ],
    ];
  }

  /**
   * $offset is in legacy horizontal units (multiples of 280); each step maps
   * onto one row of the vertical flow the editor now uses (top-down).
   */
  private static function action_node( $id, $offset, $integration, $action, $params ) {
    $step = max( 1, (int) round( $offset / 280 ) );
    return [
      'id'       => $id,
      'type'     => 'action',
      'position' => [ 'x' => 320, 'y' => 60 + $step * 150 ],
      'data'     => [
        'kind'        => 'action',
        'integration' => $integration,
        'action'      => $action,
        'params'      => $params,
      ],
    ];
  }

  private static function chain_edges( $ids ) {
    $edges = [];
    for ( $i = 1; $i < count( $ids ); $i++ ) {
      $edges[] = [
        'id'     => 'e_' . $ids[ $i - 1 ] . '_' . $ids[ $i ],
        'source' => $ids[ $i - 1 ],
        'target' => $ids[ $i ],
      ];
    }
    return $edges;
  }
}
