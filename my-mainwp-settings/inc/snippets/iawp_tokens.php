<?php
// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;


/*
Custom Report Tokens - This block allows me to bridge the IAWP tokens to work with my email reports
*/

add_filter( 'mainwp_pro_reports_custom_tokens', 'bridge_expose_all_iawp_tokens', 10, 4 );
function bridge_expose_all_iawp_tokens( $tokensValues, $report, $site, $templ_email ) {
    // Fetch the JSON analytics blob
    $raw = apply_filters( 'mainwp_getwebsiteoptions', false, intval( $site['id'] ), 'iawp_analytics' );

    // Default placeholder
    $defaults = [
        'views'    => 'n/a',
        'visitors' => 'n/a',
        'sessions' => 'n/a',
    ];

    // If no data, set all to default and bail
    if ( empty( $raw ) ) {
        foreach ( $defaults as $key => $value ) {
            // new format
            $tokensValues[ "[iawp.{$key}]" ]   = $value;
            // legacy
            $tokensValues[ "[ipwa-{$key}]" ]   = $value;
            // typo fallback
            $tokensValues[ "[iwpa-{$key}]" ]   = $value;
        }
        return $tokensValues;
    }

    // Decode JSON
    $analytics = json_decode( $raw, true );
    if ( ! is_array( $analytics ) ) {
        foreach ( $defaults as $key => $_ ) {
            $tokensValues[ "[iawp.{$key}]" ]   = 'error';
            $tokensValues[ "[ipwa-{$key}]" ]   = 'error';
            $tokensValues[ "[iwpa-{$key}]" ]   = 'error';
        }
        return $tokensValues;
    }

    // Map each metric into all three token styles
    foreach ( [ 'views', 'visitors', 'sessions' ] as $key ) {
        $val = isset( $analytics[ $key ] ) ? intval( $analytics[ $key ] ) : 0;

        // new lowercase dot notation
        $tokensValues[ "[iawp.{$key}]" ] = $val;
        // legacy hyphen notation
        $tokensValues[ "[ipwa-{$key}]" ] = $val;
        // typo fallback
        $tokensValues[ "[iwpa-{$key}]" ] = $val;
    }

    return $tokensValues;
}