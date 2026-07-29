<?php
/**
 * Plugin Name: Discord Webhook Notifications for MainWP
 * Description: Sends Discord webhook notifications when plugin, theme, or FlowMattic patch updates are available in MainWP.
 * Version: 1.3.0
 * Author: Isaac @ Sprucely Designed
 * Author URI: https://www.sprucely.net
 * Plugin URI: https://github.com/sprucely-designed/mainwp-discord-notifications
 *
 * RequiresPlugins: mainwp
 *
 * License: GPL3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * GitHub Plugin URI: https://github.com/sprucely-designed/mainwp-discord-notifications
 * Primary Branch: main
 *
 * @package Sprucely_MWP_Discord
 */

defined( 'ABSPATH' ) || exit;

/**
 * FlowMattic patch state option saved against each MainWP website.
 *
 * Keep this aligned with the option used by the MainWP FlowMattic Patcher.
 */
const SPRUCELY_MWPDN_FLOWMATTIC_STATE_OPTION = 'rup_flowmattic_patcher_state';

/**
 * Webhook URL registry.
 */
global $sprucely_mwpdn_webhook_urls;
$sprucely_mwpdn_webhook_urls = array(
	'plugin_updates'    => '',
	'theme_updates'     => '',
	'flowmattic_updates'=> '',
);

add_action( 'mainwp_child_plugin_activated', 'sprucely_mwpdn_setup_plugin_update_hook' );
add_action( 'mainwp_cronupdatescheck_action', 'sprucely_mwpdn_check_for_updates' );
add_action( 'sprucely_mwpdn_check_for_updates', 'sprucely_mwpdn_check_for_updates' );

register_activation_hook( __FILE__, 'sprucely_mwpdn_setup_plugin_update_hook' );
register_deactivation_hook( __FILE__, 'sprucely_mwpdn_clear_scheduled_hook' );

/**
 * Schedule an hourly fallback check.
 *
 * MainWP's own cron update action also triggers the same check.
 */
function sprucely_mwpdn_setup_plugin_update_hook() {
	if ( ! wp_next_scheduled( 'sprucely_mwpdn_check_for_updates' ) ) {
		wp_schedule_event( time(), 'hourly', 'sprucely_mwpdn_check_for_updates' );
	}
}

/**
 * Clear the fallback scheduled check.
 */
function sprucely_mwpdn_clear_scheduled_hook() {
	wp_clear_scheduled_hook( 'sprucely_mwpdn_check_for_updates' );
}

/**
 * Check all supported update types.
 */
function sprucely_mwpdn_check_for_updates() {
	sprucely_mwpdn_set_webhook_urls();
	sprucely_mwpdn_check_for_plugin_updates();
	sprucely_mwpdn_check_for_theme_updates();
	sprucely_mwpdn_check_for_flowmattic_updates();
}

/**
 * Load configured webhook URLs.
 */
function sprucely_mwpdn_set_webhook_urls() {
	global $sprucely_mwpdn_webhook_urls;

	$sprucely_mwpdn_webhook_urls['plugin_updates']     = get_option( 'mwpdn_plugin_updates_webhook_url', '' );
	$sprucely_mwpdn_webhook_urls['theme_updates']      = get_option( 'mwpdn_theme_updates_webhook_url', '' );
	$sprucely_mwpdn_webhook_urls['flowmattic_updates'] = get_option( 'mwpdn_flowmattic_updates_webhook_url', '' );
}

/**
 * Check for plugin updates.
 */
