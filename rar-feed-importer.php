<?php
/**
 * Plugin Name:       RAR Feed Importer
 * Plugin URI:        https://rarnational.org.au/
 * Description:       Checks an RSS feed once per day and imports any new items as posts.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Jayden
 * License:           GPL-2.0-or-later
 * Text Domain:       rar-feed-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RARFI_VERSION', '1.0.0' );
define( 'RARFI_OPTION', 'rarfi_settings' );
define( 'RARFI_LOG_OPTION', 'rarfi_last_run' );
define( 'RARFI_LOCK', 'rarfi_running' );
define( 'RARFI_CRON_HOOK', 'rarfi_daily_import' );
define( 'RARFI_META_GUID', '_rarfi_guid' );
define( 'RARFI_META_SOURCE', '_rarfi_source_url' );
define( 'RARFI_META_IMAGE', '_rarfi_source_image' );

/**
 * Default settings.
 *
 * @return array
 */
function rarfi_defaults() {
	return array(
		'feed_url'        => 'https://rarnational.org.au/feed',
		'post_type'       => 'post',
		'post_status'     => 'publish',
		'post_author'     => 1,
		'category'        => 0,
		'max_items'       => 20,
		'max_age_days'    => 30,
		'import_images'   => 1,
		'import_terms'    => 0,
		'backdate'        => 1,
	);
}

/**
 * Merged settings.
 *
 * @return array
 */
function rarfi_get_settings() {
	$saved = get_option( RARFI_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, rarfi_defaults() );
}

/* -------------------------------------------------------------------------
 * Scheduling
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'rarfi_activate' );
register_deactivation_hook( __FILE__, 'rarfi_deactivate' );

function rarfi_activate() {
	rarfi_maybe_schedule();
}

function rarfi_deactivate() {
	$timestamp = wp_next_scheduled( RARFI_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, RARFI_CRON_HOOK );
	}
	wp_clear_scheduled_hook( RARFI_CRON_HOOK );
}

/**
 * Make sure the daily event exists. Runs on activation and on every admin load,
 * so the schedule survives things like a plugin folder rename or a partial install.
 */
