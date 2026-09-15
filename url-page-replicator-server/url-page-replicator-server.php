<?php
/**
 * Plugin Name: URL Page Replicator Server
 * Description: SaaS Server API engine for headless rendering, proxy sandboxing, package packaging, and AI multi-framework code generation.
 * Version: 2.0.0
 * Author: Antigravity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UPR_SERVER_PATH', plugin_dir_path( __FILE__ ) );
define( 'UPR_SERVER_URL', plugin_dir_url( __FILE__ ) );

// Hardcoded Gemini API Key (can be set here or in wp-config.php)
if ( ! defined( 'UPR_GEMINI_API_KEY' ) ) {
	define( 'UPR_GEMINI_API_KEY', '' ); // Paste your Gemini API key here or define in wp-config.php
}

// Handle CORS Preflight OPTIONS Requests early
add_action( 'init', 'upr_server_handle_cors_preflight' );
function upr_server_handle_cors_preflight() {
	if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( strpos( $request_uri, '/upr-server/v1/' ) !== false ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-UPR-Master-Key, X-Requested-With' );
			header( 'Access-Control-Max-Age: 86400' );
			status_header( 200 );
			exit;
		}
	}
}

// Send CORS headers with all REST API responses
add_action( 'rest_api_init', function() {
	remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
	add_filter( 'rest_pre_serve_request', function( $value ) {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-UPR-Master-Key, X-Requested-With' );
		return $value;
	} );
}, 15 );

// Create custom DB table on activation
register_activation_hook( __FILE__, 'upr_server_activate' );
function upr_server_activate() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'upr_server_tokens';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		token varchar(64) NOT NULL,
		client_url varchar(255) NOT NULL,
		credits_total int(11) DEFAULT 0 NOT NULL,
		credits_used int(11) DEFAULT 0 NOT NULL,
		free_credits_monthly int(11) DEFAULT 3 NOT NULL,
		free_credits_used int(11) DEFAULT 0 NOT NULL,
		last_free_reset_month varchar(7) DEFAULT '' NOT NULL,
		status varchar(20) DEFAULT 'active' NOT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY token (token)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

// Ensure database schema is automatically updated if columns are missing
add_action( 'plugins_loaded', 'upr_server_ensure_db_schema' );
function upr_server_ensure_db_schema() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'upr_server_tokens';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
		upr_server_activate();
		return;
	}

	$cols = $wpdb->get_col( "DESC $table_name", 0 );
	if ( ! in_array( 'last_free_reset_month', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE $table_name ADD COLUMN last_free_reset_month varchar(7) DEFAULT '' NOT NULL AFTER credits_used" );
	}
	if ( ! in_array( 'free_credits_used', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE $table_name ADD COLUMN free_credits_used int(11) DEFAULT 0 NOT NULL AFTER last_free_reset_month" );
	}
	if ( ! in_array( 'free_credits_monthly', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE $table_name ADD COLUMN free_credits_monthly int(11) DEFAULT 3 NOT NULL AFTER credits_used" );
	}
}

// Register REST API Routes
add_action( 'rest_api_init', 'upr_server_register_routes' );
function upr_server_register_routes() {
	register_rest_route( 'upr-server/v1', '/replicate', array(
		'methods'             => 'POST',
		'callback'            => 'upr_server_handle_replicate',
		'permission_callback' => 'upr_server_validate_token',
	) );

	register_rest_route( 'upr-server/v1', '/replicate-figma', array(
		'methods'             => 'POST',
		'callback'            => 'upr_server_handle_replicate_figma',
		'permission_callback' => 'upr_server_validate_token',
	) );

	register_rest_route( 'upr-server/v1', '/transpile-component', array(
		'methods'             => 'POST',
		'callback'            => 'upr_server_handle_transpile_component',
		'permission_callback' => 'upr_server_validate_token',
	) );

	register_rest_route( 'upr-server/v1', '/credits/info', array(
		'methods'             => 'GET',
		'callback'            => 'upr_server_handle_credits_info',
		'permission_callback' => 'upr_server_validate_token',
	) );

	register_rest_route( 'upr-server/v1', '/credits/add', array(
		'methods'             => 'POST',
		'callback'            => 'upr_server_handle_add_credits',
		'permission_callback' => '__return_true', // guarded internally via master key
	) );
}

/**
 * Synchronize monthly free token allowance (resets to 3 free tokens on the 1st of every month)
 */
