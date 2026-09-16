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
/**
 * Resolves all Gemini API keys for multi-account pool rotation.
 * Supports multiple keys separated by newlines, commas, or semicolons.
 */
function upr_get_gemini_api_keys() {
	$raw_keys = array();

	if ( defined( 'UPR_GEMINI_API_KEYS' ) && ! empty( UPR_GEMINI_API_KEYS ) ) {
		$raw_keys[] = UPR_GEMINI_API_KEYS;
	}
	if ( defined( 'UPR_GEMINI_API_KEY' ) && ! empty( UPR_GEMINI_API_KEY ) ) {
		$raw_keys[] = UPR_GEMINI_API_KEY;
	}
	$env = getenv( 'GEMINI_API_KEY' );
	if ( ! empty( $env ) ) {
		$raw_keys[] = $env;
	}
	$server_opt = get_option( 'upr_server_gemini_api_key', '' );
	if ( ! empty( $server_opt ) ) {
		$raw_keys[] = $server_opt;
	}
	$client_opt = get_option( 'upr_client_gemini_api_key', '' );
	if ( ! empty( $client_opt ) ) {
		$raw_keys[] = $client_opt;
	}

	$all_keys = array();
	foreach ( $raw_keys as $item ) {
		if ( is_array( $item ) ) {
			foreach ( $item as $k ) {
				$k = trim( $k );
				if ( ! empty( $k ) && ! in_array( $k, $all_keys, true ) ) {
					$all_keys[] = $k;
				}
			}
		} elseif ( is_string( $item ) ) {
			$parts = preg_split( '/[\r\n,;]+/', $item );
			foreach ( $parts as $p ) {
				$p = trim( $p );
				if ( ! empty( $p ) && ! in_array( $p, $all_keys, true ) ) {
					$all_keys[] = $p;
				}
			}
		}
	}

	return $all_keys;
}

/**
 * Returns primary Gemini API key (for single-key contexts)
 */
function upr_get_gemini_api_key() {
	$keys = upr_get_gemini_api_keys();
	return ! empty( $keys ) ? $keys[0] : '';
}

/**
 * Main Page Transpiler: converts full replicated page into modern framework project
 */
