<?php
/*
 * Plugin Name:     Coinsnap add-on for GiveWP 
 * Plugin URI:      https://www.coinsnap.io
 * Description:     Provides a <a href="https://coinsnap.io">Coinsnap</a>  - Bitcoin + Lightning Payment Gateway for <a href="https://givewp.com/">Givewp</a>.
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-givewp
 * Domain Path:     /languages
 * Version:         1.0.0
 * Requires PHP:    7.4
 * Requires at least: 6.0
 * Tested up to:    6.7.1
 * GiveWP tested up to: 3.19.0
 * License:         GPL2
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:         true
 */

if (!defined( 'ABSPATH' )){
    exit;
}

if(!defined('SERVER_PHP_VERSION')){ define( 'SERVER_PHP_VERSION', '7.4' );}
if(!defined('COINSNAP_VERSION')){ define( 'COINSNAP_VERSION', '1.0.0' );}
if(!defined('COINSNAP_REFERRAL_CODE')){ define( 'COINSNAP_REFERRAL_CODE', 'D19825' );}
if(!defined('COINSNAP_PLUGIN_ID')){ define( 'COINSNAP_PLUGIN_ID', 'coinsnap-for-givewp' );}
if(!defined('COINSNAP_SERVER_URL')){ define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' );}

// Register the gateways 
add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {
    include 'class-coinsnap-gateway.php';    
    $paymentGatewayRegister->registerGateway(CoinsnapGivewpClass::class);    
});