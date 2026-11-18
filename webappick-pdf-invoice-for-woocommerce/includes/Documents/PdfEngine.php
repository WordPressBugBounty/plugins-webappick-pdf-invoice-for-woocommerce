<?php
/**
 * The mPDF wrapper.
 *
 * @package Challan
 */

namespace Challan\Documents;

use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Renders HTML to PDF via the BUNDLED mPDF (composer vendor/). v1 parity:
 * international script flags, default-font selection (with the bn_BD
 * override), extra-font registration, watermark backgrounds, debug mode,
 * and all v1 public filters.
 *
 * The v1 plugin downloaded the engine at runtime over unverified TLS — v2 ships it
 * inside the plugin (release builds strip unused vendor fonts for size).
 */
class PdfEngine {

	/**
	 * Tall probe page (mm) used to measure an auto-height document.
	 */
	const AUTO_HEIGHT_PROBE = 2000;

	/**
	 * Shortest auto-height page (mm), so tiny receipts still feed cleanly.
	 */
	const AUTO_HEIGHT_MIN = 100;

	/**
	 * Settings repository.
	 *
	 * @var Repository
	 */
	private $settings;

	/**
	 * Measured page height (mm) for the current auto-height render; 0 = probe.
	 *
	 * @var float
	 */
	private $fitted_height = 0.0;

	/**
	 * Create the engine.
	 *
	 * @param Repository $settings Settings repository.
	 */
	public function __construct( Repository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether the bundled engine is available.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( \Mpdf\Mpdf::class ) || file_exists( CHALLAN_PATH . 'vendor/autoload.php' );
	}

	/**
	 * Discard stray output (notices, BOMs, other plugins' echoes) before a
	 * document or download is streamed — it would corrupt the file. Same
	 * approach as WooCommerce's download handler.
	 *
	 * @return void
	 */
	public static function clean_output() {
		$levels = ob_get_level();
		for ( $i = 0; $i < $levels; $i++ ) {
			@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a non-removable buffer must not stop the download.
		}
	}

	/**
	 * Render HTML to PDF bytes.
	 *
	 * @param string $html Full document HTML (with <style>).
	 * @param string $type Document type id.
	 * @return string PDF bytes.
	 * @throws \RuntimeException When the engine is unavailable or rendering fails.
	 */
	public function render( $html, $type = 'invoice' ) {
		/** Preserved v1 Pro action (before a PDF is produced). */
		do_action( 'woo_invoice_restore_previous_language', '' );
		try {
			$pdf = $this->layout( $html, $type )->Output( '', \Mpdf\Output\Destination::STRING_RETURN );
		} catch ( \Mpdf\MpdfException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the chained previous exception, never output.
			throw new \RuntimeException( esc_html( $this->friendly_error( $e ) ), 0, $e );
		}
		/** Preserved v1 Pro action (after a PDF is produced). */
		do_action( 'woo_invoice_after_generate_pdf', $type );

		return $pdf;
	}

