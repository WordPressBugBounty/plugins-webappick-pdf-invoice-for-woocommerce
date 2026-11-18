<?php
/**
 * Uninstall handler.
 *
 * Intentionally leaves all options, order meta and generated documents
 * in place (matching v1): invoices are business records, and settings
 * survive an accidental delete/reinstall cycle. See CLAUDE.md — the
 * database layer is frozen.
 *
 * @package Challan
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}
