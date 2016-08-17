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
 * @category     Adyen
 * @package      Adyen_Payment
 * @copyright    Copyright (c) 2011 Adyen (http://www.adyen.com)
 * @license      http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */



/**
 * @category   Payment Gateway
 * @package    Adyen_Payment
 * @author     Adyen
 * @property   Adyen B.V
 * @copyright  Copyright (c) 2014 Adyen BV (http://www.adyen.com)
 */
class Adyen_Payment_Model_Adyen_Hpp extends Adyen_Payment_Model_Adyen_Abstract
{
    /**
     * @var GUEST_ID , used when order is placed by guests
     */
    const GUEST_ID = 'customer_';

    protected $_canUseInternal = false;
    protected $_code = 'adyen_hpp';
    protected $_formBlockType = 'adyen/form_hpp';
    protected $_infoBlockType = 'adyen/info_hpp';
    protected $_paymentMethod = 'hpp';
    protected $_isInitializeNeeded = true;

    protected $_paymentMethodType = 'hpp';

    public function getPaymentMethodType() {
        return $this->_paymentMethodType;
    }

    /**
     * Ability to set the code, for dynamic payment methods.
     * @param $code
     * @return $this
     */
    public function setCode($code)
    {
        $this->_code = $code;
        return $this;
    }

    /**
     * @desc Get checkout session namespace
     *
     * @return Mage_Checkout_Model_Session
     */
    public function getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }
        $info    = $this->getInfoInstance();

        if(!$this->getHppOptionsDisabled()) {
            $hppType = str_replace('adyen_hpp_', '', $info->getData('method'));
            $hppType = str_replace('adyen_ideal', 'ideal', $hppType);
        } else {
            $hppType = null;
        }

        // set hpp type
        $info->setCcType($hppType);

        $hppTypeLabel =  Mage::getStoreConfig('payment/'.$info->getData('method').'/title');
        $info->setAdditionalInformation('hpp_type_label', $hppTypeLabel);

        // set bankId and label
        $selectedBankId = $data->getData('adyen_ideal_type');
        if($selectedBankId) {
            $issuers = $this->getInfoInstance()->getMethodInstance()->getIssuers();
            if(!empty($issuers)) {
                $info->setAdditionalInformation('hpp_type_bank_label', $issuers[$selectedBankId]['label']);
            }
            $info->setPoNumber($selectedBankId);
        }

        /* @note misused field */
        $config = Mage::getStoreConfig("payment/adyen_hpp/disable_hpptypes");
        if (empty($hppType) && empty($config)) {
            Mage::throwException(
                Mage::helper('adyen')->__('Payment Method is compulsory in order to process your payment')
            );
        }
        return $this;
    }


    public function validate()
    {
        parent::validate();
    }


    /**
     * @desc Called just after asssign data
     */
    public function prepareSave()
    {
        parent::prepareSave();
    }


    /**
     * @desc Get current quote
     *
     * @return Mage_Sales_Model_Quote
     */
    public function getQuote()
    {
        return $this->getCheckout()->getQuote();
    }


    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('adyen/process/redirect');
    }


    /**
     * @desc prepare params array to send it to gateway page via POST
     * @return array
     */
    public function getFormFields()
    {
        $this->_initOrder();
        /* @var $order Mage_Sales_Model_Order */
        $order             = $this->_order;
        $realOrderId       = $order->getRealOrderId();
        $orderCurrencyCode = $order->getOrderCurrencyCode();
        $shopperIP         = trim($order->getRemoteIp());

        $billingCountryCode = (is_object($order->getBillingAddress()) && $order->getBillingAddress()->getCountry() != "") ?
            $order->getBillingAddress()->getCountry() :
            false ;

        $hasDeliveryAddress = $order->getShippingAddress()!= null;


        $adyFields = Mage::helper('adyen/payment')->prepareFieldsForUrl(
            $orderCurrencyCode,
            $realOrderId,
            $order->getGrandTotal(),
            $order->getCustomerEmail(),
            $order->getCustomerId(),
            [],
            $order->getStoreId(),
            Mage::getStoreConfig('general/locale/code', $order->getStoreId()),
            $billingCountryCode,
            $shopperIP,
            $this->getInfoInstance()->getCcType(),
            $this->getInfoInstance()->getMethod(),
            trim($this->getInfoInstance()->getPoNumber()),
            $this->_code,
            $hasDeliveryAddress,
            $order
        );

        // get extra fields for open invoice
        $adyFields = Mage::getModel('adyen/adyen_openinvoice')->getOptionalFormFields($adyFields, $order, false);

        // calculate the signature
        $secretWord = Mage::helper('adyen/payment')->_getSecretWord($order->getStoreId(), $this->_code);
        $adyFields['merchantSig'] = Mage::helper('adyen/payment')->createHmacSignature($adyFields, $secretWord);

        Mage::log($adyFields, self::DEBUG_LEVEL, 'adyen_http-request.log', true);

        return $adyFields;
    }

    /**
     * @param array $fields
     * @return string
     */
    public function getFormUrl($fields = [])
    {
        $paymentRoutine     = $this->_getConfigData('payment_routines', 'adyen_hpp');
        $isConfigDemoMode   = $this->getConfigDataDemoMode();
        $hppOptionsDisabled = $this->getHppOptionsDisabled();

        return Mage::helper('adyen/payment')->getFormUrl($fields, $isConfigDemoMode, $paymentRoutine, $hppOptionsDisabled);
    }


    public function getFormName()
    {
        return "Adyen HPP";
    }


    /**
     * Return redirect block type
     *
     * @return string
     */
    public function getRedirectBlockType()
    {
        return $this->_redirectBlockType;
    }


    public function initialize($paymentAction, $stateObject)
    {
        $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        $stateObject->setState($state);
        $stateObject->setStatus($this->_getConfigData('order_status'));
    }

    public function getHppOptionsDisabled() {
        return Mage::getStoreConfig("payment/adyen_hpp/disable_hpptypes");
    }

    public function getShowIdealLogos() {
        return $this->_getConfigData('show_ideal_logos', 'adyen_hpp');
    }

    public function canCreateAdyenSubscription() {

        // validate if recurringType is correctly configured
        $recurringType = $this->_getConfigData('recurringtypes', 'adyen_abstract');
        if($recurringType == "RECURRING" || $recurringType == "ONECLICK,RECURRING") {
            return true;
        }
        return false;
    }

    /**
     * @param Mage_Sales_Model_Quote|null $quote
     * @return bool
     */
    public function isAvailable($quote = null)
    {
        $isAvailable = parent::isAvailable();
        
        if (!is_null($quote)) {
            $disableZeroTotal = Mage::getStoreConfig('payment/adyen_hpp/disable_zero_total', $quote->getStoreId());
        } else {
            $disableZeroTotal = Mage::getStoreConfig('payment/adyen_hpp/disable_zero_total');
        }
        
        if (!is_null($quote) && $quote->getGrandTotal() <= 0 && $disableZeroTotal) {
            return false;
        }

        return $isAvailable;
    }
}
