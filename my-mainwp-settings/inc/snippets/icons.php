<?php
// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/* ==========================================================================
 * MAINWP ICON CACHE + NATIVE MAINWP REBUILD + CUSTOM GITHUB ICONS
 * ==========================================================================
 *
 * DESIGN GOAL
 * -----------
 *
 * Keep icon handling as close as possible to MainWP's own 6.1.7 behaviour.
 *
 * MainWP itself renders plugin icons on both:
 *
 *     admin.php?page=PluginsManage
 *
 * and:
 *
 *     admin.php?page=UpdatesManage&tab=plugins-updates
 *
 * through:
 *
 *     MainWP_System_Utility::get_plugin_icon()
 *
 * The native refresh path used by MainWP is:
 *
 *     cached-icon-expired
 *          |
 *          v
 *     mainwp_get_icon_start()
 *          |
 *          v
 *     AJAX action: mainwp_refresh_icon
 *          |
 *          v
 *     MainWP_Post_Handler::ajax_refresh_icon()
 *          |
 *          v
 *     MainWP_System_Utility::handle_get_icon()
 *          |
 *          v
 *     WordPress.org API
 *          |
 *          v
 *     MainWP_System_Utility::update_cached_icons()
 *          |
 *          v
 *     plugins_icons / themes_icons
 *
 * This plugin deliberately uses that SAME native AJAX action for Clear + Pull.
 *
 *
 * CUSTOM GITHUB ICONS
 * -------------------
 *
 * MainWP fires:
 *
 *     mainwp_before_save_cached_icons
 *
 * immediately before saving its native cache. We use that hook to merge the
 * custom GitHub map into the cache.
 *
 * The effective priority therefore remains:
 *
 *     GitHub custom icon
 *          |
 *          v
 *     WordPress.org native icon
 *          |
 *          v
 *     MainWP placeholder
 *
 *
 * ADMIN BAR
 * ---------
 *
 * "MainWP Icons" contains two hover actions:
 *
 *     Clear Icon Cache
 *
 *         Clears MainWP's native plugin/theme icon cache and resets the local
 *         GitHub map freshness state. The user remains on the same admin page.
 *
 *     Clear + Native Pull
 *
 *         Clears the same caches, builds a unique list of synced plugin/theme
 *         slugs, then opens MainWP -> Manage Plugins and processes those slugs
 *         one-by-one through MainWP's own mainwp_refresh_icon AJAX endpoint.
 *
 *         No custom WordPress.org API implementation is used.
 *
 *
 * MISSING ICON REPORT
 * -------------------
 *
 * After the native queue finishes, MainWP's completed cache is compared with
 * the GitHub maps.
 *
 * A slug is written to the CSV/JSON report only when:
 *
 *     no GitHub custom icon exists
 *
 * AND:
 *
 *     MainWP's native cache has no usable icon path
 *
 * This produces a practical backlog of icons that can be added to the GitHub
 * repository later.
 * ========================================================================== */


/**
 * Return the current admin URL without this plugin's icon-action parameters.
 *
 * Used by Clear Only so the action returns to the exact admin screen from
 * which it was clicked.
 *
 * @return string
 */
function rup_mainwp_icons_current_admin_url() {

    $request_uri = isset( $_SERVER['REQUEST_URI'] )
        ? wp_unslash( $_SERVER['REQUEST_URI'] )
        : '/wp-admin/';

    return remove_query_arg(
        [
            'rup_mainwp_icon_action',
            'rup_mainwp_native_pull',
            '_wpnonce',
        ],
        $request_uri
    );
}


/**
 * Add MainWP icon tools to the WordPress admin toolbar.
 *
 * @param WP_Admin_Bar $admin_bar WordPress admin toolbar instance.
 * @return void
 */
function rup_mainwp_icons_admin_bar( $admin_bar ) {

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $base_url = rup_mainwp_icons_current_admin_url();

    $clear_url = wp_nonce_url(
        add_query_arg(
            'rup_mainwp_icon_action',
            'clear',
            $base_url
        ),
        'rup_mainwp_icon_action'
    );

    $pull_url = wp_nonce_url(
        add_query_arg(
            'rup_mainwp_icon_action',
            'pull',
            $base_url
        ),
        'rup_mainwp_icon_action'
    );

    $admin_bar->add_menu(
        [
            'id'    => 'rup-mainwp-icons',
            'title' => 'MainWP Icons',
            'href'  => false,
            'meta'  => [
                'title' => 'MainWP plugin and theme icon tools',
            ],
        ]
    );

    $admin_bar->add_menu(
        [
            'parent' => 'rup-mainwp-icons',
            'id'     => 'rup-mainwp-icons-clear',
            'title'  => 'Clear Icon Cache',
            'href'   => $clear_url,
            'meta'   => [
                'title' => 'Clear MainWP plugin/theme icons and reset custom-map freshness',
            ],
        ]
    );

    $admin_bar->add_menu(
        [
            'parent' => 'rup-mainwp-icons',
            'id'     => 'rup-mainwp-icons-pull',
            'title'  => 'Clear + Native Pull',
            'href'   => $pull_url,
            'meta'   => [
                'title' => 'Clear caches then rebuild through MainWP native mainwp_refresh_icon AJAX',
            ],
        ]
    );
}
add_action( 'admin_bar_menu', 'rup_mainwp_icons_admin_bar', 100 );


/**
 * Clear MainWP's native icon caches and reset local GitHub freshness/cache data.
 *
 * MainWP_DB is used rather than direct SQL so the same storage API MainWP uses
 * for plugins_icons/themes_icons is used here too.
 *
 * We intentionally leave MainWP's housekeeping timestamps alone:
 *
 *     lasttime_clear_cached_plugins_icon
 *     lasttime_clear_cached_themes_icon
 *
 * @return array|WP_Error
 */
