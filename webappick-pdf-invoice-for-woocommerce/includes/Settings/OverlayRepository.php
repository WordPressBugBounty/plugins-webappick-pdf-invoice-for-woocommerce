<?php
/**
 * Settings repository with in-memory overrides.
 *
 * @package Challan
 */

namespace Challan\Settings;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Wraps the normal Repository with a transient overlay of typed values —
 * used by the live-preview endpoint to render documents with the UI's
 * UNSAVED settings. Nothing is ever persisted through this class; only
 * registered keys are honored.
 */
class OverlayRepository extends Repository {

	/**
	 * Typed override values keyed by option name.
	 *
	 * @var array<string,mixed>
	 */
	private $overrides;

	/**
	 * Settings registry, kept to look up field types for overrides.
	 *
	 * @var Registry
	 */
	private $overlay_registry;

	/**
	 * Wrap the repository with a set of unsaved override values.
	 *
	 * @param Registry            $registry  Settings registry.
	 * @param array<string,mixed> $overrides Typed values from the UI.
	 */
	public function __construct( Registry $registry, array $overrides ) {
		parent::__construct( $registry );
		$this->overlay_registry = $registry;
		$this->overrides        = $overrides;
	}

	/**
	 * Typed value of one setting, preferring the unsaved override when the
	 * key is registered and overridden.
	 *
	 * @param string $key Option name.
	 * @return mixed
	 */
	public function get( $key ) {
		if ( array_key_exists( $key, $this->overrides ) && $this->overlay_registry->has( $key ) ) {
			return $this->normalize( $key, $this->overrides[ $key ] );
		}

		return parent::get( $key );
	}

	/**
	 * Coerce an override into the same typed shape parent::get() returns.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Override value.
	 * @return mixed
	 */
	private function normalize( $key, $value ) {
		$field = $this->overlay_registry->get( $key );

		switch ( $field['type'] ) {
			case 'bool':
				return ( true === $value || '1' === $value || 1 === $value );
			case 'int':
				return (int) $value;
			case 'array':
				return is_array( $value ) ? $value : array();
			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}
}
