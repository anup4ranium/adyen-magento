<?php
/**
 * Adyen Payment Module
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category	Adyen
 * @package	Adyen_Payment
 * @copyright	Copyright (c) 2011 Adyen (http://www.adyen.com)
 * @license	http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
/**
 * @category   Payment Gateway
 * @package    Adyen_Payment
 * @author     Adyen
 * @property   Adyen B.V
 * @copyright  Copyright (c) 2014 Adyen BV (http://www.adyen.com)
 */
class Adyen_Payment_Model_Observer {

    /**
     * @event controller_action_predispatch
     * @param Varien_Event_Observer $observer
     */
    public function addHppMethodsToConfig(Varien_Event_Observer $observer)
    {
        $store = Mage::app()->getStore();
        $this->_addHppMethodsToConfig($store);
    }


    /**
     * @param Mage_Core_Model_Store $store
     */
    protected function _addHppMethodsToConfig(Mage_Core_Model_Store $store)
    {
        Varien_Profiler::start(__CLASS__.'::'.__FUNCTION__);

        foreach ($this->_fetchHppMethods($store) as $methodCode => $methodData) {
            $this->createPaymentMethodFromHpp($methodCode, $methodData, $store);
        }

        $store->setConfig('payment/adyen_hpp/active', 0);

        Varien_Profiler::stop(__CLASS__.'::'.__FUNCTION__);
    }


    /**
     * @param string $methodCode ideal,mc,etc.
     * @param array $methodData
     * @param       $store
     */
    public function createPaymentMethodFromHpp($methodCode, $methodData = array(), $store)
    {
        $methodNewCode = 'adyen_hpp_'.$methodCode;

        if ($methodCode == 'ideal') {
            unset($methodData['title']);
            $methodNewCode = 'adyen_ideal';
        } else {
            $methodData = $methodData + Mage::getStoreConfig('payment/adyen_hpp', $store);
            $methodData['model'] = 'adyen/adyen_hpp';
        }

        foreach ($methodData as $key => $value) {
            if (is_object($value) || is_array($value)) {
                $value = json_encode($value);
            }
            $store->setConfig('payment/'.$methodNewCode.'/'.$key, $value);
        }
    }


    protected function _fetchHppMethods(Mage_Core_Model_Store $store)
    {
        $adyenHelper = Mage::helper('adyen');


        $skinCode          = $adyenHelper->_getConfigData('skinCode', 'adyen_hpp', $store);
        $merchantAccount   = $adyenHelper->_getConfigData('merchantAccount', null, $store);

        //@todo amount filtering, move to loading of payment method.
//        $amount               = Mage::helper('adyen')->formatAmount(
//            Mage::helper('checkout/cart')->getQuote()->getGrandTotal(),
//            $orderCurrencyCode
//        );

        $adyFields = array(
            "paymentAmount"     => $this->_getCurrentPaymentAmount(),
            "currencyCode"      => $this->_getCurrentCurrencyCode(),
            "merchantReference" => "Get Payment methods",
            "skinCode"          => $skinCode,
            "merchantAccount"   => $merchantAccount,
            "sessionValidity"   => date(
                DATE_ATOM,
                mktime(date("H") + 1, date("i"), date("s"), date("m"), date("j"), date("Y"))
            ),
            "countryCode"       => $this->_getCurrentCountryCode(),
            "shopperLocale"     => Mage::app()->getLocale()->getLocaleCode()
        );
        $responseData = $this->_getResponse($adyFields, $store);

        $paymentMethods = array();
        foreach ($responseData['paymentMethods'] as $paymentMethod) {
            $paymentMethod = $this->_fieldMapPaymentMethod($paymentMethod);
            $paymentMethodCode = $paymentMethod['brandCode'];

            //Skip open invoice methods if they are enabled
            if (Mage::getStoreConfig('payment/adyen_openinvoice/openinvoicetypes') == $paymentMethodCode) {
                continue;
            }

            if (in_array($paymentMethodCode, array('diners','discover','amex','mc','visa','maestro', 'elv', 'sepadirectdebit'))) {
                continue;
            }

            unset($paymentMethod['brandCode']);
            $paymentMethods[$paymentMethodCode] = $paymentMethod;
        }

        return $paymentMethods;
    }

    protected function _getQuote()
    {
        return Mage::getSingleton('checkout/session')->getQuote();
    }

    protected function _getCurrentLocaleCode()
    {
        return Mage::app()->getLocale()->getLocaleCode();
    }

    protected function _getCurrentCurrencyCode()
    {
        return $this->_getQuote()->getQuoteCurrencyCode() ?: Mage::app()->getBaseCurrencyCode();
    }

    protected function _getCurrentCountryCode()
    {
        $billingParams = Mage::app()->getRequest()->getParam('billing');
        if (isset($billingParams['country_id'])) {
            return $billingParams['country_id'];
        }

        if ($country = $this->_getQuote()->getBillingAddress()->getCountry()) {
            return $country;
        }

        if (Mage::getStoreConfig('payment/account/merchant_country')) {
            return Mage::getStoreConfig('payment/account/merchant_country');
        }

        return null;
    }

    protected function _getCurrentPaymentAmount()
    {
        if ($grandTotal = $this->_getQuote()->getGrandTotal() > 0) {
            return $grandTotal;
        }
        return 10;
    }


