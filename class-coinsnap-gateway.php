<?php
if (!defined( 'ABSPATH' )){
    exit;
}

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Exceptions\Primitives\Exception;
use Give\Framework\PaymentGateways\CommandHandlers\RespondToBrowserHandler;
use Give\Framework\PaymentGateways\Commands\GatewayCommand;
use Give\Framework\PaymentGateways\Commands\PaymentComplete;
use Give\Framework\PaymentGateways\Commands\PaymentProcessing;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\Commands\RespondToBrowser;
use Give\Framework\PaymentGateways\Commands\SubscriptionProcessing;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\PaymentGateway;
use Give\Framework\Http\Response\Types\RedirectResponse;
use Coinsnap\Client\Webhook;

require_once(dirname(__FILE__) . "/library/loader.php");
use Coinsnap\Util\Notice;
use Coinsnap\Client\Store;

class CoinsnapGivewpClass extends PaymentGateway {
    /*
    * @inheritDoc
    */
    public const COINSNAP_WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];
    public const BTCPAY_WEBHOOK_EVENTS = ['InvoiceCreated','InvoiceExpired','InvoiceSettled','InvoiceProcessing'];	 

    public function __construct(SubscriptionModule $subscriptionModule = null){

        // Settings in admin
        add_filter('give_get_sections_gateways', [$this, 'admin_payment_gateway_sections']);
        add_filter('give_get_settings_gateways', [$this, 'admin_payment_gateway_setting_fields']);
       
        //  Webhook payload processing
        $this -> give_process_webhook();
        
        if (is_admin()) {
            add_action('admin_notices', array($this, 'coinsnap_notice'));
            add_action( 'admin_enqueue_scripts', [$this, 'enqueueAdminScripts'] );
            add_action( 'wp_ajax_coinsnap_connection_handler', [$this, 'coinsnapConnectionHandler'] );
            add_action( 'wp_ajax_btcpay_server_apiurl_handler', [$this, 'btcpayApiUrlHandler']);
        }
        
        // Settings: API keys are never shown, an empty field keeps the stored key (5, 6.14).
        add_action('give_admin_field_coinsnap_secret', [$this, 'renderSecretField'], 10, 2);
        add_filter('give_admin_settings_sanitize_option_coinsnap_api_key', [$this, 'sanitizeCoinsnapApiKey'], 10, 3);
        add_filter('give_admin_settings_sanitize_option_btcpay_api_key', [$this, 'sanitizeBtcpayApiKey'], 10, 3);
        add_action('give_update_options_gateways_coinsnap', [$this, 'afterSettingsSaved']);
        add_action('admin_post_coinsnap_givewp_reconnect_webhook', [$this, 'reconnectWebhook']);

        // BTCPay posts the new API key here after the admin authorized it on BTCPay.
        add_action('template_redirect', [$this, 'btcpayCallback']);

        parent::__construct($subscriptionModule);
    }
    
    /**
     * BTCPay connect callback. Public on purpose (BTCPay posts it cross-site, without the
     * admin's cookie), so the proof is the one-time state; the key's permissions and store
     * come from BTCPay itself, and the server URL from the state, never from the request.
     */
    public function btcpayCallback() {
        global $wp_query;

        if (!isset($wp_query->query_vars['coinsnap-for-givewp-btcpay-settings-callback'])) {
            return;
        }

        $notice = new \Coinsnap\Util\Notice();
        $settingsUrl = admin_url('edit.php?post_type=give_forms&page=give-settings&tab=gateways&section=coinsnap&provider=btcpay');

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the one-time state is the proof.
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $btcpayUrl = Coinsnap_GiveWP_Security::btcpay_consume_state($state);
        if ($btcpayUrl === null) {
            $notice->addNotice('error', __('The BTCPay authorization link has expired or is invalid. Please click "Generate API key" again.', 'coinsnap-for-givewp'));
            wp_safe_redirect($settingsUrl);
            exit();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the one-time state is the proof.
        $apiKey = isset($_POST['apiKey']) ? sanitize_text_field(wp_unslash($_POST['apiKey'])) : '';
        $permissions = Coinsnap_GiveWP_Security::btcpay_key_permissions($btcpayUrl, $apiKey);
        $storeId = is_array($permissions) ? Coinsnap_GiveWP_Security::btcpay_key_store($permissions) : '';
        if ($permissions === null || !Coinsnap_GiveWP_Security::btcpay_key_is_acceptable($permissions, $storeId)) {
            $notice->addNotice('error', __('The API key was refused. Please select exactly one store and keep the requested permissions on the BTCPay API authorization page.', 'coinsnap-for-givewp'));
            wp_safe_redirect($settingsUrl);
            exit();
        }

        // The server URL is saved only together with a key that works on it (6.14).
        give_update_option('btcpay_server_url', $btcpayUrl);
        give_update_option('btcpay_api_key', $apiKey);
        give_update_option('btcpay_store_id', $storeId);
        give_update_option('coinsnap_provider', 'btcpay');

        $notice->addNotice('success', __('Successfully received api key and store id from BTCPay Server API. Please finish setup by saving this settings form.', 'coinsnap-for-givewp'));

        $cfg = ['provider' => 'btcpay', 'url' => rtrim($btcpayUrl, '/'), 'store_id' => $storeId, 'api_key' => $apiKey];
        if (Coinsnap_GiveWP_Security::register_webhook($cfg)) {
            $notice->addNotice('success', __('Successfully registered a new webhook on BTCPay Server.', 'coinsnap-for-givewp'), true);
        } else {
            $notice->addNotice('error', __('Could not register a new webhook on the store.', 'coinsnap-for-givewp'));
        }
        wp_safe_redirect($settingsUrl);
        exit();
    }

    /** API key field: an empty password field with a hint, never the stored key. */
    public function renderSecretField($field, $optionValue) {
        $hint = Coinsnap_GiveWP_Security::secret_hint(is_string($optionValue) ? $optionValue : '');
        $class = empty($field['class']) ? '' : ' ' . $field['class'];
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><label for="<?php echo esc_attr($field['id']); ?>"><?php echo esc_html($field['name']); ?></label></th>
            <td class="give-forminp give-forminp-password">
                <input name="<?php echo esc_attr($field['id']); ?>" id="<?php echo esc_attr($field['id']); ?>" type="password" value="" autocomplete="new-password"
                    class="give-input-field<?php echo esc_attr($class); ?>"<?php echo ($hint !== '') ? ' data-coinsnap-has-value="1"' : ''; ?>
                    placeholder="<?php echo esc_attr($hint !== '' ? $hint : __('Paste your API key', 'coinsnap-for-givewp')); ?>" />
                <p class="give-field-description"><?php echo esc_html($hint !== '' ? __('Leave empty to keep the saved key.', 'coinsnap-for-givewp') : $field['desc']); ?></p>
            </td>
        </tr>
        <?php
    }

    /** Empty field: keep the stored key (null tells GiveWP to skip the option). */
    public function sanitizeCoinsnapApiKey($value, $option, $rawValue) {
        $value = trim((string) $value);
        return ($value === '') ? null : $value;
    }

    /**
     * A new BTCPay server URL with an empty key field removes the stored key (6.14).
     * A pasted key is saved only if BTCPay says it is limited to the configured store.
     */
    public function sanitizeBtcpayApiKey($value, $option, $rawValue) {
        $value = trim((string) $value);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- GiveWP verified the settings nonce before saving.
        $postedUrl = isset($_POST['btcpay_server_url']) ? rtrim(sanitize_text_field(wp_unslash($_POST['btcpay_server_url'])), '/') : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $postedStore = isset($_POST['btcpay_store_id']) ? sanitize_text_field(wp_unslash($_POST['btcpay_store_id'])) : '';
        $urlChanged = $postedUrl !== rtrim((string) give_get_option('btcpay_server_url', ''), '/');

        if ($value === '') {
            return $urlChanged ? '' : null;
        }

        $permissions = Coinsnap_GiveWP_Security::btcpay_key_permissions($postedUrl, $value);
        if ($permissions === null || !Coinsnap_GiveWP_Security::btcpay_key_is_acceptable($permissions, $postedStore)) {
            // GiveWP saves while the page is rendered, so use its own message list (not a cookie).
            Give_Admin_Settings::add_error('coinsnap-btcpay-key', __('The BTCPay API key was not saved: it must work on this BTCPay server and be limited to the entered store, with only store permissions.', 'coinsnap-for-givewp'));
            return $urlChanged ? '' : null;
        }
        return $value;
    }

    /** After the settings were saved (GiveWP checked capability and nonce): connect the webhook if this store has none. */
    public function afterSettingsSaved() {
        $cfg = Coinsnap_GiveWP_Security::config();
        if (Coinsnap_GiveWP_Security::is_configured($cfg) && !Coinsnap_GiveWP_Security::has_webhook($cfg)) {
            Coinsnap_GiveWP_Security::register_webhook($cfg);
        }
    }

    /** "Create new secret": re-registers the webhook with a new secret. */
    public function reconnectWebhook() {
        if (!current_user_can('manage_give_settings')) {
            wp_die(esc_html__('Forbidden', 'coinsnap-for-givewp'), '', ['response' => 403]);
        }
        check_admin_referer('coinsnap_givewp_reconnect_webhook');
        $notice = new \Coinsnap\Util\Notice();
        if (Coinsnap_GiveWP_Security::register_webhook(Coinsnap_GiveWP_Security::config())) {
            $notice->addNotice('success', __('Webhook connected with a new secret.', 'coinsnap-for-givewp'));
        } else {
            $notice->addNotice('error', __('Could not connect the webhook. Please check the store settings.', 'coinsnap-for-givewp'));
        }
        wp_safe_redirect(admin_url('edit.php?post_type=give_forms&page=give-settings&tab=gateways&section=coinsnap'));
        exit();
    }

    private function reconnectUrl(): string {
        return wp_nonce_url(admin_url('admin-post.php?action=coinsnap_givewp_reconnect_webhook'), 'coinsnap_givewp_reconnect_webhook');
    }

    public function coinsnapConnectionHandler(){
        
        // Capability and nonce before any API call or webhook change (6.5).
        check_ajax_referer('coinsnap-ajax-nonce', '_wpnonce');
        if (!current_user_can('manage_give_settings')) {
            wp_send_json_error(null, 403);
        }
        
        if(empty($this->getApiUrl()) || empty($this->getApiKey())){
            $response = [
                    'result' => false,
                    'message' => __('GiveWP: empty gateway URL or API Key', 'coinsnap-for-givewp')
            ];
            $this->sendJsonResponse($response);
        }
        
        $_provider = $this->getPaymentProvider();
        $client = new \Coinsnap\Client\Invoice($this->getApiUrl(),$this->getApiKey());
        $store = new \Coinsnap\Client\Store($this->getApiUrl(),$this->getApiKey());
        $currency = give_get_currency();
        
        
        if($_provider === 'btcpay'){
            try {
                $storePaymentMethods = $store->getStorePaymentMethods($this->getStoreId());

                if ($storePaymentMethods['code'] === 200) {
                    if($storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData(0,$currency,'bitcoin','calculation');
                    }
                    elseif($storePaymentMethods['result']['lightning']){
                        $checkInvoice = $client->checkPaymentData(0,$currency,'lightning','calculation');
                    }
                }
            }
            catch (\Exception $e) {
                $response = [
                        'result' => false,
                        'message' => __('GiveWP: API connection is not established', 'coinsnap-for-givewp')
                ];
                $this->sendJsonResponse($response);
            }
        }
        else {
            $checkInvoice = $client->checkPaymentData(0,$currency,'coinsnap','calculation');
        }
        
        if(isset($checkInvoice) && $checkInvoice['result']){
            $connectionData = __('Min order amount is', 'coinsnap-for-givewp') .' '. $checkInvoice['min_value'].' '.$currency;
        }
        else {
            $connectionData = __('No payment method is configured', 'coinsnap-for-givewp');
        }
        
        $_message_disconnected = ($_provider !== 'btcpay')? 
            __('GiveWP: Coinsnap server is disconnected', 'coinsnap-for-givewp') :
            __('GiveWP: BTCPay server is disconnected', 'coinsnap-for-givewp');
        $_message_connected = ($_provider !== 'btcpay')?
            __('GiveWP: Coinsnap server is connected', 'coinsnap-for-givewp') : 
            __('GiveWP: BTCPay server is connected', 'coinsnap-for-givewp');
        
        $response = ['result' => false,'message' => $_message_disconnected];
        
        try {
            $this_store = $store->getStore($this->getStoreId());
            
            if ($this_store['code'] !== 200) {
                $this->sendJsonResponse($response);
            }
            
            $cfg = Coinsnap_GiveWP_Security::config();
            $webhookStatus = Coinsnap_GiveWP_Security::webhook_status($cfg);

            if($webhookStatus === 'ok') {
                $response = ['result' => true,'message' => $_message_connected.' ('.$connectionData.')'];
                $this->sendJsonResponse($response);
            }

            // Only a webhook that is definitely missing is created again; an API error changes nothing.
            $webhook = ($webhookStatus === 'missing') && Coinsnap_GiveWP_Security::register_webhook($cfg);
            
            $response['result'] = $webhook;
            $response['message'] = $webhook ? $_message_connected.' ('.$connectionData.')' : $_message_disconnected.' (Webhook)';
        }
        catch (\Exception $e) {
            $response['message'] =  __('GiveWP: API connection is not established', 'coinsnap-for-givewp');
        }

        $this->sendJsonResponse($response);
    }

    private function sendJsonResponse(array $response): void {
        echo wp_json_encode($response);
        exit();
    }
    
    public function enqueueAdminScripts() {
        if (!current_user_can('manage_give_settings')) {
            return; // the AJAX nonce is only for users who may change the settings
        }
	// Register the CSS file
	wp_register_style( 'coinsnap-admin-styles', plugins_url('assets/css/backend-style.css', __FILE__ ), array(), COINSNAP_GIVEWP_VERSION );
	// Enqueue the CSS file
	wp_enqueue_style( 'coinsnap-admin-styles' );
        //  Enqueue admin fileds handler script
        
        if('gateways' === filter_input(INPUT_GET,'tab',FILTER_SANITIZE_FULL_SPECIAL_CHARS) && 'coinsnap' === filter_input(INPUT_GET,'section',FILTER_SANITIZE_FULL_SPECIAL_CHARS)){
            wp_enqueue_script('coinsnap-admin-fields',plugins_url('assets/js/adminFields.js', __FILE__ ),[ 'jquery' ],COINSNAP_GIVEWP_VERSION,true);
        }
        
        wp_enqueue_script('coinsnap-connection-check',plugins_url('assets/js/connectionCheck.js', __FILE__ ),[ 'jquery' ],COINSNAP_GIVEWP_VERSION,true);
        wp_localize_script('coinsnap-connection-check', 'coinsnap_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce( 'coinsnap-ajax-nonce' )
        ));
    }
    
    /**
     * Handles the BTCPay server AJAX callback from the settings form.
     */
    public function btcpayApiUrlHandler() {
        $_nonce = isset($_POST['apiNonce']) ? sanitize_text_field(wp_unslash($_POST['apiNonce'])) : '';
        if ( !wp_verify_nonce( $_nonce, 'coinsnap-ajax-nonce' ) ) {
            wp_die('Unauthorized!', '', ['response' => 401]);
        }
        
        if ( current_user_can( 'manage_give_settings' ) ) {
            $host = filter_var(isset($_POST['host']) ? rtrim(sanitize_text_field(wp_unslash($_POST['host'])), '/') : '', FILTER_VALIDATE_URL);

            if ($host === false || (substr( $host, 0, 7 ) !== "http://" && substr( $host, 0, 8 ) !== "https://")) {
                wp_send_json_error("Error validating BTCPayServer URL.");
            }

            // Only what the plugin uses, for one store (strict, selectiveStores).
            $permissions = Coinsnap_GiveWP_Security::BTCPAY_PERMISSIONS;

            try {
		// Create the redirect url to BTCPay instance.
		$url = \Coinsnap\Client\BTCPayApiKey::getAuthorizeUrl(
                    $host,
                    $permissions,
                    'GiveWP',
                    true,
                    true,
                    add_query_arg('state', Coinsnap_GiveWP_Security::btcpay_start_state($host), home_url('?coinsnap-for-givewp-btcpay-settings-callback')),
                    null
		);

		// The host is kept with the one-time state and saved only with a key that works on it (6.14).

		// Return the redirect url.
		wp_send_json_success(['url' => $url]);
            }
            
            catch (\Throwable $e) {
                Coinsnap_GiveWP_Security::log('info', 'btcpay authorize url failed');
            }
	}
        wp_send_json_error("Error processing Ajax request.");
    }    
    
    public function coinsnap_notice(){
        
        $notices = new Notice(); 
        $notices->showNotices();

        // After the security update every store needs a webhook with a new secret.
        if (current_user_can('manage_give_settings')) {
            $cfg = Coinsnap_GiveWP_Security::config();
            if (Coinsnap_GiveWP_Security::is_configured($cfg) && !Coinsnap_GiveWP_Security::has_webhook($cfg)) {
                echo '<div class="notice notice-warning"><p>'
                    . esc_html__('Bitcoin payment for GiveWP: the Coinsnap webhook must be connected again before donations can be confirmed.', 'coinsnap-for-givewp')
                    . ' <a class="button button-primary" href="' . esc_url($this->reconnectUrl()) . '">' . esc_html__('Connect webhook', 'coinsnap-for-givewp') . '</a></p></div>';
            }
        }
    }

    public static function id(): string {
        return 'coinsnap-gateway';
    }

    function admin_payment_gateway_sections($sections){
        $sections['coinsnap'] = __('Coinsnap', 'coinsnap-for-givewp');
        return $sections;
    }

    function admin_payment_gateway_setting_fields($settings){
        $statuses = give_get_payment_statuses();
        
        switch (give_get_current_setting_section()) {            
            case 'coinsnap':
               $settings = array(
                    array(
                        'id' => 'cnp_give_title',
                        'type' => 'title',
                        'desc' => '<div id="coinsnapConnectionStatus"></div>'.$this->webhookStatusHtml()
                    )
                );
                $settings[] = array(
                    'id'   => 'coinsnap_provider',
                    'name' => __( 'Payment provider', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Select payment provider', 'coinsnap-for-givewp' ),
                    'type'        => 'select',
                    'options'   => [
                        'coinsnap'  => 'Coinsnap',
                        'btcpay'    => 'BTCPay Server'
                    ]
                );
                
                //  Coinsnap fields
                $settings[] = array(
                    'id'   => 'coinsnap_store_id',
                    'name' => __( 'Store ID*', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Enter Store ID', 'coinsnap-for-givewp' ),
                    'type' => 'text',
                    'class' => 'coinsnap'
                );
                $settings[] = array(
                    'id'   => 'coinsnap_api_key',
                    'name' => __( 'API Key*', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Enter API Key', 'coinsnap-for-givewp' ),
                    'type' => 'coinsnap_secret',
                    'class' => 'coinsnap'
                );
                
                //  BTCPay fields
                $settings[] = array(
                    'id' => 'btcpay_server_url',
                    'name'       => __( 'BTCPay server URL*', 'coinsnap-for-givewp' ),
                    'type'        => 'text',
                    'desc'        => __( '<a href="#" class="btcpay-apikey-link">Check connection</a>', 'coinsnap-for-givewp' ).'<br/><br/><button class="button btcpay-apikey-link" id="btcpay_wizard_button" type="button" target="_blank">'. __('Generate API key','coinsnap-for-givewp').'</button>',
                    'default'     => '',
                    'class' => 'btcpay'
                );
                $settings[] = array(
                    'id'   => 'btcpay_store_id',
                    'name' => __( 'Store ID*', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Enter Store ID', 'coinsnap-for-givewp' ),
                    'type' => 'text',
                    'default'     => '',
                    'class' => 'btcpay'
                );
                $settings[] = array(
                    'id'   => 'btcpay_api_key',
                    'name' => __( 'API Key*', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Enter API Key', 'coinsnap-for-givewp' ),
                    'type' => 'coinsnap_secret',
                    'default'     => '',
                    'class' => 'btcpay'
                );
                
                $settings[] = array(
                    'id'   => 'coinsnap_expired_status',
                    'name' => __( 'Expired Status', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Select Expired Status', 'coinsnap-for-givewp' ),
                    'type'        => 'select',
                    'default'         => 'cancelled',
                    'options'     => $statuses,
                );
                $settings[] = array(
                    'id'   => 'coinsnap_settled_status',
                    'name' => __( 'Settled Status', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Select Settled Status', 'coinsnap-for-givewp' ),
                    'type'        => 'select',
                    'default'         => 'publish',
                    'options'     => $statuses,
                );
                $settings[] = array(
                    'id'   => 'coinsnap_processing_status',
                    'name' => __( 'Processing Status', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Select Processing Status', 'coinsnap-for-givewp' ),
                    'type'  => 'select',
                    'default' => 'processing',
                    'options' => $statuses,
                );				
                $settings[] = array(
                    'id'   => 'coinsnap_desc',
                    'name' => __( 'Payment Description', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Enter Payment Description', 'coinsnap-for-givewp' ),
                    'default'  => "You will be taken away to Bitcoin + Lightning to complete the donation!",
                    'type' => 'text',
                );
                $settings[] = array(
                    'id'   => 'coinsnap_autoredirect',
                    'name' => __( 'Redirect after payment', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Redirect to Thank You page after payment automatically', 'coinsnap-for-givewp' ),
                    'type' => 'checkbox',
                    'default' => 'on',
                );
                $settings[] = array(
                    'id'   => 'coinsnap_returnurl',
                    'name' => __( 'Return URL after payment', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Custom return URL after successful payment (default URL if blank)', 'coinsnap-for-givewp' ),
                    'type' => 'text',
                );
		$settings[] = array(
                    'id' => 'cnp_give_title',
                    'type' => 'sectionend',
		);

		break;

            }

        return $settings;
    }

    /** "Webhook: connected" and a "Create new secret" button; the secret itself is never shown. */
    private function webhookStatusHtml(): string {
        $connected = Coinsnap_GiveWP_Security::has_webhook(Coinsnap_GiveWP_Security::config());
        return '<p>' . esc_html($connected ? __('Webhook: connected', 'coinsnap-for-givewp') : __('Webhook: not connected', 'coinsnap-for-givewp'))
            . ' <a class="button" href="' . esc_url($this->reconnectUrl()) . '">'
            . esc_html($connected ? __('Create new secret', 'coinsnap-for-givewp') : __('Connect webhook', 'coinsnap-for-givewp')) . '</a></p>';
    }

    /**
     * @inheritDoc
     */
    public function getId(): string {
        return self::id();
    }

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return __('Coinsnap', 'coinsnap-for-givewp');
	}

	/**
	 * @inheritDoc
	 */
	public function getPaymentMethodLabel(): string	{
            return __('Bitcoin + Lightning', 'coinsnap-for-givewp');
	}

	/**
	 * @inheritDoc
	 */
	public function getLegacyFormFieldMarkup(int $formId, array $args): string {		
            return "<div class='coinsnap-givewp-help-text'>
                <p>".esc_html(give_get_option( 'coinsnap_desc'))."</p>
            </div>";
	}

    /**
    * @inheritDoc
    */
    public function createPayment(Donation $donation, $gatewayData): GatewayCommand {
        
        // A donor's checkout only creates a webhook when this store has none; it never replaces a secret.
        $cfg = Coinsnap_GiveWP_Security::config();
        if (! Coinsnap_GiveWP_Security::has_webhook($cfg) && ! Coinsnap_GiveWP_Security::register_webhook($cfg)) {
            throw new PaymentGatewayException(esc_html__('Bitcoin payments are not available at the moment. Please try again later.', 'coinsnap-for-givewp'));
        }
				
        $amount =  round(($donation->amount->getAmount() / 100), 2);
        $currency = strtoupper($donation->amount->getCurrency()->getCode());
        $redirectUrl = (!empty(give_get_option('coinsnap_returnurl')))? give_get_option('coinsnap_returnurl') : esc_url_raw(give_get_success_page_uri());
        
        $buyerEmail = $donation->email;				
        $buyerName = $donation->firstName . ' ' .$donation->lastName;

        // Donation data and the donor's details. The plugin has no setting for customer data, so they are always sent.
        $metadata = $this->invoiceMetadata($donation);
        
        $client = new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
        
        $_provider = $this->getPaymentProvider();
        if($_provider === 'btcpay'){
        
            $store = new Store($this->getApiUrl(), $this->getApiKey());
            
            try {
                $storePaymentMethods = $store->getStorePaymentMethods($this->getStoreId());

                if ($storePaymentMethods['code'] === 200) {
                    if(!$storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']){
                        $errorMessage = __( 'No payment method is configured on BTCPay server', 'coinsnap-for-givewp' );
                        throw new PaymentGatewayException(esc_html($errorMessage));
                    }
                }
                else {
                    $errorMessage = __( 'Error store loading. Wrong or empty Store ID', 'coinsnap-for-givewp' );
                     $checkInvoice = array('result' => false,'error' => esc_html($errorMessage));
                }

                if($storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']){
                    $checkInvoice = $client->checkPaymentData((float)$amount, $currency ,'bitcoin');
                }
                elseif($storePaymentMethods['result']['lightning']){
                    $checkInvoice = $client->checkPaymentData((float)$amount, $currency ,'lightning');
                }
                else {
                    $errorMessage = __( 'No payment method is configured on BTCPay server', 'coinsnap-for-givewp' );
                    throw new PaymentGatewayException(esc_html($errorMessage));
                }
            }
            catch (\Throwable $e){
                $errorMessage = __( 'API connection is not established', 'coinsnap-for-givewp' );
                $checkInvoice = array('result' => false,'error' => esc_html($errorMessage));
            }
        }
        else {
            $checkInvoice = $client->checkPaymentData((float)$amount, $currency );
        }
                
        if($checkInvoice['result'] === true){
            
            if($this->getPaymentProvider() === 'btcpay' && $currency !== 'BTC'){
                $store = new \Coinsnap\Client\Store($this->getApiUrl(), $this->getApiKey());
                $btcpayCurrencies = $store -> getStoreCurrenciesRates($this->getStoreId(),array($currency));
                $isCurrency = true;
                if(!isset($btcpayCurrencies['result']['error']) && count($btcpayCurrencies['result']['currencies'])>0){
                        if(!isset($btcpayCurrencies['result']['currencies']['BTC_'.$currency])){
                            $isCurrency = false;
                        }
                }
                else {
                    $isCurrency = false;
                }
                    
                // Handle currencies non-supported by BTCPay Server, we need to change them BTC and adjust the amount.
                if( !$isCurrency ){
                        $currency = 'BTC';
                        $rate = 1/$checkInvoice['rate'];
                        $amountBTC = bcdiv(strval($amount), strval($rate), 8);
                        $amount = (float)$amountBTC;
                }
            }
                
            $camount = ($currency === 'BTC')? \Coinsnap\Util\PreciseNumber::parseFloat($amount,8) : \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);

            $redirectAutomatically = give_get_option( 'coinsnap_autoredirect');
            $walletMessage = '';

            try {
                $csinvoice = $client->createInvoice(
                    $this->getStoreId(),  
                    $currency,
                    $camount,
                    $donation->id,
                    $buyerEmail,
                    $buyerName, 
                    $redirectUrl,
                    COINSNAP_GIVEWP_REFERRAL_CODE,     
                    $metadata,
                    $redirectAutomatically,
                    $walletMessage
                );
                
                $invoiceData = $csinvoice->getData();
                $payurl = (string) ($invoiceData['checkoutLink'] ?? '');

                // Keep every invoice with the amount and currency it asked for (3, 6.7).
                if (!is_string($invoiceData['id'] ?? null) || !Coinsnap_GiveWP_Security::record_invoice($invoiceData['id'], (int) $donation->id, $_provider, $camount->__toString(), $currency)) {
                    throw new \RuntimeException('invoice not recorded');
                }
                
                wp_redirect($payurl);
                exit();
            }
            catch (\Throwable $e){
                $errorMessage = __( 'API connection is not established', 'coinsnap-for-givewp' );
                throw new PaymentGatewayException(esc_html($errorMessage));
            }
        }
                
        else {
            if($checkInvoice['error'] === 'currencyError'){
                $errorMessage = sprintf( 
                /* translators: 1: Currency */
                __( 'Currency %1$s is not supported by Coinsnap', 'coinsnap-for-givewp' ), strtoupper( $currency ));
            }      
            elseif($checkInvoice['error'] === 'amountError'){
                $errorMessage = sprintf( 
                /* translators: 1: Amount, 2: Currency */
                __( 'Invoice amount cannot be less than %1$s %2$s', 'coinsnap-for-givewp' ), $checkInvoice['min_value'], strtoupper( $currency ));
            }
            throw new PaymentGatewayException(esc_html($errorMessage));
        }
        
        exit;
    }

    // Invoice metadata. orderId and orderNumber stay the donation ID: the webhook checks the API invoice's
    // orderId (Coinsnap, falling back to metadata.orderNumber) or metadata.orderId (BTCPay) against the recorded donation.
    private function invoiceMetadata(Donation $donation): array {
        $id = (int) $donation->id;
        // Older GiveWP versions lack some properties (the model throws on unknown ones).
        $get = function (string $key) use ($donation) {
            try {
                return $donation->$key;
            } catch (\Throwable $e) {
                return null;
            }
        };
        $name = trim($get('firstName') . ' ' . $get('lastName'));
        $formTitle = wp_strip_all_tags((string) $get('formTitle'));
        $total = round($donation->amount->getAmount() / 100, 2);
        $address = $get('billingAddress');

        $donationNumber = function_exists('give_get_payment_number') ? (string) give_get_payment_number($id) : '';
        $campaign = $get('campaignId') ? $get('campaign') : null;
        $campaignTitle = ($campaign && isset($campaign->title)) ? wp_strip_all_tags((string) $campaign->title) : '';
        $type = $get('type');
        $type = (is_object($type) && method_exists($type, 'getValue')) ? (string) $type->getValue() : '';
        $mode = $get('mode');
        $mode = (is_object($mode) && method_exists($mode, 'getValue')) ? (string) $mode->getValue() : '';

        return self::withoutEmptyValues([
            'orderId' => (string) $id,
            'orderNumber' => (string) $id,
            'donationNumber' => ($donationNumber !== '' && $donationNumber !== (string) $id) ? $donationNumber : '',
            'orderUrl' => admin_url('edit.php?post_type=give_forms&page=give-payment-history&view=view-payment-details&id=' . $id),
            'customerName' => $name,
            'buyerName' => $name,
            'buyerEmail' => (string) $get('email'),
            'buyerPhone' => (string) $get('phone'),
            'buyerCompany' => (string) $get('company'),
            'buyerAddress1' => $address ? (string) $address->address1 : '',
            'buyerAddress2' => $address ? (string) $address->address2 : '',
            'buyerCity' => $address ? (string) $address->city : '',
            'buyerState' => $address ? (string) $address->state : '',
            'buyerZip' => $address ? (string) $address->zip : '',
            'buyerCountry' => $address ? (string) $address->country : '',
            'buyerMessage' => (string) $get('comment'),
            'itemDesc' => $formTitle !== '' ? sprintf('Donation: %s', $formTitle) : '',
            'itemCode' => $get('formId') ? (string) $get('formId') : '',
            'lineItems' => [self::withoutEmptyValues([
                'name' => $formTitle,
                'productId' => $get('formId') ? (int) $get('formId') : null,
                'quantity' => 1,
                'price' => $total,
                'total' => $total,
            ])],
            'formId' => $get('formId') ? (int) $get('formId') : null,
            'formTitle' => $formTitle,
            'campaignId' => $get('campaignId') ? (int) $get('campaignId') : null,
            'campaignTitle' => $campaignTitle,
            'levelId' => (string) $get('levelId'),
            'donationType' => $type,
            'subscriptionId' => $get('subscriptionId') ? (int) $get('subscriptionId') : null,
            'anonymous' => $get('anonymous') ? true : null,
            'testMode' => $mode === 'test' ? true : null,
            'platform' => 'givewp',
            'pluginVersion' => COINSNAP_GIVEWP_VERSION,
            'language' => function_exists('determine_locale') ? determine_locale() : get_locale(),
        ]);
    }

    // Drops null, '' and [] so the invoice carries only values that exist.
    private static function withoutEmptyValues(array $values): array {
        return array_filter($values, function ($value) {
            return $value !== null && $value !== '' && $value !== [];
        });
    }

    /**
     * @inerhitDoc
     */
    public function refundDonation(Donation $donation): PaymentRefunded {
        // Step 1: refund the donation with your gateway.
        // Step 2: return a command to complete the refund.
        return new PaymentRefunded();
    }

    public function give_process_webhook(){
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- webhooks are authenticated by their signature.
        if (!isset($_GET['give-listener']) || $_GET['give-listener'] !== 'coinsnap') {
            return;
        }
        // GiveWP builds the gateway again while saving a donation; handle the webhook only once.
        static $running = false;
        if ($running) {
            return;
        }
        $running = true;

        $rawPostData = (string) file_get_contents('php://input');
        $code = $this->handleWebhook($rawPostData);

        status_header($code);
        echo ($code === 200) ? 'OK' : (($code === 401) ? 'Unauthorized' : 'Retry later');
        exit;
    }

    /**
     * Returns the HTTP status to send. Only invoiceId is taken from the body; the
     * decision comes from the invoice fetched from the API.
     */
    public function handleWebhook(string $rawPostData): int {
        $cfg = Coinsnap_GiveWP_Security::config();
        $isBtcpay = $cfg['provider'] === 'btcpay';
        $header = $isBtcpay
            ? (isset($_SERVER['HTTP_BTCPAY_SIG']) ? (string) wp_unslash($_SERVER['HTTP_BTCPAY_SIG']) : '') // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : (isset($_SERVER['HTTP_X_COINSNAP_SIGNATURE']) ? (string) wp_unslash($_SERVER['HTTP_X_COINSNAP_SIGNATURE']) : ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if (!Coinsnap_GiveWP_Security::signature_ok($cfg['provider'], $header, $rawPostData, Coinsnap_GiveWP_Security::get_secret($cfg))) {
            return 401;
        }

        $data = json_decode($rawPostData, true);
        $invoiceId = (is_array($data) && is_string($data['invoiceId'] ?? null)) ? $data['invoiceId'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $invoiceId)) {
            Coinsnap_GiveWP_Security::log('info', 'no invoice id');
            return 200;
        }
        $claimsPaid = in_array($data['type'] ?? '', ['Settled', 'InvoiceSettled'], true);

        try {
            $invoice = Coinsnap_GiveWP_Security::fetch_invoice($cfg, $invoiceId);
        } catch (\RuntimeException $e) {
            Coinsnap_GiveWP_Security::log('info', 'retry: API unavailable', $invoiceId);
            return 503;
        }
        if ($invoice === null) {
            Coinsnap_GiveWP_Security::log('info', 'unknown invoice', $invoiceId);
            return 200;
        }

        // The donation the invoice was created for, as the API reports it.
        $apiDonationId = $isBtcpay ? ($invoice['metadata']['orderId'] ?? null) : ($invoice['orderId'] ?? ($invoice['metadata']['orderNumber'] ?? null));
        $apiDonationId = is_numeric($apiDonationId) ? (int) $apiDonationId : 0;

        $record = Coinsnap_GiveWP_Security::get_record($invoiceId);
        if ($record === null) {
            $record = $this->legacyRecord($invoiceId, $invoice, $apiDonationId, $cfg['provider']);
        }
        if ($record === null || (int) $record['donation_id'] !== $apiDonationId || $record['provider'] !== $cfg['provider']) {
            Coinsnap_GiveWP_Security::log('info', 'not ours', $invoiceId);
            return 200;
        }

        $donation = Donation::find((int) $record['donation_id']);
        if (!$donation || $donation->gatewayId !== self::id()) {
            Coinsnap_GiveWP_Security::log('info', 'not ours', $invoiceId);
            return 200;
        }

        $status = (string) ($invoice['status'] ?? '');
        if ($claimsPaid && $status !== 'Settled') {
            // Signed, says paid, but the API disagrees: the secret may have leaked. Change nothing.
            Coinsnap_GiveWP_Security::log('security', 'forged status', $invoiceId);
            return 200;
        }
        $current = $donation->status->getValue();
        // Only states this plugin may move a donation out of. Completed, refunded, revoked
        // or any status the merchant set by hand never change from a webhook (6.8).
        $unpaid = in_array($current, ['pending', 'processing'], true);

        if ($status === 'Settled') {
            if (!Coinsnap_GiveWP_Security::invoice_pays($invoice, (string) $record['amount'], (string) $record['currency'], $isBtcpay)) {
                $forged = $claimsPaid && ($invoice['additionalStatus'] ?? '') !== 'Marked';
                Coinsnap_GiveWP_Security::log($forged ? 'security' : 'info', $forged ? 'forged status' : 'amount mismatch', $invoiceId);
                return 200;
            }
            // A late payment may still complete a donation an earlier invoice expired.
            $latePayable = in_array($current, ['cancelled', 'abandoned', 'failed'], true) && !Coinsnap_GiveWP_Security::donation_has_paid_invoice((int) $donation->id);
            if (!$unpaid && !$latePayable) {
                Coinsnap_GiveWP_Security::log('info', 'already processed', $invoiceId);
                return 200;
            }
            return $this->completeOnce($donation, $invoiceId);
        }

        if ($status === 'Processing') {
            if ($current === 'pending') {
                $this->setDonationStatus($donation, (string) give_get_option('coinsnap_processing_status'), $invoiceId);
            }
            Coinsnap_GiveWP_Security::log('info', 'status Processing', $invoiceId);
            return 200;
        }

        if ($status === 'Expired') {
            // Another invoice of the same donation may have paid it.
            if ($unpaid && !Coinsnap_GiveWP_Security::donation_has_paid_invoice((int) $donation->id)) {
                $this->setDonationStatus($donation, (string) give_get_option('coinsnap_expired_status'), $invoiceId);
            }
            Coinsnap_GiveWP_Security::log('info', 'status Expired', $invoiceId);
            return 200;
        }

        // New, Invalid or a status this plugin doesn't know: change nothing.
        Coinsnap_GiveWP_Security::log('info', 'status ' . preg_replace('/[^A-Za-z]/', '', $status), $invoiceId);
        return 200;
    }

    /**
     * Donations started before this version have no invoice record. They are accepted only
     * during a short window after the upgrade, when the invoice asked for exactly the donation's
     * amount and currency (6.7).
     */
    private function legacyRecord(string $invoiceId, array $invoice, int $donationId, string $provider): ?array {
        if ($donationId <= 0 || !Coinsnap_GiveWP_Security::in_legacy_window()) {
            return null;
        }
        $donation = Donation::find($donationId);
        if (!$donation || $donation->gatewayId !== self::id() || $donation->createdAt->getTimestamp() > (int) get_option(Coinsnap_GiveWP_Security::UPGRADED_AT)) {
            return null;
        }
        $currency = strtoupper($donation->amount->getCurrency()->getCode());
        $amount = number_format($donation->amount->getAmount() / 100, 2, '.', '');
        if (strtoupper((string) ($invoice['currency'] ?? '')) !== $currency) {
            return null;
        }
        Coinsnap_GiveWP_Security::record_invoice($invoiceId, $donationId, $provider, $amount, $currency);
        return Coinsnap_GiveWP_Security::get_record($invoiceId);
    }

    /**
     * Claims the invoice (only one request can), then completes the donation. GiveWP runs its
     * own transaction when saving, so a failed save releases the claim instead and the provider retries.
     */
    private function completeOnce(Donation $donation, string $invoiceId): int {
        if (!Coinsnap_GiveWP_Security::mark_paid_once($invoiceId)) {
            Coinsnap_GiveWP_Security::log('info', 'already processed', $invoiceId);
            return 200;
        }
        try {
            $this->setDonationStatus($donation, (string) give_get_option('coinsnap_settled_status', 'publish'), $invoiceId);
        } catch (\Throwable $e) {
            Coinsnap_GiveWP_Security::unmark_paid($invoiceId);
            Coinsnap_GiveWP_Security::log('info', 'retry: donation update failed', $invoiceId);
            return 503;
        }
        Coinsnap_GiveWP_Security::log('info', 'paid', $invoiceId);
        return 200;
    }

    private function setDonationStatus(Donation $donation, string $orderStatus, string $invoiceId): void {
        switch ($orderStatus) {
            case 'pending':
                $donation->status = DonationStatus::PENDING(); break;
            case 'processing':
                $donation->status = DonationStatus::PROCESSING(); break;    
            case 'publish':
                $donation->status = DonationStatus::COMPLETE(); break;     
            case 'refunded':
                $donation->status = DonationStatus::REFUNDED(); break;  
            case 'failed':
                $donation->status = DonationStatus::FAILED(); break;     
            case 'cancelled':
                $donation->status = DonationStatus::CANCELLED(); break;
            case 'abandoned':
                $donation->status = DonationStatus::ABANDONED(); break;
            case 'preapproval':
                $donation->status = DonationStatus::PREAPPROVAL(); break;
            case 'revoked':
                $donation->status = DonationStatus::REVOKED(); break;
            default:
                return;
        }
        $donation->gatewayTransactionId = $invoiceId;
        $donation->save();
    }

    public function getPaymentProvider() {
        return (give_get_option( 'coinsnap_provider') === 'btcpay')? 'btcpay' : 'coinsnap';
    }
    public function getWebhookUrl() {
        return esc_url_raw( add_query_arg( array( 'give-listener' => 'coinsnap' ), home_url( 'index.php' ) ) );
    }
    public function getApiKey() {
        return ($this->getPaymentProvider() === 'btcpay')? give_get_option( 'btcpay_api_key') : give_get_option( 'coinsnap_api_key');
    }
    public function getStoreId() {
        return ($this->getPaymentProvider() === 'btcpay')? give_get_option( 'btcpay_store_id') : give_get_option( 'coinsnap_store_id');
    }
    public function getApiUrl() {
        return ($this->getPaymentProvider() === 'btcpay')? give_get_option( 'btcpay_server_url') : COINSNAP_GIVEWP_SERVER_URL;
    }
}
