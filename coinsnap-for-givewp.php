<?php
/*
 * Plugin Name:     Bitcoin payment for GiveWP 
 * Plugin URI:      https://coinsnap.io/wp-add-ons/givewp/
 * Description:     Receive Bitcoin donations or Bitcoin contributions for your fundraisers. Easy setup, fast & simple transactions.
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-givewp
 * Domain Path:     /languages
 * Version:         1.4.7
 * Requires PHP:    7.4
 * Requires at least: 6.0
 * Tested up to:    7.0
 * Requires Plugins: give
 * GiveWP tested up to: 4.15.2
 * License:         GPL2
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:         true
 */

if (!defined( 'ABSPATH' )){
    exit;
}

if(!defined('COINSNAP_GIVEWP_PHP_VERSION')){ define( 'COINSNAP_GIVEWP_PHP_VERSION', '7.4' );}
if(!defined('COINSNAP_GIVEWP_VERSION')){ define( 'COINSNAP_GIVEWP_VERSION', '1.4.7' );}
if(!defined('COINSNAP_GIVEWP_REFERRAL_CODE')){ define( 'COINSNAP_GIVEWP_REFERRAL_CODE', 'D19825' );}
if(!defined('COINSNAP_GIVEWP_PLUGIN_ID')){ define( 'COINSNAP_GIVEWP_PLUGIN_ID', 'coinsnap-for-givewp' );}
if(!defined('COINSNAP_GIVEWP_SERVER_URL')){ define( 'COINSNAP_GIVEWP_SERVER_URL', 'https://app.coinsnap.io' );}
if(!defined('COINSNAP_GIVEWP_BASEURL')){ define( 'COINSNAP_GIVEWP_BASEURL', plugin_basename(__FILE__) );}
if(!defined('COINSNAP_CURRENCIES')){define( 'COINSNAP_CURRENCIES', array("EUR","USD","SATS","BTC","CAD","JPY","GBP","CHF","RUB") );}
if(!defined('COINSNAP_API_PATH')){define( 'COINSNAP_API_PATH', '/api/v1/');}
if(!defined('COINSNAP_SERVER_PATH')){define( 'COINSNAP_SERVER_PATH', 'stores' );}

require_once __DIR__ . '/class-coinsnap-givewp-security.php';

// Invoice records table: created on activation, and on the first load after an update.
register_activation_hook(__FILE__, ['Coinsnap_GiveWP_Security', 'install']);
add_action('plugins_loaded', ['Coinsnap_GiveWP_Security', 'install']);

// Register the gateways 

add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {
    include 'class-coinsnap-gateway.php';    
    $paymentGatewayRegister->registerGateway(CoinsnapGivewpClass::class);    
});

add_action('admin_init', 'coinsnap_givewp_dependency_check');

function coinsnap_givewp_dependency_check(){
        
    if (!is_plugin_active('give/give.php')) {
        add_action('admin_notices', 'coinsnap_givewp_dependency_notice');
        deactivate_plugins(plugin_basename(__FILE__));
    }
}
    
function coinsnap_givewp_dependency_notice(){?>
    <div class="notice notice-error">
        <p><?php echo esc_html_e('Bitcoin payment for GiveWP plugin requires GiveWP to be installed and activated.','coinsnap-for-givewp');?></p>
    </div>
    <?php        
}
    
add_action('init', function() {
    
    // Setting up and handling custom endpoint for api key redirect from BTCPay Server.
    add_rewrite_endpoint('coinsnap-for-givewp-btcpay-settings-callback', EP_ROOT);
});

// To be able to use the endpoint without appended url segments we need to do this.
add_filter('request', function($vars) {
    if (isset($vars['coinsnap-for-givewp-btcpay-settings-callback'])) {
        // The proof is the one-time state created when the admin clicked "Generate API key",
        // never a nonce created in this request.
        $vars['coinsnap-for-givewp-btcpay-settings-callback'] = true;
    }
    return $vars;
});