function upr_server_transpile_page( $target_path, $compilation_id, $format, $title = 'Replicated Page', $original_url = '' ) {
	$html_file = $target_path . '/index.html';
	if ( ! file_exists( $html_file ) ) {
		return new WP_Error( 'upr_missing_html', 'Target index.html not found for transpilation.', array( 'status' => 404 ) );
	}

	$gemini_keys = upr_get_gemini_api_keys();
	if ( empty( $gemini_keys ) ) {
		return new WP_Error( 'upr_missing_gemini_key', 'Gemini API key is not configured. Please define UPR_GEMINI_API_KEY in the plugin or save it in Replicator Server settings.', array( 'status' => 500 ) );
	}

	$raw_html = file_get_contents( $html_file );

	// 1. Build local media asset manifest from scraped files
	$asset_manifest = upr_transpiler_build_asset_manifest( $target_path, $raw_html );

	// 2. Sanitize and prepare DOM for AI processing
	$sanitized = upr_transpiler_sanitize_dom( $raw_html );

	// 3. Transpile components using Gemini AI with multi-account key pool
	$project_files = upr_transpiler_call_gemini( $sanitized['html'], $sanitized['styles'], $format, $title, $gemini_keys, $asset_manifest );
	if ( is_wp_error( $project_files ) ) {
		return $project_files;
	}
	if ( empty( $project_files ) || ! is_array( $project_files ) ) {
		return new WP_Error( 'upr_transpile_empty', 'AI transpiler returned no valid component files.', array( 'status' => 500 ) );
	}

	// 4. Scaffold complete runnable project structure (package.json, vite, tailwind, tsconfig, public/)
	upr_transpiler_scaffold_project( $target_path, $format, $title );

	// 5. Write generated project files into output directory
	$src_dir = $target_path . '/src';
	if ( ! is_dir( $src_dir ) ) {
		wp_mkdir_p( $src_dir );
	}

	foreach ( $project_files as $file ) {
		if ( empty( $file['path'] ) || empty( $file['content'] ) ) continue;
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

		// Extract CSS keyframes from scraped stylesheets to preserve original animations
		$keyframes_css = '';
		$css_files = glob( $raw_dir . '/*.css' );
		if ( ! empty( $css_files ) ) {
			foreach ( $css_files as $cf ) {
				$css_content = file_get_contents( $cf );
				if ( preg_match_all( '/@keyframes\s+[a-zA-Z0-9_-]+\s*\{(?:\s*[^{}]*\{[^{}]*\})*\s*\}/is', $css_content, $kf_matches ) ) {
					$keyframes_css .= "\n/* Animations from " . basename( $cf ) . " */\n" . implode( "\n", $kf_matches[0] ) . "\n";
				}
			}
		}

		if ( strpos( $format, 'tailwind' ) !== false ) {
			$pkg['devDependencies']['tailwindcss'] = '^3.4.11';
			$pkg['devDependencies']['postcss'] = '^8.4.47';
			$pkg['devDependencies']['autoprefixer'] = '^10.4.20';

			// tailwind.config.js
			file_put_contents( $target_path . '/tailwind.config.js', "/** @type {import('tailwindcss').Config} */\nexport default {\n  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],\n  theme: { extend: {} },\n  plugins: [],\n}\n" );
			// postcss.config.js
			file_put_contents( $target_path . '/postcss.config.js', "export default {\n  plugins: {\n    tailwindcss: {},\n    autoprefixer: {},\n  },\n}\n" );
			// src/index.css
			file_put_contents( $src_dir . '/index.css', "@tailwind base;\n@tailwind components;\n@tailwind utilities;\n\nbody {\n  margin: 0;\n  font-family: system-ui, -apple-system, sans-serif;\n}\n" . $keyframes_css );
		} else {
			file_put_contents( $src_dir . '/index.css', "body {\n  margin: 0;\n  font-family: system-ui, -apple-system, sans-serif;\n}\n" . $keyframes_css );
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
/**
 * Builds local media asset manifest from scraped images
 */
function upr_transpiler_build_asset_manifest( $target_path, $html ) {
	$media_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp' );
	$manifest_lines = array();
	$files = @scandir( $target_path );
	if ( ! empty( $files ) ) {
		foreach ( $files as $f ) {
			if ( $f === '.' || $f === '..' || is_dir( $target_path . '/' . $f ) ) continue;
			$ext = strtolower( pathinfo( $f, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, $media_exts, true ) ) {
				$context = '';
				if ( preg_match( '/<img[^>]+src=["\'][^"\']*' . preg_quote( $f, '/' ) . '["\'][^>]*alt=["\']([^"\']+)["\']/i', $html, $m ) ) {
					$context = trim( $m[1] );
				} elseif ( preg_match( '/alt=["\']([^"\']+)["\'][^>]*src=["\'][^"\']*' . preg_quote( $f, '/' ) . '["\']/i', $html, $m ) ) {
					$context = trim( $m[1] );
				}
				if ( empty( $context ) ) {
					if ( preg_match( '/(?:srcset|data-src|data-srcset)=["\'][^"\']*' . preg_quote( $f, '/' ) . '["\'][^>]*alt=["\']([^"\']+)["\']/i', $html, $m ) ) {
						$context = trim( $m[1] );
					}
				}
				if ( empty( $context ) ) {
					$clean_name = preg_replace( '/[_-]+/', ' ', pathinfo( $f, PATHINFO_FILENAME ) );
					$context = ucwords( trim( preg_replace( '/\s+[a-z0-9]{8,}\s*/i', ' ', $clean_name ) ) );
				}
				$manifest_lines[] = "- {$context}: `/{$f}`";
			}
		}
	}
	return ! empty( $manifest_lines ) ? implode( "\n", array_slice( $manifest_lines, 0, 60 ) ) : 'No local assets detected.';
}

/**
 * Pre-sanitizes DOM tree to eliminate SVG path coordinates and tracking bloat
 */
function upr_transpiler_sanitize_dom( $html ) {
	if ( empty( $html ) ) {
		return array( 'html' => '', 'styles' => '' );
	}

	// Remove scripts, noscripts, iframes, canvas, video/audio elements
	$clean = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $html );
	$clean = preg_replace( '/<noscript\b[^>]*>(.*?)<\/noscript>/is', '', $clean );
	$clean = preg_replace( '/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $clean );
	$clean = preg_replace( '/<canvas\b[^>]*>(.*?)<\/canvas>/is', '', $clean );

	// Extract style tags before stripping
	$styles = '';
	if ( preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $clean, $style_matches ) ) {
		$styles = implode( "\n", $style_matches[1] );
		$clean = preg_replace( '/<style\b[^>]*>(.*?)<\/style>/is', '', $clean );
	}

	// Strip huge SVG path coordinates and replace with lightweight icon tag
	$clean = preg_replace( '/<svg\b[^>]*>.*?<\/svg>/is', '<svg data-icon="icon" class="w-5 h-5 inline-block"></svg>', $clean );

	// Simplify <picture> elements into direct high-resolution <img> tags with exact local paths
	$clean = preg_replace_callback( '/<picture\b([^>]*)>(.*?)<\/picture>/is', function( $matches ) {
		$picture_attrs = $matches[1];
		$inner = $matches[2];

		$alt = '';
		if ( preg_match( '/alt=["\']([^"\']*)["\']/i', $inner, $m ) ) {
			$alt = $m[1];
		}

		$class = '';
		if ( preg_match( '/class=["\']([^"\']*)["\']/i', $picture_attrs, $m ) ) {
			$class = $m[1];
		}

		$candidates = array();
		if ( preg_match_all( '/<source[^>]+srcset=["\']([^"\']+)["\']/i', $inner, $sources ) ) {
			foreach ( $sources[1] as $src_str ) {
				$urls = explode( ',', $src_str );
				foreach ( $urls as $u ) {
					$parts = preg_split( '/\s+/', trim( $u ) );
					if ( ! empty( $parts[0] ) && strpos( $parts[0], 'data:' ) !== 0 ) {
						$candidates[] = $parts[0];
					}
				}
			}
		}
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $inner, $img_m ) ) {
			if ( strpos( $img_m[1], 'data:' ) !== 0 ) {
				$candidates[] = $img_m[1];
			}
		}

		$best = '';
		foreach ( $candidates as $c ) {
			if ( strpos( $c, '_largetall' ) !== false || strpos( $c, '_large' ) !== false ) {
				$best = $c;
				break;
			}
		}
		if ( empty( $best ) ) {
			foreach ( $candidates as $c ) {
				if ( strpos( $c, '_medium' ) !== false ) {
					$best = $c;
					break;
				}
			}
		}
		if ( empty( $best ) && ! empty( $candidates ) ) {
			$best = end( $candidates );
		}

		if ( empty( $best ) ) {
			return '<img src="/placeholder.png" alt="' . htmlspecialchars( $alt, ENT_QUOTES ) . '" class="' . htmlspecialchars( $class, ENT_QUOTES ) . '" />';
		}

		$filename = basename( parse_url( $best, PHP_URL_PATH ) );
		return '<img src="/' . ltrim( $filename, '/' ) . '" alt="' . htmlspecialchars( $alt, ENT_QUOTES ) . '" class="' . htmlspecialchars( $class, ENT_QUOTES ) . '" />';
	}, $clean );

	// Strip data attributes that bloat DOM (analytics, anim, tracking, etc.)
	$clean = preg_replace( '/\s+data-(?:analytics|anim|viewport|feature|focus|module|unit)[a-z0-9_-]*="[^"]*"/i', '', $clean );
	$clean = preg_replace( '/\s+aria-(?:hidden|label|describedby)="[^"]*"/i', '', $clean );
	$clean = preg_replace( '/\s+tabindex="[^"]*"/i', '', $clean );
	$clean = preg_replace( '/\s+role="[^"]*"/i', '', $clean );

	// Strip base64 data URIs
	$clean = preg_replace( '/src=["\']data:image\/[^;]+;base64,[^"\']+["\']/i', 'src="/placeholder.png"', $clean );

	// Clean up extra whitespace and empty tags
	$clean = preg_replace( '/\n\s*\n/', "\n", $clean );

	// Limit styles to key rules (up to 25k chars)
	if ( strlen( $styles ) > 25000 ) {
		$styles = substr( $styles, 0, 25000 ) . "\n/* ... styles truncated for brevity ... */";
	}

	// Keep up to 400k chars of clean HTML so Gemini sees the entire page (heroes, promos, dual carousels, full footer)
	if ( strlen( $clean ) > 400000 ) {
		$clean = substr( $clean, 0, 400000 );
	}

	return array(
		'html'   => $clean,
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
 * Calls Gemini to generate the full project structure with high-fidelity styling & real assets
 */
function upr_transpiler_call_gemini( $html, $styles, $format, $title, $api_key, $asset_manifest = '' ) {
	$is_react = strpos( $format, 'react' ) !== false;
	$is_tailwind = strpos( $format, 'tailwind' ) !== false;
	$is_angular = ( $format === 'angular' );

	$format_desc = 'React with Tailwind CSS';
	if ( $is_react && ! $is_tailwind ) $format_desc = 'React with CSS Modules';
	if ( $is_angular ) $format_desc = 'Angular 17+ with Standalone Components';
	if ( $format === 'html-clean' ) $format_desc = 'Clean Semantic HTML5 with BEM CSS';

	$ext = $is_react ? 'tsx' : ( $is_angular ? 'ts' : 'html' );
	$prompt = "You are a World-Class Frontend Architect specializing in Pixel-Perfect Design Replication. Convert this captured webpage DOM, styles, and local media assets into clean, production-grade {$format_desc} components.

AVAILABLE LOCAL ASSETS (Stored in public/ - Reference directly with leading slash):
{$asset_manifest}

CRITICAL HIGH-FIDELITY DESIGN & LAYOUT RULES:
1. EXACT LOCAL IMAGE BINDING (DO NOT HALLUCINATE OR REPEAT PRO PHONE):
   - The Captured HTML below ALREADY contains the exact localized image paths in '<img src=\"/...\" />' tags!
   - You MUST extract and use the exact 'src' from each corresponding section in the Captured HTML:
     * Hero 1 (iPhone 18 Pro): Dark theme ('bg-black text-white'), use '/hero_iphone_18_pro_preorder__dd68unjbzswi_large.jpg'.
     * Hero 2 (iPhone Duo): Light theme ('bg-[#f5f5f7] text-neutral-900'), MUST use the unfolded folding device held in two hands: '/hero_iphone_duo_announce__fh4u8yzndpe2_largetall.jpg'. NEVER repeat the Pro phone image here!
     * Hero 3 (Apple Watch Series 12): Dark theme ('bg-black text-white'), MUST use the centered dual watches image: '/hero_apple_watch_series_12_preorder__cv2wd7ow8926_largetall.jpg' with logo '/hero_logo_apple_watch_series_12__eze8r897c5me_large.png'.
2. HERO SECTIONS (SCALE & CENTERING):
   - Hero container: 'w-full min-h-[580px] lg:min-h-[660px] flex flex-col items-center justify-between text-center relative overflow-hidden py-12 px-4'.
   - Product images MUST NOT be tiny thumbnails: Use 'w-full max-w-[850px] lg:max-w-[1050px] object-contain mx-auto mt-6' (Watch image centered and large!).
   - Typography: Bold headline ('text-4xl sm:text-5xl lg:text-6xl font-semibold tracking-tight'), subheadline ('text-xl sm:text-2xl mt-2 text-neutral-300 font-normal'), and pill CTAs ('bg-blue-600 hover:bg-blue-700 text-white rounded-full px-5 py-2 text-sm font-medium', 'border border-blue-600 text-blue-600 hover:bg-blue-600 hover:text-white rounded-full px-5 py-2 text-sm font-medium transition-colors').
3. PROMO CARDS (FULL-BLEED EDGE-TO-EDGE ARTWORK):
   - 2-column responsive grid on desktop: 'grid grid-cols-1 md:grid-cols-2 gap-4 max-w-[1280px] mx-auto px-4 my-4'.
   - EVERY PROMO CARD MUST HAVE FULL-BLEED EDGE-TO-EDGE BACKGROUND ARTWORK (like Apple.com):
     Outer container: relative min-h-[560px] rounded-3xl overflow-hidden flex flex-col justify-between items-center text-center p-8 bg-neutral-900.
     Image element: '<img src=\"/promo_airpods_5__large.jpg\" alt=\"AirPods 5\" className=\"absolute inset-0 w-full h-full object-cover object-center\" />'.
     Text overlay container: relative z-10 flex flex-col items-center with title, subhead, and CTA pill links.
   - Cards in grid: Watch Ultra 4, AirPods 5, iCloud+, MacBook Air, Apple Upgrade, Apple Card.
   - Use the exact full-bleed artwork image path for each card from the Captured HTML.
4. MOBILE NAVIGATION (FULL-SCREEN DRAWER):
   - In Navbar.tsx, implement a mobile drawer with useState(false) and hamburger toggle icons ('Menu' and 'X' from 'lucide-react').
   - When opened on mobile, it MUST be a full-screen drawer: 'fixed inset-x-0 top-12 bottom-0 bg-neutral-950/95 backdrop-blur-2xl z-50 flex flex-col px-8 py-8 space-y-4 overflow-y-auto'.
   - Links inside mobile drawer: 'text-2xl font-semibold text-neutral-200 hover:text-white transition-colors border-b border-neutral-800/80 pb-3 block'.
5. DUAL CAROUSELS (src/components/Carousel.{$ext}):
   - Implement BOTH carousels found in the Captured HTML under 'Endless entertainment':
     A) **Carousel 1: Apple TV+ 3-Card Continuous Filmstrip Slider**:
        - Shows **3 slides visible simultaneously** across the screen:
          * Active center card: 'w-[65vw] max-w-[980px] h-[460px] lg:h-[540px] rounded-2xl shadow-2xl relative overflow-hidden flex-shrink-0 transition-all duration-700'.
          * Left & right adjacent cards: 'w-[48vw] max-w-[700px] h-[460px] lg:h-[540px] opacity-40 hover:opacity-80 scale-95 hover:scale-100 rounded-2xl overflow-hidden flex-shrink-0 transition-all duration-700 cursor-pointer'.
        - Track: 'flex items-center justify-center gap-6 transition-transform duration-700 ease-out'.
        - Slide content: Full poster image covering card ('absolute inset-0 w-full h-full object-cover'), movie title/logo overlay, genre badge, 'Stream now' pill CTA with Play icon.
        - Controls: Chevron buttons ('ChevronLeft', 'ChevronRight'), auto-advancing useEffect (4s), play/pause toggle ('Play', 'Pause'), and interactive expanding pagination pill dots.
        - Slides: Widow's Bay, Severance, The Morning Show, Ted Lasso, Foundation (using real poster images from Captured HTML).
     B) **Carousel 2: Stream Reel / Category Ribbon**:
        - Horizontal scrolling card strip right below Carousel 1: 'flex gap-4 overflow-x-auto py-6 px-4 scrollbar-none'.
        - Secondary thumbnail cards (Fitness+, Hello Kitty, Dolly Parton, etc.) with rounded corners and subtle hover zoom.
6. COMPLETE FOOTER DIRECTORY (ALL 11 COLUMNS & LEGAL):
   - In src/components/Footer.{$ext}, you MUST include the complete Apple directory exactly as captured in the DOM:
     - All 11 directory columns: Shop and Learn, Apple Wallet, Account, Entertainment, Apple Store, For Business, For Education, For Healthcare, For Government, Apple Values, About Apple.
     - Complete legal footnotes section at the top of the footer.
     - Copyright notice ('Copyright © 2026 Apple Inc. All rights reserved.'), legal links ('Privacy Policy', 'Terms of Use', 'Sales and Refunds', 'Legal', 'Site Map'), and country selector ('United States').
7. OUTPUT STRUCTURE:
   - Output ONLY the UI components under 'src/':
     * src/App.{$ext} (default export App, assembling Navbar, Hero, PromoGrid, Carousel, and Footer)
     * src/components/Navbar.{$ext}
     * src/components/Hero.{$ext}
     * src/components/PromoGrid.{$ext}
     * src/components/Carousel.{$ext}
     * src/components/Footer.{$ext}
   - Do NOT output config files (NO package.json, vite.config, tsconfig, or index.html).
   - Output each file inside a markdown code block with '// FILE: path' on the very first line comment:

```{$ext}
// FILE: src/components/Navbar.{$ext}
[code here]
```

```{$ext}
// FILE: src/components/Hero.{$ext}
[code here]
```

```{$ext}
// FILE: src/components/PromoGrid.{$ext}
[code here]
```

```{$ext}
// FILE: src/components/Carousel.{$ext}
[code here]
```

```{$ext}
// FILE: src/components/Footer.{$ext}
[code here]
```

```{$ext}
// FILE: src/App.{$ext}
[code here]
```

Captured HTML:
```html
{$html}
```

Extracted Styles:
```css
{$styles}
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
 * Sends request to Google Gemini REST API with Multi-Key Pool & Model Failover
 */
function upr_transpiler_query_gemini( $prompt, $api_keys = null, $json_mode = false ) {
	if ( empty( $api_keys ) ) {
		$api_keys = upr_get_gemini_api_keys();
	} elseif ( is_string( $api_keys ) ) {
		$api_keys = preg_split( '/[\r\n,;]+/', $api_keys );
		$api_keys = array_filter( array_map( 'trim', $api_keys ) );
	}

	if ( empty( $api_keys ) ) {
		return new WP_Error( 'upr_missing_gemini_key', 'No Gemini API keys configured. Please add an API key in settings.', array( 'status' => 500 ) );
	}

	// Models ordered by quality, speed, and active availability
	$candidate_models = array(
		'gemini-3.6-flash',
		'gemini-3.5-flash',
		'gemini-3.7-flash',
		'gemini-3.8-flash',
		'gemini-3.5-flash-lite',
		'gemini-3.1-flash-lite',
		'gemini-flash-lite-latest',
		'gemini-flash-latest'
	);

	$last_error = 'Gemini API call failed';

	// Shuffle keys array so traffic is distributed evenly across Google accounts
	$pool = array_values( $api_keys );
	if ( count( $pool ) > 1 ) {
		shuffle( $pool );
	}

	foreach ( $pool as $current_key ) {
		$key_quota_exhausted = false;

		foreach ( $candidate_models as $model ) {
			if ( $key_quota_exhausted ) {
				break;
			}

			$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$current_key}";

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
				'timeout' => 180,
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

			$error_message = $data['error']['message'] ?? ( 'Model ' . $model . ' returned HTTP ' . $status );
			$last_error = $error_message;

			// Check for rate limit or quota exhaustion (HTTP 429)
			if ( $status === 429 || stripos( $error_message, 'quota' ) !== false || stripos( $error_message, 'rate limit' ) !== false || stripos( $error_message, 'resource has been exhausted' ) !== false ) {
				error_log( "[UPR Replicator] Key ... hit rate limit ({$error_message}). Automatically rotating to next account key in pool." );
				$key_quota_exhausted = true; // rotate to next key in pool
				break;
			}

			// If model is 503 (high demand) or 404 (deprecated), continue to next model with current key
		}
	}

	return new WP_Error( 'upr_gemini_error', $last_error, array( 'status' => 500 ) );
}