function sprucely_mwpdn_check_for_plugin_updates() {
	global $sprucely_mwpdn_webhook_urls, $wpdb;

	if ( empty( $sprucely_mwpdn_webhook_urls['plugin_updates'] ) ) {
		return;
	}

	$cache_key = 'sprucely_mwpdn_plugin_updates';
	$results   = wp_cache_get( $cache_key );

	if ( false === $results ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT plugin_upgrades
				FROM {$wpdb->prefix}mainwp_wp
				WHERE is_ignorePluginUpdates = %d
				",
				0
			)
		);

		wp_cache_set( $cache_key, $results, '', 300 );
	}

	if ( empty( $results ) ) {
		return;
	}

	$sent_notifications = get_option( 'sprucely_mwpdn_sent_plugin_notifications', array() );
	$unique_updates     = array();

	foreach ( $results as $result ) {
		$plugin_upgrades = json_decode( $result->plugin_upgrades, true );

		if ( ! is_array( $plugin_upgrades ) ) {
			continue;
		}

		foreach ( $plugin_upgrades as $plugin_slug => $plugin_info ) {
			if ( empty( $plugin_info['update'] ) || ! is_array( $plugin_info['update'] ) ) {
				continue;
			}

			$update_info = $plugin_info['update'];
			$new_version = (string) ( $update_info['new_version'] ?? '' );

			if ( '' === $new_version ) {
				continue;
			}

			$unique_key = $plugin_slug . '|' . $new_version;

			if (
				isset( $sent_notifications[ $plugin_slug ] )
				&& ! version_compare( $sent_notifications[ $plugin_slug ], $new_version, '<' )
			) {
				continue;
			}

			$unique_updates[ $unique_key ] = array(
				'plugin_name'   => $plugin_info['Name'] ?? $plugin_slug,
				'new_version'   => $new_version,
				'changelog_url' => $update_info['url'] ?? '',
				'plugin_uri'    => $plugin_info['PluginURI'] ?? '',
				'thumbnail_url' => sprucely_mwpdn_get_cached_thumbnail_url( $plugin_info['PluginURI'] ?? '' ),
				'description'   => $plugin_info['Description'] ?? '',
				'author'        => $plugin_info['AuthorName'] ?? '',
				'changelog'     => $update_info['sections']['changelog'] ?? '',
			);
		}
	}

	foreach ( $unique_updates as $key => $update ) {
		if ( sprucely_mwpdn_send_discord_message( $update, 'plugin_updates' ) ) {
			list( $plugin_slug, $new_version ) = explode( '|', $key, 2 );
			$sent_notifications[ $plugin_slug ] = $new_version;
		}

		usleep( 500000 );
	}

	if ( ! empty( $unique_updates ) ) {
		update_option( 'sprucely_mwpdn_sent_plugin_notifications', $sent_notifications );
	}
}

/**
 * Check for theme updates.
 */
function sprucely_mwpdn_check_for_theme_updates() {
	global $sprucely_mwpdn_webhook_urls, $wpdb;

	if ( empty( $sprucely_mwpdn_webhook_urls['theme_updates'] ) ) {
		return;
	}

	$cache_key = 'sprucely_mwpdn_theme_updates';
	$results   = wp_cache_get( $cache_key );

	if ( false === $results ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT theme_upgrades
				FROM {$wpdb->prefix}mainwp_wp
				WHERE is_ignoreThemeUpdates = %d
				",
				0
			)
		);

		wp_cache_set( $cache_key, $results, '', 300 );
	}

	if ( empty( $results ) ) {
		return;
	}

	$sent_notifications = get_option( 'sprucely_mwpdn_sent_theme_notifications', array() );
	$unique_updates     = array();

	foreach ( $results as $result ) {
		$theme_upgrades = json_decode( $result->theme_upgrades, true );

		if ( ! is_array( $theme_upgrades ) ) {
			continue;
		}

		foreach ( $theme_upgrades as $theme_slug => $theme_info ) {
			if ( empty( $theme_info['update'] ) || ! is_array( $theme_info['update'] ) ) {
				continue;
			}

			$update_info = $theme_info['update'];
			$new_version = (string) ( $update_info['new_version'] ?? '' );

			if ( '' === $new_version ) {
				continue;
			}

			$unique_key = $theme_slug . '|' . $new_version;

			if (
				isset( $sent_notifications[ $theme_slug ] )
				&& ! version_compare( $sent_notifications[ $theme_slug ], $new_version, '<' )
			) {
				continue;
			}

			$unique_updates[ $unique_key ] = array(
				'theme_name'    => $theme_info['Name'] ?? $theme_slug,
				'new_version'   => $new_version,
				'changelog_url' => $update_info['url'] ?? '',
				'theme_uri'     => $update_info['url'] ?? '',
				'thumbnail_url' => sprucely_mwpdn_get_cached_thumbnail_url( $update_info['url'] ?? '' ),
				'description'   => $theme_info['Description'] ?? '',
				'author'        => $theme_info['AuthorName'] ?? '',
				'changelog'     => $update_info['sections']['changelog'] ?? '',
			);
		}
	}

	foreach ( $unique_updates as $key => $update ) {
		if ( sprucely_mwpdn_send_discord_message( $update, 'theme_updates' ) ) {
			list( $theme_slug, $new_version ) = explode( '|', $key, 2 );
			$sent_notifications[ $theme_slug ] = $new_version;
		}

		usleep( 500000 );
	}

	if ( ! empty( $unique_updates ) ) {
		update_option( 'sprucely_mwpdn_sent_theme_notifications', $sent_notifications );
	}
}

