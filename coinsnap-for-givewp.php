<?php
/*
 * Plugin Name:     Bitcoin payment for GiveWP 
 * Plugin URI:      https://coinsnap.io/coinsnap-for-givewp-payment-plugin/
 * Description:     Receive Bitcoin donations or Bitcoin contributions for your fundraisers. Easy setup, fast & simple transactions.
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-givewp
 * Domain Path:     /languages
 * Version:         1.0.2
 * Requires PHP:    7.4
 * Requires at least: 6.0
 * Tested up to:    6.7
 * Requires Plugins: give
 * GiveWP tested up to: 3.22.1
 * License:         GPL2
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:         true
 */

if (!defined( 'ABSPATH' )){
    exit;
}

if(!defined('COINSNAP_GIVEWP_PHP_VERSION')){ define( 'COINSNAP_GIVEWP_PHP_VERSION', '7.4' );}
if(!defined('COINSNAP_GIVEWP_VERSION')){ define( 'COINSNAP_GIVEWP_VERSION', '1.0.2' );}
if(!defined('COINSNAP_GIVEWP_REFERRAL_CODE')){ define( 'COINSNAP_GIVEWP_REFERRAL_CODE', 'D19825' );}
if(!defined('COINSNAP_GIVEWP_PLUGIN_ID')){ define( 'COINSNAP_GIVEWP_PLUGIN_ID', 'coinsnap-for-givewp' );}
if(!defined('COINSNAP_GIVEWP_SERVER_URL')){ define( 'COINSNAP_GIVEWP_SERVER_URL', 'https://app.coinsnap.io' );}
if(!defined('COINSNAP_GIVEWP_BASEURL')){ define( 'COINSNAP_GIVEWP_BASEURL', plugin_basename(__FILE__) );}

// Register the gateways 
add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {
    include 'class-coinsnap-gateway.php';    
    $paymentGatewayRegister->registerGateway(CoinsnapGivewpClass::class);    
});

add_action('admin_init', 'check_givewp_dependency');

function check_givewp_dependency(){
        
            if (!is_plugin_active('give/give.php')) {
            add_action('admin_notices', 'givewp_dependency_notice');
            deactivate_plugins(plugin_basename(__FILE__));
        }
    }
    
function givewp_dependency_notice(){?>
    <div class="notice notice-error">
        <p><?php echo esc_html_e('Bitcoin payment for GiveWP plugin requires GiveWP to be installed and activated.','coinsnap-for-givewp');?></p>
    </div>
    <?php        
    }