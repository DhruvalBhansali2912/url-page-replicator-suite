<?php
/**
 * Core compiler engine logic for URL Page Replicator Server.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Main compiler function for standard URL replication
if ( ! function_exists( 'upr_server_execute_compile_page' ) ) {
function upr_server_execute_compile_page( $url, $preserve_folder = false ) {
	$compilation_id = 'upr_' . uniqid() . '_' . time();
	$upload_dir = wp_upload_dir();
	
	$target_path = wp_normalize_path( $upload_dir['basedir'] . '/url-page-replicator-server/' . $compilation_id );
	$target_url = $upload_dir['baseurl'] . '/url-page-replicator-server/' . $compilation_id;
	
	if ( ! uprs_mkdir_recursive( $target_path ) ) {
		return new WP_Error( 'upr_fs_error', 'Failed to create compilation directory.', array( 'status' => 500 ) );
	}

	// Fetch primary page to follow redirects
	$response = wp_remote_get( $url, array(
		'timeout'    => 15,
		'sslverify'  => false,
		'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
	) );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'upr_fetch_error', 'Failed to reach the target URL: ' . $response->get_error_message(), array( 'status' => 400 ) );
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$final_url = $url;
	if ( isset( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ) {
		$response_obj = $response['http_response']->get_response_object();
		if ( isset( $response_obj->url ) ) {
			$final_url = $response_obj->url;
		}
	}

	$html = wp_remote_retrieve_body( $response );
	
	// Execute Puppeteer Headless Browser
	$renderer_script = UPR_SERVER_PATH . 'renderer/render.js';
	if ( file_exists( $renderer_script ) ) {
		$cb_url = $final_url;
		$cb = 'upr_cb=' . time();
		if ( strpos( $cb_url, '?' ) !== false ) {
			$cb_url .= '&' . $cb;
		} else {
			$cb_url .= '?' . $cb;
		}
		
		$cmd = 'node ' . escapeshellarg( $renderer_script ) . ' --url ' . escapeshellarg( $cb_url ) . ' --dir ' . escapeshellarg( $target_path ) . ' 2>&1';
		$output = shell_exec( $cmd );
		if ( ! empty( $output ) && strpos( $output, 'Error:' ) === false && strlen( $output ) > 500 ) {
			if ( strpos( $output, 'error404' ) === false && strpos( $output, 'Page not found' ) === false ) {
				$html = $output;
			}
		}
	}

	// Scan and rewrite public paths inside JS files
	$js_files = glob( $target_path . '/*.js' );
	if ( ! empty( $js_files ) ) {
		$upload_url_path = './';
		foreach ( $js_files as $js_file ) {
			$js_content = file_get_contents( $js_file );
			$changed = false;
			
			if ( strpos( $js_content, '"assets/' ) !== false ) {
				$js_content = str_replace( '"assets/', '"' . $upload_url_path, $js_content );
				$changed = true;
			}
			if ( strpos( $js_content, "'assets/" ) !== false ) {
				$js_content = str_replace( "'assets/", "'" . $upload_url_path, $js_content );
				$changed = true;
			}
			if ( strpos( $js_content, '"/assets/' ) !== false ) {
				$js_content = str_replace( '"/assets/', '"' . $upload_url_path, $js_content );
				$changed = true;
			}
			if ( strpos( $js_content, "'/assets/" ) !== false ) {
				$js_content = str_replace( "'/assets/", "'" . $upload_url_path, $js_content );
				$changed = true;
			}
			if ( strpos( $js_content, '`/assets/' ) !== false ) {
				$js_content = str_replace( '`/assets/', '`' . $upload_url_path, $js_content );
				$changed = true;
			}

			// Replace Unicode escaped _nuxt paths: \u002F_nuxt\u002F -> .\u002F or custom
			$escaped_target = '';
			if ( $upload_url_path === './' ) {
				$escaped_target = '.\\u002F';
			} else {
				$escaped_target = str_replace( '/', '\\u002F', $upload_url_path );
			}

			if ( strpos( $js_content, '\\u002F_nuxt\\u002F' ) !== false ) {
				$js_content = str_replace( '\\u002F_nuxt\\u002F', $escaped_target, $js_content );
				$changed = true;
			}

			// Replace standard _nuxt/ paths
			if ( strpos( $js_content, '"/_nuxt/"' ) !== false ) {
				$js_content = str_replace( '"/_nuxt/"', '"' . $upload_url_path . '"', $js_content );
				$changed = true;
			}
			if ( strpos( $js_content, "'/_nuxt/'" ) !== false ) {
				$js_content = str_replace( "'/_nuxt/'", "'" . $upload_url_path . "'", $js_content );
				$changed = true;
			}
			if ( strpos( $js_content, '"/_nuxt/' ) !== false ) {
				$js_content = str_replace( '"/_nuxt/', '"' . $upload_url_path, $js_content );
				$changed = true;
			}
			if ( strpos( $js_content, "'/_nuxt/" ) !== false ) {
				$js_content = str_replace( "'/_nuxt/", "'" . $upload_url_path, $js_content );
				$changed = true;
			}
			if ( strpos( $js_content, '`/_nuxt/' ) !== false ) {
				$js_content = str_replace( '`/_nuxt/', '`' . $upload_url_path, $js_content );
				$changed = true;
			}

			// Override window.location.pathname (and minified variants like t.location.pathname) so React Router / TanStack Router match the homepage route successfully
			$js_content = preg_replace(
				'/(?:\b\w+\s*\.\s*)?location\s*\.\s*pathname\b/',
				'(globalThis.uprGetPathname?globalThis.uprGetPathname():\'/\')',
				$js_content,
				-1,
				$count
			);
			if ( $count > 0 ) {
				$changed = true;
			}

			$js_content = preg_replace(
				'/\b(\w+)\s*=\s*function\s*\(\s*(\w+)\s*\)\s*\{\s*return\s*[\'"]\/[\'"]\s*\+\s*\2\s*\}/',
				'$1=function($2){return ($2.startsWith("/")||$2.startsWith("http"))?$2:("' . $upload_url_path . '"+$2)}',
				$js_content,
				-1,
				$count_cg
			);
			if ( $count_cg > 0 ) {
				$changed = true;
			}
			
			$js_content_sandboxed = uprs_sandbox_js_content( $js_content );
			if ( $js_content_sandboxed !== $js_content ) {
				$js_content = $js_content_sandboxed;
				$changed = true;
			}
			
			if ( $changed ) {
				file_put_contents( $js_file, $js_content );
			}
		}
	}

	$processed_data = uprs_process_webpage( $html, $final_url, $target_path, $target_url );
	$final_html = $processed_data['html'];

	// Save processed HTML and metadata manifest inside compile folder
	file_put_contents( $target_path . '/index.html', $final_html );

	$metadata = array(
		'title'        => ! empty( $processed_data['title'] ) ? $processed_data['title'] : 'Replicated Page',
		'original_url' => $url,
		'slug'         => sanitize_title( ! empty( $processed_data['title'] ) ? $processed_data['title'] : 'replicated-page' ),
		'compiled_at'  => current_time( 'mysql' )
	);
	file_put_contents( $target_path . '/metadata.json', json_encode( $metadata, JSON_PRETTY_PRINT ) );

	// Compress the folder into a ZIP package
	$exports_dir = wp_normalize_path( $upload_dir['basedir'] . '/url-page-replicator-server/exports' );
	uprs_mkdir_recursive( $exports_dir );

	$zip_filename = $compilation_id . '.zip';
	$zip_filepath = $exports_dir . '/' . $zip_filename;
	$zip_url = $upload_dir['baseurl'] . '/url-page-replicator-server/exports/' . $zip_filename;

	if ( ! uprs_zip_folder( $target_path, $zip_filepath ) ) {
		return new WP_Error( 'upr_zip_error', 'Failed to generate ZIP archive package.', array( 'status' => 500 ) );
	}

	// Clean up temporary compilation directory if not preserving for transpiler
	if ( ! $preserve_folder ) {
		uprs_rrmdir( $target_path );
	}

	return array(
		'status'         => 'success',
		'compilation_id' => $compilation_id,
		'target_path'    => $target_path,
		'title'          => $metadata['title'],
		'slug'           => $metadata['slug'],
		'download_url'   => $zip_url
	);
}
}

// Compile Figma multiple screens responsive package using official Figma REST API
if ( ! function_exists( 'upr_server_compile_figma' ) ) {
function upr_server_compile_figma( $desktop_url, $tablet_url, $mobile_url, $client_figma_token = '' ) {
	@set_time_limit( 1800 ); // Allow up to 30 minutes for compiling and API rate-limiting delays
	$figma_token = ! empty( $client_figma_token ) ? $client_figma_token : get_option( 'upr_server_figma_token', '' );
	if ( empty( $figma_token ) ) {
		return new WP_Error( 'upr_missing_figma_token', 'Figma API Token not configured on server or client.', array( 'status' => 500 ) );
	}

	$compilation_id = 'upr_figma_' . uniqid() . '_' . time();
	$upload_dir = wp_upload_dir();
	$target_path = wp_normalize_path( $upload_dir['basedir'] . '/url-page-replicator-server/' . $compilation_id );
	uprs_mkdir_recursive( $target_path );

	$css_rules = array();
	$assets_queue = array();

	// Compile breakpoints
	$breakpoints = array(
		'desktop' => array( 'url' => $desktop_url, 'class' => 'upr-desktop' ),
		'tablet'  => array( 'url' => $tablet_url, 'class' => 'upr-tablet' ),
		'mobile'  => array( 'url' => $mobile_url, 'class' => 'upr-mobile' )
	);

	$html_viewports = '';
	$file_key_global = '';

	// Gather all URLs and parse node IDs and file key
	$node_ids = array();
	$file_key_global = '';
	$active_breakpoints = array();
	
	foreach ( $breakpoints as $bp_key => $bp ) {
		if ( empty( $bp['url'] ) ) {
			continue;
		}

		$parsed = uprs_parse_figma_url( $bp['url'] );
		if ( ! $parsed ) {
			continue;
		}

		$file_key_global = $parsed['file_key'];
		$node_ids[ $bp_key ] = $parsed['node_id'];
		$active_breakpoints[ $bp_key ] = $bp;
	}

	if ( empty( $file_key_global ) || empty( $node_ids ) ) {
		return new WP_Error( 'upr_compile_empty', 'No valid Figma URLs provided.', array( 'status' => 400 ) );
	}

	// Fetch all nodes in a single batch API call
	$batch_nodes = uprs_fetch_figma_nodes_batch( $file_key_global, array_values( $node_ids ), $figma_token );
	if ( is_wp_error( $batch_nodes ) ) {
		return $batch_nodes;
	}

	// Fetch Figma file image fills mapping exactly once per file key
	$image_refs = uprs_fetch_figma_image_refs( $file_key_global, $figma_token );
	if ( is_wp_error( $image_refs ) ) {
		$image_refs = array();
	}

	// Compile breakpoints
	foreach ( $active_breakpoints as $bp_key => $bp ) {
		$node_id = $node_ids[ $bp_key ];
		$api_id = str_replace( '-', ':', $node_id );
		
		if ( ! isset( $batch_nodes[ $api_id ]['document'] ) ) {
			continue;
		}
		
		$node_data = $batch_nodes[ $api_id ]['document'];

		// Recursively compile Figma node to HTML & CSS rules
		$compiled_html = uprs_compile_figma_node( $node_data, $file_key_global, $figma_token, $image_refs, $css_rules, $assets_queue, null, null );
		
		$html_viewports .= '<div class="upr-viewport ' . esc_attr( $bp['class'] ) . '">' . $compiled_html . '</div>' . "\n";
	}

	if ( empty( $html_viewports ) ) {
		return new WP_Error( 'upr_compile_empty', 'No valid Figma nodes were compiled.', array( 'status' => 400 ) );
	}

	// Download queued asset vectors & images with server-side caching
	if ( ! empty( $assets_queue ) && ! empty( $file_key_global ) ) {
		$cache_dir = wp_normalize_path( $upload_dir['basedir'] . '/url-page-replicator-server/cache/' . $file_key_global );
		uprs_mkdir_recursive( $cache_dir );

		$vector_ids_to_fetch = array();
		foreach ( $assets_queue as $id => $asset ) {
			if ( $asset['type'] === 'vector' ) {
				$cached_filepath = $cache_dir . '/' . $asset['filename'];
				$dest_filepath = $target_path . '/' . $asset['filename'];
				
				if ( file_exists( $cached_filepath ) && filesize( $cached_filepath ) > 0 ) {
					copy( $cached_filepath, $dest_filepath );
				} else {
					if ( isset( $asset['node'] ) && ( isset( $asset['node']['fillGeometry'] ) || isset( $asset['node']['strokeGeometry'] ) ) ) {
						$svg_content = uprs_generate_vector_svg( $asset['node'] );
						file_put_contents( $dest_filepath, $svg_content );
						file_put_contents( $cached_filepath, $svg_content );
					} else {
						$vector_ids_to_fetch[] = $id;
					}
				}
			}
		}

		// Batch fetch vector URLs from Figma only for missing items
		$vector_urls = array();
		if ( ! empty( $vector_ids_to_fetch ) ) {
			$vector_urls = uprs_fetch_figma_image_urls( $file_key_global, $vector_ids_to_fetch, $figma_token, 'svg' );
			if ( is_wp_error( $vector_urls ) ) {
				return $vector_urls; // Propagate 429 rate limits or API errors
			}
		}

		foreach ( $assets_queue as $id => $asset ) {
			if ( $asset['type'] === 'image_ref' ) {
				$filepath = $target_path . '/' . $asset['filename'];
				$download_url = $asset['url'];
				if ( ! empty( $download_url ) && ! file_exists( $filepath ) ) {
					$response = wp_remote_get( $download_url, array( 'timeout' => 20, 'sslverify' => false ) );
					if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
						file_put_contents( $filepath, wp_remote_retrieve_body( $response ) );
					}
				}
			} elseif ( $asset['type'] === 'vector' ) {
				$dest_filepath = $target_path . '/' . $asset['filename'];
				if ( file_exists( $dest_filepath ) ) {
					continue; // Already copied from local cache
				}

				$api_id = str_replace( '-', ':', $id );
				if ( isset( $vector_urls[ $api_id ] ) ) {
					$download_url = $vector_urls[ $api_id ];
					if ( ! empty( $download_url ) ) {
						$response = wp_remote_get( $download_url, array( 'timeout' => 20, 'sslverify' => false ) );
						if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
							$body_content = wp_remote_retrieve_body( $response );
							file_put_contents( $dest_filepath, $body_content );
							// Cache it globally for next compilations
							file_put_contents( $cache_dir . '/' . $asset['filename'], $body_content );
						}
					}
				}
			}
		}
	}

	// Format compiled CSS
	$css_content = "/* Compiled Figma CSS styles */\n";
	
	// Add global classes for forms, buttons, links, and carousels
	$css_content .= "
