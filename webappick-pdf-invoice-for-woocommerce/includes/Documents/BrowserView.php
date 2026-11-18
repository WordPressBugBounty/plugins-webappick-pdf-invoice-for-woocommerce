<?php
/**
 * Browser rendering of mPDF documents.
 *
 * @package Challan
 */

namespace Challan\Documents;

use Challan\Settings\Repository;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Makes a document's mPDF markup look in a browser as it does in the PDF.
 *
 * - adapt(): mPDF-only markup (page footer, barcodes, page breaks, the
 *   font setting, background) → browser equivalents. Used by the admin
 *   live preview.
 * - standalone(): the "Output as HTML" view. Lays the document out with
 *   mPDF first and presents it like the PDF viewer: real paper sheets
 *   (size and margins from the layout) on a grey backdrop, set in the
 *   exact fonts the PDF embeds, served from the plugin/uploads folders.
 */
final class BrowserView {

	/**
	 * The "Output as HTML" page for one document.
	 *
	 * @param string     $html     Document HTML (mPDF markup).
	 * @param string     $type     Document type id.
	 * @param Repository $settings Render settings.
	 * @param PdfEngine  $engine   Engine used for the layout pass.
	 * @return string
	 */
	public static function standalone( $html, $type, Repository $settings, PdfEngine $engine ) {
		$page   = array(
			'w'      => 210.0,
			'h'      => 297.0,
			'l'      => 7.94,
			'r'      => 7.94,
			't'      => 7.94,
			'b'      => 7.94,
			'footer' => 9.0,
			'pages'  => 1,
			'line'   => 1.33,
			'paper'  => '#fff',
			'auto'   => false,
		);
		$fonts  = '';
		$layout = null;
		$styles = array();
		$probe  = null;

		try {
			$mpdf = $engine->layout( $html, $type, true );
			// Finish the document so page footers get placed too.
			$mpdf->Output( '', \Mpdf\Output\Destination::STRING_RETURN );
			$layout = $mpdf instanceof LayoutProbe ? $mpdf->challan_layout : null;
			$styles = $mpdf instanceof LayoutProbe ? $mpdf->challan_css_records() : array();
			$probe  = $mpdf;

			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- mPDF API properties.
			$page = array(
				'w'      => (float) $mpdf->w,
				'h'      => (float) $mpdf->h,
				'l'      => (float) $mpdf->DeflMargin,
				'r'      => (float) $mpdf->DefrMargin,
				't'      => (float) $mpdf->orig_tMargin,
				'b'      => (float) $mpdf->orig_bMargin,
				'footer' => (float) $mpdf->margin_footer,
				'pages'  => max( 1, count( $mpdf->pages ) ),
				'line'   => (float) $mpdf->normalLineheight,
				// mPDF paints the body background over the whole page.
				'paper'  => ! empty( $mpdf->bodyBackgroundColor ) ? LayoutProbe::challan_color( $mpdf->bodyBackgroundColor ) : '#fff',
				// Roll paper is already cut to the content height by the layout.
				'auto'   => false,
			);
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			list( $html, $fonts ) = self::apply_pdf_fonts( $html, $mpdf );
		} catch ( \Throwable $e ) {
			// No engine / layout failure: still show the document, on A4.
			unset( $e );
		}

		$html = self::mpdf_selectors( $html );
		// mPDF reads an empty or zero border attribute as "no border";
		// browsers read the empty one as a 1px grid.
		$html = preg_replace( '/(<table\b[^>]*?)\sborder\s*=\s*(["\'])\s*0?\s*\2/i', '$1', $html );
		$html = self::adapt( $html, $settings, $type, true );
		// Page footers: a document split into pages (one .invoice-box per
		// page) carries one footer per page; mPDF draws each at the bottom
		// of its page and fills in the page numbers.
		$footers = preg_match_all( '/<div class="challan-preview-pagefooter">/', $html );
		$number  = 0;
		$html    = preg_replace_callback(
			'/<div class="challan-preview-pagefooter">(.*?)(?=<div class="challan-preview-pagefooter">|$)/s',
			static function ( $matches ) use ( &$number, $footers, $page, $layout ) {
				$index = 1 === $footers ? $page['pages'] - 1 : min( $number, $page['pages'] - 1 );
				++$number;
				$top = isset( $layout['footer'] ) ? $index * $page['h'] + $layout['footer']['top'] : null;
				$tag = null !== $top
					? '<div class="challan-preview-pagefooter" style="top:' . round( $top, 3 ) . 'mm">'
					: '<div class="challan-preview-pagefooter">';
				return $tag . str_ireplace( '{PAGENO}', (string) ( $index + 1 ), $matches[1] );
			},
			$html
		);
		$html    = str_ireplace( array( '{PAGENO}', '{nbpg}', '{nb}' ), array( '1', (string) $page['pages'], (string) $page['pages'] ), $html );
		// Between pages: the bottom margin of one and the top margin of the next.
		$boxes = 0;
		$html  = preg_replace_callback(
			'/<div\b[^>]*\bclass="invoice-box\b/',
			static function ( $matches ) use ( &$boxes ) {
				return ( $boxes++ > 0 ? '<div class="challan-page-gap"></div>' : '' ) . $matches[0];
			},
			$html
		);
		// mPDF may break a line where the script changes (the amount and its
		// currency symbol); give the browser the same break opportunity.
		$html = preg_replace( '/(<span\b[^>]*class=["\'][^"\']*woocommerce-Price-currencySymbol)/i', '<wbr>$1', $html );
		if ( array() !== $styles && null !== $probe ) {
			$html = self::apply_resolved_css( $html, $styles, $probe );
		}
		if ( is_array( $layout ) ) {
			$html = self::apply_geometry( $html, $layout );
		}

		$mm  = static function ( $value ) {
			return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' ) . 'mm';
		};
		$css = '<style id="challan-html-view">'
			. 'html{background:#525659}'
			. 'body{margin:0 !important;padding:24px 12px !important;background:transparent !important}'
			. '.challan-sheet{position:relative;box-sizing:border-box;background:' . esc_attr( 'currentColor' === $page['paper'] ? '#fff' : $page['paper'] ) . ';margin:0 auto;'
			. 'width:' . $mm( $page['w'] ) . ';'
			. 'min-height:' . $mm( $page['auto'] ? 0 : $page['h'] * $page['pages'] ) . ';'
			. 'padding:' . $mm( $page['t'] ) . ' ' . $mm( $page['r'] ) . ' ' . $mm( $page['b'] ) . ' ' . $mm( $page['l'] ) . ';'
			. 'box-shadow:0 1px 4px rgba(0,0,0,.35),0 4px 18px rgba(0,0,0,.25)}'
			. '.challan-sheet>*{position:relative;z-index:1}'
			. '.challan-sheet .invoice-box{position:static;min-height:' . $mm( $page['auto'] ? 0 : max( 0, $page['h'] - $page['t'] - $page['b'] ) ) . '}'
			// One .invoice-box per page: each fills its page's content area,
			// the next starts below the page margins.
			. '.challan-page-gap{height:' . $mm( $page['t'] + $page['b'] ) . '}'
			. ( $page['pages'] > 1
				? '.challan-sheet{background-image:repeating-linear-gradient(to bottom,transparent 0,transparent calc(' . $mm( $page['h'] ) . ' - 1px),#c3c9d3 calc(' . $mm( $page['h'] ) . ' - 1px),#c3c9d3 ' . $mm( $page['h'] ) . ')}'
				: '' )
			// mPDF's "normal" line height, and its table cells, which drop
			// the margins and padding of the blocks inside them.
			. '.challan-sheet{line-height:' . round( $page['line'], 3 ) . '}'
			// mPDF sits images on the line without a descender gap below.
			. '.challan-sheet img{vertical-align:bottom}'
			// mPDF ignores display on table rows and cells: a hidden cell keeps its column.
			. '.challan-sheet tr{display:table-row !important}.challan-sheet td,.challan-sheet th{display:table-cell !important}'
			// Codes follow the cell's alignment, as mPDF's <barcode> does.
			. '.challan-sheet img[alt="QR code"],.challan-sheet img[alt="Barcode"]{display:inline-block !important}'
			. '.challan-sheet td p,.challan-sheet th p,.challan-sheet td div,.challan-sheet th div,.challan-sheet td h1,.challan-sheet th h1,.challan-sheet td h2,.challan-sheet th h2,.challan-sheet td h3,.challan-sheet th h3,.challan-sheet td h4,.challan-sheet th h4,.challan-sheet td h5,.challan-sheet th h5,.challan-sheet td h6,.challan-sheet th h6,.challan-sheet td ul,.challan-sheet th ul,.challan-sheet td ol,.challan-sheet th ol,.challan-sheet td blockquote,.challan-sheet th blockquote,.challan-sheet td pre,.challan-sheet th pre,.challan-sheet td address,.challan-sheet th address{margin-top:0 !important;margin-bottom:0 !important;padding:0 !important}'
			// mPDF ends the page footer margin_footer above the paper edge.
			. ( $page['auto']
				? '.challan-preview-pagefooter{position:static;margin-top:14px}'
				: '.challan-preview-pagefooter{position:absolute;left:' . $mm( $page['l'] ) . ';right:' . $mm( $page['r'] ) . ';'
					. ( isset( $layout['footer'] )
						? 'top:' . $mm( ( $page['pages'] - 1 ) * $page['h'] + $layout['footer']['top'] ) . ';bottom:auto'
						: 'bottom:' . $mm( $page['footer'] ) )
					. '}' )
			. '@media print{'
			. 'html{background:none}body{padding:0 !important}'
			. '.challan-sheet{box-shadow:none;margin:0;width:auto;min-height:0;padding:0;background-image:none;zoom:1 !important}'
			. '.challan-page-gap{display:none}.challan-sheet .invoice-box.pageBreak{page-break-after:always}'
			. '.challan-sheet .invoice-box{min-height:' . $mm( $page['auto'] ? 0 : max( 0, $page['h'] - $page['t'] - $page['b'] - 1 ) ) . '}'
			. '.challan-preview-pagefooter{position:fixed;left:0;right:0;top:auto !important;bottom:0}'
			// A fixed footer repeats on every printed page: print one.
			. '.challan-sheet .invoice-box ~ .invoice-box .challan-preview-pagefooter{display:none}'
			. '}'
			. '@page{size:' . $mm( $page['w'] ) . ' ' . ( $page['auto'] ? 'auto' : $mm( $page['h'] ) ) . ';margin:' . $mm( $page['t'] ) . ' ' . $mm( $page['r'] ) . ' ' . $mm( $page['b'] ) . ' ' . $mm( $page['l'] ) . '}'
			. '</style>';

		// mPDF's own default stylesheet for the elements whose browser
		// defaults differ — first in <head>, so the template overrides it
		// exactly as it does in mPDF.
		$base = '<style id="challan-html-base">'
			. 'table{line-height:1.2;border-collapse:separate;border-spacing:2px;vertical-align:middle;margin:0}'
			. 'td,th{padding:.1em;vertical-align:inherit}'
			. 'th{font-weight:bold;text-align:center}'
			. 'p{margin:1.12em 0}'
			// mPDF draws a rule as a filled bar in the element's color.
			. 'hr{border:0;color:#888;background:currentColor;height:.2mm;width:100%;margin:.83em auto}'
			. '</style>';
		$html = preg_replace( '/<head(\s[^>]*)?>/i', '$0' . $base, $html, 1 );
		$html = str_ireplace( '</head>', $fonts . $css . '</head>', $html );
		$html = preg_replace( '/<body([^>]*)>/i', '<body$1><div class="challan-sheet">', $html, 1 );
		$html = preg_replace( '#</body>#i', '</div>' . self::wrap_guard_script() . '</body>', $html, 1 );

		return $html;
	}

