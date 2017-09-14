<?php
require_once(Mage::getBaseDir('lib') . DIRECTORY_SEPARATOR . 'thirdwatch_php_sdk' . DIRECTORY_SEPARATOR . 'autoload.php');
use \Swagger\Client\Api;
use \Swagger\Client\Model;
use \Swagger\Client\Common;

class Thirdwatch_Mitra_Helper_Order extends Mage_Core_Helper_Abstract
{

    const ACTION_CREATE = 'create';
    const ACTION_TRANSACTION = 'transaction';
    const ACTION_UPDATE = 'update';
    const ACTION_CANCEL = 'cancel';
    const ACTION_REFUND = 'refund';
    const ACTION_ONLY_TRANSACTION = 'onlyTransaction';

    private $_customer = array();
    protected $requestData = array();

    /**
     * Submit an order to thirdwatch.
     * @param Mage_Sales_Model_Order $order
     * @param string $action - one of self::ACTION_*
     */
    public function postOrder($order, $action)
    {
        switch ($action) {
            case self::ACTION_CREATE:
                Mage::helper('mitra/log')->log("ACTION_CREATE");
                $this->createOrder($order);
                break;
            case self:: ACTION_TRANSACTION:
                Mage::helper('mitra/log')->log("ACTION_TRANSACTION");
                $this->createOrder($order);
                $this->createTransaction($order, '_sale');
                $order->setState(Mage_Sales_Model_Order::STATE_HOLDED, 'thirdwatch_holded');
                $order->save();
                break;
            case self:: ACTION_CANCEL:
                Mage::helper('mitra/log')->log("ACTION_CANCEL");
                $this->createTransaction($order, '_void');
                break;
            case self:: ACTION_REFUND:
                Mage::helper('mitra/log')->log("ACTION_REFUND");
                $this->createTransaction($order, '_refund');
                break;
            case self:: ACTION_ONLY_TRANSACTION:
                Mage::helper('mitra/log')->log("ACTION_ONLY_TRANSACTION");
                $this->createTransaction($order, '_sale');
                $order->setState(Mage_Sales_Model_Order::STATE_HOLDED, 'thirdwatch_holded');
                $order->save();
                break;
            case self::ACTION_UPDATE:
                Mage::helper('mitra/log')->log("ACTION_UPDATE");
                $this->updateOrderStatus($order);
                break;
        }
    }

    public function getOrderOrigId($order)
    {
        if (!$order) {
            return null;
        }
        return $order->getId() . '_' . $order->getIncrementId();
    }

    /**
     * This function is called whenever an item is added to the cart or removed from the cart.
     */
    private function getLineItemData($val)
    {
        $prodType = null;
        $category = null;
        $subCategories = null;
        $brand = null;
        $product = Mage::getModel('catalog/product')->load($val->getProductId());

        if ($product) {
            $categoryIds = $product->getCategoryIds();
            foreach ($categoryIds as $categoryId) {
                $cat = Mage::getModel('catalog/category')->load($categoryId);
                $catName = $cat->getName();
                if (!empty($catName)) {
                    if (empty($category)) {
                        $category = $catName;
                    } else if (empty($subCategories)) {
                        $subCategories = $catName;
                    } else {
                        $subCategories = $subCategories . '|' . $catName;
                    }
                }
            }

            if ($product->getManufacturer()) {
                $brand = $product->getAttributeText('manufacturer');
            }
        }

        $currencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();
        $countryCode = Mage::getStoreConfig('general/country/default');

        $lineItemData = array(
            '_price' => (string) $product->getPrice(),
            '_quantity' => intval($val->getQty()),
            '_product_title' => (string) $val->getName(),
            '_sku' => (string) $val->getSku(),
            '_item_id' => (string) $product->getId(),
            '_product_weight' => (string) $val->getWeight(),
            '_category' => (string) $category,
            '_brand' => (string) $brand,
            '_description' => (string) $product->getDescription(),
            '_description_short' => (string) $product->getShortDescription(),
            '_manufacturer' => (string)$brand,
            '_currency_code' => (string)$currencyCode,
            '_country' => (string)$countryCode,
        );
        return $lineItemData;
    }

