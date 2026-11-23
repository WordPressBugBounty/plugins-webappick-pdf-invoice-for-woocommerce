<?php
/**
 * Base class for document HTML renderers.
 *
 * @package Challan
 */

namespace Challan\Documents;

use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Shared wrap/merge logic. Subclasses provide the per-order body; this
 * class wraps it with the template stylesheet and joins multiple orders
 * with page breaks (the `pageBreak` class from the bundled CSS).
 */
abstract class AbstractRenderer {

	/**
	 * Settings the document is rendered with.
	 *
	 * @var Repository
	 */
	protected $settings;

	/**
	 * The v1 pro page-number block for the repeating footer — the exact
	 * markup v1 emitted inside htmlpagefooter when the page-number
	 * option covers this document type. Empty on free installs (the
	 * options are pro-registered, so reads return null).
	 *
	 * @return string
	 */
	protected function page_number_block() {
		if ( ! $this->settings->get( 'wpifw_enable_page_number' ) ) {
			return '';
		}
		$docs = (array) $this->settings->get( 'wpifw_add_page_number' );
		if ( ! in_array( $this->type(), $docs, true ) ) {
			return '';
		}
		return '<p><div align="center">{PAGENO}</div></p>';
	}

	/**
	 * Create the renderer.
	 *
	 * @param Repository $settings Settings repository.
	 */
	public function __construct( Repository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether the order being rendered prints right-to-left (the RTL
	 * setting or an RTL document language — see Language::is_rtl()).
	 *
	 * @var bool|null Null outside render().
	 */
	protected $rtl = null;

	/**
	 * Right-to-left for the current document.
	 *
	 * @return bool
	 */
	protected function is_rtl() {
		return null === $this->rtl ? (bool) $this->settings->get( 'wpifw_rtl' ) : $this->rtl;
	}

	/**
	 * Document type id.
	 *
	 * @return string
	 */
	abstract public function type();

	/**
	 * Per-order document body HTML (escaped by the implementation).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	abstract protected function body( \WC_Order $order );

	/**
	 * Render one order's document.
	 *
	 * @param \WC_Order $order Order.
	 * @return string Full HTML.
	 */
	public function render( \WC_Order $order ) {
		$this->rtl = Language::is_rtl( $this->settings, $order );
		$switched  = Language::begin( $this->settings, $order );
		try {
			return $this->wrap( $this->body( $order ) );
		} finally {
			if ( $switched ) {
				Language::end();
			}
		}
	}

	/**
	 * Render several orders into one document with page breaks.
	 *
	 * @param \WC_Order[] $orders Orders.
	 * @return string Full HTML.
	 */
	public function render_many( array $orders ) {
		$bodies    = array();
		$first_rtl = null;
		foreach ( $orders as $order ) {
			$this->rtl = Language::is_rtl( $this->settings, $order );
			// One stylesheet per document: the first order's direction.
			$first_rtl = null === $first_rtl ? $this->rtl : $first_rtl;
			$switched  = Language::begin( $this->settings, $order );
			try {
				$bodies[] = $this->body( $order );
			} finally {
				if ( $switched ) {
					Language::end();
				}
			}
		}
		$this->rtl = (bool) $first_rtl;

		return $this->wrap( implode( '<div class="pageBreak"></div>', $bodies ) );
	}

	/**
	 * Stylesheet for this document type.
	 *
	 * @return string CSS.
	 */
	protected function css() {
		$css = Templates::css(
			(string) $this->settings->get( 'wpifw_templateid' ),
			$this->type(),
			$this->is_rtl()
		);

		// Preserved v1 action: hook-provided CSS, captured from output.
		ob_start();
		do_action( 'woo_invoice_custom_style', $this->type() );
		$css .= "\n" . wp_strip_all_tags( (string) ob_get_clean() );

		if ( 'invoice' === $this->type() ) {
			$css .= "\n" . wp_strip_all_tags( (string) $this->settings->get( 'wpifw_custom_css' ) );
		} elseif ( ! challan_is_pro_active() ) {
			// v1 Free styled every document with the one Custom CSS field.
			if ( in_array( $this->type(), array( 'packing_slip', 'delivery_address' ), true ) ) {
				$css .= "\n" . wp_strip_all_tags( (string) $this->settings->get( 'wpifw_custom_css' ) );
			}
		} elseif ( 'packing_slip' === $this->type() ) {
			// v1 pro packing-slip CSS (registered by Challan Pro).
			$css .= "\n" . wp_strip_all_tags( (string) $this->settings->get( 'wpifw_packing_slip_css' ) );
		}

		return $css;
	}

	/**
	 * Line item title as safe HTML for the products table.
	 *
	 * Version 1 printed titles raw, so inline markup that stores keep in product
	 * or line-item names (e.g. "Name<span> - </span>Variant") rendered.
	 * v2 escaped them, which printed the tags as text. This keeps a small
	 * inline allowlist and escapes everything else.
	 *
	 * With a title length limit the tags are dropped first and the plain
	 * text is cut, so a tag or an entity is never cut in half.
	 *
	 * @param string $title  Item title (may contain HTML).
	 * @param int    $length Maximum characters; 0 = no limit.
	 * @return array{0: string, 1: bool} Safe HTML, and whether it was truncated.
	 */
	protected function item_title_html( $title, $length = 0 ) {
		$title  = (string) $title;
		$length = (int) $length;

		if ( $length > 0 && function_exists( 'mb_substr' ) ) {
			$plain = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' );
			if ( mb_strlen( $plain ) > $length ) {
				return array( esc_html( mb_substr( $plain, 0, $length ) ), true );
			}
		}

		$inline = array(
			'class' => true,
			'style' => true,
		);

		/**
		 * Filters the HTML tags allowed in item titles on documents.
		 *
		 * @since 4.0.1
		 *
		 * @param array  $allowed wp_kses() allowlist.
		 * @param string $type    Document type.
		 */
		$allowed = (array) apply_filters(
			'challan_item_title_allowed_html',
			array(
				'span'   => $inline,
				'br'     => array(),
				'b'      => $inline,
				'strong' => $inline,
				'i'      => $inline,
				'em'     => $inline,
				'small'  => $inline,
				'sub'    => array(),
				'sup'    => array(),
			),
			$this->type()
		);

		return array( wp_kses( $title, $allowed ), false );
	}

	/**
	 * Line item title as printed in the products table.
	 *
	 * The v1 "Remove Invalid Characters" (remove_invalid_characters_from_title)
	 * keeps only letters, numbers, spaces, '-' and '_' — the fix for mPDF's
	 * "Invalid input characters" error. Combining marks are kept too (v1
	 * dropped them, which broke Bengali / Arabic / Hindi names), and invalid
	 * UTF-8 bytes are stripped first so the title is never blanked.
	 *
	 * @param \WC_Order_Item $item Line item.
	 * @return string
	 */
	protected function item_name( $item ) {
		$name = (string) $item->get_name();

		if ( $this->settings->get( 'remove_invalid_characters_from_title' ) ) {
			$name    = wp_check_invalid_utf8( $name, true );
			$cleaned = preg_replace( '/[^\p{L}\p{M}\p{N}\s\-_]/u', '', $name );
			$name    = null === $cleaned ? $name : $cleaned;
		}

		return $name;
	}

	/**
	 * The document <title>, through the preserved v1 title filters.
	 *
	 * @return string
	 */
	protected function document_title() {
		if ( 'packing_slip' === $this->type() ) {
			return (string) apply_filters( 'woo_invoice_packing_slip_title', 'Packing Slip' );
		}
		if ( 'delivery_address' === $this->type() ) {
			return (string) apply_filters( 'woo_invoice_delivery_address_title', 'Delivery Address' );
		}
		return (string) apply_filters( 'woo_invoice_invoice_title', 'Invoice' );
	}

	/**
	 * Wrap body markup into a full HTML document.
	 *
	 * @param string $inner Body markup.
	 * @return string
	 */
	protected function wrap( $inner ) {
		return '<!DOCTYPE html><html><head><meta charset="utf-8">'
			. '<title>' . esc_html( $this->document_title() ) . '</title>'
			. '<style>' . $this->css() . '</style></head><body>'
			. $inner
			. '</body></html>';
	}
}
