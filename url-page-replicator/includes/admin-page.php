<?php
/**
 * Unified Admin Page UI and Setup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}



/**
 * Plugin Name: URL Page Replicator Client
 * Description: Client-side API consumer that downloads, extracts, and serves replicated pages natively.
 * Version: 1.0.0
 * Author: Antigravity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'UPR_PATH' ) ) {
	define( 'UPR_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'UPR_URL' ) ) {
	define( 'UPR_URL', plugin_dir_url( __FILE__ ) );
}

// Intercept page loading to render replicated content
add_filter( 'template_include', 'upr_client_serve_page_template' );
if ( ! function_exists( 'upr_client_serve_page_template' ) ) {
function upr_client_serve_page_template( $template ) {
	if ( is_page() ) {
		$post_id = get_the_ID();
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

// Enqueue styles and scripts for client administration pages
add_action( 'admin_enqueue_scripts', 'upr_client_enqueue_assets' );
if ( ! function_exists( 'upr_client_enqueue_assets' ) ) {
function upr_client_enqueue_assets( $hook ) {
	if ( false === strpos( $hook, 'upr-client-dashboard' ) ) {
		return;
	}
	wp_enqueue_style( 'upr-client-admin-css', UPR_URL . 'assets/admin.css', array(), time() );
	
	wp_enqueue_script(
		'upr-client-admin-js',
		UPR_URL . 'assets/admin.js',
		array( 'jquery' ),
		time(),
		true
	);

	wp_localize_script(
		'upr-client-admin-js',
		'upr_client_ajax',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'upr_client_nonce' ),
			'gemini_api_key' => get_option( 'upr_client_gemini_api_key', '' )
		)
	);
}
}

// Add Admin Menu Page & Submenus
add_action( 'admin_menu', 'upr_client_add_menu' );
if ( ! function_exists( 'upr_client_add_menu' ) ) {
function upr_client_add_menu() {
	// Main Menu
	add_menu_page(
		'Page Replicator',
		'Page Replicator',
		'manage_options',
		'upr-client-dashboard',
		'upr_client_render_url_tab',
		'dashicons-copy',
		80
	);

	// Submenu 1: URL Replicator
	add_submenu_page(
		'upr-client-dashboard',
		'URL Replicator',
		'URL Replicator',
		'manage_options',
		'upr-client-dashboard',
		'upr_client_render_url_tab'
	);

	// Submenu 2: Figma Compiler
	add_submenu_page(
		'upr-client-dashboard',
		'Figma Compiler',
		'Figma Compiler',
		'manage_options',
		'upr-client-dashboard-figma',
		'upr_client_render_figma_tab'
	);

	// Submenu 3: Section Compiler
	add_submenu_page(
		'upr-client-dashboard',
		'Section Compiler',
		'Section Compiler',
		'manage_options',
		'upr-client-dashboard-sections',
		'upr_client_render_sections_tab'
	);

	// Submenu 4: Settings
	add_submenu_page(
		'upr-client-dashboard',
		'Settings',
		'Settings',
		'manage_options',
		'upr-client-dashboard-settings',
		'upr_client_render_settings_tab'
	);
}
}

// Helper to query and fetch all replicated pages on client site
if ( ! function_exists( 'upr_client_get_replicated_pages' ) ) {
function upr_client_get_replicated_pages() {
	return get_posts( array(
		'post_type'      => 'page',
		'posts_per_page' => 50,
		'post_status'    => 'publish',
		'meta_query'     => array(
			array(
				'key'     => '_upr_is_replicated',
				'compare' => 'EXISTS',
			),
		),
	) );
}
}

// Shared Header Render function
if ( ! function_exists( 'upr_client_render_header' ) ) {
function upr_client_render_header() {
	?>
	<header class="upr-header">
		<div class="upr-logo">
			<span class="dashicons dashicons-admin-page"></span>
			<h1><?php esc_html_e( 'URL Page Replicator Client', 'url-page-replicator-client' ); ?></h1>
		</div>
		<p class="upr-tagline"><?php esc_html_e( 'Compile external pages or Figma prototypes on your SaaS server and download them directly to run locally.', 'url-page-replicator-client' ); ?></p>
	</header>
	<?php
}
}

// Shared Tab Navigation Render function
if ( ! function_exists( 'upr_client_render_tabs' ) ) {
function upr_client_render_tabs( $active ) {
	?>
	<div class="upr-nav-tab-wrapper">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=upr-client-dashboard' ) ); ?>" class="upr-nav-tab <?php echo 'url' === $active ? 'active' : ''; ?>">URL Replicator</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=upr-client-dashboard-figma' ) ); ?>" class="upr-nav-tab <?php echo 'figma' === $active ? 'active' : ''; ?>">Figma Compiler</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=upr-client-dashboard-sections' ) ); ?>" class="upr-nav-tab <?php echo 'sections' === $active ? 'active' : ''; ?>">Section Compiler</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=upr-client-dashboard-settings' ) ); ?>" class="upr-nav-tab <?php echo 'settings' === $active ? 'active' : ''; ?>">Settings</a>
	</div>
	<?php
}
}

// Shared Replicated Pages Table Render function
if ( ! function_exists( 'upr_client_render_pages_table' ) ) {
function upr_client_render_pages_table() {
	$replicas = upr_client_get_replicated_pages();
	?>
	<section class="upr-card upr-list-card">
		<h2><?php esc_html_e( 'Your Replicated Pages', 'url-page-replicator-client' ); ?></h2>
		<div class="upr-table-wrapper">
			<?php if ( ! empty( $replicas ) ) : ?>
				<table class="upr-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title / Slug', 'url-page-replicator-client' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'url-page-replicator-client' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $replicas as $replica ) : 
							$replica_url = get_permalink( $replica->ID );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $replica->post_title ); ?></strong>
									<div class="upr-row-meta">
										<span class="upr-meta-label"><?php esc_html_e( 'Slug:', 'url-page-replicator-client' ); ?></span>
										<code>/<?php echo esc_html( $replica->post_name ); ?>/</code>
									</div>
								</td>
								<td>
									<div class="upr-row-actions">
										<a href="<?php echo esc_url( $replica_url ); ?>" target="_blank" class="upr-action-btn upr-view-btn" title="<?php esc_attr_e( 'View Page', 'url-page-replicator' ); ?>">
											<span class="dashicons dashicons-visibility"></span>
										</a>
										<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=upr_export_page&id=' . $replica->ID . '&nonce=' . wp_create_nonce( 'upr_client_nonce' ) ) ); ?>" class="upr-action-btn upr-export-btn" title="<?php esc_attr_e( 'Export Page', 'url-page-replicator' ); ?>">
											<span class="dashicons dashicons-download" style="transform: rotate(180deg); margin-top: 5px;"></span>
										</a>
										<button type="button" class="upr-action-btn upr-delete-btn upr-client-delete-btn" data-id="<?php echo esc_attr( $replica->ID ); ?>" title="<?php esc_attr_e( 'Delete Page', 'url-page-replicator-client' ); ?>">
											<span class="dashicons dashicons-trash"></span>
										</button>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<div class="upr-no-items" style="text-align: center; padding: 40px 20px;">
					<span class="dashicons dashicons-info" style="font-size: 48px; width: 48px; height: 48px; color: #cbd5e1;"></span>
					<p><?php esc_html_e( 'No pages replicated yet.', 'url-page-replicator-client' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
	</section>
	<?php
}
}

// Tab 1: Render URL Replicator UI
if ( ! function_exists( 'upr_client_render_url_tab' ) ) {
function upr_client_render_url_tab() {
	$server_url = get_option( 'upr_client_server_url', '' );
	$token      = get_option( 'upr_client_token', '' );
	?>
	<div class="wrap upr-admin-wrap">
		<?php upr_client_render_header(); ?>
		<?php upr_client_render_tabs( 'url' ); ?>

		<div class="upr-grid">
			<!-- Configuration Section -->
			<section class="upr-card upr-config-card">
				<h2><?php esc_html_e( 'Replicate New Page', 'url-page-replicator-client' ); ?></h2>
				<?php if ( empty( $server_url ) || empty( $token ) ) : ?>
					<div class="upr-feedback-box error">Please configure your Server URL and API Token in Settings first.</div>
				<?php else : ?>
					<form id="upr-replicator-form">
						<div class="upr-form-group">
							<label for="target_url"><?php esc_html_e( 'Target Webpage URL', 'url-page-replicator-client' ); ?></label>
							<input type="url" name="target_url" id="target_url" placeholder="https://www.relayhumancloud.com/" required />
							<p class="upr-desc"><?php esc_html_e( 'Enter the full URL of the page you want to replicate (including http/https).', 'url-page-replicator-client' ); ?></p>
						</div>
						<button type="submit" class="upr-btn-submit">
							<span class="btn-text"><?php esc_html_e( 'Replicate Webpage', 'url-page-replicator-client' ); ?></span>
						</button>
					</form>
					
					<div id="upr-feedback-url" class="upr-feedback-box hidden"></div>
					
					<!-- Progress Bar Container -->
					<div id="upr-progress-container-url" class="upr-progress-card hidden" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-top: 20px;">
						<div class="upr-progress-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
							<span id="upr-progress-label-url" class="upr-progress-label" style="font-weight: 600; font-size: 14px; color: #1e293b;">Replicating Webpage...</span>
							<span id="upr-progress-percent-url" class="upr-progress-percent" style="font-weight: 700; font-size: 14px; color: #4f46e5;">0%</span>
						</div>
						<div class="upr-progress-bar-bg" style="background: #e2e8f0; height: 10px; border-radius: 5px; overflow: hidden; margin-bottom: 10px;">
							<div id="upr-progress-bar-fill-url" class="upr-progress-bar-fill" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); height: 100%; border-radius: 5px; width: 0%; transition: width 0.3s ease;"></div>
						</div>
						<div id="upr-progress-status-url" class="upr-progress-status" style="font-size: 12px; color: #64748b;">Preparing task...</div>
					</div>

					<hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 25px 0;" />
					
					<h2><?php esc_html_e( 'Import Replicated Page', 'url-page-replicator' ); ?></h2>
					<form id="upr-import-form" enctype="multipart/form-data">
						<div class="upr-form-group">
							<label for="import_file"><?php esc_html_e( 'Select Exported ZIP Package', 'url-page-replicator' ); ?></label>
							<input type="file" name="import_file" id="import_file" accept=".zip" required style="border: 1px dashed #cbd5e1; padding: 15px; border-radius: 6px; width: 100%; box-sizing: border-box; background: #faf5ff; cursor: pointer;" />
							<p class="upr-desc"><?php esc_html_e( 'Upload a previously exported .zip package to import the page and its assets.', 'url-page-replicator' ); ?></p>
						</div>
						<button type="submit" class="upr-btn-submit" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
							<span class="btn-text"><?php esc_html_e( 'Import Page Package', 'url-page-replicator' ); ?></span>
						</button>
					</form>
					
					<div id="upr-feedback-import" class="upr-feedback-box hidden" style="margin-top: 15px;"></div>
				<?php endif; ?>
			</section>

			<!-- Pages List Section -->
			<?php upr_client_render_pages_table(); ?>
		</div>
	</div>
	<?php
}
}

// Tab 2: Render Figma Compiler UI
if ( ! function_exists( 'upr_client_render_figma_tab' ) ) {
function upr_client_render_figma_tab() {
	$server_url = get_option( 'upr_client_server_url', '' );
	$token      = get_option( 'upr_client_token', '' );
	?>
	<div class="wrap upr-admin-wrap">
		<?php upr_client_render_header(); ?>
		<?php upr_client_render_tabs( 'figma' ); ?>

		<div class="upr-grid">
			<!-- Configuration Section -->
			<section class="upr-card upr-config-card">
				<h2>
					<?php esc_html_e( 'Compile Figma Prototype', 'url-page-replicator-client' ); ?>
					<a href="https://www.figma.com/settings" target="_blank" class="upr-header-link" style="font-size: 12px; font-weight: 500; margin-left: 10px; color: #4f46e5; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
						<span class="dashicons dashicons-external" style="font-size: 14px; width: 14px; height: 14px; display: inline-block; line-height: 14px;"></span>
						<?php esc_html_e( 'Get Figma Token', 'url-page-replicator-client' ); ?>
					</a>
				</h2>
				<?php if ( empty( $server_url ) || empty( $token ) ) : ?>
					<div class="upr-feedback-box error">Please configure your Server URL and API Token in Settings first.</div>
				<?php else : ?>
					<form id="upr-figma-form">
						<div class="upr-form-group">
							<label for="desktop_url"><?php esc_html_e( 'Desktop Frame URL', 'url-page-replicator-client' ); ?></label>
							<input type="url" name="desktop_url" id="desktop_url" placeholder="https://www.figma.com/proto/..." required />
							<p class="upr-desc"><?php esc_html_e( 'Primary desktop breakpoint mockup prototype URL.', 'url-page-replicator-client' ); ?></p>
						</div>
						<div class="upr-form-group">
							<label for="tablet_url"><?php esc_html_e( 'Tablet Frame URL (Optional)', 'url-page-replicator-client' ); ?></label>
							<input type="url" name="tablet_url" id="tablet_url" placeholder="https://www.figma.com/proto/..." />
						</div>
						<div class="upr-form-group">
							<label for="mobile_url"><?php esc_html_e( 'Mobile Frame URL (Optional)', 'url-page-replicator-client' ); ?></label>
							<input type="url" name="mobile_url" id="mobile_url" placeholder="https://www.figma.com/proto/..." />
						</div>
						<button type="submit" class="upr-btn-submit">
							<span class="btn-text"><?php esc_html_e( 'Compile Figma Prototypes', 'url-page-replicator-client' ); ?></span>
						</button>
					</form>
					
					<div id="upr-feedback-figma" class="upr-feedback-box hidden"></div>

					<!-- Progress Bar Container -->
					<div id="upr-progress-container-figma" class="upr-progress-card hidden" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-top: 20px;">
						<div class="upr-progress-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
							<span id="upr-progress-label-figma" class="upr-progress-label" style="font-weight: 600; font-size: 14px; color: #1e293b;">Compiling Figma Nodes...</span>
							<span id="upr-progress-percent-figma" class="upr-progress-percent" style="font-weight: 700; font-size: 14px; color: #4f46e5;">0%</span>
						</div>
						<div class="upr-progress-bar-bg" style="background: #e2e8f0; height: 10px; border-radius: 5px; overflow: hidden; margin-bottom: 10px;">
							<div id="upr-progress-bar-fill-figma" class="upr-progress-bar-fill" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); height: 100%; border-radius: 5px; width: 0%; transition: width 0.3s ease;"></div>
						</div>
						<div id="upr-progress-status-figma" class="upr-progress-status" style="font-size: 12px; color: #64748b;">Initializing compiler...</div>
					</div>
				<?php endif; ?>
			</section>

			<!-- Pages List Section -->
			<?php upr_client_render_pages_table(); ?>
		</div>
	</div>
	<?php
}
}

// Tab 3: Render Section Compiler UI
if ( ! function_exists( 'upr_client_render_sections_tab' ) ) {
function upr_client_render_sections_tab() {
	$python_compiler_url = get_option( 'upr_client_python_compiler_url', 'http://localhost:5000/api/compile' );
	?>
	<div class="wrap upr-admin-wrap">
		<?php upr_client_render_header(); ?>
		<?php upr_client_render_tabs( 'sections' ); ?>

		<div class="upr-grid" style="grid-template-columns: 1fr;">
			<section class="upr-card">
				<h2><?php esc_html_e( 'Smart Section Compiler', 'url-page-replicator-client' ); ?></h2>
				<form id="upr-sections-compiler-form" enctype="multipart/form-data">
					<div class="upr-form-group" style="margin-bottom: 20px;">
						<label for="page_title"><strong><?php esc_html_e( 'Target Page Title', 'url-page-replicator-client' ); ?></strong></label>
						<input type="text" name="page_title" id="page_title" placeholder="My Figma Replicated Page" required style="max-width: 500px; width: 100%; height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0 12px; box-sizing: border-box;" />
					</div>

					<div class="upr-form-group" style="margin-bottom: 20px;">
						<label for="python_url"><strong><?php esc_html_e( 'Python Compiler API URL', 'url-page-replicator-client' ); ?></strong></label>
						<input type="url" name="python_url" id="python_url" value="<?php echo esc_url( $python_compiler_url ); ?>" required style="max-width: 500px; width: 100%; height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0 12px; box-sizing: border-box;" />
						<p class="upr-desc" style="font-size: 12px; color: #64748b; margin-top: 4px;">Endpoints should point to your running Python smart section compiler service.</p>
					</div>

					<div class="upr-form-group" style="margin-bottom: 25px; display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
						<div>
							<label for="main_image"><strong><?php esc_html_e( 'Main Page Design Image (PNG/JPG)', 'url-page-replicator-client' ); ?></strong></label>
							<input type="file" name="main_image" id="main_image" accept="image/*" />
							<div id="main-image-preview" style="font-size: 11px; color: #4f46e5; margin-top: 4px; display: none;"></div>
						</div>
						<div>
							<label for="main_pdf"><strong><?php esc_html_e( 'Main Page Design PDF', 'url-page-replicator-client' ); ?></strong></label>
							<input type="file" name="main_pdf" id="main_pdf" accept="application/pdf" />
							<div id="main-pdf-preview" style="font-size: 11px; color: #4f46e5; margin-top: 4px; display: none;"></div>
						</div>
					</div>

					<hr style="border: 0; border-top: 1px solid #cbd5e1; margin: 30px 0;" />

					<h3 style="margin-bottom: 15px; font-size: 18px; color: #1e293b;">Design Sections Dataset</h3>
					<div id="upr-sections-list-container" style="display: flex; flex-direction: column; gap: 20px; margin-bottom: 25px;">
						<!-- Dynamic section cards will be appended here via JS -->
					</div>

					<div style="margin-bottom: 30px; display: flex; align-items: center; gap: 10px;">
						<button type="button" id="upr-add-section-btn" class="button button-secondary" style="height: 38px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
							<span class="dashicons dashicons-plus-alt" style="margin-top: 1px;"></span>
							<?php esc_html_e( 'Add Section Card', 'url-page-replicator-client' ); ?>
						</button>
						<button type="button" id="upr-save-draft-btn" class="button button-secondary" style="height: 38px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
							<span class="dashicons dashicons-disk" style="margin-top: 1px;"></span>
							<?php esc_html_e( 'Save Draft', 'url-page-replicator-client' ); ?>
						</button>
						<button type="button" id="upr-load-draft-btn" class="button button-secondary" style="height: 38px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
							<span class="dashicons dashicons-download" style="margin-top: 1px;"></span>
							<?php esc_html_e( 'Load Draft', 'url-page-replicator-client' ); ?>
						</button>
						<span id="upr-draft-status" style="font-size: 13px; color: #10b981; font-weight: 600; display: none;">Draft saved successfully!</span>
					</div>

					<button type="submit" class="button button-primary" style="height: 44px; padding: 0 24px; font-size: 14px; font-weight: 700; cursor: pointer;">
						<?php esc_html_e( 'Compile Section Layouts & Generate Page', 'url-page-replicator-client' ); ?>
					</button>
				</form>

				<div id="upr-feedback-sections" class="upr-feedback-box hidden" style="margin-top: 20px;"></div>

				<!-- Progress Bar -->
				<div id="upr-progress-container-sections" class="upr-progress-card hidden" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-top: 20px;">
					<div class="upr-progress-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
						<span id="upr-progress-label-sections" class="upr-progress-label" style="font-weight: 600; font-size: 14px; color: #1e293b;">Compiling sections...</span>
						<span id="upr-progress-percent-sections" class="upr-progress-percent" style="font-weight: 700; font-size: 14px; color: #4f46e5;">0%</span>
					</div>
					<div class="upr-progress-bar-bg" style="background: #e2e8f0; height: 10px; border-radius: 5px; overflow: hidden;">
						<div id="upr-progress-bar-fill-sections" class="upr-progress-bar-fill" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); height: 100%; border-radius: 5px; width: 0%; transition: width 0.3s ease;"></div>
					</div>
					<div id="upr-progress-status-sections" class="upr-progress-status" style="font-size: 12px; color: #64748b; margin-top: 8px;">Processing datasets...</div>
				</div>
			</section>

			<!-- Drafts Archive Card -->
			<section class="upr-card" style="margin-top: 30px;">
				<h3 style="margin-top: 0; font-size: 18px; color: #1e293b;"><?php esc_html_e( 'Saved Drafts Archive', 'url-page-replicator-client' ); ?></h3>
				<p class="upr-desc" style="margin-bottom: 20px;"><?php esc_html_e( 'Drafts are saved permanently to your WordPress database. You can save multiple drafts keyed by Figma Page Title.', 'url-page-replicator-client' ); ?></p>
				<div id="upr-drafts-archive-table-container">
					<?php upr_client_render_drafts_archive_table(); ?>
				</div>
			</section>

			<?php upr_client_render_pages_table(); ?>
		</div>
	</div>
	<?php
}
}

// Render Saved Drafts Table Markup
if ( ! function_exists( 'upr_client_render_drafts_archive_table' ) ) {
function upr_client_render_drafts_archive_table() {
	$drafts = get_option( 'upr_sections_drafts_archive', array() );
	if ( is_string( $drafts ) ) {
		$drafts = json_decode( $drafts, true );
	}
	if ( ! is_array( $drafts ) ) {
		$drafts = array();
	}

	if ( ! empty( $drafts ) ) : ?>
		<table class="upr-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Figma Page Title', 'url-page-replicator-client' ); ?></th>
					<th><?php esc_html_e( 'Last Saved At', 'url-page-replicator-client' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'url-page-replicator-client' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $drafts as $title => $meta ) : 
					$saved_at = isset( $meta['saved_at'] ) ? $meta['saved_at'] : 'Unknown';
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $title ); ?></strong>
						</td>
						<td>
							<code><?php echo esc_html( $saved_at ); ?></code>
						</td>
						<td>
							<div class="upr-row-actions">
								<button type="button" class="upr-action-btn upr-view-btn upr-client-load-draft-trigger" data-title="<?php echo esc_attr( $title ); ?>" title="Load Draft to Form" style="cursor: pointer;">
									<span class="dashicons dashicons-download"></span>
								</button>
								<button type="button" class="upr-action-btn upr-delete-btn upr-client-delete-draft-trigger" data-title="<?php echo esc_attr( $title ); ?>" title="Delete Draft" style="cursor: pointer;">
									<span class="dashicons dashicons-trash"></span>
								</button>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<div class="upr-no-items" style="text-align: center; padding: 30px 20px; border: 1px dashed #cbd5e1; border-radius: 8px;">
			<span class="dashicons dashicons-portfolio" style="font-size: 36px; width: 36px; height: 36px; color: #94a3b8; margin-bottom: 10px;"></span>
			<p style="margin: 0; color: #64748b; font-size: 13px;"><?php esc_html_e( 'No saved drafts found in database.', 'url-page-replicator-client' ); ?></p>
		</div>
	<?php endif;
}
}

// Tab 3: Render Settings UI (with Remaining Tokens/Credits Info)
if ( ! function_exists( 'upr_client_render_settings_tab' ) ) {
function upr_client_render_settings_tab() {
	$server_url  = get_option( 'upr_client_server_url', '' );
	$token       = get_option( 'upr_client_token', '' );
	$figma_token = get_option( 'upr_client_figma_token', '' );
	$gemini_api_key = get_option( 'upr_client_gemini_api_key', '' );
	$python_compiler_url = get_option( 'upr_client_python_compiler_url', 'http://localhost:5000/api/compile' );

	// Handle settings update
	if ( isset( $_POST['upr_save_settings'] ) && check_admin_referer( 'upr_save_settings_action', 'upr_save_settings_nonce' ) ) {
		$server_url  = esc_url_raw( rtrim( $_POST['server_url'], '/' ) );
		$token       = sanitize_text_field( $_POST['token'] );
		$figma_token = sanitize_text_field( $_POST['figma_token'] );
		$gemini_api_key = sanitize_text_field( $_POST['gemini_api_key'] );
		$python_compiler_url = esc_url_raw( $_POST['python_compiler_url'] );
		update_option( 'upr_client_server_url', $server_url );
		update_option( 'upr_client_token', $token );
		update_option( 'upr_client_figma_token', $figma_token );
		update_option( 'upr_client_gemini_api_key', $gemini_api_key );
		update_option( 'upr_client_python_compiler_url', $python_compiler_url );
		echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
	}

	// Fetch credits balance from Server
	$credits_summary = '';
	if ( ! empty( $server_url ) && ! empty( $token ) ) {
		$res = wp_remote_get( $server_url . '/upr-server/v1/credits/info', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			'timeout' => 15,
		) );

		if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
			$info = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( isset( $info['remaining'] ) ) {
				$credits_summary = '
				<div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 16px; margin-top: 16px;">
					<h4 style="margin: 0 0 10px 0; color: #1e293b;">API Key Credits Info</h4>
					<p style="margin: 4px 0; font-size: 13px;"><strong>Total Purchased Credits:</strong> ' . intval( $info['credits_total'] ) . '</p>
					<p style="margin: 4px 0; font-size: 13px;"><strong>Total Consumed Credits:</strong> ' . intval( $info['credits_used'] ) . '</p>
					<p style="margin: 4px 0; font-size: 14px; color: #4f46e5; font-weight: bold;"><strong>Credits Remaining:</strong> ' . intval( $info['remaining'] ) . '</p>
				</div>';
			}
		} else {
			$credits_summary = '<div style="color: #dc3232; margin-top: 10px;">Unable to fetch credits info. Please verify your Server URL and API Token.</div>';
		}
	}
	?>
	<div class="wrap upr-admin-wrap">
		<?php upr_client_render_header(); ?>
		<?php upr_client_render_tabs( 'settings' ); ?>

		<div class="upr-grid" style="grid-template-columns: 1fr;">
			<section class="upr-card">
				<h2><?php esc_html_e( 'API Connection Settings', 'url-page-replicator-client' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'upr_save_settings_action', 'upr_save_settings_nonce' ); ?>
					<div class="upr-form-group">
						<label for="server_url"><?php esc_html_e( 'API Server URL', 'url-page-replicator-client' ); ?></label>
						<input type="url" name="server_url" id="server_url" value="<?php echo esc_url( $server_url ); ?>" placeholder="http://localhost/test/wp-json" required style="max-width: 500px;" />
						<p class="upr-desc"><?php esc_html_e( 'The base endpoint URL of your dedicated server.', 'url-page-replicator-client' ); ?></p>
					</div>
					<div class="upr-form-group">
						<label for="token"><?php esc_html_e( 'API Key Token', 'url-page-replicator-client' ); ?></label>
						<input type="text" name="token" id="token" value="<?php echo esc_attr( $token ); ?>" placeholder="Bearer Token Key" required style="max-width: 500px;" />
						<p class="upr-desc"><?php esc_html_e( 'Registered SaaS authentication token key.', 'url-page-replicator-client' ); ?></p>
					</div>
					<div class="upr-form-group">
						<label for="figma_token"><?php esc_html_e( 'Figma Personal Access Token (Optional)', 'url-page-replicator-client' ); ?></label>
						<input type="text" name="figma_token" id="figma_token" value="<?php echo esc_attr( $figma_token ); ?>" placeholder="figd_..." style="max-width: 500px;" />
						<p class="upr-desc">
							<?php esc_html_e( 'Provide your own Figma Personal Access Token to avoid rate limits when compiling prototypes. You can create a token under Figma Settings -> Account -> Personal Access Tokens.', 'url-page-replicator-client' ); ?>
							<a href="https://www.figma.com/settings" target="_blank" style="color: #4f46e5; text-decoration: none; font-weight: 500;"><?php esc_html_e( 'Get Figma Token', 'url-page-replicator-client' ); ?></a>
						</p>
					</div>
					<div class="upr-form-group">
						<label for="gemini_api_key"><?php esc_html_e( 'Gemini API Key (Optional)', 'url-page-replicator-client' ); ?></label>
						<input type="text" name="gemini_api_key" id="gemini_api_key" value="<?php echo esc_attr( $gemini_api_key ); ?>" placeholder="AIzaSy..." style="max-width: 500px;" />
						<p class="upr-desc"><?php esc_html_e( 'Used by the smart section compiler to call the Gemini Vision API and auto-generate responsive HTML/CSS layouts directly from screenshots.', 'url-page-replicator-client' ); ?> <a href="https://aistudio.google.com/" target="_blank" style="color: #4f46e5; text-decoration: none; font-weight: 500;"><?php esc_html_e( 'Get Gemini API Key', 'url-page-replicator-client' ); ?></a></p>
					</div>
					<div class="upr-form-group">
						<label for="python_compiler_url"><?php esc_html_e( 'Python Compiler API URL', 'url-page-replicator-client' ); ?></label>
						<input type="url" name="python_compiler_url" id="python_compiler_url" value="<?php echo esc_url( $python_compiler_url ); ?>" placeholder="http://localhost:5000/api/compile" required style="max-width: 500px;" />
						<p class="upr-desc"><?php esc_html_e( 'Endpoint path of your smart local Python compiler service.', 'url-page-replicator-client' ); ?></p>
					</div>
					<button type="submit" name="upr_save_settings" class="button button-primary" style="height: 38px; font-weight: 600; cursor: pointer;">
						<?php esc_html_e( 'Save Settings', 'url-page-replicator-client' ); ?>
					</button>
				</form>
				
				<?php echo $credits_summary; ?>
			</section>
		</div>
	</div>
	<?php
}
}

// REST call for standard URL replication (300 seconds timeout)
if ( ! function_exists( 'upr_client_api_replicate' ) ) {
function upr_client_api_replicate( $server_url, $token, $url ) {
	$res = wp_remote_post( $server_url . '/upr-server/v1/replicate', array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
		'body'    => json_encode( array( 'url' => $url ) ),
		'timeout' => 1800,
	) );

	if ( is_wp_error( $res ) ) {
		return $res;
	}

	$code = wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );

	if ( 200 !== $code ) {
		$err_msg = isset( $body['message'] ) ? $body['message'] : 'Replication API Server failed.';
		return new WP_Error( 'upr_api_fail', $err_msg );
	}

	return upr_client_install_package( $body );
}
}

// REST call for Figma responsive replication (300 seconds timeout)
if ( ! function_exists( 'upr_client_api_replicate_figma' ) ) {
function upr_client_api_replicate_figma( $server_url, $token, $desktop_url, $tablet_url, $mobile_url ) {
	$client_figma_token = get_option( 'upr_client_figma_token', '' );
	$res = wp_remote_post( $server_url . '/upr-server/v1/replicate-figma', array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
		'body'    => json_encode( array(
			'desktop_url' => $desktop_url,
			'tablet_url'  => $tablet_url,
			'mobile_url'  => $mobile_url,
			'figma_token' => $client_figma_token
		) ),
		'timeout' => 1800,
	) );

	if ( is_wp_error( $res ) ) {
		return $res;
	}

	$code = wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );

	if ( 200 !== $code ) {
		$err_msg = isset( $body['message'] ) ? $body['message'] : 'Figma API Server compilation failed.';
		$debug = isset( $body['data'] ) ? $body['data'] : array();
		return new WP_Error( 'upr_api_fail', $err_msg, $debug );
	}

	return upr_client_install_package( $body );
}
}

// Download, extract package ZIP and register Page locally on Client WP
if ( ! function_exists( 'upr_client_install_package' ) ) {
function upr_client_install_package( $payload ) {
	if ( empty( $payload['download_url'] ) || empty( $payload['slug'] ) || empty( $payload['title'] ) ) {
		return new WP_Error( 'upr_invalid_payload', 'Invalid API response payload.' );
	}

	$download_url = $payload['download_url'];
	$slug         = $payload['slug'];
	$title        = $payload['title'];

	// Download ZIP using core helper
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$temp_file = download_url( $download_url );
	if ( is_wp_error( $temp_file ) ) {
		return $temp_file;
	}

	// Local extraction directory inside Client Uploads folder
	$upload_dir = wp_upload_dir();
	$local_path = wp_normalize_path( $upload_dir['basedir'] . '/url-page-replicator/pages/' . $slug );

	if ( is_dir( $local_path ) ) {
		upr_client_rrmdir( $local_path );
	}
	mkdir( $local_path, 0755, true );

	// Unzip file
	WP_Filesystem();
	$unzipped = unzip_file( $temp_file, $local_path );
	unlink( $temp_file );

	if ( is_wp_error( $unzipped ) ) {
		return $unzipped;
	}

	// Insert or update local Page
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	$post_args = array(
		'post_title'   => sanitize_text_field( $title ),
		'post_name'    => $slug,
		'post_content' => 'Replicated page content served locally.',
		'post_status'  => 'publish',
		'post_type'    => 'page'
	);

	if ( $page ) {
		$post_args['ID'] = $page->ID;
		$post_id = wp_update_post( $post_args );
	} else {
		$post_id = wp_insert_post( $post_args );
	}

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	// Save references in postmeta
	update_post_meta( $post_id, '_upr_is_replicated', 1 );
	update_post_meta( $post_id, '_upr_local_path', $local_path );

	// Flush permalinks
	flush_rewrite_rules();

	return $post_id;
}
}

// Clean up helper
if ( ! function_exists( 'upr_client_rrmdir' ) ) {
function upr_client_rrmdir( $dir ) {
	if ( is_dir( $dir ) ) {
		$objects = scandir( $dir );
		foreach ( $objects as $object ) {
			if ( $object != "." && $object != ".." ) {
				if ( is_dir( $dir . DIRECTORY_SEPARATOR . $object ) && ! is_link( $dir . "/" . $object ) ) {
					upr_client_rrmdir( $dir . DIRECTORY_SEPARATOR . $object );
				} else {
					unlink( $dir . DIRECTORY_SEPARATOR . $object );
				}
			}
		}
		rmdir( $dir );
	}
}
}

// Register AJAX Action handlers
add_action( 'wp_ajax_upr_client_run_url_replication', 'upr_client_ajax_run_url_replication' );
add_action( 'wp_ajax_upr_client_run_figma_replication', 'upr_client_ajax_run_figma_replication' );
add_action( 'wp_ajax_upr_client_delete_page', 'upr_client_ajax_delete_page' );
add_action( 'wp_ajax_upr_client_get_replicas_table_html', 'upr_client_ajax_get_replicas_table_html' );
add_action( 'wp_ajax_upr_client_save_compiled_page', 'upr_client_ajax_save_compiled_page' );
add_action( 'wp_ajax_upr_client_save_draft_option', 'upr_client_ajax_save_draft_option' );
add_action( 'wp_ajax_upr_client_load_draft_option', 'upr_client_ajax_load_draft_option' );
add_action( 'wp_ajax_upr_client_delete_draft_option', 'upr_client_ajax_delete_draft_option' );

if ( ! function_exists( 'upr_client_ajax_save_draft_option' ) ) {
function upr_client_ajax_save_draft_option() {
	check_ajax_referer( 'upr_client_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}
	
	$title = sanitize_text_field( $_POST['title'] );
	$draft_data = stripslashes( $_POST['draft_data'] );
	
	if ( empty( $title ) || empty( $draft_data ) ) {
		wp_send_json_error( array( 'message' => 'Page title or draft data missing.' ) );
	}

	$drafts = get_option( 'upr_sections_drafts_archive', array() );
	if ( is_string( $drafts ) ) {
		$drafts = json_decode( $drafts, true );
	}
	if ( ! is_array( $drafts ) ) {
		$drafts = array();
	}

	$drafts[$title] = array(
		'data'     => $draft_data,
		'saved_at' => current_time( 'mysql' )
	);

	update_option( 'upr_sections_drafts_archive', $drafts );

	ob_start();
	upr_client_render_drafts_archive_table();
	$table_html = ob_get_clean();

	wp_send_json_success( array( 'table_html' => $table_html ) );
}
}

if ( ! function_exists( 'upr_client_ajax_load_draft_option' ) ) {
function upr_client_ajax_load_draft_option() {
	check_ajax_referer( 'upr_client_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}
	
	$title = sanitize_text_field( $_POST['title'] );
	$drafts = get_option( 'upr_sections_drafts_archive', array() );
	if ( is_string( $drafts ) ) {
		$drafts = json_decode( $drafts, true );
	}
	if ( ! is_array( $drafts ) || ! isset( $drafts[$title] ) ) {
		wp_send_json_error( array( 'message' => 'Draft not found.' ) );
	}

	wp_send_json_success( array( 'draft_data' => $drafts[$title]['data'] ) );
}
}

if ( ! function_exists( 'upr_client_ajax_delete_draft_option' ) ) {
function upr_client_ajax_delete_draft_option() {
	check_ajax_referer( 'upr_client_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}
	
	$title = sanitize_text_field( $_POST['title'] );
	$drafts = get_option( 'upr_sections_drafts_archive', array() );
	if ( is_string( $drafts ) ) {
		$drafts = json_decode( $drafts, true );
	}
	if ( is_array( $drafts ) && isset( $drafts[$title] ) ) {
		unset( $drafts[$title] );
		update_option( 'upr_sections_drafts_archive', $drafts );
	}

	ob_start();
	upr_client_render_drafts_archive_table();
	$table_html = ob_get_clean();

	wp_send_json_success( array( 'table_html' => $table_html ) );
}
}

if ( ! function_exists( 'upr_client_ajax_save_compiled_page' ) ) {
function upr_client_ajax_save_compiled_page() {
	check_ajax_referer( 'upr_client_nonce', 'nonce' );
	
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}

	$title = sanitize_text_field( $_POST['title'] );
	$slug  = sanitize_title( $title );
	
	// Accept base64 encoded CSS and JS scripts to bypass WAF blocks and escaping
	$html  = base64_decode( $_POST['html'] );
	$css   = base64_decode( $_POST['css'] );
	$js    = base64_decode( $_POST['js'] );

	if ( empty( $title ) || empty( $html ) ) {
		wp_send_json_error( array( 'message' => 'Empty page title or HTML compiled code.' ) );
	}

	$upload_dir = wp_upload_dir();
	$local_path = wp_normalize_path( $upload_dir['basedir'] . '/url-page-replicator/pages/' . $slug );

	if ( is_dir( $local_path ) ) {
		upr_client_rrmdir( $local_path );
	}
	mkdir( $local_path, 0755, true );

	// Save code bundles to local directory
	file_put_contents( $local_path . '/index.html', $html );
	file_put_contents( $local_path . '/style.css', $css );
	file_put_contents( $local_path . '/script.js', $js );

	// Create or update WordPress Page
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	$post_args = array(
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => 'Replicated page content served locally.',
		'post_status'  => 'publish',
		'post_type'    => 'page'
	);

	if ( $page ) {
		$post_args['ID'] = $page->ID;
		$post_id = wp_update_post( $post_args );
	} else {
		$post_id = wp_insert_post( $post_args );
	}

	if ( is_wp_error( $post_id ) ) {
		wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
	}

	update_post_meta( $post_id, '_upr_is_replicated', 1 );
	update_post_meta( $post_id, '_upr_local_path', $local_path );

	flush_rewrite_rules();

	wp_send_json_success( array( 'url' => get_permalink( $post_id ) ) );
}
}

if ( ! function_exists( 'upr_client_ajax_run_url_replication' ) ) {
function upr_client_ajax_run_url_replication() {
	check_ajax_referer( 'upr_client_nonce', 'nonce' );
	
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}

	$target_url = esc_url_raw( $_POST['target_url'] );
	$server_url = get_option( 'upr_client_server_url', '' );
	$token      = get_option( 'upr_client_token', '' );

	if ( empty( $server_url ) || empty( $token ) ) {
		wp_send_json_error( array( 'message' => 'Connection settings not configured.' ) );
	}

	$result = upr_client_api_replicate( $server_url, $token, $target_url );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array( 'url' => get_permalink( $result ) ) );
}
}

if ( ! function_exists( 'upr_client_ajax_run_figma_replication' ) ) {
function upr_client_ajax_run_figma_replication() {
	check_ajax_referer( 'upr_client_nonce', 'nonce' );
	
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}

	$desktop_url = esc_url_raw( $_POST['desktop_url'] );
	$tablet_url  = esc_url_raw( $_POST['tablet_url'] );
	$mobile_url  = esc_url_raw( $_POST['mobile_url'] );

	$server_url = get_option( 'upr_client_server_url', '' );
	$token      = get_option( 'upr_client_token', '' );

	if ( empty( $server_url ) || empty( $token ) ) {
		wp_send_json_error( array( 'message' => 'Connection settings not configured.' ) );
	}

	$result = upr_client_api_replicate_figma( $server_url, $token, $desktop_url, $tablet_url, $mobile_url );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 
			'message' => $result->get_error_message(),
			'debug'   => $result->get_error_data()
		) );
	}

	wp_send_json_success( array( 'url' => get_permalink( $result ) ) );
}
}

if ( ! function_exists( 'upr_client_ajax_delete_page' ) ) {
function upr_client_ajax_delete_page() {
	check_ajax_referer( 'upr_client_nonce', 'nonce' );
	
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}

	$delete_id = intval( $_POST['delete_id'] );
	$local_path = get_post_meta( $delete_id, '_upr_local_path', true );
	if ( ! empty( $local_path ) && is_dir( $local_path ) ) {
		upr_client_rrmdir( $local_path );
	}
	
	$res = wp_delete_post( $delete_id, true );
	if ( $res ) {
		wp_send_json_success();
	} else {
		wp_send_json_error( array( 'message' => 'Failed to delete page.' ) );
	}
}
}

if ( ! function_exists( 'upr_client_ajax_get_replicas_table_html' ) ) {
function upr_client_ajax_get_replicas_table_html() {
	check_ajax_referer( 'upr_client_nonce', 'nonce' );
	
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die();
	}

	$replicas = upr_client_get_replicated_pages();
	if ( ! empty( $replicas ) ) : ?>
		<table class="upr-table">
			<thead>
				<tr>
					<th>Title / Slug</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $replicas as $replica ) : 
					$replica_url = get_permalink( $replica->ID );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $replica->post_title ); ?></strong>
							<div class="upr-row-meta">
								<span class="upr-meta-label">Slug:</span>
								<code>/<?php echo esc_html( $replica->post_name ); ?>/</code>
							</div>
						</td>
						<td>
							<div class="upr-row-actions">
								<a href="<?php echo esc_url( $replica_url ); ?>" target="_blank" class="upr-action-btn upr-view-btn" title="View Page">
									<span class="dashicons dashicons-visibility"></span>
								</a>
								<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=upr_export_page&id=' . $replica->ID . '&nonce=' . wp_create_nonce( 'upr_client_nonce' ) ) ); ?>" class="upr-action-btn upr-export-btn" title="Export Page">
									<span class="dashicons dashicons-download" style="transform: rotate(180deg); margin-top: 5px;"></span>
								</a>
								<button type="button" class="upr-action-btn upr-delete-btn upr-client-delete-btn" data-id="<?php echo esc_attr( $replica->ID ); ?>" title="Delete Page">
									<span class="dashicons dashicons-trash"></span>
								</button>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<div class="upr-no-items" style="text-align: center; padding: 40px 20px;">
			<span class="dashicons dashicons-info" style="font-size: 48px; width: 48px; height: 48px; color: #cbd5e1;"></span>
			<p>No pages replicated yet.</p>
		</div>
	<?php endif;
	wp_die();
}
}

// Export Page Package
if ( ! function_exists( 'upr_handle_export_page' ) ) {
	add_action( 'wp_ajax_upr_export_page', 'upr_handle_export_page' );
	function upr_handle_export_page() {
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( $_GET['nonce'], 'upr_client_nonce' ) ) {
			wp_die( 'Security check failed.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.' );
		}

		$post_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
		if ( empty( $post_id ) || 'page' !== get_post_type( $post_id ) ) {
			wp_die( 'Invalid page ID.' );
		}

		$title = get_the_title( $post_id );
		$slug = get_post_field( 'post_name', $post_id );
		$local_path = get_post_meta( $post_id, '_upr_local_path', true );

		if ( empty( $local_path ) || ! is_dir( $local_path ) ) {
			wp_die( 'Page source files not found locally.' );
		}

		// Generate temporary zip
		$zip_filename = $slug . '-export.zip';
		$upload_dir = wp_upload_dir();
		$temp_zip = $upload_dir['basedir'] . '/' . $zip_filename;

		if ( file_exists( $temp_zip ) ) {
			@unlink( $temp_zip );
		}

		// Add metadata.json to folder before zipping
		$metadata = array(
			'title'        => $title,
			'slug'         => $slug,
			'original_url' => get_post_meta( $post_id, '_upr_source_url', true ),
			'js_mode'      => get_post_meta( $post_id, '_upr_js_mode', true ),
			'is_replicated'=> get_post_meta( $post_id, '_upr_is_replicated', true )
		);
		file_put_contents( $local_path . '/metadata.json', json_encode( $metadata, JSON_PRETTY_PRINT ) );

		// Initialize ZipArchive
		$zip = new ZipArchive();
		if ( $zip->open( $temp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === TRUE ) {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $local_path ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $files as $name => $file ) {
				if ( ! $file->isDir() ) {
					$filePath = $file->getRealPath();
					$relativePath = substr( $filePath, strlen( $local_path ) + 1 );
					$zip->addFile( $filePath, $relativePath );
				}
			}
			$zip->close();
		} else {
			wp_die( 'Failed to create ZIP package.' );
		}

		// Clean up temporary metadata.json
		@unlink( $local_path . '/metadata.json' );

		// Serve ZIP file
		if ( file_exists( $temp_zip ) ) {
			if ( ob_get_length() ) {
				ob_end_clean();
			}
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $zip_filename . '"' );
			header( 'Content-Length: ' . filesize( $temp_zip ) );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );
			readfile( $temp_zip );
			@unlink( $temp_zip );
			exit;
		} else {
			wp_die( 'Zip generation failed.' );
		}
	}
}

// Import Page Package
if ( ! function_exists( 'upr_handle_import_page' ) ) {
	add_action( 'wp_ajax_upr_import_page', 'upr_handle_import_page' );
	function upr_handle_import_page() {
		check_ajax_referer( 'upr_client_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		if ( empty( $_FILES['import_file'] ) ) {
			wp_send_json_error( array( 'message' => 'No ZIP file uploaded.' ) );
		}

		$file = $_FILES['import_file'];
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( array( 'message' => 'File upload failed.' ) );
		}

		$ext = pathinfo( $file['name'], PATHINFO_EXTENSION );
		if ( 'zip' !== strtolower( $ext ) ) {
			wp_send_json_error( array( 'message' => 'Please upload a valid ZIP archive.' ) );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $file['tmp_name'] ) !== TRUE ) {
			wp_send_json_error( array( 'message' => 'Failed to open ZIP package.' ) );
		}

		$metadata_index = $zip->locateName( 'metadata.json' );
		if ( $metadata_index === false ) {
			$zip->close();
			wp_send_json_error( array( 'message' => 'ZIP file is not a valid Replicator package (missing metadata.json).' ) );
		}

		$metadata_content = $zip->getFromIndex( $metadata_index );
		$metadata = json_decode( $metadata_content, true );
		if ( empty( $metadata ) || empty( $metadata['slug'] ) || empty( $metadata['title'] ) ) {
			$zip->close();
			wp_send_json_error( array( 'message' => 'Invalid package metadata.' ) );
		}

		$title = sanitize_text_field( $metadata['title'] );
		$slug  = sanitize_title( $metadata['slug'] );

		$upload_dir = wp_upload_dir();
		$target_dir = $upload_dir['basedir'] . '/url-page-replicator/pages/' . $slug;
		wp_mkdir_p( $target_dir );

		// Clear existing files
		$files = glob( $target_dir . '/*' );
		foreach ( $files as $f ) {
			if ( is_file( $f ) ) {
				@unlink( $f );
			}
		}

		if ( ! $zip->extractTo( $target_dir ) ) {
			$zip->close();
			wp_send_json_error( array( 'message' => 'Failed to extract package files.' ) );
		}
		$zip->close();

		$existing_post = get_page_by_path( $slug, OBJECT, 'page' );
		$post_data = array(
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
		);

		if ( $existing_post ) {
			$post_data['ID'] = $existing_post->ID;
			$post_id = wp_update_post( $post_data );
		} else {
			$post_id = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Failed to create local page: ' . $post_id->get_error_message() ) );
		}

		update_post_meta( $post_id, '_upr_is_replicated', 1 );
		update_post_meta( $post_id, '_upr_local_path', wp_normalize_path( $target_dir ) );
		if ( ! empty( $metadata['original_url'] ) ) {
			update_post_meta( $post_id, '_upr_source_url', esc_url_raw( $metadata['original_url'] ) );
		}
		if ( ! empty( $metadata['js_mode'] ) ) {
			update_post_meta( $post_id, '_upr_js_mode', sanitize_text_field( $metadata['js_mode'] ) );
		}

		wp_send_json_success( array( 
			'message' => 'Page imported successfully!',
			'url'     => get_permalink( $post_id )
		) );
	}
}
