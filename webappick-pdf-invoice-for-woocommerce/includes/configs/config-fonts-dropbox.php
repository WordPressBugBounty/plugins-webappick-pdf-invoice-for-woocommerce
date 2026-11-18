<?php
/**
 * Update shim for Challan 3.x (v1). Not used by this version.
 *
 * When WordPress updates Challan 3.x to this version, the old 3.x code is
 * still loaded for the rest of that request. Its `upgrader_process_complete`
 * hooks include this path (v1's font and mPDF download settings) after the
 * new files are in place. Without the file, PHP 8 stops the update request
 * with a fatal error (implode() on a failed include), so the Plugins screen
 * reports the update as failed.
 *
 * Every key the v1 3.3–3.7 downloaders read, in a shape that makes them do
 * nothing: empty lists, and "challan-v2-no-download/" where an empty path
 * would be a fatal error on PHP 8 (that path doesn't exist and names a
 * folder, so an old download fails with a warning and writes no file).
 * Loading this file also stops the remaining v1 update hooks (see
 * includes/classes/v1-update-guard.php). Fonts and the PDF library are
 * handled by this version itself.
 *
 * @package Challan
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/classes/v1-update-guard.php';

return array(
	'base-url'           => array(),
	'default'            => array(),
	'data-files'         => array(),
	'mpdf-lib'           => 'challan-v2-no-download/',
	'admin-ui-fonts'     => 'challan-v2-no-download/',
	'admin-ui-font-list' => array( '' ),
);
