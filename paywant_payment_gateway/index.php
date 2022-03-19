<?php
/*
 * Plugin Name: Paywant Checkout Modülü for WooCommerce
 * Plugin URI: http://www.paywant.com
 * Description: Paywant aracılığıyla WooCommerce üzerinden satış yapmak için kullanabileceğiniz modül
 * Version: 1.0.0
 * Author: paywant
 * Author URI: https://github.com/paywant
 */

if (!defined('ABSPATH'))
{
    exit;
}

define('PAYWANT_VERSION', '1.0.0');
define('PAYWANT_PLUGIN_DIR', plugin_dir_url(__FILE__));

add_action('plugins_loaded', 'WooCommerce_Paywant');
add_action('wp_enqueue_scripts', 'paywant_init_scripts');
add_filter('woocommerce_payment_gateways', 'paywant_init_woocommerce');

function paywant_init_woocommerce()
{
    $methods[] = 'Paywant';

    return $methods;
}

function paywant_init_scripts()
{
    wp_enqueue_script('paywant_custom_js', 'https://secure.paywant.com/js/paywant.js');
}

function WooCommerce_Paywant()
{
    class Paywant extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'paywant';
            $this->icon = PAYWANT_PLUGIN_DIR . 'paywant.png';
            $this->has_fields = true;
            $this->method_title = 'Paywant Checkout';
            $this->method_description = 'Paywant sistemi üzerinden alışverişinizi tamamlayabilirsiniz.';
            $this->order_button_text = __('Paywant\'ya ilerle', 'woocommerce');

            $this->paywant_init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->paywant_api_key = trim($this->get_option('paywant_api_key'));
            $this->paywant_api_secret = trim($this->get_option('paywant_api_secret'));

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_receipt_' . $this->id, [$this, 'paywant_receipt_page']);
            add_action('woocommerce_api_paywant', [$this, 'paywant_response']);
        }

        public function paywant_init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Ödeme Yöntemi Durumu', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Aktif', 'woocommerce'),
                    'default' => 'yes',
                ],
                'title' => [
                    'title' => __('Ödeme Yöntemi İsmi', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Ödeme sırasında gösterilecek olan ödeme yöntemi ismi.', 'woocommerce'),
                    'default' => __('Paywant', 'woocommerce'),
                    'desc_tip' => true,
                ],
                'paywant_api_key' => [
                    'title' => __('Paywant Mağaza API Anahtarı', 'woocommerce'),
                    'type' => 'text',
                    'default' => '',
                ],
                'paywant_api_secret' => [
                    'title' => __('Paywant Mağaza API Secret', 'woocommerce'),
                    'type' => 'text',
                    'default' => '',
                ],
            ];
        }

        public function admin_options()
        {
            ?>
            <h2><?php _e('Paywant WooCommerce Ödeme Modülü', 'woocommerce'); ?></h2>
            <table class="form-table">
            <?php $this->generate_settings_html(); ?>
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label>Paywant Callback URL</label>
                </th>
                <td class="forminp">
                    <fieldset>
                        <input class="input-text regular-input" type="text" value="<?php echo $this->getCallbackUrl(); ?>" readonly>
                    </fieldset>
                </td>
		    </tr>
            </table>
            <?php
        }

        public function process_payment($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);

            // Mark as on-hold (we're awaiting the cheque)
            $order->update_status('unpaid', __('Paywant üzerinden ödeme işleminin tamamlanması bekleniyor.', 'woocommerce'));

            // Remove cart
            $woocommerce->cart->empty_cart();

            // Return thankyou redirect
            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            ];
        }

        public function paywant_receipt_page($order_id)
        {
            $order = new WC_Order($order_id);

			$userID		= $order->get_customer_id();							// kullanıcı id
			$userEmail	= $order->get_billing_email();		// kullanıcı e-mail adresi
			$userAccountName	= $order->get_billing_email(); 						// 
			$userIPAdresi = $order->get_customer_ip_address();					// kullanıcının ip adresi
			
			$hashOlustur = base64_encode(hash_hmac('sha256',"$userAccountName|$userEmail|$userID".$this->get_option('paywant_api_key'),$this->get_option('paywant_api_secret'),true));
			
			$productData = array(
				"name" =>  $order_id." Sipariş Ödemesi", // Ürün adı 
				"amount" => bcmul(bcmul($order->get_total(),1,2),100), 				// Ürün fiyatı, 10 TL : 1000
				"extraData" => $order_id,				// Notify sayfasına iletilecek ekstra veri
				"paymentChannel" => "0",	// Bu ödeme için kullanılacak ödeme kanalları
				"commissionType" => 1			// Komisyon tipi, 1: Üstlen, 2: Yansıt ( Sadece alt yapı komisyonlarını yansıtır), 3: Tüm komisyonları yansıt (Paywant komisyonu dahil yansıtır)
			);

			$requestBody = array(
				'apiKey' => $this->get_option('paywant_api_key'),
				'hash' => $hashOlustur,
				'userAccountName'=> $userAccountName,
				'userEmail' => $userEmail,
				'userIPAddress' => $userIPAdresi,
				'userID' => $userID,
				'proApi' => true,
				'productData' => $productData
			);
			
            $args = [
				'method' => 'POST',
                'timeout' => '90',
                'redirection' => '5',
                'httpversion' => '1.0',
                'blocking' => true,
                'body' => $requestBody,
            ];

            $response = wp_remote_post('https://secure.paywant.com/payment/token', $args);

            if (is_wp_error($response))
            {
                return 'Failed to handle response. Error: ' . $response->get_error_message();
            }

            $result = wp_remote_retrieve_body($response);

            try
            {
                $result = json_decode($result);
            }
            catch (Exception $ex)
            {
                return 'Failed to handle response';
            }

            if ($result->status == true)
            {
                ?>
                <div id="paywant-area">
                    <iframe src="<?php echo $result->message; ?>" id="paywantiframe" frameborder="0" scrolling="no" style="width: 100%;"></iframe>
                </div>
				<script type="text/javascript">
					setTimeout(function(){ 
						iFrameResize({},'#paywantiframe');
					}, 1000);
				</script>
			<?php
            }
            else
            {
                echo $result;
            }
        }

        public function paywant_response()
        {
            if (!$_POST )
            {
                die('Paywant.com');
            }


	
			  $SiparisID = $_POST["SiparisID"];
			  $ExtraData= $_POST["ExtraData"];
			  $UserID= $_POST["UserID"];
			  $ReturnData= $_POST["ReturnData"];
			  $Status= $_POST["Status"];
			  $OdemeKanali= $_POST["OdemeKanali"];
			  $OdemeTutari= $_POST["OdemeTutari"];
			  $NetKazanc= $_POST["NetKazanc"];
			  $Hash= $_POST["Hash"];

		   if($SiparisID == "" || $ExtraData == "" || $UserID == "" || $ReturnData == "" || $Status == "" || $OdemeKanali == "" || $OdemeTutari == "" || $NetKazanc == "" || $Hash == "")
		   {
			  echo "eksik veri";
			  exit();
		   }
		

			$hashKontrol = base64_encode(hash_hmac('sha256',"$SiparisID|$ExtraData|$UserID|$ReturnData|$Status|$OdemeKanali|$OdemeTutari|$NetKazanc".$this->get_option('paywant_api_key'),$this->get_option('paywant_api_secret'),true));
		
			if($Hash != $hashKontrol)
				exit("hash hatali");
		   

     
            $order_id = $ExtraData;
            $order = new WC_Order($order_id);
            if ($order->get_status() != 'processing' || $order->get_status() != 'completed')
            {
                if($Status  == "100")
                {
                    $order->payment_complete();
                    $order->add_order_note('Ödeme onaylandı.<br />## Paywant ##<br />#  Paywant Id: ' . $SiparisID . '<br/># Sipariş numarası: ' . $order_id);
                }
                else
                {
                    $order->update_status('failed', 'Sipariş iptal edildi.<br />## Paywant ##<br />Paywant Id: ' . $SiparisID . ' - Sipariş Id: ' . $order_id, 'woothemes');
                }
            }
            else
            {
                die('Paywant: Unexpected order status: ' . $order->get_status() . ' - Expected order status: processing OR completed');
            }

            die('OK');
        }

        private function getCallbackUrl()
        {
            return str_replace('https:', 'http:', add_query_arg('wc-api', 'paywant', home_url('/')));
        }
    }
}
