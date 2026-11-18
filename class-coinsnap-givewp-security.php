<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-side payment security for Bitcoin payment for GiveWP.
 *
 * Follows the Coinsnap Plugin Security Guide: secrets stay on the server, a
 * donation counts only after the Coinsnap or BTCPay API confirms it, and each
 * invoice is acted on once.
 */
class Coinsnap_GiveWP_Security {

    const SECRETS_OPTION = 'coinsnap_givewp_webhook_secrets';
    const LEGACY_WEBHOOK = 'give_settings_coinsnap_webhook';
    const STATE_KEY      = 'coinsnap_givewp_btcpay_state';
    const DB_VERSION_KEY = 'coinsnap_givewp_db_version';
    const DB_VERSION     = '1';
    const UPGRADED_AT    = 'coinsnap_givewp_security_upgraded_at';
    const LEGACY_WINDOW  = DAY_IN_SECONDS;
    const TABLE          = 'coinsnap_givewp_invoices';

    /** Only what the plugin uses, for one store (no refunds, no invoice changes). */
    const BTCPAY_PERMISSIONS = [
        'btcpay.store.cancreateinvoice',
        'btcpay.store.canviewinvoices',
        'btcpay.store.canviewstoresettings',
        'btcpay.store.webhooks.canmodifywebhooks',
    ];
    const BTCPAY_REQUIRED = [
        'btcpay.store.cancreateinvoice',
        'btcpay.store.canviewinvoices',
        'btcpay.store.webhooks.canmodifywebhooks',
    ];

    /* ---------------------------------------------------------------------
     * Configuration
     * ------------------------------------------------------------------- */

    public static function config(): array {
        if (give_get_option('coinsnap_provider') === 'btcpay') {
            return [
                'provider' => 'btcpay',
                'url'      => rtrim((string) give_get_option('btcpay_server_url', ''), '/'),
                'store_id' => (string) give_get_option('btcpay_store_id', ''),
                'api_key'  => (string) give_get_option('btcpay_api_key', ''),
            ];
        }
        return [
            'provider' => 'coinsnap',
            'url'      => rtrim(COINSNAP_GIVEWP_SERVER_URL, '/'),
            'store_id' => (string) give_get_option('coinsnap_store_id', ''),
            'api_key'  => (string) give_get_option('coinsnap_api_key', ''),
        ];
    }

    public static function is_configured(array $cfg): bool {
        return $cfg['url'] !== '' && $cfg['store_id'] !== '' && $cfg['api_key'] !== '';
    }

    /** From the saved site address, never from the request (6.3). */
    public static function webhook_url(): string {
        return esc_url_raw(add_query_arg(['give-listener' => 'coinsnap'], home_url('index.php')));
    }

    /* ---------------------------------------------------------------------
     * Webhook secrets: one per provider and store, under this plugin's own name (1, 6.4)
     * ------------------------------------------------------------------- */

    private static function secret_key(array $cfg): string {
        return $cfg['provider'] . ':' . $cfg['store_id'];
    }

    public static function get_secret(array $cfg): string {
        $all = get_option(self::SECRETS_OPTION, []);
        $key = self::secret_key($cfg);
        return (is_array($all) && is_string($all[$key]['secret'] ?? null)) ? $all[$key]['secret'] : '';
    }

    public static function get_webhook_id(array $cfg): string {
        $all = get_option(self::SECRETS_OPTION, []);
        $key = self::secret_key($cfg);
        return (is_array($all) && is_string($all[$key]['webhook_id'] ?? null)) ? $all[$key]['webhook_id'] : '';
    }

    public static function save_secret(array $cfg, string $secret, string $webhookId): void {
        $all = get_option(self::SECRETS_OPTION, []);
        if (!is_array($all)) {
            $all = [];
        }
        $all[self::secret_key($cfg)] = ['secret' => $secret, 'webhook_id' => $webhookId];
        update_option(self::SECRETS_OPTION, $all, false);
    }

    public static function has_webhook(array $cfg): bool {
        return self::get_secret($cfg) !== '';
    }

    /* ---------------------------------------------------------------------
     * Webhook registration, on the server only (1, 6.3, 6.5)
     * ------------------------------------------------------------------- */

