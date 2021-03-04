<?php
class Payfort_Fort_Payment extends Payfort_Fort_Super
{

    private static $instance;
    private $pfHelper;
    private $pfConfig;
    private $pfOrder;

    public function __construct()
    {
        parent::__construct();
        $this->pfHelper   = Payfort_Fort_Helper::getInstance();
        $this->pfConfig   = Payfort_Fort_Config::getInstance();
        $this->pfOrder    = new Payfort_Fort_Order();
    }

    /**
     * @return Payfort_Fort_Config
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Payfort_Fort_Payment();
        }
        return self::$instance;
    }

    public function getPaymentRequestParams($paymentMethod, $integrationType = PAYFORT_FORT_INTEGRATION_TYPE_REDIRECTION)
    {
        $orderId = $this->pfOrder->getSessionOrderId();
        $this->pfOrder->loadOrder($orderId);

        $gatewayParams = array(
            'merchant_identifier' => $this->pfConfig->getMerchantIdentifier(),
            'access_code'         => $this->pfConfig->getAccessCode(),
            'merchant_reference'  => $orderId,
            'language'            => $this->pfConfig->getLanguage(),
        );
        if ($integrationType == PAYFORT_FORT_INTEGRATION_TYPE_REDIRECTION) {
            $baseCurrency                    = $this->pfHelper->getBaseCurrency();
            $orderCurrency                   = $this->pfOrder->getCurrencyCode();
            $currency                        = $this->pfHelper->getFortCurrency($baseCurrency, $orderCurrency);
            $gatewayParams['currency']       = strtoupper($currency);
            $gatewayParams['amount']         = $this->pfHelper->convertFortAmount($this->pfOrder->getTotal(), $this->pfOrder->getCurrencyValue(), $currency);
            $gatewayParams['customer_email'] = $this->pfOrder->getEmail();
            $gatewayParams['command']        = $this->pfConfig->getCommand();
            $gatewayParams['return_url']     = $this->pfHelper->getReturnUrl('responseOnline');
            if ($paymentMethod == PAYFORT_FORT_PAYMENT_METHOD_SADAD) {
                $gatewayParams['payment_option'] = 'SADAD';
            } elseif ($paymentMethod == PAYFORT_FORT_PAYMENT_METHOD_NAPS) {
                $gatewayParams['payment_option']    = 'NAPS';
                $gatewayParams['order_description'] = $orderId;
            } else if ($paymentMethod == PAYFORT_FORT_PAYMENT_METHOD_INSTALLMENTS) {
                $gatewayParams['installments'] = 'STANDALONE';
                $gatewayParams['command']      = 'PURCHASE';
            }
        } elseif ($integrationType == PAYFORT_FORT_INTEGRATION_TYPE_MERCAHNT_PAGE || $integrationType == PAYFORT_FORT_INTEGRATION_TYPE_MERCAHNT_PAGE2) {
            $gatewayParams['service_command'] = 'TOKENIZATION';
            $gatewayParams['return_url']      = $this->pfHelper->getReturnUrl('merchantPageResponse');

            if ($paymentMethod == PAYFORT_FORT_PAYMENT_METHOD_INSTALLMENTS && $integrationType == PAYFORT_FORT_INTEGRATION_TYPE_MERCAHNT_PAGE) {
                $baseCurrency                    = $this->pfHelper->getBaseCurrency();
                $orderCurrency                   = $this->pfOrder->getCurrencyCode();
                $currency                        = $this->pfHelper->getFortCurrency($baseCurrency, $orderCurrency);
                $gatewayParams['currency']       = strtoupper($currency);
                $gatewayParams['installments']          = 'STANDALONE';
                $gatewayParams['amount']         = $this->pfHelper->convertFortAmount($this->pfOrder->getTotal(), $this->pfOrder->getCurrencyValue(), $currency);
            }
        }
        $signature                  = $this->pfHelper->calculateSignature($gatewayParams, 'request');
        $gatewayParams['signature'] = $signature;

        $gatewayUrl = $this->pfHelper->getGatewayUrl();

        $this->pfHelper->log("Payfort Request Params for payment method ($paymentMethod) \n\n" . print_r($gatewayParams, 1));
        return array('url' => $gatewayUrl, 'params' => $gatewayParams);
    }

    public function getPaymentRequestForm($paymentMethod, $integrationType = PAYFORT_FORT_INTEGRATION_TYPE_REDIRECTION)
    {
        $paymentRequestParams = $this->getPaymentRequestParams($paymentMethod, $integrationType);

        $form = '<form style="display:none" name="frm_payfort_fort_payment" id="frm_payfort_fort_payment" method="post" action="' . $paymentRequestParams['url'] . '">';
        foreach ($paymentRequestParams['params'] as $k => $v) {
            $form .= '<input type="hidden" name="' . $k . '" value="' . $v . '">';
        }
        $form .= '<input type="submit">';
        return $form;
    }

    /**
     * 
     * @param array  $fortParams
     * @param string $responseMode (online, offline)
     * @retrun boolean
     */
    public function handleFortResponse($fortParams = array(), $responseMode = 'online', $integrationType = 'redirection')
    {
        try {
            $responseParams  = $fortParams;
            $success         = false;
            $responseMessage = Payfort_Fort_Language::__('error_transaction_error_1');
            //$this->session->data['error'] = Payfort_Fort_Language::__('text_payment_failed').$params['response_message'];
            if (empty($responseParams)) {
                $this->pfHelper->log('Invalid fort response parameters (' . $responseMode . ')');
                throw new Exception($responseMessage);
            }

            if (!isset($responseParams['merchant_reference']) || empty($responseParams['merchant_reference'])) {
                $this->pfHelper->log("Invalid fort response parameters. merchant_reference not found ($responseMode) \n\n" . print_r($responseParams, 1));
                throw new Exception($responseMessage);
            }

            $orderId = $responseParams['merchant_reference'];
            $this->pfOrder->loadOrder($orderId);

            $paymentMethod = $this->pfOrder->getPaymentMethod();
            $this->pfHelper->log("Fort response parameters ($responseMode) for payment method ($paymentMethod) \n\n" . print_r($responseParams, 1));

            $notIncludedParams = array('signature', 'wc-ajax', 'wc-api',  'payfort_fort', 'integration_type', 'WordApp_launch', 'WordApp_mobile_site', 'WordApp_demo', 'WordApp_demo');

            $responseType          = $responseParams['response_message'];
            $signature             = $responseParams['signature'];
            $responseOrderId       = $responseParams['merchant_reference'];
            $responseStatus        = isset($responseParams['status']) ? $responseParams['status'] : '';
            $responseCode          = isset($responseParams['response_code']) ? $responseParams['response_code'] : '';
            $responseStatusMessage = $responseType;

            $responseGatewayParams = $responseParams;
            foreach ($responseGatewayParams as $k => $v) {
                if (in_array($k, $notIncludedParams)) {
                    unset($responseGatewayParams[$k]);
                }
            }
            $responseSignature = $this->pfHelper->calculateSignature($responseGatewayParams, 'response');
            // check the signature
            if (strtolower($responseSignature) !== strtolower($signature)) {
                $responseMessage = Payfort_Fort_Language::__('error_invalid_signature');
                $this->pfHelper->log(sprintf('Invalid Signature. Calculated Signature: %1s, Response Signature: %2s', $signature, $responseSignature));
                // There is a problem in the response we got
                if ($responseMode == 'offline') {
                    $r = $this->pfOrder->declineOrder('Invalid Signature.');
                    if ($r) {
                        throw new Exception($responseMessage);
                    }
                } else {
                    throw new Exception($responseMessage);
                }
            }
            if (empty($responseCode)) {
                //get order status
                $orderStaus = $this->pfOrder->getStatusId();
                if ($orderStaus == $this->pfConfig->getSuccessOrderStatusId()) {
                    $responseCode   = '00000';
                    $responseStatus = '02';
                } else {
                    $responseCode   = 'failed';
                    $responseStatus = '10';
                }
            }

            if ($integrationType == 'cc_merchant_page_h2h') {
                if ($responseCode == '20064' && isset($responseParams['3ds_url'])) {
                    if ($integrationType == PAYFORT_FORT_INTEGRATION_TYPE_MERCAHNT_PAGE2) {
                        header('location:' . $responseParams['3ds_url']);
                    } else {
                        echo '<script>window.top.location.href = "' . $responseParams['3ds_url'] . '"</script>';
                    }
                    exit;
                }
            }
            if ($responseStatus == '01') {
                $responseMessage = Payfort_Fort_Language::__('text_payment_canceled');
                if ($responseMode == 'offline') {
                    $r = $this->pfOrder->cancelOrder();
                    if ($r) {
                        throw new Exception($responseMessage);
                    }
                } else {
                    throw new Exception($responseMessage);
                }
            }
            if (substr($responseCode, 2) != '000') {
                $responseMessage = sprintf(Payfort_Fort_Language::__('error_transaction_error_2'), $responseStatusMessage);
                if ($responseMode == 'offline') {
                    $r = $this->pfOrder->declineOrder($responseStatusMessage);
                    if ($r) {
                        throw new Exception($responseMessage);
                    }
                } else {
                    throw new Exception($responseMessage);
                }
            }
            if (substr($responseCode, 2) == '000') {
                if (($paymentMethod == PAYFORT_FORT_PAYMENT_METHOD_CC && ($integrationType == PAYFORT_FORT_INTEGRATION_TYPE_MERCAHNT_PAGE || $integrationType == PAYFORT_FORT_INTEGRATION_TYPE_MERCAHNT_PAGE2)) || ($paymentMethod == PAYFORT_FORT_PAYMENT_METHOD_INSTALLMENTS && $integrationType == PAYFORT_FORT_INTEGRATION_TYPE_MERCAHNT_PAGE)) {
                    $host2HostParams = $this->merchantPageNotifyFort($responseParams, $orderId);
                    return $this->handleFortResponse($host2HostParams, 'online', 'cc_merchant_page_h2h');
                } else { //success order
                    $this->pfOrder->successOrder($responseParams, $responseMode);
                }
            } else {
                $responseMessage = sprintf(Payfort_Fort_Language::__('error_transaction_error_2'), Payfort_Fort_Language::__('error_response_unknown'));
                if ($responseMode == 'offline') {
                    $r = $this->pfOrder->declineOrder('Unknown Response');
                    if ($r) {
                        throw new Exception($responseMessage);
                    }
                } else {
                    throw new Exception($responseMessage);
                }
            }
        } catch (Exception $e) {
            unset(WC()->session->order_awaiting_payment);
            $this->pfHelper->setFlashMsg($e->getMessage(), PAYFORT_FORT_FLASH_MSG_ERROR);
            return false;
        }
        return true;
    }