function rup_mainwp_icons_clear_caches() {

    if ( ! class_exists( '\MainWP\Dashboard\MainWP_DB' ) ) {
        return new WP_Error(
            'mainwp_db_missing',
            'MainWP database API is not available.'
        );
    }

    $db = \MainWP\Dashboard\MainWP_DB::instance();

    $plugins_before = $db->get_general_option(
        'plugins_icons',
        'array'
    );

    $themes_before = $db->get_general_option(
        'themes_icons',
        'array'
    );

    $plugin_count_before = is_array( $plugins_before )
        ? count( $plugins_before )
        : 0;

    $theme_count_before = is_array( $themes_before )
        ? count( $themes_before )
        : 0;

    $db->update_general_option(
        'plugins_icons',
        [],
        'array'
    );

    $db->update_general_option(
        'themes_icons',
        [],
        'array'
    );

    /*
     * Remove the local copies of the GitHub maps.
     *
     * The next MainWP icon save will therefore perform a fresh GitHub map
     * check and merge.
     */
    delete_transient( '_rup_mainwp_icons_map_plugin' );
    delete_transient( '_rup_mainwp_icons_map_theme' );

    delete_option( '_mainwp_icons_last_tag_plugin' );
    delete_option( '_mainwp_icons_last_check_plugin' );
    delete_option( '_mainwp_icons_last_tag_theme' );
    delete_option( '_mainwp_icons_last_check_theme' );

    error_log(
        sprintf(
            '[MainWP ICON CLEAR] Plugins: %d -> 0 | Themes: %d -> 0',
            $plugin_count_before,
            $theme_count_before
        )
    );

    return [
        'plugins_before' => $plugin_count_before,
        'themes_before'  => $theme_count_before,
    ];
}


/**
 * Build a unique native-refresh queue from MainWP's already-synced site data.
 *
 * No child sites are contacted here.
 *
 * Plugin slugs are normalised with MainWP_Utility::get_dir_slug(), matching the
 * exact conversion MainWP 6.1.7 uses on PluginsManage before calling
 * MainWP_System_Utility::get_plugin_icon().
 *
 * @return array
 */
function rup_mainwp_icons_build_native_queue() {

    if (
        ! class_exists( '\MainWP\Dashboard\MainWP_DB' ) ||
        ! class_exists( '\MainWP\Dashboard\MainWP_Utility' )
    ) {
        return [];
    }

    $sites = \MainWP\Dashboard\MainWP_DB::instance()->get_websites_for_current_user(
        [
            'fields' => [
                'plugins',
                'themes',
            ],
        ]
    );

    $plugins = [];
    $themes  = [];

    foreach ( $sites as $site ) {

        $site_plugins = ! empty( $site->plugins )
            ? json_decode( $site->plugins, true )
            : [];

        $site_themes = ! empty( $site->themes )
            ? json_decode( $site->themes, true )
            : [];

        if ( is_array( $site_plugins ) ) {

            foreach ( $site_plugins as $plugin ) {

                if ( ! is_array( $plugin ) ) {
                    continue;
                }

                $plugin_file = '';

                if ( ! empty( $plugin['slug'] ) ) {
                    $plugin_file = wp_unslash(
                        (string) $plugin['slug']
                    );
                } elseif ( ! empty( $plugin['file'] ) ) {
                    $plugin_file = wp_unslash(
                        (string) $plugin['file']
                    );
                }

                $plugin_file = str_replace(
                    '\\',
                    '/',
                    $plugin_file
                );

                if ( '' === $plugin_file ) {
                    continue;
                }

                $slug = \MainWP\Dashboard\MainWP_Utility::get_dir_slug(
                    $plugin_file
                );

                $slug = sanitize_key( $slug );

                if ( '' !== $slug && '.' !== $slug ) {
                    $plugins[ $slug ] = true;
                }
            }
        }

        if ( is_array( $site_themes ) ) {

            foreach ( $site_themes as $theme ) {

                if ( ! is_array( $theme ) ) {
                    continue;
                }

                $slug = ! empty( $theme['slug'] )
                    ? sanitize_key( $theme['slug'] )
                    : '';

                if ( '' !== $slug && '.' !== $slug ) {
                    $themes[ $slug ] = true;
                }
            }
        }
    }

    ksort( $plugins );
    ksort( $themes );

    $queue = [];

    foreach ( array_keys( $plugins ) as $slug ) {
        $queue[] = [
            'type' => 'plugin',
            'slug' => $slug,
        ];
    }

    foreach ( array_keys( $themes ) as $slug ) {
        $queue[] = [
            'type' => 'theme',
            'slug' => $slug,
        ];
    }

    return $queue;
}


/**
 * Process Clear Icon Cache / Clear + Native Pull.
 *
 * Clear Only:
 *     Clears caches and returns to the current admin page.
 *
 * Clear + Native Pull:
 *     Clears caches, builds the unique queue, stores it as a short-lived
 *     per-user run state, then deliberately opens PluginsManage.
 *
 * PluginsManage is used for the pull screen because it loads MainWP's normal
 * JavaScript and security nonce for the native mainwp_refresh_icon AJAX action.
 *
 * @return void
 */
