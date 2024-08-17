<?php
/*
 * Plugin Name:     Coinsnap for GiveWP
 * Plugin URI:      https://www.coinsnap.io
 * Description:     Provides a <a href="https://coinsnap.io">Coinsnap</a>  - Bitcoin + Lightning Payment Gateway for <a href="https://givewp.com/">Givewp</a>.
 * Author:          Coinsnap
 * Author URI:      https://coinsnap.io/
 * Text Domain:     coinsnap-for-givewp
 * Domain Path:     /languages
 * Version:         1.0.0
 * Requires PHP:    7.4
 * Tested up to:    6.6.1
 * Requires at least: 5.2
 * GiveWP tested up to: 3.15.0
 * License:         GPL2
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:         true
 */

if (!defined( 'ABSPATH' )) exit;

define( 'SERVER_PHP_VERSION', '7.4' );
define( 'COINSNAP_VERSION', '1.0.0' );
define( 'COINSNAP_REFERRAL_CODE', 'D19825' );
define( 'COINSNAP_PLUGIN_ID', 'coinsnap-for-givewp' );
define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' );

// Register the gateways 
add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {
    include 'class-coinsnap-gateway.php';    
    $paymentGatewayRegister->registerGateway(CoinsnapGivewpClass::class);    
});