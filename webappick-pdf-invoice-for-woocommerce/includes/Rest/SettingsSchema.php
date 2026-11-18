<?php
/**
 * Response schema for settings values, generated from the settings registry.
 *
 * @package Challan
 * @since   4.0.0
 */

namespace Challan\Rest;

use Challan\Settings\Registry;
use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Builds one schema property per registered setting, so the published
 * schema always matches the settings list (Free's Definitions plus any
 * fields added through `challan_settings_fields`, e.g. by Challan Pro).
 *
 * Values are described as the API returns them (Repository::get()), not as
 * they are stored: toggles are booleans, numbers are integers.
 *
 * @since 4.0.0
 */
final class SettingsSchema {

	/**
	 * Error codes Repository::save() reports per key.
	 *
	 * @since 4.0.0
	 *
	 * @var string[]
	 */
	const SAVE_ERRORS = array( 'unknown_setting', 'pro_required', 'invalid_value' );

	/**
	 * One schema property per registered setting.
	 *
	 * @since 4.0.0
	 *
	 * @param Registry $registry Settings registry.
	 * @return array<string,array<string,mixed>>
	 */
	public static function properties( Registry $registry ) {
		$properties = array();

		foreach ( $registry->all() as $key => $field ) {
			$properties[ $key ] = self::property( $key, $field );
		}

		return $properties;
	}

	/**
	 * Schema of one setting value.
	 *
	 * Custom keys (ignored by JSON Schema validators):
	 * - `x-challan-type`: the Challan field type (bool, int, string,
	 *   textarea, css, array). The JSON type alone can't tell a textarea
	 *   from a one-line field.
	 * - `x-challan-section`: the settings section the field belongs to.
	 * - `x-challan-pro`: whether saving it needs Challan Pro.
	 * - `x-challan-choices`: the values PATCH accepts, for select fields. Not
	 *   an `enum`, because a value stored by an older version may be
	 *   returned even when it is not in the list.
	 *
	 * @since 4.0.0
	 *
	 * @param string              $key   Option name.
	 * @param array<string,mixed> $field Field definition.
	 * @return array<string,mixed>
	 */
	public static function property( $key, array $field ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'string';

		$property = array(
			'description'       => sprintf(
				/* translators: %s: option name. */
				__( 'Stored in the wp_options row "%s".', 'webappick-pdf-invoice-for-woocommerce' ),
				$key
			),
			'type'              => self::json_type( $type ),
			'default'           => Repository::cast( array( 'type' => $type ), isset( $field['default'] ) ? $field['default'] : '' ),
			'context'           => array( 'edit' ),
			'x-challan-type'    => $type,
			'x-challan-section' => isset( $field['section'] ) ? (string) $field['section'] : 'general',
			'x-challan-pro'     => ! empty( $field['pro'] ),
		);

		if ( isset( $field['choices'] ) ) {
			$property['x-challan-choices'] = array_values( array_map( 'strval', (array) $field['choices'] ) );
		}

		return $property;
	}

	/**
	 * JSON type for a Challan field type.
	 *
	 * `array` settings may be lists or key => value maps, and PHP sends an
	 * empty one as `[]`, so they are described as array or object.
	 *
	 * @since 4.0.0
	 *
	 * @param string $type Challan field type.
	 * @return string|string[]
	 */
	public static function json_type( $type ) {
		switch ( $type ) {
			case 'bool':
				return 'boolean';
			case 'int':
				return 'integer';
			case 'array':
				return array( 'array', 'object' );
			default: // string, textarea, css.
				return 'string';
		}
	}

	/**
	 * Schema of the per-key save errors (`errors` in a PATCH response and
	 * `data.errors` in `challan_rest_invalid_settings`).
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public static function save_errors() {
		return array(
			'description'          => __( 'PATCH only. Keys that were not saved, with the reason: "unknown_setting" (not a registered setting), "pro_required" (a Pro setting while Challan Pro is inactive) or "invalid_value" (the value has the wrong type or is not an accepted choice). Sent as [] when there are none.', 'webappick-pdf-invoice-for-woocommerce' ),
			'type'                 => array( 'object', 'array' ),
			'additionalProperties' => array(
				'type' => 'string',
				'enum' => self::SAVE_ERRORS,
			),
			'context'              => array( 'edit' ),
			'readonly'             => true,
		);
	}
}