function rarfi_maybe_schedule() {
	if ( ! wp_next_scheduled( RARFI_CRON_HOOK ) ) {
		// First run tomorrow at roughly 3am site time.
		$offset = (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
		$first  = strtotime( 'tomorrow 3:00am', time() + $offset ) - $offset;
		wp_schedule_event( $first, 'daily', RARFI_CRON_HOOK );
	}
}
add_action( 'admin_init', 'rarfi_maybe_schedule' );

add_action( RARFI_CRON_HOOK, 'rarfi_run_scheduled_import' );

function rarfi_run_scheduled_import() {
	rarfi_import( 'cron' );
}

/* -------------------------------------------------------------------------
 * Import
 * ---------------------------------------------------------------------- */

/**
 * Fetch the feed and import anything new.
 *
 * @param string $trigger 'cron' or 'manual'.
 * @return array Result summary.
 */
function rarfi_import( $trigger = 'cron' ) {
	$settings = rarfi_get_settings();

	$result = array(
		'time'     => current_time( 'mysql' ),
		'trigger'  => $trigger,
		'imported' => 0,
		'skipped'  => 0,
		'errors'   => array(),
		'titles'   => array(),
	);

	if ( empty( $settings['feed_url'] ) ) {
		$result['errors'][] = __( 'No feed URL configured.', 'rar-feed-importer' );
		return rarfi_finish( $result );
	}

	// Stop a manual click or an external trigger colliding with a run already in flight.
	if ( get_transient( RARFI_LOCK ) ) {
		$result['errors'][] = __( 'Another import is already running.', 'rar-feed-importer' );
		return $result;
	}
	set_transient( RARFI_LOCK, 1, 10 * MINUTE_IN_SECONDS );

	if ( ! function_exists( 'fetch_feed' ) ) {
		include_once ABSPATH . WPINC . '/feed.php';
	}

	// Keep SimplePie's cache short so a manual run reflects the live feed.
	add_filter( 'wp_feed_cache_transient_lifetime', 'rarfi_feed_cache_lifetime' );
	$feed = fetch_feed( $settings['feed_url'] );
	remove_filter( 'wp_feed_cache_transient_lifetime', 'rarfi_feed_cache_lifetime' );

	if ( is_wp_error( $feed ) ) {
		$result['errors'][] = $feed->get_error_message();
		return rarfi_finish( $result );
	}

	$max   = max( 1, (int) $settings['max_items'] );
	$items = $feed->get_items( 0, $feed->get_item_quantity( $max ) );

	if ( empty( $items ) ) {
		$result['errors'][] = __( 'Feed returned no items.', 'rar-feed-importer' );
		return rarfi_finish( $result );
	}

	$cutoff = 0;
	if ( (int) $settings['max_age_days'] > 0 ) {
		$cutoff = time() - ( (int) $settings['max_age_days'] * DAY_IN_SECONDS );
	}

	foreach ( $items as $item ) {
		$guid = rarfi_item_guid( $item );

		if ( rarfi_already_imported( $guid ) ) {
			$result['skipped']++;
			continue;
		}

		$timestamp = $item->get_date( 'U' );
		if ( $cutoff && $timestamp && $timestamp < $cutoff ) {
			$result['skipped']++;
			continue;
		}

		$post_id = rarfi_create_post( $item, $guid, $settings );

		if ( is_wp_error( $post_id ) ) {
			$result['errors'][] = $post_id->get_error_message();
			continue;
		}

		$result['imported']++;
		$result['titles'][] = get_the_title( $post_id );
	}

	return rarfi_finish( $result );
}

/**
 * Write the run log, release the lock and hand back the result.
 *
 * @param array $result
 * @return array
 */
function rarfi_finish( $result ) {
	delete_transient( RARFI_LOCK );
	update_option( RARFI_LOG_OPTION, $result, false );

	/**
	 * Fires after an import run finishes.
	 *
	 * @param array $result
	 */
	do_action( 'rarfi_after_import', $result );

	return $result;
}

function rarfi_feed_cache_lifetime() {
	return 15 * MINUTE_IN_SECONDS;
}

/**
 * A stable identifier for a feed item.
 *
 * @param SimplePie_Item $item
 * @return string
 */
function rarfi_item_guid( $item ) {
	$id = $item->get_id( false );

	if ( empty( $id ) ) {
		$id = $item->get_permalink();
	}

	if ( empty( $id ) ) {
		$id = $item->get_title() . '|' . $item->get_date( 'c' );
	}

	return md5( (string) $id );
}

/**
 * Has this item been imported before? Checks all statuses including trash,
 * so a deleted post doesn't get pulled back in on the next run.
 *
 * @param string $guid
 * @return bool
 */
function rarfi_already_imported( $guid ) {
	global $wpdb;

	$post_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			RARFI_META_GUID,
			$guid
		)
	);

	return ! empty( $post_id );
}

/**
 * Turn a feed item into a post.
 *
 * @param SimplePie_Item $item
 * @param string         $guid
 * @param array          $settings
 * @return int|WP_Error
 */
function rarfi_create_post( $item, $guid, $settings ) {
	$title = wp_strip_all_tags( (string) $item->get_title() );
	if ( '' === trim( $title ) ) {
		$title = __( '(untitled feed item)', 'rar-feed-importer' );
	}

	$content = $item->get_content();
	if ( empty( $content ) ) {
		$content = $item->get_description();
	}

	$postarr = array(
		'post_title'   => $title,
		'post_content' => wp_kses_post( (string) $content ),
		'post_excerpt' => wp_kses_post( wp_strip_all_tags( (string) $item->get_description() ) ),
		'post_status'  => 'draft',
		'post_type'    => $settings['post_type'],
		'post_author'  => (int) $settings['post_author'],
	);

	if ( ! empty( $settings['backdate'] ) ) {
		$gmdate = $item->get_gmdate( 'Y-m-d H:i:s' );
		if ( $gmdate ) {
			$postarr['post_date_gmt'] = $gmdate;
			$postarr['post_date']     = get_date_from_gmt( $gmdate );
		}
	}

	if ( 'post' === $settings['post_type'] && (int) $settings['category'] > 0 ) {
		$postarr['post_category'] = array( (int) $settings['category'] );
	}

	$post_id = wp_insert_post( $postarr, true );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	update_post_meta( $post_id, RARFI_META_GUID, $guid );
	update_post_meta( $post_id, RARFI_META_SOURCE, esc_url_raw( (string) $item->get_permalink() ) );

	if ( ! empty( $settings['import_terms'] ) && 'post' === $settings['post_type'] ) {
		rarfi_assign_terms( $post_id, $item );
	}

	if ( ! empty( $settings['import_images'] ) ) {
		rarfi_attach_featured_image( $post_id, $item );
	}

	// Everything is attached, so publishing now fires the draft-to-publish
	// transition with the featured image already in place.
	if ( 'draft' !== $settings['post_status'] ) {
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $settings['post_status'],
			)
		);
	}

	return $post_id;
}

