<?php
/**
 * Local Page Renderer Template for URL Page Replicator Client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = get_the_ID();
$local_path = get_post_meta( $post_id, '_upr_local_path', true );
$html_file = $local_path . '/index.html';

if ( empty( $local_path ) || ! file_exists( $html_file ) ) {
	wp_die( 'Replicated page content not found. Please re-run replication on the dashboard.', 'Content Not Found', array( 'response' => 404 ) );
}

$html = file_get_contents( $html_file );

// Send cache-busting headers to prevent aggressive browser caching of layout updates
header( 'Cache-Control: no-cache, no-store, must-revalidate' );
header( 'Pragma: no-cache' );
header( 'Expires: 0' );

// Resolve relative package paths (./filename) to local uploads directory URLs
$upload_dir = wp_upload_dir();
$slug = get_post_field( 'post_name', $post_id );
$local_url_path = $upload_dir['baseurl'] . '/url-page-replicator/pages/' . $slug . '/';

$html = str_replace( './', $local_url_path, $html );

// Output HTML and terminate
echo $html;
exit;
