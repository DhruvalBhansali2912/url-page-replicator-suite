<?php
/**
 * AI Multi-Framework Code Transpiler Engine
 * Transforms raw DOM and CSS into component-based React, Angular, and Semantic HTML/CSS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves Gemini API key with priority:
 * 1. Hardcoded PHP constant UPR_GEMINI_API_KEY
 * 2. Environment variable GEMINI_API_KEY
 * 3. WordPress options (upr_server_gemini_api_key)
 */
function upr_get_gemini_api_key() {
	if ( defined( 'UPR_GEMINI_API_KEY' ) && ! empty( UPR_GEMINI_API_KEY ) ) {
		return UPR_GEMINI_API_KEY;
	}
	$env = getenv( 'GEMINI_API_KEY' );
	if ( ! empty( $env ) ) {
		return $env;
	}
	$server_opt = get_option( 'upr_server_gemini_api_key', '' );
	if ( ! empty( $server_opt ) ) {
		return $server_opt;
	}
	return get_option( 'upr_client_gemini_api_key', '' );
}

/**
 * Main Page Transpiler: converts full replicated page into modern framework project
 */
function upr_server_transpile_page( $target_path, $compilation_id, $format, $title = 'Replicated Page', $original_url = '' ) {
	$html_file = $target_path . '/index.html';
	if ( ! file_exists( $html_file ) ) {
		return new WP_Error( 'upr_missing_html', 'Target index.html not found for transpilation.', array( 'status' => 404 ) );
	}

	$gemini_key = upr_get_gemini_api_key();
	if ( empty( $gemini_key ) ) {
		return new WP_Error( 'upr_missing_gemini_key', 'Gemini API key is not configured. Please define UPR_GEMINI_API_KEY in the plugin or save it in Replicator Server settings.', array( 'status' => 500 ) );
	}

	$raw_html = file_get_contents( $html_file );

	// 1. Sanitize and prepare DOM for AI processing
	$sanitized = upr_transpiler_sanitize_dom( $raw_html );

	// 2. Transpile components using Gemini AI
	$project_files = upr_transpiler_call_gemini( $sanitized['html'], $sanitized['styles'], $format, $title, $gemini_key );
	if ( is_wp_error( $project_files ) ) {
		$project_files = array(); // fall back to scaffolded project
	}

	// 3. Scaffold complete runnable project structure (package.json, vite, tailwind, tsconfig, public/)
	upr_transpiler_scaffold_project( $target_path, $format, $title );

	// 4. Write generated project files into output directory
	$src_dir = $target_path . '/src';
	if ( ! is_dir( $src_dir ) ) {
		wp_mkdir_p( $src_dir );
	}

	if ( is_array( $project_files ) ) {
		foreach ( $project_files as $file ) {
			if ( empty( $file['path'] ) || empty( $file['content'] ) ) continue;
			$file_path = wp_normalize_path( $target_path . '/' . ltrim( $file['path'], '/' ) );
			$dir = dirname( $file_path );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			file_put_contents( $file_path, $file['content'] );
		}
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
 * Scaffolds runnable framework templates with dependencies and moves media to public/
 */
function upr_transpiler_scaffold_project( $target_path, $format, $title ) {
	$public_dir = $target_path . '/public';
	$src_dir = $target_path . '/src';
	$comp_dir = $src_dir . '/components';
	$raw_dir = $target_path . '/raw_scraped';

	if ( ! is_dir( $public_dir ) ) wp_mkdir_p( $public_dir );
	if ( ! is_dir( $src_dir ) ) wp_mkdir_p( $src_dir );
	if ( ! is_dir( $comp_dir ) ) wp_mkdir_p( $comp_dir );
	if ( ! is_dir( $raw_dir ) ) wp_mkdir_p( $raw_dir );

	// Move media assets into public/
	$media_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'woff2', 'woff', 'ttf' );
	$files = scandir( $target_path );
	foreach ( $files as $item ) {
		if ( $item === '.' || $item === '..' || is_dir( $target_path . '/' . $item ) ) continue;
		$ext = strtolower( pathinfo( $item, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, $media_exts ) ) {
			@rename( $target_path . '/' . $item, $public_dir . '/' . $item );
		} elseif ( in_array( $ext, array( 'js', 'css', 'html', 'json' ) ) && $item !== 'metadata.json' ) {
			@rename( $target_path . '/' . $item, $raw_dir . '/' . $item );
		}
	}

	$slug = sanitize_title( $title ?: 'replicated-project' );

	if ( strpos( $format, 'react' ) !== false ) {
		// package.json
		$pkg = array(
			'name' => $slug,
			'private' => true,
			'version' => '1.0.0',
			'type' => 'module',
			'scripts' => array(
				'dev' => 'vite',
				'build' => 'vite build',
				'preview' => 'vite preview'
			),
			'dependencies' => array(
				'react' => '^18.3.1',
				'react-dom' => '^18.3.1',
				'lucide-react' => '^0.441.0'
			),
			'devDependencies' => array(
				'@types/react' => '^18.3.5',
				'@types/react-dom' => '^18.3.0',
				'@vitejs/plugin-react' => '^4.3.1',
				'typescript' => '^5.5.3',
				'vite' => '^5.4.2'
			)
		);

		if ( strpos( $format, 'tailwind' ) !== false ) {
			$pkg['devDependencies']['tailwindcss'] = '^3.4.11';
			$pkg['devDependencies']['postcss'] = '^8.4.47';
			$pkg['devDependencies']['autoprefixer'] = '^10.4.20';

			// tailwind.config.js
			file_put_contents( $target_path . '/tailwind.config.js', "/** @type {import('tailwindcss').Config} */\nexport default {\n  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],\n  theme: { extend: {} },\n  plugins: [],\n}\n" );
			// postcss.config.js
			file_put_contents( $target_path . '/postcss.config.js', "export default {\n  plugins: {\n    tailwindcss: {},\n    autoprefixer: {},\n  },\n}\n" );
			// src/index.css
			file_put_contents( $src_dir . '/index.css', "@tailwind base;\n@tailwind components;\n@tailwind utilities;\n\nbody {\n  margin: 0;\n  font-family: system-ui, -apple-system, sans-serif;\n}\n" );
		} else {
			file_put_contents( $src_dir . '/index.css', "body {\n  margin: 0;\n  font-family: system-ui, -apple-system, sans-serif;\n}\n" );
		}

		file_put_contents( $target_path . '/package.json', json_encode( $pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		// vite.config.ts
		file_put_contents( $target_path . '/vite.config.ts', "import { defineConfig } from 'vite';\nimport react from '@vitejs/plugin-react';\n\nexport default defineConfig({\n  plugins: [react()],\n});\n" );

		// tsconfig.json
		file_put_contents( $target_path . '/tsconfig.json', "{\n  \"compilerOptions\": {\n    \"target\": \"ES2020\",\n    \"useDefineForClassFields\": true,\n    \"lib\": [\"ES2020\", \"DOM\", \"DOM.Iterable\"],\n    \"module\": \"ESNext\",\n    \"skipLibCheck\": true,\n    \"moduleResolution\": \"bundler\",\n    \"resolveJsonModule\": true,\n    \"isolatedModules\": true,\n    \"noEmit\": true,\n    \"jsx\": \"react-jsx\",\n    \"strict\": false,\n    \"noImplicitAny\": false,\n    \"noUnusedLocals\": false,\n    \"noUnusedParameters\": false\n  },\n  \"include\": [\"src\"]\n}\n" );

		// tsconfig.node.json
		file_put_contents( $target_path . '/tsconfig.node.json', "{\n  \"compilerOptions\": {\n    \"composite\": true,\n    \"skipLibCheck\": true,\n    \"module\": \"ESNext\",\n    \"moduleResolution\": \"bundler\",\n    \"allowSyntheticDefaultImports\": true\n  },\n  \"include\": [\"vite.config.ts\"]\n}\n" );

		// index.html
		file_put_contents( $target_path . '/index.html', "<!doctype html>\n<html lang=\"en\">\n  <head>\n    <meta charset=\"UTF-8\" />\n    <link rel=\"icon\" type=\"image/x-icon\" href=\"/favicon.ico\" />\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\" />\n    <title>" . esc_html( $title ) . "</title>\n  </head>\n  <body>\n    <div id=\"root\"></div>\n    <script type=\"module\" src=\"/src/main.tsx\"></script>\n  </body>\n</html>\n" );

		// src/main.tsx
		file_put_contents( $src_dir . '/main.tsx', "import React from 'react';\nimport ReactDOM from 'react-dom/client';\nimport App from './App';\nimport './index.css';\n\nReactDOM.createRoot(document.getElementById('root')!).render(\n  <React.StrictMode>\n    <App />\n  </React.StrictMode>,\n);\n" );

		// fallback src/App.tsx
		file_put_contents( $src_dir . '/App.tsx', "import React from 'react';\n\nexport function App() {\n  return (\n    <div className=\"min-h-screen bg-gray-50 flex flex-col items-center justify-center p-6 text-center\">\n      <h1 className=\"text-4xl font-bold text-gray-900 mb-2\">" . esc_html( $title ) . "</h1>\n      <p className=\"text-gray-600\">Replicated Modern Framework Project</p>\n    </div>\n  );\n}\n\nexport default App;\n" );
	}
}

/**
 * Single Component Transpiler: converts an isolated HTML/CSS slice into a clean component
 */
function upr_server_transpile_component( $html, $css, $format, $title = 'Component' ) {
	$gemini_key = upr_get_gemini_api_key();
	if ( empty( $gemini_key ) ) {
		return new WP_Error( 'upr_missing_gemini_key', 'Gemini API key is not configured. Please define UPR_GEMINI_API_KEY in the plugin or save it in Replicator Server settings.', array( 'status' => 500 ) );
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

	$ext = $is_react ? 'tsx' : ( $is_angular ? 'ts' : 'html' );
	$prompt = "You are a Principal Frontend Architect. Convert this captured webpage DOM and styles into clean, modular, production-grade {$format_desc} components.

Rules:
1. Do NOT output configuration files (NO package.json, NO vite.config.ts, NO tsconfig.json, NO index.html). They are already generated.
2. Output ONLY the UI components under 'src/':
   - src/App.{$ext} (default export App, assembling components)
   - src/components/Navbar.{$ext}
   - src/components/Hero.{$ext}
   - src/components/Card.{$ext}
   - src/components/Footer.{$ext}
3. Decompose repeating items (cards, nav links, footer links) into data arrays with props.
4. Output each file inside a markdown code block with '// FILE: path' on the first line comment:

```{$ext}
// FILE: src/components/Navbar.{$ext}
[code here]
```

```{$ext}
// FILE: src/components/Hero.{$ext}
[code here]
```

```{$ext}
// FILE: src/App.{$ext}
[code here]
```

Captured HTML:
```html
" . substr( $html, 0, 30000 ) . "
```

Extracted Styles:
```css
" . substr( $styles, 0, 15000 ) . "
```

Output ONLY the codeblocks with // FILE: path comments.";

	$raw_response = upr_transpiler_query_gemini( $prompt, $api_key );
	if ( is_wp_error( $raw_response ) ) {
		return $raw_response;
	}

	$files = array();

	// 1. Try parsing // FILE: path code blocks
	preg_match_all( '/```(?:tsx|typescript|ts|jsx|javascript|js|html|css)?\s*\n\/\/\s*FILE:\s*([^\n\r]+)\s*\n([\s\S]*?)```/i', $raw_response, $matches, PREG_SET_ORDER );
	if ( ! empty( $matches ) ) {
		foreach ( $matches as $m ) {
			$path = trim( $m[1] );
			$content = trim( $m[2] );
			if ( ! empty( $path ) && ! empty( $content ) ) {
				$files[] = array( 'path' => $path, 'content' => $content );
			}
		}
	}

	// 2. Fallback to JSON if returned as JSON
	if ( empty( $files ) ) {
		$raw_json = preg_replace( '/^```(?:json)?\n/m', '', $raw_response );
		$raw_json = preg_replace( '/\n```$/m', '', $raw_json );
		$raw_json = trim( $raw_json );
		$decoded = json_decode( $raw_json, true );
		if ( ! empty( $decoded['files'] ) && is_array( $decoded['files'] ) ) {
			$files = $decoded['files'];
		}
	}

	return $files;
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
				'maxOutputTokens' => 32768
			)
		);

		if ( $json_mode ) {
			$body_data['generationConfig']['responseMimeType'] = 'application/json';
		}

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 120,
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
