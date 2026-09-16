<?php
/**
 * Master CSS Rules to Tailwind Class Conversion Engine
 * Universal, deterministic translation of extracted CSS rules into Tailwind utility classes.
 * 
 * ZERO HARDCODING: All styling is derived strictly from the source page's authentic CSS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts a single CSS property declaration (e.g. 'display', 'flex') to a Tailwind utility class.
 */
function upr_css_declaration_to_tailwind( $prop, $val ) {
	$prop = strtolower( trim( $prop ) );
	$val  = trim( $val, " \t\n\r\0\x0B;" );
	$val_clean = str_replace( array( ' !important', '!important' ), '', $val );
	$val_clean = trim( $val_clean );

	if ( empty( $prop ) || empty( $val_clean ) ) {
		return null;
	}

	// 1. Display
	if ( $prop === 'display' ) {
		$map = array(
			'flex'         => 'flex',
			'inline-flex'  => 'inline-flex',
			'grid'         => 'grid',
			'inline-grid'  => 'inline-grid',
			'block'        => 'block',
			'inline-block' => 'inline-block',
			'inline'       => 'inline',
			'none'         => 'hidden',
			'table'        => 'table',
			'contents'     => 'contents',
		);
		return $map[ $val_clean ] ?? null;
	}

	// 2. Flexbox
	if ( $prop === 'flex-direction' ) {
		$map = array(
			'row'            => 'flex-row',
			'row-reverse'    => 'flex-row-reverse',
			'column'         => 'flex-col',
			'column-reverse' => 'flex-col-reverse',
		);
		return $map[ $val_clean ] ?? null;
	}

	if ( $prop === 'flex-wrap' ) {
		$map = array(
			'wrap'         => 'flex-wrap',
			'wrap-reverse' => 'flex-wrap-reverse',
			'nowrap'       => 'flex-nowrap',
		);
		return $map[ $val_clean ] ?? null;
	}

	if ( $prop === 'justify-content' ) {
		$map = array(
			'flex-start'    => 'justify-start',
			'start'         => 'justify-start',
			'flex-end'      => 'justify-end',
			'end'           => 'justify-end',
			'center'        => 'justify-center',
			'space-between' => 'justify-between',
			'space-around'  => 'justify-around',
			'space-evenly'  => 'justify-evenly',
		);
		return $map[ $val_clean ] ?? null;
	}

	if ( $prop === 'align-items' ) {
		$map = array(
			'flex-start' => 'items-start',
			'start'      => 'items-start',
			'flex-end'   => 'items-end',
			'end'        => 'items-end',
			'center'     => 'items-center',
			'baseline'   => 'items-baseline',
			'stretch'    => 'items-stretch',
		);
		return $map[ $val_clean ] ?? null;
	}

	if ( $prop === 'align-self' ) {
		$map = array(
			'auto'       => 'self-auto',
			'flex-start' => 'self-start',
			'start'      => 'self-start',
			'flex-end'   => 'self-end',
			'end'        => 'self-end',
			'center'     => 'self-center',
			'stretch'    => 'self-stretch',
			'baseline'   => 'self-baseline',
		);
		return $map[ $val_clean ] ?? null;
	}

	if ( $prop === 'flex-grow' ) {
		return $val_clean === '1' ? 'grow' : ( $val_clean === '0' ? 'grow-0' : "grow-[{$val_clean}]" );
	}

	if ( $prop === 'flex-shrink' ) {
		return $val_clean === '1' ? 'shrink' : ( $val_clean === '0' ? 'shrink-0' : "shrink-[{$val_clean}]" );
	}

	// 3. Grid
	if ( $prop === 'grid-template-columns' ) {
		if ( preg_match( '/^repeat\(\s*(\d+)\s*,/i', $val_clean, $gm ) ) {
			return 'grid-cols-' . $gm[1];
		}
		return 'grid-cols-[' . str_replace( ' ', '_', $val_clean ) . ']';
	}

	if ( $prop === 'gap' ) {
		$v = str_replace( ' ', '_', $val_clean );
		return "gap-[{$v}]";
	}
	if ( $prop === 'row-gap' ) {
		$v = str_replace( ' ', '_', $val_clean );
		return "gap-y-[{$v}]";
	}
	if ( $prop === 'column-gap' ) {
		$v = str_replace( ' ', '_', $val_clean );
		return "gap-x-[{$v}]";
	}

	// 4. Position & Inset
	if ( $prop === 'position' ) {
		$map = array(
			'static'   => 'static',
			'relative' => 'relative',
			'absolute' => 'absolute',
			'fixed'    => 'fixed',
			'sticky'   => 'sticky',
		);
		return $map[ $val_clean ] ?? null;
	}

	if ( in_array( $prop, array( 'top', 'bottom', 'left', 'right' ), true ) ) {
		if ( $val_clean === '0' || $val_clean === '0px' ) {
			return "{$prop}-0";
		}
		if ( $val_clean === 'auto' ) {
			return "{$prop}-auto";
		}
		$v = str_replace( ' ', '_', $val_clean );
		return "{$prop}-[{$v}]";
	}

	if ( $prop === 'inset' ) {
		if ( $val_clean === '0' || $val_clean === '0px' ) return 'inset-0';
		$v = str_replace( ' ', '_', $val_clean );
		return "inset-[{$v}]";
	}

	if ( $prop === 'z-index' ) {
		$map = array( '0' => 'z-0', '10' => 'z-10', '20' => 'z-20', '30' => 'z-30', '40' => 'z-40', '50' => 'z-50', 'auto' => 'z-auto' );
		return $map[ $val_clean ] ?? "z-[{$val_clean}]";
	}

	// 5. Dimensions & Sizing
	if ( $prop === 'width' ) {
		if ( $val_clean === '100%' ) return 'w-full';
		if ( $val_clean === '100vw' ) return 'w-screen';
		if ( $val_clean === 'auto' ) return 'w-auto';
		if ( $val_clean === 'max-content' ) return 'w-max';
		if ( $val_clean === 'min-content' ) return 'w-min';
		if ( $val_clean === 'fit-content' ) return 'w-fit';
		return 'w-[' . str_replace( ' ', '_', $val_clean ) . ']';
	}

	if ( $prop === 'max-width' ) {
		if ( $val_clean === '100%' ) return 'max-w-full';
		if ( $val_clean === 'none' ) return 'max-w-none';
		return 'max-w-[' . str_replace( ' ', '_', $val_clean ) . ']';
	}

	if ( $prop === 'min-width' ) {
		if ( $val_clean === '0' || $val_clean === '0px' ) return 'min-w-0';
		if ( $val_clean === '100%' ) return 'min-w-full';
		return 'min-w-[' . str_replace( ' ', '_', $val_clean ) . ']';
	}

	if ( $prop === 'height' ) {
		if ( $val_clean === '100%' ) return 'h-full';
		if ( $val_clean === '100vh' ) return 'h-screen';
		if ( $val_clean === 'auto' ) return 'h-auto';
		if ( $val_clean === 'max-content' ) return 'h-max';
		if ( $val_clean === 'min-content' ) return 'h-min';
		if ( $val_clean === 'fit-content' ) return 'h-fit';
		return 'h-[' . str_replace( ' ', '_', $val_clean ) . ']';
	}

	if ( $prop === 'max-height' ) {
		if ( $val_clean === '100%' ) return 'max-h-full';
		if ( $val_clean === '100vh' ) return 'max-h-screen';
		if ( $val_clean === 'none' ) return 'max-h-none';
		return 'max-h-[' . str_replace( ' ', '_', $val_clean ) . ']';
	}

	if ( $prop === 'min-height' ) {
		if ( $val_clean === '0' || $val_clean === '0px' ) return 'min-h-0';
		if ( $val_clean === '100%' ) return 'min-h-full';
		if ( $val_clean === '100vh' ) return 'min-h-screen';
		return 'min-h-[' . str_replace( ' ', '_', $val_clean ) . ']';
	}

	if ( $prop === 'aspect-ratio' ) {
		$clean_ar = preg_replace( '/\s+/', '', $val_clean );
		if ( $clean_ar === '16/9' ) return 'aspect-video';
		if ( $clean_ar === '1/1' || $clean_ar === '1' ) return 'aspect-square';
		return "aspect-[{$clean_ar}]";
	}

	// 6. Typography
	if ( $prop === 'font-size' ) {
		return 'text-[' . str_replace( ' ', '_', $val_clean ) . ']';
	}

	if ( $prop === 'font-weight' ) {
		$weights = array(
			'100' => 'font-thin',
			'200' => 'font-extralight',
			'300' => 'font-light',
			'400' => 'font-normal',
			'normal' => 'font-normal',
			'500' => 'font-medium',
			'600' => 'font-semibold',
			'700' => 'font-bold',
			'bold' => 'font-bold',
			'800' => 'font-extrabold',
			'900' => 'font-black',
		);
		return $weights[ $val_clean ] ?? "font-[{$val_clean}]";
	}

	if ( $prop === 'line-height' ) {
		if ( $val_clean === '1' ) return 'leading-none';
		if ( $val_clean === '1.25' ) return 'leading-tight';
		if ( $val_clean === '1.375' ) return 'leading-snug';
		if ( $val_clean === '1.5' ) return 'leading-normal';
		if ( $val_clean === '1.625' ) return 'leading-relaxed';
		if ( $val_clean === '2' ) return 'leading-loose';
		return 'leading-[' . str_replace( ' ', '_', $val_clean ) . ']';
	}

	if ( $prop === 'letter-spacing' ) {
		return 'tracking-[' . str_replace( ' ', '_', $val_clean ) . ']';
	}

	if ( $prop === 'text-align' ) {
		$map = array(
			'left'    => 'text-left',
			'center'  => 'text-center',
			'right'   => 'text-right',
			'justify' => 'text-justify',
		);
		return $map[ $val_clean ] ?? null;
	}

	if ( $prop === 'text-transform' ) {
		$map = array(
			'uppercase'  => 'uppercase',
			'lowercase'  => 'lowercase',
			'capitalize' => 'capitalize',
			'none'       => 'normal-case',
		);
		return $map[ $val_clean ] ?? null;
	}

	if ( $prop === 'text-decoration' || $prop === 'text-decoration-line' ) {
		if ( strpos( $val_clean, 'underline' ) !== false ) return 'underline';
		if ( strpos( $val_clean, 'line-through' ) !== false ) return 'line-through';
		if ( strpos( $val_clean, 'none' ) !== false ) return 'no-underline';
	}

	if ( $prop === 'color' ) {
		if ( $val_clean === 'inherit' ) return 'text-inherit';
		if ( $val_clean === 'transparent' ) return 'text-transparent';
		if ( in_array( $val_clean, array( '#fff', '#ffffff', 'white' ), true ) ) return 'text-white';
		if ( in_array( $val_clean, array( '#000', '#000000', 'black' ), true ) ) return 'text-black';
		return 'text-[' . str_replace( ' ', '_', $val_clean ) . ']';
	}

	// 7. Spacing (Padding & Margin)
	if ( in_array( $prop, array( 'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right' ), true ) ) {
		$prefix = array(
			'padding'        => 'p',
			'padding-top'    => 'pt',
			'padding-bottom' => 'pb',
			'padding-left'   => 'pl',
			'padding-right'  => 'pr',
		)[ $prop ];

		if ( $val_clean === '0' || $val_clean === '0px' ) return "{$prefix}-0";
		
		// Split multi-value padding (e.g. 10px 20px)
		if ( $prop === 'padding' ) {
			$parts = preg_split( '/\s+/', $val_clean );
			if ( count( $parts ) === 2 ) {
				$py = $parts[0] === '0' ? 'py-0' : 'py-[' . $parts[0] . ']';
				$px = $parts[1] === '0' ? 'px-0' : 'px-[' . $parts[1] . ']';
				return "{$py} {$px}";
			}
		}

		$v = str_replace( ' ', '_', $val_clean );
		return "{$prefix}-[{$v}]";
	}

	if ( in_array( $prop, array( 'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right' ), true ) ) {
		$prefix = array(
			'margin'        => 'm',
			'margin-top'    => 'mt',
			'margin-bottom' => 'mb',
			'margin-left'   => 'ml',
			'margin-right'  => 'mr',
		)[ $prop ];

		if ( $val_clean === '0' || $val_clean === '0px' ) return "{$prefix}-0";
		if ( $val_clean === 'auto' ) {
			return $prop === 'margin' ? 'm-auto' : "{$prefix}-auto";
		}

		// Split multi-value margin (e.g. 0 auto)
		if ( $prop === 'margin' ) {
			$parts = preg_split( '/\s+/', $val_clean );
			if ( count( $parts ) === 2 ) {
				$my = $parts[0] === 'auto' ? 'my-auto' : ( $parts[0] === '0' ? 'my-0' : 'my-[' . $parts[0] . ']' );
				$mx = $parts[1] === 'auto' ? 'mx-auto' : ( $parts[1] === '0' ? 'mx-0' : 'mx-[' . $parts[1] . ']' );
				return "{$my} {$mx}";
			}
		}

		$v = str_replace( ' ', '_', $val_clean );
		return "{$prefix}-[{$v}]";
	}

	// 8. Borders & Radii
	if ( $prop === 'border-radius' ) {
		if ( in_array( $val_clean, array( '50%', '9999px', '100%' ), true ) ) return 'rounded-full';
		if ( $val_clean === '0' || $val_clean === '0px' ) return 'rounded-none';
		$v = str_replace( ' ', '_', $val_clean );
		return "rounded-[{$v}]";
	}

	if ( $prop === 'border' || $prop === 'border-width' ) {
		if ( $val_clean === 'none' || $val_clean === '0' || $val_clean === '0px' ) return 'border-0';
		if ( $val_clean === '1px solid transparent' ) return 'border border-transparent';
		if ( preg_match( '/^1px\s+solid\s+(.+)$/i', $val_clean, $bm ) ) {
			$c = trim( $bm[1] );
			return 'border border-[' . str_replace( ' ', '_', $c ) . ']';
		}
		if ( preg_match( '/^(\d+px)\s+solid\s+(.+)$/i', $val_clean, $bm ) ) {
			$w = $bm[1];
			$c = trim( $bm[2] );
			return "border-[{$w}] border-[" . str_replace( ' ', '_', $c ) . ']';
		}
	}

	if ( $prop === 'border-color' ) {
		return 'border-[' . str_replace( ' ', '_', $val_clean ) . ']';
	}

	// 9. Backgrounds
	if ( $prop === 'background-color' || ( $prop === 'background' && ! preg_match( '/url\(/i', $val_clean ) ) ) {
		if ( $val_clean === 'transparent' ) return 'bg-transparent';
		if ( in_array( $val_clean, array( '#fff', '#ffffff', 'white' ), true ) ) return 'bg-white';
		if ( in_array( $val_clean, array( '#000', '#000000', 'black' ), true ) ) return 'bg-black';
		return 'bg-[' . str_replace( ' ', '_', $val_clean ) . ']';
	}

	if ( $prop === 'background-size' ) {
		if ( $val_clean === 'cover' ) return 'bg-cover';
		if ( $val_clean === 'contain' ) return 'bg-contain';
	}

	if ( $prop === 'background-position' ) {
		if ( $val_clean === 'center' ) return 'bg-center';
		if ( $val_clean === 'top' ) return 'bg-top';
		if ( $val_clean === 'bottom' ) return 'bg-bottom';
	}

	// 10. Overflow & Object Fit
	if ( $prop === 'overflow' ) {
		$map = array( 'hidden' => 'overflow-hidden', 'auto' => 'overflow-auto', 'scroll' => 'overflow-scroll', 'visible' => 'overflow-visible' );
		return $map[ $val_clean ] ?? null;
	}
	if ( $prop === 'overflow-x' ) {
		$map = array( 'hidden' => 'overflow-x-hidden', 'auto' => 'overflow-x-auto', 'scroll' => 'overflow-x-scroll' );
		return $map[ $val_clean ] ?? null;
	}
	if ( $prop === 'overflow-y' ) {
		$map = array( 'hidden' => 'overflow-y-hidden', 'auto' => 'overflow-y-auto', 'scroll' => 'overflow-y-scroll' );
		return $map[ $val_clean ] ?? null;
	}

	if ( $prop === 'object-fit' ) {
		$map = array( 'cover' => 'object-cover', 'contain' => 'object-contain', 'fill' => 'object-fill', 'none' => 'object-none' );
		return $map[ $val_clean ] ?? null;
	}

	if ( $prop === 'object-position' ) {
		$map = array( 'center' => 'object-center', 'bottom' => 'object-bottom', 'top' => 'object-top', 'left' => 'object-left', 'right' => 'object-right' );
		return $map[ $val_clean ] ?? null;
	}

	// 11. Opacity & Visibility
	if ( $prop === 'opacity' ) {
		if ( $val_clean === '0' ) return 'opacity-0';
		if ( $val_clean === '1' ) return 'opacity-100';
		$pct = round( floatval( $val_clean ) * 100 );
		return "opacity-{$pct}";
	}

	if ( $prop === 'cursor' ) {
		if ( $val_clean === 'pointer' ) return 'cursor-pointer';
		if ( $val_clean === 'default' ) return 'cursor-default';
	}

	return null;
}