/**
 * Check for pending FlowMattic patches stored by the MainWP FlowMattic Patcher.
 *
 * Each patch is notified once per site and patch signature. A different webhook
 * can be configured for these notifications.
 */
function sprucely_mwpdn_check_for_flowmattic_updates() {
	global $sprucely_mwpdn_webhook_urls;

	if ( empty( $sprucely_mwpdn_webhook_urls['flowmattic_updates'] ) ) {
		return;
	}

	$sites = sprucely_mwpdn_get_mainwp_sites();

	if ( empty( $sites ) ) {
		return;
	}

	$sent_notifications = get_option( 'sprucely_mwpdn_sent_flowmattic_notifications', array() );
	$active_keys        = array();
	$changed            = false;

	foreach ( $sites as $site ) {
		$site_id = (int) ( $site->id ?? 0 );

		if ( $site_id < 1 ) {
			continue;
		}

		$state = sprucely_mwpdn_get_flowmattic_state( $site_id );

		if ( empty( $state['patches'] ) || ! is_array( $state['patches'] ) ) {
			continue;
		}

		$site_name = (string) ( $site->name ?? $site->url ?? 'MainWP site' );
		$site_url  = (string) ( $site->url ?? '' );

		foreach ( $state['patches'] as $patch ) {
			if ( ! is_array( $patch ) || 'pending' !== (string) ( $patch['status'] ?? '' ) ) {
				continue;
			}

			$patch_id = (int) ( $patch['patch_id'] ?? 0 );

			if ( $patch_id < 1 ) {
				continue;
			}

			$title = trim(
				(string) (
					$patch['title']
					?? $patch['name']
					?? 'FlowMattic patch'
				)
			);

			$version = (string) ( $patch['version'] ?? $state['version'] ?? '' );

			/*
			 * Signature changes when the patch ID, title, or version changes,
			 * allowing a materially revised patch to generate a new alert.
			 */
			$notification_key = $site_id . '|patch-' . $patch_id;
			$signature        = hash( 'sha256', $patch_id . '|' . $title . '|' . $version );

			$active_keys[ $notification_key ] = true;

			if (
				isset( $sent_notifications[ $notification_key ] )
				&& hash_equals( (string) $sent_notifications[ $notification_key ], $signature )
			) {
				continue;
			}

			$update = array(
				'flowmattic_name' => 'FlowMattic Patch #' . $patch_id,
				'patch_title'     => $title,
				'patch_id'        => $patch_id,
				'new_version'     => $version,
				'description'     => (string) ( $patch['description'] ?? '' ),
				'site_name'       => $site_name,
				'site_url'        => $site_url,
			);

			if ( sprucely_mwpdn_send_discord_message( $update, 'flowmattic_updates' ) ) {
				$sent_notifications[ $notification_key ] = $signature;
				$changed = true;
			}

			usleep( 500000 );
		}
	}

	/*
	 * Remove entries for patches that are no longer pending. If the same patch
	 * becomes pending again later, a new notification can be sent.
	 */
	foreach ( array_keys( $sent_notifications ) as $notification_key ) {
		if ( ! isset( $active_keys[ $notification_key ] ) ) {
			unset( $sent_notifications[ $notification_key ] );
			$changed = true;
		}
	}

	if ( $changed ) {
		update_option( 'sprucely_mwpdn_sent_flowmattic_notifications', $sent_notifications );
	}
}