    /**
     * Create or update this plugin's webhook with a new secret.
     * Callers check capability and nonce first, or only call it when no secret is stored yet.
     */
    public static function register_webhook(array $cfg): bool {
        if (!self::is_configured($cfg)) {
            return false;
        }
        $url = self::webhook_url();
        $events = ($cfg['provider'] === 'btcpay') ? CoinsnapGivewpClass::BTCPAY_WEBHOOK_EVENTS : CoinsnapGivewpClass::COINSNAP_WEBHOOK_EVENTS;
        $client = new \Coinsnap\Client\Webhook($cfg['url'], $cfg['api_key']);

        try {
            // An API error is never "no webhook": if the list fails, nothing is created.
            $existingId = '';
            foreach ($client->getWebhooks($cfg['store_id']) as $webhook) {
                $data = $webhook->getData();
                if (rtrim((string) ($data['url'] ?? ''), '/') === rtrim($url, '/')) {
                    $existingId = (string) ($data['id'] ?? '');
                    break;
                }
            }

            $secret = bin2hex(random_bytes(32));
            if ($existingId !== '') {
                $client->updateWebhook($cfg['store_id'], $url, $existingId, $events, true, true, $secret);
                $id = $existingId;
            } else {
                $created = $client->createWebhook($cfg['store_id'], $url, $events, $secret);
                $id = (string) ($created->getData()['id'] ?? '');
            }
        } catch (\Throwable $e) {
            self::log('info', 'webhook register failed');
            return false;
        }

        self::save_secret($cfg, $secret, $id);
        delete_option(self::LEGACY_WEBHOOK); // the old, provider-generated secret is no longer used
        return true;
    }

    /**
     * 'ok' (webhook found on the server), 'missing' (definitely not there) or 'error'
     * (the API could not be asked; never treat this as missing).
     */
    public static function webhook_status(array $cfg): string {
        $id = self::get_webhook_id($cfg);
        if (!self::has_webhook($cfg) || $id === '') {
            return 'missing';
        }
        try {
            $data = (new \Coinsnap\Client\Webhook($cfg['url'], $cfg['api_key']))->getWebhook($cfg['store_id'], $id)->getData();
            return (rtrim((string) ($data['url'] ?? ''), '/') === rtrim(self::webhook_url(), '/')) ? 'ok' : 'missing';
        } catch (\Coinsnap\Exception\RequestException $e) {
            return in_array($e->getCode(), [400, 404], true) ? 'missing' : 'error';
        } catch (\Throwable $e) {
            return 'error';
        }
    }

    /* ---------------------------------------------------------------------
     * Webhook signature (handler step 1)
     * ------------------------------------------------------------------- */