function upr_server_sync_token_credits( &$row ) {
	global $wpdb;
	$current_month = current_time( 'Y-m' );
	$table_name = $wpdb->prefix . 'upr_server_tokens';

	if ( empty( $row->last_free_reset_month ) || $row->last_free_reset_month !== $current_month ) {
		$wpdb->update(
			$table_name,
			array(
				'last_free_reset_month' => $current_month,
				'free_credits_used'     => 0,
			),
			array( 'id' => $row->id )
		);
		$row->last_free_reset_month = $current_month;
		$row->free_credits_used     = 0;
	}

	$free_monthly_total = isset( $row->free_credits_monthly ) ? intval( $row->free_credits_monthly ) : 3;
	$free_used          = intval( $row->free_credits_used );
	$free_remaining     = max( 0, $free_monthly_total - $free_used );

	$purchased_total    = intval( $row->credits_total );
	$purchased_used     = intval( $row->credits_used );
	$purchased_remaining= max( 0, $purchased_total - $purchased_used );

	$total_available    = $free_remaining + $purchased_remaining;

	return array(
		'free_monthly_total'   => $free_monthly_total,
		'free_used'            => $free_used,
		'free_remaining'       => $free_remaining,
		'purchased_total'      => $purchased_total,
		'purchased_used'       => $purchased_used,
		'purchased_remaining'  => $purchased_remaining,
		'total_available'      => $total_available,
		'current_month'        => $current_month,
	);
}

/**
 * Consume token credit (consumes monthly free credit first, then purchased credits)
 */
function upr_server_consume_credit( $token_row, $amount = 1 ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'upr_server_tokens';
	$credits_meta = upr_server_sync_token_credits( $token_row );

	$free_to_consume = min( $amount, $credits_meta['free_remaining'] );
	$purchased_to_consume = $amount - $free_to_consume;

	$update_data = array();
	if ( $free_to_consume > 0 ) {
		$update_data['free_credits_used'] = intval( $token_row->free_credits_used ) + $free_to_consume;
	}
	if ( $purchased_to_consume > 0 ) {
		$update_data['credits_used'] = intval( $token_row->credits_used ) + $purchased_to_consume;
	}

	if ( ! empty( $update_data ) ) {
		$wpdb->update( $table_name, $update_data, array( 'id' => $token_row->id ) );
	}

	return true;
}

// Credits Info Endpoint Handler
function upr_server_handle_credits_info( WP_REST_Request $request ) {
	$token_row = $request->get_param( 'upr_token_row' );
	$meta = upr_server_sync_token_credits( $token_row );

	return rest_ensure_response( array(
		'status'                     => 'success',
		'client_url'                 => $token_row->client_url,
		'free_monthly_total'         => $meta['free_monthly_total'],
		'free_monthly_used'          => $meta['free_used'],
		'free_monthly_remaining'     => $meta['free_remaining'],
		'purchased_total'            => $meta['purchased_total'],
		'purchased_used'             => $meta['purchased_used'],
		'purchased_remaining'        => $meta['purchased_remaining'],
		'total_available'            => $meta['total_available'],
		'current_month'              => $meta['current_month'],
		'renewal_url'                => 'https://inventkid.com/pricing'
	) );
}