/**
 * Get MainWP sites visible to the current MainWP context.
 *
 * @return array
 */
function sprucely_mwpdn_get_mainwp_sites() {
	if ( ! class_exists( '\MainWP\Dashboard\MainWP_DB' ) ) {
		return array();
	}

	try {
		$db = \MainWP\Dashboard\MainWP_DB::instance();

		$sites = method_exists( $db, 'get_websites_for_current_user' )
			? $db->get_websites_for_current_user()
			: array();

		if ( empty( $sites ) && method_exists( $db, 'get_websites' ) ) {
			$sites = $db->get_websites();
		}

		return is_array( $sites ) ? $sites : (array) $sites;
	} catch ( Throwable $throwable ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'MainWP Discord Notifications site lookup failed: ' . $throwable->getMessage() );
		return array();
	}
}

/**
 * Load the saved FlowMattic patch state for a MainWP site.
 *
 * @param int $site_id MainWP site ID.
 * @return array
 */
function sprucely_mwpdn_get_flowmattic_state( $site_id ) {
	$json = apply_filters(
		'mainwp_getwebsiteoptions',
		'',
		(int) $site_id,
		SPRUCELY_MWPDN_FLOWMATTIC_STATE_OPTION
	);

	$state = json_decode( (string) $json, true );

	return is_array( $state ) ? $state : array();
}

/**
 * Retrieve a cached Open Graph image or favicon URL.
 *
 * @param string $url Target URL.
 * @return string
 */
function sprucely_mwpdn_get_cached_thumbnail_url( $url ) {
	if ( empty( $url ) ) {
		return '';
	}

	$cache_key     = 'sprucely_mwpdn_thumbnail_url_' . md5( $url );
	$thumbnail_url = get_transient( $cache_key );

	if ( false === $thumbnail_url ) {
		$thumbnail_url = sprucely_mwpdn_get_thumbnail_url( $url );
		set_transient( $cache_key, $thumbnail_url, WEEK_IN_SECONDS );
	}

	return (string) $thumbnail_url;
}

/**
 * Retrieve an Open Graph image or favicon URL.
 *
 * @param string $url Target URL.
 * @return string
 */
function sprucely_mwpdn_get_thumbnail_url( $url ) {
	$parsed_url = wp_parse_url( $url );

	if ( empty( $parsed_url['scheme'] ) || empty( $parsed_url['host'] ) ) {
		return '';
	}

	$base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 10,
			'redirection' => 3,
		)
	);

	if ( is_wp_error( $response ) ) {
		return '';
	}

	$html = wp_remote_retrieve_body( $response );

	if ( '' === $html || ! class_exists( 'DOMDocument' ) ) {
		return '';
	}

	$previous = libxml_use_internal_errors( true );
	$dom      = new DOMDocument();
	$loaded   = $dom->loadHTML( $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	if ( ! $loaded ) {
		return '';
	}

	foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
		$property = $meta->getAttribute( 'property' );
		$name     = $meta->getAttribute( 'name' );

		if ( 'og:image' === $property || 'og:image' === $name ) {
			return esc_url_raw( $meta->getAttribute( 'content' ) );
		}
	}

	foreach ( $dom->getElementsByTagName( 'link' ) as $link ) {
		$rel = strtolower( $link->getAttribute( 'rel' ) );

		if ( 'icon' !== $rel && 'shortcut icon' !== $rel ) {
			continue;
		}

		$favicon_url = $link->getAttribute( 'href' );

		if ( 0 !== strpos( $favicon_url, 'http' ) ) {
			$favicon_url = $base_url . '/' . ltrim( $favicon_url, '/' );
		}

		return esc_url_raw( $favicon_url );
	}

	return '';
}

