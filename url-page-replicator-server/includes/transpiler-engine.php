<?php
/**
 * AI Multi-Framework Code Transpiler Engine
 * Transforms raw DOM and CSS into component-based React, Angular, and Semantic HTML/CSS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Page Transpiler: converts full replicated page into modern framework project
 */
function upr_server_transpile_page( $target_path, $compilation_id, $format, $title = 'Replicated Page', $original_url = '' ) {
	$html_file = $target_path . '/index.html';
	if ( ! file_exists( $html_file ) ) {
		return new WP_Error( 'upr_missing_html', 'Target index.html not found for transpilation.', array( 'status' => 404 ) );
	}

	$gemini_key = get_option( 'upr_server_gemini_api_key', '' );
	if ( empty( $gemini_key ) ) {
		// Fallback to client key option if set
		$gemini_key = get_option( 'upr_client_gemini_api_key', '' );
	}

	if ( empty( $gemini_key ) ) {
		return new WP_Error( 'upr_missing_gemini_key', 'Gemini API key is not configured on the server. Please add it in Replicator Server settings.', array( 'status' => 500 ) );
	}

	$raw_html = file_get_contents( $html_file );

	// 1. Sanitize and prepare DOM for AI processing
	$sanitized = upr_transpiler_sanitize_dom( $raw_html );

	// 2. Transpile using Gemini AI
	$project_files = upr_transpiler_call_gemini( $sanitized['html'], $sanitized['styles'], $format, $title, $gemini_key );
	if ( is_wp_error( $project_files ) ) {
		return $project_files;
	}

	// 3. Write generated project files into output directory
	$src_dir = $target_path . '/src';
	if ( ! is_dir( $src_dir ) ) {
		wp_mkdir_p( $src_dir );
	}

	foreach ( $project_files as $file ) {
		$file_path = wp_normalize_path( $target_path . '/' . ltrim( $file['path'], '/' ) );
		$dir = dirname( $file_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		file_put_contents( $file_path, $file['content'] );
	}

	// Save transpilation metadata
	$meta = array(
		'title'        => $title,
		'original_url' => $original_url,
		'format'       => $format,
		'generated_at' => current_time( 'mysql' ),
		'engine'       => 'Gemini Multi-Framework Transpiler'
	);
	file_put_contents( $target_path . '/framework-meta.json', json_encode( $meta, JSON_PRETTY_PRINT ) );

	return true;
}

/**
 * Single Component Transpiler: converts an isolated HTML/CSS slice into a clean component
 */
function upr_server_transpile_component( $html, $css, $format, $title = 'Component' ) {
	$gemini_key = get_option( 'upr_server_gemini_api_key', '' );
	if ( empty( $gemini_key ) ) {
		$gemini_key = get_option( 'upr_client_gemini_api_key', '' );
	}

	if ( empty( $gemini_key ) ) {
		return new WP_Error( 'upr_missing_gemini_key', 'Gemini API key is not configured on the server.', array( 'status' => 500 ) );
	}

	$prompt = upr_transpiler_build_component_prompt( $html, $css, $format, $title );
	$response = upr_transpiler_query_gemini( $prompt, $gemini_key );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	// Clean Markdown code fences if returned
	$clean_code = preg_replace( '/^```[a-zA-Z]*\n/m', '', $response );
	$clean_code = preg_replace( '/\n```$/m', '', $clean_code );
	$clean_code = trim( $clean_code );

	return array(
		'status'    => 'success',
		'format'    => $format,
		'component' => $clean_code,
		'title'     => $title
	);
}

/**
 * Pre-sanitizes DOM tree to minimize token size
 */
function upr_transpiler_sanitize_dom( $html ) {
	if ( empty( $html ) ) {
		return array( 'html' => '', 'styles' => '' );
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
	libxml_clear_errors();

	// Extract inline styles and style tags
	$styles = '';
	$style_tags = $dom->getElementsByTagName( 'style' );
	for ( $i = $style_tags->length - 1; $i >= 0; $i-- ) {
		$st = $style_tags->item( $i );
		$styles .= "\n" . $st->nodeValue;
		$st->parentNode->removeChild( $st );
	}

	// Remove script tags, noscript, and iframe trackers
	$remove_tags = array( 'script', 'noscript', 'iframe' );
	foreach ( $remove_tags as $tag_name ) {
		$nodes = $dom->getElementsByTagName( $tag_name );
		for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
			$node = $nodes->item( $i );
			$node->parentNode->removeChild( $node );
		}
	}

	// Simplify SVGs to placeholder tokens to save thousands of tokens
	$svgs = $dom->getElementsByTagName( 'svg' );
	for ( $i = 0; $i < $svgs->length; $i++ ) {
		$svg = $svgs->item( $i );
		while ( $svg->hasChildNodes() ) {
			$svg->removeChild( $svg->firstChild );
		}
		$svg->setAttribute( 'data-icon-placeholder', 'icon_' . ( $i + 1 ) );
	}

	// Strip base64 data URIs
	$xpath = new DOMXPath( $dom );
	$data_nodes = $xpath->query( '//*[@src[starts-with(., "data:")]] | //*[@href[starts-with(., "data:")]]' );
	foreach ( $data_nodes as $node ) {
		if ( $node->hasAttribute( 'src' ) && strpos( $node->getAttribute( 'src' ), 'data:' ) === 0 ) {
			$node->setAttribute( 'src', './assets/placeholder.png' );
		}
		if ( $node->hasAttribute( 'href' ) && strpos( $node->getAttribute( 'href' ), 'data:' ) === 0 ) {
			$node->setAttribute( 'href', '#' );
		}
	}

	$body = $dom->getElementsByTagName( 'body' );
	$clean_html = $body->length > 0 ? $dom->saveHTML( $body->item( 0 ) ) : $dom->saveHTML();

	// Truncate style string to key classes and properties (keep under 20k chars)
	if ( strlen( $styles ) > 20000 ) {
		$styles = substr( $styles, 0, 20000 ) . "\n/* ... styles truncated for brevity ... */";
	}

	return array(
		'html'   => $clean_html,
		'styles' => $styles
	);
}

/**
 * Builds component prompt for Gemini
 */
function upr_transpiler_build_component_prompt( $html, $css, $format, $title ) {
	$framework_rules = '';
	if ( strpos( $format, 'react' ) !== false ) {
		$css_style = ( strpos( $format, 'tailwind' ) !== false ) ? 'Tailwind CSS utility classes' : 'CSS Modules / scoped CSS';
		$framework_rules = "Target: Modern React (TypeScript/TSX).
- Create a functional component named '{$title}'.
- Use {$css_style}.
- Deduplicate repeating elements into props data arrays (e.g. items.map(...)).
- Code must be clean, modular, and use semantic HTML.
- Return ONLY the component TSX code.";
	} elseif ( $format === 'angular' ) {
		$framework_rules = "Target: Modern Angular (v17+ Standalone Component).
- Component with @Component({ standalone: true, selector: 'app-" . sanitize_title( $title ) . "', ... }).
- Use modern control flow (@for, @if).
- Include TypeScript interface for any data models.
- Return ONLY the component TypeScript file code.";
	} else {
		$framework_rules = "Target: Clean Semantic HTML5 & Modern CSS.
- Semantic HTML tags (<section>, <header>, <article>, etc.).
- Clean BEM CSS classes (no inline style mess).
- Reusable, clean CSS rules.
- Return clean HTML and CSS.";
	}

	return "You are a Principal Frontend Architect. Convert the following extracted DOM slice and styles into a production-grade, component-based frontend component.

{$framework_rules}

Extracted HTML Slice:
```html
{$html}
```

Relevant Matched CSS:
```css
{$css}
```

Instructions:
1. Deduplicate repeating elements into reusable sub-components with props.
2. Ensure accessible and responsive layout.
3. Output ONLY the clean code without surrounding conversational text.";
}

/**
 * Calls Gemini to generate the full project structure
 */
function upr_transpiler_call_gemini( $html, $styles, $format, $title, $api_key ) {
	$is_react = strpos( $format, 'react' ) !== false;
	$is_tailwind = strpos( $format, 'tailwind' ) !== false;
	$is_angular = ( $format === 'angular' );

	$format_desc = 'React with Tailwind CSS';
	if ( $is_react && ! $is_tailwind ) $format_desc = 'React with CSS Modules';
	if ( $is_angular ) $format_desc = 'Angular 17+ with Standalone Components';
	if ( $format === 'html-clean' ) $format_desc = 'Clean Semantic HTML5 with BEM CSS';

	$prompt = "You are a Principal Frontend Architect. Convert this captured webpage DOM and styles into a complete, industry-standard, component-based project.

Target Framework: {$format_desc}
Project Title: {$title}

Rules:
1. Decompose the page into clean, reusable components (e.g. Navbar, Hero, FeatureCard, Features, Testimonial, Footer).
2. For repeating items (cards, lists, grids), extract a single reusable component with a data array (JSON/props) and loop over it.
3. Clean all class names and styles. Do not write inline styles.
4. Output MUST be valid JSON with a 'files' array containing the complete project files.

JSON Format:
{
  \"files\": [
    { \"path\": \"package.json\", \"content\": \"...\" },
    { \"path\": \"src/App.tsx\", \"content\": \"...\" },
    { \"path\": \"src/components/Navbar.tsx\", \"content\": \"...\" },
    { \"path\": \"src/components/Hero.tsx\", \"content\": \"...\" },
    { \"path\": \"src/components/Card.tsx\", \"content\": \"...\" },
    { \"path\": \"src/components/Footer.tsx\", \"content\": \"...\" }
  ]
}

Captured HTML:
```html
" . substr( $html, 0, 35000 ) . "
```

Extracted Styles:
```css
" . substr( $styles, 0, 15000 ) . "
```

Return ONLY the valid JSON object.";

	$raw_response = upr_transpiler_query_gemini( $prompt, $api_key, true );
	if ( is_wp_error( $raw_response ) ) {
		return $raw_response;
	}

	$raw_json = preg_replace( '/^```(?:json)?\n/m', '', $raw_response );
	$raw_json = preg_replace( '/\n```$/m', '', $raw_json );
	$raw_json = trim( $raw_json );

	$decoded = json_decode( $raw_json, true );
	if ( empty( $decoded ) || empty( $decoded['files'] ) || ! is_array( $decoded['files'] ) ) {
		return array(
			array(
				'path'    => $is_react ? 'src/App.tsx' : ( $is_angular ? 'src/app/app.component.ts' : 'index.html' ),
				'content' => $raw_response
			)
		);
	}

	return $decoded['files'];
}

/**
 * Sends request to Google Gemini REST API
 */
function upr_transpiler_query_gemini( $prompt, $api_key, $json_mode = false ) {
	$candidate_models = array(
		'gemini-flash-latest',
		'gemini-3.5-flash',
		'gemini-3-flash-preview',
		'gemini-3.5-flash-lite'
	);

	$last_error = 'Gemini API call failed';

	foreach ( $candidate_models as $model ) {
		$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

		$body_data = array(
			'contents' => array(
				array(
					'parts' => array(
						array( 'text' => $prompt )
					)
				)
			),
			'generationConfig' => array(
				'temperature'     => 0.2,
				'maxOutputTokens' => 8192
			)
		);

		if ( $json_mode ) {
			$body_data['generationConfig']['responseMimeType'] = 'application/json';
		}

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 60,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => json_encode( $body_data )
		) );

		if ( is_wp_error( $response ) ) {
			$last_error = $response->get_error_message();
			continue;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status === 200 && isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return $data['candidates'][0]['content']['parts'][0]['text'];
		}

		$last_error = $data['error']['message'] ?? ( 'Model ' . $model . ' failed with status ' . $status );
	}

	return new WP_Error( 'upr_gemini_error', $last_error, array( 'status' => 500 ) );
}
