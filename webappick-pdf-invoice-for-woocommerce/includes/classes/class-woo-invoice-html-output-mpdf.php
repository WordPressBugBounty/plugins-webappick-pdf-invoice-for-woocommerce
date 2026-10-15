<?php
/**
 * mPDF subclass that only records the table column widths mPDF calculates.
 *
 * Used by Woo_Invoice_Html_Output, so the HTML output can use the same column
 * widths as the PDF. Nothing is output; the PDF itself does not use this class.
 *
 * @since      3.7.90
 * @package    Woo_Invoice
 * @subpackage Woo_Invoice/includes/classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( '\Mpdf\Mpdf' ) && ! class_exists( 'Woo_Invoice_Html_Output_Mpdf' ) ) {
	/**
	 * Class Woo_Invoice_Html_Output_Mpdf
	 */
	class Woo_Invoice_Html_Output_Mpdf extends \Mpdf\Mpdf {

		/**
		 * One entry per top-level table, in document order: [ level => [ table number => [ 'wc' => column widths, 'hr' => row heights (mm) ] ] ].
		 *
		 * @var array
		 */
		public $challan_table_widths = array();

		/**
		 * Record the final column widths of a top-level table and its nested tables.
		 *
		 * @param array $table    Table data.
		 * @param bool  $split    Table is split over pages.
		 * @param int   $startrow First row written.
		 * @param int   $startcol First column written.
		 * @param int   $splitpg  Split page.
		 * @param int   $rety     Y position.
		 * @return mixed
		 */
		public function _tableWrite( &$table, $split = false, $startrow = 0, $startcol = 0, $splitpg = 0, $rety = 0 ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Overrides mPDF's method.
			if ( isset( $table['level'] ) && 1 === (int) $table['level'] && ! $startrow && ! $startcol ) {
				$group = array();
				foreach ( (array) $this->table as $level => $tables ) {
					foreach ( (array) $tables as $number => $data ) {
						$group[ $level ][ $number ] = array(
							'wc' => isset( $data['wc'] ) && is_array( $data['wc'] ) ? array_values( array_filter( $data['wc'], 'is_numeric' ) ) : array(),
							'hr' => isset( $data['hr'] ) && is_array( $data['hr'] ) ? array_values( array_filter( $data['hr'], 'is_numeric' ) ) : array(),
						);
					}
				}
				$this->challan_table_widths[] = $group;
			}
			return parent::_tableWrite( $table, $split, $startrow, $startcol, $splitpg, $rety );
		}
	}
}
