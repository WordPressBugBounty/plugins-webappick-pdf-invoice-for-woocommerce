<?php
/**
 * Settings registry — the single source of truth for which options exist.
 *
 * @package Challan
 */

namespace Challan\Settings;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Holds all registered settings fields. The registry doubles as the write
 * allowlist for the REST API: a key not present here can never be saved.
 *
 * Extensions (Challan Pro) add fields via the `challan_settings_fields`
 * filter; fields flagged 'pro' => true are only writable while
 * `challan_is_pro_active()` (filter `challan_pro_active`) reports true.
 */
class Registry {

	/**
	 * Registered fields keyed by option name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $fields;

	/**
	 * Build the field registry from the core definitions plus any fields
	 * added through the `challan_settings_fields` filter.
	 */
	public function __construct() {
		/**
		 * Filter the settings field registry.
		 *
		 * Extension point for Challan Pro (and future add-ons) to register
		 * additional fields. Every entry MUST use the exact wp_options key
		 * it stores to, with 'type', 'default', 'section', and 'pro' => true.
		 *
		 * @param array<string,array<string,mixed>> $fields Field definitions.
		 */
		$this->fields = apply_filters( 'challan_settings_fields', Definitions::fields() );
	}

	/**
	 * Whether a key is registered.
	 *
	 * @param string $key Option name.
	 * @return bool
	 */
	public function has( $key ) {
		return isset( $this->fields[ $key ] );
	}

	/**
	 * A single field definition.
	 *
	 * @param string $key Option name.
	 * @return array<string,mixed>|null
	 */
	public function get( $key ) {
		return isset( $this->fields[ $key ] ) ? $this->fields[ $key ] : null;
	}

	/**
	 * All field definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all() {
		return $this->fields;
	}

	/**
	 * Whether a field requires an active pro plugin to be written.
	 *
	 * @param string $key Option name.
	 * @return bool
	 */
	public function is_pro( $key ) {
		return ! empty( $this->fields[ $key ]['pro'] );
	}

	/**
	 * Schema payload for the admin UI: sections in order, fields per section.
	 *
	 * @return array<string,mixed>
	 */
	public function schema() {
		$sections = array();
		foreach ( Definitions::sections() as $section ) {
			$sections[ $section ] = array();
		}

		foreach ( $this->fields as $key => $field ) {
			$section = isset( $field['section'] ) ? $field['section'] : 'general';
			if ( ! isset( $sections[ $section ] ) ) {
				$sections[ $section ] = array(); // Extension-defined section.
			}
			$sections[ $section ][ $key ] = array(
				'type'    => $field['type'],
				'default' => $field['default'],
				'pro'     => ! empty( $field['pro'] ),
			);
		}

		return $sections;
	}
}
