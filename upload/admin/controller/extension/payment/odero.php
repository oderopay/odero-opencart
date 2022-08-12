<?php
class ControllerExtensionPaymentOdero extends Controller {
    private $module_version      = '2.3';

    private $error = array();

    private $fields = array(
        array(
            'validateField' => 'error_api_channel',
            'name'          => 'payment_odero_api_channel',
        ),
        array(
            'validateField' => 'blank',
            'name'          => 'payment_odero_api_url',
        ),
        array(
            'validateField' => 'error_api_key',
            'name'          => 'payment_odero_merchant_id',
        ),
        array(
            'validateField' => 'error_secret_key',
            'name'          => 'payment_odero_merchant_token',
        ),
        array(
            'validateField' => 'error_order_status',
            'name'          => 'payment_odero_order_status',
        ),
        array(
            'validateField' => 'error_order_refund_status',
            'name'          => 'payment_odero_order_refund_status',
        ),
        array(
            'validateField' => 'error_order_reverse_status',
            'name'          => 'payment_odero_order_reverse_status',
        ),
        array(
            'validateField' => 'error_cancel_order_status',
            'name'          => 'payment_odero_order_cancel_status',
        ),
        array(
            'validateField' => 'blank',
            'name'          => 'payment_odero_status',
        ),
        array(
            'validateField' => 'blank',
            'name'          => 'payment_odero_sort_order',
        ),
        array(
            'validateField' => 'error_title',
            'name'          => 'payment_odero_title',
        ),
        array(
            'validateField' => 'blank',
            'name'          => 'payment_odero_order_status_id',
        ),

        array(
            'validateField' => 'blank',
            'name'          => 'webhook_odero_webhook_url_key',
        )
    );

