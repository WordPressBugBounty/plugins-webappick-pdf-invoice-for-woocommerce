<?php
/**
 * Records mPDF's resolved CSS for the HTML view.
 *
 * @package Challan
 */

namespace Challan\Documents;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * A drop-in for mPDF's CSS manager that remembers the properties it
 * resolved for each element in parse order. mPDF supports a narrower selector set than
 * browsers (no descendant matching for inline elements, no sibling
 * combinators, …), so the HTML view applies these resolved values instead
 * of letting the browser cascade the template CSS its own way.
 *
 * Installed by LayoutProbe only; PDFs use mPDF's own manager.
 */
class CssRecorder extends \Mpdf\CssManager {

	/**
	 * Elements whose resolution is recorded.
	 */
	const TAGS = array( 'A', 'B', 'BIG', 'CITE', 'CODE', 'DIV', 'EM', 'FONT', 'I', 'IMG', 'KBD', 'LABEL', 'MARK', 'P', 'S', 'SAMP', 'SMALL', 'SPAN', 'STRIKE', 'STRONG', 'SUB', 'SUP', 'TABLE', 'TD', 'TH', 'TR', 'TT', 'U', 'VAR', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' );

	/**
	 * Recorded elements in parse order: tag, class, props (scalar values only), context.
	 *
	 * @var array<int,array{tag:string,class:string,props:array<string,string>,context:string}>
	 */
	public $challan_records = array();

	/**
	 * Resolve, then remember the result.
	 *
	 * @param string $inherit Context (BLOCK, INLINE, TABLE, TOPTABLE, '').
	 * @param string $tag     Tag name.
	 * @param array  $attr    Attributes.
	 * @return array
	 */
	public function mergeCss( $inherit, $tag, $attr ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mPDF method name.
		$properties = parent::mergeCss( $inherit, $tag, $attr );

		$tag = strtoupper( (string) $tag );
		if ( in_array( $tag, self::TAGS, true ) ) {
			$scalars = array();
			foreach ( (array) $properties as $name => $value ) {
				if ( is_scalar( $value ) ) {
					$scalars[ (string) $name ] = (string) $value;
				}
			}
			$this->challan_records[] = array(
				'tag'     => $tag,
				'class'   => isset( $attr['CLASS'] ) ? strtolower( trim( preg_replace( '/\s+/', ' ', (string) $attr['CLASS'] ) ) ) : '',
				'props'   => $scalars,
				// Blocks inside table cells are resolved as inline ('').
				'context' => (string) $inherit,
			);
		}

		return $properties;
	}
}