/**
 * Convert common HTML to Discord-compatible Markdown.
 *
 * @param string $html HTML content.
 * @return string
 */
function sprucely_mwpdn_convert_html_to_markdown( $html ) {
	$html = (string) $html;
	$html = preg_replace( '/<a[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', '[$2]($1)', $html );
	$html = preg_replace( '/<(?!a\s|\/a\s*|\/?a\s+[^>]*\s*>)(\w+)\s+[^>]*>/i', '<$1>', $html );

	$markdown = $html;
	$markdown = preg_replace( '/<strong>(.*?)<\/strong>/is', '**$1**', $markdown );
	$markdown = preg_replace( '/<b>(.*?)<\/b>/is', '**$1**', $markdown );
	$markdown = preg_replace( '/<em>(.*?)<\/em>/is', '*$1*', $markdown );
	$markdown = preg_replace( '/<i>(.*?)<\/i>/is', '*$1*', $markdown );
	$markdown = preg_replace( '/<code>(.*?)<\/code>/is', '`$1`', $markdown );

	for ( $level = 1; $level <= 6; $level++ ) {
		$markdown = preg_replace(
			'/<h' . $level . '>(.*?)<\/h' . $level . '>/is',
			str_repeat( '#', $level ) . ' $1',
			$markdown
		);
	}

	$markdown = preg_replace( '/<\/?(ul|ol)>/i', "\n", $markdown );
	$markdown = preg_replace( '/<li>/i', '- ', $markdown );
	$markdown = preg_replace( '/<\/li>/i', "\n", $markdown );
	$markdown = wp_strip_all_tags( $markdown );
	$markdown = html_entity_decode( $markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown );

	return trim( $markdown );
}

/**
 * Send a Discord webhook message.
 *
 * @param array  $update           Update information.
 * @param string $webhook_url_type Webhook key.
 * @return bool
 */
