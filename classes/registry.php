<?php

if ( !defined( 'ABSPATH' ) ) { exit; }

/**
 * Collects integrations registered via the `mwflow_register_integration` filter,
 * validates each entry against the schema below, and exposes the result to the
 * UI as a single normalised array.
 *
 * Schema (per integration):
 *   id           string  - unique slug
 *   name         string
 *   description  string
 *   logo_url     string  - optional URL or data URI for the icon
 *   color        string  - hex; used for the node header tint
 *   version      string  - optional
 *   actions      array of action schemas
 *   triggers     array of trigger schemas (optional)
 *
 * Action schema:
 *   id           string  - unique within integration
 *   name         string
 *   description  string
 *   icon         string  - optional lucide-react icon name
 *   inputs       array of input fields
 *   outputs      array of output fields
 *   callback     callable - invoked at runtime with resolved input values
 *
 * Trigger schema:
 *   id           string
 *   name         string
 *   description  string
 *   hook         string - WordPress action this trigger listens to
 *   priority     int    - optional
 *   args         int    - optional
 *   outputs      array of output fields
 *   mapper       callable - optional; turns WP hook args into the outputs map
 *
 * Field schema (inputs/outputs):
 *   id           string
 *   name         string
 *   type         string - see Meow_MWFLOW_SDK::TYPES
 *   required     bool   - inputs only; default false
 *   default      mixed  - inputs only
 *   options      array  - for type=select
 */
class Meow_MWFLOW_Registry {
  private $core;
  private $integrations = null;

  public function __construct( $core ) {
    $this->core = $core;
  }

  public function get_integrations() {
    if ( $this->integrations !== null ) {
      return $this->integrations;
    }
    $collected = apply_filters( 'mwflow_register_integration', [] );
    $this->integrations = $this->normalise( $collected );
    return $this->integrations;
  }

  public function get_integration( $id ) {
    $all = $this->get_integrations();
    return $all[ $id ] ?? null;
  }

  public function get_action( $integration_id, $action_id ) {
    $integration = $this->get_integration( $integration_id );
    if ( !$integration ) { return null; }
    foreach ( $integration['actions'] as $action ) {
      if ( $action['id'] === $action_id ) {
        return $action + [ '_integration' => $integration_id ];
      }
    }
    return null;
  }

  public function get_trigger( $integration_id, $trigger_id ) {
    $integration = $this->get_integration( $integration_id );
    if ( !$integration ) { return null; }
    foreach ( ( $integration['triggers'] ?? [] ) as $trigger ) {
      if ( $trigger['id'] === $trigger_id ) {
        return $trigger + [ '_integration' => $integration_id ];
      }
    }
    return null;
  }

  /**
   * Find a registered trigger by the WordPress hook it binds to. Used by the
   * hook trigger handler to apply the trigger's `mapper` so named outputs
   * (post_id, comment_id, …) are available instead of just arg0/arg1.
   * Returns the trigger schema (including its `mapper` callback) or null.
   */
  public function find_trigger_by_hook( $hook ) {
    foreach ( $this->get_integrations() as $integration_id => $integration ) {
      foreach ( ( $integration['triggers'] ?? [] ) as $trigger ) {
        if ( ( $trigger['hook'] ?? '' ) === $hook ) {
          return $trigger + [ '_integration' => $integration_id ];
        }
      }
    }
    return null;
  }

  /**
   * Strip non-serialisable fields (callbacks) before sending to the React UI.
   */
  public function get_integrations_for_ui() {
    $integrations = $this->get_integrations();
    $out = [];
    foreach ( $integrations as $id => $integration ) {
      $clean = $integration;
      unset( $clean['actions'], $clean['triggers'] );
      $clean['actions']  = array_map( [ $this, 'clean_action_for_ui' ], $integration['actions'] ?? [] );
      $clean['triggers'] = array_map( [ $this, 'clean_trigger_for_ui' ], $integration['triggers'] ?? [] );
      $out[] = $clean;
    }
    return $out;
  }

