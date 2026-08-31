<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * WordPress integration — get/create posts, fetch users, attachments,
 * options. No external plugin required; uses WP core APIs directly.
 */
class Meow_MWFLOW_Integrations_Wordpress {

  public function __construct( $core ) {
    add_filter( 'mwflow_register_integration', [ $this, 'register' ] );
  }

  public function register( $integrations ) {
    $integrations[] = Meow_MWFLOW_SDK::integration( [
      'id'          => 'wordpress',
      'name'        => __( 'WordPress', 'meow-workflow' ),
      'description' => __( 'Posts, users, media, options.', 'meow-workflow' ),
      'color'       => '#21759b',
      'version'     => get_bloginfo( 'version' ),
      'triggers'    => [
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'on_post_published',
          'name'    => __( 'When a post is published', 'meow-workflow' ),
          'hook'    => 'publish_post',
          'args'    => 2,
          'outputs' => [
            Meow_MWFLOW_SDK::output( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id' ),
          ],
          'mapper'  => [ __CLASS__, 'map_first_arg_post' ],
        ] ),
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'on_post_updated',
          'name'    => __( 'When a post is updated', 'meow-workflow' ),
          'hook'    => 'post_updated',
          'args'    => 3,
          'outputs' => [
            Meow_MWFLOW_SDK::output( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id' ),
          ],
          'mapper'  => [ __CLASS__, 'map_first_arg_post' ],
        ] ),
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'on_post_trashed',
          'name'    => __( 'When a post is trashed', 'meow-workflow' ),
          'hook'    => 'trashed_post',
          'args'    => 1,
          'outputs' => [
            Meow_MWFLOW_SDK::output( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id' ),
          ],
          'mapper'  => [ __CLASS__, 'map_first_arg_post' ],
        ] ),
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'on_user_registered',
          'name'    => __( 'When a user registers', 'meow-workflow' ),
          'hook'    => 'user_register',
          'outputs' => [
            Meow_MWFLOW_SDK::output( 'user_id', __( 'User ID', 'meow-workflow' ), 'user_id' ),
          ],
          'mapper'  => [ __CLASS__, 'map_user_register' ],
        ] ),
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'on_comment_posted',
          'name'    => __( 'When a comment is posted', 'meow-workflow' ),
          'hook'    => 'comment_post',
          'args'    => 2,
          'outputs' => [
            Meow_MWFLOW_SDK::output( 'comment_id', __( 'Comment ID', 'meow-workflow' ), 'number' ),
          ],
          'mapper'  => [ __CLASS__, 'map_comment_post' ],
        ] ),
        Meow_MWFLOW_SDK::trigger( [
          'id'      => 'on_comment_approved',
          'name'    => __( 'When a comment is approved', 'meow-workflow' ),
          'hook'    => 'comment_unapproved_to_approved',
          'args'    => 1,
          'outputs' => [
            Meow_MWFLOW_SDK::output( 'comment_id', __( 'Comment ID', 'meow-workflow' ), 'number' ),
          ],
          'mapper'  => [ __CLASS__, 'map_comment_object' ],
        ] ),
      ],
      'actions' => [
        Meow_MWFLOW_SDK::action( [
          'id'          => 'get_post',
          'name'        => __( 'Get post', 'meow-workflow' ),
          'description' => __( 'Load a post by its ID so later steps can use its title, content, author and more.', 'meow-workflow' ),
          'icon'        => 'file',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id', [ 'required' => true ] ),
          ],
          'outputs'     => [
            Meow_MWFLOW_SDK::output( 'id', __( 'ID', 'meow-workflow' ), 'post_id' ),
            Meow_MWFLOW_SDK::output( 'title', __( 'Title', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'content', __( 'Content', 'meow-workflow' ), 'longtext' ),
            Meow_MWFLOW_SDK::output( 'excerpt', __( 'Excerpt', 'meow-workflow' ), 'longtext' ),
            Meow_MWFLOW_SDK::output( 'url', __( 'Permalink', 'meow-workflow' ), 'url' ),
            Meow_MWFLOW_SDK::output( 'author_id', __( 'Author ID', 'meow-workflow' ), 'user_id' ),
            Meow_MWFLOW_SDK::output( 'featured_image_id', __( 'Featured image ID', 'meow-workflow' ), 'attachment_id' ),
            Meow_MWFLOW_SDK::output( 'featured_image_url', __( 'Featured image URL', 'meow-workflow' ), 'url' ),
            Meow_MWFLOW_SDK::output( 'categories', __( 'Categories (names)', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'date', __( 'Published date', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'edit_url', __( 'Edit link (wp-admin)', 'meow-workflow' ), 'url' ),
          ],
          'callback'    => [ __CLASS__, 'callback_get_post' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'get_random_post',
          'name'        => __( 'Get random post', 'meow-workflow' ),
          'description' => __( 'Pick one random published post — great for "post of the day" or social-sharing flows.', 'meow-workflow' ),
          'icon'        => 'shuffle',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'post_type', __( 'Post type', 'meow-workflow' ), 'string', [ 'default' => 'post', 'placeholder' => 'post', 'description' => __( 'The post type slug, e.g. post, page, or product.', 'meow-workflow' ) ] ),
            Meow_MWFLOW_SDK::input( 'has_image', __( 'Only posts with a featured image', 'meow-workflow' ), 'boolean', [ 'default' => false ] ),
          ],
          'outputs'     => [
            Meow_MWFLOW_SDK::output( 'id', __( 'ID', 'meow-workflow' ), 'post_id' ),
            Meow_MWFLOW_SDK::output( 'title', __( 'Title', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'content', __( 'Content', 'meow-workflow' ), 'longtext' ),
            Meow_MWFLOW_SDK::output( 'url', __( 'Permalink', 'meow-workflow' ), 'url' ),
            Meow_MWFLOW_SDK::output( 'featured_image_id', __( 'Featured image ID', 'meow-workflow' ), 'attachment_id' ),
            Meow_MWFLOW_SDK::output( 'featured_image_url', __( 'Featured image URL', 'meow-workflow' ), 'url' ),
          ],
          'callback'    => [ __CLASS__, 'callback_get_random_post' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'create_post',
          'name'        => __( 'Create post', 'meow-workflow' ),
          'description' => __( 'Create a new post or page. Starts as a draft by default so nothing goes live by accident.', 'meow-workflow' ),
          'icon'        => 'plus-square',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'title', __( 'Title', 'meow-workflow' ), 'string', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'content', __( 'Content', 'meow-workflow' ), 'longtext' ),
            Meow_MWFLOW_SDK::input( 'status', __( 'Status', 'meow-workflow' ), 'select', [
              'options' => [
                [ 'value' => 'draft',   'label' => __( 'Draft', 'meow-workflow' ) ],
                [ 'value' => 'publish', 'label' => __( 'Published', 'meow-workflow' ) ],
                [ 'value' => 'pending', 'label' => __( 'Pending review', 'meow-workflow' ) ],
                [ 'value' => 'private', 'label' => __( 'Private', 'meow-workflow' ) ],
              ],
              'default' => 'draft',
            ] ),
            Meow_MWFLOW_SDK::input( 'post_type', __( 'Post type', 'meow-workflow' ), 'string', [ 'default' => 'post', 'placeholder' => 'post', 'description' => __( 'The post type slug, e.g. post, page, or product.', 'meow-workflow' ), 'advanced' => true ] ),
          ],
          'outputs'     => [
            Meow_MWFLOW_SDK::output( 'id', __( 'New post ID', 'meow-workflow' ), 'post_id' ),
            Meow_MWFLOW_SDK::output( 'url', __( 'Edit URL', 'meow-workflow' ), 'url' ),
          ],
          'callback'    => [ __CLASS__, 'callback_create_post' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'update_post',
          'name'        => __( 'Update post', 'meow-workflow' ),
          'description' => __( 'Change fields on an existing post. Leave a field empty to keep its current value.', 'meow-workflow' ),
          'icon'        => 'edit',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'title', __( 'Title', 'meow-workflow' ), 'string', [ 'description' => __( 'Leave empty to keep the current title.', 'meow-workflow' ) ] ),
            Meow_MWFLOW_SDK::input( 'content', __( 'Content', 'meow-workflow' ), 'longtext', [ 'description' => __( 'Leave empty to keep the current content.', 'meow-workflow' ) ] ),
            Meow_MWFLOW_SDK::input( 'excerpt', __( 'Excerpt', 'meow-workflow' ), 'longtext', [ 'description' => __( 'Leave empty to keep the current excerpt.', 'meow-workflow' ) ] ),
            Meow_MWFLOW_SDK::input( 'status', __( 'Status', 'meow-workflow' ), 'select', [
              'empty_label' => __( '— No change —', 'meow-workflow' ),
              'description' => __( 'Leave on "No change" to keep the current status.', 'meow-workflow' ),
              'options'     => [
                [ 'value' => 'publish', 'label' => __( 'Published', 'meow-workflow' ) ],
                [ 'value' => 'draft',   'label' => __( 'Draft', 'meow-workflow' ) ],
                [ 'value' => 'pending', 'label' => __( 'Pending review', 'meow-workflow' ) ],
                [ 'value' => 'private', 'label' => __( 'Private', 'meow-workflow' ) ],
              ],
            ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'id', __( 'Post ID', 'meow-workflow' ), 'post_id' ) ],
          'callback'    => [ __CLASS__, 'callback_update_post' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'get_user',
          'name'        => __( 'Get user', 'meow-workflow' ),
          'description' => __( 'Load a user by ID to use their name or email in later steps (e.g. a welcome email).', 'meow-workflow' ),
          'icon'        => 'user',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'user_id', __( 'User ID', 'meow-workflow' ), 'user_id', [ 'required' => true ] ),
          ],
          'outputs'     => [
            Meow_MWFLOW_SDK::output( 'id', __( 'ID', 'meow-workflow' ), 'user_id' ),
            Meow_MWFLOW_SDK::output( 'login', __( 'Login', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'email', __( 'Email', 'meow-workflow' ), 'email' ),
            Meow_MWFLOW_SDK::output( 'display_name', __( 'Display name', 'meow-workflow' ), 'string' ),
          ],
          'callback'    => [ __CLASS__, 'callback_get_user' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'get_comment',
          'name'        => __( 'Get comment', 'meow-workflow' ),
          'description' => __( 'Load a comment by its ID — its text, author and status — e.g. to moderate it with AI.', 'meow-workflow' ),
          'icon'        => 'message-square',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'comment_id', __( 'Comment ID', 'meow-workflow' ), 'number', [ 'required' => true, 'placeholder' => '{{ trigger.comment_id }}' ] ),
          ],
          'outputs'     => [
            Meow_MWFLOW_SDK::output( 'id', __( 'ID', 'meow-workflow' ), 'number' ),
            Meow_MWFLOW_SDK::output( 'content', __( 'Comment text', 'meow-workflow' ), 'longtext' ),
            Meow_MWFLOW_SDK::output( 'author', __( 'Author name', 'meow-workflow' ), 'string' ),
            Meow_MWFLOW_SDK::output( 'author_email', __( 'Author email', 'meow-workflow' ), 'email' ),
            Meow_MWFLOW_SDK::output( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id' ),
            Meow_MWFLOW_SDK::output( 'status', __( 'Status', 'meow-workflow' ), 'string' ),
          ],
          'callback'    => [ __CLASS__, 'callback_get_comment' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'set_comment_status',
          'name'        => __( 'Set comment status', 'meow-workflow' ),
          'description' => __( 'Approve, hold, spam, or trash a comment — the action half of a moderation flow.', 'meow-workflow' ),
          'icon'        => 'message-square',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'comment_id', __( 'Comment ID', 'meow-workflow' ), 'number', [ 'required' => true, 'placeholder' => '{{ trigger.comment_id }}' ] ),
            Meow_MWFLOW_SDK::input( 'status', __( 'New status', 'meow-workflow' ), 'select', [
              'required' => true,
              'options'  => [
                [ 'value' => 'approve', 'label' => __( 'Approved', 'meow-workflow' ) ],
                [ 'value' => 'hold',    'label' => __( 'Pending (hold)', 'meow-workflow' ) ],
                [ 'value' => 'spam',    'label' => __( 'Spam', 'meow-workflow' ) ],
                [ 'value' => 'trash',   'label' => __( 'Trash', 'meow-workflow' ) ],
              ],
            ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'updated', __( 'Updated', 'meow-workflow' ), 'boolean' ) ],
          'callback'    => [ __CLASS__, 'callback_set_comment_status' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'get_attachment_url',
          'name'        => __( 'Get attachment URL', 'meow-workflow' ),
          'description' => __( 'Get the direct URL of a media item by its attachment ID.', 'meow-workflow' ),
          'icon'        => 'image',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'attachment_id', __( 'Attachment ID', 'meow-workflow' ), 'attachment_id', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'size', __( 'Size', 'meow-workflow' ), 'select', [
              'default'     => 'full',
              'description' => __( 'Which generated image size to link to.', 'meow-workflow' ),
              'options'     => [
                [ 'value' => 'full',      'label' => __( 'Full size', 'meow-workflow' ) ],
                [ 'value' => 'large',     'label' => __( 'Large', 'meow-workflow' ) ],
                [ 'value' => 'medium',    'label' => __( 'Medium', 'meow-workflow' ) ],
                [ 'value' => 'thumbnail', 'label' => __( 'Thumbnail', 'meow-workflow' ) ],
              ],
            ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'url', __( 'URL', 'meow-workflow' ), 'url' ) ],
          'callback'    => [ __CLASS__, 'callback_get_attachment_url' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'get_recent_posts',
          'name'        => __( 'Get recent posts', 'meow-workflow' ),
          'description' => __( 'Fetch the latest posts as a list — handy for a digest email. The "summary" output is a ready-to-paste bulleted list.', 'meow-workflow' ),
          'icon'        => 'list',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'post_type', __( 'Post type', 'meow-workflow' ), 'string', [ 'default' => 'post' ] ),
            Meow_MWFLOW_SDK::input( 'count', __( 'How many', 'meow-workflow' ), 'number', [ 'default' => 5, 'min' => 1, 'max' => 20 ] ),
            Meow_MWFLOW_SDK::input( 'days', __( 'From the last N days (0 = any)', 'meow-workflow' ), 'number', [ 'default' => 0, 'min' => 0, 'max' => 365 ] ),
          ],
          'outputs'     => [
            Meow_MWFLOW_SDK::output( 'count', __( 'Number found', 'meow-workflow' ), 'number' ),
            Meow_MWFLOW_SDK::output( 'posts', __( 'Posts', 'meow-workflow' ), 'json' ),
            Meow_MWFLOW_SDK::output( 'summary', __( 'Bulleted summary', 'meow-workflow' ), 'longtext' ),
          ],
          'callback'    => [ __CLASS__, 'callback_get_recent_posts' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'set_post_status',
          'name'        => __( 'Change post status', 'meow-workflow' ),
          'description' => __( 'Publish, unpublish (draft), or trash a post.', 'meow-workflow' ),
          'icon'        => 'toggle-left',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'status', __( 'New status', 'meow-workflow' ), 'select', [
              'required' => true,
              'options'  => [
                [ 'value' => 'publish', 'label' => __( 'Published', 'meow-workflow' ) ],
                [ 'value' => 'draft',   'label' => __( 'Draft (unpublished)', 'meow-workflow' ) ],
                [ 'value' => 'pending', 'label' => __( 'Pending review', 'meow-workflow' ) ],
                [ 'value' => 'private', 'label' => __( 'Private', 'meow-workflow' ) ],
                [ 'value' => 'trash',   'label' => __( 'Trash', 'meow-workflow' ) ],
              ],
            ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'updated', __( 'Updated', 'meow-workflow' ), 'boolean' ) ],
          'callback'    => [ __CLASS__, 'callback_set_post_status' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'set_custom_field',
          'name'        => __( 'Set custom field', 'meow-workflow' ),
          'description' => __( 'Save a custom field (post meta) on a post.', 'meow-workflow' ),
          'icon'        => 'tag',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'key', __( 'Field name', 'meow-workflow' ), 'string', [ 'required' => true, 'placeholder' => 'my_field' ] ),
            Meow_MWFLOW_SDK::input( 'value', __( 'Value', 'meow-workflow' ), 'longtext' ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'updated', __( 'Updated', 'meow-workflow' ), 'boolean' ) ],
          'callback'    => [ __CLASS__, 'callback_set_custom_field' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'set_post_terms',
          'name'        => __( 'Add categories or tags', 'meow-workflow' ),
          'description' => __( 'Add one or more categories or tags to a post. Separate multiple with commas.', 'meow-workflow' ),
          'icon'        => 'tags',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'taxonomy', __( 'Type', 'meow-workflow' ), 'select', [
              'default' => 'category',
              'options' => [
                [ 'value' => 'category', 'label' => __( 'Categories', 'meow-workflow' ) ],
                [ 'value' => 'post_tag', 'label' => __( 'Tags', 'meow-workflow' ) ],
              ],
            ] ),
            Meow_MWFLOW_SDK::input( 'terms', __( 'Names (comma-separated)', 'meow-workflow' ), 'string', [ 'required' => true, 'placeholder' => 'News, Featured' ] ),
            Meow_MWFLOW_SDK::input( 'append', __( 'Keep existing terms too', 'meow-workflow' ), 'boolean', [ 'default' => true ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'updated', __( 'Updated', 'meow-workflow' ), 'boolean' ) ],
          'callback'    => [ __CLASS__, 'callback_set_post_terms' ],
        ] ),
        Meow_MWFLOW_SDK::action( [
          'id'          => 'set_featured_image',
          'name'        => __( 'Set featured image', 'meow-workflow' ),
          'description' => __( 'Set a post\'s featured image (thumbnail) from a media attachment ID.', 'meow-workflow' ),
          'icon'        => 'image-plus',
          'inputs'      => [
            Meow_MWFLOW_SDK::input( 'post_id', __( 'Post ID', 'meow-workflow' ), 'post_id', [ 'required' => true ] ),
            Meow_MWFLOW_SDK::input( 'attachment_id', __( 'Attachment ID', 'meow-workflow' ), 'attachment_id', [ 'required' => true ] ),
          ],
          'outputs'     => [ Meow_MWFLOW_SDK::output( 'updated', __( 'Updated', 'meow-workflow' ), 'boolean' ) ],
          'callback'    => [ __CLASS__, 'callback_set_featured_image' ],
        ] ),
      ],
    ] );
    return $integrations;
  }

  /* ------------------------------------------------------------------ */
  /* Trigger mappers — turn raw hook args into friendly named fields.    */
  /* Each receives the full $incoming args array from the WP hook.       */
  /* ------------------------------------------------------------------ */

  public static function map_first_arg_post( $args ) {
    return [ 'post_id' => (int) ( $args[0] ?? 0 ) ];
  }

  public static function map_user_register( $args ) {
    return [ 'user_id' => (int) ( $args[0] ?? 0 ) ];
  }

  public static function map_comment_post( $args ) {
    return [ 'comment_id' => (int) ( $args[0] ?? 0 ) ];
  }

  public static function map_comment_object( $args ) {
    $comment = $args[0] ?? null;
    $id = is_object( $comment ) ? (int) $comment->comment_ID : (int) $comment;
    return [ 'comment_id' => $id ];
  }

  public static function callback_get_post( $inputs ) {
    $post = get_post( (int) $inputs['post_id'] );
    if ( !$post ) { throw new Exception( 'Post not found.' ); }
    return self::format_post( $post );
  }

  public static function callback_get_random_post( $inputs ) {
    $args = [
      'post_type'      => $inputs['post_type'] ?? 'post',
      'post_status'    => 'publish',
      'posts_per_page' => 1,
      'orderby'        => 'rand',
    ];
    if ( !empty( $inputs['has_image'] ) ) {
      $args['meta_query'] = [ [ 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ] ];
    }
    $posts = get_posts( $args );
    if ( empty( $posts ) ) { throw new Exception( 'No matching post found.' ); }
    return self::format_post( $posts[0] );
  }

  public static function callback_create_post( $inputs ) {
    $id = wp_insert_post( [
      'post_title'   => $inputs['title'] ?? '',
      'post_content' => $inputs['content'] ?? '',
      'post_status'  => $inputs['status'] ?? 'draft',
      'post_type'    => $inputs['post_type'] ?? 'post',
    ], true );
    if ( is_wp_error( $id ) ) { throw new Exception( $id->get_error_message() ); }
    return [ 'id' => (int) $id, 'url' => get_edit_post_link( $id, '' ) ];
  }

  public static function callback_update_post( $inputs ) {
    $data = [ 'ID' => (int) $inputs['post_id'] ];
    $map = [
      'title'   => 'post_title',
      'content' => 'post_content',
      'excerpt' => 'post_excerpt',
      'status'  => 'post_status',
    ];
    foreach ( $map as $in => $key ) {
      if ( isset( $inputs[ $in ] ) && $inputs[ $in ] !== '' ) { $data[ $key ] = $inputs[ $in ]; }
    }
    $id = wp_update_post( $data, true );
    if ( is_wp_error( $id ) ) { throw new Exception( $id->get_error_message() ); }
    return [ 'id' => (int) $id ];
  }

  public static function callback_get_user( $inputs ) {
    $user = get_user_by( 'id', (int) $inputs['user_id'] );
    if ( !$user ) { throw new Exception( 'User not found.' ); }
    return [
      'id'           => $user->ID,
      'login'        => $user->user_login,
      'email'        => $user->user_email,
      'display_name' => $user->display_name,
    ];
  }

  public static function callback_get_comment( $inputs ) {
    $comment = get_comment( (int) $inputs['comment_id'] );
    if ( !$comment ) { throw new Exception( 'Comment not found.' ); }
    $approved = (string) $comment->comment_approved;
    $status = $approved === '1' ? 'approved' : ( $approved === '0' ? 'pending' : $approved );
    return [
      'id'           => (int) $comment->comment_ID,
      'content'      => $comment->comment_content,
      'author'       => $comment->comment_author,
      'author_email' => $comment->comment_author_email,
      'post_id'      => (int) $comment->comment_post_ID,
      'status'       => $status,
    ];
  }

  public static function callback_set_comment_status( $inputs ) {
    $id = (int) $inputs['comment_id'];
    $status = in_array( $inputs['status'] ?? '', [ 'approve', 'hold', 'spam', 'trash' ], true )
      ? $inputs['status'] : 'hold';
    $res = wp_set_comment_status( $id, $status === 'trash' ? 'trash' : $status, true );
    if ( is_wp_error( $res ) ) { throw new Exception( $res->get_error_message() ); }
    return [ 'updated' => true ];
  }

  public static function callback_get_attachment_url( $inputs ) {
    $url = wp_get_attachment_image_url( (int) $inputs['attachment_id'], $inputs['size'] ?? 'full' );
    if ( !$url ) {
      $url = wp_get_attachment_url( (int) $inputs['attachment_id'] );
    }
    if ( !$url ) { throw new Exception( 'Attachment not found.' ); }
    return [ 'url' => $url ];
  }

  public static function callback_get_recent_posts( $inputs ) {
    $args = [
      'post_type'      => $inputs['post_type'] ?: 'post',
      'post_status'    => 'publish',
      'posts_per_page' => max( 1, min( 20, (int) ( $inputs['count'] ?? 5 ) ) ),
      'orderby'        => 'date',
      'order'          => 'DESC',
    ];
    $days = (int) ( $inputs['days'] ?? 0 );
    if ( $days > 0 ) {
      $args['date_query'] = [ [ 'after' => $days . ' days ago' ] ];
    }
    $posts = get_posts( $args );
    $list = [];
    $lines = [];
    foreach ( $posts as $post ) {
      $list[] = [
        'id'    => $post->ID,
        'title' => $post->post_title,
        'url'   => get_permalink( $post ),
      ];
      $lines[] = '- ' . $post->post_title . ' (' . get_permalink( $post ) . ')';
    }
    return [
      'count'   => count( $list ),
      'posts'   => $list,
      'summary' => implode( "\n", $lines ),
    ];
  }

  public static function callback_set_post_status( $inputs ) {
    $id = (int) $inputs['post_id'];
    $status = $inputs['status'] ?? 'draft';
    if ( $status === 'trash' ) {
      $result = wp_trash_post( $id );
      if ( !$result ) { throw new Exception( 'Could not trash the post.' ); }
      return [ 'updated' => true ];
    }
    $res = wp_update_post( [ 'ID' => $id, 'post_status' => $status ], true );
    if ( is_wp_error( $res ) ) { throw new Exception( $res->get_error_message() ); }
    return [ 'updated' => true ];
  }

  public static function callback_set_custom_field( $inputs ) {
    $id = (int) $inputs['post_id'];
    // Meta keys allow more than sanitize_key() permits (case, some symbols),
    // so just trim and guard against an empty key.
    $key = trim( (string) ( $inputs['key'] ?? '' ) );
    if ( $key === '' ) { throw new Exception( 'A field name is required.' ); }
    update_post_meta( $id, $key, $inputs['value'] ?? '' );
    return [ 'updated' => true ];
  }

  public static function callback_set_post_terms( $inputs ) {
    $id = (int) $inputs['post_id'];
    $taxonomy = in_array( $inputs['taxonomy'] ?? 'category', [ 'category', 'post_tag' ], true )
      ? $inputs['taxonomy'] : 'category';
    $names = array_filter( array_map( 'trim', explode( ',', (string) ( $inputs['terms'] ?? '' ) ) ) );
    if ( empty( $names ) ) { throw new Exception( 'At least one term name is required.' ); }
    $append = !empty( $inputs['append'] );
    $res = wp_set_object_terms( $id, $names, $taxonomy, $append );
    if ( is_wp_error( $res ) ) { throw new Exception( $res->get_error_message() ); }
    return [ 'updated' => true ];
  }

  public static function callback_set_featured_image( $inputs ) {
    $ok = set_post_thumbnail( (int) $inputs['post_id'], (int) $inputs['attachment_id'] );
    return [ 'updated' => (bool) $ok ];
  }

  private static function format_post( $post ) {
    $thumb_id = (int) get_post_thumbnail_id( $post );
    $terms = wp_get_post_terms( $post->ID, 'category', [ 'fields' => 'names' ] );
    $categories = is_wp_error( $terms ) ? [] : $terms;
    return [
      'id'                 => $post->ID,
      'title'              => $post->post_title,
      'content'            => $post->post_content,
      'excerpt'            => $post->post_excerpt,
      'url'                => get_permalink( $post ),
      'author_id'          => (int) $post->post_author,
      'featured_image_id'  => $thumb_id,
      'featured_image_url' => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'full' ) : '',
      'categories'         => implode( ', ', $categories ),
      'date'               => get_the_date( '', $post ),
      'edit_url'           => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
    ];
  }
}
