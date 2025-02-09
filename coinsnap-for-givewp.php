<?php
/*
 * Plugin Name:     Bitcoin payment for GiveWP 
 * Plugin URI:      https://www.coinsnap.io
 * Description:     Provides Bitcoin + Lightning Payments for GiveWP Wordpress donation platform.
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-givewp
 * Domain Path:     /languages
 * Version:         1.0.1
 * Requires PHP:    7.4
 * Requires at least: 6.0
 * Tested up to:    6.7
 * GiveWP tested up to: 3.20.0
 * License:         GPL2
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:         true
 */

if (!defined( 'ABSPATH' )){
    exit;
}

if(!defined('COINSNAP_GIVEWP_PHP_VERSION')){ define( 'COINSNAP_GIVEWP_PHP_VERSION', '7.4' );}
if(!defined('COINSNAP_GIVEWP_VERSION')){ define( 'COINSNAP_GIVEWP_VERSION', '1.0.1' );}
if(!defined('COINSNAP_GIVEWP_REFERRAL_CODE')){ define( 'COINSNAP_GIVEWP_REFERRAL_CODE', 'D19825' );}
if(!defined('COINSNAP_GIVEWP_PLUGIN_ID')){ define( 'COINSNAP_GIVEWP_PLUGIN_ID', 'coinsnap-for-givewp' );}
if(!defined('COINSNAP_GIVEWP_SERVER_URL')){ define( 'COINSNAP_GIVEWP_SERVER_URL', 'https://app.coinsnap.io' );}

// Register the gateways 
add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {
    include 'class-coinsnap-gateway.php';    
    $paymentGatewayRegister->registerGateway(CoinsnapGivewpClass::class);    
});