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
class Adyen_Payment_Block_Form_Cc extends Mage_Payment_Block_Form_Cc
{

    protected function _construct()
    {
        if (Mage::getStoreConfig('payment/adyen_abstract/title_renderer')
            == Adyen_Payment_Model_Source_Rendermode::MODE_TITLE_IMAGE) {
            $this->setMethodTitle('');
        }

        parent::_construct();
    }

    public function getMethodLabelAfterHtml()
    {
        if (Mage::getStoreConfig('payment/adyen_abstract/title_renderer')
            == Adyen_Payment_Model_Source_Rendermode::MODE_TITLE) {
            return '';
        }

        if (! $this->hasData('_method_label_html')) {
            $labelBlock = Mage::app()->getLayout()->createBlock('core/template', null, array(
                'template' => 'adyen/payment/payment_method_label.phtml',
                'payment_method_icon' =>  $this->getSkinUrl('images'.DS.'adyen'.DS."img_trans.gif"),
                'payment_method_label' => Mage::helper('adyen')->getConfigData('title', $this->getMethod()->getCode()),
                'payment_method_class' => $this->getMethod()->getCode()
            ));

            $this->setData('_method_label_html', $labelBlock->toHtml());
        }

        return $this->getData('_method_label_html');
    }
	
    /**
     * Retrieve availables credit card types
     *
     * @return array
     */
    public function getCcAvailableTypes() {
        return $this->getMethod()->getAvailableCCTypes();
    }
    
    public function isCseEnabled() {
        return $this->getMethod()->isCseEnabled();
    }
    public function getCsePublicKey() {
        return $this->getMethod()->getCsePublicKey();
    }
	
    public function getPossibleInstallments(){
        return $this->getMethod()->getPossibleInstallments();
    }
    
    public function hasInstallments(){
        return Mage::helper('adyen/installments')->isInstallmentsEnabled();
    }

    public function getRecurringType() {
        return $this->getMethod()->getRecurringType();
    }

    /**
     * Alway's return true for creditcard verification otherwise api call to adyen won't work
     *
     * @return boolean
     */
    public function hasVerification()
    {
    	return true;
    }

}
