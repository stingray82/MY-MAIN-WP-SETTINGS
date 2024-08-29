<?php
/**
 * My MainWP settings
 *
 * @package       MYMAINWPSE
 * @author        Stingray82
 * @license       gplv2
 * @version       1.0
 *
 * @wordpress-plugin
 * Plugin Name:   My MainWP settings
 * Plugin URI:    https://github.com/stingray82/
 * Description:   My MainWP Custom Settings
 * Version:       1.0
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

// Include your custom code here.
// Returns a New custom token [website.updated.total] which is the total of Wordpress, Plugins and Theme Updates in the month
add_filter( 'mainwp_pro_reports_addition_custom_tokens', 'mycustom_mainwp_pro_reports_addition_custom_tokens', 10, 3 );
function mycustom_mainwp_pro_reports_addition_custom_tokens( $tokens, $site_id, $data ) {
    if(is_array($data) && isset($data[$site_id])){
         $total = 0;
         $total += isset($data[$site_id]['other_tokens_data']['body']['[plugin.updated.count]']) ? intval( $data[$site_id]['other_tokens_data']['body']['[plugin.updated.count]'] ) : 0;
         $total += isset($data[$site_id]['other_tokens_data']['body']['[theme.updated.count]']) ? intval( $data[$site_id]['other_tokens_data']['body']['[theme.updated.count]'] ) : 0;
         $total += isset($data[$site_id]['other_tokens_data']['body']['[wordpress.updated.count]']) ? intval( $data[$site_id]['other_tokens_data']['body']['[wordpress.updated.count]'] ) : 0;
         
         $tokens['[website.updated.total]'] = $total;
    }
    
    return $tokens;
}

// Set Admin Default Page to MAINWP
function admin_default_page() {
return 'wp-admin/admin.php?page=mainwp_tab';
}
add_filter('login_redirect', 'admin_default_page');

//Stop PDF attachments in Pro-Report Email Only
add_filter( 'mainwp_pro_reports_email_attachments', 'mycustom_mainwp_pro_reports_email_attachments', 10, 4 );
function mycustom_mainwp_pro_reports_email_attachments( $attachments, $html_to_pdf, $report, $site_id = false ) {
   return '';
}



