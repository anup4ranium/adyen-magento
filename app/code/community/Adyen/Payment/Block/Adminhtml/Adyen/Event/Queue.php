<?php
/**
 * Magento
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
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category    Mage
 * @package     Mage_Sales
 * @copyright   Copyright (c) 2014 Magento Inc. (http://www.magentocommerce.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Adminhtml sales orders block
 */

class Adyen_Payment_Block_Adminhtml_Adyen_Event_Queue extends Mage_Adminhtml_Block_Widget_Grid_Container {

    /**
     * Instructions to create child grid
     *
     * @var string
     */
    protected $_blockGroup = 'adyen';
    protected $_controller = 'adminhtml_adyen_event_queue';


    /**
     * Set header text and remove "add" btn
     */
    public function __construct()
    {
        $this->_headerText = Mage::helper('adyen')->__('Adyen Notification Queue');
        parent::__construct();
        $this->_removeButton('add');
    }



}