/**
 * Copy the feed item's <category> values across as tags.
 *
 * @param int            $post_id
 * @param SimplePie_Item $item
 */
function rarfi_assign_terms( $post_id, $item ) {
	$categories = $item->get_categories();
	if ( empty( $categories ) ) {
		return;
	}

	$names = array();
	foreach ( $categories as $category ) {
		$label = $category->get_label();
		if ( ! empty( $label ) ) {
			$names[] = sanitize_text_field( $label );
		}
	}

	if ( $names ) {
		wp_set_post_terms( $post_id, $names, 'post_tag', true );
	}
}

/**
 * Find the best candidate image URL for a feed item.
 *
 * Checks, in order: the enclosure, media:thumbnail, media:content, then the
 * first <img> in the item content.
 *
 * @param SimplePie_Item $item
 * @return string
 */
function rarfi_find_image_url( $item ) {
	$enclosure = $item->get_enclosure();

	if ( $enclosure ) {
		$link = $enclosure->get_link();
		$type = (string) $enclosure->get_type();

		if ( $link && ( 0 === strpos( $type, 'image/' ) || rarfi_looks_like_image( $link ) ) ) {
			return $link;
		}

		$thumb = $enclosure->get_thumbnail();
		if ( ! empty( $thumb ) ) {
			return is_array( $thumb ) ? reset( $thumb ) : $thumb;
		}
	}

	// media:thumbnail / media:content, which most WordPress SEO plugins add.
	foreach ( array( 'thumbnail', 'content' ) as $tag ) {
		$nodes = $item->get_item_tags( 'http://search.yahoo.com/mrss/', $tag );
		if ( ! empty( $nodes[0]['attribs']['']['url'] ) ) {
			return $nodes[0]['attribs']['']['url'];
		}
	}

	$content = $item->get_content();
	if ( $content && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches ) ) {
		return $matches[1];
	}

	return '';
}

function rarfi_looks_like_image( $url ) {
	return (bool) preg_match( '/\.(jpe?g|png|gif|webp)(\?|$)/i', $url );
}

/**
 * Return an existing attachment previously sideloaded from this URL, if any,
 * so a repeated image in the feed does not fill the media library with copies.
 *
 * @param string $url
 * @return int
 */
function rarfi_existing_attachment( $url ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			RARFI_META_IMAGE,
			$url
		)
	);
}

/**
 * Sideload a featured image for the post.
 *
 * @param int            $post_id
 * @param SimplePie_Item $item
 */
function rarfi_attach_featured_image( $post_id, $item ) {
	if ( has_post_thumbnail( $post_id ) ) {
		return;
	}

	$url = rarfi_find_image_url( $item );

	if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
		return;
	}

	$url = esc_url_raw( $url );

	$existing = rarfi_existing_attachment( $url );
	if ( $existing ) {
		set_post_thumbnail( $post_id, $existing );
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $url, 30 );
	if ( is_wp_error( $tmp ) ) {
		return;
	}

	$name = basename( wp_parse_url( $url, PHP_URL_PATH ) );
	if ( ! preg_match( '/\.(jpe?g|png|gif|webp)$/i', $name ) ) {
		$name .= '.jpg';
	}

	$file_array = array(
		'name'     => sanitize_file_name( $name ),
		'tmp_name' => $tmp,
	);

	$attachment_id = media_handle_sideload( $file_array, $post_id );

	if ( is_wp_error( $attachment_id ) ) {
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		return;
	}

	update_post_meta( $attachment_id, RARFI_META_IMAGE, $url );
	set_post_thumbnail( $post_id, $attachment_id );
}