    /**
     * This function is called whenever an order is placed.
     */
    private function getOrderItemData($val)
    {
        $prodType = null;
        $category = null;
        $subCategories = null;
        $brand = null;
        $product = Mage::getModel('catalog/product')->load($val->getProductId());

        if ($product) {
            $prodType = $product->getTypeId();
            $categoryIds = $product->getCategoryIds();
            foreach ($categoryIds as $categoryId) {
                $cat = Mage::getModel('catalog/category')->load($categoryId);
                $catName = $cat->getName();
                if (!empty($catName)) {
                    if (empty($category)) {
                        $category = $catName;
                    } else if (empty($subCategories)) {
                        $subCategories = $catName;
                    } else {
                        $subCategories = $subCategories . '|' . $catName;
                    }
                }
            }

            if ($product->getManufacturer()) {
                $brand = $product->getAttributeText('manufacturer');
            }
        }

        $currencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();
        $countryCode = Mage::getStoreConfig('general/country/default');

        $lineItemData = array(
            '_price' => (string) $val->getPrice(),
            '_quantity' => intval($val->getQtyOrdered()),
            '_product_title' => (string) $val->getName(),
            '_sku' => (string) $val->getSku(),
            '_item_id' => (string) $product->getId(),
            '_product_weight' => (string) $val->getWeight(),
            '_category' => (string) $category,
            '_brand' => (string) $brand,
            '_description' => (string) $product->getDescription(),
            '_description_short' => (string) $product->getShortDescription(),
            '_manufacturer' => (string)$brand,
            '_currency_code' => (string)$currencyCode,
            '_country' => (string)$countryCode,
        );
        return $lineItemData;
    }

    public function postCart($item){
        $helper = Mage::helper('mitra');
        $thirdwatchKey = $helper->getKey();
        \Swagger\Client\Configuration::getDefaultConfiguration()->setApiKey('X-THIRDWATCH-API-KEY', $thirdwatchKey);

        $customer = Mage::getSingleton('customer/session')->getCustomer();
        $cartData = array();
        $customerData = Mage::getModel('customer/customer')->load($customer->getId());
        $session = Mage::getSingleton('core/session');
        $SID=$session->getEncryptedSessionId();

        try{
            $currentDate = Varien_Date::now();
            $currentTimestamp = Varien_Date::toTimestamp($currentDate);
            $remoteAddress = Mage::helper('core/http')->getRemoteAddr();

            $cartData['_user_id'] = (string) $customerData->getId();
            $cartData['_session_id'] = (string) $SID;
            $cartData['_device_ip'] = (string) $remoteAddress;
            $cartData['_origin_timestamp'] = (string) $currentTimestamp . '000';

            $cartData['_item'] = $this->getLineItemData($item);

            $api_instance = new \Swagger\Client\Api\AddToCartApi();
            $body = new \Swagger\Client\Model\AddToCart($cartData);
        }
        catch (Exception $e){
            Mage::helper('mitra/log')->log($e->getMessage());
        }

        try {
            $api_instance->addToCart($body);
        } catch (Exception $e) {
            Mage::helper('mitra/log')->log($e->getMessage());
        }

    }

    public function removeCart($item){
        $helper = Mage::helper('mitra');
        $thirdwatchKey = $helper->getKey();
        \Swagger\Client\Configuration::getDefaultConfiguration()->setApiKey('X-THIRDWATCH-API-KEY', $thirdwatchKey);

        $customer = Mage::getSingleton('customer/session')->getCustomer();
        $cartData = array();
        $customerData = Mage::getModel('customer/customer')->load($customer->getId());
        $session = Mage::getSingleton('core/session');
        $SID=$session->getEncryptedSessionId();

        try{
            $currentDate = Varien_Date::now();
            $currentTimestamp = Varien_Date::toTimestamp($currentDate);
            $remoteAddress = Mage::helper('core/http')->getRemoteAddr();

            $cartData['_user_id'] = (string) $customerData->getId();
            $cartData['_session_id'] = (string) $SID;
            $cartData['_device_ip'] = (string) $remoteAddress;
            $cartData['_origin_timestamp'] = (string) $currentTimestamp . '000';

            $cartData['_item'] = $this->getLineItemData($item);

            $api_instance = new \Swagger\Client\Api\RemoveFromCartApi();
            $body = new \Swagger\Client\Model\RemoveFromCart($cartData);
        }
        catch (Exception $e){
            Mage::helper('mitra/log')->log($e->getMessage());
        }

        try {
            $result = $api_instance->removeFromCart($body);
        } catch (Exception $e) {
            Mage::helper('mitra/log')->log($e->getMessage());
        }
    }