/**
 * Parses raw CSS string into structured selectors and rule maps.
 */
function upr_parse_css_to_rules( $css_content ) {
	// Strip comments
	$css = preg_replace( '/\/\*[\s\S]*?\*\//', '', $css_content );

	$rule_map = array();

	// Match rule blocks: selector { ... }
	if ( preg_match_all( '/([^{}]+)\{([^{}]+)\}/', $css, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $m ) {
			$selector_block = trim( $m[1] );
			$declarations   = trim( $m[2] );

			// Skip @keyframes, @font-face, etc.
			if ( strpos( $selector_block, '@' ) !== false ) {
				continue;
			}

			// Parse declarations: prop: val;
			$decls = explode( ';', $declarations );
			$parsed_decls = array();
			foreach ( $decls as $d ) {
				if ( strpos( $d, ':' ) === false ) continue;
				list( $p, $v ) = explode( ':', $d, 2 );
				$p = trim( $p );
				$v = trim( $v );
				if ( ! empty( $p ) && ! empty( $v ) ) {
					$parsed_decls[ $p ] = $v;
				}
			}

			if ( empty( $parsed_decls ) ) continue;

			// Handle comma-separated selectors
			$selectors = explode( ',', $selector_block );
			foreach ( $selectors as $sel ) {
				$sel = trim( $sel );
				if ( empty( $sel ) ) continue;

				if ( ! isset( $rule_map[ $sel ] ) ) {
					$rule_map[ $sel ] = array();
				}
				$rule_map[ $sel ] = array_merge( $rule_map[ $sel ], $parsed_decls );
			}
		}
	}

	return $rule_map;
}