    /**
     * Coinsnap: X-Coinsnap-Signature: t=<unix>,v1=HMAC(t.body), at most 5 minutes old.
     * BTCPay: BTCPay-Sig: sha256=HMAC(body). An empty secret rejects every webhook.
     */
    public static function signature_ok(string $provider, string $header, string $body, string $secret): bool {
        if ($provider === 'btcpay') {
            $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
            if ($secret === '' || $header === '' || !hash_equals($expected, $header)) {
                self::log('security', 'bad signature');
                return false;
            }
            return true;
        }

        parse_str(str_replace(',', '&', $header), $p);
        $t = (int) ($p['t'] ?? 0);
        $expected = hash_hmac('sha256', $t . '.' . $body, $secret);
        if ($secret === '' || $t <= 0 || !hash_equals($expected, (string) ($p['v1'] ?? ''))) {
            self::log('security', 'bad signature');
            return false;
        }
        if (abs(time() - $t) > 300) {
            self::log('security', 'stale timestamp');
            return false;
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * Fetch the invoice (handler step 3)
     * ------------------------------------------------------------------- */

    /** The invoice, null if the API doesn't know it, or throws so the webhook replies 503. */
    public static function fetch_invoice(array $cfg, string $invoiceId): ?array {
        try {
            $data = (new \Coinsnap\Client\Invoice($cfg['url'], $cfg['api_key']))->getInvoice($cfg['store_id'], $invoiceId)->getData();
        } catch (\Coinsnap\Exception\RequestException $e) {
            if ($e->getCode() >= 500) {
                throw new \RuntimeException('API unavailable');
            }
            return null; // Coinsnap 400 / BTCPay 404: unknown invoice, e.g. a dashboard test webhook
        } catch (\Throwable $e) {
            throw new \RuntimeException('API unavailable'); // network error or broken reply
        }
        return is_array($data) ? $data : null;
    }

    /* ---------------------------------------------------------------------
     * When is a donation paid (3)
     * ------------------------------------------------------------------- */

    /** Decimal places compared for a currency. */
    public static function decimals(string $currency): int {
        $c = strtoupper($currency);
        if ($c === 'BTC') {
            return 8;
        }
        return in_array($c, ['SATS', 'JPY'], true) ? 0 : 2;
    }

    /** A non-negative amount as whole units of the given precision, or null. Never compared as floats. */
    public static function to_units($value, int $decimals): ?int {
        if (is_int($value) || is_float($value)) {
            $s = number_format((float) $value, $decimals, '.', '');
        } else {
            $s = trim((string) $value);
        }
        if (!preg_match('/^(\d+)(?:\.(\d+))?$/', $s, $m)) {
            return null;
        }
        $frac = $m[2] ?? '';
        if (strlen($frac) > $decimals) {
            // More digits than the currency has: round once, then parse again.
            $s = number_format((float) $s, $decimals, '.', '');
            preg_match('/^(\d+)(?:\.(\d+))?$/', $s, $m);
            $frac = $m[2] ?? '';
        }
        return (int) $m[1] * (10 ** $decimals) + (int) str_pad($frac, $decimals, '0');
    }

    /**
     * Paid only when the API says Settled, in the currency the plugin asked for and
     * the amount it recorded. BTCPay invoices marked settled by hand are refused.
     */
    public static function invoice_pays(array $invoice, string $expectedAmount, string $currency, bool $isBtcpay): bool {
        $currency = strtoupper($currency);
        $dec = self::decimals($currency);
        $expected = self::to_units($expectedAmount, $dec);
        if ($expected === null || $expected <= 0 || ($invoice['status'] ?? '') !== 'Settled') {
            return false;
        }
        if ($isBtcpay && ($invoice['additionalStatus'] ?? '') === 'Marked') {
            return false;
        }
        if (strtoupper((string) ($invoice['currency'] ?? '')) !== $currency) {
            return false;
        }

        if ($isBtcpay) {
            // BTCPay: amount is in the invoice currency, as created.
            $paid = self::to_units($invoice['amount'] ?? '', $dec);
            return $paid !== null && abs($paid - $expected) <= 1;
        }

        // Coinsnap: amount is whole sats; fiatAmount is in the invoice currency (empty for SATS/BTC invoices).
        $sats = self::to_units($invoice['amount'] ?? '', 0);
        if ($currency === 'SATS') {
            return $sats !== null && abs($sats - $expected) <= 1;
        }
        if ($currency === 'BTC') {
            return $sats !== null && abs($sats - $expected) <= 1; // 8 decimals = sats
        }
        $fiat = self::to_units($invoice['fiatAmount'] ?? '', $dec);
        if ($fiat === null) {
            return false; // empty fiatAmount means not paid
        }
        // fiatAmount is derived from whole sats, so allow one cent or two sats' worth, whichever is larger.
        $tolerance = 1;
        if ($sats !== null && $sats > 0) {
            $tolerance = max(1, (int) ceil(2 * $fiat / $sats));
        }
        return abs($fiat - $expected) <= $tolerance;
    }

    /* ---------------------------------------------------------------------
     * Invoice records: every invoice the plugin created, acted on once (4, 6.7, 6.9)
     * ------------------------------------------------------------------- */

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install(): void {
        global $wpdb;
        if (get_option(self::DB_VERSION_KEY) === self::DB_VERSION) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::table() . " (
  invoice_id varchar(64) NOT NULL,
  donation_id bigint(20) unsigned NOT NULL,
  provider varchar(10) NOT NULL,
  amount varchar(32) NOT NULL,
  currency varchar(10) NOT NULL,
  created_at datetime NOT NULL,
  paid_at datetime DEFAULT NULL,
  PRIMARY KEY  (invoice_id),
  KEY donation_id (donation_id)
) {$charset};");
        if (!get_option(self::UPGRADED_AT)) {
            // Donations started before this version have no record; they are accepted for one day (6.7).
            update_option(self::UPGRADED_AT, time(), false);
        }
        update_option(self::DB_VERSION_KEY, self::DB_VERSION, true);
    }

    public static function record_invoice(string $invoiceId, int $donationId, string $provider, string $amount, string $currency): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $r = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::table() . ' (invoice_id, donation_id, provider, amount, currency, created_at) VALUES (%s, %d, %s, %s, %s, %s)',
            $invoiceId, $donationId, $provider, $amount, strtoupper($currency), current_time('mysql', true)
        ));
        return $r !== false;
    }

    public static function get_record(string $invoiceId): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE invoice_id = %s', $invoiceId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** True for exactly one caller per invoice, however many webhooks arrive at once. */
    public static function mark_paid_once(string $invoiceId): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $r = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET paid_at = %s WHERE invoice_id = %s AND paid_at IS NULL',
            current_time('mysql', true), $invoiceId
        ));
        return $r === 1;
    }

    /** Releases a claim when completing the donation failed, so a retry can complete it. */
    public static function unmark_paid(string $invoiceId): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET paid_at = NULL WHERE invoice_id = %s', $invoiceId));
    }

    public static function donation_has_paid_invoice(int $donationId): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . self::table() . ' WHERE donation_id = %d AND paid_at IS NOT NULL LIMIT 1', $donationId));
    }

    public static function in_legacy_window(): bool {
        $at = (int) get_option(self::UPGRADED_AT, 0);
        return $at > 0 && time() - $at <= self::LEGACY_WINDOW;
    }

    /* ---------------------------------------------------------------------
     * BTCPay connect callback and key permissions (1)
     * ------------------------------------------------------------------- */

    /** Called by the admin AJAX handler (capability and nonce checked there). The URL is saved only with a working key. */
    public static function btcpay_start_state(string $btcpayUrl): string {
        $state = bin2hex(random_bytes(32));
        set_transient(self::STATE_KEY, ['hash' => hash('sha256', $state), 'url' => $btcpayUrl], 10 * MINUTE_IN_SECONDS);
        return $state;
    }

    /** One use, even if the check fails. Returns the BTCPay URL the admin started with, or null. */
    public static function btcpay_consume_state(string $state): ?string {
        $pending = get_transient(self::STATE_KEY);
        delete_transient(self::STATE_KEY);
        if (!is_array($pending) || !is_string($pending['hash'] ?? null) || !is_string($pending['url'] ?? null) || $state === '') {
            return null;
        }
        return hash_equals($pending['hash'], hash('sha256', $state)) ? $pending['url'] : null;
    }

    /** Ask BTCPay what the key can do; never trust permissions from the POST. */
    public static function btcpay_key_permissions(string $btcpayUrl, string $apiKey): ?array {
        if ($btcpayUrl === '' || $apiKey === '') {
            return null;
        }
        $response = wp_remote_get(rtrim($btcpayUrl, '/') . '/api/v1/api-keys/current', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'token ' . $apiKey, 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $key = json_decode((string) wp_remote_retrieve_body($response), true);
        return (is_array($key) && is_array($key['permissions'] ?? null)) ? array_values($key['permissions']) : null;
    }

    /** The single store the key's first permission is limited to, or ''. */
    public static function btcpay_key_store(array $permissions): string {
        return explode(':', (string) ($permissions[0] ?? ''), 2)[1] ?? '';
    }

    /** Every permission is a store permission for this one store, and the needed ones are there. */
    public static function btcpay_key_is_acceptable(array $permissions, string $storeId): bool {
        if ($permissions === [] || $storeId === '') {
            return false;
        }
        foreach ($permissions as $permission) {
            [$policy, $scope] = array_pad(explode(':', (string) $permission, 2), 2, '');
            if (strpos($policy, 'btcpay.store.') !== 0 || $scope !== $storeId) {
                return false;
            }
        }
        foreach (self::BTCPAY_REQUIRED as $needed) {
            if (!in_array($needed . ':' . $storeId, $permissions, true)) {
                return false;
            }
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * Settings display
     * ------------------------------------------------------------------- */

    public static function secret_hint(string $value): string {
        return $value === '' ? '' : '••••••••' . substr($value, -4);
    }

    /* ---------------------------------------------------------------------
     * Logs: a fixed reason and the invoice ID, nothing else (5, 6.13)
     * ------------------------------------------------------------------- */

    public static function log(string $level, string $reason, string $invoiceId = ''): void {
        if ($level === 'security' && !self::security_log_allowed()) {
            return;
        }
        $id = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceId), 0, 64);
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(sprintf('[coinsnap-for-givewp] %s webhook reason=%s invoice=%s', strtoupper($level), $reason, $id !== '' ? $id : '-'));
    }

    private static function security_log_allowed(): bool {
        $key = 'coinsnap_givewp_seclog_' . gmdate('YmdHi');
        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, 2 * MINUTE_IN_SECONDS);
        if ($count === 11) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[coinsnap-for-givewp] SECURITY webhook more rejections this minute, not logged');
        }
        return $count <= 10;
    }
}
