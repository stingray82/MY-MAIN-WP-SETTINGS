<?php
/**
 * My MainWP settings
 *
 * @package       MYMAINWPSE
 * @author        Stingray82
 * @license       gplv2
 * @version       1.12
 *
 * @wordpress-plugin
 * Plugin Name:   My MainWP settings
 * Plugin URI:    https://github.com/stingray82/
 * Description:   My MainWP Custom Settings
 * Version:       1.12
 * Author:        Stingray82
 * Author URI:    https://github.com/stingray82/
 * Text Domain:   my-mainwp-settings
 * Domain Path:   /languages
 * License:       GPLv2
 * License URI:   https://www.gnu.org/licenses/gpl-2.0.html
 *
 * You should have received a copy of the GNU General Public License
 * along with My MainWP settings. If not, see <https://www.gnu.org/licenses/gpl-2.0.html/>.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;



/*
This code block includes added snippet php files 
*/


// Variables
define( 'MAINWP_SETTINGS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );


// LOAD NEEDED FILES 
// Load required files from the `inc` directory
function mainwp_settings_load_includes() {
    $includes = [
        'discord.php',
        'iawp_tokens.php',
    ];

    foreach ( $includes as $file ) {
        $file_path = MAINWP_SETTINGS_PLUGIN_DIR . 'inc/snippets/' . $file;
        
        if ( file_exists( $file_path ) ) {
            require_once $file_path;
        } else {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "MainWP Settings: Failed to load $file_path" );
            }
        }
    }
}

add_action( 'plugins_loaded', 'mainwp_settings_load_includes' );



/*
 Returns a New custom token [website.updated.total] which is the total of Wordpress, Plugins and Theme Updates in the month
*/
add_filter( 'mainwp_pro_reports_addition_custom_tokens', 'rup_custom_update_total', 10, 3 );
function rup_custom_update_total( $tokens, $site_id, $data ) {
    if(is_array($data) && isset($data[$site_id])){
         $total = 0;
         $total += isset($data[$site_id]['other_tokens_data']['body']['[plugin.updated.count]']) ? intval( $data[$site_id]['other_tokens_data']['body']['[plugin.updated.count]'] ) : 0;
         $total += isset($data[$site_id]['other_tokens_data']['body']['[theme.updated.count]']) ? intval( $data[$site_id]['other_tokens_data']['body']['[theme.updated.count]'] ) : 0;
         $total += isset($data[$site_id]['other_tokens_data']['body']['[wordpress.updated.count]']) ? intval( $data[$site_id]['other_tokens_data']['body']['[wordpress.updated.count]'] ) : 0;
         
         $tokens['[website.updated.total]'] = $total;
    }
    
    return $tokens;

}


/*
Set Admin Default Page to MAINWP
*/
function admin_default_page( $redirect_to, $request, $user ) {
    if ( isset( $user->roles ) && in_array( 'administrator', (array) $user->roles ) ) {
        return admin_url( 'admin.php?page=mainwp_tab' );
    }
    return $redirect_to;
}
add_filter( 'login_redirect', 'admin_default_page', 10, 3 );


//Stop PDF attachments in Pro-Report Email Only
add_filter( 'mainwp_pro_reports_email_attachments', 'mycustom_mainwp_pro_reports_email_attachments', 10, 4 );
function mycustom_mainwp_pro_reports_email_attachments( $attachments, $html_to_pdf, $report, $site_id = false ) {
   return '';
}

/* 
Cloudflare + Solid Security Custom Token [ithemes.security.total] 
*/
add_filter( 'mainwp_pro_reports_addition_custom_tokens', 'rup_ithemes_security_tokens', 10, 3 );
function rup_ithemes_security_tokens( $tokens, $site_id, $data ) {
    $all_analytics = apply_filters('cfmwp_all_analytics_data', array());
    // Log the $all_analytics array to the error log
    if (!empty($all_analytics)) {
        //error_log('CFMWP Analytics Data: ' . print_r($all_analytics, true));
    } else {
        //error_log('CFMWP Analytics Data: No data returned');
    }
    if(is_array($data) && isset($data[$site_id])){
         $total = 0;
         $total += isset($data[$site_id]['other_tokens_data']['body']['[ithemes.lockout.count]']) ? intval( $data[$site_id]['other_tokens_data']['body']['[ithemes.lockout.count]'] ) : 0;
         $total += isset($data[$site_id]['other_tokens_data']['body']['[ithemes.blocked.count]']) ? intval( $data[$site_id]['other_tokens_data']['body']['[ithemes.blocked.count]'] ) : 0; 
         $total += isset($all_analytics['attacks']) ? $all_analytics['attacks'] : 0;
         $tokens['[ithemes.security.total]'] = $total;

    }
    
    return $tokens;

}


