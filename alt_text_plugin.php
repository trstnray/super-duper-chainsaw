<?php
/**
 * Plugin Name:       Tristan's Alt Text from Filename
 * Description:       Automatically sets image ALT text from the filename on upload, adds one-click actions in Media Library, and provides a stats page with a full image list.
 * Version:           0.6
 * Author:            Tristan Ray
 * Text Domain:       alt-from-filename
 * Requires at least: 5.6
 * Requires PHP:      7.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WAAFT_Alt_From_Filename {
	const NONCE_ACTION  = 'waaft_action_';
	const NONCE_FIELD   = 'waaft_nonce';
	const MENU_SLUG     = 'waaft-alt-from-filename';
	const PER_PAGE      = 50; // admin table pagination

	public static function init() {
		// Core behaviors
		add_action( 'add_attachment', [ __CLASS__, 'maybe_set_alt_on_upload' ] );

		// Admin UI: menu + assets + notices
		add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );

		// Media Library row and bulk actions
		add_filter( 'media_row_actions', [ __CLASS__, 'media_row_action' ], 10, 2 );
		add_filter( 'bulk_actions-upload', [ __CLASS__, 'register_bulk_action' ] );
		add_filter( 'handle_bulk_actions-upload', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );

		// One-off actions via admin-post.php
		add_action( 'admin_post_waaft_set_alt', [ __CLASS__, 'handle_single_set_alt' ] );
		add_action( 'admin_post_waaft_backfill_missing', [ __CLASS__, 'handle_backfill_missing' ] );
	}

	/** ---------- Core: on upload ---------- */
	public static function maybe_set_alt_on_upload( $attachment_id ) {
		if ( ! self::is_image( $attachment_id ) ) { return; }

		$current = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' !== trim( (string) $current ) ) { return; } // respect existing ALT

		$alt = self::derive_alt_from_filename( $attachment_id );
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}
	}

	/** ---------- Utilities ---------- */
	private static function is_image( $attachment_id ) {
		return (bool) wp_attachment_is_image( $attachment_id );
	}

	private static function derive_alt_from_filename( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) { return ''; }

		$base = pathinfo( $file, PATHINFO_FILENAME );

		// Normalize accents using WP core helper.
		if ( function_exists( 'remove_accents' ) ) {
			$base = remove_accents( $base );
		}

		// Replace separators with spaces, strip non-alphanumerics (keep spaces), collapse spaces, trim.
		$base = preg_replace( '/[_\-.]+/u', ' ', $base );
		$base = preg_replace( '/[^A-Za-z0-9 ]+/u', '', $base );
		$base = preg_replace( '/\s+/u', ' ', $base );
		$base = trim( $base );

		return $base;
	}

	private static function can_edit( $attachment_id ) {
		return current_user_can( 'edit_post', $attachment_id );
	}

	private static function nonce_for( $attachment_id ) {
		return wp_create_nonce( self::NONCE_ACTION . $attachment_id );
	}

	private static function check_nonce( $attachment_id, $nonce ) {
		return wp_verify_nonce( $nonce, self::NONCE_ACTION . $attachment_id );
	}

	/** ---------- Admin: menu + page ---------- */
	public static function add_admin_menu() {
		add_menu_page(
			__( 'Alt Text Assistant', 'alt-from-filename' ),
			__( 'Alt Text', 'alt-from-filename' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_admin_page' ],
			'dashicons-format-image',
			65
		);
	}

	public static function enqueue_admin( $hook ) {
		// Load on media-related screens and our settings page
		$targets = [ 'upload.php', 'post.php', 'post-new.php', 'media-new.php', 'settings_page_' . self::MENU_SLUG, 'toplevel_page_' . self::MENU_SLUG ];
		if ( in_array( $hook, $targets, true ) ) {
			wp_enqueue_media();
			wp_enqueue_script(
				'waaft-media',
				plugins_url( 'assets/admin-media.js', __FILE__ ),
				[ 'jquery', 'media-views' ],
				'1.0.0',
				true
			);
			wp_localize_script( 'waaft-media', 'WAAFT', [
				'i18nButton' => __( 'Use filename as ALT', 'alt-from-filename' ),
			] );
		}
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		// Stats
		$total_images = self::count_images_total();
		$with_alt     = self::count_images_with_alt();
		$without_alt  = max( 0, $total_images - $with_alt );
		$mime_counts  = self::image_mime_counts();

		// Pagination
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$q = new WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'ID',
			'order'          => 'DESC',
		] );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Alt Text Assistant', 'alt-from-filename' ); ?></h1>

			<h2><?php esc_html_e( 'Statistics', 'alt-from-filename' ); ?></h2>
			<ul>
				<li><strong><?php echo esc_html( number_format_i18n( $total_images ) ); ?></strong> <?php esc_html_e( 'total images', 'alt-from-filename' ); ?></li>
				<li><strong><?php echo esc_html( number_format_i18n( $with_alt ) ); ?></strong> <?php esc_html_e( 'with ALT', 'alt-from-filename' ); ?></li>
				<li><strong><?php echo esc_html( number_format_i18n( $without_alt ) ); ?></strong> <?php esc_html_e( 'without ALT', 'alt-from-filename' ); ?></li>
			</ul>

			<?php if ( ! empty( $mime_counts ) ) : ?>
				<table class="widefat striped" style="max-width:700px">
					<thead><tr>
						<th><?php esc_html_e( 'MIME', 'alt-from-filename' ); ?></th>
						<th><?php esc_html_e( 'Count', 'alt-from-filename' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $mime_counts as $mime => $count ) : ?>
						<tr>
							<td><?php echo esc_html( $mime ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em">
				<?php wp_nonce_field( self::NONCE_ACTION . 'backfill', self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="waaft_backfill_missing" />
				<?php submit_button( __( 'Backfill ALT for images missing ALT', 'alt-from-filename' ), 'primary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Runs the filename→ALT rule for images that currently have no ALT.', 'alt-from-filename' ); ?></p>
			</form>

			<h2 style="margin-top:2em"><?php esc_html_e( 'All Images', 'alt-from-filename' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Thumb', 'alt-from-filename' ); ?></th>
						<th><?php esc_html_e( 'ID', 'alt-from-filename' ); ?></th>
						<th><?php esc_html_e( 'Filename', 'alt-from-filename' ); ?></th>
						<th><?php esc_html_e( 'ALT', 'alt-from-filename' ); ?></th>
						<th><?php esc_html_e( 'Title', 'alt-from-filename' ); ?></th>
						<th><?php esc_html_e( 'Caption', 'alt-from-filename' ); ?></th>
						<th><?php esc_html_e( 'Description', 'alt-from-filename' ); ?></th>
						<th><?php esc_html_e( 'MIME', 'alt-from-filename' ); ?></th>
						<th><?php esc_html_e( 'Action', 'alt-from-filename' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( $q->have_posts() ) : while ( $q->have_posts() ) : $q->the_post();
					$id   = get_the_ID();
					$alt  = get_post_meta( $id, '_wp_attachment_image_alt', true );
					$mime = get_post_mime_type( $id );
					$file = get_attached_file( $id );
					$base = $file ? wp_basename( $file ) : '';
					$nonce = self::nonce_for( $id );
					$action_url = wp_nonce_url(
						add_query_arg( [
							'action'        => 'waaft_set_alt',
							'attachment_id' => $id,
							'_wp_http_referer' => rawurlencode( esc_url_raw( $_SERVER['REQUEST_URI'] ?? '' ) ),
						], admin_url( 'admin-post.php' ) ),
						self::NONCE_ACTION . $id
					);
				?>
					<tr>
						<td><?php echo wp_get_attachment_image( $id, [50,50], true ); ?></td>
						<td><?php echo (int) $id; ?></td>
						<td><?php echo esc_html( $base ); ?></td>
						<td><?php echo esc_html( $alt ); ?></td>
						<td><?php echo esc_html( get_the_title( $id ) ); ?></td>
						<td><?php echo esc_html( get_post_field( 'post_excerpt', $id ) ); ?></td>
						<td><?php echo esc_html( wp_strip_all_tags( get_post_field( 'post_content', $id ) ) ); ?></td>
						<td><?php echo esc_html( $mime ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Set ALT from filename', 'alt-from-filename' ); ?></a></td>
					</tr>
				<?php endwhile; else : ?>
					<tr><td colspan="9"><?php esc_html_e( 'No images found.', 'alt-from-filename' ); ?></td></tr>
				<?php endif; wp_reset_postdata(); ?>
				</tbody>
			</table>

			<?php
			$total_pages = max( 1, (int) $q->max_num_pages );
			if ( $total_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo paginate_links( [
					'format'  => '?paged=%#%',
					'current' => $paged,
					'total'   => $total_pages,
				] );
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	private static function count_images_total() {
		// Total attachments with image MIME
		$q = new WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => false,
		] );
		return (int) $q->found_posts;
	}

	private static function count_images_with_alt() {
		$q = new WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'meta_query'     => [
				[
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '!=',
				],
			],
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => false,
		] );
		return (int) $q->found_posts;
	}

	private static function image_mime_counts() {
		$out = [];
		$counts = wp_count_attachments( 'image' );
		if ( $counts && is_object( $counts ) ) {
			foreach ( (array) $counts as $mime => $count ) {
				$out[ $mime ] = (int) $count;
			}
		}
		return $out;
	}

	/** ---------- Media Library: row + bulk ---------- */
	public static function media_row_action( $actions, $post ) {
		if ( 'attachment' !== $post->post_type || ! self::is_image( $post->ID ) || ! self::can_edit( $post->ID ) ) {
			return $actions;
		}
		$url = wp_nonce_url(
			add_query_arg( [
				'action'        => 'waaft_set_alt',
				'attachment_id' => $post->ID,
			], admin_url( 'admin-post.php' ) ),
			self::NONCE_ACTION . $post->ID
		);
		$actions['waaft_set_alt'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Set ALT from filename', 'alt-from-filename' ) . '</a>';
		return $actions;
	}

	public static function register_bulk_action( $actions ) {
		$actions['waaft_bulk_set_alt'] = __( 'Set ALT from filename', 'alt-from-filename' );
		return $actions;
	}

	public static function handle_bulk_action( $redirect_to, $action, $ids ) {
		if ( 'waaft_bulk_set_alt' !== $action || empty( $ids ) ) {
			return $redirect_to;
		}
		$updated = 0;
		foreach ( $ids as $id ) {
			if ( ! self::is_image( $id ) || ! self::can_edit( $id ) ) { continue; }
			$alt = self::derive_alt_from_filename( $id );
			if ( '' !== $alt ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
				$updated++;
			}
		}
		return add_query_arg( [ 'waaft_msg' => rawurlencode( sprintf( __( 'ALT updated for %d item(s).', 'alt-from-filename' ), $updated ) ) ], $redirect_to );
	}

	/** ---------- Actions: single + backfill ---------- */
	public static function handle_single_set_alt() {
		$attachment_id = isset( $_GET['attachment_id'] ) ? (int) $_GET['attachment_id'] : 0;
		if ( ! $attachment_id || ! self::is_image( $attachment_id ) || ! self::can_edit( $attachment_id ) ) {
			wp_die( esc_html__( 'Invalid request.', 'alt-from-filename' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! self::check_nonce( $attachment_id, sanitize_text_field( $_GET['_wpnonce'] ) ) ) {
			wp_die( esc_html__( 'Security check failed.', 'alt-from-filename' ) );
		}

		$alt = self::derive_alt_from_filename( $attachment_id );
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			$msg = __( 'ALT set from filename.', 'alt-from-filename' );
		} else {
			$msg = __( 'Could not derive ALT from filename.', 'alt-from-filename' );
		}

		$back = ! empty( $_GET['_wp_http_referer'] ) ? esc_url_raw( wp_unslash( $_GET['_wp_http_referer'] ) ) : admin_url( 'upload.php' );
		wp_safe_redirect( add_query_arg( [ 'waaft_msg' => rawurlencode( $msg ) ], $back ) );
		exit;
	}

	public static function handle_backfill_missing() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'alt-from-filename' ) );
		}
		check_admin_referer( self::NONCE_ACTION . 'backfill', self::NONCE_FIELD );

		// Update images missing ALT (no meta or empty string)
		$q = new WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'meta_query'     => [
				'relation' => 'OR',
				[
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				],
			],
			'fields'         => 'ids',
			'posts_per_page' => 500, // batch size to keep it snappy
			'no_found_rows'  => true,
		] );

		$updated = 0;
		foreach ( $q->posts as $id ) {
			if ( ! self::can_edit( $id ) ) { continue; }
			$alt = self::derive_alt_from_filename( $id );
			if ( '' !== $alt ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
				$updated++;
			}
		}

		wp_safe_redirect( add_query_arg(
			[ 'page' => self::MENU_SLUG, 'waaft_msg' => rawurlencode( sprintf( __( 'Backfilled %d image(s).', 'alt-from-filename' ), $updated ) ) ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/** ---------- Notices ---------- */
	public static function admin_notices() {
		if ( empty( $_GET['waaft_msg'] ) ) { return; }
		$msg = wp_kses_post( wp_unslash( $_GET['waaft_msg'] ) );
		echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
	}
}

WAAFT_Alt_From_Filename::init();

/** ---------- Assets loader helper (for single-file plugins) ---------- */
add_action( 'plugins_loaded', function() {
	// If the JS asset file is missing (e.g., copy-paste install), register a fallback inline script.
	$asset_path = __DIR__ . '/assets/admin-media.js';
	if ( ! file_exists( $asset_path ) ) {
		add_action( 'admin_enqueue_scripts', function( $hook ) {
			$targets = [ 'upload.php', 'post.php', 'post-new.php', 'media-new.php' ];
			if ( in_array( $hook, $targets, true ) ) {
				wp_add_inline_script( 'media-views', WAAFT_inline_media_js() );
			}
		} );
	}
} );

function WAAFT_inline_media_js() {
	return <<<JS
(function($){
	if (!window.wp || !wp.media || !wp.media.view) { return; }
	var label = (window.WAAFT && WAAFT.i18nButton) ? WAAFT.i18nButton : 'Use filename as ALT';

	function addButton(view){
		var target = view.$el.find('.setting[data-setting="alt"]');
		if (!target.length || target.find('.waaft-btn').length) { return; }
		var btn = $('<button type="button" class="button button-small waaft-btn" style="margin-top:4px;">'+label+'</button>');
		btn.on('click', function(){
			try {
				var model = view.model || (view.controller && view.controller.state().get('selection').first());
				if (!model) { return; }
				var filename = (model.get('filename') || '').replace(/\\.[^/.]+$/, '');
				// Normalize (approximate WP PHP logic)
				filename = filename.normalize ? filename.normalize('NFD').replace(/[\\u0300-\\u036f]/g, '') : filename;
				filename = filename.replace(/[_\\-.]+/g, ' ')
					.replace(/[^A-Za-z0-9 ]+/g, '')
					.replace(/\\s+/g, ' ')
					.trim();
				target.find('input[type="text"]').val(filename).trigger('change');
			} catch(e){}
		});
		target.append(btn);
	}

	function wrap(proto){
		if (!proto) { return; }
		var original = proto.render;
		proto.render = function(){
			var out = original.apply(this, arguments);
			setTimeout(addButton.bind(null, this), 0);
			return out;
		};
	}

	$(function(){
		wrap(wp.media.view.Attachment && wp.media.view.Attachment.Details && wp.media.view.Attachment.Details.prototype);
		wrap(wp.media.view.AttachmentCompat && wp.media.view.AttachmentCompat.prototype);
	});
})(jQuery);
JS;
}