function sprucely_mwpdn_send_discord_message( $update, $webhook_url_type ) {
	global $sprucely_mwpdn_webhook_urls;

	if ( empty( $sprucely_mwpdn_webhook_urls[ $webhook_url_type ] ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'Discord webhook URL not defined for ' . $webhook_url_type . '.' );
		return false;
	}

	$webhook_url = $sprucely_mwpdn_webhook_urls[ $webhook_url_type ];

	if ( 'flowmattic_updates' === $webhook_url_type ) {
		$title = $update['flowmattic_name'] ?? 'FlowMattic Patch';

		$embed_description  = "**Pending FlowMattic patch detected.**\n\n";
		$embed_description .= ! empty( $update['patch_title'] )
			? '**Patch:** ' . sprucely_mwpdn_convert_html_to_markdown( $update['patch_title'] ) . "\n"
			: '';
		$embed_description .= ! empty( $update['patch_id'] )
			? '**Patch ID:** ' . absint( $update['patch_id'] ) . "\n"
			: '';
		$embed_description .= ! empty( $update['new_version'] )
			? '**FlowMattic version:** ' . sanitize_text_field( $update['new_version'] ) . "\n"
			: '';
		$embed_description .= ! empty( $update['site_name'] )
			? '**Site:** ' . sanitize_text_field( $update['site_name'] ) . "\n"
			: '';

		if ( ! empty( $update['description'] ) ) {
			$description = sprucely_mwpdn_convert_html_to_markdown( $update['description'] );

			if ( function_exists( 'mb_substr' ) && mb_strlen( $description ) > 1200 ) {
				$description = mb_substr( $description, 0, 1197 ) . '...';
			} elseif ( strlen( $description ) > 1200 ) {
				$description = substr( $description, 0, 1197 ) . '...';
			}

			$embed_description .= "\n**Description:**\n" . $description;
		}

		$embed = array(
			'title'       => $title,
			'description' => $embed_description,
		);

		if ( ! empty( $update['site_url'] ) ) {
			$embed['url'] = esc_url_raw( $update['site_url'] );
		}
	} else {
		$changelog_summary = '';

		if ( ! empty( $update['changelog'] ) ) {
			$changelog_summary = sprucely_mwpdn_convert_html_to_markdown( $update['changelog'] );

			if ( function_exists( 'mb_substr' ) && mb_strlen( $changelog_summary ) > 850 ) {
				$changelog_summary = mb_substr( $changelog_summary, 0, 847 ) . '...';
			} elseif ( strlen( $changelog_summary ) > 850 ) {
				$changelog_summary = substr( $changelog_summary, 0, 847 ) . '...';
			}

			$changelog_summary = "**Changelog Summary:** {$changelog_summary}\n";
		}

		if (
			! empty( $update['changelog_url'] )
			&& false !== strpos( $update['changelog_url'], 'wordpress.org/plugins' )
		) {
			$update['changelog_url'] = trailingslashit( $update['changelog_url'] ) . '#developers';
		}

		$description   = ! empty( $update['description'] )
			? '**Description:** ' . sprucely_mwpdn_convert_html_to_markdown( $update['description'] ) . "\n"
			: '';
		$author        = ! empty( $update['author'] )
			? '**Author:** ' . sprucely_mwpdn_convert_html_to_markdown( $update['author'] ) . "\n"
			: '';
		$changelog_url = ! empty( $update['changelog_url'] )
			? '[View Full Changelog](' . esc_url_raw( $update['changelog_url'] ) . ')'
			: '';

		$embed_description  = '**Version ' . sanitize_text_field( $update['new_version'] ?? '' ) . " is available.**\n\n";
		$embed_description .= $author;
		$embed_description .= $description;
		$embed_description .= $changelog_summary;
		$embed_description .= $changelog_url ? "\n\n{$changelog_url}" : '';

		$embed = array(
			'title'       => $update['plugin_name'] ?? $update['theme_name'] ?? 'Update available',
			'description' => $embed_description,
		);

		$item_url = $update['plugin_uri'] ?? $update['theme_uri'] ?? '';

		if ( ! empty( $item_url ) ) {
			$embed['url'] = esc_url_raw( $item_url );
		}

		if ( ! empty( $update['thumbnail_url'] ) ) {
			$embed['thumbnail'] = array(
				'url' => esc_url_raw( $update['thumbnail_url'] ),
			);
		}
	}

	$payload = array(
		'content' => '',
		'embeds'  => array( $embed ),
	);

	$response = wp_remote_post(
		$webhook_url,
		array(
			'body'        => wp_json_encode( $payload ),
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'method'      => 'POST',
			'data_format' => 'body',
			'timeout'     => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'Discord Webhook Error: ' . $response->get_error_message() );
		return false;
	}

	$response_code = wp_remote_retrieve_response_code( $response );

	if ( 204 !== $response_code && 200 !== $response_code ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			'Discord Webhook Response (' . $response_code . '): '
			. wp_remote_retrieve_body( $response )
		);
		return false;
	}

	return true;
}

/**
 * Add a support link to the plugin metadata.
 *
 * @param array  $links Existing links.
 * @param string $file  Plugin file.
 * @return array
 */
