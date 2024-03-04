<?php
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

define( 'SERVER_PHP_VERSION', '7.4' );
define( 'COINSNAP_VERSION', '1.0.0' );
define( 'COINSNAP_REFERRAL_CODE', '' );
define( 'COINSNAP_PLUGIN_ID', 'coinsnap-for-givewp' );
define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' );

require_once(dirname(__FILE__) . "/library/autoload.php");

class CoinsnapGivewpClass extends PaymentGateway
{
	/*
* @inheritDoc
*/
    public const WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];	 

	public function __construct(SubscriptionModule $subscriptionModule = null)
	{
		// Settings in admin
		add_filter('give_get_sections_gateways', [$this, 'admin_payment_gateway_sections']);
		add_filter('give_get_settings_gateways', [$this, 'admin_payment_gateway_setting_fields']);
        add_action('init', array( $this, 'give_process_webhook'));      
       parent::__construct($subscriptionModule);
	}

	public static function id(): string
	{
		return 'coinsnap-gateway';
	}
	function admin_payment_gateway_sections($sections)
	{
		$sections['coinsnap'] = __('Coinsnap', 'give-coinsnap');

		return $sections;
	}

	function admin_payment_gateway_setting_fields($settings)
	{
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
                    'name' => __( 'Store ID', 'give-coinsnap' ),
                    'desc' => __( 'Enter Store ID', 'give-coinsnap' ),
                    'type' => 'text',
                );

                $settings[] = array(
                    'id'   => 'coinsnap_api_key',
                    'name' => __( 'API Key', 'give-coinsnap' ),
                    'desc' => __( 'Enter API Key', 'give-coinsnap' ),
                    'type' => 'text',
                );
                $settings[] = array(
                    'id'   => 'coinsnap_expired_status',
                    'name' => __( 'Expired Status', 'give-coinsnap' ),
                    'desc' => __( 'Select Expired Status', 'give-coinsnap' ),
                    'type'        => 'select',
                    'default'         => 'cancelled',
                    'options'     => $statuses,
                );
                $settings[] = array(
                    'id'   => 'coinsnap_settled_status',
                    'name' => __( 'Settled Status', 'give-coinsnap' ),
                    'desc' => __( 'Select Settled Status', 'give-coinsnap' ),
                    'type'        => 'select',
                    'default'         => 'publish',
                    'options'     => $statuses,
                );
                $settings[] = array(
                    'id'   => 'coinsnap_processing_status',
                    'name' => __( 'Processing Status', 'give-coinsnap' ),
                    'desc' => __( 'Select Processing Status', 'give-coinsnap' ),
                    'type'        => 'select',
                    'default'         => 'processing',
                    'options'     => $statuses,
                );				
                $settings[] = array(
                    'id'   => 'coinsnap_desc',
                    'name' => __( 'Payment Description', 'give-coinsnap' ),
                    'desc' => __( 'Enter Payment Description', 'give-coinsnap' ),
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
	public function getId(): string
	{
		return self::id();
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return __('Coinsnap', 'give-coinsnap');
	}

	/**
	 * @inheritDoc
	 */
	public function getPaymentMethodLabel(): string
	{
		return __('Bitcoin + Lightning', 'give-coinsnap');
	}

	/**
	 * @inheritDoc
	 */
	public function getLegacyFormFieldMarkup(int $formId, array $args): string
	{		
        return "<div class='coinsnap-givewp-help-text'>
            <p>".give_get_option( 'coinsnap_desc')."</p>
        </div>";
	}

	/**
	 * @inheritDoc
	 */
	public function createPayment(Donation $donation, $gatewayData): GatewayCommand
	{
  
        $webhook_url = $this->get_webhook_url();
        
				
        if (! $this->webhookExists($this->getStoreId(), $this->getApiKey(), $webhook_url)){
            if (! $this->registerWebhook($this->getStoreId(), $this->getApiKey(),$webhook_url)) {                
                throw new PaymentGatewayException(__('unable to set Webhook url.', 'give-coinsnap'));
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
			    	COINSNAP_REFERRAL_CODE,     
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

    public function give_process_webhook() {
				
        if ( ! isset( $_GET['give-listener'] ) || $_GET['give-listener'] !== 'coinsnap' ) {
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
        return COINSNAP_SERVER_URL;
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