	/**
	 * Drop the selectors mPDF never matches, so the browser doesn't apply
	 * rules the PDF ignores: sibling combinators (+, ~) and descendant
	 * selectors whose ancestor is an inline element — mPDF's cascade only
	 * tracks block and table ancestors (e.g. `.seller-info b` with
	 * span.seller-info).
	 *
	 * @param string $html Document HTML.
	 * @return string
	 */
	private static function mpdf_selectors( $html ) {
		$body = stristr( $html, '<body' );
		if ( false === $body ) {
			return $html;
		}

		// Tag names per class in the body.
		$classes = array();
		if ( preg_match_all( '/<([a-z][a-z0-9]*)\b[^>]*\sclass\s*=\s*(["\'])(.*?)\2/is', $body, $tags, PREG_SET_ORDER ) ) {
			foreach ( $tags as $tag ) {
				foreach ( preg_split( '/\s+/', trim( $tag[3] ) ) as $class ) {
					$classes[ $class ][ strtolower( $tag[1] ) ] = true;
				}
			}
		}
		$inline = array( 'a', 'abbr', 'b', 'bdo', 'big', 'cite', 'code', 'em', 'font', 'i', 'ins', 'kbd', 'label', 'mark', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'tt', 'u', 'var' );

		$unmatched = static function ( $selector ) use ( $classes, $inline ) {
			if ( preg_match( '/[+~]/', $selector ) ) {
				return true;
			}
			$parts = preg_split( '/\s+/', trim( str_replace( '>', ' ', $selector ) ) );
			array_pop( $parts ); // The subject itself may be anything.
			foreach ( $parts as $part ) {
				$tag = preg_match( '/^([a-z][a-z0-9]*)/i', $part, $tag_match ) ? strtolower( $tag_match[1] ) : '';
				if ( '' !== $tag && in_array( $tag, $inline, true ) ) {
					return true;
				}
				if ( '' === $tag && preg_match_all( '/\.([a-z0-9_-]+)/i', $part, $class_match ) ) {
					$names = array();
					foreach ( $class_match[1] as $class ) {
						$names = array_merge( $names, isset( $classes[ $class ] ) ? array_keys( $classes[ $class ] ) : array() );
					}
					if ( array() !== $names && array() === array_diff( $names, $inline ) ) {
						return true;
					}
				}
			}
			return false;
		};

		return preg_replace_callback(
			'#(<style[^>]*>)(.*?)(</style>)#is',
			static function ( $style ) use ( $unmatched ) {
				$css = preg_replace_callback(
					'/(^|})(\s*)([^{}@]+)\{/',
					static function ( $rule ) use ( $unmatched ) {
						$kept = array();
						foreach ( explode( ',', $rule[3] ) as $selector ) {
							$clean = trim( $selector );
							if ( '' !== $clean && ! $unmatched( $clean ) ) {
								$kept[] = $selector;
							}
						}
						// A rule left without selectors matches nothing.
						$selectors = array() !== $kept ? implode( ',', $kept ) : '.challan-mpdf-unmatched';
						return $rule[1] . $rule[2] . $selectors . '{';
					},
					preg_replace( '#/\*.*?\*/#s', '', $style[2] )
				);
				return $style[1] . $css . $style[3];
			},
			$html
		);
	}