    public function index() {

        $this->load->language('extension/payment/odero');
        $this->load->model('setting/setting');
        $this->load->model('user/user');
        $this->load->model('extension/payment/odero');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {

            $request            = $this->requestOdero($this->request->post,'add','');


            $this->model_setting_setting->editSetting('payment_odero',$request);

            $this->getApiConnection($request['payment_odero_merchant_id'],$request['payment_odero_merchant_token']);


            $this->response->redirect($this->url->link('extension/payment/odero', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        $this->setOderoWebhookUrlKey();

        foreach ($this->fields as $key => $field) {

            if (isset($this->error[$field['validateField']])) {
                $data[$field['validateField']] = $this->error[$field['validateField']];
            } else {
                $data[$field['validateField']] = '';
            }

            if (isset($this->request->post[$field['name']])) {
                $data[$field['name']] = $this->request->post[$field['name']];
            } else {
                $data[$field['name']] = $this->config->get($field['name']);
            }
        }

        $this->document->setTitle($this->language->get('heading_title'));
        $this->document->addStyle('view/stylesheet/odero/odero.css');
        $this->document->addScript('view/javascript/odero/accordion_odero.js','footer');



        /* Extension Install Completed Status */
        $data['install_status']  = $this->installStatus();

        /* User Info Get*/
        $user_info              = $this->model_user_user->getUser($this->user->getId());
        $data['firstname']      = $user_info['firstname'];
        $data['lastname']       = $user_info['lastname'];

        /* Get Api Status */
        $data['api_status']     = $this->getApiStatus($data['install_status']);

        /* Get Order Status */
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();


        $data['action']         = $this->url->link('extension/payment/odero', 'user_token=' . $this->session->data['user_token'], true);
        $data['heading_title']  = $this->language->get('heading_title');
        $data['header']         = $this->load->controller('common/header');
        $data['column_left']    = $this->load->controller('common/column_left');
        $data['footer']         = $this->load->controller('common/footer');
        $data['locale']         = $this->language->get('code');
        $data['odero_webhook_url_key'] = $this->config->get('webhook_odero_webhook_url_key');
        $data['odero_webhook_url']  = HTTPS_CATALOG.'index.php?route=extension/payment/odero/webhook&key=' .$this->config->get('webhook_odero_webhook_url_key');
        $data['module_version'] = $this->module_version;


        $data_pwi_load_check['pwi_status_error']  = $this->language->get('pwi_status_error');
        $data_pwi_load_check['pwi_status_error_detail']  = $this->language->get('pwi_status_error_detail');
        $data_pwi_load_check['dev_odero_opencart_link']  = $this->language->get('dev_odero_opencart_link');
        $data_pwi_load_check['dev_odero_detail']  = $this->language->get('dev_odero_detail');
        $data_pwi_load_check['header']         = $this->load->controller('common/header');
        $data_pwi_load_check['column_left']    = $this->load->controller('common/column_left');

        //if pwi disabled and pwi first enabled status 0, set output pwi load page
        if (!$data['install_status']){
            $this->response->setOutput($this->load->view('extension/payment/odero_pwi_load_control', $data_pwi_load_check));
        }
        else{
            $this->response->setOutput($this->load->view('extension/payment/odero', $data));
        }
    }

    private function getApiConnection($api_key,$secret_key) {

        $test_api_con        = $this->model_extension_payment_odero->apiConnection();

        $this->session->data['api_status'] = $test_api_con;

        return $test_api_con;
    }

    private function getOverlayScript($position,$api_key,$secret_key) {

        $overlay_script_object = new stdClass();
        $overlay_script_object->locale          = $this->language->get('code');
        $overlay_script_object->conversationId  = rand(100000,99999999);
        $overlay_script_object->position        = $position;

        $overlay_pki         = $this->model_extension_payment_odero->pkiStringGenerate($overlay_script_object);
        $authorization_data  = $this->model_extension_payment_odero->authorizationGenerate($api_key,$secret_key,$overlay_pki);
        $overlay_script      = $this->model_extension_payment_odero->overlayScript($authorization_data,$overlay_script_object);

        return $overlay_script;
    }

    private function getApiStatus($install_status) {

        $api_status = false;

        $api_key    = $this->config->get('payment_odero_merchant_id');
        $secret_key = $this->config->get('payment_odero_merchant_token');

        return $this->getApiConnection($api_key,$secret_key);

    }

    private function installStatus() {

        try {
            return class_exists(\Oderopay\OderoConfig::class);
        }catch (\Exception $e) {
            return false;
        }
    }


    public function install() {
        $this->load->model('extension/payment/odero');
        $this->model_extension_payment_odero->install();
       // $this->model_setting_event->addEvent('overlay_script', 'catalog/controller/common/footer/after', 'extension/payment/odero/injectOverlayScript');
        $this->model_setting_event->addEvent('module_notification', 'admin/controller/common/footer/after', 'extension/payment/odero/injectModuleNotification');
    }

    public function uninstall() {

        $this->load->model('extension/payment/odero');
        $this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '0' AND code = 'payment_odero_pwi_status'");
        $this->model_extension_payment_odero->uninstall();
        $this->model_setting_event->deleteEventByCode('overlay_script');
        $this->model_setting_event->deleteEventByCode('module_notification');
    }

    protected function validate() {

        if (!$this->user->hasPermission('modify', 'extension/payment/odero')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        foreach ($this->fields as $key => $field) {

            if($field['validateField'] != 'blank') {

                if (!$this->request->post[$field['name']]){
                    $this->error[$field['validateField']] = $this->language->get($field['validateField']);
                }
            }

        }

        return !$this->error;
    }

    public function requestOdero($request,$method_type,$extra_request = false) {

        $request_modify = array();

        if ($method_type == 'add') {

            foreach ($this->fields as $key => $field) {

                if(isset($request[$field['name']])) {

                    if($field['name'] == 'payment_odero_merchant_id' || $field['name'] == 'payment_odero_merchant_token')
                        $request[$field['name']] = str_replace(' ','',$request[$field['name']]);

                    $request_modify[$field['name']] = $request[$field['name']];
                }

            }

            if($request_modify['payment_odero_api_channel'] == 'live') {

                $request_modify['payment_odero_api_url'] = 'https://api.odero.ro';

            } else if($request_modify['payment_odero_api_channel'] == 'sandbox') {

                $request_modify['payment_odero_api_url'] = 'https://sandbox-api.odero.ro';
                

            }


            if(!$request_modify['payment_odero_overlay_status']) {
                $request_modify['payment_odero_overlay_status'] = 'bottomLeft';
            }

        }

        if ($method_type == 'edit') {

            if(isset($extra_request->status)) {

                if($extra_request->status == 'success') {

                    $request_modify['payment_odero_overlay_token']     = $extra_request->protectedShopId;
                }
            }
        }

        return $request_modify;
    }

    /**
     * @return bool
     */
    private function setOderoWebhookUrlKey()
    {

        $webhookUrl = $this->config->get('webhook_odero_webhook_url_key');

        $uniqueUrlId = substr(base64_encode(time() . mt_rand()),15,6);

        if (!$webhookUrl) {
            $this->model_setting_setting->editSetting('webhook_odero',array(
                "webhook_odero_webhook_url_key" => $uniqueUrlId
            ));
        }

        return true;
    }

}
