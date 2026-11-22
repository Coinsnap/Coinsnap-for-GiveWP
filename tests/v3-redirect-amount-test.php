<?php
// Runs inside WordPress with GiveWP and this plugin active: wp eval-file tests/v3-redirect-amount-test.php
// Every HTTP call is answered by a fake (no API key needed). Checks that the gateway is offered on
// visual builder (v3) forms, that createPayment returns a redirect command instead of redirecting
// itself, and that the invoice asks for the donation amount in the currency's own units
// (12.34 EUR, 1000 JPY, 500 SATS), also in the invoice record the webhook compares against.
if (!defined('ABSPATH')) { exit; }

use Give\Donations\Models\Donation;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Donors\Models\Donor;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\Support\ValueObjects\Money;

$failures = 0;
$check = function (bool $ok, string $label) use (&$failures) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) { $failures++; }
};

$bodies = [];
$fake = function ($pre, $args, $url) use (&$bodies) {
    $reply = function ($body) {
        return ['headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary(['content-type' => 'application/json']),
            'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    };
    if (strpos($url, 'coingecko') !== false) {
        return $reply(['rates' => ['btc' => ['value' => 1], 'sats' => ['value' => 100000000], 'eur' => ['value' => 90000], 'jpy' => ['value' => 15000000]]]);
    }
    if (strpos($url, '/currencies') !== false) {
        return $reply(['EUR', 'USD', 'SATS', 'BTC', 'JPY']);
    }
    if (($args['method'] ?? '') === 'POST' && preg_match('~/invoices$~', $url)) {
        $bodies[] = json_decode((string) $args['body'], true);
        return $reply(['id' => 'csv3-inv-' . wp_generate_password(8, false), 'checkoutLink' => 'https://pay.example/i/x']);
    }
    return $reply([]);
};
$redirected = [];
$stop = function ($location) use (&$redirected) { $redirected[] = $location; throw new \RuntimeException('redirect ' . $location); };
// SATS is not a GiveWP currency; a site that sells in sats adds it like this (no decimals).
$sats = function ($currencies) {
    $currencies['SATS'] = ['admin_label' => 'Sats', 'symbol' => 'sats', 'setting' => ['currency_position' => 'after', 'thousands_separator' => ',', 'decimal_separator' => '.', 'number_decimals' => 0]];
    return $currencies;
};
add_filter('pre_http_request', $fake, 10, 3);
add_filter('wp_redirect', $stop, 1);
add_filter('give_currencies', $sats);

$gateway = new CoinsnapGivewpClass();
$versions = $gateway->supportsFormVersions();
$check(in_array(2, $versions, true) && in_array(3, $versions, true), 'gateway supports v2 and v3 forms');

$savedSettings = get_option('give_settings');
$savedSecrets = get_option(Coinsnap_GiveWP_Security::SECRETS_OPTION, null);
$savedUpgradedAt = get_option(Coinsnap_GiveWP_Security::UPGRADED_AT, null);
$formId = wp_insert_post(['post_type' => 'give_forms', 'post_title' => 'Clean Water', 'post_status' => 'publish']);
$donor = Donor::create(['name' => 'Ana Buyer', 'firstName' => 'Ana', 'lastName' => 'Buyer', 'email' => 'ana' . wp_rand() . '@example.com']);
$donations = [];

try {
    foreach (['coinsnap_provider' => 'coinsnap', 'coinsnap_api_key' => 'fake', 'coinsnap_store_id' => 'fake-store'] as $k => $v) {
        give_update_option($k, $v);
    }
    Coinsnap_GiveWP_Security::save_secret(Coinsnap_GiveWP_Security::config(), 'fake-secret', 'wh1');

    foreach ([['12.34', 'EUR', '12.34'], ['1000', 'JPY', '1000'], ['500', 'SATS', '500']] as [$decimal, $currency, $expected]) {
        $donation = Donation::create([
            'formId' => $formId, 'formTitle' => 'Clean Water', 'donorId' => $donor->id, 'amount' => Money::fromDecimal($decimal, $currency),
            'status' => DonationStatus::PENDING(), 'type' => \Give\Donations\ValueObjects\DonationType::SINGLE(), 'mode' => \Give\Donations\ValueObjects\DonationMode::TEST(), 'gatewayId' => CoinsnapGivewpClass::id(),
            'firstName' => 'Ana', 'lastName' => 'Buyer', 'email' => $donor->email,
        ]);
        $donations[] = $donation;

        $before = count($bodies);
        $result = null;
        try {
            $result = $gateway->createPayment(Donation::find($donation->id), []);
        } catch (\Throwable $e) {
            $result = $e;
        }
        $body = count($bodies) > $before ? end($bodies) : [];
        $check($result instanceof RedirectOffsite && $result->redirectUrl === 'https://pay.example/i/x', "$currency: createPayment returns RedirectOffsite to the payment page");
        $check($redirected === [], "$currency: no wp_redirect from createPayment");
        $check((string) ($body['currency'] ?? '') === $currency && Coinsnap_GiveWP_Security::to_units($body['amount'] ?? '', 2) === Coinsnap_GiveWP_Security::to_units($expected, 2), "$currency: invoice amount $expected (sent: " . wp_json_encode($body['amount'] ?? null) . ')');
        $li = $body['metadata']['lineItems'][0] ?? [];
        $check((float) ($li['total'] ?? 0) === (float) $expected, "$currency: lineItems total $expected");

        global $wpdb;
        $recorded = $wpdb->get_row($wpdb->prepare('SELECT amount, currency FROM ' . Coinsnap_GiveWP_Security::table() . ' WHERE donation_id = %d', $donation->id), ARRAY_A);
        $settled = ['status' => 'Settled', 'currency' => $currency, 'amount' => $currency === 'SATS' ? $expected : '1', 'fiatAmount' => $currency === 'SATS' ? '' : $expected];
        if ($currency !== 'SATS') {
            $settled['amount'] = (string) ($currency === 'JPY' ? 6667 : 13711); // whole sats; only the fiat amount is compared
        }
        $check($recorded && Coinsnap_GiveWP_Security::invoice_pays($settled, (string) $recorded['amount'], (string) $recorded['currency'], false), "$currency: webhook accepts a settled invoice of $expected");

        // Donations from before the invoice records: the legacy record must use the same units.
        $legacy = new \ReflectionMethod($gateway, 'legacyRecord');
        $legacy->setAccessible(true);
        update_option(Coinsnap_GiveWP_Security::UPGRADED_AT, time() + 60);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Coinsnap_GiveWP_Security::table() . ' WHERE donation_id = %d', $donation->id));
        $rec = $legacy->invoke($gateway, 'csv3-legacy-' . $donation->id, ['currency' => $currency], (int) $donation->id, 'coinsnap');
        $check(is_array($rec) && Coinsnap_GiveWP_Security::to_units((string) $rec['amount'], 2) === Coinsnap_GiveWP_Security::to_units($expected, 2), "$currency: legacy record amount $expected");
    }
} finally {
    remove_filter('pre_http_request', $fake, 10);
    remove_filter('wp_redirect', $stop, 1);
    update_option('give_settings', $savedSettings);
    if ($savedSecrets === null) { delete_option(Coinsnap_GiveWP_Security::SECRETS_OPTION); } else { update_option(Coinsnap_GiveWP_Security::SECRETS_OPTION, $savedSecrets, false); }
    global $wpdb;
    foreach ($donations as $donation) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Coinsnap_GiveWP_Security::table() . ' WHERE donation_id = %d', $donation->id));
        try { $donation->delete(); } catch (\Throwable $e) { wp_delete_post($donation->id, true); }
    }
    if ($savedUpgradedAt === null) { delete_option(Coinsnap_GiveWP_Security::UPGRADED_AT); } else { update_option(Coinsnap_GiveWP_Security::UPGRADED_AT, $savedUpgradedAt, false); }
    remove_filter('give_currencies', $sats);
    try { $donor->delete(); } catch (\Throwable $e) { }
    wp_delete_post($formId, true);
}

echo $failures === 0 ? "OK\n" : "$failures FAILED\n";