    private function _getCustomerObject($customer_id) {
        if(!isset($this->_customer[$customer_id])) {
            $collection = Mage::getModel('customer/customer')->getCollection();
            $collection->addAttributeToFilter('entity_id', $customer_id);
            $this->_customer[$customer_id] = $collection->getFirstItem();
        }

        return $this->_customer[$customer_id];
    }

    private function getLineItems($model)
    {
        $lineItems = array();
        foreach ($model->getAllVisibleItems() as $key => $val) {
            $lineItems[] = $this->getOrderItemData($val);
        }
        return $lineItems;
    }

    private function getPaymentDetails($model)
    {
        $order = $this->loadOrderByOrigId($this->getOrderOrigId($model));
        $paymentData = array();

        try
        {
            $payment = $order->getPayment();
            $paymentData['_payment_type'] = (string) $payment->getMethodInstance()->getTitle();
            $paymentData['_amount'] = (string) $order->getGrandTotal();
            $paymentData['_currency_code'] = (string) $order->getOrderCurrencyCode();
            $paymentData['_payment_gateway'] = (string) $payment->getMethodInstance()->getTitle();
        }
        catch (Exception $e) {
            Mage::helper('mitra/log')->log($e->getMessage());
        }

        $paymentJson = new \Swagger\Client\Model\PaymentMethod($paymentData);
        return $paymentJson;
    }

    private function loadOrderByOrigId($full_orig_id)
    {
        if (!$full_orig_id) {
            return null;
        }

        $magento_ids = explode("_", $full_orig_id);
        $order_id = $magento_ids[0];
        $increment_id = $magento_ids[1];

        if ($order_id && $increment_id) {
            return Mage::getModel('sales/order')->getCollection()
                ->addFieldToFilter('entity_id', $order_id)
                ->addFieldToFilter('increment_id', $increment_id)
                ->getFirstItem();
        }
        return Mage::getModel('sales/order')->load($order_id);
    }

    private function getOrder($model){
        $orderData = array();
        $customerData = $this->_getCustomerObject($model->getCustomerId());
        $session = Mage::getSingleton('core/session');
        $SID=$session->getEncryptedSessionId();

        try{
            $remoteAddress = Mage::helper('core/http')->getRemoteAddr();

            $orderData['_user_id'] = (string) $customerData->getId();
            $orderData['_session_id'] = (string) $SID;
            $orderData['_device_ip'] = (string) $remoteAddress;
            $orderData['_origin_timestamp'] = (string) Varien_Date::toTimestamp($model->getCreatedAt()) . '000';
            $orderData['_order_id'] = (string) $this->getOrderOrigId($model);
            $orderData['_user_email'] = (string) $model->getCustomerEmail();
            $orderData['_amount'] = (string) $model->getGrandTotal();
            $orderData['_currency_code'] = (string) $model->getOrderCurrencyCode();
            $orderData['_billing_address'] = Mage::helper('mitra/common')->getBillingAddress($model->getBillingAddress());
            $orderData['_shipping_address'] = Mage::helper('mitra/common')->getShippingAddress($model->getShippingAddress());
            $orderData['_items'] = $this->getLineItems($model);
            $orderData['_payment_methods'] = array($this->getPaymentDetails($model));
        }
        catch (Exception $e){
            Mage::helper('mitra/log')->log($e->getMessage());
        }
        return $orderData;
    }

    public function createOrder($model){
        $helper = Mage::helper('mitra');
        $thirdwatchKey = $helper->getKey();
        \Swagger\Client\Configuration::getDefaultConfiguration()->setApiKey('X-THIRDWATCH-API-KEY', $thirdwatchKey);

        try {
            $orderData = $this->getOrder($model);
            Mage::helper('mitra/log')->log($orderData);
            $api_instance = new \Swagger\Client\Api\CreateOrderApi();
            $body = new \Swagger\Client\Model\CreateOrder($orderData);
        }
        catch (Exception $e){
            Mage::helper('mitra/log')->log($e);
        }

        try {
            $api_instance->createOrder($body);
        } catch (Exception $e) {
            Mage::helper('mitra/log')->log($e->getMessage());
        }
    }

