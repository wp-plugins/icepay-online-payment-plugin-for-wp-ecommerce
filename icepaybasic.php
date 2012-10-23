<?php

###################################################################
#                                                             	  #
#	The property of ICEPAY www.icepay.eu                      #
#                                                             	  #
#   The merchant is entitled to change de ICEPAY plug-in code,	  #
#	any changes will be at merchant's own risk.		  #
#	Requesting ICEPAY support for a modified plug-in will be  #
#	charged in accordance with the standard ICEPAY tariffs.	  #
#                                                             	  #
###################################################################

/**
 * ICEPAY Wordpress Payment module - Main script
 * 
 * @version 1.0.1
 * @author Wouter van Tilburg
 * @author Olaf Abbenhuis
 * @license http://opensource.org/licenses/GPL-3.0 GNU General Public License, version 3 (GPL-3.0)
 * @copyright Copyright (c) 2012 ICEPAY B.V.
 * 
 * Below are settings required for Wordpress
 * 
 * Plugin Name: ICEPAY Basic for WP Ecommerce
 * Plugin URI: http://wordpress.org/extend/plugins/icepay/ 
 * Description: Enables ICEPAY within Wordpress Ecommerce
 * Author: ICEPAY
 * Version: 1.0.1
 * Author URI: http://www.icepay.com
 */
define('ICEPAY_VERSION', '1.0.1');

define('WPSC_ICEPAY_FILE_PATH', dirname(__FILE__));
define('WPSC_ICEPAY_URL', plugins_url('', __FILE__));

// Load ICEPAY API 
require_once( WPSC_ICEPAY_FILE_PATH . '/api/icepay_api_basic.php' );

// Load admin script 
require_once( WPSC_ICEPAY_FILE_PATH . '/admin.php' );

// Load merchant class
require_once(dirname(dirname(__FILE__)) . "/wp-e-commerce/wpsc-includes/merchant.class.php");
require_once(dirname(dirname(__FILE__)) . "/wp-e-commerce/wpsc-theme/functions/wpsc-transaction_results_functions.php");

// Add hook for activation
register_activation_hook(__FILE__, 'ICEPAY_register');

// Create ICEPAY table upon activating
function ICEPAY_register() {
    global $wpdb;

    $table_name = $wpdb->prefix . "wpsc_icepay_transactions";
    $sql = "CREATE TABLE $table_name (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `order_id` int(11) NOT NULL,
        `status` varchar(255) NOT NULL,
        `transaction_id` int(11) DEFAULT NULL,
        PRIMARY KEY (`id`)
    );";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

class ICEPAY_Basic extends wpsc_merchant {
    // Add necessary actions for ICEPAY to work
    function ICEPAY_Basic() {
        add_action('init', array($this, 'ICEPAY_Locale'), 8);
        add_action('init', array($this, 'ICEPAY_setPaymentmethods'), 9);
        add_action('init', array($this, 'ICEPAY_Response'), 10);
        add_action('wpsc_submit_checkout', array($this, 'ICEPAY_doPayment'), 12);
    }