function rup_mainwp_icons_process_admin_action() {

    if ( empty( $_GET['rup_mainwp_icon_action'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    check_admin_referer( 'rup_mainwp_icon_action' );

    $action = sanitize_key(
        wp_unslash( $_GET['rup_mainwp_icon_action'] )
    );

    if ( ! in_array( $action, [ 'clear', 'pull' ], true ) ) {
        return;
    }

    $result = rup_mainwp_icons_clear_caches();

    if ( is_wp_error( $result ) ) {

        set_transient(
            '_rup_mainwp_icons_notice_' . get_current_user_id(),
            [
                'type'    => 'error',
                'message' => $result->get_error_message(),
            ],
            MINUTE_IN_SECONDS
        );

        wp_safe_redirect(
            rup_mainwp_icons_current_admin_url()
        );

        exit;
    }

    if ( 'clear' === $action ) {

        set_transient(
            '_rup_mainwp_icons_notice_' . get_current_user_id(),
            [
                'type'           => 'clear',
                'plugins_before' => $result['plugins_before'],
                'themes_before'  => $result['themes_before'],
            ],
            MINUTE_IN_SECONDS
        );

        wp_safe_redirect(
            rup_mainwp_icons_current_admin_url()
        );

        exit;
    }

    $queue  = rup_mainwp_icons_build_native_queue();
    $run_id = wp_generate_uuid4();

    update_option(
        '_rup_mainwp_native_icon_run_' . get_current_user_id(),
        [
            'run_id'          => $run_id,
            'started_at'      => current_time( 'mysql' ),
            'queue'           => $queue,
            'total'           => count( $queue ),
            'plugins_before'  => $result['plugins_before'],
            'themes_before'   => $result['themes_before'],
        ],
        false
    );

    /*
     * Clear + Native Pull intentionally moves to PluginsManage.
     *
     * Unlike Clear Only, this is not an accidental redirect: the page is the
     * native MainWP plugin-management context that already loads
     * mainwp_secure_data() and the mainwp_refresh_icon security nonce.
     */
    $redirect = add_query_arg(
        [
            'page'                   => 'PluginsManage',
            'rup_mainwp_native_pull' => rawurlencode( $run_id ),
        ],
        admin_url( 'admin.php' )
    );

    wp_safe_redirect( $redirect );
    exit;
}
add_action(
    'admin_init',
    'rup_mainwp_icons_process_admin_action'
);


/**
 * Prevent PluginsManage's own automatic expired-icon queue from running in
 * parallel with the explicit Clear + Native Pull queue.
 *
 * Both would use the same native MainWP AJAX endpoint, but running two queues
 * simultaneously can cause overlapping read/modify/write operations on the
 * single plugins_icons/themes_icons JSON options.
 *
 * During the explicit pull we therefore return MainWP's normal placeholder
 * markup WITHOUT the cached-icon-expired class.
 *
 * Once the pull completes the page is reloaded without the run token and
 * MainWP renders the finished native cache normally.
 *
 * @param string $icon Existing filtered icon HTML.
 * @param string $slug Plugin/theme slug.
 * @param string $type plugin|theme.
 * @return string
 */
function rup_mainwp_icons_suppress_parallel_native_queue(
    $icon,
    $slug,
    $type
) {

    if ( empty( $_GET['rup_mainwp_native_pull'] ) ) {
        return $icon;
    }

    $run_id = sanitize_text_field(
        wp_unslash( $_GET['rup_mainwp_native_pull'] )
    );

    $state = get_option(
        '_rup_mainwp_native_icon_run_' . get_current_user_id(),
        []
    );

    if (
        ! is_array( $state ) ||
        empty( $state['run_id'] ) ||
        ! hash_equals(
            (string) $state['run_id'],
            (string) $run_id
        )
    ) {
        return $icon;
    }

    if ( 'plugin' === $type ) {
        return '<i style="font-size:17px" class="plug circular inverted icon"></i>';
    }

    if ( 'theme' === $type ) {
        return '<i style="font-size:17px" class="tint circular inverted icon"></i>';
    }

    return $icon;
}
add_filter(
    'mainwp_get_plugin_theme_icon',
    'rup_mainwp_icons_suppress_parallel_native_queue',
    1,
    3
);


/**
 * Return the configured remote GitHub map URL for a plugin/theme type.
 *
 * @param string $type plugin|theme.
 * @return string
 */
function rup_mainwp_icons_map_url( $type ) {

    $urls = [
        'plugin' => 'https://raw.githubusercontent.com/stingray82/mainwp-plugin-icons/main/icons-map.json',
        'theme'  => 'https://raw.githubusercontent.com/stingray82/mainwp-plugin-icons/main/themes-icons-map.json',
    ];

    return $urls[ $type ] ?? '';
}


/**
 * Get the GitHub custom icon map.
 *
 * The large JSON map is cached locally in a transient. lastupdate.json is
 * checked at most every two hours unless Clear Icon Cache resets the state.
 *
 * @param string $type plugin|theme.
 * @return array
 */
function rup_mainwp_icons_get_custom_map( $type ) {

    if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
        return [];
    }

    $map_url = rup_mainwp_icons_map_url( $type );

    if ( '' === $map_url ) {
        return [];
    }

    $fresh_url = 'https://raw.githubusercontent.com/stingray82/mainwp-plugin-icons/main/lastupdate.json';

    $map_transient = "_rup_mainwp_icons_map_{$type}";
    $seen_key      = "_mainwp_icons_last_tag_{$type}";
    $check_key     = "_mainwp_icons_last_check_{$type}";

    $icons_map = get_transient(
        $map_transient
    );

    $have_map = is_array( $icons_map );

    $last_check = (int) get_option(
        $check_key,
        0
    );

    $seen_tag = (string) get_option(
        $seen_key,
        ''
    );

    $now = time();

    $should_check =
        ! $have_map ||
        empty( $seen_tag ) ||
        ( $now - $last_check ) >= 2 * HOUR_IN_SECONDS;

    $fresh_tag    = '';
    $download_map = ! $have_map;

    if ( $should_check ) {

        $response = wp_remote_get(
            $fresh_url,
            [
                'headers' => [
                    'User-Agent' => 'MainWP-Icons-Updater/1.27',
                ],
                'timeout' => 8,
            ]
        );

        update_option(
            $check_key,
            $now,
            false
        );

        if (
            ! is_wp_error( $response ) &&
            200 === wp_remote_retrieve_response_code( $response )
        ) {

            $meta = json_decode(
                wp_remote_retrieve_body( $response ),
                true
            );

            if ( is_array( $meta ) ) {

                $type_meta = $meta[ $type ] ?? null;

                $mtime = (
                    is_array( $type_meta ) &&
                    ! empty( $type_meta['latest_mtime'] )
                )
                    ? (string) $type_meta['latest_mtime']
                    : '0';

                $generated = ! empty( $meta['generated_at'] )
                    ? (string) $meta['generated_at']
                    : '';

                $fresh_tag = $mtime . '|' . $generated;

                if (
                    ! $have_map ||
                    empty( $seen_tag ) ||
                    ! hash_equals(
                        $seen_tag,
                        $fresh_tag
                    )
                ) {
                    $download_map = true;
                }
            }
        }
    }

    if ( $download_map ) {

        $response = wp_remote_get(
            $map_url,
            [
                'headers' => [
                    'User-Agent' => 'MainWP-Icons-Updater/1.27',
                ],
                'timeout' => 10,
            ]
        );

        if (
            ! is_wp_error( $response ) &&
            200 === wp_remote_retrieve_response_code( $response )
        ) {

            $downloaded = json_decode(
                wp_remote_retrieve_body( $response ),
                true
            );

            if ( is_array( $downloaded ) ) {

                $icons_map = $downloaded;

                set_transient(
                    $map_transient,
                    $icons_map,
                    DAY_IN_SECONDS
                );

                if ( '' !== $fresh_tag ) {
                    update_option(
                        $seen_key,
                        $fresh_tag,
                        false
                    );
                }
            }
        }
    }

    return is_array( $icons_map )
        ? $icons_map
        : [];
}


/**
 * Merge the complete custom GitHub map into MainWP's native icon cache.
 *
 * This intentionally mirrors the behaviour of the original working snippet:
 * whenever MainWP saves a plugin/theme icon, the relevant GitHub map is merged
 * into the same native MainWP cache array before it is persisted.
 *
 * Therefore a custom GitHub entry overrides a matching WordPress.org icon while
 * all non-matching native MainWP icons remain untouched.
 *
 * @param array  $cached_icons MainWP icon cache about to be saved.
 * @param string $icon         Icon currently being saved by MainWP.
 * @param string $slug         Current slug.
 * @param string $type         plugin|theme.
 * @param bool   $custom_icon  MainWP custom-upload flag.
 * @param bool   $noexp        MainWP no-expiry flag.
 * @return array
 */
function rup_mainwp_icons_merge_github_map(
    $cached_icons,
    $icon,
    $slug,
    $type,
    $custom_icon,
    $noexp
) {

    /*
     * During our explicit native MainWP pull, keep the native cache PURE.
     *
     * MainWP's fetch_wp_org_icons() falls back to an already cached path when
     * WordPress.org returns no icon. If the full GitHub map is merged during
     * every pull request, a GitHub path can therefore be mistaken for a
     * successful native WordPress.org result.
     *
     * The native queue is allowed to finish first. The finaliser then merges
     * the GitHub maps into the completed native cache ONCE.
     */
    if (
        isset( $_POST['rup_mainwp_native_pull_request'] ) &&
        '1' === sanitize_text_field(
            wp_unslash( $_POST['rup_mainwp_native_pull_request'] )
        )
    ) {
        return $cached_icons;
    }

    if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
        return $cached_icons;
    }

    if ( ! is_array( $cached_icons ) ) {
        $cached_icons = [];
    }

    $icons_map = rup_mainwp_icons_get_custom_map(
        $type
    );

    if ( empty( $icons_map ) ) {
        return $cached_icons;
    }

    $now = time();

    foreach ( $icons_map as $custom_slug => $custom_icon_url ) {

        if (
            ! is_string( $custom_slug ) ||
            '' === $custom_slug ||
            ! is_string( $custom_icon_url ) ||
            '' === trim( $custom_icon_url )
        ) {
            continue;
        }

        $cached_icons[ $custom_slug ] = [
            'lasttime_cached' => $now,
            'path_custom'     => '',
            'path'            => rawurlencode(
                trim( $custom_icon_url )
            ),
        ];
    }

    return $cached_icons;
}
add_filter(
    'mainwp_before_save_cached_icons',
    'rup_mainwp_icons_merge_github_map',
    10,
    6
);


/**
 * Create the missing-icon CSV and JSON reports.
 *
 * @param array $missing Missing-icon rows.
 * @return array Report URLs.
 */
function rup_mainwp_icons_write_missing_reports( $missing ) {

    $uploads = wp_upload_dir();

    if ( ! empty( $uploads['error'] ) ) {
        error_log(
            '[MainWP ICONS] Cannot create missing-icon report: ' .
            $uploads['error']
        );

        return [];
    }

    $dir = trailingslashit(
        $uploads['basedir']
    ) . 'mainwp-icon-reports';

    $url = trailingslashit(
        $uploads['baseurl']
    ) . 'mainwp-icon-reports';

    if (
        ! is_dir( $dir ) &&
        ! wp_mkdir_p( $dir )
    ) {
        error_log(
            '[MainWP ICONS] Cannot create report directory: ' .
            $dir
        );

        return [];
    }

    usort(
        $missing,
        function ( $a, $b ) {

            $type_compare = strcmp(
                $a['type'],
                $b['type']
            );

            if ( 0 !== $type_compare ) {
                return $type_compare;
            }

            return strcmp(
                $a['slug'],
                $b['slug']
            );
        }
    );

    $csv_path  = trailingslashit( $dir ) . 'missing-icons.csv';
    $json_path = trailingslashit( $dir ) . 'missing-icons.json';

    $handle = fopen(
        $csv_path,
        'w'
    );

    if ( false !== $handle ) {

        fputcsv(
            $handle,
            [
                'type',
                'slug',
                'reason',
            ]
        );

        foreach ( $missing as $row ) {
            fputcsv(
                $handle,
                [
                    $row['type'],
                    $row['slug'],
                    $row['reason'],
                ]
            );
        }

        fclose( $handle );
    }

    file_put_contents(
        $json_path,
        wp_json_encode(
            [
                'generated_at' => current_time( 'c' ),
                'count'        => count( $missing ),
                'description'  => 'Slugs with no usable MainWP native icon and no matching GitHub custom icon.',
                'missing'      => array_values( $missing ),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        )
    );

    return [
        'csv'  => trailingslashit( $url ) . 'missing-icons.csv',
        'json' => trailingslashit( $url ) . 'missing-icons.json',
    ];
}


/**
 * Finalise a Clear + Native Pull run.
 *
 * The WordPress.org requests themselves have already been performed through
 * MainWP's OWN mainwp_refresh_icon AJAX action.
 *
 * This endpoint only:
 *
 * - reads MainWP's finished plugins_icons/themes_icons cache;
 * - compares every queued slug with the appropriate GitHub map;
 * - generates the missing-icon reports;
 * - returns the completion/report data to the current PluginsManage page.
 *
 * The browser then calls MainWP's own mainwp_fetch_plugins() function so the
 * existing table is rebuilt exactly as if "Show Plugins" had been clicked.
 *
 * @return void
 */
function rup_mainwp_icons_ajax_finalize_native_pull() {

    check_ajax_referer(
        'rup_mainwp_icons_finalize',
        'nonce'
    );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error(
            [ 'message' => 'Permission denied.' ],
            403
        );
    }

    $run_id = isset( $_POST['run_id'] )
        ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) )
        : '';

    $user_id   = get_current_user_id();
    $state_key = '_rup_mainwp_native_icon_run_' . $user_id;

    $state = get_option(
        $state_key,
        []
    );

    if (
        ! is_array( $state ) ||
        empty( $state['run_id'] ) ||
        '' === $run_id ||
        ! hash_equals(
            (string) $state['run_id'],
            (string) $run_id
        )
    ) {
        wp_send_json_error(
            [ 'message' => 'This native icon pull is no longer active.' ],
            409
        );
    }

    if ( ! class_exists( '\MainWP\Dashboard\MainWP_DB' ) ) {
        wp_send_json_error(
            [ 'message' => 'MainWP database API is not available.' ],
            500
        );
    }

    $db = \MainWP\Dashboard\MainWP_DB::instance();

    /*
     * At this point the cache contains ONLY the results produced by MainWP's
     * native mainwp_refresh_icon queue. GitHub was deliberately suppressed
     * during those AJAX requests.
     */
    $plugin_native_cache = $db->get_general_option(
        'plugins_icons',
        'array'
    );

    $theme_native_cache = $db->get_general_option(
        'themes_icons',
        'array'
    );

    if ( ! is_array( $plugin_native_cache ) ) {
        $plugin_native_cache = [];
    }

    if ( ! is_array( $theme_native_cache ) ) {
        $theme_native_cache = [];
    }

    $plugin_map = rup_mainwp_icons_get_custom_map( 'plugin' );
    $theme_map  = rup_mainwp_icons_get_custom_map( 'theme' );

    $final_plugin_cache = $plugin_native_cache;
    $final_theme_cache  = $theme_native_cache;

    $missing      = [];
    $native_found = [];
    $custom_found = [];

    foreach ( $state['queue'] as $item ) {

        if ( empty( $item['slug'] ) || empty( $item['type'] ) ) {
            continue;
        }

        $slug = (string) $item['slug'];
        $type = (string) $item['type'];

        $native_cache = 'plugin' === $type
            ? $plugin_native_cache
            : $theme_native_cache;

        $map = 'plugin' === $type
            ? $plugin_map
            : $theme_map;

        $entry = isset( $native_cache[ $slug ] ) &&
            is_array( $native_cache[ $slug ] )
                ? $native_cache[ $slug ]
                : [];

        $native_path = ! empty( $entry['path'] )
            ? rawurldecode( (string) $entry['path'] )
            : '';

        $has_native = '' !== trim( $native_path );

        $has_custom = isset( $map[ $slug ] ) &&
            is_string( $map[ $slug ] ) &&
            '' !== trim( $map[ $slug ] );

        if ( $has_native ) {
            $native_found[ $type . ':' . $slug ] = [
                'type' => $type,
                'slug' => $slug,
                'url'  => $native_path,
            ];
        }

        if ( $has_custom ) {
            $custom_found[ $type . ':' . $slug ] = [
                'type' => $type,
                'slug' => $slug,
                'url'  => trim( $map[ $slug ] ),
            ];
        }

        if ( ! $has_native && ! $has_custom ) {
            $missing[] = [
                'type'   => $type,
                'slug'   => $slug,
                'reason' => 'No icon returned by MainWP native mainwp_refresh_icon and not in GitHub map',
            ];
        }
    }

    /*
     * Merge GitHub overrides ONCE after the native MainWP pull has completely
     * finished. This restores the behaviour/priority of the original snippet
     * without contaminating MainWP's native lookup process.
     */
    $now = time();

    foreach ( $plugin_map as $slug => $url ) {
        if ( ! is_string( $slug ) || ! is_string( $url ) || '' === trim( $url ) ) {
            continue;
        }

        $final_plugin_cache[ $slug ] = [
            'lasttime_cached' => $now,
            'path_custom'     => '',
            'path'            => rawurlencode( trim( $url ) ),
        ];
    }

    foreach ( $theme_map as $slug => $url ) {
        if ( ! is_string( $slug ) || ! is_string( $url ) || '' === trim( $url ) ) {
            continue;
        }

        $final_theme_cache[ $slug ] = [
            'lasttime_cached' => $now,
            'path_custom'     => '',
            'path'            => rawurlencode( trim( $url ) ),
        ];
    }

    $db->update_general_option(
        'plugins_icons',
        $final_plugin_cache,
        'array'
    );

    $db->update_general_option(
        'themes_icons',
        $final_theme_cache,
        'array'
    );

    $report_urls = rup_mainwp_icons_write_missing_reports(
        $missing
    );

    /*
     * Also write a diagnostic JSON showing exactly what MainWP's native queue
     * returned before the GitHub maps were merged. This makes future debugging
     * possible without guessing from the UI.
     */
    $uploads = wp_upload_dir();
    $diagnostic_url = '';

    if ( empty( $uploads['error'] ) ) {
        $dir = trailingslashit( $uploads['basedir'] ) . 'mainwp-icon-reports';

        if ( is_dir( $dir ) || wp_mkdir_p( $dir ) ) {
            $diag_path = trailingslashit( $dir ) . 'native-icon-diagnostics.json';

            file_put_contents(
                $diag_path,
                wp_json_encode(
                    [
                        'generated_at'       => current_time( 'c' ),
                        'queued'             => intval( $state['total'] ?? count( $state['queue'] ) ),
                        'native_icon_count'  => count( $native_found ),
                        'github_icon_count'  => count( $custom_found ),
                        'missing_count'      => count( $missing ),
                        'native_icons'       => array_values( $native_found ),
                        'github_overrides'   => array_values( $custom_found ),
                        'missing'            => array_values( $missing ),
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                )
            );

            $diagnostic_url =
                trailingslashit( $uploads['baseurl'] ) .
                'mainwp-icon-reports/native-icon-diagnostics.json';
        }
    }

    $completion = [
        'total'              => intval( $state['total'] ?? count( $state['queue'] ) ),
        'native_count'       => count( $native_found ),
        'custom_count'       => count( $custom_found ),
        'plugin_cache_count' => count( $final_plugin_cache ),
        'theme_cache_count'  => count( $final_theme_cache ),
        'missing_count'      => count( $missing ),
        'report_urls'        => $report_urls,
        'diagnostic_url'     => $diagnostic_url,
    ];

    /*
     * The pull page already has MainWP's PluginsManage JavaScript loaded.
     *
     * Do NOT redirect here. PluginsManage renders its table through AJAX and a
     * normal page redirect does not guarantee that the currently displayed
     * table is rebuilt after the icon cache changes.
     *
     * Return the result to the browser. The runner will:
     *
     * 1. remove the temporary pull token from the browser URL;
     * 2. show the report links/summary;
     * 3. call MainWP's own global mainwp_fetch_plugins() function.
     *
     * That is exactly the function used by MainWP's "Show Plugins" button.
     */
    delete_option(
        '_rup_mainwp_icon_completion_' . $user_id
    );

    delete_option(
        $state_key
    );

    wp_send_json_success(
        array_merge(
            $completion,
            [
                'clean_url' => admin_url( 'admin.php?page=PluginsManage' ),
            ]
        )
    );
}
add_action(
    'wp_ajax_rup_mainwp_icons_finalize_native_pull',
    'rup_mainwp_icons_ajax_finalize_native_pull'
);