	/**
	 * Apply the CSS mPDF resolved for each element (CssRecorder) as inline
	 * declarations, so the browser shows what the PDF engine applied
	 * rather than its own cascade of the template CSS. Elements are paired
	 * with the records by tag and class, in document order; the page footer
	 * (parsed by mPDF out of document order) is paired separately.
	 *
	 * @param string     $html    Browser-adapted document HTML.
	 * @param array      $records CssRecorder records.
	 * @param \Mpdf\Mpdf $mpdf    Laid-out document (font resolution).
	 * @return string
	 */
	private static function apply_resolved_css( $html, array $records, $mpdf ) {
		$names = array_map( 'strtolower', CssRecorder::TAGS );
		if ( ! preg_match_all( '/<(\/?)([a-z][a-z0-9]*)\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return $html;
		}

		// Elements of interest, flagged when inside the page footer block.
		$elements = array();
		$footer   = 0; // Div depth inside the footer block; 0 = outside.
		foreach ( $matches as $match ) {
			$closing = '/' === $match[1][0];
			$name    = strtolower( $match[2][0] );
			$tag     = $match[0][0];

			if ( 'div' === $name ) {
				if ( $closing ) {
					$footer = $footer > 0 ? $footer - 1 : 0;
				} elseif ( $footer > 0 ) {
					++$footer;
				} elseif ( false !== strpos( $tag, 'challan-preview-pagefooter' ) ) {
					$footer = 1;
					continue;
				}
			}
			if ( $closing || ! in_array( $name, $names, true ) || 'img' === $name ) {
				continue;
			}
			$class      = preg_match( '/\sclass\s*=\s*(["\'])(.*?)\1/is', $tag, $class_match ) ? strtolower( trim( preg_replace( '/\s+/', ' ', $class_match[2] ) ) ) : '';
			$elements[] = array(
				'tag'    => $tag,
				'offset' => $match[0][1],
				'key'    => strtoupper( $name ) . '|' . $class,
				'footer' => $footer > 0,
			);
		}

		$keys = array();
		foreach ( $records as $r => $record ) {
			$keys[ $r ] = $record['tag'] . '|' . $record['class'];
		}

		// Pair in order, tolerating elements mPDF skipped or added.
		$pairs   = array();
		$used    = array();
		$pointer = 0;
		$window  = 60;
		foreach ( array( false, true ) as $in_footer ) {
			$pointer = $in_footer ? 0 : $pointer;
			foreach ( $elements as $e => $element ) {
				if ( $element['footer'] !== $in_footer ) {
					continue;
				}
				$limit = $in_footer ? count( $keys ) : min( count( $keys ), $pointer + $window );
				for ( $r = $pointer; $r < $limit; $r++ ) {
					if ( empty( $used[ $r ] ) && $keys[ $r ] === $element['key'] ) {
						$pairs[ $e ] = $r;
						$used[ $r ]  = true;
						$pointer     = $r + 1;
						break;
					}
				}
			}
		}

		// Edit back to front (element order = document order) so offsets stay valid.
		krsort( $pairs );
		foreach ( $pairs as $e => $r ) {
			$css = self::resolved_declarations( $records[ $r ], $mpdf );
			if ( '' === $css ) {
				continue;
			}
			$element = $elements[ $e ];
			$tag     = $element['tag'];
			if ( preg_match( '/\sstyle\s*=\s*(["\'])(.*?)\1/is', $tag, $style ) ) {
				$merged = rtrim( trim( $style[2] ), ';' );
				$new    = str_replace( $style[0], ' style="' . esc_attr( ( '' !== $merged ? $merged . ';' : '' ) . $css ) . '"', $tag );
			} else {
				$new = preg_replace( '#\s*(/?)>$#', ' style="' . esc_attr( $css ) . '"$1>', $tag, 1 );
			}
			$html = substr_replace( $html, $new, $element['offset'], strlen( $element['tag'] ) );
		}

		return $html;
	}

	/**
	 * Inline CSS for one element from mPDF's resolved properties.
	 *
	 * @param array      $record CssRecorder record.
	 * @param \Mpdf\Mpdf $mpdf   Laid-out document.
	 * @return string
	 */
	private static function resolved_declarations( array $record, $mpdf ) {
		$props  = $record['props'];
		$tag    = $record['tag'];
		$cell   = in_array( $tag, array( 'TD', 'TH' ), true );
		$block  = in_array( $tag, array( 'DIV', 'P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true );
		$inline = ! $cell && ! $block && ! in_array( $tag, array( 'TABLE', 'TR' ), true );
		if ( 'TR' === $tag ) {
			return '';
		}

		$value = static function ( $name ) use ( $props ) {
			if ( ! isset( $props[ $name ] ) ) {
				return null;
			}
			$v = trim( (string) $props[ $name ] );
			return '' !== $v && preg_match( '/^[#a-z0-9.%(),\s+-]+$/i', $v ) ? $v : null;
		};

		$out = array();
		// Text properties — what mPDF did not set is inherited, as in mPDF.
		$text = array( 'font-size', 'font-weight', 'font-style', 'color', 'text-transform', 'letter-spacing' );
		foreach ( $text as $name ) {
			$v = $value( strtoupper( $name ) );
			if ( null !== $v ) {
				$out[ $name ] = $v;
			} elseif ( $inline || $cell || ( $block && '' === $record['context'] ) ) {
				$out[ $name ] = 'inherit';
			}
		}

		$family = isset( $props['FONT-FAMILY'] ) ? self::resolve_font( (string) $props['FONT-FAMILY'], $mpdf ) : null;
		if ( null !== $family ) {
			$out['font-family'] = 'challan-' . preg_replace( '/[^a-z0-9_-]/', '', $family );
		} elseif ( $inline || $cell ) {
			$out['font-family'] = 'inherit';
		}

		if ( $inline ) {
			$out['text-decoration']  = null !== $value( 'TEXT-DECORATION' ) ? $value( 'TEXT-DECORATION' ) : 'none';
			$out['background-color'] = null !== $value( 'BACKGROUND-COLOR' ) ? $value( 'BACKGROUND-COLOR' ) : 'transparent';
		} else {
			foreach ( array( 'text-align', 'background-color', 'vertical-align' ) as $name ) {
				$v = $value( strtoupper( $name ) );
				if ( null !== $v ) {
					$out[ $name ] = $v;
				} elseif ( 'text-align' === $name && $cell ) {
					$out[ $name ] = 'inherit';
				}
			}
			$sides = array( 'top', 'right', 'bottom', 'left' );
			foreach ( $sides as $side ) {
				if ( $cell || ( $block && 'BLOCK' === $record['context'] ) ) {
					$v                         = $value( 'PADDING-' . strtoupper( $side ) );
					$out[ 'padding-' . $side ] = null !== $v ? $v : '0';
				}
				if ( 'TABLE' === $tag || ( $block && 'BLOCK' === $record['context'] ) ) {
					$v                        = $value( 'MARGIN-' . strtoupper( $side ) );
					$out[ 'margin-' . $side ] = null !== $v ? $v : '0';
				}
				if ( $block && 'BLOCK' === $record['context'] ) {
					$v                        = $value( 'BORDER-' . strtoupper( $side ) );
					$out[ 'border-' . $side ] = null !== $v ? $v : '0 none';
				}
			}
		}

		$css = array();
		foreach ( $out as $name => $v ) {
			$css[] = $name . ':' . $v . ' !important';
		}

		return implode( ';', $css );
	}

	/**
	 * Give tables and images the geometry mPDF laid them out with:
	 * column widths, row heights, the shrink-to-fit factor of over-wide
	 * tables, and image sizes. Tables are matched to the recording by
	 * nesting level and row/column counts, images by file name.
	 *
	 * @param string $html   Browser-adapted document HTML.
	 * @param array  $layout LayoutProbe::$challan_layout.
	 * @return string
	 */
	private static function apply_geometry( $html, array $layout ) {
		if ( ! preg_match_all( '/<(\/?)(table|tr|td|th|img)\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return $html;
		}

		$tables = array(); // Per table: its nesting level, its rows with their cells and spans, its tag token and its parent table.
		$stack  = array();
		$images = array(); // Each: token, file, table.
		$tokens = array();

		foreach ( $matches as $index => $match ) {
			$tokens[ $index ] = array(
				'tag'    => $match[0][0],
				'offset' => $match[0][1],
				'css'    => '',
				'attr'   => '',
			);
			$closing          = '/' === $match[1][0];
			$name             = strtolower( $match[2][0] );
			$current          = array() !== $stack ? end( $stack ) : null;

			if ( 'table' === $name ) {
				if ( $closing ) {
					array_pop( $stack );
					continue;
				}
				$tables[] = array(
					'token'  => $index,
					'level'  => count( $stack ) + 1,
					'rows'   => array(),
					'parent' => $current,
				);
				$stack[]  = count( $tables ) - 1;
			} elseif ( null === $current || $closing ) {
				continue;
			} elseif ( 'tr' === $name ) {
				$tables[ $current ]['rows'][] = array(
					'token' => $index,
					'cells' => array(),
				);
			} elseif ( 'img' === $name ) {
				$src      = preg_match( '/\ssrc\s*=\s*["\']([^"\']*)["\']/i', $match[0][0], $src_match ) ? $src_match[1] : '';
				$images[] = array(
					'token' => $index,
					'file'  => basename( (string) wp_parse_url( html_entity_decode( $src ), PHP_URL_PATH ) ),
					'table' => $current,
				);
			} else {
				$row = count( $tables[ $current ]['rows'] ) - 1;
				if ( $row < 0 ) {
					continue;
				}
				$span = preg_match( '/\scolspan\s*=\s*["\']?(\d+)/i', $match[0][0], $span_match ) ? max( 1, (int) $span_match[1] ) : 1;

				$rows = preg_match( '/\srowspan\s*=\s*["\']?(\d+)/i', $match[0][0], $rows_match ) ? max( 1, (int) $rows_match[1] ) : 1;

				$tables[ $current ]['rows'][ $row ]['cells'][] = array(
					'token' => $index,
					'span'  => $span,
					'rows'  => $rows,
				);
			}
		}

		// Images outside tables too.
		foreach ( $matches as $index => $match ) {
			if ( 'img' === strtolower( $match[2][0] ) && ! in_array( $index, array_column( $images, 'token' ), true ) ) {
				$src      = preg_match( '/\ssrc\s*=\s*["\']([^"\']*)["\']/i', $match[0][0], $src_match ) ? $src_match[1] : '';
				$images[] = array(
					'token' => $index,
					'file'  => basename( (string) wp_parse_url( html_entity_decode( $src ), PHP_URL_PATH ) ),
					'table' => null,
				);
			}
		}

		$mm = static function ( $value ) {
			return rtrim( rtrim( number_format( (float) $value, 3, '.', '' ), '0' ), '.' ) . 'mm';
		};

		// Match each table to its recording.
		$recorded = isset( $layout['tables'] ) ? (array) $layout['tables'] : array();
		$used     = array();
		$scale    = array(); // Table index => factor its sizes are drawn at (top-level shrink).
		foreach ( $tables as $t => $table ) {
			$cols = 0;
			foreach ( $table['rows'] as $row ) {
				$cols = max( $cols, array_sum( array_column( $row['cells'], 'span' ) ) );
			}

			$scale[ $t ] = null !== $table['parent'] ? $scale[ $table['parent'] ] : 1.0;

			$found = null;
			foreach ( $recorded as $r => $record ) {
				if ( empty( $used[ $r ] ) && $record['level'] === $table['level'] && count( $table['rows'] ) === $record['rows'] && $record['cols'] === $cols ) {
					$found      = $record;
					$used[ $r ] = true;
					break;
				}
			}
			if ( null === $found ) {
				continue;
			}

			if ( 1 === $table['level'] && $found['shrink'] > 1 ) {
				$scale[ $t ] = (float) $found['shrink'];
			}
			$k = $scale[ $t ];

			$css = 'table-layout:fixed;width:' . $mm( $found['width'] * $k ) . ' !important;max-width:none';
			if ( 1 === $table['level'] && $k > 1 ) {
				// mPDF shrinks an over-wide table — text, images, padding — to fit.
				$css .= ';zoom:' . round( 1 / $k, 5 );
			}
			$borders = isset( $found['borders'] ) ? (array) $found['borders'] : array();
			if ( array() !== $borders && empty( $found['separate'] ) ) {
				// Collapsed borders: mPDF paints the cells' borders, not the table's.
				$css .= ';border:0 none !important;border-collapse:collapse !important';
			}
			$tokens[ $table['token'] ]['css'] .= $css;

			$occupied = array(); // Row => columns taken by rowspans from above.
			foreach ( $table['rows'] as $r => $row ) {
				if ( isset( $found['heights'][ $r ] ) && $found['heights'][ $r ] > 0 ) {
					$tokens[ $row['token'] ]['css'] .= 'height:' . $mm( $found['heights'][ $r ] * $k );
				}
				$column = 0;
				foreach ( $row['cells'] as $cell ) {
					while ( ! empty( $occupied[ $r ][ $column ] ) ) {
						++$column;
					}
					$css = 'box-sizing:border-box';
					if ( 0 === $r ) {
						$width = array_sum( array_slice( $found['columns'], $column, $cell['span'] ) );
						if ( $width > 0 ) {
							$css .= ';width:' . $mm( $width * $k ) . ' !important';
						}
					}
					if ( isset( $borders[ $r ][ $column ] ) ) {
						foreach ( array(
							'T' => 'top',
							'R' => 'right',
							'B' => 'bottom',
							'L' => 'left',
						) as $side => $name ) {
							$css .= ';border-' . $name . ':' . $borders[ $r ][ $column ][ $side ] . ' !important';
						}
						if ( ! empty( $borders[ $r ][ $column ]['H'] ) ) {
							$tokens[ $cell['token'] ]['attr'] = ' data-challan-ch="' . round( $borders[ $r ][ $column ]['H'] * 96 / 25.4, 2 ) . '"';
						}
					}
					$tokens[ $cell['token'] ]['css'] .= $css;
					for ( $down = 1; $down < $cell['rows']; $down++ ) {
						for ( $across = 0; $across < $cell['span']; $across++ ) {
							$occupied[ $r + $down ][ $column + $across ] = true;
						}
					}
					$column += $cell['span'];
				}
			}
		}

		// Images: the painted size, in file order.
		$painted = isset( $layout['images'] ) ? (array) $layout['images'] : array();
		foreach ( $images as $image ) {
			foreach ( $painted as $p => $record ) {
				if ( basename( (string) wp_parse_url( $record['file'], PHP_URL_PATH ) ) !== $image['file'] ) {
					continue;
				}
				unset( $painted[ $p ] );
				$k                                 = null !== $image['table'] ? $scale[ $image['table'] ] : 1.0;
				$tokens[ $image['token'] ]['css'] .= 'width:' . $mm( $record['w'] * $k ) . ' !important;height:' . $mm( $record['h'] * $k ) . ' !important;max-width:none';
				break;
			}
		}

		// Barcodes / QR codes (images made from mPDF's <barcode>): the
		// painted size, in document order per kind.
		$codes = isset( $layout['codes'] ) ? (array) $layout['codes'] : array();
		foreach ( $matches as $index => $match ) {
			if ( 'img' !== strtolower( $match[2][0] ) || ! preg_match( '/\salt="(QR code|Barcode)"/', $match[0][0], $alt ) ) {
				continue;
			}
			$qr = 'QR code' === $alt[1];
			foreach ( $codes as $c => $code ) {
				if ( $code['qr'] !== $qr ) {
					continue;
				}
				unset( $codes[ $c ] );
				$k = 1.0;
				foreach ( $images as $image ) {
					if ( $image['token'] === $index && null !== $image['table'] ) {
						$k = $scale[ $image['table'] ];
					}
				}
				$tokens[ $index ]['css'] .= 'width:' . $mm( $code['w'] * $k ) . ' !important;height:' . $mm( $code['h'] * $k ) . ' !important;max-width:none !important';
				break;
			}
		}

		// Write the styles in, back to front so offsets stay valid.
		foreach ( array_reverse( $tokens, true ) as $token ) {
			if ( '' === $token['css'] ) {
				continue;
			}
			$tag = $token['tag'];
			if ( '' !== $token['attr'] ) {
				$tag = preg_replace( '#\s*(/?)>$#', $token['attr'] . '$1>', $tag, 1 );
			}
			if ( preg_match( '/\sstyle\s*=\s*(["\'])(.*?)\1/is', $tag, $style ) ) {
				$merged = rtrim( trim( $style[2] ), ';' );
				$merged = ( '' !== $merged ? $merged . ';' : '' ) . $token['css'];
				$new    = str_replace( $style[0], ' style="' . esc_attr( $merged ) . '"', $tag );
			} else {
				$new = preg_replace( '#\s*(/?)>$#', ' style="' . esc_attr( $token['css'] ) . '"$1>', $tag, 1 );
			}
			$html = substr_replace( $html, $new, $token['offset'], strlen( $token['tag'] ) );
		}

		return $html;
	}

	/**
	 * Text metrics differ by fractions of a pixel between mPDF and the
	 * browser, enough to flip a line break in a cell sized to its text.
	 * Each cell's text is compared with the height mPDF measured for it:
	 * a cell that wrapped where mPDF didn't is kept on one line, one that
	 * didn't wrap where mPDF did gets its text area narrowed — each only
	 * where that reproduces mPDF's height.
	 *
	 * @return string
	 */
	private static function wrap_guard_script() {
		return '<script>(function(){'
			. 'function ch(td){var r=document.createRange();r.selectNodeContents(td);return r.getBoundingClientRect().height;}'
			. 'function fit(){'
			. 'document.querySelectorAll("td[data-challan-ch],th[data-challan-ch]").forEach(function(td){'
			. 'if(td.textContent.trim()===""||td.querySelector("table,img")){return;}'
			. 'var c=getComputedStyle(td),z=td.currentCSSZoom||1;'
			. 'var room=parseFloat(td.getAttribute("data-challan-ch"))-z*(parseFloat(c.paddingTop)+parseFloat(c.paddingBottom)+parseFloat(c.borderTopWidth)+parseFloat(c.borderBottomWidth));'
			. 'var now=ch(td);'
			// A line wrapped that mPDF kept whole: keep the cell on one line.
			. 'if(now>room+1.5){td.style.whiteSpace="nowrap";var a=ch(td);if(a>=now-0.5||a<room-1.5){td.style.whiteSpace="";}return;}'
			// mPDF wrapped a line the browser kept whole: narrow the text area until it wraps.
			. 'if(now<room-1.5){var pad=parseFloat(c.paddingRight)||0,max=td.clientWidth*0.3,add=0;'
			. 'while(ch(td)<room-1.5&&add<max){add+=1;td.style.setProperty("padding-right",(pad+add)+"px","important");}'
			. 'var b=ch(td);if(b<room-1.5||b>room+1.5){td.style.setProperty("padding-right",pad+"px","important");}}'
			// mPDF's content filled the row, so it started at the top whatever the
			// vertical-align; the browser's shorter content must start there too.
			. 'var full=parseFloat(td.getAttribute("data-challan-ch")),tr=td.parentElement;'
			. 'if(ch(td)<room-1.5&&tr&&Math.abs(tr.getBoundingClientRect().height-full)<=1.5){td.style.setProperty("vertical-align","top","important");}'
			. '});}'
			// Narrow windows: scale the sheet to fit, like a PDF viewer.
			. 'function scale(){var s=document.querySelector(".challan-sheet");if(!s){return;}s.style.zoom="";'
			. 'var room=document.documentElement.clientWidth-24,w=s.getBoundingClientRect().width;'
			. 'if(room>0&&w>room){s.style.zoom=(room/w).toFixed(4);}}'
			. 'function run(){fit();scale();window.addEventListener("resize",scale);}'
			. 'if(document.fonts&&document.fonts.ready){document.fonts.ready.then(run);}else{window.addEventListener("load",run);}'
			. '})();</script>';
	}

	/**
	 * Point every font-family in the document at the font mPDF actually
	 * used for it, and declare those fonts with @font-face.
	 *
	 * @param string     $html Document HTML.
	 * @param \Mpdf\Mpdf $mpdf Laid-out document.
	 * @return array{0:string,1:string} HTML and the @font-face style block.
	 */
	private static function apply_pdf_fonts( $html, $mpdf ) {
		$used = array();

		$default = self::resolve_font( (string) $mpdf->default_font, $mpdf );
		if ( null === $default ) {
			foreach ( array_keys( (array) $mpdf->fonts ) as $key ) {
				$family = preg_replace( '/(BI|B|I)$/', '', (string) $key );
				if ( isset( $mpdf->fontdata[ $family ] ) ) {
					$default = $family;
					break;
				}
			}
		}

		$rewrite = static function ( $matches ) use ( $mpdf, &$used ) {
			$family = null;
			foreach ( explode( ',', $matches[2] ) as $candidate ) {
				$family = self::resolve_font( $candidate, $mpdf );
				if ( null !== $family ) {
					break;
				}
			}
			if ( null === $family ) {
				// mPDF ignores families it doesn't have — the text inherits.
				return $matches[1] . 'inherit';
			}
			$used[ $family ] = true;
			// Unquoted: the declaration may sit inside a style="" attribute.
			return $matches[1] . 'challan-' . preg_replace( '/[^a-z0-9_-]/', '', $family );
		};

		// CSS blocks and style attributes alike.
		$html = preg_replace_callback( '/(font-family\s*:\s*)([^;}"<>]+(?:"[^"<>]*"[^;}"<>]*)*)/i', $rewrite, $html );

		if ( null !== $default ) {
			$used[ $default ] = true;
		}

		$css = '';
		foreach ( array_keys( $used ) as $family ) {
			$css .= self::font_faces( $family, $mpdf );
		}
		if ( null !== $default ) {
			$css .= 'body{font-family:challan-' . preg_replace( '/[^a-z0-9_-]/', '', $default ) . '}';
		}

		return array( $html, '' !== $css ? '<style id="challan-html-fonts">' . $css . '</style>' : '' );
	}

	/**
	 * Resolve a CSS family the way mPDF does: generic names, then the translation table,
	 * then the registered fonts.
	 *
	 * @param string     $name CSS family name.
	 * @param \Mpdf\Mpdf $mpdf Laid-out document.
	 * @return string|null mPDF font family key, null when mPDF has no such font.
	 */
	private static function resolve_font( $name, $mpdf ) {
		$family = strtolower( str_replace( array( '"', "'", ' ' ), '', trim( (string) $name ) ) );
		if ( '' === $family ) {
			return null;
		}

		$generic = array(
			'sans'       => 'sans_fonts',
			'sans-serif' => 'sans_fonts',
			'serif'      => 'serif_fonts',
			'mono'       => 'mono_fonts',
			'monospace'  => 'mono_fonts',
		);
		if ( isset( $generic[ $family ] ) ) {
			$list   = (array) $mpdf->{ $generic[ $family ] };
			$family = isset( $list[0] ) ? strtolower( (string) $list[0] ) : '';
		}
		if ( ! empty( $mpdf->fonttrans[ $family ] ) ) {
			$family = strtolower( (string) $mpdf->fonttrans[ $family ] );
		}

		return isset( $mpdf->fontdata[ $family ] ) ? $family : null;
	}

	/**
	 * The @font-face rules for one mPDF family — the styles the layout loaded.
	 *
	 * @param string     $family mPDF font family key.
	 * @param \Mpdf\Mpdf $mpdf   Laid-out document.
	 * @return string
	 */
	private static function font_faces( $family, $mpdf ) {
		$styles = array(
			''   => 'font-weight:normal;font-style:normal',
			'B'  => 'font-weight:bold;font-style:normal',
			'I'  => 'font-weight:normal;font-style:italic',
			'BI' => 'font-weight:bold;font-style:italic',
		);

		$css = '';
		foreach ( $styles as $suffix => $face ) {
			$file = isset( $mpdf->fonts[ $family . $suffix ]['ttffile'] ) ? (string) $mpdf->fonts[ $family . $suffix ]['ttffile'] : '';
			$url  = '' !== $file ? self::font_url( $file ) : '';
			if ( '' === $url ) {
				continue;
			}
			$css .= '@font-face{font-family:challan-' . preg_replace( '/[^a-z0-9_-]/', '', $family ) . ';src:url("' . esc_url( $url ) . '");' . $face . '}';
		}

		return $css;
	}

	/**
	 * Public URL of a font file inside the plugin or wp-content.
	 *
	 * @param string $file Absolute font path.
	 * @return string Empty when the file isn't web-reachable.
	 */
	private static function font_url( $file ) {
		$real = realpath( $file );
		if ( false === $real || ! preg_match( '/\.(ttf|otf)$/i', $real ) ) {
			return '';
		}

		$roots = array(
			(string) realpath( CHALLAN_PATH )   => CHALLAN_URL,
			(string) realpath( WP_CONTENT_DIR ) => trailingslashit( content_url() ),
		);
		foreach ( $roots as $root => $url ) {
			if ( '' !== $root && 0 === strpos( $real, trailingslashit( $root ) ) ) {
				$relative = substr( $real, strlen( trailingslashit( $root ) ) );
				return trailingslashit( $url ) . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
			}
		}

		return '';
	}

	/**
	 * Browser CSS for the configured PDF font so the HTML preview shows
	 * the same typeface as the PDF: downloaded custom fonts load via
	 *
	 * @font-face from the uploads directory; mPDF built-ins map to the
	 * closest web-safe stacks.
	 *
	 * @param \Challan\Settings\Repository|null $repository Render settings.
	 * @return string
	 */
	private static function font_css( $repository ) {
		if ( ! $repository instanceof \Challan\Settings\Repository ) {
			return '';
		}
		$family = strtolower( (string) $repository->get( 'wpifw_pdf_font_family' ) );
		if ( '' === $family ) {
			// No font chosen: the PDF prints in mPDF's default family.
			$family = 'dejavusanscondensed';
		}

		$custom = get_option( 'wpifw_custom_font_list', array() );
		if ( is_array( $custom ) && ! empty( $custom[ $family ]['R'] ) ) {
			$uploads  = wp_upload_dir();
			$base_dir = trailingslashit( $uploads['basedir'] );
			$base_url = trailingslashit( $uploads['baseurl'] );
			$css      = '';

			$faces = array(
				'R'  => 'font-weight:normal;font-style:normal',
				'B'  => 'font-weight:bold;font-style:normal',
				'I'  => 'font-weight:normal;font-style:italic',
				'BI' => 'font-weight:bold;font-style:italic',
			);
			foreach ( $faces as $style => $face_css ) {
				if ( empty( $custom[ $family ][ $style ] ) ) {
					continue;
				}
				$file = (string) $custom[ $family ][ $style ];
				foreach ( array( 'challan/fonts/', 'WOO-INVOICE/WOO-INVOICE-FONTS/' ) as $dir ) {
					if ( file_exists( $base_dir . $dir . $file ) ) {
						$css .= '@font-face{font-family:"' . esc_attr( $family ) . '";'
							. 'src:url("' . esc_url( $base_url . $dir . rawurlencode( $file ) ) . '");'
							. $face_css . '}';
						break;
					}
				}
			}

			return '' !== $css
				? '<style>' . $css . 'body{font-family:"' . esc_attr( $family ) . '",sans-serif}</style>'
				: '';
		}

		return '<style>' . self::bundled_font_faces( $family ) . 'body{font-family:' . self::web_stack( $family ) . '}</style>';
	}

	/**
	 * The @font-face rules for a DejaVu family from the font files bundled
	 * with mPDF, so the preview shows the PDF's typeface even where the
	 * browser has no DejaVu installed.
	 *
	 * @param string $family mPDF font family (lowercase).
	 * @return string CSS; empty for other families.
	 */
	private static function bundled_font_faces( $family ) {
		$files = array(
			'dejavusans'           => array( 'DejaVuSans', '-Bold', '-Oblique', '-BoldOblique' ),
			'dejavusanscondensed'  => array( 'DejaVuSansCondensed', '-Bold', '-Oblique', '-BoldOblique' ),
			'dejavuserif'          => array( 'DejaVuSerif', '-Bold', '-Italic', '-BoldItalic' ),
			'dejavuserifcondensed' => array( 'DejaVuSerifCondensed', '-Bold', '-Italic', '-BoldItalic' ),
			'dejavusansmono'       => array( 'DejaVuSansMono', '-Bold', '-Oblique', '-BoldOblique' ),
		);
		if ( ! isset( $files[ $family ] ) ) {
			return '';
		}

		list( $base, $bold, $italic, $bold_italic ) = $files[ $family ];
		$faces                                      = array(
			''           => 'font-weight:normal;font-style:normal',
			$bold        => 'font-weight:bold;font-style:normal',
			$italic      => 'font-weight:normal;font-style:italic',
			$bold_italic => 'font-weight:bold;font-style:italic',
		);
		$name                                       = self::web_stack_name( $family );
		$css                                        = '';
		foreach ( $faces as $suffix => $face ) {
			$url = self::font_url( CHALLAN_PATH . 'vendor/mpdf/mpdf/ttfonts/' . $base . $suffix . '.ttf' );
			if ( '' !== $url ) {
				$css .= '@font-face{font-family:\'' . $name . '\';src:url("' . esc_url( $url ) . '");' . $face . '}';
			}
		}

		return $css;
	}

	/**
	 * The first (named) family of an mPDF built-in's web stack.
	 *
	 * @param string $family mPDF font family (lowercase).
	 * @return string Family name without quotes.
	 */
	private static function web_stack_name( $family ) {
		$stack = explode( ',', self::web_stack( $family ) );

		return trim( $stack[0], " '\"" );
	}

	/**
	 * Closest web font stack for an mPDF family name.
	 *
	 * @param string $family mPDF font family (lowercase).
	 * @return string CSS font-family value.
	 */
	private static function web_stack( $family ) {
		// mPDF built-ins → closest web stacks.
		$stacks = array(
			'dejavusans'           => "'DejaVu Sans', Helvetica, Arial, sans-serif",
			'dejavusanscondensed'  => "'DejaVu Sans Condensed', 'Arial Narrow', Arial, sans-serif",
			'dejavuserif'          => "'DejaVu Serif', Georgia, 'Times New Roman', serif",
			'dejavuserifcondensed' => "'DejaVu Serif Condensed', Georgia, serif",
			'dejavusansmono'       => "'DejaVu Sans Mono', Menlo, Consolas, monospace",
			'freesans'             => 'FreeSans, Helvetica, Arial, sans-serif',
			'freeserif'            => "FreeSerif, Georgia, 'Times New Roman', serif",
			'freemono'             => 'FreeMono, Menlo, Consolas, monospace',
		);
		return isset( $stacks[ $family ] ) ? $stacks[ $family ] : '"' . esc_attr( $family ) . '", sans-serif';
	}

	/**
	 * Watermark-background CSS for the HTML preview — emulates the
	 * mPDF SetWatermarkImage (image fit to the page, given opacity,
	 * behind the content). Overlay-aware via the render repository.
	 *
	 * @param \Challan\Settings\Repository|null $repository Render settings.
	 * @param string                            $type       Document type.
	 * @param string                            $selector   CSS selector of the watermark layer.
	 * @param string                            $position   CSS position of the layer ('fixed' or 'absolute').
	 * @return string
	 */
	private static function background_css( $repository, $type, $selector = 'body::before', $position = 'fixed' ) {
		if ( ! $repository instanceof \Challan\Settings\Repository ) {
			return '';
		}

		$background = '';
		$opacity    = 0.3;
		if ( 'invoice' === $type && $repository->get( 'wpifw_enable_invoice_background' ) ) {
			$background = $repository->get( 'wpifw_invoice_background_attachment_id' );
			$raw        = (string) $repository->get( 'wpifw_invoice_background_opacity' );
			$opacity    = (float) $raw > 0 ? (float) $raw : 0.3; // v1: blank or 0 = default.
		} elseif ( 'packing_slip' === $type && $repository->get( 'wpifw_enable_packingslip_background' ) ) {
			$background = $repository->get( 'wpifw_packingslip_background_attachment_id' );
			$raw        = (string) $repository->get( 'wpifw_packingslip_background_opacity' );
			$opacity    = (float) $raw > 0 ? (float) $raw : 1.0;
		}

		$url = is_numeric( $background ) && (int) $background > 0
			? (string) wp_get_attachment_url( (int) $background )
			: (string) $background;
		if ( '' === $url ) {
			return '';
		}

		// Preview: behind the page content; standalone sheet: under its
		// children, which sit at z-index 1.
		return '<style>' . $selector . '{content:"";position:' . $position . ';inset:0;z-index:' . ( 'fixed' === $position ? '-1' : '0;pointer-events:none' ) . ';'
			. 'background:url("' . esc_url( $url ) . '") center / contain no-repeat;'
			. 'opacity:' . esc_attr( (string) max( 0, min( 1, $opacity ) ) ) . '}</style>';
	}

	/**
	 * Convert mPDF-only markup into browser-visible equivalents (the
	 * admin live preview, which frames the result as a page itself).
	 *
	 * @param string                            $html       Document HTML.
	 * @param \Challan\Settings\Repository|null $repository Render settings.
	 * @param string                            $type       Document type id.
	 * @param bool                              $standalone For standalone(): fonts and page box come from the layout.
	 * @return string
	 */
	public static function adapt( $html, $repository = null, $type = 'invoice', $standalone = false ) {
		// Emulate the mPDF page model: @page margins become body margins,
		// and .invoice-box (height:100% in mPDF = full page) gets an
		// explicit A4 content height so full-page template borders render
		// as they do in the PDF. A4 @96dpi = 1123px, minus 2×30px margins.
		$page_css = '<style>'
			. 'body{margin:30px}'
			. '.invoice-box{min-height:1063px;box-sizing:border-box;position:relative}'
			// mPDF ignores the templates\' negative horizontal margins on the
			// order-info band; neutralize them in the browser too so the
			// grey band spans the content width exactly like the PDF.
			. ( $standalone ? '' : '.order-table{box-sizing:border-box;margin-left:0 !important;margin-right:0 !important}' )
			// mPDF pins htmlpagefooter to the page bottom; emulate with an
			// absolutely positioned block inside the page box.
			. '.challan-preview-pagefooter{position:absolute;bottom:20px;left:20px;right:20px}'
			. '</style>';

		// Roll paper (POS receipt): no fixed page box — the sheet is as
		// long as its content and the footer follows the last line, like
		// the fitted-height PDF.
		$template_id = $repository ? (string) $repository->get( 'wpifw_templateid' ) : '';
		$roll        = in_array( $type, array( 'invoice', 'credit_note' ), true ) ? Templates::paper( $template_id ) : null;
		if ( null !== $roll && $roll['auto_height'] ) {
			$page_css .= '<style>'
				. 'body{margin:16px 14px}'
				. '.invoice-box{min-height:0}'
				. '.challan-preview-pagefooter{position:static;margin-top:14px}'
				. '</style>';
		}
		if ( ! $standalone ) {
			$page_css .= self::font_css( $repository );
		}

		// A template with its own typeface (Classic, POS receipt) wins over
		// the font setting in the PDF — mirror that here.
		$template_font = in_array( $type, array( 'invoice', 'credit_note' ), true ) && $repository
			? Templates::font( (string) $repository->get( 'wpifw_templateid' ) )
			: '';
		if ( '' !== $template_font && ! $standalone ) {
			$page_css .= '<style>body{font-family:' . self::web_stack( $template_font ) . '}</style>';
		}
		$page_css .= $standalone
			? self::background_css( $repository, $type, '.challan-sheet::before', 'absolute' )
			: self::background_css( $repository, $type );
		$html      = str_ireplace( '</head>', $page_css . '</head>', $html );

		// Repeating page footer → footer block pinned to the page bottom.
		$html = preg_replace(
			'/<htmlpagefooter[^>]*>/i',
			'<div class="challan-preview-pagefooter">',
			$html
		);
		$html = str_ireplace( '</htmlpagefooter>', '</div>', $html );
		$html = preg_replace( '/<sethtmlpagefooter[^>]*\/?>/i', '', $html );

		// Page-break markers become a visual gap with a divider (the
		// standalone view lays pages out itself).
		if ( ! $standalone ) {
			$html = str_replace(
				'class="invoice-box pageBreak"',
				'class="invoice-box pageBreak" style="border-bottom:2px dashed #cbd5e1;margin-bottom:32px;padding-bottom:32px"',
				$html
			);
		}

		// mPDF's <barcode> element means nothing to a browser — swap in
		// the REAL codes as cached SVGs (generated once per value by
		// CodeImages, reused on every subsequent preview). A dashed
		// placeholder only appears if generation is unavailable.
		$html = preg_replace_callback(
			'/<barcode\s[^>]*code="([^"]*)"[^>]*type="(QR|C39)"[^>]*\/?>/i',
			static function ( $matches ) {
				$data = html_entity_decode( $matches[1], ENT_QUOTES );
				if ( 'QR' === strtoupper( $matches[2] ) ) {
					$url = CodeImages::qr_url( $data );
					return '' !== $url
						? '<img src="' . esc_url( $url ) . '" alt="QR code" style="width:56px;height:56px;margin-top:5px;display:block"/>'
						: '<span style="display:inline-flex;width:56px;height:56px;margin-top:5px;border:1.5px dashed #94a3b8;border-radius:4px;align-items:center;justify-content:center;font:600 9px sans-serif;color:#64748b">QR</span>';
				}
				$url = CodeImages::c39_url( $data );
				return '' !== $url
					? '<img src="' . esc_url( $url ) . '" alt="Barcode" style="height:34px;margin-top:6px;display:block;max-width:170px"/>'
					: '<span style="display:inline-flex;width:150px;height:34px;margin-top:6px;border:1.5px dashed #94a3b8;border-radius:4px;align-items:center;justify-content:center;font:600 9px sans-serif;color:#64748b">BARCODE</span>';
			},
			$html
		);

		return $html;
	}
}
