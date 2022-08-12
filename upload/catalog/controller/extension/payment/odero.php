<?php
class ControllerExtensionPaymentOdero extends Controller {

    private $module_version      = VERSION;
    private $module_product_name = 'eleven-2.3';

    public function index() {

        $this->load->language('extension/payment/odero');
        $data['form_class']         = $this->config->get('payment_odero_design');
        $data['form_type']          = $this->config->get('payment_odero_design');
        $data['config_theme']       = $this->config->get('config_theme');
        $data['onepage_desc']       = $this->language->get('odero_onepage_desc');

        if($data['form_type'] == 'onepage')
            $data['form_class'] = 'responsive';


        $data['user_login_check']   = $this->customer->isLogged();

        return $this->load->view('extension/payment/odero_form',$data);
    }

    private function setcookieSameSite($name, $value, $expire, $path, $domain, $secure, $httponly) {

        if (PHP_VERSION_ID < 70300) {

            setcookie($name, $value, $expire, "$path; samesite=None", $domain, $secure, $httponly);
        }
        else {
            setcookie($name, $value, [
                'expires' => $expire,
                'path' => $path,
                'domain' => $domain,
                'samesite' => 'None',
                'secure' => $secure,
                'httponly' => $httponly
            ]);


        }
    }

    private function checkAndSetCookieSameSite(){

        $checkCookieNames = array('PHPSESSID','OCSESSID','default','PrestaShop-','wp_woocommerce_session_');

        foreach ($_COOKIE as $cookieName => $value) {
            foreach ($checkCookieNames as $checkCookieName){
                if (stripos($cookieName,$checkCookieName) === 0) {
                    $this->setcookieSameSite($cookieName,$_COOKIE[$cookieName], time() + 86400, "/", $_SERVER['SERVER_NAME'],true, true);
                }
            }
        }
    }