    // Add the ICEPAY payment methods
    function ICEPAY_setPaymentmethods() {
        global $nzshpcrt_gateways, $num, $gateway_checkout_form_fields;

        $paymentMethods = Icepay_Api_Basic::getInstance()->readFolder()->getObject();

        $gatewayNames = get_option('payment_gateway_names');

        foreach ($paymentMethods as $key => $paymentMethod) {
            if (!$this->ICEPAY_checkoutValidation($paymentMethod))
                continue;

            $num++;

            $nzshpcrt_gateways[$num]['name'] = "ICEPAY " . $paymentMethod->getReadableName();
            //$nzshpcrt_gateways[$num]['image'] = WPSC_ICEPAY_URL . "/images/{$key}.png";
            $image = WPSC_ICEPAY_URL . "/images/{$key}.png";
            $nzshpcrt_gateways[$num]['function'] = 'ICEPAY_doPayment';
            $nzshpcrt_gateways[$num]['form'] = "form_icepaybasic";
            $nzshpcrt_gateways[$num]['submit_function'] = "submit_form_icepaybasic";
            $nzshpcrt_gateways[$num]['is_exclusive'] = true;
            $nzshpcrt_gateways[$num]['payment_type'] = "icepay_checkout";
            $nzshpcrt_gateways[$num]['payment_gateway'] = "icepay";
            $nzshpcrt_gateways[$num]['internalname'] = "icepay_" . strtolower($paymentMethod->getCode());
            $nzshpcrt_gateways[$num]['display_name'] = $paymentMethod->getReadableName();

            add_option($nzshpcrt_gateways[$num]['internalname'] . "_code", $paymentMethod->getCode());

            $issuers = $paymentMethod->getSupportedIssuers();

            $output = "";
            $output .= "<tr><td><p><img src='{$image}' width='163' border=0/></p>";

            if (count($issuers) > 1) {
                $output .= "<p><select name='{$key}_issuer' style='width:163px;'>";
                foreach ($issuers as $issuer) {
                    $output .= sprintf("<option value='%s'>%s</option>", $issuer, __($issuer, 'icepay'));
                }
                $output .= "</select></p>";
            }

            $output .= "</td></tr>";
            $output .= sprintf("<input type='hidden' name='icepay_paymentmethod' value='%s' />", $paymentMethod->getCode());

            $gateway_checkout_form_fields[$nzshpcrt_gateways[$num]['internalname']] .= $output;
            if (!isset($gatewayNames[$nzshpcrt_gateways[$num]['internalname']]))
                $gatewayNames[$nzshpcrt_gateways[$num]['internalname']] = $paymentMethod->getReadableName();
        }


        update_option('payment_gateway_names', $gatewayNames);
    }

    function ICEPAY_checkoutValidation($paymentMethod) {
        if (is_admin())
            return true;

        global $wpdb, $wpsc_cart;

        $ic_obj = new StdClass();

        // Get the grand total of order
        $ic_obj->amount = intval($wpsc_cart->total_price * 100);

        // Get the used currency for the shop
        $currency = $wpdb->get_row("SELECT `code` FROM `" . WPSC_TABLE_CURRENCY_LIST . "` WHERE `id`='" . get_option('currency_type') . "' LIMIT 1");
        $ic_obj->currency = $currency->code;

        // Get the Wordpress language and adjust format so ICEPAY accepts it.
        $language_locale = get_bloginfo('language');
        $ic_obj->language = strtoupper(substr($language_locale, 0, 2));
        
        // Amount check
        $amountRange = $paymentMethod->getSupportedAmountRange();
        if (!$ic_obj->amount > $amountRange['minimum'] && $ic_obj->amount < $amountRange['maximum'])
            return false;
        
        if (!Icepay_Api_Basic::getInstance()->exists($ic_obj->currency, $paymentMethod->getSupportedCurrency()))
            return false;
        
        $supportedLanguages = $paymentMethod->getSupportedLanguages();
        if (count($supportedLanguages) > 1 && !Icepay_Api_Basic::getInstance()->exists($ic_obj->language, $supportedLanguages))
            return false;

        return true;
    }

    // Check if paymentmethod is supported
    function ICEPAY_checkSettings($paymentMethod, $ic_obj) {
        $error = false;

        // Currency check
        if (!Icepay_Api_Basic::getInstance()->exists($ic_obj->currency, $paymentMethod->getSupportedCurrency())) {
            $this->set_error_message(__('The currency is not supported by this payment method.', 'icepay'));
            $error = true;
        }

        // Country check
        if (!Icepay_Api_Basic::getInstance()->exists($ic_obj->country, $paymentMethod->getSupportedCountries())) {
            $this->set_error_message(__('The country is not supported by this payment method.', 'icepay'));
            $error = true;
        }

        // Amount check
        $amountRange = $paymentMethod->getSupportedAmountRange();

        if (!$ic_obj->amount > $amountRange['minimum'] && $ic_obj->amount < $amountRange['maximum']) {
            $this->set_error_message(__('The amount is not supported by this payment method.', 'icepay'));
            $error = true;
        }

        if ($error)
            $this->return_to_checkout();
    }