    /**
     * @param $requestParams
     * @return array
     * @throws Mage_Core_Exception
     * @todo implement caching, exclude sessionValidity
     */
    protected function _getResponse($requestParams, Mage_Core_Model_Store $store)
    {
        $cacheKey = $this->_getCacheKeyForRequest($requestParams, $store);
        if ($responseData = Mage::app()->getCache()->load($cacheKey)) {
            Mage::log('loadcache');
            return unserialize($responseData);
        }

        $this->_signRequestParams($requestParams, $store);
        $ch = curl_init();

        $url = Mage::helper('adyen')->getConfigDataDemoMode()
            ? "https://test.adyen.com/hpp/directory.shtml"
            : "https://live.adyen.com/hpp/directory.shtml";
        curl_setopt($ch, CURLOPT_URL, $url);

        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, count($requestParams));
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($requestParams));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $results = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpStatus != 200) {
            Mage::throwException(
                Mage::helper('adyen')->__('HTTP Status code %s received, data %s', $httpStatus, $results)
            );
        }

        if ($results === false) {
            Mage::throwException(
                Mage::helper('adyen')->__('Got an empty response, status code %s', $httpStatus)
            );
        }

        $responseData = json_decode($results, true);
        if (! $responseData || !isset($responseData['paymentMethods'])) {
            Mage::throwException(
                Mage::helper('adyen')->__('Did not receive JSON, could not retrieve payment methods, received %s', $results)
            );
        }

        Mage::app()->getCache()->save(serialize($responseData), $cacheKey, array(Mage_Core_Model_Config::CACHE_TAG));

        return $responseData;
    }

    protected $_requestFields = array(

    );

    protected $_cacheParams = array(
        'currencyCode',
        'merchantReference',
        'skinCode',
        'merchantAccount',
        'countryCode',
        'shopperLocale',
    );


    /**
     * @param                       $requestParams
     * @param Mage_Core_Model_Store $store
     * @return string
     */
    protected function _getCacheKeyForRequest($requestParams, Mage_Core_Model_Store $store)
    {
        $cacheParams = array();
        $cacheParams['store'] = $store->getId();
        foreach ($this->_cacheParams as $paramKey) {
            if (isset($requestParams[$paramKey])) {
                $cacheParams[$paramKey] = $requestParams[$paramKey];
            }
        }

        return md5(implode('|', $cacheParams));
    }

    protected $_requiredHmacFields = array(
        'merchantReference',
        'paymentAmount',
        'currencyCode',
        'shipBeforeDate',
        'skinCode',
        'merchantAccount',
        'sessionValidity'
    );

    protected $_optionalHmacFields = array(
        'merchantReturnData',
        'shopperEmail',
        'shopperReference',
        'allowedMethods',
        'blockedMethods',
        'offset',
        'shopperStatement',
        'recurringContract',
        'billingAddressType',
        'deliveryAddressType'
    );


    /**
     * Communication between Adyen and the shop must be encoded with Hmac.
     * @param                       $fields
     * @param Mage_Core_Model_Store $store
     *
     * @throws Mage_Core_Exception
     * @throws Zend_Crypt_Hmac_Exception
     */
    protected function _signRequestParams(&$fields, Mage_Core_Model_Store $store)
    {
        unset($fields['merchantSig']);
        $hmacFields = $fields;

        foreach ($this->_requiredHmacFields as $requiredHmacField) {
            if (! isset($fields[$requiredHmacField])) {
                $fields[$requiredHmacField] = '';
            }
        }

        foreach ($fields as $field => $value) {
            if (! in_array($field, $this->_requiredHmacFields)
                && ! in_array($field, $this->_optionalHmacFields)) {
                unset($hmacFields[$field]);
            }
        }

        if (! $hmacKey = $this->_getHmacKey($store)) {
            Mage::throwException(Mage::helper('adyen')->__('You forgot to fill in HMAC key for Test or Live'));
        }

        $signMac = Zend_Crypt_Hmac::compute($hmacKey, 'sha1', implode('', $hmacFields));
        $fields['merchantSig'] = base64_encode(pack('H*', $signMac));
    }


    /**
     * Get the Hmac key from the config
     * @param Mage_Core_Model_Store $store
     * @return string
     */
    protected function _getHmacKey(Mage_Core_Model_Store $store)
    {
        $adyenHelper = Mage::helper('adyen');
        switch ($adyenHelper->getConfigDataDemoMode()) {
            case true:
                $secretWord = trim($adyenHelper->_getConfigData('secret_wordt', 'adyen_hpp'));
                break;
            default:
                $secretWord = trim($adyenHelper->_getConfigData('secret_wordp', 'adyen_hpp'));
                break;
        }
        return $secretWord;
    }


    protected $_fieldMapPaymentMethod = array(
        'name' => 'title'
    );

    protected function _fieldMapPaymentMethod($paymentMethod)
    {
        foreach ($this->_fieldMapPaymentMethod as $field => $newField) {
            if (isset($paymentMethod[$field])) {
                $paymentMethod[$newField] = $paymentMethod[$field];
                unset($paymentMethod[$field]);
            }
        }
        return $paymentMethod;
    }



    public function salesOrderPaymentCancel(Varien_Event_Observer $observer) {
        // observer is payment object
        $payment = $observer->getEvent()->getPayment();
        $order = $payment->getOrder();

        if($this->isPaymentMethodAdyen($order)) {
            $pspReference = Mage::getModel('adyen/event')->getOriginalPspReference($order->getIncrementId());
            $payment->getMethodInstance()->SendCancelOrRefund($payment, $pspReference);
        }
    }

    /**
     * Determine if the payment method is Adyen
     * @param type $order
     * @return boolean
     */
    public function isPaymentMethodAdyen($order) {
        return ( strpos($order->getPayment()->getMethod(), 'adyen') !== false ) ? true : false;
    }
}