    private function merchantPageNotifyFort($fortParams, $orderId)
    {
        //send host to host
        $this->pfOrder->loadOrder($orderId);

        $baseCurrency  = $this->pfHelper->getBaseCurrency();
        $orderCurrency = $this->pfOrder->getCurrencyCode();
        $currency      = $this->pfHelper->getFortCurrency($baseCurrency, $orderCurrency);
        $language      = $this->pfConfig->getLanguage();
        $paymentMethod = $this->pfOrder->getPaymentMethod();

        $total = $this->pfOrder->getTotal();

        $this->pfHelper->log('Check discount');
        if (isset($fortParams['card_bin']) && $fortParams['card_bin'] == "548274") {
            $total -= 0.2 * $total;
            $this->applyVodafoneCashDiscount($orderId);
        }

        $postData      = array(
            'merchant_reference'  => $fortParams['merchant_reference'],
            'access_code'         => $this->pfConfig->getAccessCode(),
            'command'             => $this->pfConfig->getCommand(),
            'merchant_identifier' => $this->pfConfig->getMerchantIdentifier(),
            'customer_ip'         => $this->pfHelper->getCustomerIp(),
            'amount'              => $this->pfHelper->convertFortAmount($total, $this->pfOrder->getCurrencyValue(), $currency),
            'currency'            => strtoupper($currency),
            'customer_email'      => $this->pfOrder->getEmail(),
            'token_name'          => $fortParams['token_name'],
            'language'            => $language,
            'return_url'          => $this->pfHelper->getReturnUrl('responseOnline')
        );

        if ($paymentMethod == PAYFORT_FORT_PAYMENT_METHOD_INSTALLMENTS) {
            $postData['installments']            = 'YES';
            $postData['plan_code']               = $fortParams['plan_code'];
            $postData['issuer_code']             = $fortParams['issuer_code'];
            $postData['command']                 = 'PURCHASE';
        }

        $customerName = $this->pfOrder->getCustomerName();
        if (!empty($customerName)) {
            $postData['customer_name'] = $this->pfOrder->getCustomerName();
        }
        //calculate request signature
        $signature             = $this->pfHelper->calculateSignature($postData, 'request');
        $postData['signature'] = $signature;

        $gatewayUrl = $this->pfHelper->getGatewayUrl('notificationApi');
        $this->pfHelper->log('Merchant Page Notify Api Request Params : ' . print_r($postData, 1));

        $response = $this->callApi($postData, $gatewayUrl);

        return $response;
    }

