<?php
class ModelExtensionPaymentOdero extends Model {

    public function install() {
        $this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "odero_order` (
			  `odero_order_id` INT(11) NOT NULL AUTO_INCREMENT,
			  `payment_id` VARCHAR(255) NOT NULL,
			  `order_id` INT(11) NOT NULL,
			  `status` VARCHAR(20) NOT NULL,
			  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (`odero_order_id`)
			) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;");

        $this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "odero_card` (
			  	`odero_card_id` INT(11) NOT NULL AUTO_INCREMENT,
			  	`customer_id` INT(11) NOT NULL,
				`card_token` VARCHAR(255),
				`last_four_digits` VARCHAR(50),
			  	`created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			  	PRIMARY KEY (`odero_card_id`)
			) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;");
    }

    public function uninstall() {
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "odero_order`;");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "odero_card`;");
    }

    public function apiConnection() {

        //todo check api connection
        return false;

    }

}