/* -------------------------------------------------------------------------
 * Settings screen
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'rarfi_admin_menu' );

function rarfi_admin_menu() {
	add_options_page(
		__( 'RAR Feed Importer', 'rar-feed-importer' ),
		__( 'Feed Importer', 'rar-feed-importer' ),
		'manage_options',
		'rar-feed-importer',
		'rarfi_settings_page'
	);
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'rarfi_action_links' );

function rarfi_action_links( $links ) {
	$url = admin_url( 'options-general.php?page=rar-feed-importer' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'rar-feed-importer' ) . '</a>' );
	return $links;
}

add_action( 'admin_init', 'rarfi_register_settings' );

function rarfi_register_settings() {
	register_setting( 'rarfi_settings_group', RARFI_OPTION, 'rarfi_sanitize_settings' );
}

function rarfi_sanitize_settings( $input ) {
	if ( ! is_array( $input ) ) {
		$input = array();
	}

	$defaults = rarfi_defaults();
	$out      = array();

	$out['feed_url']      = esc_url_raw( trim( (string) ( $input['feed_url'] ?? $defaults['feed_url'] ) ) );
	$out['post_type']     = sanitize_key( $input['post_type'] ?? 'post' );
	$out['post_status']   = in_array( $input['post_status'] ?? '', array( 'draft', 'publish', 'pending', 'private' ), true ) ? $input['post_status'] : $defaults['post_status'];
	$out['post_author']   = max( 1, (int) ( $input['post_author'] ?? 1 ) );
	$out['category']      = max( 0, (int) ( $input['category'] ?? 0 ) );
	$out['max_items']     = min( 100, max( 1, (int) ( $input['max_items'] ?? $defaults['max_items'] ) ) );
	$out['max_age_days']  = max( 0, (int) ( $input['max_age_days'] ?? $defaults['max_age_days'] ) );
	$out['import_images'] = empty( $input['import_images'] ) ? 0 : 1;
	$out['import_terms']  = empty( $input['import_terms'] ) ? 0 : 1;
	$out['backdate']      = empty( $input['backdate'] ) ? 0 : 1;

	return $out;
}

/**
 * Handle the "Import now" button.
 */
add_action( 'admin_post_rarfi_run_now', 'rarfi_handle_run_now' );

function rarfi_handle_run_now() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'rar-feed-importer' ) );
	}

	check_admin_referer( 'rarfi_run_now' );

	$result = rarfi_import( 'manual' );

	$redirect = add_query_arg(
		array(
			'page'          => 'rar-feed-importer',
			'rarfi_ran'     => 1,
			'rarfi_count'   => (int) $result['imported'],
			'rarfi_skipped' => (int) $result['skipped'],
		),
		admin_url( 'options-general.php' )
	);

	wp_safe_redirect( $redirect );
	exit;
}

