<?php
/**
 * Browser rendering for the "Document Output Type: HTML" option.
 *
 * The document templates are written for mPDF. A browser renders that markup
 * differently (empty table borders, mPDF-only tags, page size, fonts), so this
 * class turns the template HTML into a page that looks like the PDF. It is only
 * used for the HTML output; the PDF is built from the untouched template HTML.
 *
 * @since      3.7.90
 * @package    Woo_Invoice
 * @subpackage Woo_Invoice/includes/classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Woo_Invoice_Html_Output' ) ) {
	/**
	 * Class Woo_Invoice_Html_Output
	 */
	class Woo_Invoice_Html_Output {

		/**
		 * Largest font file (bytes) that is embedded into the page.
		 */
		const MAX_FONT_BYTES = 2097152;

		/**
		 * Paper sizes in mm (portrait) used when mPDF's PageFormat class is not available.
		 *
		 * @var array
		 */
		private static $paper_sizes = array(
			'A3'     => array( 297, 420 ),
			'A4'     => array( 210, 297 ),
			'A5'     => array( 148, 210 ),
			'A6'     => array( 105, 148 ),
			'LETTER' => array( 215.9, 279.4 ),
			'LEGAL'  => array( 215.9, 355.6 ),
		);

		/**
		 * Mpdf config of the document (format, fonts, margins).
		 *
		 * @var array
		 */
		private $config;

		/**
		 * Running footers/headers (mPDF htmlpagefooter / htmlpageheader), keyed by marker id.
		 *
		 * @var array
		 */
		private $running = array();

		/**
		 * Constructor.
		 *
		 * @param array $config Output of Woo_Invoice_PDF::get_html_output_config().
		 */
		public function __construct( $config ) {
			$this->config = is_array( $config ) ? $config : array();
		}

		/**
		 * Turn template HTML (made for mPDF) into a browser page that matches the PDF.
		 *
		 * @param string $html Template HTML.
		 * @return string
		 */
		public function render( $html ) {
			// Markup echoed before <html> (e.g. <style> blocks printed while the template was
			// rendered) is part of the PDF's HTML; in a browser it must not come before the doctype.
			if ( preg_match( '/<html\b[^>]*>/i', $html, $tag, PREG_OFFSET_CAPTURE ) && $tag[0][1] > 0 ) {
				$lead = substr( $html, 0, $tag[0][1] );
				$html = substr( $html, $tag[0][1] );
				if ( '' !== trim( $lead ) ) {
					if ( preg_match( '/<head\b[^>]*>/i', $html, $head, PREG_OFFSET_CAPTURE ) ) {
						$at = $head[0][1] + strlen( $head[0][0] );
					} else {
						$at = strlen( $tag[0][0] );
					}
					$html = substr( $html, 0, $at ) . $lead . substr( $html, $at );
				}
			}

			list( $page_w, $page_h ) = $this->page_size();
			$margins                 = $this->page_margins( $html );
			$page_bg                 = $this->page_background( $html );

			$html = $this->apply_table_widths( $html );
			$html = $this->replace_barcodes( $html );
			$html = $this->replace_page_numbers( $html );
			// mPDF keeps a <div> inside a <p>; a browser closes the <p> and adds an empty one for the stray </p>.
			$html = preg_replace( '#<p(\s[^>]*)?>(\s*<div\b(?:(?!</?p\b).)*?</div>\s*)</p>#is', '<div class="challan-html-p"$1>$2</div>', $html );
			$html = $this->extract_running_blocks( $html );
			// mPDF draws no border for border="", a browser draws a 1px border.
			$html = preg_replace( '/\sborder\s*=\s*(""|\'\')/i', '', $html );
			// mPDF sizes a percentage image width on the enclosing block, not on the table cell.
			$html = $this->convert_image_percent_widths( $html );
			$html = $this->map_css(
				$html,
				function ( $css ) {
					return $this->add_px_to_unitless_lengths( $this->convert_font_size_keywords( $this->add_font_fallback( $css ) ) );
				}
			);
			$html = $this->wrap_pages( $html );

			$head = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
				. '<style id="challan-html-output">' . $this->font_faces( $html ) . $this->base_css( $page_w, $page_h, $margins, $page_bg, $this->config_mm( 'margin_header', 9 ), $this->config_mm( 'margin_footer', 9 ) ) . '</style>';

			// mPDF fits a full-width table between its side margins (narrower for positive,
			// wider for negative margins); a browser keeps 100% and shifts it. For tables
			// without measured widths (running footers), apply the same rule.
			$script = '<script>(function(){var t=document.querySelectorAll(".challan-html-page table");for(var i=0;i<t.length;i++){if(t[i].hasAttribute("data-challan-widths"))continue;var s=getComputedStyle(t[i]),l=parseFloat(s.marginLeft)||0,r=parseFloat(s.marginRight)||0;if(!l&&!r)continue;var p=t[i].parentElement,ps=getComputedStyle(p),w=p.clientWidth-(parseFloat(ps.paddingLeft)||0)-(parseFloat(ps.paddingRight)||0);if(Math.abs(t[i].getBoundingClientRect().width-w)<1.5){t[i].style.width=(w-l-r)+"px";}}'
				// An image or barcode alone on its line gets no descender gap in mPDF.
				. 'var m=document.querySelectorAll(".challan-html-page img,.challan-html-page .challan-html-barcode");for(var j=0;j<m.length;j++){var e=m[j].parentElement;if(e&&e.children.length===1&&!e.textContent.trim()){m[j].style.verticalAlign="top";}}})();</script>';
			if ( false !== stripos( $html, '</body>' ) ) {
				$html = preg_replace( '#</body>#i', $script . '</body>', $html, 1 );
			} else {
				$html .= $script;
			}

			if ( false !== stripos( $html, '</head>' ) ) {
				$html = preg_replace( '#</head>#i', $head . '</head>', $html, 1 );
			} else {
				$html = $head . $html;
			}

			return "<!DOCTYPE html>\n" . ltrim( $html );
		}

		/**
		 * Page width and height in mm, in the orientation mPDF uses.
		 *
		 * @return array
		 */
		private function page_size() {
			$format = isset( $this->config['format'] ) ? $this->config['format'] : 'A4';

			if ( is_array( $format ) && count( $format ) >= 2 && (float) $format[0] > 0 && (float) $format[1] > 0 ) {
				return array( (float) $format[0], (float) $format[1] );
			}

			$name      = strtoupper( trim( (string) $format ) );
			$landscape = false;
			if ( '-L' === substr( $name, -2 ) ) {
				$landscape = true;
				$name      = substr( $name, 0, -2 );
			} elseif ( '-P' === substr( $name, -2 ) ) {
				$name = substr( $name, 0, -2 );
			}

			$size = null;
			if ( class_exists( '\Mpdf\PageFormat' ) ) {
				try {
					$points = \Mpdf\PageFormat::getSizeFromName( $name );
					$size   = array( $points[0] * 25.4 / 72, $points[1] * 25.4 / 72 );
				} catch ( \Exception $e ) {
					$size = null;
				}
			}
			if ( null === $size ) {
				$size = isset( self::$paper_sizes[ $name ] ) ? self::$paper_sizes[ $name ] : self::$paper_sizes['A4'];
			}

			return $landscape ? array( $size[1], $size[0] ) : $size;
		}

		/**
		 * Page margins in mm (top, right, bottom, left) from the last @page rule, as mPDF applies it.
		 *
		 * @param string $html Template HTML.
		 * @return array
		 */
		private function page_margins( $html ) {
			$margins = array(
				isset( $this->config['margin_top'] ) ? (float) $this->config['margin_top'] : 16,
				isset( $this->config['margin_right'] ) ? (float) $this->config['margin_right'] : 15,
				isset( $this->config['margin_bottom'] ) ? (float) $this->config['margin_bottom'] : 16,
				isset( $this->config['margin_left'] ) ? (float) $this->config['margin_left'] : 15,
			);

			if ( preg_match_all( '/@page\s*\{([^}]*)\}/i', $html, $rules ) ) {
				foreach ( $rules[1] as $rule ) {
					if ( preg_match( '/(?:^|;|\s)margin\s*:\s*([^;]+)/i', $rule, $m ) ) {
						$values = preg_split( '/\s+/', trim( $m[1] ) );
						$values = array_map( array( $this, 'to_mm' ), $values );
						switch ( count( $values ) ) {
							case 1:
								$margins = array( $values[0], $values[0], $values[0], $values[0] );
								break;
							case 2:
								$margins = array( $values[0], $values[1], $values[0], $values[1] );
								break;
							case 3:
								$margins = array( $values[0], $values[1], $values[2], $values[1] );
								break;
							default:
								$margins = array_slice( $values, 0, 4 );
						}
					}
					foreach ( array( 'top', 'right', 'bottom', 'left' ) as $i => $side ) {
						if ( preg_match( '/margin-' . $side . '\s*:\s*([^;]+)/i', $rule, $m ) ) {
							$margins[ $i ] = $this->to_mm( trim( $m[1] ) );
						}
					}
				}
			}

			return $margins;
		}

		/**
		 * Page background colour from the last @page rule.
		 *
		 * @param string $html Template HTML.
		 * @return string
		 */
		private function page_background( $html ) {
			$background = '#fff';
			if ( preg_match_all( '/@page\s*\{([^}]*)\}/i', $html, $rules ) ) {
				foreach ( $rules[1] as $rule ) {
					if ( preg_match( '/background(?:-color)?\s*:\s*(#[0-9a-f]{3,8}|rgba?\([^)]*\)|[a-z]+)\s*(?:;|$)/i', $rule, $m ) ) {
						$background = $m[1];
					}
				}
			}
			return $background;
		}

		/**
		 * Convert a CSS length to mm (mPDF uses 96 dpi for px).
		 *
		 * @param string $value CSS length.
		 * @return float
		 */
		private function to_mm( $value ) {
			if ( ! preg_match( '/^(-?[\d.]+)\s*(px|mm|cm|in|pt|pc)?$/i', trim( $value ), $m ) ) {
				return 0;
			}
			$number = (float) $m[1];
			$unit   = isset( $m[2] ) ? strtolower( $m[2] ) : 'px';
			$factor = array(
				'px' => 25.4 / 96,
				'mm' => 1,
				'cm' => 10,
				'in' => 25.4,
				'pt' => 25.4 / 72,
				'pc' => 25.4 / 6,
			);
			return $number * $factor[ $unit ];
		}

		/**
		 * Give every table the column widths mPDF calculates for the PDF.
		 *
		 * mPDF shares out free table width by text length, a browser by its own rules,
		 * so columns would land in different places. The document is laid out once with
		 * mPDF (nothing is written) and the widths are copied onto the HTML tables.
		 *
		 * @param string $html Template HTML.
		 * @return string
		 */
		private function apply_table_widths( $html ) {
			if ( ! class_exists( '\Mpdf\Mpdf' ) ) {
				return $html;
			}
			require_once __DIR__ . '/class-woo-invoice-html-output-mpdf.php';
			if ( ! class_exists( 'Woo_Invoice_Html_Output_Mpdf' ) ) {
				return $html;
			}

			// Running footers are laid out separately by mPDF; they are not measured.
			$measure = preg_replace( '#<htmlpagefooter\b.*?</htmlpagefooter\s*>#is', '', $html );
			$config  = $this->config;
			unset( $config['watermark'] );

			ob_start(); // mPDF notices must not reach the page.
			try {
				$mpdf                   = new Woo_Invoice_Html_Output_Mpdf( $config );
				$mpdf->autoScriptToLang = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$mpdf->baseScript       = 1; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$mpdf->autoVietnamese   = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$mpdf->autoArabic       = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$mpdf->WriteHTML( $measure );
				$groups = $mpdf->challan_table_widths;
			} catch ( \Throwable $e ) {
				$groups = array();
			}
			ob_end_clean();

			if ( empty( $groups ) ) {
				return $html;
			}

			// Walk the tables outside running footers in document order, as mPDF numbers them.
			preg_match_all( '#<table\b[^>]*>|</table\s*>|<tr\b[^>]*>|<htmlpagefooter\b|</htmlpagefooter\s*>#i', $html, $tags, PREG_OFFSET_CAPTURE );
			$depth     = 0;
			$in_footer = false;
			$group     = -1;
			$counters  = array();
			$edits     = array();
			$open      = array(); // Index in $edits of the table open at each depth.
			foreach ( $tags[0] as $tag ) {
				$text = strtolower( substr( $tag[0], 0, 6 ) );
				if ( 0 === strpos( strtolower( $tag[0] ), '<htmlpagefooter' ) ) {
					$in_footer = true;
					continue;
				}
				if ( 0 === strpos( strtolower( $tag[0] ), '</htmlpagefooter' ) ) {
					$in_footer = false;
					continue;
				}
				if ( $in_footer ) {
					continue;
				}
				if ( '</tabl' === $text ) {
					$depth = max( 0, $depth - 1 );
					continue;
				}
				if ( 0 === strpos( $text, '<tr' ) && ! ctype_alpha( substr( $tag[0], 3, 1 ) ) ) {
					if ( $depth && isset( $open[ $depth ] ) ) {
						$edits[ $open[ $depth ] ][3][] = array( $tag[1], $tag[0] );
					}
					continue;
				}
				++$depth;
				if ( 1 === $depth ) {
					++$group;
					$counters = array();
				}
				$counters[ $depth ] = isset( $counters[ $depth ] ) ? $counters[ $depth ] + 1 : 1;
				if ( ! isset( $groups[ $group ][ $depth ][ $counters[ $depth ] ] ) ) {
					return $html; // Structure differs from what mPDF saw: keep the browser's layout.
				}
				$open[ $depth ] = count( $edits );
				$edits[]        = array( $tag[1], $tag[0], $groups[ $group ][ $depth ][ $counters[ $depth ] ], array() );
			}
			if ( count( $groups ) !== $group + 1 ) {
				return $html;
			}

			// Row heights: mPDF's row heights as minimum heights, when the rows match one to one.
			$rows = array();
			foreach ( $edits as $edit ) {
				$heights = $edit[2]['hr'];
				if ( ! empty( $heights ) && count( $heights ) === count( $edit[3] ) ) {
					foreach ( $edit[3] as $i => $row ) {
						$rows[] = array( $row[0], $row[1], $heights[ $i ] );
					}
				}
			}
			foreach ( array_reverse( $rows ) as $row ) {
				list( $offset, $tag, $height ) = $row;
				$css = 'height:' . round( $height, 3 ) . 'mm;';
				if ( preg_match( '/\sstyle\s*=\s*(["\'])/i', $tag ) ) {
					$new = preg_replace( '/(\sstyle\s*=\s*["\'])/i', '$1' . $css, $tag, 1 );
				} else {
					$new = substr( $tag, 0, -1 ) . ' style="' . $css . '">';
				}
				$html = substr( $html, 0, $offset ) . $new . substr( $html, $offset + strlen( $tag ) );
				foreach ( $edits as $i => $edit ) {
					if ( $edit[0] > $offset ) {
						$edits[ $i ][0] += strlen( $new ) - strlen( $tag );
					}
				}
			}

			foreach ( array_reverse( $edits ) as $edit ) {
				list( $offset, $open, $data ) = $edit;
				$widths = $data['wc'];
				if ( empty( $widths ) ) {
					continue;
				}
				$cols = '';
				foreach ( $widths as $width ) {
					$cols .= '<col style="width:' . round( $width, 3 ) . 'mm">';
				}
				$css = 'table-layout:fixed;width:' . round( array_sum( $widths ), 3 ) . 'mm;';
				if ( preg_match( '/\sstyle\s*=\s*(["\'])/i', $open, $quote ) ) {
					$tag = preg_replace( '/(\sstyle\s*=\s*["\'])/i', '$1' . $css, $open, 1 );
				} else {
					$tag = substr( $open, 0, -1 ) . ' style="' . $css . '">';
				}
				$tag  = substr( $tag, 0, -1 ) . ' data-challan-widths="1">';
				$html = substr( $html, 0, $offset ) . $tag . '<colgroup>' . $cols . '</colgroup>' . substr( $html, $offset + strlen( $open ) );
			}

			return $html;
		}

		/**
		 * Replace mPDF <barcode> tags with inline SVG of the same size.
		 *
		 * @param string $html Template HTML.
		 * @return string
		 */
		private function replace_barcodes( $html ) {
			return preg_replace_callback(
				'#<barcode\b([^>]*?)/?>(?:\s*</barcode>)?#i',
				function ( $match ) {
					$attr = array();
					if ( preg_match_all( '/([\w-]+)\s*=\s*("([^"]*)"|\'([^\']*)\')/', $match[1], $pairs, PREG_SET_ORDER ) ) {
						foreach ( $pairs as $pair ) {
							$attr[ strtolower( $pair[1] ) ] = html_entity_decode( isset( $pair[4] ) ? $pair[4] : $pair[3], ENT_QUOTES, 'UTF-8' );
						}
					}
					$svg = $this->barcode_svg( $attr );
					if ( '' === $svg ) {
						return '';
					}
					$class = isset( $attr['class'] ) ? ' ' . $attr['class'] : '';
					$style = isset( $attr['style'] ) ? $attr['style'] : '';
					return '<span class="challan-html-barcode' . esc_attr( $class ) . '" style="' . esc_attr( $style ) . '">' . $svg . '</span>';
				},
				$html
			);
		}

		/**
		 * SVG for one barcode, sized like mPDF draws it.
		 *
		 * @param array $attr Barcode tag attributes.
		 * @return string
		 */
		private function barcode_svg( $attr ) {
			$code = isset( $attr['code'] ) ? $attr['code'] : '';
			$type = isset( $attr['type'] ) ? strtoupper( $attr['type'] ) : 'EAN13';
			$size = isset( $attr['size'] ) && (float) $attr['size'] > 0 ? (float) $attr['size'] : 1;
			if ( '' === $code ) {
				return '';
			}

			try {
				if ( 'QR' === $type ) {
					if ( ! class_exists( '\Mpdf\QrCode\QrCode' ) || ! class_exists( '\Mpdf\QrCode\Output\Svg' ) ) {
						return '';
					}
					$level  = isset( $attr['error'] ) ? strtoupper( $attr['error'] ) : 'L';
					$qrcode = new \Mpdf\QrCode\QrCode( str_replace( array( '\r\n', '\n' ), array( "\r\n", "\n" ), $code ), $level );
					if ( ! empty( $attr['disableborder'] ) ) {
						$qrcode->disableBorder();
					}
					$svg = ( new \Mpdf\QrCode\Output\Svg() )->output( $qrcode, 100, '#fff', '#000' );
					$svg = preg_replace( '/^<\?xml[^>]*>\s*/', '', $svg );
					$mm  = $size * 25; // mPDF: QR is 25 mm times "size".
					return preg_replace( '/<svg([^>]*?)\swidth="100"\sheight="100"/', '<svg$1 width="' . $mm . 'mm" height="' . $mm . 'mm" viewBox="0 0 100 100" shape-rendering="crispEdges"', $svg, 1 );
				}

				if ( ! class_exists( '\Mpdf\Barcode' ) ) {
					return '';
				}
				$height  = isset( $attr['height'] ) && (float) $attr['height'] > 0 ? (float) $attr['height'] : 1;
				$ratio   = isset( $attr['pr'] ) ? $attr['pr'] : '';
				$arrcode = ( new \Mpdf\Barcode() )->getBarcodeArray( $code, $type, $ratio );
				if ( empty( $arrcode['bcode'] ) ) {
					return '';
				}
				$xres = $arrcode['nom-X'] * $size;
				$llm  = isset( $arrcode['lightmL'] ) ? $arrcode['lightmL'] * $xres : 0;
				$rlm  = isset( $arrcode['lightmR'] ) ? $arrcode['lightmR'] * $xres : 0;
				$tlm  = isset( $arrcode['lightTB'] ) ? $arrcode['lightTB'] * $xres * $height : 0;
				$bch  = $arrcode['nom-H'] * $size * $height;
				$w    = $arrcode['maxw'] * $xres + $llm + $rlm;
				$h    = $bch + 2 * $tlm;

				$rects = '';
				$x     = $llm;
				foreach ( $arrcode['bcode'] as $bar ) {
					$bw = $bar['w'] * $xres;
					if ( ! empty( $bar['t'] ) ) {
						$bar_h  = isset( $bar['h'] ) && isset( $bar['p'] ) ? $bar['h'] * $bch / $arrcode['maxh'] : $bch;
						$bar_y  = isset( $bar['h'] ) && isset( $bar['p'] ) ? $tlm + $bar['p'] * $bch / $arrcode['maxh'] : $tlm;
						$rects .= sprintf( '<rect x="%.4F" y="%.4F" width="%.4F" height="%.4F"/>', $x, $bar_y, $bw, $bar_h );
					}
					$x += $bw;
				}

				return sprintf(
					'<svg xmlns="http://www.w3.org/2000/svg" width="%1$.4Fmm" height="%2$.4Fmm" viewBox="0 0 %1$.4F %2$.4F" shape-rendering="crispEdges"><rect width="100%%" height="100%%" fill="#fff"/><g fill="#000">%3$s</g></svg>',
					$w,
					$h,
					$rects
				);
			} catch ( \Exception $e ) {
				return '';
			}
		}

		/**
		 * Take mPDF running footers/headers out of the flow; they are drawn on the page
		 * itself (see wrap_pages), like mPDF writes them in the page margin area.
		 *
		 * @param string $html Template HTML.
		 * @return string
		 */
		private function extract_running_blocks( $html ) {
			$html = preg_replace_callback(
				'#<(htmlpagefooter|htmlpageheader)\b[^>]*>(.*?)</\1\s*>#is',
				function ( $m ) {
					$id                   = count( $this->running );
					$this->running[ $id ] = array(
						'type' => 'htmlpageheader' === strtolower( $m[1] ) ? 'header' : 'footer',
						'html' => $m[2],
					);
					return '<!--challan-running-' . $id . '-->';
				},
				$html
			);
			return preg_replace( '#<(sethtmlpagefooter|sethtmlpageheader)\b[^>]*>(\s*</\1\s*>)?#i', '', $html );
		}

		/**
		 * A length from the mPDF config, in mm.
		 *
		 * @param string $key     Config key.
		 * @param float  $default Default (mm).
		 * @return float
		 */
		private function config_mm( $key, $default ) {
			return isset( $this->config[ $key ] ) && is_numeric( $this->config[ $key ] ) ? (float) $this->config[ $key ] : $default;
		}

		/**
		 * Apply a CSS rewrite to <style> blocks and style="" attributes only (never to text).
		 *
		 * @param string   $html     Template HTML.
		 * @param callable $callback Receives and returns CSS text.
		 * @return string
		 */
		private function map_css( $html, $callback ) {
			$html = preg_replace_callback(
				'#(<style\b[^>]*>)(.*?)(</style\s*>)#is',
				function ( $m ) use ( $callback ) {
					return $m[1] . call_user_func( $callback, $m[2] ) . $m[3];
				},
				$html
			);
			return preg_replace_callback(
				'#(<[a-z][a-z0-9]*\b[^>]*?\sstyle\s*=\s*)(["\'])(.*?)\2#is',
				function ( $m ) use ( $callback ) {
					return $m[1] . $m[2] . call_user_func( $callback, $m[3] ) . $m[2];
				},
				$html
			);
		}

		/**
		 * mPDF reads a unitless length ("font-size:12") as pixels; a browser ignores it.
		 *
		 * @param string $html Template HTML.
		 * @return string
		 */
		private function add_px_to_unitless_lengths( $html ) {
			$props = 'font-size|(?:min-|max-)?(?:width|height)|margin(?:-top|-right|-bottom|-left)?|padding(?:-top|-right|-bottom|-left)?|top|right|bottom|left|text-indent|letter-spacing|word-spacing|border(?:-top|-right|-bottom|-left)?-width';
			return preg_replace_callback(
				'/(?<![\w-])(' . $props . ')(\s*:\s*)([^;"\'}<>]+)/i',
				function ( $m ) {
					$value = preg_replace( '/(?<![\w.#%-])(-?(?:\d+\.?\d*|\.\d+))(?![\w.%])/', '$1px', $m[3] );
					return $m[1] . $m[2] . $value;
				},
				$html
			);
		}

		/**
		 * mPDF scales font-size keywords from the parent font size; a browser uses fixed sizes.
		 *
		 * @param string $html Template HTML.
		 * @return string
		 */
		private function convert_font_size_keywords( $html ) {
			$ratios = array(
				'xx-small' => 0.7,
				'x-small'  => 0.77,
				'small'    => 0.86,
				'large'    => 1.2,
				'x-large'  => 1.5,
				'xx-large' => 2,
			);
			return preg_replace_callback(
				'/(font-size\s*:\s*)(xx-small|x-small|small|large|x-large|xx-large)\b/i',
				function ( $m ) use ( $ratios ) {
					return $m[1] . $ratios[ strtolower( $m[2] ) ] . 'em';
				},
				$html
			);
		}

		/**
		 * Replace mPDF page number placeholders (one page per document box).
		 *
		 * @param string $html Template HTML.
		 * @return string
		 */
		private function replace_page_numbers( $html ) {
			$pages = max( 1, preg_match_all( '/class\s*=\s*["\'][^"\']*\binvoice-box\b/i', $html ) );
			$page  = 0;
			$html  = preg_replace_callback(
				'/\{PAGENO\}/',
				function () use ( &$page ) {
					return (string) ++$page;
				},
				$html
			);
			return str_replace( array( '{nbpg}', '{nb}' ), (string) $pages, $html );
		}

		/**
		 * Rewrite "width: N%" on images to container units, so the width is taken
		 * from the document box like mPDF does; drop responsive-image attributes.
		 *
		 * @param string $html Template HTML.
		 * @return string
		 */
		private function convert_image_percent_widths( $html ) {
			return preg_replace_callback(
				'#<img\b[^>]*>#i',
				function ( $match ) {
					// mPDF ignores responsive-image attributes and always draws "src".
					$tag = preg_replace( '/\s(srcset|sizes|loading|decoding)\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $match[0] );
					return preg_replace_callback(
						'/(style\s*=\s*["\'][^"\']*?\bwidth\s*:\s*)([\d.]+)%/i',
						function ( $m ) {
							return $m[1] . $m[2] . 'cqw';
						},
						$tag
					);
				},
				$html
			);
		}

		/**
		 * mPDF uses its default font for any family it does not know; a browser would
		 * use its own default. Add the default font as fallback to every font-family.
		 *
		 * @param string $html Template HTML.
		 * @return string
		 */
		private function add_font_fallback( $html ) {
			return preg_replace_callback(
				'/(font-family\s*:\s*)([^;"}<]+)/i',
				function ( $m ) {
					$value = rtrim( $m[2] );
					if ( '' === $value || false !== stripos( $value, 'challan-default' ) || preg_match( '/!important\s*$/i', $value ) ) {
						return $m[0];
					}
					return $m[1] . $value . ', challan-default';
				},
				$html
			);
		}

		/**
		 * Put every document box (one invoice / packing slip) on its own paper sheet,
		 * with its running footer/header drawn on that sheet.
		 *
		 * @param string $html Template HTML.
		 * @return string
		 */
		private function wrap_pages( $html ) {
			$box_pattern = '/<div\b[^>]*class\s*=\s*["\'][^"\']*\binvoice-box\b[^>]*>/i';

			if ( preg_match_all( $box_pattern, $html, $boxes, PREG_OFFSET_CAPTURE ) ) {
				// Each box starts a sheet; the sheet ends where the next box starts.
				$body_end = stripos( $html, '</body>' );
				$body_end = false === $body_end ? strlen( $html ) : $body_end;
				$starts   = array();
				foreach ( $boxes[0] as $box ) {
					$starts[] = $box[1];
				}
				$out  = substr( $html, 0, $starts[0] );
				$last = count( $starts ) - 1;
				foreach ( $starts as $i => $start ) {
					$end  = $i < $last ? $starts[ $i + 1 ] : $body_end;
					$out .= $this->page( substr( $html, $start, $end - $start ), '' );
				}
				return $out . substr( $html, $body_end );
			}

			// Documents without a box (shipping label): one sheet for the whole body.
			if ( preg_match( '#<body\b[^>]*>#i', $html, $body, PREG_OFFSET_CAPTURE ) ) {
				$start    = $body[0][1] + strlen( $body[0][0] );
				$body_end = stripos( $html, '</body>', $start );
				$body_end = false === $body_end ? strlen( $html ) : $body_end;
				return substr( $html, 0, $start ) . $this->page( substr( $html, $start, $body_end - $start ), ' challan-html-page-plain' ) . substr( $html, $body_end );
			}
			return $this->page( $html, ' challan-html-page-plain' );
		}

		/**
		 * One paper sheet; running footers/headers found in the content are drawn on the sheet.
		 *
		 * @param string $content Page content.
		 * @param string $class   Extra class.
		 * @return string
		 */
		private function page( $content, $class ) {
			$running = '';
			$content = preg_replace_callback(
				'/<!--challan-running-(\d+)-->/',
				function ( $m ) use ( &$running ) {
					if ( isset( $this->running[ (int) $m[1] ] ) ) {
						$block    = $this->running[ (int) $m[1] ];
						$running .= '<div class="challan-html-' . $block['type'] . '">' . $block['html'] . '</div>';
					}
					return '';
				},
				$content
			);
			return '<div class="challan-html-page' . $class . '">' . $content . $running . '</div>';
		}

		/**
		 * Embedded @font-face rules for the fonts mPDF uses in this document.
		 *
		 * @param string $html Template HTML.
		 * @return string
		 */
		private function font_faces( $html ) {
			$fontdata = isset( $this->config['fontdata'] ) && is_array( $this->config['fontdata'] ) ? $this->config['fontdata'] : array();
			$default  = ! empty( $this->config['default_font'] ) ? strtolower( $this->config['default_font'] ) : 'dejavuserifcondensed';
			if ( ! isset( $fontdata[ $default ] ) ) {
				// mPDF falls back to DejaVu Sans Condensed when the chosen font isn't available (e.g. a Challan Pro custom font).
				$default = 'dejavusanscondensed';
			}

			$families = array( 'challan-default' => $default );
			if ( preg_match_all( '/font-family\s*:\s*([^;"}<]+)/i', $html, $m ) ) {
				foreach ( $m[1] as $list ) {
					foreach ( explode( ',', $list ) as $name ) {
						$name = strtolower( trim( $name, " \t\n\r'\"" ) );
						if ( isset( $fontdata[ $name ] ) ) {
							$families[ $name ] = $name;
						}
					}
				}
			}

			// Italic files are only embedded when the document uses italics.
			$variants = array(
				'R' => array( 'normal', 'normal' ),
				'B' => array( 'bold', 'normal' ),
			);
			if ( preg_match( '/<(i|em|cite|var|address|q)\b|font-style\s*:\s*(italic|oblique)/i', $html ) ) {
				$variants['I']  = array( 'normal', 'italic' );
				$variants['BI'] = array( 'bold', 'italic' );
			}

			$css = '';
			foreach ( $families as $family => $key ) {
				if ( empty( $fontdata[ $key ] ) ) {
					continue;
				}
				foreach ( $variants as $variant => $style ) {
					if ( empty( $fontdata[ $key ][ $variant ] ) ) {
						continue;
					}
					$file = $this->find_font_file( $fontdata[ $key ][ $variant ] );
					if ( ! $file ) {
						continue;
					}
					$css .= sprintf(
						'@font-face{font-family:"%s";src:url(data:font/ttf;base64,%s) format("truetype");font-weight:%s;font-style:%s;}',
						$family,
						base64_encode( file_get_contents( $file ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Embeds a local font file into the page.
						$style[0],
						$style[1]
					);
				}
			}

			return $css;
		}

		/**
		 * Locate a font file in mPDF's font directories.
		 *
		 * @param string $file Font file name.
		 * @return string|false
		 */
		private function find_font_file( $file ) {
			$dirs = isset( $this->config['fontDir'] ) ? (array) $this->config['fontDir'] : array();
			foreach ( $dirs as $dir ) {
				$path = trailingslashit( $dir ) . $file;
				if ( is_readable( $path ) && filesize( $path ) <= self::MAX_FONT_BYTES ) {
					return $path;
				}
			}
			return false;
		}

		/**
		 * Background image ("watermark") drawn over the page like mPDF's SetWatermarkImage( $src, $alpha, 'P', 'P' ).
		 *
		 * @return string
		 */
		private function watermark_css() {
			if ( empty( $this->config['watermark']['background'] ) ) {
				return '';
			}
			$src     = $this->config['watermark']['background'];
			$opacity = isset( $this->config['watermark']['opacity'] ) && '' !== $this->config['watermark']['opacity'] ? (float) $this->config['watermark']['opacity'] : 0.5;

			if ( is_numeric( $src ) ) {
				$src = wp_get_attachment_url( (int) $src );
			} elseif ( ! preg_match( '#^(https?:)?//#i', $src ) ) {
				$uploads = wp_get_upload_dir();
				$path    = wp_normalize_path( $src );
				$base    = wp_normalize_path( $uploads['basedir'] );
				$src     = 0 === strpos( $path, $base ) ? $uploads['baseurl'] . substr( $path, strlen( $base ) ) : '';
			}
			if ( empty( $src ) ) {
				return '';
			}

			return '.challan-html-page::before{content:"";position:absolute;inset:0;pointer-events:none;z-index:1;'
				. 'background:url("' . esc_url_raw( $src ) . '") center/contain no-repeat;opacity:' . max( 0, min( 1, $opacity ) ) . ';}';
		}

		/**
		 * Browser CSS that reproduces mPDF's page layout and defaults.
		 *
		 * @param float  $page_w  Page width (mm).
		 * @param float  $page_h  Page height (mm).
		 * @param array  $margins Page margins (mm): top, right, bottom, left.
		 * @param string $page_bg Page background colour.
		 * @param float  $header  Distance of a running header from the top edge (mm).
		 * @param float  $footer  Distance of a running footer from the bottom edge (mm).
		 * @return string
		 */
		private function base_css( $page_w, $page_h, $margins, $page_bg, $header, $footer ) {
			$mm = function ( $value ) {
				return round( $value, 3 ) . 'mm';
			};

			return 'html{background:#525659;}'
				. 'body{margin:0!important;padding:24px 0!important;line-height:1.2;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
				. '.challan-html-page{box-sizing:border-box;position:relative;display:flex;flex-direction:column;'
				. 'width:' . $mm( $page_w ) . ';min-height:' . $mm( $page_h ) . ';margin:0 auto 24px;'
				. 'padding:' . $mm( $margins[0] ) . ' ' . $mm( $margins[1] ) . ' ' . $mm( $margins[2] ) . ' ' . $mm( $margins[3] ) . ';'
				. 'background:' . $page_bg . ';box-shadow:0 1px 6px rgba(0,0,0,.45);container-type:inline-size;overflow:hidden;}'
				. '.challan-html-page>.invoice-box{flex:1 0 auto;box-sizing:border-box;width:auto!important;max-width:none!important;height:auto!important;margin:0!important;container-type:inline-size;}'
				. '.challan-html-footer,.challan-html-header{position:absolute;z-index:2;left:' . $mm( $margins[3] ) . ';right:' . $mm( $margins[1] ) . ';}'
				. '.challan-html-footer{bottom:' . $mm( $footer ) . ';}'
				. '.challan-html-header{top:' . $mm( $header ) . ';}'
				// mPDF collapses margins at the page edge: the bottom margin of a footer's last block, the top margin of a header's first block.
				. '.challan-html-footer :is(p,div,table,.challan-html-p):last-child{margin-bottom:0!important;}'
				. '.challan-html-header :is(p,div,table,.challan-html-p):first-child{margin-top:0!important;}'
				// mPDF ignores margins and padding of block elements inside table cells.
				. '.challan-html-page :is(td,th) :is(p,div,h1,h2,h3,h4,h5,h6){margin:0!important;padding:0!important;}'
				// mPDF defaults (zero specificity, so template rules still win).
				. ':where(.challan-html-page){line-height:1.33;}'
				. ':where(.challan-html-page) :is(p,.challan-html-p){margin:1.12em 0;}'
				. ':where(.challan-html-page) table{line-height:1.2;vertical-align:middle;}'
				. ':where(.challan-html-page) :is(thead,tbody,tfoot,tr,td,th):not([valign]){vertical-align:inherit;}'
				. ':where(.challan-html-page) :is(td,th){padding:0.1em;}'
				. '.challan-html-page :is(td,th){box-sizing:border-box;}'
				. ':where(.challan-html-page) hr{border:0;height:0.2mm;background:#888;color:#888;margin:0.83em auto;}'
				. '.challan-html-page img{max-width:none;}'
				. '.challan-html-barcode{display:inline-block;line-height:0;}'
				. '.challan-html-barcode svg{display:block;}'
				. $this->watermark_css()
				. '@media print{html,body{background:none!important;padding:0!important;}'
				. '.challan-html-page{margin:0!important;box-shadow:none!important;break-after:page;}'
				. '@page{size:' . $mm( $page_w ) . ' ' . $mm( $page_h ) . ';margin:0;}}';
		}
	}
}

if ( ! function_exists( 'woo_invoice_print_html_output' ) ) {
	/**
	 * Print a document (invoice, packing slip, label) as a browser page that matches its PDF.
	 *
	 * @param callable|string $html          Callable that renders the template HTML (its echoed output is
	 *                                captured like the PDF does), or the HTML itself.
	 * @param string          $order_ids     Order id(s).
	 * @param string          $template_type invoice, packing_slip or label.
	 * @param string          $paper_size    Paper size passed to the PDF (labels).
	 * @return void
	 */
	function woo_invoice_print_html_output( $html, $order_ids, $template_type, $paper_size = 'A4' ) {
		if ( is_callable( $html ) ) {
			ob_start();
			echo call_user_func( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured, as the PDF does.
			$html = ob_get_clean();
		}
		$pdf    = woo_invoice_pdf( '', '', $order_ids, $template_type, $paper_size );
		$output = new Woo_Invoice_Html_Output( $pdf->get_html_output_config() );
		echo $output->render( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Document HTML built by the plugin's own templates.
	}
}