/**
 * Run the explicit native MainWP pull queue in the browser.
 *
 * IMPORTANT:
 *
 * Each icon request is sent to MainWP's own:
 *
 *     action = mainwp_refresh_icon
 *
 * using MainWP's own:
 *
 *     mainwp_secure_data()
 *
 * Therefore the actual fetch/save path is the same one MainWP itself uses when
 * it sees .cached-icon-expired.
 *
 * This script only serialises the queue and shows progress.
 *
 * @return void
 */



/**
 * Render and run the explicit native icon pull from MainWP's own page-layout
 * hook: mainwp_after_header.
 *
 * WHY THIS HOOK
 * -------------
 *
 * MainWP 6.1.7 fires:
 *
 *     do_action( 'mainwp_after_header', $websites );
 *
 * from MainWP_UI while building Dashboard pages.
 *
 * This is more reliable here than generic WordPress admin_notices/footer
 * hooks, and it avoids the timing problem of trying to attach inline
 * JavaScript to the "mainwp" script handle before MainWP has registered it.
 *
 * The progress box is rendered immediately by PHP. The JavaScript then waits
 * until MainWP's own globalThis.mainwp_secure_data() helper is available and
 * processes every queued slug through MainWP's native:
 *
 *     action = mainwp_refresh_icon
 *
 * No custom WordPress.org fetcher is used.
 *
 * @return void
 */