.upr-input-field {
	width: 100%;
	height: 100%;
	border: none;
	background: transparent;
	outline: none;
	font-family: inherit;
	font-size: inherit;
	color: inherit;
	padding: 10px 15px;
	box-sizing: border-box;
	resize: none;
}
.upr-clickable-button {
	cursor: pointer;
	transition: all 0.2s ease-in-out;
}
.upr-clickable-button:hover {
	filter: brightness(0.9) contrast(1.1);
}
.upr-clickable-link {
	cursor: pointer;
	transition: all 0.2s ease-in-out;
}
.upr-clickable-link:hover {
	opacity: 0.8;
}
.upr-carousel {
	overflow-y: hidden !important;
	-ms-overflow-style: none !important; /* IE/Edge */
	scrollbar-width: none !important; /* Firefox */
}
.upr-carousel::-webkit-scrollbar {
	display: none !important; /* Chrome/Safari */
}
";

	foreach ( $css_rules as $selector => $rule ) {
		$css_content .= $selector . " { " . $rule . " }\n";
	}

	// Write CSS file
	file_put_contents( $target_path . '/style.css', $css_content );

	// Build responsive HTML template index.html
	$final_html = '<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Compiled Figma Prototype</title>
	<link rel="stylesheet" href="./style.css?v=' . time() . '">
	<style>
		body { margin: 0; padding: 0; }
		.upr-viewport { width: 100%; min-height: 100vh; overflow: hidden; }
		@media (min-width: 1025px) {
			.upr-desktop { display: flex; }
			.upr-tablet, .upr-mobile { display: none !important; }
		}
		@media (min-width: 768px) and (max-width: 1024px) {
			.upr-tablet { display: flex; }
			.upr-desktop, .upr-mobile { display: none !important; }
		}
		@media (max-width: 767px) {
			.upr-mobile { display: flex; }
			.upr-desktop, .upr-tablet { display: none !important; }
		}
	</style>