    // Make the payment
    function ICEPAY_doPayment() {
        global $wpsc_cart, $wpdb;

        // Check if payment is done by ICEPAY
        if (!isset($_POST['icepay_paymentmethod']))
            return false;

        $gateway = $_POST['custom_gateway'];
        $pmCode = get_option($gateway . "_code");

        // Get ICEPAY configuration options
        $options = get_option('icepay_options');

        // Initiate icepay object
        $ic_obj = new StdClass();

        // Get the grand total of order
        $ic_obj->amount = intval($wpsc_cart->total_price * 100);
        $ic_obj->country = $wpsc_cart->selected_country;

        // Get the used currency for the shop
        $currency = $wpdb->get_row("SELECT `code` FROM `" . WPSC_TABLE_CURRENCY_LIST . "` WHERE `id`='" . get_option('currency_type') . "' LIMIT 1");
        $ic_obj->currency = $currency->code;

        // Get the Wordpress language and adjust format so ICEPAY accepts it.
        $language_locale = get_bloginfo('language');
        $ic_obj->language = strtoupper(substr($language_locale, 0, 2));


        // Get the order and fetch the order id
        $order = $wpdb->get_row("SELECT `id` FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` ORDER BY `id` DESC LIMIT 1");
        $orderID = $order->id;

        // Get paymentclass
        $paymentMethodClass = Icepay_Api_Basic::getInstance()
                ->readFolder()
                ->getClassByPaymentmethodCode($pmCode);

        // Check if ICEPAY supports paymentparameters
        $this->ICEPAY_checkSettings($paymentMethodClass, $ic_obj);

        var_dump($paymentMethodClass->getSupportedLanguages());
        
        $language = $ic_obj->language;
        
        $supportedLanguages = $paymentMethodClass->getSupportedLanguages();
        
        if (count($supportedLanguages) == 1 && $supportedLanguages[0] != '00')
            $language = $supportedLanguages[0];

        $paymentObj = new Icepay_PaymentObject();
        $paymentObj->setOrderID($orderID)
                ->setDescription($options['icepay_description'])
                ->setReference($options['icepay_description'])
                ->setAmount(intval($ic_obj->amount))
                ->setCurrency($ic_obj->currency)
                ->setCountry($ic_obj->country)
                ->setLanguage($language)
                ->setPaymentMethod($pmCode);

        $issuer = (isset($_POST[strtolower($pmCode) . '_issuer'])) ? $_POST[strtolower($pmCode) . '_issuer'] : 'DEFAULT';

        $paymentObj->setIssuer($issuer);

        // Validate payment object
        $basicmode = Icepay_Basicmode::getInstance();
        $basicmode->setMerchantID($options['icepay_merchantid'])
                ->setSecretCode($options['icepay_secretcode'])
                ->validatePayment($paymentObj);

        $basicmode->setProtocol('http');

        // Insert payment into ICEPAY table
        $table_name = $wpdb->prefix . "wpsc_icepay_transactions";
        $wpdb->insert($table_name, array('order_id' => $orderID, 'status' => Icepay_StatusCode::OPEN, 'transaction_id' => NULL));

        // Get payment url and redirect to paymentscreen
        $url = $basicmode->getURL();
        header("location: {$url}");
        exit();
    }

    // Connect icepay statusses to E-commerce statusses
    function ICEPAY_getEcommerceStatus($status) {
        if ($status == Icepay_StatusCode::ERROR)
            return 6; // 'Payment Declined'

        if ($status == Icepay_StatusCode::OPEN)
            return 2; // 'Order Received'

        if ($status == Icepay_StatusCode::SUCCESS)
            return 3; // 'Accepted Payment'

        return 6;
    }