    public function getCheckoutFormResponse() {

        $this->checkAndSetCookieSameSite();

        $storeName = $this->config->get('config_name');
        $merchantId = $this->config->get('payment_odero_merchant_id');
        $secretKey = $this->config->get('payment_odero_merchant_token');
        $stage = $this->config->get('payment_odero_api_channel')  == 'live' ? \Oderopay\OderoConfig::ENV_PROD :  \Oderopay\OderoConfig::ENV_STG;

        $oderoConfig = new \Oderopay\OderoConfig($storeName,$merchantId, $secretKey, $stage);
        $oderopay = new \Oderopay\OderoClient($oderoConfig);

        $this->load->model('checkout/order');
        $this->load->model('setting/setting');
        $this->load->model('extension/payment/odero');
        $this->load->model('tool/image');

        $order_id                              = (int) $this->session->data['order_id'];
        $customer_id 	                       = (int) isset($this->session->data['customer_id']) ? $this->session->data['customer_id'] : 0;
        $order_info 	                       = $this->model_checkout_order->getOrder($order_id);
        $products                              = $this->cart->getProducts();

        $this->session->data['conversation_id'] = $order_id;

        $order_info['payment_address']         = $order_info['payment_address_1']." ".$order_info['payment_address_2'];
        $order_info['shipping_address']        = $order_info['shipping_address_1']." ".$order_info['shipping_address_2'];


        $paymentModel = new Oderopay\Model\Payment\Payment();
        $paymentModel->setAmount($this->priceParser($this->itemPriceSubTotal($products) * $order_info['currency_value']));
        $paymentModel->setCurrency($order_info['currency_code']);
        $paymentModel->setExtOrderId($order_id);
        $paymentModel->setReturnUrl($this->url->link('extension/payment/odero/errorpage'));
        //$paymentModel->setCardToken($this->model_extension_payment_odero->findUserCardToken($customer_id));
        $paymentModel->setExtOrderUrl($this->url->link('account/order/info', 'order_id=' . $order_id, true));

        if ($paymentModel->getAmount() === 0) {
            return false;
        }


        $billingAddress = new \Oderopay\Model\Address\BillingAddress();
        $billingAddress->setAddress($order_info['payment_address']);
        $billingAddress->setCity($this->dataCheck($order_info['payment_zone']));
        $billingAddress->setCountry($this->dataCheck($order_info['payment_iso_code_3']));

        $shippingAddress = new \Oderopay\Model\Address\DeliveryAddress();
        $shippingAddress->setAddress($order_info['shipping_address']);
        $shippingAddress->setCity($this->dataCheck($order_info['shipping_zone']));
        $shippingAddress->setCountry($this->dataCheck($order_info['payment_iso_code_3']));
        $shippingAddress->setDeliveryType($this->language->get('text_shipping'));

        $customer = new \Oderopay\Model\Payment\Customer();
        $customer->setEmail($this->dataCheck($order_info['email']));
        $customer->setPhoneNumber($this->dataCheck($order_info['telephone']));
        $customer->setBillingInformation($billingAddress);
        $customer->setDeliveryInformation($shippingAddress);

        $paymentModel->setCustomer($customer);

        $orderProducts = [];

        foreach ($products as $key => $product) {
            $price = $product['price'] * $order_info['currency_value'];

            if($price) {
                $image = $this->model_tool_image->resize($product['image'], $this->config->get('config_image_popup_width') ?? 250, $this->config->get('config_image_popup_height') ?? 250);
                $basketProduct = new \Oderopay\Model\Payment\BasketItem();
                $basketProduct
                    ->setExtId($product['product_id'])
                    ->setImageUrl($image)
                    ->setName($product['name'])
                    ->setPrice($this->priceParser($price))
                    ->setQuantity($product['quantity']);

                $orderProducts[] = $basketProduct;

            }
        }

        $shipping = $this->shippingInfo();

        if(!empty($shipping) && $shipping['cost'] && $shipping['cost'] != '0.00') {

            $basketProduct = new \Oderopay\Model\Payment\BasketItem();
            $basketProduct
                ->setExtId( $this->language->get('text_shipping'))
                ->setImageUrl($image)
                ->setName($this->language->get('text_shipping'))
                ->setPrice($this->priceParser($shipping['cost'] * $order_info['currency_value']))
                ->setQuantity(1);

            $orderProducts[] = $basketProduct;
        }

        $paymentModel->setProducts($orderProducts);


        $payment = $oderopay->payments->create($paymentModel); //PaymentIntentResponse

        //save to db for callback message
        if($payment->isSuccess()){
            $this->model_extension_payment_odero->insertPaymentIntent($payment, $order_id);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($payment->toArray()));
    }

    public function errorPage() {

        $data['continue'] = $this->url->link('common/home');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');
        $data['error_title']    = $this->language->get('payment_failed');
        $data['error_message']  = $this->session->data['odero_error_message'];
        $data['error_icon']     = 'catalog/view/theme/default/image/payment/odero_error_icon.png';

        return $this->response->setOutput($this->load->view('extension/payment/odero_error', $data));

    }

