<?php
/**
 * Cached QR / barcode images for the HTML preview.
 *
 * @package Challan
 */

namespace Challan\Documents;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Generates the REAL codes the PDF will carry, as SVG files under
 * uploads — generated once per value and reused (the file IS the
 * cache, keyed by a hash of the payload), so repeated preview loads
 * never regenerate.
 *
 * QR needs a real encoder (Reed–Solomon ECC, masking) — we use
 * mpdf/qrcode, the same pure-PHP package mPDF itself uses for
 * <barcode type="QR"> in the PDF, so preview and PDF are guaranteed
 * to encode identically. Code 39 is simple enough to draw in plain
 * PHP with the standard 9-element patterns.
 */
final class CodeImages {

	/**
	 * Code 39 patterns: 9 elements per character, alternating
	 * bar/space starting with a bar; n = narrow, w = wide.
	 *
	 * @var array<string,string>
	 */
	const C39 = array(
		'0' => 'nnnwwnwnn',
		'1' => 'wnnwnnnnw',
		'2' => 'nnwwnnnnw',
		'3' => 'wnwwnnnnn',
		'4' => 'nnnwwnnnw',
		'5' => 'wnnwwnnnn',
		'6' => 'nnwwwnnnn',
		'7' => 'nnnwnnwnw',
		'8' => 'wnnwnnwnn',
		'9' => 'nnwwnnwnn',
		'A' => 'wnnnnwnnw',
		'B' => 'nnwnnwnnw',
		'C' => 'wnwnnwnnn',
		'D' => 'nnnnwwnnw',
		'E' => 'wnnnwwnnn',
		'F' => 'nnwnwwnnn',
		'G' => 'nnnnnwwnw',
		'H' => 'wnnnnwwnn',
		'I' => 'nnwnnwwnn',
		'J' => 'nnnnwwwnn',
		'K' => 'wnnnnnnww',
		'L' => 'nnwnnnnww',
		'M' => 'wnwnnnnwn',
		'N' => 'nnnnwnnww',
		'O' => 'wnnnwnnwn',
		'P' => 'nnwnwnnwn',
		'Q' => 'nnnnnnwww',
		'R' => 'wnnnnnwwn',
		'S' => 'nnwnnnwwn',
		'T' => 'nnnnwnwwn',
		'U' => 'wwnnnnnnw',
		'V' => 'nwwnnnnnw',
		'W' => 'wwwnnnnnn',
		'X' => 'nwnnwnnnw',
		'Y' => 'wwnnwnnnn',
		'Z' => 'nwwnwnnnn',
		'-' => 'nwnnnnwnw',
		'.' => 'wwnnnnwnn',
		' ' => 'nwwnnnwnn',
		'$' => 'nwnwnwnnn',
		'/' => 'nwnwnnnwn',
		'+' => 'nwnnnwnwn',
		'%' => 'nnnwnwnwn',
		'*' => 'nwnnwnwnn',
	);

	/**
	 * URL of a cached QR SVG for the payload (generated on first use).
	 *
	 * @param string $data Payload to encode.
	 * @return string URL, or '' when the encoder is unavailable.
	 */
	public static function qr_url( $data ) {
		// The bundled autoloader is lazy (the PDF engine loads it on
		// demand); HTML previews need it here for the QR encoder.
		if ( ! class_exists( \Mpdf\QrCode\QrCode::class ) && file_exists( CHALLAN_PATH . 'vendor/autoload.php' ) ) {
			require_once CHALLAN_PATH . 'vendor/autoload.php';
		}
		if ( ! class_exists( \Mpdf\QrCode\QrCode::class ) ) {
			return '';
		}

		return self::cached(
			'qr-' . md5( $data ) . '.svg',
			static function () use ( $data ) {
				$qr     = new \Mpdf\QrCode\QrCode( $data );
				$output = new \Mpdf\QrCode\Output\Svg();
				return $output->output( $qr, 200, 'white', 'black' );
			}
		);
	}

