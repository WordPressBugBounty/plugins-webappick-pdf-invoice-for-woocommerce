<?php
/**
 * mPDF wrapper used for every PDF Challan generates.
 *
 * @since      3.7.91
 * @package    Challan_Free
 * @subpackage Challan_Free/includes/classes
 * @link       https://webappick.com
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Challan_Free_Mpdf' ) && class_exists( '\Mpdf\Mpdf' ) ) {

	/**
	 * Challan_Free_Mpdf
	 *
	 * Backports the mPDF 8.2 fix for "unserialize(): Extra data starting at offset ..."
	 * so the downloaded mPDF 8.0.x library works on PHP 8.3+.
	 */
	class Challan_Free_Mpdf extends \Mpdf\Mpdf {

		/**
		 * Marker mPDF puts around inline object attributes (images, list markers, textareas).
		 */
		const CHALLAN_OBJECT_IDENTIFIER = "\xbb\xa4\xac";

		/**
		 * Read the serialized attributes of an inline object.
		 *
		 * mPDF 8.0.x passes the closing marker to unserialize(), which PHP 8.3+ reports as a warning.
		 * With debug mode on, mPDF then aborts the PDF with "Error detected. PDF file generation aborted".
		 *
		 * @param string $t Text chunk holding the object marker.
		 * @return mixed
		 */
		public function _getObjAttr( $t ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
			$c  = explode( self::CHALLAN_OBJECT_IDENTIFIER, $t, 2 );
			$c  = explode( ',', $c[1], 2 );
			$sp = array();

			foreach ( $c as $v ) {
				$v           = explode( '=', $v, 2 );
				$sp[ $v[0] ] = isset( $v[1] ) ? trim( $v[1], self::CHALLAN_OBJECT_IDENTIFIER ) : '';
			}

			return unserialize( $sp['objattr'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		}
	}
}
