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
        add_action('admin_notices', array($this, 'coinsnap_notice'));
        parent::__construct($subscriptionModule);
    }
    
    public function coinsnap_notice(){
        
        $page = (filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS ))? filter_input(INPUT_GET,'page',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
        $tab = (filter_input(INPUT_GET,'tab',FILTER_SANITIZE_FULL_SPECIAL_CHARS ))? filter_input(INPUT_GET,'tab',FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
        
        if($page === 'give-settings' && $tab === 'gateways'){
        
            $coinsnap_url = $this->getApiUrl();
            $coinsnap_api_key = $this->getApiKey();
            $coinsnap_store_id = $this->getStoreId();
            $coinsnap_webhook_url = $this->get_webhook_url();
                
                if(!isset($coinsnap_store_id) || empty($coinsnap_store_id)){
                    echo '<div class="notice notice-error"><p>';
                    esc_html_e('Coinsnap Store ID is not set', 'coinsnap-for-givewp');
                    echo '</p></div>';
                }

                if(!isset($coinsnap_api_key) || empty($coinsnap_api_key)){
                    echo '<div class="notice notice-error"><p>';
                    esc_html_e('Coinsnap API Key is not set', 'coinsnap-for-givewp');
                    echo '</p></div>';
                }
                
                if(!empty($coinsnap_api_key) && !empty($coinsnap_store_id)){
                    $client = new \Coinsnap\Client\Store($coinsnap_url, $coinsnap_api_key);
                    $store = $client->getStore($coinsnap_store_id);
                    if (!empty($store)) {
                        echo '<div class="notice notice-success"><p>';
                        esc_html_e('Established connection to Coinsnap Server', 'coinsnap-for-givewp');
                        echo '</p></div>';
                        
                        if ( ! $this->webhookExists( $coinsnap_store_id, $coinsnap_api_key, $coinsnap_webhook_url ) ) {
                            if ( ! $this->registerWebhook( $coinsnap_store_id, $coinsnap_api_key, $coinsnap_webhook_url ) ) {
                                echo '<div class="notice notice-error"><p>';
                                esc_html_e('Unable to create webhook on Coinsnap Server', 'coinsnap-for-givewp');
                                echo '</p></div>';
                            }
                            else {
                                echo '<div class="notice notice-success"><p>';
                                esc_html_e('Successfully registered a new webhook on Coinsnap Server', 'coinsnap-for-givewp');
                                echo '</p></div>';
                            }
                        }
                        else {
                            echo '<div class="notice notice-info"><p>';
                            esc_html_e('Webhook already exists, skipping webhook creation', 'coinsnap-for-givewp');
                            echo '</p></div>';
                        }
                    }
                    else {
                        echo '<div class="notice notice-error"><p>';
                        esc_html_e('Unable to connect to Coinsnap Server', 'coinsnap-for-givewp');
                        echo esc_html($store['result']['message']);
                        echo '</p></div>';
                    }
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
					),
				);				
               

                $settings[] = array(
                    'id'   => 'coinsnap_store_id',
                    'name' => __( 'Store ID', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Enter Store ID', 'coinsnap-for-givewp' ),
                    'type' => 'text',
                );

                $settings[] = array(
                    'id'   => 'coinsnap_api_key',
                    'name' => __( 'API Key', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Enter API Key', 'coinsnap-for-givewp' ),
                    'type' => 'text',
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
                    'type'        => 'select',
                    'default'         => 'processing',
                    'options'     => $statuses,
                );				
                $settings[] = array(
                    'id'   => 'coinsnap_desc',
                    'name' => __( 'Payment Description', 'coinsnap-for-givewp' ),
                    'desc' => __( 'Enter Payment Description', 'coinsnap-for-givewp' ),
                    'default'  => "You will be taken away to Bitcoin + Lightning to complete the donation!",
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
                throw new PaymentGatewayException(esc_html('Unable to set Webhook url.', 'coinsnap-for-givewp'));
                exit;
            }
         }      
				
        $amount =  ($donation->amount->getAmount() / 100);        
        $redirectUrl = esc_url_raw(give_get_success_page_uri());
        
        $amount = round($amount, 2);
        $buyerEmail = $donation->email;				
        $buyerName = $donation->firstName . ' ' .$donation->lastName;

        $metadata = [];
        $metadata['orderNumber'] = $donation->id;
        $metadata['customerName'] = $buyerName;

        $checkoutOptions = new \Coinsnap\Client\InvoiceCheckoutOptions();
        $checkoutOptions->setRedirectURL( $redirectUrl );
        $client =new \Coinsnap\Client\Invoice($this->getApiUrl(), $this->getApiKey());
        $camount = \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
								
        $csinvoice = $client->createInvoice(
            $this->getStoreId(),  
            $donation->amount->getCurrency()->getCode(),
            $camount,
            $donation->id,
            $buyerEmail,
            $buyerName, 
            $redirectUrl,
            COINSNAP_GIVEWP_REFERRAL_CODE,     
            $metadata,
            $checkoutOptions
	);		
		
        $payurl = $csinvoice->getData()['checkoutLink'] ;	
        wp_redirect($payurl);
        exit;
    }

	/**
	 * @inerhitDoc
	 */
	public function refundDonation(Donation $donation): PaymentRefunded
	{
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
        
        
        if ($status == 'Expired') $order_status = give_get_option('coinsnap_expired_status');
        else if ($status == 'Processing') $order_status = give_get_option('coinsnap_processing_status');
        else if ($status == 'Settled') $order_status = give_get_option('coinsnap_settled_status');
        
        
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


    public function get_webhook_url() {
        return esc_url_raw( add_query_arg( array( 'give-listener' => 'coinsnap' ), home_url( 'index.php' ) ) );
    }
    public function getApiKey() {
        return give_get_option( 'coinsnap_api_key');
    }
    public function getStoreId() {
        return give_get_option( 'coinsnap_store_id');
    }
    public function getApiUrl() {
        return COINSNAP_GIVEWP_SERVER_URL;
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
