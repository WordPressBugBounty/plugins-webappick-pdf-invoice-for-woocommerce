<?php
/**
 * Document template registry and stylesheet resolution.
 *
 * @package Challan
 */

namespace Challan\Documents;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Invoice templates. A template is a stylesheet over the shared document
 * markup (v1 model, preserved). Selection is stored in the legacy
 * `wpifw_templateid` option. There are four templates: Simple (free) and
 * Modern, Classic and POS receipt (pro; their stylesheets ship in Challan
 * Pro, which resolves them via the `challan_template_css_file` filter).
 * Any stored id that is not one of them (v1's invoice-1…9, unknown, none),
 * or a pro template while Challan Pro is inactive, prints as Simple.
 */
final class Templates {

	/**
	 * Default template (fresh installs, and stores moved off v1 templates).
	 */
	const DEFAULT_TEMPLATE = 'challan-simple';

	/**
	 * Registered templates.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		$templates = array(
			// Simple ships free; Modern, Classic and POS receipt are pro —
			// their stylesheets live in Challan Pro.
			'challan-simple'  => array(
				'label' => __( 'Simple', 'webappick-pdf-invoice-for-woocommerce' ),
				'desc'  => __( 'Quiet type, no rules or fills. Reads well on plain paper.', 'webappick-pdf-invoice-for-woocommerce' ),
				'pro'   => false,
			),
			'challan-modern'  => array(
				'label' => __( 'Modern', 'webappick-pdf-invoice-for-woocommerce' ),
				'desc'  => __( 'Boxed table, tinted totals, codes beside the addresses.', 'webappick-pdf-invoice-for-woocommerce' ),
				'pro'   => true,
			),
			'challan-classic' => array(
				'label' => __( 'Classic', 'webappick-pdf-invoice-for-woocommerce' ),
				'desc'  => __( 'Serif, centered masthead, black table header.', 'webappick-pdf-invoice-for-woocommerce' ),
				'pro'   => true,
				// Typeface the stylesheet sets (mPDF family name).
				'font'  => 'dejavuserif',
			),
			'challan-receipt' => array(
				'label'   => __( 'POS receipt', 'webappick-pdf-invoice-for-woocommerce' ),
				'desc'    => __( '80 mm thermal roll. Monospace, no colour, barcode at the foot.', 'webappick-pdf-invoice-for-woocommerce' ),
				'pro'     => true,
				// Thermal roll: 80 mm wide, page height fitted to the content.
				'paper'   => array(
					'width'       => 80,
					'auto_height' => true,
				),
				// One column: header and address segments stack vertically.
				'stacked' => true,
				'font'    => 'dejavusansmono',
			),

		);

		/**
		 * Filter the registered document templates.
		 *
		 * @param array<string,array<string,mixed>> $templates Templates.
		 */
		return apply_filters( 'challan_templates', $templates );
	}

	/**
	 * The template a document actually prints with: the stored one when it
	 * is registered and usable (pro templates only while Challan Pro is
	 * active), else Simple.
	 *
	 * @param string $template_id Stored template id (`wpifw_templateid`).
	 * @return string
	 */
	public static function effective( $template_id ) {
		$all         = self::all();
		$template_id = (string) $template_id;
		if ( ! isset( $all[ $template_id ] ) || ( ! empty( $all[ $template_id ]['pro'] ) && ! challan_is_pro_active() ) ) {
			return self::DEFAULT_TEMPLATE;
		}

		return $template_id;
	}

	/**
	 * Paper a template prints on, when it brings its own (e.g. the POS
	 * receipt's 80 mm roll). Pro templates only count while Challan Pro
	 * is active — without it the document prints as Simple on the
	 * regular paper setting.
	 *
	 * @param string $template_id Template id (`wpifw_templateid`).
	 * @return array{width:float,auto_height:bool}|null
	 */
	public static function paper( $template_id ) {
		$template = self::usable( $template_id );
		if ( null === $template || ! isset( $template['paper'] ) || ! is_array( $template['paper'] ) ) {
			return null;
		}

		$paper = $template['paper'];

		return array(
			'width'       => (float) $paper['width'],
			'auto_height' => ! empty( $paper['auto_height'] ),
		);
	}

	/**
	 * Typeface the template's stylesheet sets (mPDF family name), if any —
	 * it takes precedence over the font-family setting, so the browser
	 * preview must use it too.
	 *
	 * @param string $template_id Template id (`wpifw_templateid`).
	 * @return string Empty when the template follows the font setting.
	 */
	public static function font( $template_id ) {
		$template = self::usable( $template_id );

		return null !== $template && ! empty( $template['font'] ) ? (string) $template['font'] : '';
	}

	/**
	 * Whether the template lays the header and address segments out in
	 * one column (one segment per row) instead of side by side.
	 *
	 * @param string $template_id Template id (`wpifw_templateid`).
	 * @return bool
	 */
	public static function stacked( $template_id ) {
		$template = self::usable( $template_id );

		return null !== $template && ! empty( $template['stacked'] );
	}

	/**
	 * The registry entry of the template that prints (see effective()).
	 *
	 * @param string $template_id Stored template id.
	 * @return array<string,mixed>|null
	 */
	private static function usable( $template_id ) {
		$all = self::all();
		$id  = self::effective( $template_id );

		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Stylesheet contents for a document render.
	 *
	 * @param string $template_id Selected template id.
	 * @param string $type        Document type id.
	 * @param bool   $rtl         Whether RTL rendering is on.
	 * @return string CSS.
	 */
	public static function css( $template_id, $type, $rtl = false ) {
		$dir = CHALLAN_PATH . 'templates/css/';

		/** Preserved v1 filter. */
		$default = apply_filters( 'woo_invoice_default_css_file', $dir . 'default.css' );
		$files   = array( $default );

		if ( 'packing_slip' === $type ) {
			$files[] = $dir . 'packing_slip.css';
		} elseif ( 'invoice' === $type ) {
			$template_id = self::effective( $template_id );
			$file        = $dir . sanitize_file_name( $template_id ) . '.css';

			/**
			 * Filter the template stylesheet path — lets Challan Pro
			 * resolve its own template files. Both the v1 filter name and
			 * the v2 alias are applied.
			 *
			 * @param string $file        Absolute stylesheet path.
			 * @param string $template_id Template id.
			 */
			$file = apply_filters( 'woo_invoice_template_css_file', $file );
			$file = apply_filters( 'challan_template_css_file', $file, $template_id );

			if ( file_exists( $file ) ) {
				$files[] = $file;
			}
		}

		if ( $rtl ) {
			$files[] = $dir . 'invoice-rtl.css';
		}

		$css = '';
		foreach ( $files as $file ) {
			if ( file_exists( $file ) ) {
				$css .= file_get_contents( $file ) . "\n"; // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local bundled files only.
			}
		}

		return $css;
	}
}
