<?php
/**
 * Executase ao desinstalar o plugin desde WordPress.
 * Borra a configuración gardada nos metadatos de usuario.
 *
 * @package wp-admin-menu-editor
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$meta_key = '_wpame_menus_ocultos';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $meta_key ) );