</head>
<body>
' . $html_viewports . '
<script>
document.addEventListener("DOMContentLoaded", function() {
	// Toggle Accordion / FAQ Items
	const accordions = document.querySelectorAll(".upr-accordion-item");
	accordions.forEach(item => {
		item.addEventListener("click", function(e) {
			// Prevent clicks inside form inputs/textareas from toggling accordion
			if (e.target.tagName === "INPUT" || e.target.tagName === "TEXTAREA" || e.target.closest(".upr-input-field")) {
				return;
			}
			item.classList.toggle("upr-expanded");
		});
		// Add pointer style to header elements inside
		item.style.cursor = "pointer";
	});

	// Testimonials Carousel dots pagination
	const dotsContainer = document.querySelector(\'[class*="341_563"]\');
	if (dotsContainer) {
		const dots = dotsContainer.querySelectorAll("img, div");
		const carousels = document.querySelectorAll(".upr-carousel");
		// Find the testimonials carousel (the one containing testimonials text)
		let testimonialsCarousel = null;
		carousels.forEach(c => {
			if (c.innerHTML.indexOf("Director") !== -1 || c.innerHTML.indexOf("Corp") !== -1) {
				testimonialsCarousel = c;
			}
		});

		if (testimonialsCarousel && dots.length > 0) {
			// Find the actual sliding horizontal flex wrapper
			const sliderWrapper = testimonialsCarousel.querySelector(\'.upr-carousel, [class*="341_564"], div\');
			const cards = sliderWrapper ? sliderWrapper.children : [];
			
			dots.forEach((dot, index) => {
				dot.style.cursor = "pointer";
				// Style the active dot by default
				if (index === 0) {
					dot.style.opacity = "1";
				} else {
					dot.style.opacity = "0.3";
				}
				
				dot.addEventListener("click", function() {
					if (cards && cards[index]) {
						cards[index].scrollIntoView({ behavior: "smooth", block: "nearest", inline: "center" });
						// Highlight active dot
						dots.forEach((d, idx) => {
							d.style.opacity = (idx === index) ? "1" : "0.3";
						});
					}
				});
			});
		}
	}
});
</script>
</body>
</html>';

	file_put_contents( $target_path . '/index.html', $final_html );

	$metadata = array(
		'title'        => 'Responsive Figma Prototype',
		'slug'         => 'responsive-figma-prototype',
		'compiled_at'  => current_time( 'mysql' )
	);
	file_put_contents( $target_path . '/metadata.json', json_encode( $metadata, JSON_PRETTY_PRINT ) );

	// Compress compilation folder to ZIP
	$exports_dir = wp_normalize_path( $upload_dir['basedir'] . '/url-page-replicator-server/exports' );
	uprs_mkdir_recursive( $exports_dir );

	$zip_filename = $compilation_id . '.zip';
	$zip_filepath = $exports_dir . '/' . $zip_filename;
	$zip_url = $upload_dir['baseurl'] . '/url-page-replicator-server/exports/' . $zip_filename;

	uprs_zip_folder( $target_path, $zip_filepath );
	uprs_rrmdir( $target_path );

	return array(
		'status'       => 'success',
		'title'        => $metadata['title'],
		'slug'         => $metadata['slug'],
		'download_url' => $zip_url
	);
}
}

// Compress a directory to ZIP
if ( ! function_exists( 'uprs_zip_folder' ) ) {
function uprs_zip_folder( $source_dir, $out_zip_path ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return false;
	}
	$zip = new ZipArchive();
	if ( $zip->open( $out_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
		return false;
	}
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source_dir ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $files as $name => $file ) {
		if ( ! $file->isDir() ) {
			$file_path = $file->getRealPath();
			$relative_path = substr( $file_path, strlen( $source_dir ) + 1 );
			$relative_path = str_replace( '\\', '/', $relative_path );
			$zip->addFile( $file_path, $relative_path );
		}
	}
	$zip->close();
	return true;
}
}