function rarfi_settings_page() {
	$settings = rarfi_get_settings();
	$log      = get_option( RARFI_LOG_OPTION, array() );
	$next     = wp_next_scheduled( RARFI_CRON_HOOK );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'RAR Feed Importer', 'rar-feed-importer' ); ?></h1>

		<?php if ( isset( $_GET['rarfi_ran'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: 1: imported count, 2: skipped count */
						esc_html__( 'Import finished. %1$d imported, %2$d skipped.', 'rar-feed-importer' ),
						(int) ( $_GET['rarfi_count'] ?? 0 ),
						(int) ( $_GET['rarfi_skipped'] ?? 0 )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'rarfi_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rarfi_feed_url"><?php esc_html_e( 'Feed URL', 'rar-feed-importer' ); ?></label></th>
					<td>
						<input type="url" class="regular-text code" id="rarfi_feed_url"
							name="<?php echo esc_attr( RARFI_OPTION ); ?>[feed_url]"
							value="<?php echo esc_attr( $settings['feed_url'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rarfi_post_type"><?php esc_html_e( 'Create as', 'rar-feed-importer' ); ?></label></th>
					<td>
						<select id="rarfi_post_type" name="<?php echo esc_attr( RARFI_OPTION ); ?>[post_type]">
							<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) : ?>
								<?php if ( 'attachment' === $type->name ) { continue; } ?>
								<option value="<?php echo esc_attr( $type->name ); ?>" <?php selected( $settings['post_type'], $type->name ); ?>>
									<?php echo esc_html( $type->labels->singular_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rarfi_post_status"><?php esc_html_e( 'Status', 'rar-feed-importer' ); ?></label></th>
					<td>
						<select id="rarfi_post_status" name="<?php echo esc_attr( RARFI_OPTION ); ?>[post_status]">
							<?php
							$statuses = array(
								'draft'   => __( 'Draft', 'rar-feed-importer' ),
								'pending' => __( 'Pending review', 'rar-feed-importer' ),
								'publish' => __( 'Published', 'rar-feed-importer' ),
								'private' => __( 'Private', 'rar-feed-importer' ),
							);
							foreach ( $statuses as $value => $label ) :
								?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['post_status'], $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Imported items go live immediately. Switch to Draft if you would rather review them first.', 'rar-feed-importer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Author', 'rar-feed-importer' ); ?></th>
					<td>
						<?php
						wp_dropdown_users(
							array(
								'name'     => RARFI_OPTION . '[post_author]',
								'selected' => (int) $settings['post_author'],
								'who'      => 'authors',
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Category', 'rar-feed-importer' ); ?></th>
					<td>
						<?php
						wp_dropdown_categories(
							array(
								'name'             => RARFI_OPTION . '[category]',
								'selected'         => (int) $settings['category'],
								'show_option_none' => __( '— Default category —', 'rar-feed-importer' ),
								'option_none_value' => 0,
								'hide_empty'       => 0,
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Only applies when creating standard posts.', 'rar-feed-importer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rarfi_max_items"><?php esc_html_e( 'Items per run', 'rar-feed-importer' ); ?></label></th>
					<td>
						<input type="number" min="1" max="100" class="small-text" id="rarfi_max_items"
							name="<?php echo esc_attr( RARFI_OPTION ); ?>[max_items]"
							value="<?php echo esc_attr( $settings['max_items'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rarfi_max_age"><?php esc_html_e( 'Ignore items older than', 'rar-feed-importer' ); ?></label></th>
					<td>
						<input type="number" min="0" class="small-text" id="rarfi_max_age"
							name="<?php echo esc_attr( RARFI_OPTION ); ?>[max_age_days]"
							value="<?php echo esc_attr( $settings['max_age_days'] ); ?>" />
						<?php esc_html_e( 'days (0 = no limit)', 'rar-feed-importer' ); ?>
						<p class="description"><?php esc_html_e( 'Useful on the first run so you do not pull in the whole back catalogue.', 'rar-feed-importer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'rar-feed-importer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" value="1"
								name="<?php echo esc_attr( RARFI_OPTION ); ?>[import_images]"
								<?php checked( $settings['import_images'], 1 ); ?> />
							<?php esc_html_e( 'Download a featured image where one is available', 'rar-feed-importer' ); ?>
						</label><br />
						<label>
							<input type="checkbox" value="1"
								name="<?php echo esc_attr( RARFI_OPTION ); ?>[import_terms]"
								<?php checked( $settings['import_terms'], 1 ); ?> />
							<?php esc_html_e( 'Copy feed categories across as tags', 'rar-feed-importer' ); ?>
						</label><br />
						<label>
							<input type="checkbox" value="1"
								name="<?php echo esc_attr( RARFI_OPTION ); ?>[backdate]"
								<?php checked( $settings['backdate'], 1 ); ?> />
							<?php esc_html_e( 'Keep the original publication date', 'rar-feed-importer' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Schedule', 'rar-feed-importer' ); ?></h2>
		<p>
			<?php if ( $next ) : ?>
				<?php
				printf(
					/* translators: %s: formatted date */
					esc_html__( 'Next automatic run: %s', 'rar-feed-importer' ),
					esc_html( wp_date( 'j M Y, g:ia', $next ) )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'No run scheduled. Deactivate and reactivate the plugin to fix this.', 'rar-feed-importer' ); ?>
			<?php endif; ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="rarfi_run_now" />
			<?php wp_nonce_field( 'rarfi_run_now' ); ?>
			<?php submit_button( __( 'Import now', 'rar-feed-importer' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( ! empty( $log ) ) : ?>
			<h2><?php esc_html_e( 'Last run', 'rar-feed-importer' ); ?></h2>
			<table class="widefat striped" style="max-width:700px">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'When', 'rar-feed-importer' ); ?></th>
						<td><?php echo esc_html( $log['time'] ?? '' ); ?> (<?php echo esc_html( $log['trigger'] ?? '' ); ?>)</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Imported', 'rar-feed-importer' ); ?></th>
						<td><?php echo (int) ( $log['imported'] ?? 0 ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Skipped', 'rar-feed-importer' ); ?></th>
						<td><?php echo (int) ( $log['skipped'] ?? 0 ); ?></td>
					</tr>
					<?php if ( ! empty( $log['titles'] ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'Titles', 'rar-feed-importer' ); ?></th>
							<td><?php echo esc_html( implode( ', ', (array) $log['titles'] ) ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $log['errors'] ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'Errors', 'rar-feed-importer' ); ?></th>
							<td style="color:#b32d2e"><?php echo esc_html( implode( ' | ', (array) $log['errors'] ) ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

// Plugin Updater
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$myUpdateChecker = PucFactory::buildUpdateChecker('https://github.com/JaydenMajor/rar-feed-importer',__FILE__,'rar-feed-importer');
$myUpdateChecker->setBranch('main');