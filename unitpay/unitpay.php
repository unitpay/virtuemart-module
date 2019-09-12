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
        $public_key = $method->public_key;
        $secret_key = $method->secret_key;
        $sum = $order['details']['BT']->order_total;
        $account = $order['details']['BT']->virtuemart_order_id;
        $desc = 'Оплата по заказу №' . $order['details']['BT']->order_number;
        $signature = hash('sha256', join('{up}', array(
            $account,
            $desc,
            $sum,
            $secret_key
        )));

        $html = '<form name="unitpay" action="https://unitpay.ru/pay/' . $public_key . '" method="get">';
        $html .= '<input type="hidden" name="sum" value="' . $sum . '">';
        $html .= '<input type="hidden" name="account" value="' . $account . '">';
        $html .= '<input type="hidden" name="desc" value="' . $desc . '">';
        $html .= '<input type="hidden" name="signature" value="' . $signature . '">';
        $html .= '</form>';
        $html .= '<script type="text/javascript">';
        $html .= 'document.forms.unitpay.submit();';
        $html .= '</script>';
        return $this->processConfirmedOrderPaymentResponse(true, $cart, $order, $html, $this->renderPluginName($method, $order), 'P');
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

        if (empty($order['details'])){
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        }elseif ((float)$order_details->order_total != (float)$params['orderSum']) {
            $result = array('error' =>
                array('message' => 'не совпадает сумма заказа')
            );
        }elseif ($currency->currency_code_3 != $params['orderCurrency']) {
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

        if (empty($order['details'])){
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        }elseif ((float)$order_details->order_total != (float)$params['orderSum']) {
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