    public function successPage() {

        if(!isset($this->session->data['order_id'])) {
            return $this->response->redirect($this->url->link('common/home'));
        }

        $this->load->language('account/order');

        $order_id = $this->session->data['order_id'];

        if (isset($this->session->data['order_id'])) {
            $this->cart->clear();

            unset($this->session->data['shipping_method']);
            unset($this->session->data['shipping_methods']);
            unset($this->session->data['payment_method']);
            unset($this->session->data['payment_methods']);
            unset($this->session->data['guest']);
            unset($this->session->data['comment']);
            unset($this->session->data['coupon']);
            unset($this->session->data['reward']);
            unset($this->session->data['voucher']);
            unset($this->session->data['vouchers']);
            unset($this->session->data['totals']);
        }

        $this->load->model('account/order');
        $this->load->model('catalog/product');
        $this->load->model('checkout/order');
        $this->load->model('tool/upload');

        $order_info = $this->model_checkout_order->getOrder($order_id);

        // Products
        $data['products'] = array();

        $products = $this->model_account_order->getOrderProducts($order_id);

        foreach ($products as $product) {
            $option_data = array();

            $options = $this->model_account_order->getOrderOptions($order_id, $product['order_product_id']);

            foreach ($options as $option) {
                if ($option['type'] != 'file') {
                    $value = $option['value'];
                } else {
                    $upload_info = $this->model_tool_upload->getUploadByCode($option['value']);

                    if ($upload_info) {
                        $value = $upload_info['name'];
                    } else {
                        $value = '';
                    }
                }

                $option_data[] = array(
                    'name'  => $option['name'],
                    'value' => (utf8_strlen($value) > 20 ? utf8_substr($value, 0, 20) . '..' : $value)
                );
            }

            $product_info = $this->model_catalog_product->getProduct($product['product_id']);

            if ($product_info) {
                $reorder = $this->url->link('account/order/reorder', 'order_id=' . $order_id . '&order_product_id=' . $product['order_product_id'], true);
            } else {
                $reorder = '';
            }

            $data['products'][] = array(
                'name'     => $product['name'],
                'model'    => $product['model'],
                'option'   => $option_data,
                'quantity' => $product['quantity'],
                'price'    => $this->currency->format($product['price'] + ($this->config->get('config_tax') ? $product['tax'] : 0), $order_info['currency_code'], $order_info['currency_value']),
                'total'    => $this->currency->format($product['total'] + ($this->config->get('config_tax') ? ($product['tax'] * $product['quantity']) : 0), $order_info['currency_code'], $order_info['currency_value']),
                'reorder'  => $reorder,
                'return'   => $this->url->link('account/return/add', 'order_id=' . $order_info['order_id'] . '&product_id=' . $product['product_id'], true)
            );
        }

        // Voucher
        $data['vouchers'] = array();

        $vouchers = $this->model_account_order->getOrderVouchers($order_id);

        foreach ($vouchers as $voucher) {
            $data['vouchers'][] = array(
                'description' => $voucher['description'],
                'amount'      => $this->currency->format($voucher['amount'], $order_info['currency_code'], $order_info['currency_value'])
            );
        }

        // Totals
        $data['totals'] = array();

        $totals = $this->model_account_order->getOrderTotals($order_id);

        foreach ($totals as $total) {
            $data['totals'][] = array(
                'title' => $total['title'],
                'text'  => $this->currency->format($total['value'], $order_info['currency_code'], $order_info['currency_value']),
            );
        }

        $data['comment'] = nl2br($order_info['comment']);

        // History
        $data['histories'] = array();

        $results = $this->model_account_order->getOrderHistories($order_id);

        foreach ($results as $result) {
            $data['histories'][] = array(
                'date_added' => date($this->language->get('date_format_short'), strtotime($result['date_added'])),
                'status'     => $result['status'],
                'comment'    => $result['notify'] ? nl2br($result['comment']) : ''
            );
        }

        $this->document->addStyle('catalog/view/javascript/odero/odero_success.css');

        $language = $this->config->get('payment_odero_language');
        $str_language = mb_strtolower($language);

        if(empty($str_language) or $str_language == 'null')
        {
            $locale              = $this->language->get('code');
        }else {
            $locale              = $str_language;
        }

        $data['locale'] = $locale;

        $data['continue'] = $this->url->link('account/order', '', true);

        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');
        $data['success_icon']     = 'catalog/view/theme/default/image/payment/odero_success_icon.png';

        /* Remove Order */
        unset($this->session->data['order_id']);

        return $this->response->setOutput($this->load->view('extension/payment/odero_success', $data));
    }

    private function dataCheck($data) {
        if(!$data || $data == ' ') {
            $data = "NOT PROVIDED";
        }
        return $data;
    }

    private function shippingInfo() {

        if(isset($this->session->data['shipping_method'])) {

            $shipping_info      = $this->session->data['shipping_method'];

        } else {

            $shipping_info = false;
        }

        if($shipping_info) {

            if ($shipping_info['tax_class_id']) {

                $shipping_info['tax'] = $this->tax->getRates($shipping_info['cost'], $shipping_info['tax_class_id']);

            } else {

                $shipping_info['tax'] = false;
            }

        }

        return $shipping_info;
    }

    private function itemPriceSubTotal($products) {

        $price = 0;

        foreach ($products as $key => $product) {

            $price += (float) $product['total'];
        }


        $shippingInfo = $this->shippingInfo();

        if(is_object($shippingInfo) || is_array($shippingInfo)) {

            $price+= (float) $shippingInfo['cost'];

        }

        return $price;

    }

