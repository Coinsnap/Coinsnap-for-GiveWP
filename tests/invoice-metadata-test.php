<?php
// Runs inside WordPress with GiveWP and this plugin active: wp eval-file tests/invoice-metadata-test.php
// Every HTTP call is answered by a fake (no API key needed). Checks the create-invoice request for Coinsnap
// and BTCPay: orderId / orderNumber = donation ID (the webhook checks them against the recorded donation)
// plus the donation metadata.
if (!defined('ABSPATH')) { exit; }

use Give\Donations\Models\Donation;
use Give\Donations\Properties\BillingAddress;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Donors\Models\Donor;
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
        return $reply(['rates' => ['btc' => ['value' => 1], 'eur' => ['value' => 90000], 'usd' => ['value' => 100000]]]);
    }
    if (strpos($url, '/payment-methods') !== false) {
        return $reply([['paymentMethodId' => 'BTC-LN', 'enabled' => true]]);
    }
    if (strpos($url, '/rates') !== false) {
        return $reply([['currencyPair' => 'BTC_EUR', 'rate' => '90000', 'errors' => []], ['currencyPair' => 'BTC_USD', 'rate' => '100000', 'errors' => []]]);
    }
    if (($args['method'] ?? '') === 'POST' && preg_match('~/invoices$~', $url)) {
        $bodies[] = json_decode((string) $args['body'], true);
        return $reply(['id' => 'csmeta-inv-' . wp_generate_password(8, false), 'checkoutLink' => 'https://pay.example/i/x']);
    }
    return $reply([]);
};
$stop = function ($location) { throw new \RuntimeException('redirect ' . $location); };
add_filter('pre_http_request', $fake, 10, 3);
add_filter('wp_redirect', $stop, 1);

$optionKeys = ['coinsnap_provider', 'coinsnap_api_key', 'coinsnap_store_id', 'btcpay_server_url', 'btcpay_api_key', 'btcpay_store_id'];
$savedSettings = get_option('give_settings');
$savedSecrets = get_option(Coinsnap_GiveWP_Security::SECRETS_OPTION, null);

$formId = wp_insert_post(['post_type' => 'give_forms', 'post_title' => 'Clean Water', 'post_status' => 'publish']);
$donor = Donor::create(['name' => 'Ana Buyer', 'firstName' => 'Ana', 'lastName' => 'Buyer', 'email' => 'ana' . wp_rand() . '@example.com']);
$donation = Donation::create([
    'formId' => $formId, 'formTitle' => 'Clean Water', 'donorId' => $donor->id, 'amount' => Money::fromDecimal(12, 'EUR'),
    'status' => DonationStatus::PENDING(), 'type' => \Give\Donations\ValueObjects\DonationType::SINGLE(), 'mode' => \Give\Donations\ValueObjects\DonationMode::TEST(), 'gatewayId' => CoinsnapGivewpClass::id(),
    'firstName' => 'Ana', 'lastName' => 'Buyer', 'email' => $donor->email, 'phone' => '+381111', 'comment' => 'Keep going',
    'billingAddress' => BillingAddress::fromArray(['address1' => 'Street 1', 'address2' => '', 'city' => 'Belgrade', 'state' => '', 'zip' => '11000', 'country' => 'RS']),
]);

try {
    foreach (['coinsnap', 'btcpay'] as $provider) {
        foreach (['coinsnap_provider' => $provider, 'coinsnap_api_key' => 'fake', 'coinsnap_store_id' => 'fake-store',
            'btcpay_server_url' => 'https://btcpay.example', 'btcpay_api_key' => 'fake', 'btcpay_store_id' => 'fake-store'] as $k => $v) {
            give_update_option($k, $v);
        }
        Coinsnap_GiveWP_Security::save_secret(Coinsnap_GiveWP_Security::config(), 'fake-secret', 'wh1');

        $before = count($bodies);
        try {
            (new CoinsnapGivewpClass())->createPayment(Donation::find($donation->id), []);
        } catch (\Throwable $e) {
            // The redirect to the payment page ends the request; the plugin turns the test's stop into a gateway exception.
        }
        $body = count($bodies) > $before ? end($bodies) : [];
        $m = $body['metadata'] ?? [];
        $id = (string) $donation->id;
        $check($body !== [], "$provider: invoice request sent");
        $check((string) ($body['orderId'] ?? '') === $id, "$provider: top-level orderId = donation ID");
        $check((string) ($m['orderId'] ?? '') === $id && (string) ($m['orderNumber'] ?? '') === $id, "$provider: metadata.orderId, orderNumber = donation ID (webhook match)");
        $check(strpos((string) ($m['orderUrl'] ?? ''), 'view-payment-details&id=' . $id) !== false, "$provider: orderUrl");
        $check(($m['itemDesc'] ?? '') === 'Donation: Clean Water' && ($m['itemCode'] ?? '') === (string) $formId, "$provider: itemDesc, itemCode");
        $check(($m['formId'] ?? 0) === $formId && ($m['formTitle'] ?? '') === 'Clean Water', "$provider: formId, formTitle");
        $li = $m['lineItems'][0] ?? [];
        $check(($li['name'] ?? '') === 'Clean Water' && ($li['quantity'] ?? 0) === 1 && (float) ($li['total'] ?? 0) === 12.0, "$provider: lineItems");
        $check(($m['platform'] ?? '') === 'givewp' && ($m['pluginVersion'] ?? '') === COINSNAP_GIVEWP_VERSION, "$provider: platform, pluginVersion");
        $check(($body['buyerEmail'] ?? '') === $donor->email && ($m['buyerEmail'] ?? '') === $donor->email, "$provider: buyerEmail");
        $check(($m['buyerName'] ?? '') === 'Ana Buyer' && ($m['customerName'] ?? '') === 'Ana Buyer' && ($m['buyerPhone'] ?? '') === '+381111', "$provider: name, phone");
        $check(($m['buyerAddress1'] ?? '') === 'Street 1' && ($m['buyerCity'] ?? '') === 'Belgrade' && ($m['buyerZip'] ?? '') === '11000' && ($m['buyerCountry'] ?? '') === 'RS', "$provider: address");
        $check(($m['buyerMessage'] ?? '') === 'Keep going', "$provider: buyerMessage (donor comment)");
        $check(!in_array('', $m, true) && !in_array(null, $m, true), "$provider: no empty values");
    }
} finally {
    remove_filter('pre_http_request', $fake, 10);
    remove_filter('wp_redirect', $stop, 1);
    update_option('give_settings', $savedSettings);
    if ($savedSecrets === null) { delete_option(Coinsnap_GiveWP_Security::SECRETS_OPTION); } else { update_option(Coinsnap_GiveWP_Security::SECRETS_OPTION, $savedSecrets, false); }
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Coinsnap_GiveWP_Security::table() . ' WHERE donation_id = %d', $donation->id));
    // Without a campaign GiveWP's delete hooks throw; remove the posts directly then.
    try { $donation->delete(); } catch (\Throwable $e) { wp_delete_post($donation->id, true); }
    try { $donor->delete(); } catch (\Throwable $e) { }
    wp_delete_post($formId, true);
}

echo $failures === 0 ? "OK\n" : "$failures FAILED\n";
