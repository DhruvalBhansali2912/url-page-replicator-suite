<?php
/**
 * Plugin Name: URL Page Replicator
 * Plugin URI:  https://github.com/google-antigravity/url-page-replicator
 * Description: Replicates any external webpage by parsing and resolving HTML, CSS, and JS. Contains URL Page Replicator, Figma Compiler, and Smart Section Compiler.
 * Version:     1.0.0
 * Author:      Antigravity
 * License:     GPL-2.0+
 * Text Domain: url-page-replicator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UPR_PATH', plugin_dir_path( __FILE__ ) );
define( 'UPR_URL', plugin_dir_url( __FILE__ ) );

// Include replicator engines and admin pages
require_once UPR_PATH . 'includes/replicator-engine.php';
require_once UPR_PATH . 'includes/admin-page.php';

// Activation DB table creation (Server-side features)
if ( ! function_exists( 'upr_activation_create_tables' ) ) {
	register_activation_hook( __FILE__, 'upr_activation_create_tables' );
	function upr_activation_create_tables() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'upr_server_tokens';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			token varchar(64) NOT NULL,
			client_url varchar(255) NOT NULL,
			credits_total int(11) DEFAULT 0 NOT NULL,
			credits_used int(11) DEFAULT 0 NOT NULL,
			status varchar(20) DEFAULT 'active' NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}


// Hook to serve replicated template layouts
if ( ! function_exists( 'upr_serve_replicated_templates' ) ) {
	add_filter( 'template_include', 'upr_serve_replicated_templates' );
	function upr_serve_replicated_templates( $template ) {
		if ( is_singular( 'page' ) ) {
			$post_id = get_the_ID();
			
			// 1. Single Replicated HTML layout (Original replication engine)
			$replicated_html = get_post_meta( $post_id, '_upr_replicated_html', true );
			if ( ! empty( $replicated_html ) ) {
				$custom_template = UPR_PATH . 'templates/single-replicated_page.php';
				if ( file_exists( $custom_template ) ) {
					return $custom_template;
				}
			}
			
			// 2. Client-Server relative compiled index.html packages
			$is_replicated = get_post_meta( $post_id, '_upr_is_replicated', true );
			if ( $is_replicated ) {
				$custom_template = UPR_PATH . 'templates/page-renderer.php';
				if ( file_exists( $custom_template ) ) {
					return $custom_template;
				}
			}
		}
		return $template;
	}
}

// Enqueue styles and scripts for administration dashboard pages
if ( ! function_exists( 'upr_enqueue_admin_assets' ) ) {
	add_action( 'admin_enqueue_scripts', 'upr_enqueue_admin_assets' );
	function upr_enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'url-page-replicator' ) ) {
			return;
		}
		wp_enqueue_style( 'upr-admin-css', UPR_URL . 'assets/admin.css', array(), time() );
		
		wp_enqueue_script(
			'upr-admin-js',
			UPR_URL . 'assets/admin.js',
			array( 'jquery' ),
			time(),
			true
		);

		wp_localize_script(
			'upr-admin-js',
			'upr_client_ajax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'upr_client_nonce' ),
				'gemini_api_key' => get_option( 'upr_client_gemini_api_key', '' )
			)
		);
	}
}

// Local Asset Routing Proxy for URL Page Replicator
// Local Asset Routing Proxy for URL Page Replicator
if ( ! function_exists( 'upr_handle_asset_router' ) ) {
	add_action( 'init', 'upr_handle_asset_router' );
	function upr_handle_asset_router() {
		if ( is_admin() ) {
			return;
		}

		$request_uri = $_SERVER['REQUEST_URI'];
		if ( false !== ( $pos = strpos( $request_uri, '?' ) ) ) {
			$request_uri = substr( $request_uri, 0, $pos );
		}

		$site_path = parse_url( site_url(), PHP_URL_PATH );
		if ( $site_path ) {
			$relative_uri = substr( $request_uri, strlen( $site_path ) );
		} else {
			$relative_uri = $request_uri;
		}
		$relative_uri = trim( $relative_uri, '/' );
		$parts = explode( '/', $relative_uri );

		if ( count( $parts ) < 2 ) {
			return;
		}

		$post = null;
		$matched_index = -1;
		for ( $i = count( $parts ) - 1; $i >= 1; $i-- ) {
			$test_path = implode( '/', array_slice( $parts, 0, $i ) );
			$test_post = get_page_by_path( $test_path, OBJECT, 'page' );
			if ( $test_post ) {
				$is_replicated = get_post_meta( $test_post->ID, '_upr_is_replicated', true );
				$source_url = get_post_meta( $test_post->ID, '_upr_source_url', true );
				if ( $is_replicated || $source_url ) {
					$post = $test_post;
					$matched_index = $i;
					break;
				}
			}
		}

		if ( ! $post || $matched_index === -1 ) {
			return;
		}

		// For new client-server replicated pages, serve directly from the local_path folder!
		$is_replicated = get_post_meta( $post->ID, '_upr_is_replicated', true );
		if ( $is_replicated ) {
			$local_path = get_post_meta( $post->ID, '_upr_local_path', true );
			if ( $local_path ) {
				$asset_rel_path = implode( '/', array_slice( $parts, $matched_index ) );
				// Clean up path to prevent traversal
				$asset_rel_path = str_replace( array( '../', '..\\' ), '', $asset_rel_path );
				$filepath = wp_normalize_path( $local_path . '/' . $asset_rel_path );

				if ( file_exists( $filepath ) && ! is_dir( $filepath ) ) {
					$ext = strtolower( pathinfo( $filepath, PATHINFO_EXTENSION ) );
					$mime_types = array(
						'css'   => 'text/css',
						'js'    => 'application/javascript',
						'png'   => 'image/png',
						'jpg'   => 'image/jpeg',
						'jpeg'  => 'image/jpeg',
						'gif'   => 'image/gif',
						'svg'   => 'image/svg+xml',
						'woff'  => 'font/woff',
						'woff2' => 'font/woff2',
						'ttf'   => 'font/ttf',
						'otf'   => 'font/otf',
						'glb'   => 'model/gltf-binary',
						'gltf'  => 'model/gltf+json',
						'basis' => 'image/basis',
						'wasm'  => 'application/wasm',
						'json'  => 'application/json',
						'ico'   => 'image/x-icon',
					);
					$content_type = isset( $mime_types[ $ext ] ) ? $mime_types[ $ext ] : 'application/octet-stream';
					
					if ( ob_get_length() ) {
						ob_end_clean();
					}
					header( 'Content-Type: ' . $content_type );
					header( 'Content-Length: ' . filesize( $filepath ) );
					header( 'Access-Control-Allow-Origin: *' );
					readfile( $filepath );
					exit;
				}
			}
			return;
		}

		// Fallback to original redirect/localize asset router for older replication style
		$asset_rel_path = implode( '/', array_slice( $parts, $matched_index ) );
		$source_url = get_post_meta( $post->ID, '_upr_redirected_url', true );
		if ( ! $source_url ) {
			$source_url = get_post_meta( $post->ID, '_upr_source_url', true );
		}

		// Define helper functions if they do not exist
		if ( ! function_exists( 'upr_resolve_url' ) ) {
			function upr_resolve_url( $rel, $base ) {
				if ( parse_url( $rel, PHP_URL_SCHEME ) != '' ) {
					return $rel;
				}
				if ( $rel[0] == '#' || $rel[0] == '?' ) {
					return $base . $rel;
				}
				extract( parse_url( $base ) );
				$path = preg_replace( '#/[^/]*$#', '', $path );
				if ( $rel[0] == '/' ) {
					$path = '';
				}
				$abs = "$host$path/$rel";
				$re = array( '#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#' );
				for ( $n = 1; $n > 0; $abs = preg_replace( $re, '/', $abs, -1, $n ) ) {}
				return $scheme . '://' . $abs;
			}
		}

		$asset_abs_url = upr_resolve_url( $asset_rel_path, $source_url );
		
		// Compute the localized filename
		$original_filename = sanitize_file_name( urldecode( basename( parse_url( $asset_abs_url, PHP_URL_PATH ) ) ) );

		if ( ! empty( $original_filename ) && strpos( $original_filename, '.' ) !== false ) {
			$filename = $original_filename;
			$ext = strtolower( pathinfo( $original_filename, PATHINFO_EXTENSION ) );
		} else {
			$md5 = md5( $asset_abs_url );
			$ext = strtolower( pathinfo( $parts[ count( $parts ) - 1 ], PATHINFO_EXTENSION ) );
			if ( empty( $ext ) ) {
				$ext = 'jpg';
			}
			$filename = $md5 . '.' . $ext;
		}

		$upload_dir = wp_upload_dir();
		$filepath = $upload_dir['basedir'] . '/url-page-replicator/' . $post->ID . '/' . $filename;

		if ( ! file_exists( $filepath ) ) {
			$response = wp_remote_get( $asset_abs_url, array(
				'timeout'   => 5,
				'sslverify' => false,
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
			) );

			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$body = wp_remote_retrieve_body( $response );
				if ( ! empty( $body ) ) {
					wp_mkdir_p( dirname( $filepath ) );
					file_put_contents( $filepath, $body );
				}
			}
		}

		if ( file_exists( $filepath ) ) {
			$mimes = array(
				'css'   => 'text/css',
				'js'    => 'application/javascript',
				'json'  => 'application/json',
				'png'   => 'image/png',
				'jpg'   => 'image/jpeg',
				'jpeg'  => 'image/jpeg',
				'gif'   => 'image/gif',
				'svg'   => 'image/svg+xml',
				'webp'  => 'image/webp',
				'woff2' => 'font/woff2',
				'woff'  => 'font/woff',
				'ttf'   => 'font/ttf',
				'eot'   => 'application/vnd.ms-fontobject',
				'otf'   => 'font/otf'
			);
			$mime = isset( $mimes[ $ext ] ) ? $mimes[ $ext ] : 'application/octet-stream';

			if ( ob_get_length() ) {
				ob_end_clean();
			}

			header( 'Content-Type: ' . $mime );
			header( 'Content-Length: ' . filesize( $filepath ) );
			header( 'Cache-Control: public, max-age=31536000' );
			readfile( $filepath );
			exit;
		}
	}
}