    private function getOrderStatus($model){
        $orderData = array();
        $customerData = $this->_getCustomerObject($model->getCustomerId());
        $session = Mage::getSingleton('core/session');
        $SID=$session->getEncryptedSessionId();

        try{
            $orderData['_user_id'] = (string) $customerData->getId();
            $orderData['_session_id'] = (string) $SID;
            $orderData['_order_id'] = (string) $this->getOrderOrigId($model);
            $orderData['_order_status'] = (string) $model->getState();;
            $orderData['_reason'] = '';
            $orderData['_shipping_cost'] = '';
            $orderData['_tracking_number'] = '';
            $orderData['_tracking_method'] = '';
        }
        catch (Exception $e){
            Mage::helper('mitra/log')->log($e->getMessage());
        }
        return $orderData;
    }

    public function updateOrderStatus($model){
        $helper = Mage::helper('mitra');
        $thirdwatchKey = $helper->getKey();
        \Swagger\Client\Configuration::getDefaultConfiguration()->setApiKey('X-THIRDWATCH-API-KEY', $thirdwatchKey);

        try {
            $orderData = $this->getOrderStatus($model);
            $api_instance = new \Swagger\Client\Api\OrderStatusApi();
            $body = new \Swagger\Client\Model\OrderStatus($orderData);
        }
        catch (Exception $e){
            Mage::helper('mitra/log')->log($e);
        }

        try {
            $api_instance->orderStatus($body);
        } catch (Exception $e) {
            Mage::helper('mitra/log')->log($e->getMessage());
        }
    }

    private function getTransaction($model, $txnType)
    {
        $orderData = array();
        $customerData = $this->_getCustomerObject($model->getCustomerId());
        $session = Mage::getSingleton('core/session');
        $SID = $session->getEncryptedSessionId();
        $txnId = '';

        try {
            $payment = $model->getPayment();
            $txnId = $payment->getLastTransId();
        }catch (Exception $e){
            Mage::helper('mitra/log')->log($e->getMessage());
        }

        try {
            $remoteAddress = Mage::helper('core/http')->getRemoteAddr();

            $orderData['_user_id'] = (string)$customerData->getId();
            $orderData['_session_id'] = (string)$SID;
            $orderData['_device_ip'] = (string)$remoteAddress;
            $orderData['_origin_timestamp'] = (string)Varien_Date::toTimestamp($model->getCreatedAt()) . '000';
            $orderData['_order_id'] = (string)$this->getOrderOrigId($model);
            $orderData['_user_email'] = (string)$model->getCustomerEmail();
            $orderData['_amount'] = (string)$model->getGrandTotal();
            $orderData['_currency_code'] = (string)$model->getOrderCurrencyCode();
            $orderData['_billing_address'] = Mage::helper('mitra/common')->getBillingAddress($model->getBillingAddress());
            $orderData['_shipping_address'] = Mage::helper('mitra/common')->getShippingAddress($model->getShippingAddress());
            $orderData['_items'] = $this->getLineItems($model);
            $orderData['_payment_methods'] = array($this->getPaymentDetails($model));

            if ($txnId){
                $orderData['_transaction_id'] = $txnId;
            }

            $orderData['_transaction_type'] = $txnType;
            $orderData['_transaction_status'] = '_success';
        } catch (Exception $e) {
            Mage::helper('mitra/log')->log($e->getMessage());
        }
        return $orderData;

    }

    public function createTransaction($model, $txnType){
        $helper = Mage::helper('mitra');
        $thirdwatchKey = $helper->getKey();
        \Swagger\Client\Configuration::getDefaultConfiguration()->setApiKey('X-THIRDWATCH-API-KEY', $thirdwatchKey);

        try {
            $orderData = $this->getTransaction($model, $txnType);
            Mage::helper('mitra/log')->log($orderData);
            $api_instance = new \Swagger\Client\Api\TransactionApi();
            $body = new \Swagger\Client\Model\Transaction($orderData);
        }
        catch (Exception $e){
            Mage::helper('mitra/log')->log($e->getMessage());
        }

        try {
            $api_instance->transaction($body);
        } catch (Exception $e) {
            Mage::helper('mitra/log')->log($e->getMessage());
        }
    }
}