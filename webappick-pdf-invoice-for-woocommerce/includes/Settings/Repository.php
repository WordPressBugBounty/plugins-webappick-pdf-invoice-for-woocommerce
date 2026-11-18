<?php
/**
 * Settings repository — typed read/write over the legacy wp_options rows.
 *
 * @package Challan
 */

namespace Challan\Settings;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Reads and writes settings through the Registry.
 *
 * COMPATIBILITY CONTRACT: values are stored in the exact v1 formats —
 * toggles as '1'/'0' strings (legacy hidden-input pattern), scalars as
 * strings, lists as arrays. The API layer exposes typed values (real
 * booleans/ints); this class converts at the boundary.
 */
class Repository {

	/**
	 * Settings registry used as the field definition source and allowlist.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Create a repository backed by the given registry.
	 *
	 * @param Registry $registry Settings registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Image settings v1 Pro stored as file path + id: key => id option.
	 */
	const LEGACY_PATH_IDS = array(
		'wpifw_signature_attachment_id'              => 'wpifw_signature_attachment_image_id',
		'wpifw_invoice_background_attachment_id'     => 'wpifw_invoice_background_attachment_image_id',
		'wpifw_packingslip_background_attachment_id' => 'wpifw_packingslip_background_attachment_image_id',
	);

	/**
	 * Typed value of one setting.
	 *
	 * @param string $key Option name.
	 * @return mixed
	 */
	public function get( $key ) {
		$field = $this->registry->get( $key );
		if ( null === $field ) {
			return null;
		}

		$raw = get_option( $key, $field['default'] );

		// v1 Pro stored these images' FILE PATH under the key and the
		// attachment id beside it; read the id from the companion option.
		if ( isset( self::LEGACY_PATH_IDS[ $key ] ) && is_string( $raw ) && '' !== $raw && ! is_numeric( $raw ) ) {
			return (int) get_option( self::LEGACY_PATH_IDS[ $key ], 0 );
		}

		return self::cast( $field, $raw );
	}

	/**
	 * Convert a stored value to the typed value the API exposes.
	 *
	 * Also used for a field's `default`, which is written in storage
	 * format (e.g. '1' for an enabled toggle).
	 *
	 * @since 4.0.0
	 *
	 * @param array<string,mixed> $field Field definition (needs 'type').
	 * @param mixed               $raw   Stored value.
	 * @return bool|int|string|array<mixed>
	 */
	public static function cast( array $field, $raw ) {
		switch ( $field['type'] ) {
			case 'bool':
				// Legacy truthiness: '1' is on; '', '0', false are off.
				return ! empty( $raw ) && '0' !== $raw;
			case 'int':
				return (int) $raw;
			case 'array':
				return is_array( $raw ) ? $raw : ( '' === $raw || false === $raw ? array() : (array) $raw );
			default:
				return is_scalar( $raw ) ? (string) $raw : '';
		}
	}

	/**
	 * Typed values of all registered settings.
	 *
	 * @return array<string,mixed>
	 */
	public function all() {
		$values = array();
		foreach ( array_keys( $this->registry->all() ) as $key ) {
			$values[ $key ] = $this->get( $key );
		}
		return $values;
	}

	/**
	 * Validate, sanitize and persist a partial set of settings.
	 *
	 * @param array<string,mixed> $values     Key => new value.
	 * @param bool                $pro_active Whether pro-flagged fields may be written.
	 * @return array{saved: array<string,mixed>, errors: array<string,string>}
	 */
	public function save( array $values, $pro_active = false ) {
		$saved  = array();
		$errors = array();

		foreach ( $values as $key => $value ) {
			if ( ! $this->registry->has( $key ) ) {
				$errors[ $key ] = 'unknown_setting';
				continue;
			}
			if ( $this->registry->is_pro( $key ) && ! $pro_active ) {
				$errors[ $key ] = 'pro_required';
				continue;
			}

			$stored = $this->to_storage( $this->registry->get( $key ), $value );
			if ( null === $stored ) {
				$errors[ $key ] = 'invalid_value';
				continue;
			}

			update_option( $key, $stored );
			if ( isset( self::LEGACY_PATH_IDS[ $key ] ) ) {
				// Keep v1's format (path under the key, id beside it) so v1
				// code and rollbacks read the same image.
				$file = (int) $stored > 0 ? get_attached_file( (int) $stored ) : '';
				update_option( $key, $file ? $file : '' );
				update_option( self::LEGACY_PATH_IDS[ $key ], (int) $stored > 0 ? (string) (int) $stored : '' );
			}
			if ( 'wpifw_logo_attachment_image_id' === $key ) {
				// v1 kept the logo's file path beside the id (and '' once
				// removed); keep it in step so v1 logic and rollbacks agree.
				$file = (int) $stored > 0 ? get_attached_file( (int) $stored ) : '';
				update_option( 'wpifw_logo_attachment_id', $file ? $file : '' );
			}
			$saved[ $key ] = $this->get( $key );
		}

		return array(
			'saved'  => $saved,
			'errors' => $errors,
		);
	}

	/**
	 * Convert an incoming API value to the exact legacy storage format.
	 *
	 * @param array<string,mixed> $field Field definition.
	 * @param mixed               $value Incoming value.
	 * @return mixed|null Null when the value cannot be coerced safely.
	 */
	private function to_storage( array $field, $value ) {
		// Select-backed settings accept their listed values only.
		if ( isset( $field['choices'] ) && ! in_array( is_scalar( $value ) ? (string) $value : null, (array) $field['choices'], true ) ) {
			return null;
		}

		switch ( $field['type'] ) {
			case 'bool':
				if ( is_bool( $value ) || in_array( $value, array( '1', '0', 1, 0, '' ), true ) ) {
					return ( true === $value || '1' === $value || 1 === $value ) ? '1' : '0';
				}
				return null;

			case 'int':
				if ( is_numeric( $value ) ) {
					return (string) (int) $value;
				}
				return null;

			case 'array':
				if ( ! is_array( $value ) ) {
					return null;
				}
				return $this->sanitize_deep( $value );

			case 'textarea':
				return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : null;

			case 'css':
				return is_scalar( $value ) ? wp_strip_all_tags( (string) $value ) : null;

			default: // string.
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : null;
		}
	}

	/**
	 * Recursively sanitize scalar leaves of an array value.
	 *
	 * @param array<mixed> $value Array value.
	 * @return array<mixed>
	 */
	private function sanitize_deep( array $value ) {
		$clean = array();
		foreach ( $value as $k => $v ) {
			$k = is_string( $k ) ? sanitize_text_field( $k ) : $k;
			if ( is_array( $v ) ) {
				$clean[ $k ] = $this->sanitize_deep( $v );
			} elseif ( is_scalar( $v ) || null === $v ) {
				$clean[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		return $clean;
	}
}
