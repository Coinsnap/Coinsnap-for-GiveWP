<?php
/*
Plugin Name: 		Coinsnap for GiveWP
Plugin URI: 		https://coinsnap.io
Description: 		Provides a <a href="https://coinsnap.io">Coinsnap</a>  - Bitcoin + Lightning Payment Gateway for <a href="https://givewp.com/">Givewp</a>.
Version: 			1.0
Author: 			Coinsnap
Author URI: 		https://coinsnap.io
*/
// Register the gateways
add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {
    include 'class-coinsnap-gateway.php';
    $paymentGatewayRegister->registerGateway(CoinsnapGivewpClass::class);
});