    // ICEPAY Postback
    function ICEPAY_Response() {
        global $wpdb, $wp_version;

        $options = get_option('icepay_options');

        if (isset($_GET['page']) && $_GET['page'] == 'icepayresult') {

            // Postback
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                $icepay = Icepay_Project_Helper::getInstance()->postback();
                $icepay->setMerchantID($options['icepay_merchantid'])
                        ->setSecretCode($options['icepay_secretcode'])
                        ->doIPCheck(true);

                if ($options['icepay_ipcheck'] != '') {
                    $ipRanges = explode(",", $options['icepay_ipcheck']);

                    foreach ($ipRanges as $ipRange) {
                        $ip = explode("-", $ipRange);
                        $icepay->setIPRange($ip[0], $ip[1]);
                    }
                }

                if ($icepay->validate()) {
                    $data = $icepay->GetPostback();

                    $orderID = $data->orderID;

                    $this->purchase_id = $orderID;

                    $table_icepay = $wpdb->prefix . "wpsc_icepay_transactions";
                    $ic_order = $wpdb->get_row("SELECT * FROM $table_icepay WHERE `order_id` = $orderID");
                    $order = $wpdb->get_row("SELECT * FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` WHERE id = $orderID");

                    if ($icepay->canUpdateStatus($ic_order->status)) {
                        if ($data->status == Icepay_StatusCode::OPEN)
                            delete_transient("{$order->sessionid}_pending_email_sent");

                        if ($data->status == Icepay_StatusCode::SUCCESS)
                            delete_transient("{$order->sessionid}_receipt_email_sent");

                        $processed = $this->ICEPAY_getEcommerceStatus(($data->status));
                        $wpdb->update($table_icepay, array('status' => $data->status, 'transaction_id' => $data->transactionID), array('order_id' => $orderID));
                        $this->set_transaction_details($data->transactionID, $processed);
                        transaction_results($order->sessionid, false, $data->transactionID);
                    }
                } else {
                    if ($icepay->isVersionCheck()) {
                        $dump = array(
                            "module" => sprintf("ICEPAY WPEC payment module version %s using PHP API version %s", ICEPAY_VERSION, Icepay_Project_Helper::getInstance()->getReleaseVersion()), //<--- Module version and PHP API version
                            "notice" => "Checksum validation passed!"
                        );

                        if ($icepay->validateVersion()) {
                            $dump["additional"] = array(
                                "Wordpress" => $wp_version, // CMS name & version
                                "E-Commerce" => WPSC_VERSION // Webshop name & version
                            );
                        } else {
                            $dump["notice"] = "Checksum failed! Merchant ID and Secret code probably incorrect.";
                        }
                        var_dump($dump);
                        exit();
                    }
                }
            } else {
                $icepay = Icepay_Project_Helper::getInstance()->result();
                $icepay->setMerchantID($options['icepay_merchantid'])
                        ->setSecretCode($options['icepay_secretcode']);

                if ($icepay->validate()) {
                    $order = $wpdb->get_row("SELECT `sessionid` FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` ORDER BY `id` DESC LIMIT 1");

                    switch ($icepay->getStatus()) {
                        case Icepay_StatusCode::ERROR:
                            $transaction_url_with_sessionid = add_query_arg('sessionid', $session_id, get_option('transact_url'));
                            wp_redirect($transaction_url_with_sessionid);
                            exit;
                            break;
                        case Icepay_StatusCode::OPEN:
                        case Icepay_StatusCode::SUCCESS:
                            set_transient("{$order->sessionid}_pending_email_sent", true, 60 * 60 * 12);
                            set_transient("{$order->sessionid}_receipt_email_sent", true, 60 * 60 * 12);
                            $this->go_to_transaction_results($order->sessionid);
                            break;
                    }
                }
            }
        }
    }

    // ICEPAY Locale
    function ICEPAY_Locale() {
        load_plugin_textdomain('icepay', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

}

// Let the magic happen
new ICEPAY_Basic();

// 
function form_icepaybasic() {

    return;
}

function submit_form_icepaybasic() {
    $paymentMethod = $_POST['method'];
    update_option($paymentMethod, $_POST['icepay_name']);
}

?>