function rup_mainwp_icons_native_pull_after_header() {

    static $rendered = false;

    if ( $rendered ) {
        return;
    }

    if (
        ! current_user_can( 'manage_options' ) ||
        empty( $_GET['rup_mainwp_native_pull'] )
    ) {
        return;
    }

    if (
        empty( $_GET['page'] ) ||
        'PluginsManage' !== sanitize_text_field(
            wp_unslash( $_GET['page'] )
        )
    ) {
        return;
    }

    $rendered = true;

    $run_id = sanitize_text_field(
        wp_unslash( $_GET['rup_mainwp_native_pull'] )
    );

    $state = get_option(
        '_rup_mainwp_native_icon_run_' . get_current_user_id(),
        []
    );

    if (
        ! is_array( $state ) ||
        empty( $state['run_id'] ) ||
        ! hash_equals(
            (string) $state['run_id'],
            (string) $run_id
        )
    ) {
        ?>
        <div id="rup-mainwp-native-pull-notice" class="ui negative message">
            <div class="header">MainWP Native Icon Pull</div>
            <p>
                The pull token is present in the URL, but the saved pull state
                could not be found. Run <strong>MainWP Icons → Clear + Native Pull</strong>
                again.
            </p>
        </div>
        <?php
        return;
    }

    $queue = isset( $state['queue'] ) &&
        is_array( $state['queue'] )
            ? array_values( $state['queue'] )
            : [];

    $finalize_nonce = wp_create_nonce(
        'rup_mainwp_icons_finalize'
    );

    ?>
    <div id="rup-mainwp-native-pull-notice" class="ui info message">
        <div class="header">MainWP Native Icon Pull</div>
        <p id="rup-mainwp-native-pull-status">
            Run accepted. Preparing to process
            <?php echo intval( count( $queue ) ); ?>
            unique plugin/theme slugs through MainWP's native icon updater...
        </p>
    </div>

    <script type="text/javascript">
    (function () {
        'use strict';

        var queue = <?php echo wp_json_encode( $queue, JSON_UNESCAPED_SLASHES ); ?>;
        var runId = <?php echo wp_json_encode( $run_id ); ?>;
        var finalizeNonce = <?php echo wp_json_encode( $finalize_nonce ); ?>;

        var total = queue.length;
        var index = 0;
        var nativeSuccess = 0;
        var nativeFailed = 0;
        var started = false;

        function withJQuery(callback) {
            if (typeof window.jQuery === 'function') {
                callback(window.jQuery);
                return;
            }

            window.setTimeout(function () {
                withJQuery(callback);
            }, 100);
        }

        withJQuery(function ($) {

            function notice() {
                return $('#rup-mainwp-native-pull-notice');
            }

            function setStatus(message) {
                $('#rup-mainwp-native-pull-status').text(message);
            }

            function setError(message) {
                notice()
                    .removeClass('info')
                    .addClass('negative');

                setStatus(message);
            }

            function finaliseRun() {

                setStatus(
                    'MainWP native queue complete. Reading MainWP cache and creating missing-icon report...'
                );

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    timeout: 30000,
                    data: {
                        action: 'rup_mainwp_icons_finalize_native_pull',
                        nonce: finalizeNonce,
                        run_id: runId,
                        native_success: nativeSuccess,
                        native_failed: nativeFailed
                    }
                })
                .done(function (response) {

                    if (
                        response &&
                        response.success &&
                        response.data
                    ) {
                        var result = response.data;

                        /*
                         * Remove the temporary native-pull token without
                         * reloading the page.
                         */
                        if (result.clean_url && window.history && window.history.replaceState) {
                            window.history.replaceState(
                                {},
                                document.title,
                                result.clean_url
                            );
                        }

                        var reportLinks = [];

                        if (result.report_urls && result.report_urls.csv) {
                            reportLinks.push(
                                '<a class="ui mini button" target="_blank" rel="noopener" href="' +
                                $('<div>').text(result.report_urls.csv).html() +
                                '">Missing Icons CSV</a>'
                            );
                        }

                        if (result.report_urls && result.report_urls.json) {
                            reportLinks.push(
                                '<a class="ui mini button" target="_blank" rel="noopener" href="' +
                                $('<div>').text(result.report_urls.json).html() +
                                '">Missing Icons JSON</a>'
                            );
                        }

                        if (result.diagnostic_url) {
                            reportLinks.push(
                                '<a class="ui mini button" target="_blank" rel="noopener" href="' +
                                $('<div>').text(result.diagnostic_url).html() +
                                '">Native Diagnostics JSON</a>'
                            );
                        }

                        notice()
                            .removeClass('info negative')
                            .addClass('positive');

                        $('#rup-mainwp-native-pull-status').html(
                            'Complete. Processed <strong>' +
                            parseInt(result.total || total, 10) +
                            '</strong> slugs. MainWP native cache returned <strong>' +
                            parseInt(result.native_count || 0, 10) +
                            '</strong> icons; GitHub covers <strong>' +
                            parseInt(result.custom_count || 0, 10) +
                            '</strong>; <strong>' +
                            parseInt(result.missing_count || 0, 10) +
                            '</strong> are missing from both sources.<br>' +
                            'Final cache: <strong>' +
                            parseInt(result.plugin_cache_count || 0, 10) +
                            '</strong> plugin icons / <strong>' +
                            parseInt(result.theme_cache_count || 0, 10) +
                            '</strong> theme icons.' +
                            (reportLinks.length
                                ? '<br><span style="display:inline-block;margin-top:8px;">' +
                                  reportLinks.join(' ') +
                                  '</span>'
                                : '')
                        );

                        /*
                         * This is the key v1.27 change.
                         *
                         * MainWP 6.1.7 wires the "Show Plugins" button to:
                         *
                         *     mainwp_fetch_plugins();
                         *
                         * That function sends mainwp_plugins_search using the
                         * CURRENT selected sites/groups/clients/status and then
                         * replaces #mainwp-plugins-content with freshly
                         * rendered HTML.
                         *
                         * Trigger that same native refresh now that the icon
                         * cache and GitHub merge are complete.
                         */
                        if (typeof globalThis.mainwp_fetch_plugins === 'function') {
                            globalThis.mainwp_fetch_plugins();

                            $('#rup-mainwp-native-pull-status').append(
                                '<br><em>Refreshing the Plugins table through MainWP…</em>'
                            );
                        } else {
                            $('#rup-mainwp-native-pull-status').append(
                                '<br><strong>Could not automatically refresh the plugin table.</strong> ' +
                                'Use MainWP\'s Show Plugins button once.'
                            );
                        }

                        return;
                    }

                    var message =
                        response &&
                        response.data &&
                        response.data.message
                            ? response.data.message
                            : 'Native pulls finished, but finalising the report failed.';

                    setError(message);
                })
                .fail(function (xhr, textStatus) {

                    if ('timeout' === textStatus) {
                        setError(
                            'All native icon requests finished, but the report finalisation request timed out. ' +
                            'The MainWP cache itself may already be rebuilt; reload Manage Plugins once and run Clear + Native Pull again only if needed.'
                        );
                        return;
                    }

                    setError(
                        'Native pulls finished, but finalising the report failed (HTTP ' +
                        xhr.status +
                        ').'
                    );
                });
            }

            function processNext() {

                if (index >= total) {
                    finaliseRun();
                    return;
                }

                var item = queue[index];

                setStatus(
                    'Requesting through MainWP: ' +
                    (index + 1) +
                    ' / ' +
                    total +
                    ' — ' +
                    item.type +
                    ' / ' +
                    item.slug +
                    ' (completed ' +
                    index +
                    ' / ' +
                    total +
                    ')'
                );

                var data = globalThis.mainwp_secure_data({
                    action: 'mainwp_refresh_icon',
                    slug: item.slug,
                    type: item.type,
                    rup_mainwp_native_pull_request: '1'
                });

                /*
                 * MainWP's handler can ultimately wait on WordPress.org.
                 *
                 * A single remote lookup must not be allowed to strand the
                 * entire rebuild forever. Treat a request that has not
                 * completed within 20 seconds as a native miss/failure and
                 * continue with the next queued slug.
                 */
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: data,
                    timeout: 20000
                })
                .done(function (response) {

                    if (
                        typeof response === 'string' &&
                        $.trim(response) === 'success'
                    ) {
                        nativeSuccess++;
                    } else {
                        nativeFailed++;
                    }
                })
                .fail(function (xhr, textStatus) {

                    nativeFailed++;

                    if ('timeout' === textStatus) {
                        console.warn(
                            '[MainWP ICONS] Native icon request timed out:',
                            item.type,
                            item.slug
                        );
                    }
                })
                .always(function () {

                    index++;

                    if (index < total) {
                        setStatus(
                            'Completed ' +
                            index +
                            ' / ' +
                            total +
                            ' native MainWP requests. Starting next icon...'
                        );
                    } else {
                        setStatus(
                            'Completed ' +
                            index +
                            ' / ' +
                            total +
                            ' native MainWP requests. Finalising...'
                        );
                    }

                    /*
                     * Yield briefly between requests. Besides making the UI
                     * update visible, this avoids a very tight chain of
                     * back-to-back admin-ajax.php calls.
                     */
                    window.setTimeout(
                        processNext,
                        25
                    );
                });
            }

            function waitForMainWP(attempt) {

                if (started) {
                    return;
                }

                if (
                    typeof globalThis.mainwp_secure_data === 'function' &&
                    typeof window.ajaxurl !== 'undefined' &&
                    window.ajaxurl
                ) {
                    started = true;

                    if (0 === total) {
                        finaliseRun();
                        return;
                    }

                    setStatus(
                        'MainWP native JavaScript ready. Starting ' +
                        total +
                        ' icon requests...'
                    );

                    processNext();
                    return;
                }

                if (attempt >= 100) {
                    setError(
                        'MainWP page loaded, but its native AJAX helper did not become available. ' +
                        'Open the browser console and check for a JavaScript error.'
                    );
                    return;
                }

                setStatus(
                    'Waiting for MainWP native JavaScript...'
                );

                window.setTimeout(function () {
                    waitForMainWP(attempt + 1);
                }, 100);
            }

            waitForMainWP(0);
        });
    })();
    </script>
    <?php
}
add_action(
    'mainwp_after_header',
    'rup_mainwp_icons_native_pull_after_header',
    100
);


