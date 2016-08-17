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
class Adyen_Payment_Helper_Payment extends Adyen_Payment_Helper_Data
{

    /**
     * @var GUEST_ID , used when order is placed by guests
     */
    const GUEST_ID = 'customer_';


    /**
     * @param array $fields
     * @param bool $isConfigDemoMode
     * @return string
     */
    public function getFormUrl($fields, $isConfigDemoMode = false)
    {
        switch ($isConfigDemoMode) {
            case true:
                $url = 'https://test.adyen.com/hpp/pay.shtml';
                break;
            default:
                $url = 'https://live.adyen.com/hpp/pay.shtml';
                break;
        }

        $url .= '?' . http_build_query($fields, '', '&');

        return $url;
    }

    /**
     * @desc prepares an array with order detail values to call the Adyen HPP page.
     *
     * @param $orderCurrencyCode
     * @param $realOrderId
     * @param $orderGrandTotal
     * @param $shopperEmail
     * @param $customerId
     * @param $merchantReturnData
     * @param $orderStoreId
     * @param $storeLocaleCode
     * @param $billingCountryCode
     *
     * @return array
     */
    public function prepareFieldsForUrl(
        $orderCurrencyCode,
        $realOrderId,
        $orderGrandTotal,
        $shopperEmail,
        $customerId,
        $merchantReturnData,
        $orderStoreId,
        $storeLocaleCode,
        $billingCountryCode
    )
    {
        // check if Pay By Mail has a skincode and secretword, otherwise use HPP
        $skinCode = trim($this->getConfigData('skin_code', 'adyen_pay_by_mail', $orderStoreId));
        $secretWord = $this->_getSecretWord($orderStoreId, 'adyen_pay_by_mail');

        if ($skinCode=="") {
            $skinCode = trim($this->getConfigData('skin_code', 'adyen_hpp', $orderStoreId));
            $secretWord = $this->_getSecretWord($orderStoreId, 'adyen_hpp');
        }


        $merchantAccount = trim($this->getConfigData('merchantAccount', null, $orderStoreId));
        $amount = Mage::helper('adyen')->formatAmount($orderGrandTotal, $orderCurrencyCode);

        $shopperLocale = trim($this->getConfigData('shopperlocale', null, $orderStoreId));
        $shopperLocale = (!empty($shopperLocale)) ? $shopperLocale : $storeLocaleCode;

        $countryCode = trim($this->getConfigData('countryCode', null, $orderStoreId));
        $countryCode = (!empty($countryCode)) ? $countryCode : $billingCountryCode;

        // shipBeforeDate is a required field by certain payment methods
        $deliveryDays = (int)$this->getConfigData('delivery_days', 'adyen_hpp', $orderStoreId);
        $deliveryDays = (!empty($deliveryDays)) ? $deliveryDays : 5 ;

        $shipBeforeDate = new DateTime("now");
        $shipBeforeDate->add(new DateInterval("P{$deliveryDays}D"));

        // number of days link is valid to use
        $sessionValidity = (int)trim($this->getConfigData('session_validity', 'adyen_pay_by_mail', $orderStoreId));
        $sessionValidity = ($sessionValidity == "") ? 3 : $sessionValidity ;

        $sessionValidityDate = new DateTime("now");
        $sessionValidityDate->add(new DateInterval("P{$sessionValidity}D"));

        // is recurring?
        $recurringType = trim($this->getConfigData('recurringtypes', 'adyen_abstract', $orderStoreId));

        /*
         * This field will be appended as-is to the return URL when the shopper completes, or abandons, the payment and
         * returns to your shop; it is typically used to transmit a session ID. This field has a maximum of 128 characters
         * This is an optional field and not necessary by default
         */
        $dataString = (is_array($merchantReturnData)) ? serialize($merchantReturnData) : $merchantReturnData;

        $adyFields = $this->adyenValueArray(
            $orderCurrencyCode,
            $realOrderId,
            $shopperEmail,
            $customerId,
            $merchantAccount,
            $amount,
            $shipBeforeDate,
            $skinCode,
            $shopperLocale,
            $countryCode,
            $recurringType,
            $dataString
        );

        // calculate the signature
        $adyFields['merchantSig'] = $this->createHmacSignature($adyFields, $secretWord);

        return $adyFields;
    }

    /**
     * @descr format the data in a specific array
     *
     * @param $orderCurrencyCode
     * @param $realOrderId
     * @param $shopperEmail
     * @param $customerId
     * @param $merchantAccount
     * @param $amount
     * @param $shipBeforeDate
     * @param $skinCode
     * @param $shopperLocale
     * @param $countryCode
     * @param $recurringType
     * @param $dataString
     * 
     * @return array
     */
    public function adyenValueArray(
        $orderCurrencyCode,
        $realOrderId,
        $shopperEmail,
        $customerId,
        $merchantAccount,
        $amount,
        $shipBeforeDate,
        $skinCode,
        $shopperLocale,
        $countryCode,
        $recurringType,
        $dataString
    )
    {
        $adyFields = [
            'merchantAccount' => $merchantAccount,
            'merchantReference' => $merchantAccount,
            'paymentAmount' => (int)$amount,
            'currencyCode' => $orderCurrencyCode,
            'shipBeforeDate' => $shipBeforeDate->format('Y-m-d'),
            'skinCode' => $skinCode,
            'shopperLocale' => $shopperLocale,
            'countryCode' => $countryCode,
            'sessionValidity' => $shipBeforeDate->format("c"),
            'shopperEmail' => $shopperEmail,
            'recurringContract' => $recurringType,
            'shopperReference' => (!empty($customerId)) ? $customerId : self::GUEST_ID . $realOrderId,
            'billingAddressType' => "",
            'deliveryAddressType' => "",
            'shopperType' => "",
            'merchantReturnData' => substr(urlencode($dataString), 0, 128),

            // @todo remove this and add allowed methods via a config xml node
            'blockedMethods' => "",
        ];

        return $adyFields;
    }

    /**
     * @param null $storeId
     * @param $paymentMethodCode
     * @return string
     */
    public function _getSecretWord($storeId=null, $paymentMethodCode)
    {
        switch ($this->getConfigDataDemoMode()) {
            case true:
                $secretWord = trim($this->getConfigData('secret_wordt', $paymentMethodCode, $storeId));
                break;
            default:
                $secretWord = trim($this->getConfigData('secret_wordp', $paymentMethodCode ,$storeId));
                break;
        }
        return $secretWord;
    }

    /**
     * @desc The character escape function is called from the array_map function in _signRequestParams
     * @param $val
     * @return string
     */
    public function escapeString($val)
    {
        return str_replace(':','\\:',str_replace('\\','\\\\',$val));
    }

    /**
     * @descr Hmac key signing is standardised by Adyen
     * - first we order the array by string
     * - then we create a column seperated array with first all the keys, then all the values
     * - finally generating the SHA256 HMAC encrypted merchant signature
     * @param $adyFields
     * @param $secretWord
     * @return string
     */
    public function createHmacSignature($adyFields, $secretWord)
    {
        ksort($adyFields, SORT_STRING);

        $signData = implode(":", array_map([$this, 'escapeString'], array_merge(
            array_keys($adyFields),
            array_values($adyFields)
        )));

        $signMac = Zend_Crypt_Hmac::compute(pack("H*", $secretWord), 'sha256', $signData);

        return base64_encode(pack('H*', $signMac));
    }
}