	/**
	 * URL of a cached QR PNG for the payload — for email bodies, where many
	 * clients (Gmail, Outlook) do not show SVG. Falls back to the SVG when
	 * GD is missing.
	 *
	 * @param string $data Payload to encode.
	 * @param int    $size Image width in pixels.
	 * @return string URL, or '' when the encoder is unavailable.
	 */
	public static function qr_png_url( $data, $size = 160 ) {
		if ( ! function_exists( 'imagecreate' ) ) {
			return self::qr_url( $data );
		}
		if ( ! class_exists( \Mpdf\QrCode\QrCode::class ) && file_exists( CHALLAN_PATH . 'vendor/autoload.php' ) ) {
			require_once CHALLAN_PATH . 'vendor/autoload.php';
		}
		if ( ! class_exists( \Mpdf\QrCode\QrCode::class ) ) {
			return '';
		}

		$size = max( 60, (int) $size );
		return self::cached(
			'qr-' . md5( $data ) . '-' . $size . '.png',
			static function () use ( $data, $size ) {
				$qr = new \Mpdf\QrCode\QrCode( $data );
				return ( new \Mpdf\QrCode\Output\Png() )->output( $qr, $size, array( 255, 255, 255 ), array( 0, 0, 0 ), 9 );
			}
		);
	}

	/**
	 * URL of a cached Code 39 SVG for the payload.
	 *
	 * @param string $data Payload (sanitized to the Code 39 charset).
	 * @return string URL, or '' when nothing encodable remains.
	 */
	public static function c39_url( $data ) {
		$data = strtoupper( (string) $data );
		$data = preg_replace( '/[^0-9A-Z\-\. \$\/\+%]/', '', $data );
		if ( '' === $data ) {
			return '';
		}

		return self::cached(
			'c39-' . md5( $data ) . '.svg',
			static function () use ( $data ) {
				return self::c39_svg( $data );
			}
		);
	}

	/**
	 * Return the URL of a cached file, generating it exactly once.
	 *
	 * @param string   $filename  Cache file name (already hash-keyed).
	 * @param callable $generate  Produces the file content (SVG markup or PNG bytes).
	 * @return string
	 */
	private static function cached( $filename, $generate ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'challan/codes';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		if ( ! file_exists( trailingslashit( $dir ) . 'index.html' ) ) {
			file_put_contents( trailingslashit( $dir ) . 'index.html', '' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		}
		if ( ! file_exists( trailingslashit( $dir ) . '.htaccess' ) ) {
			// The parent challan/ dir is deny-from-all (stored documents);
			// these images must be browser-loadable for the preview. File
			// names are unguessable hashes and listing is index-blocked.
			// Same 2.2-compat syntax as the parent deny: wherever that
			// deny is effective (mod_access_compat), this override is too.
			$rules = "Order allow,deny\nAllow from all\nSatisfy any\n";
			file_put_contents( trailingslashit( $dir ) . '.htaccess', $rules ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		}

		$path = trailingslashit( $dir ) . $filename;
		if ( ! file_exists( $path ) ) {
			$svg = (string) call_user_func( $generate );
			if ( '' === $svg ) {
				return '';
			}
			file_put_contents( $path, $svg ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- controlled uploads subdir.
		}

		return trailingslashit( $uploads['baseurl'] ) . 'challan/codes/' . $filename;
	}

	/**
	 * Draw a Code 39 barcode as SVG (start/stop asterisks included).
	 *
	 * @param string $data Sanitized payload.
	 * @return string SVG markup.
	 */
	private static function c39_svg( $data ) {
		$narrow = 2;
		$wide   = 5;
		$height = 40;

		$x     = 0;
		$rects = '';
		$chars = str_split( '*' . $data . '*' );

		foreach ( $chars as $index => $char ) {
			$pattern = isset( self::C39[ $char ] ) ? self::C39[ $char ] : self::C39['-'];
			foreach ( str_split( $pattern ) as $position => $element ) {
				$w = 'w' === $element ? $wide : $narrow;
				// Elements alternate bar/space, starting with a bar.
				if ( 0 === $position % 2 ) {
					$rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $height . '"/>';
				}
				$x += $w;
			}
			// Inter-character narrow space.
			if ( $index < count( $chars ) - 1 ) {
				$x += $narrow;
			}
		}

		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $x . '" height="' . $height . '" viewBox="0 0 ' . $x . ' ' . $height . '">'
			. '<rect width="' . $x . '" height="' . $height . '" fill="white"/>'
			. '<g fill="black">' . $rects . '</g>'
			. '</svg>';
	}
}