// Token Validation Permission Callback
function upr_server_validate_token( WP_REST_Request $request ) {
	$auth_header = $request->get_header( 'Authorization' );
	$token = '';
	if ( ! empty( $auth_header ) && preg_match( '/Bearer\s+(.+)/i', $auth_header, $matches ) ) {
		$token = sanitize_text_field( $matches[1] );
	} elseif ( ! empty( $request->get_param( 'token' ) ) ) {
		$token = sanitize_text_field( $request->get_param( 'token' ) );
	}

	if ( empty( $token ) ) {
		return new WP_Error( 'upr_unauthorized', 'Missing or malformed Authorization header or token.', array( 'status' => 401 ) );
	}
	global $wpdb;
	$table_name = $wpdb->prefix . 'upr_server_tokens';
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE token = %s AND status = 'active'", $token ) );

	if ( ! $row ) {
		return new WP_Error( 'upr_forbidden', 'Invalid or inactive API token.', array( 'status' => 403 ) );
	}

	// Validate requesting origin / URL matches the registered client_url (Allow Chrome extensions automatically)
	$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
	if ( ! empty( $origin ) ) {
		if ( strpos( $origin, 'chrome-extension://' ) === 0 ) {
			// Browser extension allowed
		} else {
			$origin_host = parse_url( $origin, PHP_URL_HOST );
			$client_host = parse_url( $row->client_url, PHP_URL_HOST );
			if ( $origin_host && $client_host && strcasecmp( $origin_host, $client_host ) !== 0 && strpos( $origin_host, $client_host ) === false ) {
				return new WP_Error( 'upr_forbidden_origin', 'Requesting origin does not match the registered client URL.', array( 'status' => 403 ) );
			}
		}
	}

	// Sync free monthly credits and check if credits are available
	$meta = upr_server_sync_token_credits( $row );
	if ( $meta['total_available'] <= 0 ) {
		return new WP_Error( 'upr_payment_required', 'Token credits exhausted. Please renew or purchase tokens on inventkid.com.', array( 'status' => 402 ) );
	}

	// Store token details inside request attributes
	$request->set_param( 'upr_token_row', $row );
	return true;
}

// Expose Admin Menu Dashboard
add_action( 'admin_menu', 'upr_server_add_admin_menu' );
function upr_server_add_admin_menu() {
	add_menu_page(
		'Replicator Server',
		'Replicator Server',
		'manage_options',
		'upr-server-dashboard',
		'upr_server_render_dashboard',
		'dashicons-cloud',
		80
	);
}

// Generate random secure token string
function upr_server_generate_key() {
	return bin2hex( random_bytes( 24 ) );
}