	/**
	 * Lay the document out exactly as the PDF is, without producing the
	 * PDF: the HTML view reads the page box and fonts from the result.
	 *
	 * @param string $html  Full document HTML (with <style>).
	 * @param string $type  Document type id.
	 * @param bool   $probe Record the laid-out geometry (LayoutProbe).
	 * @return \Mpdf\Mpdf
	 * @throws \RuntimeException When the engine is unavailable (an
	 *                           \Mpdf\MpdfException from mPDF also propagates).
	 */
	public function layout( $html, $type = 'invoice', $probe = false ) {
		if ( ! class_exists( \Mpdf\Mpdf::class ) ) {
			if ( ! file_exists( CHALLAN_PATH . 'vendor/autoload.php' ) ) {
				throw new \RuntimeException( 'PDF engine is not installed.' );
			}
			require_once CHALLAN_PATH . 'vendor/autoload.php';
		}

		try {
			$this->fitted_height = 0.0;

			// Roll paper (POS receipt): lay the document out once on a tall
			// probe page, then render for real on a page cut to the content.
			$paper = $this->template_paper( $type );
			if ( null !== $paper && $paper['auto_height'] ) {
				$measure             = $this->write( $html, $type );
				$this->fitted_height = max( self::AUTO_HEIGHT_MIN, ceil( $measure->y + $measure->bMargin + 4 ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- mPDF API property.
			}

			return $this->write( $html, $type, $probe );
		} finally {
			$this->fitted_height = 0.0;
		}
	}

	/**
	 * Create an mPDF instance for a document type and write the HTML.
	 *
	 * @param string $html  Full document HTML (with <style>).
	 * @param string $type  Document type id.
	 * @param bool   $probe Use the geometry-recording LayoutProbe.
	 * @return \Mpdf\Mpdf
	 * @throws \Mpdf\MpdfException When mPDF fails.
	 */
	private function write( $html, $type, $probe = false ) {
		$mpdf = $probe ? new LayoutProbe( $this->config( $type ) ) : new \Mpdf\Mpdf( $this->config( $type ) );

		// v1 instance flags for multilingual rendering.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- mPDF API properties.
		$mpdf->autoScriptToLang = true;
		$mpdf->baseScript       = 1;
		$mpdf->autoVietnamese   = true;
		$mpdf->autoArabic       = true;
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mpdf->debug = (bool) $this->settings->get( 'wpifw_pdf_invoice_debug_mode' );

		$watermark = $this->watermark( $type );
		if ( false !== $watermark ) {
			$mpdf->SetWatermarkImage( $watermark['background'], $watermark['opacity'], 'P', 'P' );
			$mpdf->showWatermarkImage = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- mPDF API property.
		}

		// mPDF's debug mode aborts the PDF when error_get_last() holds any
		// warning or notice — including one raised earlier in the request
		// by unrelated code (another plugin, a WooCommerce deprecation).
		// Start clean so debug mode reports mPDF's own problems only (v1
		// did the same since CHL-13).
		error_clear_last();

		$mpdf->WriteHTML( $html );

		return $mpdf;
	}

	/**
	 * Paper the selected template brings for this document type, if any.
	 * Templates style invoices and credit notes only.
	 *
	 * @param string $type Document type id.
	 * @return array{width:float,auto_height:bool}|null
	 */
	private function template_paper( $type ) {
		if ( ! in_array( $type, array( 'invoice', 'credit_note' ), true ) ) {
			return null;
		}

		return Templates::paper( (string) $this->settings->get( 'wpifw_templateid' ) );
	}

	/**
	 * The mPDF constructor config with v1 parity.
	 *
	 * @param string $type Document type id.
	 * @return array<string,mixed>
	 */
	private function config( $type ) {
		$defaults      = ( new \Mpdf\Config\ConfigVariables() )->getDefaults();
		$font_defaults = ( new \Mpdf\Config\FontVariables() )->getDefaults();

		$config = array(
			'mode'                     => 'utf-8',
			'format'                   => $this->paper_format( $type ),
			'tempDir'                  => $this->temp_dir(),
			'allowCJKoverflow'         => true,
			'allow_charset_conversion' => true,
			'autoLangToFont'           => true,
		);

		// ── Font directories: vendor defaults + downloaded pack + v1's dir.
		$font_dirs = $defaults['fontDir'];

		$downloaded = FontDownloader::fonts_dir();
		if ( '' !== $downloaded ) {
			$font_dirs[] = $downloaded;
		}

		$uploads    = wp_upload_dir();
		$legacy_dir = trailingslashit( $uploads['basedir'] ) . 'WOO-INVOICE/WOO-INVOICE-FONTS';
		if ( is_dir( $legacy_dir ) ) {
			$font_dirs[] = $legacy_dir;
		}

		/** Preserved v1 filter. */
		$config['fontDir'] = apply_filters( 'challan_external_font_dirs', $font_dirs );

		// ── Font data: defaults + CJK/Korean extras when present + fixes.
		/** Preserved v1 filter. */
		$fontdata = apply_filters( 'woo_invoice_pdf_font_data', $font_defaults['fontdata'], $type );

		foreach ( array( $downloaded, $legacy_dir ) as $dir ) {
			if ( '' === $dir || ! is_dir( $dir ) ) {
				continue;
			}
			if ( file_exists( $dir . '/Sun-ExtA.ttf' ) ) {
				$fontdata['sun-exta'] = array(
					'R'       => 'Sun-ExtA.ttf',
					'sip-ext' => 'sun-extb', // SIP = Plane 2 Unicode (extension B).
				);
			}
			if ( file_exists( $dir . '/Sun-ExtB.ttf' ) ) {
				$fontdata['sun-extb'] = array( 'R' => 'Sun-ExtB.ttf' );
			}
			if ( file_exists( $dir . '/UnBatang_0613.ttf' ) ) {
				$fontdata['unbatang'] = array( 'R' => 'UnBatang_0613.ttf' );
			}
		}

		// v1 xbriyaz filename normalization (Arabic).
		foreach ( array(
			'R'  => 'XB-Riyaz.ttf',
			'B'  => 'XB-RiyazBd.ttf',
			'I'  => 'XB-RiyazIt.ttf',
			'BI' => 'XB-RiyazBdIt.ttf',
		) as $style => $file ) {
			if ( isset( $fontdata['xbriyaz'][ $style ] ) ) {
				$fontdata['xbriyaz'][ $style ] = $file;
			}
		}

		// ── Custom fonts (Google Fonts downloads / v1 pro uploads), stored
		// in the FROZEN legacy option shape: key => { R, B, I, BI } with
		// bare filenames resolved against the fontDir list above.
		$custom = get_option( 'wpifw_custom_font_list', array() );
		if ( is_array( $custom ) ) {
			foreach ( $custom as $family => $files ) {
				$family = sanitize_key( (string) $family );
				if ( '' === $family || ! is_array( $files ) || empty( $files['R'] ) ) {
					continue;
				}
				$entry = array( 'R' => (string) $files['R'] );
				foreach ( array( 'B', 'I', 'BI' ) as $style ) {
					if ( ! empty( $files[ $style ] ) ) {
						$entry[ $style ] = (string) $files[ $style ];
					}
				}
				$fontdata[ $family ] = $entry;
			}
		}

		$config['fontdata'] = $fontdata;

		// ── Default font: bn_BD forces freeserif (v1), else the setting.
		$default_font = 'bn_BD' === get_locale()
			? 'freeserif'
			: (string) $this->settings->get( 'wpifw_pdf_font_family' );

		/** Preserved v1 filter. */
		$config['default_font'] = apply_filters( 'woo_invoice_default_font_family_name', $default_font );

		// Deliberate v1-bug fix: the templates hardcode the placeholder
		// family 'grota', and mPDF resolves unknown CSS families to its
		// substitution default (DejaVu) — NOT to default_font — so the
		// chosen font never actually applied in v1. Translating the
		// placeholder to the chosen family makes the setting work; sites
		// with no font chosen keep rendering exactly as before.
		// Only offer mPDF fonts whose files exist. The release ships the
		// DejaVu core set and downloads the rest in the background; until
		// then mPDF would pick e.g. FreeSerif for Bengali text or the ৳ sign
		// and abort the whole document ("Cannot find TTF … FreeSerif.ttf").
		// Without the family it falls back to DejaVu: the document prints,
		// at worst with missing glyphs, and the full font is used once the
		// pack has arrived.
		$config = $this->only_installed_fonts( $config );

		if ( '' !== (string) $config['default_font'] ) {
			$fonttrans           = isset( $font_defaults['fonttrans'] ) && is_array( $font_defaults['fonttrans'] )
				? $font_defaults['fonttrans']
				: array();
			$fonttrans['grota']  = strtolower( (string) $config['default_font'] );
			$config['fonttrans'] = $fonttrans;
		}

		if ( $this->settings->get( 'wpifw_rtl' ) ) {
			$config['directionality'] = 'rtl';
		}

		// v1 pro page numbers: numbering style applies when the option
		// covers this document type ('0'/'' = v1's default style '1').
		if ( $this->settings->get( 'wpifw_enable_page_number' ) ) {
			$page_docs = (array) $this->settings->get( 'wpifw_add_page_number' );
			if ( in_array( $type, $page_docs, true ) ) {
				$style                         = (string) $this->settings->get( 'wpifw_page_number_style' );
				$config['defaultPageNumStyle'] = ( '' !== $style && '0' !== $style ) ? $style : '1';
			}
		}

		/**
		 * Filter the mPDF configuration (new name + preserved v1 name).
		 *
		 * @param array<string,mixed> $config mPDF config.
		 * @param string              $type   Document type id.
		 */
		$config = apply_filters( 'challan_mpdf_config', $config, $type );

		return apply_filters( 'woo_invoice_mpdf_settings', $config );
	}

	/**
	 * Drop font families whose regular file is in none of the font
	 * directories, and point the default / substitution fonts at families
	 * that exist (DejaVu ships with the plugin).
	 *
	 * @param array<string,mixed> $config mPDF config (fontDir, fontdata, default_font).
	 * @return array<string,mixed>
	 */
	private function only_installed_fonts( array $config ) {
		$files = array();
		foreach ( (array) $config['fontDir'] as $dir ) {
			$names = is_dir( $dir ) ? scandir( $dir ) : false;
			foreach ( is_array( $names ) ? $names : array() as $name ) {
				$files[ $name ] = true;
			}
		}

		$fontdata = (array) $config['fontdata'];
		foreach ( $fontdata as $family => $styles ) {
			if ( ! is_array( $styles ) || empty( $styles['R'] ) || ! isset( $files[ $styles['R'] ] ) ) {
				unset( $fontdata[ $family ] );
				continue;
			}
			// A missing bold / italic face falls back to the regular one.
			foreach ( array( 'B', 'I', 'BI' ) as $style ) {
				if ( isset( $styles[ $style ] ) && ! isset( $files[ $styles[ $style ] ] ) ) {
					unset( $fontdata[ $family ][ $style ] );
				}
			}
		}
		$config['fontdata'] = $fontdata;

		$fallback = isset( $fontdata['dejavusans'] ) ? 'dejavusans' : (string) key( $fontdata );
		if ( '' !== (string) $config['default_font'] && ! isset( $fontdata[ strtolower( (string) $config['default_font'] ) ] ) ) {
			$config['default_font'] = $fallback;
		}

		$defaults                 = ( new \Mpdf\Config\FontVariables() )->getDefaults();
		$backup                   = array_values( array_filter( (array) $defaults['backupSubsFont'], static fn( $family ) => isset( $fontdata[ $family ] ) ) );
		$config['backupSubsFont'] = array() !== $backup ? $backup : array( $fallback );
		if ( ! isset( $fontdata[ (string) $defaults['backupSIPFont'] ] ) ) {
			$config['backupSIPFont'] = $fallback;
		}

		return $config;
	}

	/**
	 * Watermark background for a document type (v1 semantics, plus
	 * attachment-ID resolution — v2's media picker stores IDs while
	 * upgraded pro sites stored file paths; both work).
	 *
	 * @param string $type Document type id.
	 * @return array{background:string,opacity:float}|false
	 */
	private function watermark( $type ) {
		$background = '';
		$opacity    = 0.3;

		// Through the repository (not get_option) so preview overlays
		// of the image and opacity apply.
		if ( 'invoice' === $type && $this->settings->get( 'wpifw_enable_invoice_background' ) ) {
			$background = $this->settings->get( 'wpifw_invoice_background_attachment_id' );
			$raw        = (string) $this->settings->get( 'wpifw_invoice_background_opacity' );
			$opacity    = (float) $raw > 0 ? (float) $raw : 0.3; // v1: blank or 0 = default.
		} elseif ( 'packing_slip' === $type && $this->settings->get( 'wpifw_enable_packingslip_background' ) ) {
			$background = $this->settings->get( 'wpifw_packingslip_background_attachment_id' );
			$raw        = (string) $this->settings->get( 'wpifw_packingslip_background_opacity' );
			$opacity    = (float) $raw > 0 ? (float) $raw : 1.0;
		}

		if ( is_numeric( $background ) && (int) $background > 0 ) {
			$resolved   = get_attached_file( (int) $background );
			$background = $resolved ? $resolved : (string) wp_get_attachment_url( (int) $background );
		}

		/** Preserved v1 filters. */
		$background = apply_filters( 'woo_invoice_background_watermark', $background, $type );
		$opacity    = apply_filters( 'woo_invoice_background_watermark_opacity', $opacity, $type );

		if ( empty( $background ) ) {
			return false;
		}

		return array(
			'background' => (string) $background,
			'opacity'    => (float) $opacity,
		);
	}

	/**
	 * The v1 friendly message for missing-font errors.
	 *
	 * @param \Mpdf\MpdfException $e Engine exception.
	 * @return string
	 */
	private function friendly_error( \Mpdf\MpdfException $e ) {
		if ( false === stripos( $e->getMessage(), 'cannot find ttf' ) ) {
			return $e->getMessage();
		}

		if ( current_user_can( 'manage_woocommerce' ) ) {
			return __( 'A required font is missing. Go to the Challan settings page to download the missing fonts.', 'webappick-pdf-invoice-for-woocommerce' );
		}

		return __( 'This document cannot be generated right now. Please notify the site administrator.', 'webappick-pdf-invoice-for-woocommerce' );
	}

	/**
	 * Paper format for a document type, honoring custom dimensions and
	 * the preserved woo_invoice_paper_size filter.
	 *
	 * @param string $type Document type id.
	 * @return string|array{0:float,1:float}
	 */
	private function paper_format( $type ) {
		$map = array(
			'invoice'          => array( 'wpifw_invoice_paper_size', 'wpifw_invoice_custom_paper_wide', 'wpifw_invoice_custom_paper_height' ),
			'packing_slip'     => array( 'wpifw_pickingslip_paper_size', 'wpifw_pickingslip_custom_paper_wide', 'wpifw_pickingslip_custom_paper_height' ),
			// Labels custom paper reuses the frozen shipping-label keys
			// (the two documents merged) — applied only when the paper
			// select stores 'custom', unlike the invoice's W/H-first rule.
			'delivery_address' => array( 'wpifw_delivery_address_paper', 'wpifw_shipping_lebel_custom_paper_wide', 'wpifw_shipping_lebel_custom_paper_height' ),
		);

		/**
		 * Filter the option keys used to resolve a document type's paper
		 * format: array( size_key, custom_width_key, custom_height_key ).
		 * Challan Pro adds its document types here.
		 *
		 * @param array<string,array{0:string,1:string,2:string}> $map Keys by type id.
		 */
		$map  = apply_filters( 'challan_paper_option_keys', $map );
		$keys = isset( $map[ $type ] ) ? $map[ $type ] : $map['invoice'];

		$format = null;

		$size   = (string) $this->settings->get( $keys[0] );
		$width  = $keys[1] ? (float) $this->settings->get( $keys[1] ) : 0;
		$height = $keys[2] ? (float) $this->settings->get( $keys[2] ) : 0;

		// Invoices (and credit notes, which share the invoice keys) and
		// labels honor W/H only when 'custom' is selected — the
		// upgrader migrates v1 W/H-first sites to an explicit 'custom'.
		// The legacy shipping label keeps the v1 rule (W/H set = win); the
		// packing slip, like the invoice, uses W/H only with 'custom' (the
		// upgrader gives v1 free W/H sites an explicit 'custom').
		$wh_first_types = array( 'shipping_label' );
		$custom_allowed = in_array( $type, $wh_first_types, true ) || 'custom' === $size;

		if ( $custom_allowed && $width > 0 && $height > 0 ) {
			$format = array( $width, $height );
		} else {
			$normalized = array(
				'letter' => 'Letter',
				'Letter' => 'Letter', // Pro shipping label stores the capitalized legacy value.
				'legal'  => 'Legal',
				'Legal'  => 'Legal',
				'A3'     => 'A3',
				'A4'     => 'A4',
				'A5'     => 'A5',
				'A6'     => 'A6',
			);
			$format     = isset( $normalized[ $size ] ) ? $normalized[ $size ] : 'A4';
		}

		// A template with its own paper (the receipt roll) overrides the
		// paper setting; its height is the probe or the measured content.
		$paper = $this->template_paper( $type );
		if ( null !== $paper ) {
			$height = $paper['auto_height']
				? ( $this->fitted_height > 0 ? $this->fitted_height : self::AUTO_HEIGHT_PROBE )
				: 297;
			$format = array( $paper['width'], (float) $height );
		}

		/** Preserved v1 filter. */
		return apply_filters( 'woo_invoice_paper_size', $format, $type );
	}

	/**
	 * Writable temp dir for mPDF under uploads.
	 *
	 * @return string
	 */
	private function temp_dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'challan/tmp';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Block direct listing/execution.
			file_put_contents( trailingslashit( $dir ) . 'index.html', '' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		}

		return $dir;
	}
}
