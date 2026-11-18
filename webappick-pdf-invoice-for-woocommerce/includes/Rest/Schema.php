<?php
/**
 * Shared REST schema pieces for the challan/v1 controllers.
 *
 * @package Challan
 * @since   4.0.0
 */

namespace Challan\Rest;

use Challan\Documents\DocumentTypes;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Request-argument and response-schema building blocks used by every
 * challan/v1 controller, Free and Pro.
 *
 * COMPATIBILITY CONTRACT: each argument helper accepts exactly the values
 * the endpoints accept today. Where today's code is lenient (comma lists
 * parsed with absint / sanitize_key, page numbers cast with absint), the
 * helper keeps that leniency through an explicit `sanitize_callback`:
 * WordPress core only enforces `type` when an argument has a `type` and no
 * `sanitize_callback` (it then defaults to `rest_parse_request_arg`).
 *
 * WordPress core validates only these `format` values: `date-time`,
 * `email`, `hex-color`, `ip` and `uuid`. There is no `date` format, so dates
 * are described in text and not checked by the schema.
 *
 * @since 4.0.0
 */
final class Schema {

	/**
	 * JSON Schema draft used by WordPress core REST schemas.
	 *
	 * @since 4.0.0
	 *
	 * @var string
	 */
	const DRAFT = 'http://json-schema.org/draft-04/schema#';

	/**
	 * Path argument for a WooCommerce order ID (`(?P<id>\d+)` routes).
	 *
	 * Core enforces the integer type and the minimum, so `0` is rejected
	 * with `rest_invalid_param` (400), as the hand-written check did before.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public static function order_id() {
		return array(
			'description' => __( 'WooCommerce order ID.', 'webappick-pdf-invoice-for-woocommerce' ),
			'type'        => 'integer',
			'minimum'     => 1,
			'required'    => true,
		);
	}

	/**
	 * Document type argument.
	 *
	 * The `enum` lists every type registered through `challan_document_types`
	 * when routes are registered (Pro adds its types before that). The
	 * validate callback then also requires the type to be available right
	 * now: a Pro type is refused while Pro is inactive.
	 *
	 * @since 4.0.0
	 *
	 * @param string|null $default_type Default type, or null for no default.
	 * @return array<string,mixed>
	 */
	public static function document_type( $default_type = 'invoice' ) {
		$arg = array(
			'description'       => __( 'Document type.', 'webappick-pdf-invoice-for-woocommerce' ),
			'type'              => 'string',
			'enum'              => array_keys( DocumentTypes::all() ),
			'validate_callback' => array( __CLASS__, 'validate_document_type' ),
		);

		if ( null !== $default_type ) {
			$arg['default'] = $default_type;
		}

		return $arg;
	}

	/**
	 * Validate a document type: schema check first, then availability.
	 *
	 * @since 4.0.0
	 *
	 * @param mixed            $value   Submitted value.
	 * @param \WP_REST_Request $request Request.
	 * @param string           $param   Parameter name.
	 * @return true|\WP_Error|false True when valid; a schema error; false
	 *                              when the type is registered but not
	 *                              available (core reports it as invalid).
	 */
	public static function validate_document_type( $value, $request, $param ) {
		$valid = rest_validate_request_arg( $value, $request, $param );
		if ( true !== $valid ) {
			return $valid;
		}

		return DocumentTypes::is_available( $value );
	}

	/**
	 * Comma-separated order IDs.
	 *
	 * Callbacks split the value on commas and pass each entry through
	 * absint(); entries that become 0 are dropped, as before.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public static function id_list() {
		return array(
			'description' => __( 'Comma-separated order IDs, for example "12,15". Each entry is converted with absint(); entries that become 0 are ignored.', 'webappick-pdf-invoice-for-woocommerce' ),
			'type'        => 'string',
			'default'     => '',
		);
	}

	/**
	 * Comma-separated order statuses.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public static function status_list() {
		return array(
			'description' => __( 'Comma-separated order statuses, for example "completed,processing". Each entry is passed through sanitize_key().', 'webappick-pdf-invoice-for-woocommerce' ),
			'type'        => 'string',
			'default'     => '',
		);
	}

	/**
	 * A date bound for an order query.
	 *
	 * @since 4.0.0
	 *
	 * @param string $description Translated description.
	 * @return array<string,mixed>
	 */
	public static function date( $description ) {
		return array(
			'description' => $description,
			'type'        => 'string',
			'default'     => '',
		);
	}