// Render Admin Dashboard UI
function upr_server_render_dashboard() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'upr_server_tokens';

	// Handle token generation submission
	if ( isset( $_POST['upr_generate_token'] ) && check_admin_referer( 'upr_gen_token_action', 'upr_gen_token_nonce' ) ) {
		$client_url = sanitize_text_field( $_POST['client_url'] );
		$credits = intval( $_POST['credits_total'] );
		if ( ! empty( $client_url ) ) {
			$wpdb->insert( $table_name, array(
				'token'                 => upr_server_generate_key(),
				'client_url'            => $client_url,
				'credits_total'         => $credits,
				'credits_used'          => 0,
				'free_credits_monthly'  => 3,
				'free_credits_used'     => 0,
				'last_free_reset_month' => current_time( 'Y-m' ),
				'status'                => 'active',
				'created_at'            => current_time( 'mysql' )
			) );
			echo '<div class="notice notice-success is-dismissible"><p>API Token generated successfully!</p></div>';
		}
	}

	// Handle Gemini & Figma Settings save
	if ( isset( $_POST['upr_save_server_settings'] ) && check_admin_referer( 'upr_save_server_settings_action', 'upr_save_server_settings_nonce' ) ) {
		$figma_token = sanitize_text_field( $_POST['figma_token'] );
		$gemini_key  = sanitize_text_field( $_POST['gemini_api_key'] );
		update_option( 'upr_server_figma_token', $figma_token );
		update_option( 'upr_server_gemini_api_key', $gemini_key );
		echo '<div class="notice notice-success is-dismissible"><p>Server settings saved successfully!</p></div>';
	}

	// Handle token status action
	if ( isset( $_GET['action'] ) && isset( $_GET['token_id'] ) ) {
		$token_id = intval( $_GET['token_id'] );
		if ( $_GET['action'] === 'revoke' ) {
			$wpdb->update( $table_name, array( 'status' => 'revoked' ), array( 'id' => $token_id ) );
		} elseif ( $_GET['action'] === 'activate' ) {
			$wpdb->update( $table_name, array( 'status' => 'active' ), array( 'id' => $token_id ) );
		}
	}

	$figma_token = get_option( 'upr_server_figma_token', '' );
	$gemini_key  = get_option( 'upr_server_gemini_api_key', '' );
	$rows = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY created_at DESC" );
	?>
	<div class="wrap">
		<h1>URL Page Replicator Server Dashboard</h1>
		
		<div style="display: flex; gap: 20px; flex-wrap: wrap;">
			<div class="card" style="flex: 1; min-width: 300px; margin-bottom: 20px; padding: 20px; box-sizing: border-box;">
				<h2>Generate New API Token</h2>
				<form method="post">
					<?php wp_nonce_field( 'upr_gen_token_action', 'upr_gen_token_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th><label for="client_url">Client / Extension Name</label></th>
							<td><input type="text" name="client_url" id="client_url" placeholder="https://client-site.com or chrome-extension" class="regular-text" required style="width: 100%;" /></td>
						</tr>
						<tr>
							<th><label for="credits_total">Initial Purchased Credits</label></th>
							<td>
								<input type="number" name="credits_total" id="credits_total" value="10" class="small-text" required min="0" />
								<p class="description">+ 3 free tokens refreshed automatically each month.</p>
							</td>
						</tr>
					</table>
					<p class="submit" style="margin-bottom: 0; padding-bottom: 0;"><input type="submit" name="upr_generate_token" class="button button-primary" value="Generate Token" /></p>
				</form>
			</div>

			<div class="card" style="flex: 1; min-width: 300px; margin-bottom: 20px; padding: 20px; box-sizing: border-box;">
				<h2>AI & API Configuration</h2>
				<form method="post">
					<?php wp_nonce_field( 'upr_save_server_settings_action', 'upr_save_server_settings_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th><label for="gemini_api_key">Google Gemini API Key</label></th>
							<td>
								<input type="password" name="gemini_api_key" id="gemini_api_key" value="<?php echo esc_attr( $gemini_key ); ?>" placeholder="AIzaSy..." class="regular-text" style="width: 100%;" />
								<p class="description">Required for Layer 2 AI code generation (React, Angular, Clean HTML). <a href="https://aistudio.google.com/" target="_blank">Get Free Gemini API Key &rarr;</a></p>
							</td>
						</tr>
						<tr>
							<th><label for="figma_token">Figma Personal Access Token</label></th>
							<td>
								<input type="text" name="figma_token" id="figma_token" value="<?php echo esc_attr( $figma_token ); ?>" placeholder="figd_..." class="regular-text" style="width: 100%;" />
								<p class="description">Used for figma-to-page prototype compilation.</p>
							</td>
						</tr>
					</table>
					<p class="submit" style="margin-bottom: 0; padding-bottom: 0;"><input type="submit" name="upr_save_server_settings" class="button button-primary" value="Save Settings" /></p>
				</form>
			</div>
		</div>

		<h2>Active API Tokens</h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Client / Origin</th>
					<th>API Token Key</th>
					<th>Free Monthly Credits (Remaining / 3)</th>
					<th>Purchased Credits (Remaining / Total)</th>
					<th>Total Available</th>
					<th>Status</th>
					<th>Created At</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8">No tokens found.</td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php 
						$m = upr_server_sync_token_credits( $row );
						?>
						<tr>
							<td><strong><?php echo esc_html( $row->client_url ); ?></strong></td>
							<td><code><?php echo esc_html( $row->token ); ?></code></td>
							<td><span style="color: #2563eb; font-weight: 600;"><?php echo esc_html( "{$m['free_remaining']} / {$m['free_monthly_total']}" ); ?></span></td>
							<td><?php echo esc_html( "{$m['purchased_remaining']} / {$m['purchased_total']}" ); ?></td>
							<td><strong style="font-size: 1.1em; color: #059669;"><?php echo esc_html( $m['total_available'] ); ?></strong></td>
							<td>
								<span class="badge" style="padding: 3px 8px; border-radius: 3px; color: #fff; background-color: <?php echo $row->status === 'active' ? '#46b450' : '#dc3232'; ?>">
									<?php echo esc_html( strtoupper( $row->status ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $row->created_at ); ?></td>
							<td>
								<?php if ( $row->status === 'active' ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=upr-server-dashboard&action=revoke&token_id=' . $row->id ) ); ?>" class="button button-secondary" style="color: #dc3232;">Revoke</a>
								<?php else : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=upr-server-dashboard&action=activate&token_id=' . $row->id ) ); ?>" class="button button-secondary">Activate</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}

// Master Admin Credits Top-up Endpoint (for inventkid.com purchase webhook)
function upr_server_handle_add_credits( WP_REST_Request $request ) {
	$master_key = $request->get_header( 'X-UPR-Master-Key' );
	$expected_key = get_option( 'upr_server_master_key' );
	if ( empty( $expected_key ) ) {
		$expected_key = bin2hex( random_bytes( 32 ) );
		update_option( 'upr_server_master_key', $expected_key );
	}

	if ( empty( $master_key ) || $master_key !== $expected_key ) {
		return new WP_Error( 'upr_unauthorized_master', 'Invalid master key.', array( 'status' => 401 ) );
	}

	$token = sanitize_text_field( $request->get_param( 'token' ) );
	$amount = intval( $request->get_param( 'amount' ) );

	if ( empty( $token ) || $amount <= 0 ) {
		return new WP_Error( 'upr_bad_request', 'Invalid token key or credits amount.', array( 'status' => 400 ) );
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'upr_server_tokens';
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE token = %s", $token ) );

	if ( ! $row ) {
		return new WP_Error( 'upr_not_found', 'Token not found.', array( 'status' => 404 ) );
	}

	$new_total = intval( $row->credits_total ) + $amount;
	$wpdb->update( $table_name, array( 'credits_total' => $new_total ), array( 'token' => $token ) );

	$sync = upr_server_sync_token_credits( $row );

	return rest_ensure_response( array(
		'status'              => 'success',
		'client_url'          => $row->client_url,
		'purchased_total'     => $new_total,
		'total_available'     => $sync['total_available'] + $amount
	) );
}

// Single Isolated Component Transpilation Endpoint
function upr_server_handle_transpile_component( WP_REST_Request $request ) {
	$html = $request->get_param( 'html' );
	$css  = $request->get_param( 'css' );
	$format = sanitize_text_field( $request->get_param( 'format' ) ?? 'react-tailwind' );
	$title  = sanitize_text_field( $request->get_param( 'title' ) ?? 'Component' );

	if ( empty( $html ) ) {
		return new WP_Error( 'upr_bad_request', 'Missing HTML slice.', array( 'status' => 400 ) );
	}

	require_once UPR_SERVER_PATH . 'includes/transpiler-engine.php';

	$result = upr_server_transpile_component( $html, $css, $format, $title );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Consume 1 token credit (free monthly credit first)
	$token_row = $request->get_param( 'upr_token_row' );
	upr_server_consume_credit( $token_row, 1 );

	return rest_ensure_response( $result );
}

// Main URL-to-page handler
function upr_server_handle_replicate( WP_REST_Request $request ) {
	$url = esc_url_raw( $request->get_param( 'url' ) );
	$format = sanitize_text_field( $request->get_param( 'format' ) ?? 'raw' );

	if ( empty( $url ) ) {
		return new WP_Error( 'upr_bad_request', 'Missing target URL parameter.', array( 'status' => 400 ) );
	}

	$token_row = $request->get_param( 'upr_token_row' );
	
	// Expose and run the server compiler
	require_once UPR_SERVER_PATH . 'includes/replicator-engine-server.php';
	
	$package = upr_server_compile_page( $url );
	if ( is_wp_error( $package ) ) {
		return $package;
	}

	// If a modern framework was requested (react-tailwind, react-css, angular, html-clean), transpile before re-zipping
	if ( $format !== 'raw' && ! empty( $package['compilation_id'] ) ) {
		require_once UPR_SERVER_PATH . 'includes/transpiler-engine.php';
		$upload_dir = wp_upload_dir();
		$target_path = wp_normalize_path( $upload_dir['basedir'] . '/url-page-replicator-server/' . $package['compilation_id'] );
		
		$transpiled = upr_server_transpile_page( $target_path, $package['compilation_id'], $format, $package['title'] ?? 'Replicated Page', $url );
		if ( ! is_wp_error( $transpiled ) ) {
			// Re-create the ZIP package to include generated framework files
			$exports_dir = wp_normalize_path( $upload_dir['basedir'] . '/url-page-replicator-server/exports' );
			$zip_filepath = $exports_dir . '/' . $package['compilation_id'] . '.zip';
			uprs_zip_folder( $target_path, $zip_filepath );
		}
	}

	// Consume 1 token credit on success (using monthly free credit first, then purchased)
	upr_server_consume_credit( $token_row, 1 );

	return rest_ensure_response( $package );
}

// Figma prototype-to-page handler (Responsive compiling)
function upr_server_handle_replicate_figma( WP_REST_Request $request ) {
	$desktop_url = esc_url_raw( $request->get_param( 'desktop_url' ) );
	$tablet_url = esc_url_raw( $request->get_param( 'tablet_url' ) );
	$mobile_url = esc_url_raw( $request->get_param( 'mobile_url' ) );
	$client_figma_token = sanitize_text_field( $request->get_param( 'figma_token' ) );
	$format = sanitize_text_field( $request->get_param( 'format' ) ?? 'raw' );

	if ( empty( $desktop_url ) ) {
		return new WP_Error( 'upr_bad_request', 'Desktop Figma prototype URL is required.', array( 'status' => 400 ) );
	}

	$token_row = $request->get_param( 'upr_token_row' );

	require_once UPR_SERVER_PATH . 'includes/replicator-engine-server.php';

	$package = upr_server_compile_figma( $desktop_url, $tablet_url, $mobile_url, $client_figma_token );
	if ( is_wp_error( $package ) ) {
		return $package;
	}

	// If framework format requested, transpile and re-zip
	if ( $format !== 'raw' && ! empty( $package['compilation_id'] ) ) {
		require_once UPR_SERVER_PATH . 'includes/transpiler-engine.php';
		$upload_dir = wp_upload_dir();
		$target_path = wp_normalize_path( $upload_dir['basedir'] . '/url-page-replicator-server/' . $package['compilation_id'] );
		
		$transpiled = upr_server_transpile_page( $target_path, $package['compilation_id'], $format, $package['title'] ?? 'Figma Prototype', $desktop_url );
		if ( ! is_wp_error( $transpiled ) ) {
			$exports_dir = wp_normalize_path( $upload_dir['basedir'] . '/url-page-replicator-server/exports' );
			$zip_filepath = $exports_dir . '/' . $package['compilation_id'] . '.zip';
			uprs_zip_folder( $target_path, $zip_filepath );
		}
	}

	// Consume 1 token credit on success
	upr_server_consume_credit( $token_row, 1 );

	return rest_ensure_response( $package );
}