    public function merchantPageCancel()
    {
        $orderId = $this->pfOrder->getSessionOrderId();
        $this->pfOrder->loadOrder($orderId);

        if ($orderId) {
            $this->pfOrder->cancelOrder();
        }
        $this->pfHelper->setFlashMsg(Payfort_Fort_Language::__('text_payment_canceled'));
        return true;
    }

    public function callApi($postData, $gatewayUrl)
    {
        //open connection
        $ch = curl_init();

        //set the url, number of POST vars, POST data
        $useragent = "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:20.0) Gecko/20100101 Firefox/20.0";
        curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json;charset=UTF-8',
            //'Accept: application/json, application/*+json',
            //'Connection:keep-alive'
        ));
        curl_setopt($ch, CURLOPT_URL, $gatewayUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_FAILONERROR, 1);
        curl_setopt($ch, CURLOPT_ENCODING, "compress, gzip");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // allow redirects		
        //curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // return into a variable
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0); // The number of seconds to wait while trying to connect
        //curl_setopt($ch, CURLOPT_TIMEOUT, Yii::app()->params['apiCallTimeout']); // timeout in seconds
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

        $response = curl_exec($ch);

        curl_close($ch);

        $array_result = json_decode($response, true);

        if (!$response || empty($array_result)) {
            return false;
        }
        return $array_result;
    }


    /*
    * Gazri Team Edits :: To apply Discount with 20% on using Vodafone Cash Card
    */
    public function applyVodafoneCashDiscount($orderId)
    {
        $title = 'Vodafone cash discount';
        $amount = '20%';
        $tax_class = '';
        //-------------------------------

        $order    = wc_get_order($orderId);
        $subtotal = $order->get_subtotal();

        $item     = new WC_Order_Item_Fee();

        if (strpos($amount, '%') !== false) {
            $percentage = (float) str_replace(array('%', ' '), array('', ''), $amount);
            $percentage = $percentage > 100 ? -100 : -$percentage;
            $discount   = $percentage * $subtotal / 100;
        } else {
            $discount = (float) str_replace(' ', '', $amount);
            $discount = $discount > $subtotal ? -$subtotal : -$discount;
        }

        $item->set_tax_class($tax_class);
        $item->set_name($title);
        $item->set_amount($discount);
        $item->set_total($discount);

        if ('0' !== $item->get_tax_class() && 'taxable' === $item->get_tax_status() && wc_tax_enabled()) {
            $tax_for   = array(
                'country'   => $order->get_shipping_country(),
                'state'     => $order->get_shipping_state(),
                'postcode'  => $order->get_shipping_postcode(),
                'city'      => $order->get_shipping_city(),
                'tax_class' => $item->get_tax_class(),
            );
            $tax_rates = WC_Tax::find_rates($tax_for);
            $taxes     = WC_Tax::calc_tax($item->get_total(), $tax_rates, false);

            if (method_exists($item, 'get_subtotal')) {
                $subtotal_taxes = WC_Tax::calc_tax($item->get_subtotal(), $tax_rates, false);
                $item->set_taxes(array('total' => $taxes, 'subtotal' => $subtotal_taxes));
                $item->set_total_tax(array_sum($taxes));
            } else {
                $item->set_taxes(array('total' => $taxes));
                $item->set_total_tax(array_sum($taxes));
            }
            $has_taxes = true;
        } else {
            $item->set_taxes(false);
            $has_taxes = false;
        }
        $item->save();

        $order->add_item($item);
        $order->calculate_totals($has_taxes);
        $order->save();

        // $this->pfOrder->loadOrder($orderId);
    }
}
