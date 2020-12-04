<?php
if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVmPaymentUnitpay extends vmPSPlugin
{

    function __construct(&$subject, $config)
    {

        parent::__construct($subject, $config);

        $jlang = JFactory::getLanguage();
        $jlang->load('vmpayment_unitpay', JPATH_ADMINISTRATOR, NULL, TRUE);

        $varsToPush        = array(
            'domain'     => array('','string'),
            'secret_key' => array('','string'),
            'public_key' => array('','string'),
        );
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    public function plgVmConfirmedOrder($cart, $order)
    {

        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {  //настройки
            return null; // Another method was selected, do nothing
        }
        $domain = $method->domain;
        $public_key = $method->public_key;
        $secret_key = $method->secret_key;
        $sum = number_format($order['details']['BT']->order_total, 2, '.', '');

        $this->getPaymentCurrency($method);

        //get currency code
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="'.
                $method->payment_currency.'" ';
        $db = JFactory::getDBO();
        $db->setQuery($q);
        $currencyCode = $db->loadResult();

        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency(
                $order['details']['BT']->order_total,$method->payment_currency
        );
        $cartCurrency = CurrencyDisplay::getInstance($cart->pricesCurrency);

        $account = $order['details']['BT']->virtuemart_order_id;
        $desc = 'Оплата по заказу №' . $order['details']['BT']->order_number;
        $signature = hash('sha256', join('{up}', array(
            $account,
            $currencyCode,
            $desc,
            $sum,
            $secret_key
        )));

        $address = isset($order['details']['ST']) ? $order['details']['ST'] : $order['details']['BT'];

        $html = '<form name="unitpay" action="https://'.$domain.'/pay/' . $public_key . '" method="get">';
        $html .= '<input type="hidden" name="sum" value="' . $sum . '">';
        $html .= '<input type="hidden" name="account" value="' . $account . '">';
        $html .= '<input type="hidden" name="currency" value="' . $currencyCode . '">';
        $html .= '<input type="hidden" name="desc" value="' . $desc . '">';
        $html .= '<input type="hidden" name="signature" value="' . $signature . '">';
        $html .= '<input type="hidden" name="customerPhone" value="' . preg_replace('/\D/', '', $address->phone_1) . '">';
        $html .= '<input type="hidden" name="customerEmail" value="' . $address->email . '">';
        $html .= '<input type="hidden" name="cashItems" value="' . $this->cashItems($order, $currencyCode) . '">';
        $html .= '</form>';
        $html .= '<script type="text/javascript">';
        $html .= 'document.forms.unitpay.submit();';
        $html .= '</script>';

        return $this->processConfirmedOrderPaymentResponse(true, $cart, $order, $html, $this->renderPluginName($method, $order), 'P');
    }

    function cashItems($order, $orderCurrencyCode) {
        $billing = $order['details']['BT'];

        $items = array();

        foreach ($order['items'] as $item) {
            $prices = $item->allPrices[$item->selectedPrice];
            $db = JFactory::getDBO();

            $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="'.$prices["payment_currency"].'" ';
            $db->setQuery($q);
            $currencyCode = $db->loadResult();

            $q = 'SELECT `calc_value` FROM `#__virtuemart_calcs` WHERE `virtuemart_calc_id`="'.$prices["product_tax_id"].'" ';
            $db->setQuery($q);
            $tax = $db->loadResult();

            $taxRate = $item->product_tax*100;

            $items[] = array(
                'name' => mb_strcut($item->order_item_name, 0, 63),
                'count' => round($item->product_quantity),
                'currency' => $currencyCode ? $currencyCode : $orderCurrencyCode,
                'price' => $item->product_subtotal_with_tax/round($item->product_quantity),
                'nds' => $tax ? $this->taxRates($tax) : "none",
                'type' => 'commodity',
            );
        }

        if(!empty($billing)) {
            $shipment_cost = 0;

            if(!empty($billing->order_shipment))
                $shipment_cost += $billing->order_shipment;

            if(!empty($billing->order_shipment_tax))
                $shipment_cost += $billing->order_shipment_tax;

            if($shipment_cost > 0) {
                $tax = array_shift(json_decode($billing->order_billTax, true));

                $items[] = array(
                    'name' => 'Услуги доставки',
                    'count' => 1,
                    'price' => number_format($shipment_cost, 2, '.', ''),
                    'currency' => $orderCurrencyCode,
                    'nds' => isset($tax->calc_value) ? $this->taxRates($tax->calc_value) : "none",
                    'type' => 'service',
                );
            }
        }

        return base64_encode(json_encode($items));
    }

    function taxRates($rate){
        switch (intval($rate)){
            case 10:
                $vat = 'vat10';
                break;
            case 20:
                $vat = 'vat20';
                break;
            case 0:
                $vat = 'vat0';
                break;
            default:
                $vat = 'none';
        }

        return $vat;
    }

    function plgVmgetPaymentCurrency ($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

        if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement ($method->payment_element)) {
            return FALSE;
        }
        $this->getPaymentCurrency ($method);

        $paymentCurrencyId = $method->payment_currency;
        return;
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    function plgVmDeclarePluginParamsPaymentVM3( &$data) {
        return $this->declarePluginParams('payment', $data);
    }

    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    protected function displayLogos($logo_list)
    {
        $img = "";

        if (!(empty($logo_list))) {
            $url = JURI::root() . str_replace('\\', '/', str_replace(JPATH_ROOT, '', dirname(__FILE__))) . '/';
            if (!is_array($logo_list))
                $logo_list = (array) $logo_list;
            foreach ($logo_list as $logo) {
                $alt_text = substr($logo, 0, strpos($logo, '.'));
                $img .= '<img align="middle" src="' . $url . $logo . '"  alt="' . $alt_text . '" /> ';
            }
        }
        return $img;
    }

    public function plgVmOnPaymentNotification()
    {

        header('Content-type:application/json;  charset=utf-8');

        $method = '';
        $params = [];

        if ((isset($_GET['params'])) && (isset($_GET['method'])) && (isset($_GET['params']['signature']))){
            $params = $_GET['params'];
            $method = $_GET['method'];
            $signature = $params['signature'];

            $orderModel     = VmModel::getModel('orders');
            $order          = $orderModel->getOrder($params['account']);

            if (empty($signature) || empty($order['details'] )){
                $status_sign = false;
            }else{
                $plugin_method  = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);
                $status_sign = $this->verifySignature($params, $method, $plugin_method->secret_key);
            }
        }else{
            $status_sign = false;
        }

        if ($status_sign){
            switch ($method) {
                case 'check':
                    $result = $this->check( $params );
                    break;
                case 'pay':
                    $result = $this->payment( $params );
                    break;
                case 'error':
                    $result = $this->error( $params );
                    break;
                default:
                    $result = array('error' =>
                        array('message' => 'неверный метод')
                    );
                    break;
            }
        }else{
            $result = array('error' =>
                array('message' => 'неверная сигнатура')
            );
        }

        echo json_encode($result);
        die();

    }

    function verifySignature($params, $method, $secret)
    {
        return $params['signature'] == $this->getSignature($method, $params, $secret);
    }

    function getSignature($method, array $params, $secretKey)
    {
        ksort($params);
        unset($params['sign']);
        unset($params['signature']);
        array_push($params, $secretKey);
        array_unshift($params, $method);

        return hash('sha256', join('{up}', $params));
    }

    function check( $params )
    {
        $order_id = $params['account'];
        $orderModel     = VmModel::getModel('orders');
        $order          = $orderModel->getOrder($order_id);
        $order_details  = $order['details']['BT'];

        $currenciesModel     = VmModel::getModel('currency');
        $currency = $currenciesModel->getCurrency($order_details->order_currency);

        $total = number_format($order_details->order_total, 2, ".", "");

        if (empty($order['details'])){
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        }elseif ((float) $total != (float) number_format($params['orderSum'], 2, ".", "")) {
            $result = array('error' =>
                array('message' => 'не совпадает сумма заказа')
            );
        } elseif ($currency->currency_code_3 != $params['orderCurrency']) {
            $result = array('error' =>
                array('message' => 'не совпадает валюта заказа')
            );
        }
        else{
            $result = array('result' =>
                array('message' => 'Запрос успешно обработан')
            );
        }

        return $result;

    }

    function payment( $params )
    {
        $order_id = $params['account'];
        $orderModel     = VmModel::getModel('orders');
        $order          = $orderModel->getOrder($order_id);
        $order_details  = $order['details']['BT'];

        $currenciesModel     = VmModel::getModel('currency');
        $currency = $currenciesModel->getCurrency($order_details->order_currency);

        $total = number_format($order_details->order_total, 2, ".", "");

        if (empty($order['details'])){
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        }elseif ((float) $total != (float) number_format($params['orderSum'], 2, ".", "")) {
            $result = array('error' =>
                array('message' => 'не совпадает сумма заказа')
            );
        }elseif ($currency->currency_code_3 != $params['orderCurrency']) {
            $result = array('error' =>
                array('message' => 'не совпадает валюта заказа')
            );
        }
        else{

            $orderStatus['order_status']        = 'C';  //подтвержден
            $orderStatus['virtuemart_order_id'] = $params['account'];
            $orderStatus['customer_notified']   = 0;
            $orderStatus['comments']            = 'unitpay';
            $orderModel->updateStatusForOneOrder($params['account'], $orderStatus, true);

            $result = array('result' =>
                array('message' => 'Запрос успешно обработан')
            );

        }

        return $result;
    }
    function error( $params )
    {
        $order_id = $params['account'];
        $orderModel     = VmModel::getModel('orders');
        $order          = $orderModel->getOrder($order_id);

        if (empty($order['details'])){
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        }
        else{

            $orderStatus['order_status']        = 'X';  //отменен
            $orderStatus['virtuemart_order_id'] = $params['account'];
            $orderStatus['customer_notified']   = 0;
            $orderStatus['comments']            = 'unitpay';
            $orderModel->updateStatusForOneOrder($params['account'], $orderStatus, true);

            $result = array('result' =>
                array('message' => 'Запрос успешно обработан')
            );

        }

        return $result;
    }


}