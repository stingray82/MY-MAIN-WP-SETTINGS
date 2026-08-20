<?php
/**
 * My MainWP Settings
 *
 * Personal MainWP Dashboard customisations and integrations.
 *
 * @package       MYMAINWPSE
 * @author        Stingray82
 * @license       GPLv2
 * @version       1.29
 *
 * @wordpress-plugin
 * Plugin Name:   My MainWP settings
 * Plugin URI:    https://github.com/stingray82/
 * Description:   My MainWP Custom Settings
 * Version:       1.29
 * Author:        Stingray82
 * Author URI:    https://github.com/stingray82/
 * Text Domain:   my-mainwp-settings
 * Domain Path:   /languages
 * License:       GPLv2
 * License URI:   https://www.gnu.org/licenses/gpl-2.0.html
 *
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Absolute path to this plugin directory.
 */
define( 'MAINWP_SETTINGS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );


/* ==========================================================================
 * LOAD REQUIRED SNIPPETS
 * ========================================================================== */

/**
 * Load the additional PHP snippets used by this plugin.
 *
 * Snippets are expected in inc/snippets/. Missing files are logged only when
 * WP_DEBUG is enabled so a missing optional snippet does not fatal the site.
 *
 * @return void
 */
function mainwp_settings_load_includes() {
    $includes = [
        'discord.php',
        'iawp_tokens.php',
        'icons.php',
    ];

    foreach ( $includes as $file ) {
        $file_path = MAINWP_SETTINGS_PLUGIN_DIR . 'inc/snippets/' . $file;

        if ( file_exists( $file_path ) ) {
            require_once $file_path;
        } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'MainWP Settings: Failed to load ' . $file_path );
        }
    }
}
add_action( 'plugins_loaded', 'mainwp_settings_load_includes' );


/* ==========================================================================
 * MAINWP PRO REPORT TOKENS
 * ========================================================================== */

/**
 * Add [website.updated.total] to MainWP Pro Reports.
 *
 * The value is the combined number of plugin, theme and WordPress core updates
 * recorded for the report period.
 *
 * @param array $tokens  Existing report tokens.
 * @param int   $site_id MainWP site ID.
 * @param array $data    MainWP report data.
 * @return array
 */
function rup_custom_update_total( $tokens, $site_id, $data ) {
    if ( is_array( $data ) && isset( $data[ $site_id ] ) ) {
        $body = $data[ $site_id ]['other_tokens_data']['body'] ?? [];
        $total = 0;

        $total += isset( $body['[plugin.updated.count]'] ) ? intval( $body['[plugin.updated.count]'] ) : 0;
        $total += isset( $body['[theme.updated.count]'] ) ? intval( $body['[theme.updated.count]'] ) : 0;
        $total += isset( $body['[wordpress.updated.count]'] ) ? intval( $body['[wordpress.updated.count]'] ) : 0;

        $tokens['[website.updated.total]'] = $total;
    }

    return $tokens;
}
add_filter( 'mainwp_pro_reports_addition_custom_tokens', 'rup_custom_update_total', 10, 3 );


/**
 * Add [ithemes.security.total] to MainWP Pro Reports.
 *
 * Combines Solid Security lockouts/blocks with the Cloudflare attack count
 * supplied by the cfmwp_all_analytics_data filter.
 *
 * @param array $tokens  Existing report tokens.
 * @param int   $site_id MainWP site ID.
 * @param array $data    MainWP report data.
 * @return array
 */
function rup_ithemes_security_tokens( $tokens, $site_id, $data ) {
    $all_analytics = apply_filters( 'cfmwp_all_analytics_data', [] );

    if ( is_array( $data ) && isset( $data[ $site_id ] ) ) {
        $body = $data[ $site_id ]['other_tokens_data']['body'] ?? [];
        $total = 0;

        $total += isset( $body['[ithemes.lockout.count]'] ) ? intval( $body['[ithemes.lockout.count]'] ) : 0;
        $total += isset( $body['[ithemes.blocked.count]'] ) ? intval( $body['[ithemes.blocked.count]'] ) : 0;
        $total += isset( $all_analytics['attacks'] ) ? intval( $all_analytics['attacks'] ) : 0;

        $tokens['[ithemes.security.total]'] = $total;
    }

    return $tokens;
}
add_filter( 'mainwp_pro_reports_addition_custom_tokens', 'rup_ithemes_security_tokens', 10, 3 );


/* ==========================================================================
 * ADMIN LOGIN
 * ========================================================================== */

/**
 * Send administrators to MainWP after logging in.
 *
 * @param string  $redirect_to Requested redirect URL.
 * @param string  $request     Original request.
 * @param WP_User $user        Logged-in user.
 * @return string
 */
function admin_default_page( $redirect_to, $request, $user ) {
    if ( isset( $user->roles ) && in_array( 'administrator', (array) $user->roles, true ) ) {
        return admin_url( 'admin.php?page=mainwp_tab' );
    }

    return $redirect_to;
}
add_filter( 'login_redirect', 'admin_default_page', 10, 3 );


/* ==========================================================================
 * MAINWP PRO REPORT EMAILS
 * ========================================================================== */

/**
 * Prevent MainWP Pro Report emails from attaching a generated PDF.
 *
 * @param mixed $attachments Existing attachment value.
 * @param mixed $html_to_pdf HTML-to-PDF handler.
 * @param mixed $report      Report object/data.
 * @param mixed $site_id     MainWP site ID.
 * @return string
 */
function mycustom_mainwp_pro_reports_email_attachments( $attachments, $html_to_pdf, $report, $site_id = false ) {
    return '';
}
add_filter( 'mainwp_pro_reports_email_attachments', 'mycustom_mainwp_pro_reports_email_attachments', 10, 4 );


/* ==========================================================================
 * OLD MAINWP SUBPAGE DEBUGGING - RETAINED FOR REFERENCE
 * ========================================================================== */

/*
function rup_log_bad_subpages( $subPages ) {
    if ( ! is_array( $subPages ) ) {
        return $subPages;
    }

    foreach ( $subPages as $sp ) {
        if ( ! is_array( $sp ) || empty( $sp['title'] ) || empty( $sp['slug'] ) ) {
            error_log( '[MainWP DEBUG] Bad subpage on hook: ' . current_filter() . ' -> ' . print_r( $sp, true ) );
        }
    }

    return $subPages;
}

add_filter( 'mainwp_subpages_left_menu', 'rup_log_bad_subpages', 9998, 1 );
add_filter( 'mainwp_getsubpages_sites', 'rup_log_bad_subpages', 9998, 1 );
add_filter( 'mainwp_getsubpages', 'rup_log_bad_subpages', 9998, 1 );
add_filter( 'mainwp_pageheader_subpages', 'rup_log_bad_subpages', 9998, 1 );
*/

/*
add_filter( 'mainwp_subpages_left_menu', function ( $subPages ) {
    if ( ! is_array( $subPages ) ) {
        return $subPages;
    }

    foreach ( $subPages as $i => $sp ) {
        if ( ! is_array( $sp ) || empty( $sp['title'] ) || empty( $sp['slug'] ) ) {
            unset( $subPages[ $i ] );
        }
    }

    return array_values( $subPages );
}, 9999, 1 );
*/