// Parse HTML page assets (scripts, links, images, styles)
if ( ! function_exists( 'uprs_process_webpage' ) ) {
function uprs_process_webpage( $html, $base_url, $target_path, $target_url ) {
	if ( empty( $html ) ) {
		return array( 'html' => '', 'title' => '' );
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
	libxml_clear_errors();

	// Extract Title
	$title = '';
	$title_tags = $dom->getElementsByTagName( 'title' );
	if ( $title_tags->length > 0 ) {
		$title = $title_tags->item( 0 )->nodeValue;
	}

	// 0. Fix no-js class (modern sites hide navigation/animations if no-js is present)
	$html_tags = $dom->getElementsByTagName( 'html' );
	if ( $html_tags->length > 0 ) {
		$html_el = $html_tags->item( 0 );
		$html_class = $html_el->getAttribute( 'class' );
		if ( strpos( $html_class, 'no-js' ) !== false ) {
			$html_el->setAttribute( 'class', str_replace( 'no-js', 'js', $html_class ) );
		}
	}
	$xpath = new DOMXPath( $dom );
	$nav_nodes = $xpath->query( '//*[@id="globalnav" or @id="navigation" or @id="nav" or contains(@class, "nav") or contains(@class, "header")]' );
	if ( $nav_nodes && $nav_nodes->length > 0 ) {
		foreach ( $nav_nodes as $nav_el ) {
			$nav_class = $nav_el->getAttribute( 'class' );
			if ( ! empty( $nav_class ) && strpos( $nav_class, 'no-js' ) !== false ) {
				$nav_el->setAttribute( 'class', preg_replace( '/\bno-js\b/', 'js', $nav_class ) );
			}
		}
	}

	// 1. Process stylesheet link tags & preloads
	$links = $dom->getElementsByTagName( 'link' );
	for ( $i = $links->length - 1; $i >= 0; $i-- ) {
		$link = $links->item( $i );
		$rel  = strtolower( $link->getAttribute( 'rel' ) );
		$href = $link->getAttribute( 'href' );
		if ( ! empty( $href ) ) {
			if ( 'stylesheet' === $rel || strpos( $rel, 'preload' ) !== false || strpos( $rel, 'icon' ) !== false ) {
				$local_url = uprs_download_and_localize_image( $href, $base_url, $target_path, $target_url, 'css' );
				if ( $local_url ) {
					$link->setAttribute( 'href', $local_url );
				}
			}
		}
	}

	// 2. Process scripts
	$scripts = $dom->getElementsByTagName( 'script' );
	for ( $i = $scripts->length - 1; $i >= 0; $i-- ) {
		$script = $scripts->item( $i );
		$src    = $script->getAttribute( 'src' );
		if ( ! empty( $src ) ) {
			// Skip third-party trackers
			$strip_keywords = array(
				'google-analytics', 'analytics.js', 'gtm.js', 'googletagmanager',
				'gtm', 'pixel', 'adsense', 'stats', 'tracker', 
				'facebook', 'hotjar', 'doubleclick', 'amazon-adsystem'
			);
			$is_tracker = false;
			$src_lower = strtolower( $src );
			foreach ( $strip_keywords as $term ) {
				if ( strpos( $src_lower, $term ) !== false ) {
					$is_tracker = true;
					break;
				}
			}
			if ( $is_tracker ) {
				$script->parentNode->removeChild( $script );
				continue;
			}

			$local_url = uprs_download_and_localize_image( $src, $base_url, $target_path, $target_url, 'js' );
			if ( $local_url ) {
				$script->setAttribute( 'src', $local_url );
			}
		}
	}

	// 3. Process images (src, srcset, data-src, data-srcset)
	$images = $dom->getElementsByTagName( 'img' );
	for ( $i = $images->length - 1; $i >= 0; $i-- ) {
		$img = $images->item( $i );
		$src = $img->getAttribute( 'src' );
		if ( ! empty( $src ) ) {
			$local_url = uprs_download_and_localize_image( $src, $base_url, $target_path, $target_url );
			if ( $local_url ) {
				$img->setAttribute( 'src', $local_url );
			}
		}

		$srcset = $img->getAttribute( 'srcset' );
		if ( ! empty( $srcset ) ) {
			$img->setAttribute( 'srcset', uprs_localize_srcset( $srcset, $base_url, $target_path, $target_url ) );
		}

		$data_src = $img->getAttribute( 'data-src' );
		if ( ! empty( $data_src ) ) {
			$local_data_src = uprs_download_and_localize_image( $data_src, $base_url, $target_path, $target_url );
			if ( $local_data_src ) {
				$img->setAttribute( 'data-src', $local_data_src );
				if ( empty( $src ) || strpos( $src, 'data:' ) === 0 ) {
					$img->setAttribute( 'src', $local_data_src );
				}
			}
		}

		$data_srcset = $img->getAttribute( 'data-srcset' );
		if ( ! empty( $data_srcset ) ) {
			$local_data_srcset = uprs_localize_srcset( $data_srcset, $base_url, $target_path, $target_url );
			$img->setAttribute( 'data-srcset', $local_data_srcset );
			if ( empty( $srcset ) ) {
				$img->setAttribute( 'srcset', $local_data_srcset );
			}
		}
	}

	// 4. Process picture sources (<source srcset>)
	$sources = $dom->getElementsByTagName( 'source' );
	for ( $i = $sources->length - 1; $i >= 0; $i-- ) {
		$source = $sources->item( $i );
		$srcset = $source->getAttribute( 'srcset' );

		// If the source is an empty base64 placeholder, remove it so it doesn't mask the real image
		if ( $source->hasAttribute( 'data-empty' ) || ( $srcset && 0 === strpos( trim( $srcset ), 'data:' ) ) ) {
			if ( $source->parentNode ) {
				$source->parentNode->removeChild( $source );
			}
			continue;
		}

		if ( ! empty( $srcset ) ) {
			$source->setAttribute( 'srcset', uprs_localize_srcset( $srcset, $base_url, $target_path, $target_url ) );
		}

		$src = $source->getAttribute( 'src' );
		if ( ! empty( $src ) ) {
			$local_src = uprs_download_and_localize_image( $src, $base_url, $target_path, $target_url );
			if ( $local_src ) {
				$source->setAttribute( 'src', $local_src );
			}
		}

		$data_srcset = $source->getAttribute( 'data-srcset' );
		if ( ! empty( $data_srcset ) ) {
			$local_data_srcset = uprs_localize_srcset( $data_srcset, $base_url, $target_path, $target_url );
			$source->setAttribute( 'data-srcset', $local_data_srcset );
			if ( empty( $srcset ) ) {
				$source->setAttribute( 'srcset', $local_data_srcset );
			}
		}
	}

	// 4. Inject uprGetPathname global parameter inside HTML head
	$head_tags = $dom->getElementsByTagName( 'head' );
	if ( $head_tags->length > 0 ) {
		$head = $head_tags->item( 0 );
		$script_node = $dom->createElement( 'script' );
		$script_node->setAttribute( 'type', 'text/javascript' );
		
		$script_node->nodeValue = '
		globalThis.uprGetPathname = function() {
			if (window.uprClientPathname) {
				return window.uprClientPathname;
			}
			return "/";
		};';
		$head->insertBefore( $script_node, $head->firstChild );
	}

	$final_html = $dom->saveHTML();

	// Rewrite any remaining /assets/ and /_nuxt/ URLs inside inline scripts/styles/attributes to refer to the flat local directory
	$final_html = str_replace(
		array( '"/assets/', "'/assets/", '`/assets/', '"/_nuxt/', "'/_nuxt/", '`/_nuxt/', 'href="/_nuxt/', 'src="/_nuxt/', 'href="/assets/', 'src="/assets/' ),
		array( '"./', "'./", '`./', '"./', "'./", '`./', 'href="./', 'src="./', 'href="./', 'src="./' ),
		$final_html
	);

	return array( 'html' => $final_html, 'title' => $title );
}
}

// Localize srcset attributes for responsive images and pictures
if ( ! function_exists( 'uprs_localize_srcset' ) ) {
function uprs_localize_srcset( $srcset, $base_url, $target_path, $target_url ) {
	if ( empty( $srcset ) || 0 === strpos( trim( $srcset ), 'data:' ) ) {
		return $srcset;
	}
	$sources = explode( ',', $srcset );
	$localized_sources = array();

	foreach ( $sources as $source ) {
		$parts = array_filter( explode( ' ', trim( $source ) ) );
		if ( empty( $parts ) ) {
			continue;
		}
		
		$url = array_shift( $parts );
		$localized_url = uprs_download_and_localize_image( $url, $base_url, $target_path, $target_url );
		
		$descriptor = ! empty( $parts ) ? ' ' . implode( ' ', $parts ) : '';
		$localized_sources[] = $localized_url . $descriptor;
	}

	return implode( ', ', $localized_sources );
}
}

// Download and localize asset helper (images, styles, scripts)
if ( ! function_exists( 'uprs_download_and_localize_image' ) ) {
function uprs_download_and_localize_image( $url, $base_url, $target_path, $target_url, $default_ext = '' ) {
	if ( empty( $url ) || strpos( $url, 'data:' ) === 0 ) {
		return $url;
	}

	$abs_url = $url;
	if ( strpos( $url, 'http://' ) !== 0 && strpos( $url, 'https://' ) !== 0 ) {
		if ( strpos( $url, '//' ) === 0 ) {
			$abs_url = 'https:' . $url;
		} else {
			$abs_url = rtrim( $base_url, '/' ) . '/' . ltrim( $url, '/' );
		}
	}

	$parsed_path = parse_url( $abs_url, PHP_URL_PATH );
	$original_filename = '';
	if ( $parsed_path ) {
		$original_filename = sanitize_file_name( urldecode( basename( $parsed_path ) ) );
	}

	static $uprs_url_cache = array();
	if ( isset( $uprs_url_cache[ $abs_url ] ) ) {
		return $uprs_url_cache[ $abs_url ];
	}

	// Use original filename directly, but prefix unique hash if generic dimension (e.g. 1250x668.jpg) or already taken
	if ( ! empty( $original_filename ) && strpos( $original_filename, '.' ) !== false ) {
		$ext = strtolower( pathinfo( $original_filename, PATHINFO_EXTENSION ) );
		// If filename is a generic dimension like 1250x668sr.jpg, 470x264.jpg, etc., make it unique per URL
		if ( preg_match( '/^\d+x\d+/i', $original_filename ) ) {
			$filename = substr( md5( $abs_url ), 0, 8 ) . '_' . $original_filename;
		} else {
			$filename = $original_filename;
			// If already taken by a different URL in this compilation, append short hash
			if ( in_array( './' . $filename, $uprs_url_cache, true ) ) {
				$name_part = pathinfo( $original_filename, PATHINFO_FILENAME );
				$filename = $name_part . '_' . substr( md5( $abs_url ), 0, 8 ) . '.' . $ext;
			}
		}
	} else {
		$ext = ! empty( $parsed_path ) ? pathinfo( $parsed_path, PATHINFO_EXTENSION ) : '';
		if ( empty( $ext ) ) {
			$ext = $default_ext ? $default_ext : 'png';
		}
		$ext = strtolower( $ext );
		$filename = md5( $abs_url ) . '.' . $ext;
	}

	$filepath  = $target_path . '/' . $filename;
	$local_url = './' . $filename;
	$uprs_url_cache[ $abs_url ] = $local_url;

	if ( ! file_exists( $filepath ) ) {
		$abs_url_cb = $abs_url;
		$cb = 'upr_cb=' . time();
		if ( strpos( $abs_url_cb, '?' ) !== false ) {
			$abs_url_cb .= '&' . $cb;
		} else {
			$abs_url_cb .= '?' . $cb;
		}

		$response = wp_remote_get( $abs_url_cb, array(
			'timeout'    => 15,
			'sslverify'  => false,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
		) );

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = wp_remote_retrieve_body( $response );
			if ( ! empty( $body ) ) {
				file_put_contents( $filepath, $body );
				
				if ( 'css' === $ext ) {
					$body = uprs_process_css_content( $body, $abs_url, $target_path, $target_url );
					file_put_contents( $filepath, $body );
				}
				if ( 'js' === $ext ) {
					uprs_download_js_dependencies( $body, $abs_url, $target_path );
				}
			}
		}
	}

	return $local_url;
}
}

// Download dynamic module imports inside JS files recursively
if ( ! function_exists( 'uprs_download_js_dependencies' ) ) {
function uprs_download_js_dependencies( $js_content, $base_js_url, $target_path ) {
	if ( empty( $js_content ) ) {
		return;
	}

	$patterns = array(
		'/\b(?:import|export)\b.*?\bfrom\s*[\'"](\.\/[^\'"]+|\.\.\/[^\'"]+)[\'"]/s',
		'/\bimport\s*\(\s*[\'"](\.\/[^\'"]+|\.\.\/[^\'"]+)[\'"]\s*\)/s',
		'/\bimport\s*[\'"](\.\/[^\'"]+|\.\.\/[^\'"]+)[\'"]/s'
	);

	$relative_imports = array();
	foreach ( $patterns as $pattern ) {
		if ( preg_match_all( $pattern, $js_content, $matches ) ) {
			if ( ! empty( $matches[1] ) ) {
				$relative_imports = array_merge( $relative_imports, $matches[1] );
			}
		}
	}

	$relative_imports = array_unique( $relative_imports );
	if ( empty( $relative_imports ) ) {
		return;
	}

	foreach ( $relative_imports as $rel_path ) {
		$abs_import_url = uprs_resolve_relative_url( $base_js_url, $rel_path );
		if ( ! $abs_import_url ) {
			continue;
		}

		$parsed_path = parse_url( $abs_import_url, PHP_URL_PATH );
		if ( ! $parsed_path ) {
			continue;
		}
		$filename = basename( $parsed_path );
		if ( empty( $filename ) || false === strpos( $filename, '.' ) ) {
			continue;
		}

		$filepath = $target_path . '/' . $filename;

		if ( ! file_exists( $filepath ) ) {
			$abs_import_url_cb = $abs_import_url;
			$cb = 'upr_cb=' . time();
			if ( strpos( $abs_import_url_cb, '?' ) !== false ) {
				$abs_import_url_cb .= '&' . $cb;
			} else {
				$abs_import_url_cb .= '?' . $cb;
			}

			$response = wp_remote_get( $abs_import_url_cb, array(
				'timeout'    => 15,
				'sslverify'  => false,
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
			) );

			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$body = wp_remote_retrieve_body( $response );
				if ( ! empty( $body ) ) {
					file_put_contents( $filepath, $body );
					uprs_download_js_dependencies( $body, $abs_import_url, $target_path );
				}
			}
		}
	}
}
}

