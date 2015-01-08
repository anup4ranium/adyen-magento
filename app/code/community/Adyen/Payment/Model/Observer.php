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


    public function addHppMethodsToConfig(Varien_Event_Observer $observer)
    {
        $store = Mage::app()->getStore();
        if ($store->isAdmin()) {
            foreach (Mage::app()->getStores() as $store) {
                $this->_addHppMethodsToConfig($store);
            }
        } else {
            $this->_addHppMethodsToConfig($store);
        }
    }

    protected function _addHppMethodsToConfig(Mage_Core_Model_Store $store)
    {
        $paymentConfig = Mage::getStoreConfig('payment/adyen_hpp', $store);

        $configBase = $paymentConfig;
        Mage::getConfig()->setNode('default/payment/adyen_hpp/is_active', 0);
        $paymentConfig['adyen_hpp']['active'] = 0;

        foreach ($this->_fetchHppMethods($store) as $methodCode => $methodData) {
            $methodNewCode = 'adyen_hpp_'.$methodCode;
            $methodData = $methodData + $configBase;

            $className = Mage::getConfig()->getModelClassName('adyen/adyen_hpp_'. $methodCode);
            if (class_exists($className, false)) {
                $methodData['model'] = 'adyen/adyen_hpp_'.$methodCode;
            } else {
                $methodData['model'] = 'adyen/adyen_hpp_default';
            }

            foreach ($methodData as $key => $value) {
                if (is_object($value) || is_array($value)) {
                    $value = json_encode($value);
                }
                Mage::getConfig()->setNode('stores/'.$store->getCode().'/payment/'.$methodNewCode.'/'.$key, $value);
            }
        }
    }


    protected function _fetchHppMethods(Mage_Core_Model_Store $store)
    {
        $adyenHelper = Mage::helper('adyen');

        //@todo currency code filter, move to loading of payment method.
        $orderCurrencyCode = Mage::app()->getStore()->getCurrentCurrencyCode(); //Mage::helper('checkout/cart')->getQuote()->getQuoteCurrencyCode();

        $skinCode          = $adyenHelper->_getConfigData('skinCode', 'adyen_hpp', $store);
        $merchantAccount   = $adyenHelper->_getConfigData('merchantAccount', null, $store);

        //@todo amount filtering, move to loading of payment method.
//        $amount               = Mage::helper('adyen')->formatAmount(
//            Mage::helper('checkout/cart')->getQuote()->getGrandTotal(),
//            $orderCurrencyCode
//        );

        //@todo implement proper caching, remove config setting for the caching.
//        $cacheDirectoryLookup = trim($this->_getConfigData('cache_directory_lookup', 'adyen_hpp'));


        //@todo country code filter, move to loading of payment method
//        $countryCode = Mage::helper('adyen')->_getConfigData('countryCode');
//        if (empty($countryCode)) {
//            // check if billingcountry is filled in
//            if (is_object(Mage::helper('checkout/cart')->getQuote()->getBillingAddress())
//                && Mage::helper('checkout/cart')->getQuote()->getBillingAddress()->getCountry() != ""
//            ) {
//                $countryCode = Mage::helper('checkout/cart')->getQuote()->getBillingAddress()->getCountry();
//            } else {
//                $countryCode = ""; // don't set countryCode so you get all the payment methods
//                // You could do ip lookup but availability and performace is not guaranteed
////         		$ip = $this->getClientIp();
////         		$countryCode = file_get_contents('http://api.hostip.info/country.php?ip='.$ip);
//            }
//        }
//        // check if cache setting is on
//        if ($cacheDirectoryLookup) {
//            // cache name has variables merchantAccount, skinCode, currencycode and country code. Amound is not cached because of performance issues
//            $cacheId
//                =
//                'cache_directory_lookup_request_' . $merchantAccount . "_" . $skinCode . "_" . $orderCurrencyCode . "_"
//                . $countryCode;
//            // check if this request is already cached
//            if (false !== ($data = Mage::app()->getCache()->load($cacheId))) {
//                // return result from cache
//                return unserialize($data);
//            }
//        }
        // directory lookup to search for available payment methods

        $adyFields = array(
            "paymentAmount"     => '10',
            "currencyCode"      => $orderCurrencyCode,
            "merchantReference" => "Get Payment methods",
            "skinCode"          => $skinCode,
            "merchantAccount"   => $merchantAccount,
            "sessionValidity"   => date(
                DATE_ATOM,
                mktime(date("H") + 1, date("i"), date("s"), date("m"), date("j"), date("Y"))
            ),
//            "countryCode"       => 'DE',//$countryCode,
            "shopperLocale"     => Mage::app()->getLocale()->getLocaleCode(),//$countryCode,
        );
        $responseData = $this->_getResponse($adyFields, $store);

        $paymentMethods = array();
        foreach ($responseData['paymentMethods'] as $paymentMethod) {
            $paymentMethod = $this->_fieldMapPaymentMethod($paymentMethod);
            $paymentMethodCode = $paymentMethod['brandCode'];

            //Skip open invoice methods if they are enabled
            if (Mage::getStoreConfig("payment/adyen_openinvoice/active")
                && Mage::getStoreConfig("payment/adyen_openinvoice/openinvoicetypes") == $paymentMethodCode) {
                continue;
            }

            //@todo Skip credit card methods if they are enabled.

            unset($paymentMethod['brandCode']);
            $paymentMethods[$paymentMethodCode] = $paymentMethod;
        }

        return $paymentMethods;


//            $payment_methods = $results_json->paymentMethods;
//            $result_array = array();
//            foreach ($payment_methods as $payment_method) {
//                // if openinvoice is activated don't show this in HPP options
//                if (Mage::getStoreConfig("payment/adyen_openinvoice/active")) {
//                    if (Mage::getStoreConfig("payment/adyen_openinvoice/openinvoicetypes")
//                        == $payment_method->brandCode
//                    ) {
//                        continue;
//                    }
//                }
//                $result_array[$payment_method->brandCode]['name'] = $payment_method->name;
//                if (isset($payment_method->issuers)) {
//                    // for ideal go through the issuers
//                    if (count($payment_method->issuers) > 0) {
//                        foreach ($payment_method->issuers as $issuer) {
//                            $result_array[$payment_method->brandCode]['issuers'][$issuer->issuerId] = $issuer->name;
//                        }
//                    }
//                    ksort($result_array[$payment_method->brandCode]['issuers']); // sort on key
//                }
//            }
//        // if cache is on cache this result
//        if ($cacheDirectoryLookup) {
//            Mage::app()->getCache()->save(serialize($result_array), $cacheId);
//        }
    }


    /**
     * @param $requestParams
     * @return array
     * @throws Mage_Core_Exception
     */
    protected function _getResponse($requestParams, Mage_Core_Model_Store $store)
    {
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

        return $responseData;
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