/**
 * Converts a parsed rule map into a selector -> Tailwind classes mapping.
 */
function upr_convert_css_rules_to_tailwind_map( $rule_map ) {
	$tw_map = array();

	foreach ( $rule_map as $sel => $decls ) {
		$tw_classes = array();
		foreach ( $decls as $prop => $val ) {
			$tw = upr_css_declaration_to_tailwind( $prop, $val );
			if ( ! empty( $tw ) ) {
				$tw_classes[] = $tw;
			}
		}
		if ( ! empty( $tw_classes ) ) {
			$tw_map[ $sel ] = implode( ' ', array_unique( explode( ' ', implode( ' ', $tw_classes ) ) ) );
		}
	}

	return $tw_map;
}

/**
 * Annotates HTML with Tailwind utility classes directly derived from authentic CSS stylesheets.
 * Zero hardcoded styles; every class is extracted from the website's real CSS rules.
 */
function upr_annotate_html_with_tailwind( $html, $css_content ) {
	if ( empty( $html ) || empty( $css_content ) ) {
		return $html;
	}

	$rule_map = upr_parse_css_to_rules( $css_content );
	$tw_map   = upr_convert_css_rules_to_tailwind_map( $rule_map );

	if ( empty( $tw_map ) ) {
		return $html;
	}

	// Index class selectors for fast lookup: .foo -> tw_classes
	$class_tw = array();
	foreach ( $tw_map as $sel => $classes ) {
		// Clean simple class selectors like .my-class or .my-class:hover
		if ( preg_match( '/^\.([a-zA-Z0-9_\-]+)$/', $sel, $cm ) ) {
			$class_tw[ $cm[1] ] = $classes;
		}
	}

	// Walk DOM or regex-replace HTML tags to inject authentic Tailwind classes
	$annotated = preg_replace_callback( '/<([a-zA-Z0-9\-]+)\b([^>]*)>/is', function( $m ) use ( $class_tw ) {
		$tag   = $m[1];
		$attrs = $m[2];

		if ( in_array( strtolower( $tag ), array( 'script', 'style', 'meta', 'link', 'head', 'html' ), true ) ) {
			return $m[0];
		}

		$existing_classes = '';
		if ( preg_match( '/class=["\']([^"\']*)["\']/i', $attrs, $cm ) ) {
			$existing_classes = $cm[1];
		}

		$injected_tw = array();

		// Convert any existing inline style attribute to Tailwind directly
		if ( preg_match( '/style=["\']([^"\']*)["\']/i', $attrs, $sm ) ) {
			$style_str = $sm[1];
			$inline_decls = explode( ';', $style_str );
			foreach ( $inline_decls as $id ) {
				if ( strpos( $id, ':' ) !== false ) {
					list( $ip, $iv ) = explode( ':', $id, 2 );
					$tw = upr_css_declaration_to_tailwind( $ip, $iv );
					if ( ! empty( $tw ) ) {
						$injected_tw[] = $tw;
					}
				}
			}
		}

		// Match classes against our CSS rule-to-Tailwind map
		if ( ! empty( $existing_classes ) ) {
			$class_tokens = preg_split( '/\s+/', trim( $existing_classes ) );
			foreach ( $class_tokens as $token ) {
				if ( isset( $class_tw[ $token ] ) ) {
					$injected_tw[] = $class_tw[ $token ];
				}
			}
		}

		if ( empty( $injected_tw ) ) {
			return $m[0];
		}

		$new_tw_str = implode( ' ', array_unique( explode( ' ', implode( ' ', $injected_tw ) ) ) );

		if ( preg_match( '/class=["\']([^"\']*)["\']/i', $attrs ) ) {
			$attrs = preg_replace( '/class=["\']([^"\']*)["\']/i', 'class="$1 ' . esc_attr( $new_tw_str ) . '"', $attrs );
		} else {
			$attrs .= ' class="' . esc_attr( $new_tw_str ) . '"';
		}

		return "<{$tag}{$attrs}>";
	}, $html );

	return $annotated;
}