// Process CSS files to localize imports and fonts
if ( ! function_exists( 'uprs_process_css_content' ) ) {
function uprs_process_css_content( $css_content, $base_css_url, $target_path, $target_url ) {
	if ( empty( $css_content ) ) {
		return '';
	}

	if ( preg_match_all( '/url\s*\(\s*[\'"]?([^\'\"\)]+)[\'"]?\s*\)/i', $css_content, $matches ) ) {
		$original_urls = array_unique( $matches[1] );
		foreach ( $original_urls as $orig_url ) {
			if ( strpos( $orig_url, 'data:' ) === 0 ) {
				continue;
			}
			$local_url = uprs_download_and_localize_image( $orig_url, $base_css_url, $target_path, $target_url );
			if ( $local_url ) {
				$css_content = str_replace( $orig_url, $local_url, $css_content );
			}
		}
	}

	return $css_content;
}
}

// Resolve relative import URL helper
if ( ! function_exists( 'uprs_resolve_relative_url' ) ) {
function uprs_resolve_relative_url( $base_url, $relative_url ) {
	$base_parts = parse_url( $base_url );
	if ( ! isset( $base_parts['scheme'] ) || ! isset( $base_parts['host'] ) ) {
		return false;
	}

	$base_path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
	$base_dir  = dirname( $base_path );

	$relative_url = ltrim( $relative_url, '.' );
	$relative_url = ltrim( $relative_url, '/' );

	$dir_up_count = substr_count( $relative_url, '../' );
	for ( $i = 0; $i < $dir_up_count; $i++ ) {
		$base_dir = dirname( $base_dir );
	}
	$clean_relative = str_replace( '../', '', $relative_url );

	$resolved_path = rtrim( $base_dir, '/' ) . '/' . ltrim( $clean_relative, '/' );
	
	$resolved_url = $base_parts['scheme'] . '://' . $base_parts['host'];
	if ( isset( $base_parts['port'] ) ) {
		$resolved_url .= ':' . $base_parts['port'];
	}
	$resolved_url .= $resolved_path;

	return $resolved_url;
}
}

// Safe directory creation
if ( ! function_exists( 'uprs_mkdir_recursive' ) ) {
function uprs_mkdir_recursive( $path ) {
	if ( is_dir( $path ) ) {
		return true;
	}
	return mkdir( $path, 0755, true );
}
}

// Clean up temporary compilation folder
if ( ! function_exists( 'uprs_rrmdir' ) ) {
function uprs_rrmdir( $dir ) {
	if ( is_dir( $dir ) ) {
		$objects = scandir( $dir );
		foreach ( $objects as $object ) {
			if ( $object != "." && $object != ".." ) {
				if ( is_dir( $dir . DIRECTORY_SEPARATOR . $object ) && ! is_link( $dir . "/" . $object ) ) {
					uprs_rrmdir( $dir . DIRECTORY_SEPARATOR . $object );
				} else {
					unlink( $dir . DIRECTORY_SEPARATOR . $object );
				}
			}
		}
		rmdir( $dir );
	}
}
}

