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

require_once(dirname(__FILE__) . "/library/loader.php");
use Coinsnap\Util\Notice;
use Coinsnap\Client\Store;

class CoinsnapGivewpClass extends PaymentGateway {
    /*
    * @inheritDoc
    */
    public const WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];	 

    public function __construct(SubscriptionModule $subscriptionModule = null){

        // Settings in admin
        add_filter('give_get_sections_gateways', [$this, 'admin_payment_gateway_sections']);
        add_filter('give_get_settings_gateways', [$this, 'admin_payment_gateway_setting_fields']);
        add_action('init', array( $this, 'give_process_webhook'));
       
        
        if (is_admin()) {
            add_action('admin_notices', array($this, 'coinsnap_notice'));
            add_action( 'admin_enqueue_scripts', [$this, 'enqueueAdminScripts'] );
            add_action( 'wp_ajax_coinsnap_connection_handler', [$this, 'coinsnapConnectionHandler'] );
            add_action( 'wp_ajax_btcpay_server_apiurl_handler', [$this, 'btcpayApiUrlHandler']);
        }
        
        // Adding template redirect handling for btcpay-settings-callback.
        add_action( 'template_redirect', function(){
            global $wp_query;
            $notice = new \Coinsnap\Util\Notice();

            // Only continue on a btcpay-settings-callback request.
            if (!isset( $wp_query->query_vars['btcpay-settings-callback'])) {
                return;
            }

            $CoinsnapBTCPaySettingsUrl = admin_url('edit.php?post_type=give_forms&page=give-settings&tab=gateways&section=coinsnap&provider=btcpay');

            $rawData = file_get_contents('php://input');

            $btcpay_server_url = give_get_option( 'btcpay_server_url');
            $btcpay_api_key  = filter_input(INPUT_POST,'apiKey',FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            $client = new \Coinsnap\Client\Store($btcpay_server_url,$btcpay_api_key);
            if (count($client->getStores()) < 1) {
                $messageAbort = __('Error on verifiying redirected API Key with stored BTCPay Server url. Aborting API wizard. Please try again or continue with manual setup.', 'coinsnap-for-givewp');
                $notice->addNotice('error', $messageAbort);
                wp_redirect($CoinsnapBTCPaySettingsUrl);
            }

            // Data does get submitted with url-encoded payload, so parse $_POST here.
            if (!empty($_POST) || wp_verify_nonce(filter_input(INPUT_POST,'wp_nonce',FILTER_SANITIZE_FULL_SPECIAL_CHARS),'-1')) {
                $data['apiKey'] = filter_input(INPUT_POST,'apiKey',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? null;
                $permissions = (isset($_POST['permissions']) && is_array($_POST['permissions']))? $_POST['permissions'] : null;
                if (isset($permissions)) {
                    foreach ($permissions as $key => $value) {
                        $data['permissions'][$key] = sanitize_text_field($permissions[$key] ?? null);
                    }
                }
            }

            if (isset($data['apiKey']) && isset($data['permissions'])) {

                $apiData = new \Coinsnap\Client\BTCPayApiAuthorization($data);
                if ($apiData->hasSingleStore() && $apiData->hasRequiredPermissions()) {

                    give_update_option( 'btcpay_api_key', $apiData->getApiKey());
                    give_update_option( 'btcpay_store_id', $apiData->getStoreID());
                    give_update_option( 'coinsnap_provider', 'btcpay');

                    $notice->addNotice('success', __('Successfully received api key and store id from BTCPay Server API. Please finish setup by saving this settings form.', 'coinsnap-for-givewp'));

                    // Register a webhook.

                    if ($this->registerWebhook($apiData->getStoreID(), $apiData->getApiKey(), $this->get_webhook_url())) {
                        $messageWebhookSuccess = __( 'Successfully registered a new webhook on BTCPay Server.', 'coinsnap-for-givewp' );
                        $notice->addNotice('success', $messageWebhookSuccess, true );
                    }
                    else {
                        $messageWebhookError = __( 'Could not register a new webhook on the store.', 'coinsnap-for-givewp' );
                        $notice->addNotice('error', $messageWebhookError );
                    }
                    wp_redirect($CoinsnapBTCPaySettingsUrl);
                    exit();
                }
                else {
                    $notice->addNotice('error', __('Please make sure you only select one store on the BTCPay API authorization page.', 'coinsnap-for-givewp'));
                    wp_redirect($CoinsnapBTCPaySettingsUrl);
                    exit();
                }
            }

            $notice->addNotice('error', __('Error processing the data from Coinsnap. Please try again.', 'coinsnap-for-givewp'));
            wp_redirect($CoinsnapBTCPaySettingsUrl);
        });
        
        parent::__construct($subscriptionModule);
    }
    
    public function coinsnapConnectionHandler(){
        
        $_nonce = filter_input(INPUT_POST,'_wpnonce',FILTER_SANITIZE_STRING);
        
        if(empty($this->getApiUrl()) || empty($this->getApiKey())){
            $response = [
                    'result' => false,
                    'message' => __('GiveWP: empty gateway URL or API Key', 'coinsnap-for-givewp')
            ];
            $this->sendJsonResponse($response);
        }
        
        $_provider = $this->get_payment_provider();
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
        
        if( wp_verify_nonce($_nonce,'coinsnap-ajax-nonce') ){
            $response = ['result' => false,'message' => $_message_disconnected];

            try {
                $this_store = $store->getStore($this->getStoreId());
                
                if ($this_store['code'] !== 200) {
                    $this->sendJsonResponse($response);
                }
                
                $webhookExists = $this->webhookExists($this->getStoreId(), $this->getApiKey(), $this->get_webhook_url());

                if($webhookExists) {
                    $response = ['result' => true,'message' => $_message_connected.' ('.$connectionData.')'];
                    $this->sendJsonResponse($response);
                }

                $webhook = $this->registerWebhook( $this->getStoreId(), $this->getApiKey(), $this->get_webhook_url());
                $response['result'] = (bool)$webhook;
                $response['message'] = $webhook ? $_message_connected.' ('.$connectionData.')' : $_message_disconnected.' (Webhook)';
            }
            catch (\Exception $e) {
                $response['message'] =  __('GiveWP: API connection is not established', 'coinsnap-for-givewp');
            }

            $this->sendJsonResponse($response);
        }      
    }

    private function sendJsonResponse(array $response): void {
        echo wp_json_encode($response);
        exit();
    }
    
    public function enqueueAdminScripts() {
	// Register the CSS file
	wp_register_style( 'coinsnap-admin-styles', plugins_url('assets/css/backend-style.css', __FILE__ ), array(), COINSNAP_GIVEWP_VERSION );
	// Enqueue the CSS file
	wp_enqueue_style( 'coinsnap-admin-styles' );
        //  Enqueue admin fileds handler script
        wp_enqueue_script('coinsnap-admin-fields',plugins_url('assets/js/adminFields.js', __FILE__ ),[ 'jquery' ],COINSNAP_GIVEWP_VERSION,true);
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
        $_nonce = filter_input(INPUT_POST,'apiNonce',FILTER_SANITIZE_STRING);
        if ( !wp_verify_nonce( $_nonce, 'coinsnap-ajax-nonce' ) ) {
            wp_die('Unauthorized!', '', ['response' => 401]);
        }
        
        if ( current_user_can( 'manage_options' ) ) {
            $host = filter_var(filter_input(INPUT_POST,'host',FILTER_SANITIZE_STRING), FILTER_VALIDATE_URL);

            if ($host === false || (substr( $host, 0, 7 ) !== "http://" && substr( $host, 0, 8 ) !== "https://")) {
                wp_send_json_error("Error validating BTCPayServer URL.");
            }

            $permissions = array_merge([
		'btcpay.store.canviewinvoices',
		'btcpay.store.cancreateinvoice',
		'btcpay.store.canviewstoresettings',
		'btcpay.store.canmodifyinvoices'
            ],
            [
		'btcpay.store.cancreatenonapprovedpullpayments',
		'btcpay.store.webhooks.canmodifywebhooks',
            ]);

            try {
		// Create the redirect url to BTCPay instance.
		$url = \Coinsnap\Client\BTCPayApiKey::getAuthorizeUrl(
                    $host,
                    $permissions,
                    'GiveWP',
                    true,
                    true,
                    home_url('?btcpay-settings-callback'),
                    null
		);

		// Store the host to options before we leave the site.
		give_update_option('btcpay_server_url', $host);

		// Return the redirect url.
		wp_send_json_success(['url' => $url]);
            }
            
            catch (\Throwable $e) {
                
            }
	}
        wp_send_json_error("Error processing Ajax request.");
    }    
    
    public function coinsnap_notice(){
        
        $notices = new Notice(); 
        $notices->showNotices();
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
                        'desc' => '<div id="coinsnapConnectionStatus"></div>'
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
                    'type' => 'text',
                    'class' => 'coinsnap'
                );
                
                //  BTCPay fields
                $settings[] = array(
                    'id' => 'btcpay_server_url',
                    'name'       => __( 'BTCPay server URL*', 'coinsnap-for-givewp' ),
                    'type'        => 'text',
                    'desc'        => __( '<a href="#" class="btcpay-apikey-link">Check connection</a>', 'coinsnap-for-givewp' ).'<br/><br/><button class="button btcpay-apikey-link" id="btcpay_wizard_button" target="_blank">'. __('Generate API key','coinsnap-for-givewp').'</button>',
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
                    'type' => 'text',
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
                    'id' => 'cnp_give_title',
                    'type' => 'sectionend',
		);

		break;

            }

        return $settings;
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
                <p>".give_get_option( 'coinsnap_desc')."</p>
            </div>";
	}

    /**
    * @inheritDoc
    */
    public function createPayment(Donation $donation, $gatewayData): GatewayCommand {
  
        $webhook_url = $this->get_webhook_url();
				
        if (! $this->webhookExists($this->getStoreId(), $this->getApiKey(), $webhook_url)){
            if (! $this->registerWebhook($this->getStoreId(), $this->getApiKey(),$webhook_url)) {                
                throw new PaymentGatewayException(esc_html__('Unable to set Webhook URL.', 'coinsnap-for-givewp'));
            }
         }      
				
        $amount =  round(($donation->amount->getAmount() / 100), 2);
        $currency = $donation->amount->getCurrency()->getCode();
        $redirectUrl = esc_url_raw(give_get_success_page_uri());
        
        $buyerEmail = $donation->email;				
        $buyerName = $donation->firstName . ' ' .$donation->lastName;

        $metadata = [];
        $metadata['orderNumber'] = $donation->id;
        $metadata['customerName'] = $buyerName;

        $client = new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
        
        $_provider = $this->get_payment_provider();
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
                    $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper( $currency ),'bitcoin');
                }
                elseif($storePaymentMethods['result']['lightning']){
                    $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper( $currency ),'lightning');
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
            $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper( $currency ));
        }
                
        if($checkInvoice['result'] === true){
        
            $camount = \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
            
            // Handle Sats-mode because BTCPay does not understand SAT as a currency we need to change to BTC and adjust the amount.
            if ($currency === 'SATS' && $_provider === 'btcpay') {
                $currency = 'BTC';
                $amountBTC = bcdiv($camount->__toString(), '100000000', 8);
                $camount = \Coinsnap\Util\PreciseNumber::parseString($amountBTC);
            }

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

                $payurl = $csinvoice->getData()['checkoutLink'] ;	
                wp_redirect($payurl);
            }
            catch (\Throwable $e){
                $errorMessage = __( 'API connection is not established', 'coinsnap-for-ninja-forms' );
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

    /**
     * @inerhitDoc
     */
    public function refundDonation(Donation $donation): PaymentRefunded {
		// Step 1: refund the donation with your gateway.
		// Step 2: return a command to complete the refund.
		return new PaymentRefunded();
    }

    public function give_process_webhook(){
				
        if ( null === ( filter_input(INPUT_GET,'give-listener') ) || filter_input(INPUT_GET,'give-listener') !== 'coinsnap' ) {
            return;
        }
        
        $notify_json = file_get_contents('php://input');        
        
        $notify_ar = json_decode($notify_json, true);
        $invoice_id = $notify_ar['invoiceId'];        
        
        try {
            $client = new \Coinsnap\Client\Invoice( $this->getApiUrl(), $this->getApiKey() );			
            $csinvoice = $client->getInvoice($this->getStoreId(), $invoice_id);
            $status = $csinvoice->getData()['status'] ;
            $donation_id = $csinvoice->getData()['orderId'] ;
            
    
        }catch (\Throwable $e) {									
            
            echo "Error";
            exit;
        }
                
        $order_status = 'pending';
        
        
        if ($status == 'Expired'){ $order_status = give_get_option('coinsnap_expired_status'); }
        else if ($status == 'Processing'){ $order_status = give_get_option('coinsnap_processing_status'); }
        else if ($status == 'Settled'){ $order_status = give_get_option('coinsnap_settled_status'); }
        
        
        if (isset($donation_id)){
            $donation = Donation::find($donation_id);            
            if ( $donation ) {

                switch ($order_status) {
                    case 'pending':
                        $donation->status = DonationStatus::PENDING();            
                        break;
                    case 'processing':
                         $donation->status = DonationStatus::PROCESSING();
                         break;    
                    case 'publish':
                          $donation->status = DonationStatus::COMPLETE();
                          break;     
                    case 'refunded':
                          $donation->status = DonationStatus::REFUNDED();
                          break;  
                    case 'failed':
                           $donation->status = DonationStatus::FAILED();
                           break;     
                    case 'cancelled':
                            $donation->status = DonationStatus::CANCELLED();
                            break;
                    case 'abandoned':
                            $donation->status = DonationStatus::ABANDONED();
                            break;
                    case 'preapproval':
                            $donation->status = DonationStatus::PREAPPROVAL();
                            break;
                    case 'revoked':
                            $donation->status = DonationStatus::REVOKED();            
                            break;
                }                
                $donation->gatewayTransactionId = $invoice_id;
                $donation->save();
            }									
        }
        
        echo "OK";
        exit;
        
    }       


    public function get_payment_provider() {
        return (give_get_option( 'coinsnap_provider') === 'btcpay')? 'btcpay' : 'coinsnap';
    }
    public function get_webhook_url() {
        return esc_url_raw( add_query_arg( array( 'give-listener' => 'coinsnap' ), home_url( 'index.php' ) ) );
    }
    public function getApiKey() {
        return ($this->get_payment_provider() === 'btcpay')? give_get_option( 'btcpay_api_key') : give_get_option( 'coinsnap_api_key');
    }
    public function getStoreId() {
        return ($this->get_payment_provider() === 'btcpay')? give_get_option( 'btcpay_store_id') : give_get_option( 'coinsnap_store_id');
    }
    public function getApiUrl() {
        return ($this->get_payment_provider() === 'btcpay')? give_get_option( 'btcpay_server_url') : COINSNAP_GIVEWP_SERVER_URL;
    }	

    public function webhookExists(string $storeId, string $apiKey, string $webhook): bool {	
        try {		
            $whClient = new \Coinsnap\Client\Webhook( $this->getApiUrl(), $apiKey );		
            $Webhooks = $whClient->getWebhooks( $storeId );            
            
            foreach ($Webhooks as $Webhook){					
                //self::deleteWebhook($storeId,$apiKey, $Webhook->getData()['id']);
                if ($Webhook->getData()['url'] == $webhook) return true;	
            }
        }catch (\Throwable $e) {			
            return false;
        }
    
        return false;
    }
    public  function registerWebhook(string $storeId, string $apiKey, string $webhook): bool {	
        try {			
            $whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);
            
            $webhook = $whClient->createWebhook(
                $storeId,   //$storeId
                $webhook, //$url
                self::WEBHOOK_EVENTS,   
                null    //$secret
            );		
            
            return true;
        } catch (\Throwable $e) {
            return false;	
        }

        return false;
    }

    public function deleteWebhook(string $storeId, string $apiKey, string $webhookid): bool {	    
        
        try {			
            $whClient = new \Coinsnap\Client\Webhook($this->getApiUrl(), $apiKey);
            
            $webhook = $whClient->deleteWebhook(
                $storeId,   //$storeId
                $webhookid, //$url			
            );					
            return true;
        } catch (\Throwable $e) {
            
            return false;	
        }
    }
	
}
