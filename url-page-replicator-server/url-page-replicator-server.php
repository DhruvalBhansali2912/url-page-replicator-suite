<?php
/**
 * Plugin Name: URL Page Replicator Server
 * Description: SaaS Server API engine for headless rendering, proxy sandboxing, and package packaging.
 * Version: 1.0.0
 * Author: Antigravity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UPR_SERVER_PATH', plugin_dir_path( __FILE__ ) );
define( 'UPR_SERVER_URL', plugin_dir_url( __FILE__ ) );

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
		status varchar(20) DEFAULT 'active' NOT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY token (token)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

// Register REST API Route
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

// Credits Info Endpoint Handler
function upr_server_handle_credits_info( WP_REST_Request $request ) {
	$token_row = $request->get_param( 'upr_token_row' );
	return rest_ensure_response( array(
		'status'        => 'success',
		'client_url'    => $token_row->client_url,
		'credits_total' => intval( $token_row->credits_total ),
		'credits_used'  => intval( $token_row->credits_used ),
		'remaining'     => max( 0, intval( $token_row->credits_total ) - intval( $token_row->credits_used ) )
	) );
}

// Token Validation Permission Callback
function upr_server_validate_token( WP_REST_Request $request ) {
	$auth_header = $request->get_header( 'Authorization' );
	if ( empty( $auth_header ) || ! preg_match( '/Bearer\s+(.+)/i', $auth_header, $matches ) ) {
		return new WP_Error( 'upr_unauthorized', 'Missing or malformed Authorization header.', array( 'status' => 401 ) );
	}

	$token = sanitize_text_field( $matches[1] );
	global $wpdb;
	$table_name = $wpdb->prefix . 'upr_server_tokens';
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE token = %s AND status = 'active'", $token ) );

	if ( ! $row ) {
		return new WP_Error( 'upr_forbidden', 'Invalid or inactive API token.', array( 'status' => 403 ) );
	}

	// Validate requesting origin / URL matches the registered client_url
	$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
	if ( ! empty( $origin ) ) {
		$origin_host = parse_url( $origin, PHP_URL_HOST );
		$client_host = parse_url( $row->client_url, PHP_URL_HOST );
		if ( $origin_host && $client_host && strcasecmp( $origin_host, $client_host ) !== 0 && strpos( $origin_host, $client_host ) === false ) {
			return new WP_Error( 'upr_forbidden_origin', 'Requesting origin does not match the registered client URL.', array( 'status' => 403 ) );
		}
	}

	// Check if credits are available
	if ( intval( $row->credits_used ) >= intval( $row->credits_total ) ) {
		return new WP_Error( 'upr_payment_required', 'Token credits exhausted.', array( 'status' => 402 ) );
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
		$client_url = esc_url_raw( $_POST['client_url'] );
		$credits = intval( $_POST['credits_total'] );
		if ( ! empty( $client_url ) ) {
			$wpdb->insert( $table_name, array(
				'token'         => upr_server_generate_key(),
				'client_url'    => $client_url,
				'credits_total' => $credits,
				'credits_used'  => 0,
				'status'        => 'active',
				'created_at'    => current_time( 'mysql' )
			) );
			echo '<div class="notice notice-success is-dismissible"><p>API Token generated successfully!</p></div>';
		}
	}

	// Handle Figma API Token save
	if ( isset( $_POST['upr_save_figma_settings'] ) && check_admin_referer( 'upr_save_figma_settings_action', 'upr_save_figma_settings_nonce' ) ) {
		$figma_token = sanitize_text_field( $_POST['figma_token'] );
		update_option( 'upr_server_figma_token', $figma_token );
		echo '<div class="notice notice-success is-dismissible"><p>Figma settings saved successfully!</p></div>';
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
							<th><label for="client_url">Client Website URL</label></th>
							<td><input type="url" name="client_url" id="client_url" placeholder="https://client-site.com" class="regular-text" required style="width: 100%;" /></td>
						</tr>
						<tr>
							<th><label for="credits_total">Initial Credits</label></th>
							<td><input type="number" name="credits_total" id="credits_total" value="10" class="small-text" required min="0" /></td>
						</tr>
					</table>
					<p class="submit" style="margin-bottom: 0; padding-bottom: 0;"><input type="submit" name="upr_generate_token" class="button button-primary" value="Generate Token" /></p>
				</form>
			</div>

			<div class="card" style="flex: 1; min-width: 300px; margin-bottom: 20px; padding: 20px; box-sizing: border-box;">
				<h2>Global Figma settings</h2>
				<form method="post">
					<?php wp_nonce_field( 'upr_save_figma_settings_action', 'upr_save_figma_settings_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th><label for="figma_token">Figma Personal Access Token</label></th>
							<td>
								<input type="text" name="figma_token" id="figma_token" value="<?php echo esc_attr( $figma_token ); ?>" placeholder="figd_..." class="regular-text" style="width: 100%;" />
								<p class="description">Used to query Figma REST API for figma-to-page compilation.</p>
							</td>
						</tr>
					</table>
					<p class="submit" style="margin-bottom: 0; padding-bottom: 0;"><input type="submit" name="upr_save_figma_settings" class="button button-primary" value="Save Figma Token" /></p>
				</form>
			</div>
		</div>

		<h2>Active API Tokens</h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Client URL</th>
					<th>API Token Key</th>
					<th>Credits (Total / Used / Remaining)</th>
					<th>Status</th>
					<th>Created At</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6">No tokens found.</td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php 
						$remaining = intval( $row->credits_total ) - intval( $row->credits_used );
						$remaining = max( 0, $remaining );
						?>
						<tr>
							<td><strong><?php echo esc_html( $row->client_url ); ?></strong></td>
							<td><code><?php echo esc_html( $row->token ); ?></code></td>
							<td><?php echo esc_html( "{$row->credits_total} / {$row->credits_used} / {$remaining}" ); ?></td>
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

// Master Admin Credits Top-up Endpoint
function upr_server_handle_add_credits( WP_REST_Request $request ) {
	$master_key = $request->get_header( 'X-UPR-Master-Key' );
	$expected_key = get_option( 'upr_server_master_key' );
	if ( empty( $expected_key ) ) {
		// Auto-initialize master key if empty
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

	return rest_ensure_response( array(
		'status'        => 'success',
		'client_url'    => $row->client_url,
		'credits_total' => $new_total,
		'credits_used'  => $row->credits_used
	) );
}

// Main URL-to-page handler
function upr_server_handle_replicate( WP_REST_Request $request ) {
	$url = esc_url_raw( $request->get_param( 'url' ) );
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

	// Consume 1 token credit on success
	global $wpdb;
	$table_name = $wpdb->prefix . 'upr_server_tokens';
	$wpdb->update( $table_name, array( 'credits_used' => intval( $token_row->credits_used ) + 1 ), array( 'id' => $token_row->id ) );

	return rest_ensure_response( $package );
}

// Figma prototype-to-page handler (Responsive compiling)
function upr_server_handle_replicate_figma( WP_REST_Request $request ) {
	$desktop_url = esc_url_raw( $request->get_param( 'desktop_url' ) );
	$tablet_url = esc_url_raw( $request->get_param( 'tablet_url' ) );
	$mobile_url = esc_url_raw( $request->get_param( 'mobile_url' ) );
	$client_figma_token = sanitize_text_field( $request->get_param( 'figma_token' ) );

	if ( empty( $desktop_url ) ) {
		return new WP_Error( 'upr_bad_request', 'Desktop Figma prototype URL is required.', array( 'status' => 400 ) );
	}

	$token_row = $request->get_param( 'upr_token_row' );

	require_once UPR_SERVER_PATH . 'includes/replicator-engine-server.php';

	$package = upr_server_compile_figma( $desktop_url, $tablet_url, $mobile_url, $client_figma_token );
	if ( is_wp_error( $package ) ) {
		return $package;
	}

	// Consume 1 token credit on success
	global $wpdb;
	$table_name = $wpdb->prefix . 'upr_server_tokens';
	$wpdb->update( $table_name, array( 'credits_used' => intval( $token_row->credits_used ) + 1 ), array( 'id' => $token_row->id ) );

	return rest_ensure_response( $package );
}