// Add "Clear Icon Cache" link to admin bar
add_action('admin_bar_menu', function ($admin_bar) {
    if (!current_user_can('manage_options')) {
        return;
    }

    $url = add_query_arg('mainwp_clear_icon_cache', '1', admin_url());
    $url = wp_nonce_url($url, 'mainwp_clear_icon_cache');

    $admin_bar->add_menu(array(
        'id'    => 'mainwp-clear-icon-cache',
        'title' => 'Clear Icon Cache',
        'href'  => $url,
        'meta'  => array(
            'title' => 'Clear MainWP plugin & theme icon cache',
        ),
    ));
}, 100);

add_action('admin_init', function () {
    if (
        ! isset($_GET['mainwp_clear_icon_cache']) ||
        ! current_user_can('manage_options') ||
        ! check_admin_referer('mainwp_clear_icon_cache')
    ) {
        return;
    }

    global $wpdb;

    $table = $wpdb->prefix . 'mainwp_wp_options';

    /*
     * Clear MainWP plugin/theme icon caches.
     *
     * Once these are gone, MainWP will render the icons as uncached/expired
     * and its built-in mainwp_refresh_icon AJAX queue will fetch them again
     * from WordPress.org.
     */
    $result = $wpdb->query("
        DELETE FROM {$table}
        WHERE name IN (
            'plugins_icons',
            'themes_icons',
            'lasttime_clear_cached_plugins_icon',
            'lasttime_clear_cached_themes_icon'
        )
        AND wpid = 0
    ");

    /*
     * Reset our GitHub icon-map freshness checks as well.
     *
     * This means the next icon save will check lastupdate.json again
     * instead of potentially waiting for the 2-hour interval.
     */
    delete_option('_mainwp_icons_last_tag_plugin');
    delete_option('_mainwp_icons_last_check_plugin');

    delete_option('_mainwp_icons_last_tag_theme');
    delete_option('_mainwp_icons_last_check_theme');

    error_log('[MainWP ICON CLEAR] Deleted rows: ' . $result);
    error_log('[MainWP ICON CLEAR] Last query: ' . $wpdb->last_query);

    /*
     * Store notice across redirect.
     */
    set_transient(
        'mainwp_icons_cache_clear_notice_' . get_current_user_id(),
        array(
            'deleted' => intval($result),
        ),
        60
    );

    /*
     * Redirect to the MainWP Plugins screen.
     *
     * MainWP's own JS sees the now-uncached icons and calls
     * mainwp_refresh_icon -> MainWP_System_Utility::handle_get_icon()
     * -> WordPress.org API.
     */
    wp_safe_redirect(
        admin_url('admin.php?page=PluginsManage')
    );
    exit;
});

add_action('admin_notices', function () {

    if (!current_user_can('manage_options')) {
        return;
    }

    $key    = 'mainwp_icons_cache_clear_notice_' . get_current_user_id();
    $notice = get_transient($key);

    if (!$notice) {
        return;
    }

    delete_transient($key);

    echo '<div class="notice notice-success is-dismissible">';
    echo '<p>';
    echo '<strong>MainWP Icon Cache Cleared.</strong> ';
    echo 'Deleted rows: ' . intval($notice['deleted']) . '. ';
    echo 'MainWP will now refresh native WordPress.org icons as they are loaded.';
    echo '</p>';
    echo '</div>';
});


add_filter('mainwp_before_save_cached_icons', function ($cached_icons, $icon, $slug, $type, $custom_icon, $noexp) {

    // Use the SAME raw links as before
    $map_urls = [
        'plugin' => 'https://raw.githubusercontent.com/stingray82/mainwp-plugin-icons/main/icons-map.json',
        'theme'  => 'https://raw.githubusercontent.com/stingray82/mainwp-plugin-icons/main/themes-icons-map.json',
    ];
    $fresh_url = 'https://raw.githubusercontent.com/stingray82/mainwp-plugin-icons/main/lastupdate.json';

    if (!isset($map_urls[$type])) {
        return $cached_icons;
    }

    // Track freshness per type
    $opt_seen_tag   = "_mainwp_icons_last_tag_{$type}";
    $opt_last_check = "_mainwp_icons_last_check_{$type}";

    // Only ping the freshness file occasionally (still very small)
    $check_interval = 2 * HOUR_IN_SECONDS;
    $now            = time();
    $last_check     = (int) get_option($opt_last_check, 0);

    // Always do an initial check if we've never stored a tag
    $seen_tag     = get_option($opt_seen_tag);
    $should_check = ($now - $last_check) >= $check_interval || empty($seen_tag);

    $fresh_tag = null;
    if ($should_check) {
        $respFresh = wp_remote_get($fresh_url, [
            'headers' => ['User-Agent' => 'MainWP-Icons-Updater/1.0'],
            'timeout' => 8,
        ]);
        update_option($opt_last_check, $now);

        if (!is_wp_error($respFresh) && wp_remote_retrieve_response_code($respFresh) === 200) {
            $meta = json_decode(wp_remote_retrieve_body($respFresh), true);

            if (is_array($meta)) {
                // Build a per-type tag from structure
                // e.g. "1761430531|2025-10-26T20:45:58+00:00"
                $type_meta  = $meta[$type] ?? null;
                $mtime_part = is_array($type_meta) && !empty($type_meta['latest_mtime']) ? $type_meta['latest_mtime'] : '0';
                $gen_part   = !empty($meta['generated_at']) ? $meta['generated_at'] : '';
                $fresh_tag  = $mtime_part . '|' . $gen_part;
            }
        }
    }

    // If we have a current tag and it hasn't changed, skip fetching the big map
    if ($fresh_tag && $seen_tag && hash_equals($seen_tag, $fresh_tag)) {
        return $cached_icons;
    }

    // Fetch the map (same RAW URL as before)
    $json_url = $map_urls[$type];
    $response = wp_remote_get($json_url, [
        'headers' => ['User-Agent' => 'MainWP-Icons-Updater/1.0'],
        'timeout' => 10,
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        if (is_wp_error($response)) {
            error_log("[MainWP ICONS] Failed to fetch {$type} icon map: " . $response->get_error_message());
        } else {
            error_log("[MainWP ICONS] Non-200 fetching {$type} icon map.");
        }
        return $cached_icons;
    }

    $icons_map = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($icons_map)) {
        error_log("[MainWP ICONS] Invalid JSON for {$type} icons.");
        return $cached_icons;
    }

    // Refresh/update icons
    foreach ($icons_map as $custom_slug => $custom_icon_url) {
        $cached_icons[$custom_slug] = [
            'lasttime_cached' => time(),
            'path_custom'     => '',
            'path'            => urlencode($custom_icon_url),
        ];
    }

    // Store freshness tag after successful map update
    if ($fresh_tag) {
        update_option($opt_seen_tag, $fresh_tag);
    }

    return $cached_icons;

}, 10, 6);






/*// === MAINWP SUBPAGES FIRST-APPEARANCE DEBUGGER ===
function rup_log_bad_subpages($subPages){
    if (!is_array($subPages)) return $subPages;
    foreach ($subPages as $sp){
        if (!is_array($sp) || empty($sp['title']) || empty($sp['slug'])){
            error_log('[MainWP DEBUG] Bad subpage on hook: '. current_filter() .' -> '. print_r($sp, true));
        }
    }
    return $subPages;
}*/

//add_filter('mainwp_subpages_left_menu','rup_log_bad_subpages', 9998, 1);
//add_filter('mainwp_getsubpages_sites','rup_log_bad_subpages', 9998, 1);
//add_filter('mainwp_getsubpages',      'rup_log_bad_subpages', 9998, 1);
//add_filter('mainwp_pageheader_subpages','rup_log_bad_subpages',9998,1);

/*
add_filter('mainwp_subpages_left_menu', function ($subPages) {
    if (!is_array($subPages)) return $subPages;
    foreach ($subPages as $i => $sp) {
        if (!is_array($sp) || empty($sp['title']) || empty($sp['slug'])) {
            unset($subPages[$i]);
        }
    }
    return array_values($subPages);
}, 9999, 1); */