// Sandboxes JavaScript content by shadowing window and location
if ( ! function_exists( 'uprs_sandbox_js_content' ) ) {
function uprs_sandbox_js_content( $js_content ) {
	if ( empty( $js_content ) ) {
		return $js_content;
	}
	
	if ( strpos( $js_content, 'uprGetPathname' ) !== false ) {
		return $js_content;
	}

	$is_module = preg_match( '/\b(?:import\s*(?:[\'"{*]|\w+\s+from)|\bexport\s*(?:\{|default|const|let|var|function|class))\b/s', $js_content );

	$proxy_logic = 'let window = new Proxy(globalThis, {
  get(target, prop) {
    if (prop === \'location\') {
      return new Proxy(target.location, {
        get(locTarget, locProp) {
          if (locProp === \'pathname\') {
            return globalThis.uprGetPathname ? globalThis.uprGetPathname() : locTarget.pathname;
          }
          if (locProp === \'then\') return undefined;
          const val = locTarget[locProp];
          if (typeof val === \'function\') {
            if (val.name && /^[A-Z]/.test(val.name)) return val;
            try {
              return val.bind(locTarget);
            } catch (e) {
              return val;
            }
          }
          return val;
        },
        set(locTarget, locProp, locValue) {
          locTarget[locProp] = locValue;
          return true;
        }
      });
    }
    if ([\'window\', \'self\', \'globalThis\', \'top\', \'parent\', \'frames\'].includes(prop)) {
      return window;
    }
    if (prop === \'then\') return undefined;
    const val = target[prop];
    if (typeof val === \'function\') {
      if (val.name && /^[A-Z]/.test(val.name)) return val;
      if (prop === \'eval\') return val;
      try {
        return val.bind(target);
      } catch (e) {
        return val;
      }
    }
    return val;
  },
  set(target, prop, value) {
    target[prop] = value;
    return true;
  }
});
let location = window.location;
let document = new Proxy(globalThis.document, {
  get(target, prop) {
    if (prop === \'defaultView\') {
      return window;
    }
    if (prop === \'then\') return undefined;
    const val = target[prop];
    if (typeof val === \'function\') {
      if (val.name && /^[A-Z]/.test(val.name)) return val;
      try {
        return val.bind(target);
      } catch (e) {
        return val;
      }
    }
    return val;
  },
  set(target, prop, value) {
    target[prop] = value;
    return true;
  }
});
';

	if ( $is_module ) {
		return $proxy_logic . $js_content;
	} else {
		return '(function(){' . $proxy_logic . $js_content . "\n" . '})();';
	}
}
}

// Parse Figma direct file key and node ID from URL
if ( ! function_exists( 'uprs_parse_figma_url' ) ) {
function uprs_parse_figma_url( $url ) {
	$pattern = '/\/(?:design|file|proto)\/([a-zA-Z0-9]+)/';
	if ( ! preg_match( $pattern, $url, $matches ) ) {
		return false;
	}
	$file_key = $matches[1];

	$query = parse_url( $url, PHP_URL_QUERY );
	$node_id = '';
	if ( $query ) {
		parse_str( $query, $query_params );
		$node_id = isset( $query_params['node-id'] ) ? $query_params['node-id'] : '';
	}

	return array(
		'file_key' => $file_key,
		'node_id'  => $node_id
	);
}
}

// Fetch single node structure from Figma REST API
if ( ! function_exists( 'uprs_fetch_figma_node' ) ) {
function uprs_fetch_figma_node( $file_key, $node_id, $figma_token ) {
	$upload_dir = wp_upload_dir();
	$cache_dir = wp_normalize_path( $upload_dir['basedir'] . '/url-page-replicator-server/cache/' . $file_key );
	uprs_mkdir_recursive( $cache_dir );
	
	$node_id_clean = str_replace( ':', '_', $node_id );
	$cache_file = $cache_dir . '/node_' . $node_id_clean . '.json';
	
	if ( file_exists( $cache_file ) ) {
		$cached_content = file_get_contents( $cache_file );
		$cached_json = json_decode( $cached_content, true );
		if ( ! empty( $cached_json ) ) {
			return $cached_json;
		}
	}

	$api_node_id = str_replace( '-', ':', $node_id );
	$api_url = "https://api.figma.com/v1/files/{$file_key}/nodes?ids=" . urlencode( $api_node_id );

	$response = wp_remote_get( $api_url, array(
		'headers' => array(
			'X-Figma-Token' => $figma_token
		),
		'timeout' => 20,
		'sslverify' => false
	) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		$headers = wp_remote_retrieve_headers( $response );
		$body = wp_remote_retrieve_body( $response );
		$debug = array(
			'endpoint'  => 'single_node',
			'api_url'   => $api_url,
			'http_code' => $code,
			'headers'   => is_object( $headers ) ? $headers->getAll() : $headers,
			'body'      => json_decode( $body, true ) ?: $body,
		);
		if ( 429 === $code ) {
			return new WP_Error( 'upr_figma_rate_limit', 'Too many tries, please try again after some time.', $debug );
		}
		return new WP_Error( 'upr_figma_api_error', 'Figma API responded with code: ' . $code, $debug );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body ) || ! isset( $body['nodes'][$api_node_id]['document'] ) ) {
		return new WP_Error( 'upr_figma_parse_error', 'Failed to parse Figma node document structure.' );
	}

	$document = $body['nodes'][$api_node_id]['document'];
	file_put_contents( $cache_file, json_encode( $document, JSON_PRETTY_PRINT ) );

	return $document;
}
}

// Fetch multiple node structures from Figma REST API in a single batch request
if ( ! function_exists( 'uprs_fetch_figma_nodes_batch' ) ) {
function uprs_fetch_figma_nodes_batch( $file_key, $node_ids, $figma_token ) {
	$upload_dir = wp_upload_dir();
	$cache_dir = wp_normalize_path( $upload_dir['basedir'] . '/url-page-replicator-server/cache/' . $file_key );
	uprs_mkdir_recursive( $cache_dir );
	
	$cache_file = $cache_dir . '/nodes_batch_' . md5( implode( ',', $node_ids ) ) . '.json';
	
	if ( file_exists( $cache_file ) ) {
		$cached_content = file_get_contents( $cache_file );
		$cached_json = json_decode( $cached_content, true );
		if ( ! empty( $cached_json ) ) {
			return $cached_json;
		}
	}

	$api_node_ids = array_map( function( $id ) {
		return str_replace( '-', ':', $id );
	}, $node_ids );
	
	$api_url = "https://api.figma.com/v1/files/{$file_key}/nodes?ids=" . implode( ',', array_map( 'urlencode', $api_node_ids ) ) . "&geometry=paths";

	$response = wp_remote_get( $api_url, array(
		'headers' => array(
			'X-Figma-Token' => $figma_token
		),
		'timeout' => 20,
		'sslverify' => false
	) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		$headers = wp_remote_retrieve_headers( $response );
		$body = wp_remote_retrieve_body( $response );
		$debug = array(
			'endpoint'  => 'nodes_batch',
			'api_url'   => $api_url,
			'http_code' => $code,
			'headers'   => is_object( $headers ) ? $headers->getAll() : $headers,
			'body'      => json_decode( $body, true ) ?: $body,
		);
		if ( 429 === $code ) {
			return new WP_Error( 'upr_figma_rate_limit', 'Too many tries, please try again after some time.', $debug );
		}
		return new WP_Error( 'upr_figma_api_error', 'Figma API responded with code: ' . $code, $debug );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body ) || ! isset( $body['nodes'] ) ) {
		return new WP_Error( 'upr_figma_parse_error', 'Failed to parse Figma node batch structure.' );
	}

	$nodes = $body['nodes'];
	file_put_contents( $cache_file, json_encode( $nodes, JSON_PRETTY_PRINT ) );

	return $nodes;
}
}


// Fetch rendered image URLs for vector nodes from Figma REST API
if ( ! function_exists( 'uprs_fetch_figma_image_urls' ) ) {
function uprs_fetch_figma_image_urls( $file_key, $node_ids, $figma_token, $format = 'svg' ) {
	if ( empty( $node_ids ) ) {
		return array();
	}

	// Chunk the node IDs to a maximum of 10 per request to respect Figma's 10 requests per minute limit
	$chunks = array_chunk( $node_ids, 10 );
	$all_images = array();

	foreach ( $chunks as $index => $chunk_node_ids ) {
		// Sleep for 60 seconds before fetching the next chunk if there are multiple requests
		if ( $index > 0 ) {
			sleep( 60 );
		}

		$api_node_ids = array_map( function( $id ) {
			return str_replace( '-', ':', $id );
		}, $chunk_node_ids );
		
		$api_url = "https://api.figma.com/v1/images/{$file_key}?ids=" . implode( ',', array_map( 'urlencode', $api_node_ids ) ) . "&format=" . $format;

		$response = wp_remote_get( $api_url, array(
			'headers' => array(
				'X-Figma-Token' => $figma_token
			),
			'timeout' => 45,
			'sslverify' => false
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			if ( 429 === $code ) {
				$headers = wp_remote_retrieve_headers( $response );
				$body = wp_remote_retrieve_body( $response );
				$debug = array(
					'endpoint'  => 'image_urls',
					'api_url'   => $api_url,
					'http_code' => 429,
					'headers'   => is_object( $headers ) ? $headers->getAll() : $headers,
					'body'      => json_decode( $body, true ) ?: $body,
				);
				return new WP_Error( 'upr_figma_rate_limit', 'Too many tries, please try again after some time.', $debug );
			}
			continue;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['images'] ) && is_array( $body['images'] ) ) {
			$all_images = array_merge( $all_images, $body['images'] );
		}
	}

	return $all_images;
}
}

// Generate valid SVG document locally from Figma vector geometry paths
if ( ! function_exists( 'uprs_generate_vector_svg' ) ) {
function uprs_generate_vector_svg( $node ) {
	$width = isset( $node['size']['x'] ) ? $node['size']['x'] : ( isset( $node['absoluteBoundingBox']['width'] ) ? $node['absoluteBoundingBox']['width'] : 100 );
	$height = isset( $node['size']['y'] ) ? $node['size']['y'] : ( isset( $node['absoluteBoundingBox']['height'] ) ? $node['absoluteBoundingBox']['height'] : 100 );
	
	// Ensure positive non-zero dimensions
	$width = $width > 0 ? $width : 100;
	$height = $height > 0 ? $height : 100;

	$svg = '<svg width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" fill="none" xmlns="http://www.w3.org/2000/svg">' . "\n";

	// Generate fills
	if ( isset( $node['fillGeometry'] ) && is_array( $node['fillGeometry'] ) ) {
		$fill_color = '#000000'; // Default black
		if ( isset( $node['fills'] ) && is_array( $node['fills'] ) ) {
			foreach ( $node['fills'] as $fill ) {
				if ( isset( $fill['type'] ) && $fill['type'] === 'SOLID' && isset( $fill['color'] ) ) {
					$r = round( $fill['color']['r'] * 255 );
					$g = round( $fill['color']['g'] * 255 );
					$b = round( $fill['color']['b'] * 255 );
					$a = isset( $fill['color']['a'] ) ? $fill['color']['a'] : 1;
					$fill_color = sprintf( 'rgba(%d,%d,%d,%f)', $r, $g, $b, $a );
					break;
				}
			}
		}
		
		foreach ( $node['fillGeometry'] as $geo ) {
			if ( isset( $geo['path'] ) && ! empty( $geo['path'] ) ) {
				$svg .= '  <path d="' . esc_attr( $geo['path'] ) . '" fill="' . esc_attr( $fill_color ) . '"';
				if ( isset( $geo['windingRule'] ) && $geo['windingRule'] === 'EVENODD' ) {
					$svg .= ' fill-rule="evenodd" clip-rule="evenodd"';
				}
				$svg .= ' />' . "\n";
			}
		}
	}

	// Generate strokes
	if ( isset( $node['strokeGeometry'] ) && is_array( $node['strokeGeometry'] ) ) {
		$stroke_color = '#000000';
		$stroke_weight = isset( $node['strokeWeight'] ) ? $node['strokeWeight'] : 1;
		if ( isset( $node['strokes'] ) && is_array( $node['strokes'] ) ) {
			foreach ( $node['strokes'] as $stroke ) {
				if ( isset( $stroke['type'] ) && $stroke['type'] === 'SOLID' && isset( $stroke['color'] ) ) {
					$r = round( $stroke['color']['r'] * 255 );
					$g = round( $stroke['color']['g'] * 255 );
					$b = round( $stroke['color']['b'] * 255 );
					$a = isset( $stroke['color']['a'] ) ? $stroke['color']['a'] : 1;
					$stroke_color = sprintf( 'rgba(%d,%d,%d,%f)', $r, $g, $b, $a );
					break;
				}
			}
		}

		foreach ( $node['strokeGeometry'] as $geo ) {
			if ( isset( $geo['path'] ) && ! empty( $geo['path'] ) ) {
				$svg .= '  <path d="' . esc_attr( $geo['path'] ) . '" stroke="' . esc_attr( $stroke_color ) . '" stroke-width="' . esc_attr( $stroke_weight ) . '" />' . "\n";
			}
		}
	}

	$svg .= '</svg>';
	return $svg;
}
}

// Fetch Figma File Image Ref URL map
if ( ! function_exists( 'uprs_fetch_figma_image_refs' ) ) {
function uprs_fetch_figma_image_refs( $file_key, $figma_token ) {
	$api_url = "https://api.figma.com/v1/files/{$file_key}/images";
	$response = wp_remote_get( $api_url, array( 
		'headers' => array( 'X-Figma-Token' => $figma_token ), 
		'timeout' => 20,
		'sslverify' => false
	) );
	if ( is_wp_error( $response ) ) {
		return array();
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		if ( 429 === $code ) {
			$headers = wp_remote_retrieve_headers( $response );
			$body = wp_remote_retrieve_body( $response );
			$debug = array(
				'endpoint'  => 'image_refs',
				'api_url'   => $api_url,
				'http_code' => 429,
				'headers'   => is_object( $headers ) ? $headers->getAll() : $headers,
				'body'      => json_decode( $body, true ) ?: $body,
			);
			return new WP_Error( 'upr_figma_rate_limit', 'Too many tries, please try again after some time.', $debug );
		}
		return array();
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return isset( $body['meta']['images'] ) ? $body['meta']['images'] : array();
}
}

// Helper to check if a node has any visible image fills
if ( ! function_exists( 'uprs_node_has_image_fill' ) ) {
function uprs_node_has_image_fill( $node ) {
	if ( isset( $node['fills'] ) && is_array( $node['fills'] ) ) {
		foreach ( $node['fills'] as $fill ) {
			if ( isset( $fill['visible'] ) && ! $fill['visible'] ) {
				continue;
			}
			if ( isset( $fill['type'] ) && $fill['type'] === 'IMAGE' ) {
				return true;
			}
		}
	}
	return false;
}
}

// Helper to get semantic HTML tag name from node name
if ( ! function_exists( 'uprs_get_semantic_tag' ) ) {
function uprs_get_semantic_tag( $node ) {
	$name = isset( $node['name'] ) ? strtolower( $node['name'] ) : '';
	$type = isset( $node['type'] ) ? $node['type'] : '';

	if ( $type === 'TEXT' ) {
		if ( strpos( $name, 'link' ) !== false || strpos( $name, 'nav' ) !== false ) {
			return 'a';
		}
		return 'div';
	}

	if ( in_array( $type, array( 'FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'RECTANGLE' ) ) ) {
		if ( strpos( $name, 'form' ) !== false && strpos( $name, 'input' ) === false ) {
			return 'form';
		}
		if ( strpos( $name, 'button' ) !== false || strpos( $name, 'btn' ) !== false ) {
			return 'button';
		}
		if ( strpos( $name, 'link' ) !== false ) {
			return 'a';
		}
	}

	return 'div';
}
}

// Helper to check if a container acts as an input field
if ( ! function_exists( 'uprs_is_input_container' ) ) {
function uprs_is_input_container( $node ) {
	$name = isset( $node['name'] ) ? strtolower( $node['name'] ) : '';
	$type = isset( $node['type'] ) ? $node['type'] : '';
	if ( ! in_array( $type, array( 'FRAME', 'GROUP', 'RECTANGLE' ) ) ) {
		return false;
	}
	return ( strpos( $name, 'input' ) !== false || strpos( $name, 'field' ) !== false || strpos( $name, 'text area' ) !== false || strpos( $name, 'textarea' ) !== false );
}
}

// Helper to traverse and find first text node child
if ( ! function_exists( 'uprs_find_first_text_node' ) ) {
function uprs_find_first_text_node( $node ) {
	if ( isset( $node['type'] ) && $node['type'] === 'TEXT' ) {
		return $node;
	}
	if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
		foreach ( $node['children'] as $child ) {
			$found = uprs_find_first_text_node( $child );
			if ( $found ) {
				return $found;
			}
		}
	}
	return null;
}
}

// Recursive Figma JSON node compiler supporting absolute positioning and flex auto-layouts
if ( ! function_exists( 'uprs_compile_figma_node' ) ) {
function uprs_compile_figma_node( $node, $file_key, $figma_token, $image_refs, &$css_rules, &$assets_queue, $parent_layout_mode = null, $parent_bbox = null, $is_parent_carousel = false, $parent_accordion_id = null ) {
	if ( ! isset( $node['type'] ) ) {
		return '';
	}
	
	$node_id_clean = preg_replace( '/[^a-zA-Z0-9_]/', '_', $node['id'] );
	$primary_class = 'upr-figma-' . strtolower( $node['type'] ) . '-' . $node_id_clean;
	$class_name = $primary_class;
	
	$style = array();
	$html = '';
	
	$style[] = 'box-sizing: border-box;';
	
	// Visibility check: skip compile entirely if hidden and not part of an accordion (preventing default placeholder labels)
	$is_visible = ! ( isset( $node['visible'] ) && ! $node['visible'] );
	if ( ! $is_visible && ! $parent_accordion_id ) {
		return '';
	}

	// Accordion parent styling updates
	if ( $parent_accordion_id ) {
		if ( ! $is_visible ) {
			// Override to show this hidden node when accordion parent is expanded
			$css_rules[ ".upr-figma-frame-{$parent_accordion_id}.upr-expanded .{$primary_class}" ] = 'display: block !important;';
		} else {
			// If it's a plus/add icon, hide it when expanded
			$node_name_lower = isset( $node['name'] ) ? strtolower( $node['name'] ) : '';
			if ( strpos( $node_name_lower, 'plus' ) !== false || strpos( $node_name_lower, 'add' ) !== false || strpos( $node_name_lower, 'icon' ) !== false ) {
				$css_rules[ ".upr-figma-frame-{$parent_accordion_id}.upr-expanded .{$primary_class}" ] = 'display: none !important;';
			}
		}
	}
	
	$my_bbox = isset( $node['absoluteBoundingBox'] ) ? $node['absoluteBoundingBox'] : null;
	$is_absolute_toggle = isset( $node['isAbsolute'] ) && $node['isAbsolute'];

	// Calculate absolute/relative positions for absolute layout structures
	if ( $is_parent_carousel ) {
		$style[] = 'scroll-snap-align: start;';
		$style[] = 'flex-shrink: 0;';
	} elseif ( $my_bbox && ( ( $parent_bbox && empty( $parent_layout_mode ) ) || $is_absolute_toggle ) ) {
		$left = $my_bbox['x'] - ( $parent_bbox ? $parent_bbox['x'] : 0 );
		$top  = $my_bbox['y'] - ( $parent_bbox ? $parent_bbox['y'] : 0 );
		$style[] = 'position: absolute;';
		$style[] = "left: {$left}px;";
		$style[] = "top: {$top}px;";
		$style[] = 'width: ' . $my_bbox['width'] . 'px;';
		$style[] = 'height: ' . $my_bbox['height'] . 'px;';
	} elseif ( $my_bbox ) {
		// Inside Flex auto-layout
		$style[] = 'width: ' . $my_bbox['width'] . 'px;';
		$style[] = 'height: ' . $my_bbox['height'] . 'px;';
		$style[] = 'flex-shrink: 0;';
	}

	// Centering root nodes in browser
	if ( ! $parent_bbox && $my_bbox ) {
		$style[] = 'position: relative;';
		$style[] = 'margin: 0 auto;';
		$style[] = 'width: ' . $my_bbox['width'] . 'px;';
	}
	
	// Vector nodes rendering (only if the node does not have an image fill)
	$vector_types = array( 'VECTOR', 'BOOLEAN_OPERATION', 'STAR', 'LINE', 'REGULAR_POLYGON', 'ELLIPSE' );
	if ( in_array( $node['type'], $vector_types ) && ! uprs_node_has_image_fill( $node ) ) {
		// Resolve instanced sub-node IDs to their corresponding master component child node ID
		$parts = explode( ';', $node['id'] );
		$figma_api_id = end( $parts );
		$figma_api_id_clean = preg_replace( '/[^a-zA-Z0-9_]/', '_', $figma_api_id );

		$assets_queue[ $figma_api_id ] = array(
			'type'     => 'vector',
			'filename' => 'vector_' . $figma_api_id_clean . '.svg',
			'node'     => $node
		);
		$css_rules[ '.' . $class_name ] = implode( ' ', $style );
		return '<img class="' . $class_name . '" src="./vector_' . $figma_api_id_clean . '.svg" alt="Vector graphic" />';
	}
	
	$is_container = in_array( $node['type'], array( 'FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'RECTANGLE' ) ) || uprs_node_has_image_fill( $node );
	
	if ( $is_container ) {
		$has_position = false;
		foreach ( $style as $s ) {
			if ( strpos( $s, 'position:' ) !== false ) {
				$has_position = true;
				break;
			}
		}
		if ( ! $has_position ) {
			$style[] = 'position: relative;';
		}

		if ( isset( $node['layoutMode'] ) ) {
			$style[] = 'display: flex;';
			if ( $node['layoutMode'] === 'VERTICAL' ) {
				$style[] = 'flex-direction: column;';
			} else {
				$style[] = 'flex-direction: row;';
			}
			if ( isset( $node['itemSpacing'] ) ) {
				$style[] = 'gap: ' . $node['itemSpacing'] . 'px;';
			}
			
			$pt = isset( $node['paddingTop'] ) ? $node['paddingTop'] : 0;
			$pr = isset( $node['paddingRight'] ) ? $node['paddingRight'] : 0;
			$pb = isset( $node['paddingBottom'] ) ? $node['paddingBottom'] : 0;
			$pl = isset( $node['paddingLeft'] ) ? $node['paddingLeft'] : 0;
			if ( $pt || $pr || $pb || $pl ) {
				$style[] = "padding: {$pt}px {$pr}px {$pb}px {$pl}px;";
			}
			
			if ( isset( $node['primaryAxisAlignItems'] ) ) {
				$align = $node['primaryAxisAlignItems'];
				if ( 'MIN' === $align ) $style[] = 'justify-content: flex-start;';
				elseif ( 'MAX' === $align ) $style[] = 'justify-content: flex-end;';
				elseif ( 'CENTER' === $align ) $style[] = 'justify-content: center;';
				elseif ( 'SPACE_BETWEEN' === $align ) $style[] = 'justify-content: space-between;';
			}
			if ( isset( $node['counterAxisAlignItems'] ) ) {
				$align = $node['counterAxisAlignItems'];
				if ( 'MIN' === $align ) $style[] = 'align-items: flex-start;';
				elseif ( 'MAX' === $align ) $style[] = 'align-items: flex-end;';
				elseif ( 'CENTER' === $align ) $style[] = 'align-items: center;';
			}
		}
		
		if ( isset( $node['fills'] ) && is_array( $node['fills'] ) ) {
			foreach ( $node['fills'] as $fill ) {
				if ( isset( $fill['visible'] ) && ! $fill['visible'] ) {
					continue;
				}
				if ( $fill['type'] === 'SOLID' && isset( $fill['color'] ) ) {
					$r = round( $fill['color']['r'] * 255 );
					$g = round( $fill['color']['g'] * 255 );
					$b = round( $fill['color']['b'] * 255 );
					$a = isset( $fill['opacity'] ) ? $fill['opacity'] : 1;
					$style[] = "background-color: rgba($r, $g, $b, $a);";
				} elseif ( $fill['type'] === 'IMAGE' && isset( $fill['imageRef'] ) ) {
					$ref = $fill['imageRef'];
					if ( isset( $image_refs[ $ref ] ) ) {
						$img_url = $image_refs[ $ref ];
						$assets_queue[ $ref ] = array(
							'type'     => 'image_ref',
							'url'      => $img_url,
							'filename' => 'image_' . $ref . '.png'
						);
						$style[] = "background-image: url('./image_{$ref}.png');";
						$style[] = "background-size: cover;";
						$style[] = "background-position: center;";
					}
				}
			}
		}
		
		if ( isset( $node['strokes'] ) && is_array( $node['strokes'] ) && ! empty( $node['strokes'] ) ) {
			$stroke = $node['strokes'][0];
			if ( isset( $stroke['color'] ) ) {
				$r = round( $stroke['color']['r'] * 255 );
				$g = round( $stroke['color']['g'] * 255 );
				$b = round( $stroke['color']['b'] * 255 );
				$a = isset( $stroke['opacity'] ) ? $stroke['opacity'] : 1;
				$w = isset( $node['strokeWeight'] ) ? $node['strokeWeight'] : 1;
				$style[] = "border: {$w}px solid rgba($r, $g, $b, $a);";
			}
		}
		
		if ( $node['type'] === 'ELLIPSE' ) {
			$style[] = 'border-radius: 50%;';
		} elseif ( isset( $node['cornerRadius'] ) ) {
			$style[] = 'border-radius: ' . $node['cornerRadius'] . 'px;';
		}
		
		$child_html = '';
		$my_layout_mode = isset( $node['layoutMode'] ) ? $node['layoutMode'] : null;
		
		// Setup accordion items class name (detect before compiling children so we can pass down parent accordion ID)
		$is_accordion_item = false;
		$node_name_lower = isset( $node['name'] ) ? strtolower( $node['name'] ) : '';
		if ( strpos( $node_name_lower, 'accordion' ) !== false || strpos( $node_name_lower, 'faq' ) !== false || preg_match( '/^\d+\s+/', $node['name'] ) ) {
			$is_accordion_item = true;
			$class_name .= ' upr-accordion-item';
			// Check if this FAQ item starts expanded (like the first Consultation card)
			if ( strpos( $node_name_lower, '01' ) !== false || strpos( $node_name_lower, 'open' ) !== false ) {
				$class_name .= ' upr-expanded';
			}
		}
		
		// Check if parent node is a Carousel / Slider / Brand logo bar
		$is_carousel = false;
		if ( strpos( $node_name_lower, 'carousel' ) !== false || strpos( $node_name_lower, 'slider' ) !== false || strpos( $node_name_lower, 'testimonials' ) !== false || strpos( $node_name_lower, 'logo' ) !== false || strpos( $node_name_lower, 'brand' ) !== false ) {
			$is_carousel = true;
		} elseif ( $is_parent_carousel && isset( $node['layoutMode'] ) && $node['layoutMode'] === 'HORIZONTAL' ) {
			$is_carousel = true;
		}
		
		if ( $is_carousel ) {
			$style[] = 'overflow-x: auto;';
			$style[] = 'scroll-snap-type: x mandatory;';
			$style[] = 'scroll-behavior: smooth;';
			$style[] = 'display: flex;';
			$style[] = 'flex-wrap: nowrap;';
			$class_name .= ' upr-carousel';
		} elseif ( isset( $node['clipsContent'] ) && $node['clipsContent'] ) {
			$style[] = 'overflow: hidden;';
		}

		// Handle Form Input Container replacement
		if ( uprs_is_input_container( $node ) ) {
			$placeholder = '';
			$text_node = uprs_find_first_text_node( $node );
			if ( $text_node && isset( $text_node['characters'] ) ) {
				$placeholder = $text_node['characters'];
			}
			$input_name = 'upr_field_' . sanitize_title( $placeholder ?: $node['id'] );
			
			if ( strpos( $node_name_lower, 'message' ) !== false || strpos( $node_name_lower, 'area' ) !== false || strpos( $node_name_lower, 'textarea' ) !== false ) {
				$child_html = '<textarea class="upr-input-field" placeholder="' . esc_attr( $placeholder ) . '" name="' . esc_attr( $input_name ) . '"></textarea>';
			} else {
				$type = ( strpos( $node_name_lower, 'email' ) !== false ) ? 'email' : 'text';
				$child_html = '<input type="' . $type . '" class="upr-input-field" placeholder="' . esc_attr( $placeholder ) . '" name="' . esc_attr( $input_name ) . '" />';
			}
		} else {
			if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
				foreach ( $node['children'] as $child ) {
					$child_html .= uprs_compile_figma_node( $child, $file_key, $figma_token, $image_refs, $css_rules, $assets_queue, $my_layout_mode, $my_bbox, $is_carousel, $is_accordion_item ? $node_id_clean : $parent_accordion_id );
				}
			}
		}
		
		// Build semantic HTML tags
		$tag = uprs_get_semantic_tag( $node );
		
		if ( $tag === 'form' ) {
			$html = '<form class="' . $class_name . '" method="POST" action="#">' . $child_html . '</form>';
		} elseif ( $tag === 'button' ) {
			// Prevent nested layout container buttons from breaking parser display structure, keep as div with cursor pointer class
			$style[] = 'cursor: pointer;';
			$class_name .= ' upr-clickable-button';
			$html = '<div class="' . $class_name . '">' . $child_html . '</div>';
		} elseif ( $tag === 'a' ) {
			// Keep as div with cursor pointer for container links
			$style[] = 'cursor: pointer;';
			$class_name .= ' upr-clickable-link';
			$html = '<div class="' . $class_name . '">' . $child_html . '</div>';
		} else {
			$html = '<div class="' . $class_name . '">' . $child_html . '</div>';
		}
	}
	
	if ( $node['type'] === 'TEXT' ) {
		$text_val = isset( $node['characters'] ) ? nl2br( esc_html( $node['characters'] ) ) : '';
		if ( isset( $node['style'] ) ) {
			$text_style = $node['style'];
			if ( isset( $text_style['fontFamily'] ) ) {
				$style[] = "font-family: '" . $text_style['fontFamily'] . "', sans-serif;";
			}
			if ( isset( $text_style['fontSize'] ) ) {
				$style[] = "font-size: " . $text_style['fontSize'] . "px;";
			}
			if ( isset( $text_style['fontWeight'] ) ) {
				$style[] = "font-weight: " . $text_style['fontWeight'] . ";";
			}
			if ( isset( $text_style['lineHeightPx'] ) ) {
				$style[] = "line-height: " . $text_style['lineHeightPx'] . "px;";
			}
			if ( isset( $text_style['textAlignHorizontal'] ) ) {
				$style[] = "text-align: " . strtolower( $text_style['textAlignHorizontal'] ) . ";";
			}
		}
		
		if ( isset( $node['fills'] ) && is_array( $node['fills'] ) ) {
			foreach ( $node['fills'] as $fill ) {
				if ( $fill['type'] === 'SOLID' && isset( $fill['color'] ) ) {
					$r = round( $fill['color']['r'] * 255 );
					$g = round( $fill['color']['g'] * 255 );
					$b = round( $fill['color']['b'] * 255 );
					$a = isset( $fill['opacity'] ) ? $fill['opacity'] : 1;
					$style[] = "color: rgba($r, $g, $b, $a);";
				}
			}
		}
		
		$tag = uprs_get_semantic_tag( $node );
		if ( $tag === 'a' ) {
			$html = '<a class="' . $class_name . ' upr-clickable-link" href="#">' . $text_val . '</a>';
		} else {
			$html = '<div class="' . $class_name . '">' . $text_val . '</div>';
		}
	}
	
	if ( ! $is_visible ) {
		$style[] = 'display: none !important;';
	}
	
	$css_rules[ '.' . $primary_class ] = implode( ' ', $style );
	return $html;
}
}
