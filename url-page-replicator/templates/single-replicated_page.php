<?php
/**
 * Custom Template for Rendering Replicated Page
 *
 * This template bypasses the active WordPress theme completely to output
 * the exact replicated webpage HTML, including headers, scripts, styles, and body markup.
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = get_the_ID();
$replicated_html = get_post_meta( $post_id, '_upr_replicated_html', true );

if ( ! empty( $replicated_html ) ) {
	// Output raw processed HTML and terminate execution to prevent theme pollution
	echo $replicated_html;
	exit;
} else {
	// Fallback to normal theme if HTML is not found
	get_header();
	?>
	<div class="wrap" style="padding: 50px 20px; max-width: 800px; margin: 0 auto; text-align: center;">
		<div id="primary" class="content-area">
			<main id="main" class="site-main" role="main">
				<article>
					<header class="entry-header">
						<h1 class="entry-title"><?php the_title(); ?></h1>
					</header>
					<div class="entry-content">
						<p><?php esc_html_e( 'Error: No replicated content found for this page.', 'url-page-replicator' ); ?></p>
						<p><a href="<?php echo esc_url( home_url() ); ?>">&larr; <?php esc_html_e( 'Go to Homepage', 'url-page-replicator' ); ?></a></p>
					</div>
				</article>
			</main>
		</div>
	</div>
	<?php
	get_footer();
}
