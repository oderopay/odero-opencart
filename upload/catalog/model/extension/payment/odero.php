<?php

class ModelExtensionPaymentOdero extends Model {

    public function getMethod($address, $total) {

        $payment_odero_geo_zone_id = $this->config->get('payment_odero_geo_zone_id');
        $payment_odero_geo_zone_id = $this->db->escape($payment_odero_geo_zone_id);
        $address_country_id 		= $this->db->escape($address['country_id']);
        $address_zone_id 			= $this->db->escape($address['zone_id']);

        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE `geo_zone_id` = '" . $payment_odero_geo_zone_id . "' AND `country_id` = '" . $address_country_id . "' AND (`zone_id` = '" . $address_zone_id . "' OR `zone_id` = '0')");

        if ($this->config->get('payment_odero_total') > $total) {
            $status = false;
        } elseif (!$this->config->get('payment_odero_geo_zone_id')) {
            $status = true;
        } elseif ($query->num_rows) {
            $status = true;
        } else {
            $status = false;
        }

        $method_data = array();

        $this->load->language('extension/payment/odero');

        if ($status) {
            $method_data = array(
                'code'       => 'odero',
                'title'      => $this->oderoMultipLangTitle($this->config->get('payment_odero_title')) . " ".$this->language->get('odero_img_title'),
                'terms'      => '',
                'sort_order' => $this->config->get('payment_odero_sort_order')
            );
        }

        return $method_data;
    }

    private function oderoMultipLangTitle($title) {

        $this->load->language('extension/payment/odero');
        $language = $this->config->get('payment_odero_language');
        $str_language = mb_strtolower($language);

        if(empty($str_language) or $str_language == 'null')
        {
            $title_language 			 = $this->language->get('code');
        }else {
            $title_language 			 = $str_language;
        }

        if($title) {

            $parser = explode('|',$title);

            if(is_array($parser) && count($parser)) {

                foreach ($parser as $key => $parse) {
                    $result = explode('=',$parse);

                    if($title_language == $result[0]) {
                        $new_title = $result[1];
                        break;
                    }
                }

            }

        }
        if(!isset($new_title)) {
            $new_title = $this->language->get('odero');
        }

        return $new_title;

    }

    public function insertCardToken($customer_id,$card_user_key, $last_digits) {

        $insertCard = $this->db->query("INSERT INTO `" . DB_PREFIX . "odero_card` SET
			`customer_id` 	= '" . $this->db->escape($customer_id) . "',
			`last_four_digits` = '" . $this->db->escape($last_digits) . "',
			`card_token` = '" . $this->db->escape($card_user_key) . "'
			");

        return $insertCard;
    }

    public function findUserCardToken($customer_id) {

        $customer_id = $this->db->escape($customer_id);

        $card_user_key = (object) $this->db->query("SELECT card_token FROM " . DB_PREFIX . "odero_card WHERE customer_id = '" . $customer_id ."' ORDER BY odero_card_id DESC");

        if(count($card_user_key->rows)) {
            return $card_user_key->rows[0]['card_token'];
        }

        return null;
    }

    public function insertPaymentIntent(\Oderopay\Http\Response\PaymentIntentResponse $payment, $orderId) {

        $data = $payment->data;

        $insertOrder = $this->db->query("INSERT INTO `" . DB_PREFIX . "odero_order` SET
			`payment_id` = '" . $this->db->escape($data['paymentId']) . "',
			`order_id` = '" . $this->db->escape($orderId) . "',
			`status` = '" . $this->db->escape($payment->getStatus()) . "'");

        return $insertOrder;
    }

    public function getOrderIdByPaymentId($paymentId) {

        $paymentId = $this->db->escape($paymentId);

        $oderoPayment = (object) $this->db->query("SELECT * FROM " . DB_PREFIX . "odero_order WHERE payment_id = '" . $paymentId ."'");

        if(count($oderoPayment->rows)) {
            return $oderoPayment->rows[0]['order_id'];
        }

        return null;
    }

    public function getCategoryName($product_id) {

        $product_id = $this->db->escape($product_id);

        $query = $this->db->query("SELECT category_id FROM " . DB_PREFIX . "product_to_category WHERE product_id = '" . $product_id . "' LIMIT 1");


        if(count($query->rows)) {

            $category_id = $this->db->escape($query->rows[0]['category_id']);

            $category 	 = $this->db->query("SELECT name FROM " . DB_PREFIX . "category_description WHERE category_id = '" . $category_id . "' LIMIT 1");

            if($category->rows[0]['name']) {
                $category_name = $category->rows[0]['name'];
            } else {
                $category_name = 'NO CATEGORIES';
            }

        } else {
            $category_name = 'NO CATEGORIES';
        }

        return $category_name;
    }


    public function getUserCreateDate($user_id) {

        $user_id = $this->db->escape($user_id);

        $user_create_date = (object) $this->db->query("SELECT date_added FROM " . DB_PREFIX . "user WHERE user_id = '" . $user_id ."'");

        if(count($user_create_date->rows)) {

            return $user_create_date->rows[0]['date_added'];
        }

        return date('Y-m-d H:i:s');
    }

}
