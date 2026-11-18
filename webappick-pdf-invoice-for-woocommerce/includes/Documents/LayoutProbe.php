<?php
/**
 * The mPDF layout recorder for the HTML view.
 *
 * @package Challan
 */

namespace Challan\Documents;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * An mPDF instance that remembers the geometry it laid out — the size of
 * every image and the column widths / row heights of every table — so the
 * HTML view can reproduce the PDF instead of re-laying the markup out with
 * the browser's different rules (mPDF sizes percentage images against the
 * page, auto-sizes columns its own way and shrinks over-wide tables).
 *
 * Only used for the "Output as HTML" view; PDFs use plain mPDF.
 */
class LayoutProbe extends \Mpdf\Mpdf {

	/**
	 * The CSS recorder, when installed.
	 *
	 * @var CssRecorder|null
	 */
	public $challan_css = null;

	/**
	 * Recorded geometry, in mm, in the order mPDF painted it.
	 *
	 * @var array
	 */
	public $challan_layout = array(
		'images' => array(),
		'codes'  => array(),
		'tables' => array(),
	);

	/**
	 * Lay out like mPDF, with mPDF's CSS resolution observed (CssRecorder).
	 * When the engine's internals don't allow it, the probe still records
	 * the geometry; the HTML view then relies on the template CSS.
	 *
	 * @param array $config mPDF configuration.
	 */
	public function __construct( array $config = array() ) {
		parent::__construct( $config );

		try {
			$this->install_css_recorder();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Swap mPDF's CSS manager for a CssRecorder carrying the same state,
	 * in every service that holds it, before any document HTML is read.
	 *
	 * @return void
	 * @throws \ReflectionException When mPDF's internals differ.
	 */
	private function install_css_recorder() {
		$read  = static function ( $target, $class_name, $name ) {
			$property = new \ReflectionProperty( $class_name, $name );
			$property->setAccessible( true );
			return $property->getValue( $target );
		};
		$write = static function ( $target, $class_name, $name, $value ) {
			$property = new \ReflectionProperty( $class_name, $name );
			$property->setAccessible( true );
			$property->setValue( $target, $value );
		};

		$original = $read( $this, \Mpdf\Mpdf::class, 'cssManager' );
		if ( ! $original instanceof \Mpdf\CssManager ) {
			return;
		}

		$recorder = ( new \ReflectionClass( CssRecorder::class ) )->newInstanceWithoutConstructor();
		foreach ( ( new \ReflectionClass( \Mpdf\CssManager::class ) )->getProperties() as $property ) {
			if ( $property->isStatic() ) {
				continue;
			}
			$property->setAccessible( true );
			$property->setValue( $recorder, $property->getValue( $original ) );
		}

		$write( $this, \Mpdf\Mpdf::class, 'cssManager', $recorder );
		$write( $read( $this, \Mpdf\Mpdf::class, 'tag' ), \Mpdf\Tag::class, 'cssManager', $recorder );
		$write( $read( $this, \Mpdf\Mpdf::class, 'imageProcessor' ), \Mpdf\Image\ImageProcessor::class, 'cssManager', $recorder );
		$read( $recorder, \Mpdf\CssManager::class, 'cssMerger' )->setCssManager( $recorder );

		$this->challan_css = $recorder;
	}

	/**
	 * Resolved CSS per element (see CssRecorder), empty when not observed.
	 *
	 * @return array<int,array{tag:string,class:string,props:array<string,string>,context:string}>
	 */
	public function challan_css_records() {
		return $this->challan_css instanceof CssRecorder ? $this->challan_css->challan_records : array();
	}

	/**
	 * Record the painted size (mm, as drawn) of buffered images and
	 * barcodes, then paint them.
	 *
	 * @param bool        $is_table  Whether the buffer belongs to a table cell.
	 * @param string|bool $blockdir  Block direction.
	 * @return mixed
	 */
	public function printobjectbuffer( $is_table = false, $blockdir = false ) {
		foreach ( (array) $this->objectbuffer as $object ) {
			$attr = isset( $object['objattr'] ) && is_array( $object['objattr'] ) ? $object['objattr'] : $object;
			if ( is_array( $attr ) && isset( $attr['type'] ) && 'barcode' === $attr['type'] ) {
				$this->challan_layout['codes'][] = array(
					'qr' => isset( $attr['btype'] ) && 'QR' === strtoupper( (string) $attr['btype'] ),
					'w'  => (float) $attr['INNER-WIDTH'],
					'h'  => (float) $attr['INNER-HEIGHT'],
				);
				continue;
			}
			if ( ! is_array( $attr ) || ! isset( $attr['type'] ) || 'image' !== $attr['type'] ) {
				continue;
			}
			$this->challan_layout['images'][] = array(
				'file' => isset( $attr['file'] ) ? (string) $attr['file'] : '',
				'w'    => (float) $attr['INNER-WIDTH'],
				'h'    => (float) $attr['INNER-HEIGHT'],
			);
		}

		return parent::printobjectbuffer( $is_table, $blockdir );
	}

	/**
	 * Record where the page footer ended up: mPDF writes it down from
	 * margin_footer above the paper edge, then lifts it by its height.
	 *
	 * @param float $adjustmenty Footer height (mm) while writing the footer.
	 * @return mixed
	 */
	public function PrintPageBackgrounds( $adjustmenty = 0 ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mPDF method name.
		if ( $this->writingHTMLfooter && ! isset( $this->challan_layout['footer'] ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- mPDF API property.
			$this->challan_layout['footer'] = array(
				'top'    => (float) $this->h - (float) $this->margin_footer - (float) $adjustmenty,
				'height' => (float) $adjustmenty,
			);
		}

		return parent::PrintPageBackgrounds( $adjustmenty );
	}

	/**
	 * Record a table's final geometry, then write it.
	 *
	 * @param array $table    mPDF table.
	 * @param bool  $split    See mPDF.
	 * @param int   $startrow See mPDF.
	 * @param int   $startcol See mPDF.
	 * @param int   $splitpg  See mPDF.
	 * @param int   $rety     See mPDF.
	 * @return mixed
	 */
	public function _tableWrite( &$table, $split = false, $startrow = 0, $startcol = 0, $splitpg = 0, $rety = 0 ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- mPDF method name.
		if ( ! $split || 0 === (int) $startrow ) {
			$columns = array();
			foreach ( (array) $table['wc'] as $width ) {
				$columns[] = is_array( $width ) ? 0.0 : (float) $width;
			}

			$this->challan_layout['tables'][] = array(
				'level'    => (int) $table['level'],
				'rows'     => (int) $table['nr'],
				'cols'     => (int) $table['nc'],
				'width'    => isset( $table['w'] ) ? (float) $table['w'] : array_sum( $columns ),
				'columns'  => $columns,
				'heights'  => array_map( 'floatval', isset( $table['hr'] ) ? (array) $table['hr'] : array() ),
				'shrink'   => 1 === (int) $table['level'] && $this->shrin_k > 1 ? (float) $this->shrin_k : 1.0,
				'borders'  => $this->challan_cell_borders( $table ),
				'separate' => ! empty( $table['borders_separate'] ),
			);
		}

		return parent::_tableWrite( $table, $split, $startrow, $startcol, $splitpg, $rety );
	}

	/**
	 * The borders mPDF paints for each cell (CSS shorthand per side) —
	 * in collapsed mode these, not the table's own border, are drawn —
	 * and the cell's measured content height (H, mm).
	 *
	 * @param array $table mPDF table.
	 * @return array<int,array<int,array{T:string,R:string,B:string,L:string,H:float}>> By row, then start column.
	 */
	private function challan_cell_borders( array $table ) {
		$borders = array();
		foreach ( (array) $table['cells'] as $row => $cells ) {
			foreach ( (array) $cells as $col => $cell ) {
				if ( ! is_array( $cell ) || ! isset( $cell['border_details'] ) ) {
					continue;
				}
				$sides = array();
				foreach ( array( 'T', 'R', 'B', 'L' ) as $side ) {
					$detail         = isset( $cell['border_details'][ $side ] ) ? $cell['border_details'][ $side ] : array();
					$sides[ $side ] = ! empty( $detail['s'] ) && ! empty( $detail['w'] )
						? round( (float) $detail['w'], 3 ) . 'mm ' . ( ! empty( $detail['style'] ) ? $detail['style'] : 'solid' ) . ' ' . self::challan_color( isset( $detail['c'] ) ? $detail['c'] : '' )
						: '0 none';
				}
				// Content height incl. padding and borders, as mPDF measured it.
				$sides['H'] = isset( $cell['mih'] ) ? (float) $cell['mih'] : 0.0;

				$borders[ (int) $row ][ (int) $col ] = $sides;
			}
		}

		return $borders;
	}

	/**
	 * CSS color for an mPDF color string.
	 *
	 * @param mixed $color mPDF color (mode byte + channels).
	 * @return string
	 */
	public static function challan_color( $color ) {
		if ( ! is_string( $color ) || '' === $color ) {
			return 'currentColor';
		}
		$channel = static function ( $index ) use ( $color ) {
			return isset( $color[ $index ] ) ? ord( $color[ $index ] ) : 0;
		};
		switch ( $color[0] ) {
			case '1': // Grayscale.
				return 'rgb(' . $channel( 1 ) . ',' . $channel( 1 ) . ',' . $channel( 1 ) . ')';
			case '3': // RGB.
				return 'rgb(' . $channel( 1 ) . ',' . $channel( 2 ) . ',' . $channel( 3 ) . ')';
			case '5': // RGBA.
				return 'rgba(' . $channel( 1 ) . ',' . $channel( 2 ) . ',' . $channel( 3 ) . ',' . round( $channel( 4 ) / 100, 2 ) . ')';
			case '4': // CMYK (percent).
			case '6':
				$k = 1 - $channel( 4 ) / 100;
				return 'rgb(' . (int) round( 255 * ( 1 - $channel( 1 ) / 100 ) * $k ) . ',' . (int) round( 255 * ( 1 - $channel( 2 ) / 100 ) * $k ) . ',' . (int) round( 255 * ( 1 - $channel( 3 ) / 100 ) * $k ) . ')';
		}

		return 'currentColor';
	}
}