function sprucely_mwpdn_add_support_meta_link( $links, $file ) {
	if ( plugin_basename( __FILE__ ) === $file ) {
		$links[] = '<a href="https://github.com/sprucely-designed/mainwp-discord-notifications/issues">'
			. esc_html__( 'Support', 'mainwp-discord-webhook-notifications' )
			. '</a>';
	}

	return $links;
}
add_filter( 'plugin_row_meta', 'sprucely_mwpdn_add_support_meta_link', 10, 2 );

/*
 * Settings.
 */
add_action( 'admin_menu', 'sprucely_mwpdn_add_settings_page' );
add_action( 'admin_init', 'sprucely_mwpdn_register_settings' );

/**
 * Add the settings page.
 */
function sprucely_mwpdn_add_settings_page() {
	add_options_page(
		'MainWP Update Notifications',
		'Update Notifications',
		'manage_options',
		'mwpdn-settings',
		'sprucely_mwpdn_render_settings_page'
	);
}

/**
 * Register webhook settings and fields.
 */
function sprucely_mwpdn_register_settings() {
	register_setting(
		'mwpdn_settings_group',
		'mwpdn_plugin_updates_webhook_url',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		)
	);

	register_setting(
		'mwpdn_settings_group',
		'mwpdn_theme_updates_webhook_url',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		)
	);

	register_setting(
		'mwpdn_settings_group',
		'mwpdn_flowmattic_updates_webhook_url',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		)
	);

	add_settings_section(
		'mwpdn_main_section',
		'Discord Webhook URLs',
		'__return_false',
		'mwpdn-settings'
	);

	add_settings_field(
		'mwpdn_plugin_updates_webhook_url',
		'Plugin Updates Webhook URL',
		'sprucely_mwpdn_render_plugin_webhook_field',
		'mwpdn-settings',
		'mwpdn_main_section'
	);

	add_settings_field(
		'mwpdn_theme_updates_webhook_url',
		'Theme Updates Webhook URL',
		'sprucely_mwpdn_render_theme_webhook_field',
		'mwpdn-settings',
		'mwpdn_main_section'
	);

	add_settings_field(
		'mwpdn_flowmattic_updates_webhook_url',
		'FlowMattic Patch Updates Webhook URL',
		'sprucely_mwpdn_render_flowmattic_webhook_field',
		'mwpdn-settings',
		'mwpdn_main_section'
	);
}

/**
 * Render settings page.
 */
function sprucely_mwpdn_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1>MainWP Update Notifications</h1>
		<p>
			Use separate Discord webhooks for plugin updates, theme updates,
			and pending FlowMattic patches. Leave a field empty to disable that
			notification type.
		</p>

		<form method="post" action="options.php">
			<?php
			settings_fields( 'mwpdn_settings_group' );
			do_settings_sections( 'mwpdn-settings' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Render a webhook URL field.
 *
 * @param string $option_name Option name.
 */
function sprucely_mwpdn_render_webhook_field( $option_name ) {
	$url = get_option( $option_name, '' );

	printf(
		'<input type="url" name="%1$s" value="%2$s" class="regular-text code" placeholder="https://discord.com/api/webhooks/…" autocomplete="off" />',
		esc_attr( $option_name ),
		esc_attr( $url )
	);
}

/**
 * Render plugin webhook field.
 */
function sprucely_mwpdn_render_plugin_webhook_field() {
	sprucely_mwpdn_render_webhook_field( 'mwpdn_plugin_updates_webhook_url' );
}

/**
 * Render theme webhook field.
 */
function sprucely_mwpdn_render_theme_webhook_field() {
	sprucely_mwpdn_render_webhook_field( 'mwpdn_theme_updates_webhook_url' );
}

/**
 * Render FlowMattic webhook field.
 */
function sprucely_mwpdn_render_flowmattic_webhook_field() {
	sprucely_mwpdn_render_webhook_field( 'mwpdn_flowmattic_updates_webhook_url' );
}