/**
 * Display icon-tool completion/error notices.
 *
 * @return void
 */
function rup_mainwp_icons_admin_notice() {

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    static $shown = false;

    if ( $shown ) {
        return;
    }

    $key = '_rup_mainwp_icons_notice_' .
        get_current_user_id();

    $notice = get_transient(
        $key
    );

    if ( ! is_array( $notice ) ) {
        return;
    }

    $shown = true;

    delete_transient(
        $key
    );

    if ( 'error' === ( $notice['type'] ?? '' ) ) {

        echo '<div class="notice notice-error is-dismissible"><p>';
        echo '<strong>MainWP Icons:</strong> ';
        echo esc_html(
            $notice['message'] ?? 'Unknown error.'
        );
        echo '</p></div>';

        return;
    }

    if ( 'clear' === ( $notice['type'] ?? '' ) ) {

        echo '<div class="notice notice-success is-dismissible"><p>';
        echo '<strong>MainWP Icon Cache Cleared.</strong> ';
        echo 'Previous cache: ';
        echo intval(
            $notice['plugins_before'] ?? 0
        );
        echo ' plugin icons, ';
        echo intval(
            $notice['themes_before'] ?? 0
        );
        echo ' theme icons.';
        echo '</p></div>';

        return;
    }

    if ( 'complete' === ( $notice['type'] ?? '' ) ) {

        echo '<div class="notice notice-success is-dismissible"><p>';
        echo '<strong>MainWP Native Icon Pull Complete.</strong> ';

        echo 'Processed ';
        echo intval(
            $notice['total'] ?? 0
        );
        echo ' unique plugin/theme slugs through MainWP\'s native ';
        echo '<code>mainwp_refresh_icon</code> action. ';

        echo 'Native successes: ';
        echo intval(
            $notice['native_success'] ?? 0
        );
        echo '; native misses/failures: ';
        echo intval(
            $notice['native_failed'] ?? 0
        );
        echo '. ';

        echo 'Final MainWP cache: ';
        echo intval(
            $notice['plugin_cache_count'] ?? 0
        );
        echo ' plugin icons, ';
        echo intval(
            $notice['theme_cache_count'] ?? 0
        );
        echo ' theme icons. ';

        echo intval(
            $notice['missing_count'] ?? 0
        );
        echo ' are missing from both the GitHub map and MainWP\'s finished native cache.';

        $csv = $notice['report_urls']['csv'] ?? '';
        $json = $notice['report_urls']['json'] ?? '';

        if ( $csv || $json ) {

            echo ' Missing-icon report: ';

            if ( $csv ) {
                echo '<a href="' .
                    esc_url( $csv ) .
                    '" target="_blank" rel="noopener">CSV</a>';
            }

            if ( $csv && $json ) {
                echo ' | ';
            }

            if ( $json ) {
                echo '<a href="' .
                    esc_url( $json ) .
                    '" target="_blank" rel="noopener">JSON</a>';
            }
        }

        echo '</p></div>';
    }
}
add_action(
    'admin_notices',
    'rup_mainwp_icons_admin_notice'
);
add_action(
    'all_admin_notices',
    'rup_mainwp_icons_admin_notice'
);
