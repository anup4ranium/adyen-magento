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
class Adyen_Payment_Model_Adyen_Oneclick extends Adyen_Payment_Model_Adyen_Cc {

    protected $_code = 'adyen_oneclick';
    protected $_formBlockType = 'adyen/form_oneclick';
    protected $_infoBlockType = 'adyen/info_oneclick';
    protected $_paymentMethod = 'oneclick';
    protected $_canUseInternal = true; // not possible through backoffice interface


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


    /*
     * only enable if adyen_cc is enabled
     */
    public function isAvailable($quote = null)
    {
        $isAvailable = parent::isAvailable($quote);

        // check if adyen_cc is enabled if not disable oneclick as well
        $isCCActive = $this->_getConfigData('active', 'adyen_cc');
        if(!$isCCActive)
            return false;

        return $isAvailable;
    }

    public function assignData($data) {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }
        $info = $this->getInfoInstance();

        // get storeId
        if(Mage::app()->getStore()->isAdmin()) {
            $store = Mage::getSingleton('adminhtml/session_quote')->getStore();
        } else {
            $store = Mage::app()->getStore();
        }
        $storeId = $store->getId();

        // Get recurringDetailReference from config
        $recurringDetailReference = Mage::getStoreConfig("payment/".$this->getCode() . "/recurringDetailReference", $storeId);
        $info->setAdditionalInformation('recurring_detail_reference', $recurringDetailReference);

        $ccType = Mage::getStoreConfig("payment/".$this->getCode() . "/variant", $storeId);
        $info->setCcType($ccType);

        if ($this->isCseEnabled()) {
            $info->setAdditionalInformation('encrypted_data', $data->getEncryptedData());
        } else {

            // check if expiry month and year is changed
            $expiryMonth = $data->getOneclickExpMonth();
            $expiryYear = $data->getOneclickExpYear();
            $cvcCode = $data->getOneclickCid();

            $cardHolderName = Mage::getStoreConfig("payment/".$this->getCode() . "/card_holderName", $storeId);
            $last4Digits = Mage::getStoreConfig("payment/".$this->getCode() . "/card_number", $storeId);
            $cardHolderName = Mage::getStoreConfig("payment/".$this->getCode() . "/card_holderName", $storeId);

            // just set default data for info block only
            $info->setCcType($ccType)
                ->setCcOwner($cardHolderName)
                ->setCcLast4($last4Digits)
                ->setCcExpMonth($expiryMonth)
                ->setCcExpYear($expiryYear)
                ->setCcCid($cvcCode);
        }

        if(Mage::helper('adyen/installments')->isInstallmentsEnabled()) {
            $info->setAdditionalInformation('number_of_installments', $data->getInstallment());
        } else {
            $info->setAdditionalInformation('number_of_installments', "");

        }

        // recalculate the totals so that extra fee is defined
        $quote = (Mage::getModel('checkout/type_onepage') !== false)? Mage::getModel('checkout/type_onepage')->getQuote(): Mage::getModel('checkout/session')->getQuote();
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();

        return $this;
    }

    public function getlistRecurringDetails()
    {
        $quote = (Mage::getModel('checkout/type_onepage') !== false)? Mage::getModel('checkout/type_onepage')->getQuote(): Mage::getModel('checkout/session')->getQuote();
        $customerId = $quote->getCustomerId();
        return $this->_processRecurringRequest($customerId);
    }

    public function isNotRecurring() {
        $recurring_type = $this->_getConfigData('recurringtypes', 'adyen_abstract');
        if($recurring_type == "RECURRING")
            return false;
        return true;
    }

}