	/**
	 * 1-based batch number for bulk downloads.
	 *
	 * Uses `absint` as before: a value that is not a number becomes 0, which
	 * the callbacks treat as page 1. Because an explicit sanitize callback
	 * is set, core does not reject non-integer input.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public static function page() {
		return array(
			'description'       => __( 'Batch number, starting at 1. Values below 1 are treated as 1.', 'webappick-pdf-invoice-for-woocommerce' ),
			'type'              => 'integer',
			'default'           => 1,
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * The order filters shared by the bulk endpoints.
	 *
	 * Used by `GET /documents/bulk`, `GET /documents/bulk-count` and Pro's
	 * `GET /documents/bulk-csv`. The customer and payment filters need an
	 * active Challan Pro licence; the callbacks return `challan_pro_filter`
	 * (403) otherwise. Route-specific arguments (`type`, `output`, `page`,
	 * `fields`) are added by each controller.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function bulk_filters() {
		return array(
			'after'       => self::date( __( 'Only orders created on or after this date (YYYY-MM-DD). Passed to the WooCommerce order query.', 'webappick-pdf-invoice-for-woocommerce' ) ),
			'before'      => self::date( __( 'Only orders created on or before this date (YYYY-MM-DD). Passed to the WooCommerce order query.', 'webappick-pdf-invoice-for-woocommerce' ) ),
			'statuses'    => self::status_list(),
			'ids'         => self::id_list(),
			'email'       => array(
				'description' => __( 'Only orders with this billing email. Needs an active Challan Pro licence.', 'webappick-pdf-invoice-for-woocommerce' ),
				'type'        => 'string',
				'default'     => '',
			),
			'customer_id' => array(
				'description'       => __( 'Only orders of this customer (user ID). 0 or less means no filter. Needs an active Challan Pro licence.', 'webappick-pdf-invoice-for-woocommerce' ),
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => array( __CLASS__, 'to_int' ),
			),
			'customers'   => array(
				'description' => __( 'Comma-separated customers (user IDs or billing emails); orders of all of them are combined. Needs an active Challan Pro licence.', 'webappick-pdf-invoice-for-woocommerce' ),
				'type'        => 'string',
				'default'     => '',
			),
			'payment'     => array(
				'description' => __( 'Only orders paid with this payment method ID. Empty or "all" means no filter. Needs an active Challan Pro licence.', 'webappick-pdf-invoice-for-woocommerce' ),
				'type'        => 'string',
				'default'     => '',
			),
		);
	}

	/**
	 * Sanitize callback that casts to int, like `(int) $value`.
	 *
	 * One difference: a non-scalar value (e.g. `customer_id[]=5`) becomes 0,
	 * meaning "no filter"; `(int)` of a non-empty array was 1.
	 *
	 * PHP's intval() can't be used directly: core passes three arguments
	 * to a sanitize callback, and built-in functions reject extra
	 * arguments on PHP 8.
	 *
	 * @since 4.0.0
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public static function to_int( $value ) {
		return is_scalar( $value ) ? (int) $value : 0;
	}

	/**
	 * Sanitize callback that casts to string, like `(string) $value`.
	 *
	 * Keeps a parameter lenient (a number is accepted and becomes text)
	 * while it is still documented as a string. A non-scalar value becomes
	 * an empty string; `(string)` of an array was "Array" with a warning.
	 *
	 * @since 4.0.0
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function to_string( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Response schema of a WordPress REST error (a `WP_Error` sent as JSON).
	 *
	 * WordPress takes the HTTP status from the error's data. An error created
	 * without data is sent with `"data": null` and status 500.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string,mixed>
	 */
	public static function error() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'code'    => array(
					'description' => __( 'Machine-readable error code, for example "challan_rest_forbidden".', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'string',
				),
				'message' => array(
					'description' => __( 'Human-readable error message.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'        => 'string',
				),
				'data'    => array(
					'description'          => __( 'Error details: the HTTP status, and for some errors more keys. Null when the error was created without data; the HTTP status is then 500.', 'webappick-pdf-invoice-for-woocommerce' ),
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'status' => array(
							'description' => __( 'HTTP status code.', 'webappick-pdf-invoice-for-woocommerce' ),
							'type'        => 'integer',
						),
					),
					'additionalProperties' => true,
				),
			),
		);
	}

	/**
	 * Wrap properties in a top-level object schema.
	 *
	 * @since 4.0.0
	 *
	 * @param string              $title      Schema title, without the "challan-" prefix.
	 * @param array<string,mixed> $properties Property schemas.
	 * @return array<string,mixed>
	 */
	public static function object( $title, array $properties ) {
		return array(
			'$schema'    => self::DRAFT,
			'title'      => 'challan-' . $title,
			'type'       => 'object',
			'properties' => $properties,
		);
	}

	/**
	 * Schema of an endpoint that streams a file on success.
	 *
	 * Such an endpoint sends the file (PDF, ZIP, HTML or CSV) and exits,
	 * so a successful response has no JSON body. Any JSON it returns is an
	 * error, so the schema describes the error object, and `description`
	 * says what the file is.
	 *
	 * @since 4.0.0
	 *
	 * @param string $title       Schema title, without the "challan-" prefix.
	 * @param string $description Translated description of the file sent on success.
	 * @return array<string,mixed>
	 */
	public static function file( $title, $description ) {
		return array_merge(
			array(
				'$schema'     => self::DRAFT,
				'title'       => 'challan-' . $title,
				'description' => $description,
			),
			self::error()
		);
	}
}