  private function clean_action_for_ui( $action ) {
    unset( $action['callback'] );
    return $action;
  }

  private function clean_trigger_for_ui( $trigger ) {
    unset( $trigger['mapper'] );
    return $trigger;
  }

  private function normalise( $raw ) {
    $out = [];
    if ( !is_array( $raw ) ) { return $out; }
    foreach ( $raw as $key => $integration ) {
      if ( !is_array( $integration ) || empty( $integration['id'] ) ) {
        continue;
      }
      $id = sanitize_key( $integration['id'] );
      $out[ $id ] = [
        'id'          => $id,
        'name'        => (string) ( $integration['name'] ?? $id ),
        'description' => (string) ( $integration['description'] ?? '' ),
        'logo_url'    => (string) ( $integration['logo_url'] ?? '' ),
        'color'       => (string) ( $integration['color'] ?? '#3b82f6' ),
        'version'     => (string) ( $integration['version'] ?? '' ),
        'actions'     => $this->normalise_actions( $integration['actions'] ?? [] ),
        'triggers'    => $this->normalise_triggers( $integration['triggers'] ?? [] ),
      ];
    }
    return $out;
  }

  private function normalise_actions( $actions ) {
    $out = [];
    foreach ( (array) $actions as $action ) {
      if ( empty( $action['id'] ) || empty( $action['callback'] ) ) { continue; }
      $out[] = [
        'id'          => sanitize_key( $action['id'] ),
        'name'        => (string) ( $action['name'] ?? $action['id'] ),
        'description' => (string) ( $action['description'] ?? '' ),
        'icon'        => (string) ( $action['icon'] ?? '' ),
        'inputs'      => $this->normalise_fields( $action['inputs'] ?? [], true ),
        'outputs'     => $this->normalise_fields( $action['outputs'] ?? [], false ),
        'callback'    => $action['callback'],
      ];
    }
    return $out;
  }

  private function normalise_triggers( $triggers ) {
    $out = [];
    foreach ( (array) $triggers as $trigger ) {
      if ( empty( $trigger['id'] ) || empty( $trigger['hook'] ) ) { continue; }
      $out[] = [
        'id'          => sanitize_key( $trigger['id'] ),
        'name'        => (string) ( $trigger['name'] ?? $trigger['id'] ),
        'description' => (string) ( $trigger['description'] ?? '' ),
        'hook'        => (string) $trigger['hook'],
        'priority'    => (int) ( $trigger['priority'] ?? 10 ),
        'args'        => (int) ( $trigger['args'] ?? 1 ),
        'outputs'     => $this->normalise_fields( $trigger['outputs'] ?? [], false ),
        'mapper'      => $trigger['mapper'] ?? null,
      ];
    }
    return $out;
  }

  private function normalise_fields( $fields, $is_input ) {
    $out = [];
    foreach ( (array) $fields as $field ) {
      if ( empty( $field['id'] ) ) { continue; }
      $normalised = [
        'id'   => sanitize_key( $field['id'] ),
        'name' => (string) ( $field['name'] ?? $field['id'] ),
        'type' => Meow_MWFLOW_SDK::sanitise_type( $field['type'] ?? 'string' ),
      ];
      if ( $is_input ) {
        $normalised['required'] = !empty( $field['required'] );
        if ( array_key_exists( 'default', $field ) ) {
          $normalised['default'] = $field['default'];
        }
        if ( !empty( $field['options'] ) && is_array( $field['options'] ) ) {
          $normalised['options'] = array_values( $field['options'] );
        }
        if ( !empty( $field['description'] ) ) {
          $normalised['description'] = (string) $field['description'];
        }
        foreach ( [ 'min', 'max', 'step', 'multiline', 'placeholder', 'options_source', 'advanced', 'empty_label' ] as $extra ) {
          if ( array_key_exists( $extra, $field ) ) {
            $normalised[ $extra ] = $field[ $extra ];
          }
        }
      }
      $out[] = $normalised;
    }
    return $out;
  }
}