    private function priceParser($price) {

        if (strpos($price, ".") === false) {
            return $price . ".0";
        }
        $subStrIndex = 0;
        $priceReversed = strrev($price);
        for ($i = 0; $i < strlen($priceReversed); $i++) {
            if (strcmp($priceReversed[$i], "0") == 0) {
                $subStrIndex = $i + 1;
            } else if (strcmp($priceReversed[$i], ".") == 0) {
                $priceReversed = "0" . $priceReversed;
                break;
            } else {
                break;
            }
        }

        return strrev(substr($priceReversed, $subStrIndex));
    }

    public function webhook()
    {
        if (isset($this->request->get['key']) && $this->request->get['key'] !== $this->config->get('webhook_odero_webhook_url_key'))
        {
           return $this->webhookHttpResponse("invalid_key", 404);
        }

        $storeName = $this->config->get('config_name');
        $merchantId = $this->config->get('payment_odero_merchant_id');
        $secretKey = $this->config->get('payment_odero_merchant_token');
        $stage = $this->config->get('payment_odero_api_channel')  == 'live' ? \Oderopay\OderoConfig::ENV_PROD :  \Oderopay\OderoConfig::ENV_STG;

        $oderoConfig = new \Oderopay\OderoConfig($storeName,$merchantId, $secretKey, $stage);
        $oderopay = new \Oderopay\OderoClient($oderoConfig);

        $post = file_get_contents("php://input");
        $event = $oderopay->webhooks->handle(json_decode($post, true));

        switch (true) {
            case $event instanceof \Oderopay\Model\Webhook\Payment:
                $paymentId = $event->getOperationId();
                $orderId =   $this->model_extension_payment_odero->getOrderIdByPaymentId($paymentId);

                $data = $event->getData();
                if($event->getStatus() === 'SUCCESS'){
                    //payment_odero_order_status
                    $orderStatus = $this->config->get('payment_odero_order_status');

                    //Add Order History
                    $payment_field_desc    = $this->language->get('payment_field_desc');
                    $message = $payment_field_desc.$paymentId . "\n";

                    $this->model_checkout_order->addOrderHistory($orderId, $orderStatus, $message);

                    //add card token if exists
                    if(null !== $data['card_token']){
                        $orderDetail = $this->model_checkout_order->getOrder($orderId);

                        $customerId  = $orderDetail['customer_id'];
                        $this->model_extension_payment_odero->insertCardToken($customerId, $data['card_token'], $data['last_four_digits'] );
                    }
                }

                break;
            case $event instanceof \Oderopay\Model\Webhook\Refund:
                $paymentId = $event->getOperationId();

                $orderId =   $this->model_extension_payment_odero->getOrderIdByPaymentId($paymentId);

                if($event->getStatus() === 'SUCCESS'){
                    //payment_odero_order_status
                    $orderStatus = $this->config->get('payment_odero_order_refund_status');

                    //Add Order History
                    $payment_field_desc    = $this->language->get('payment_field_desc');
                    $message = $payment_field_desc.$paymentId . "\n";

                    $this->model_checkout_order->addOrderHistory($orderId, $orderStatus, $message);
                }
            case $event instanceof \Oderopay\Model\Webhook\Reverse:
                $paymentId = $event->getOperationId();
                $orderId =   $this->model_extension_payment_odero->getOrderIdByPaymentId($paymentId);
                if($event->getStatus() === 'SUCCESS'){
                    //payment_odero_order_status
                    $orderStatus = $this->config->get('payment_odero_order_reverse_status');

                    //Add Order History
                    $payment_field_desc    = $this->language->get('payment_field_desc');
                    $message = $payment_field_desc.$paymentId . "\n";

                    $this->model_checkout_order->addOrderHistory($orderId, $orderStatus, $message);
                }
                break;

            // ... handle other event types
            default:
                $this->webhookHttpResponse('Received unknown event type status: ' . $event->getStatus(), 404);
        }

    }

    public function webhookHttpResponse($message,$status){
        $httpMessage = ['message' => $message];
        header('Content-Type: application/json, Status: '. $status, true, $status);
        echo json_encode($httpMessage);
        exit();
    }
}
