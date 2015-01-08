<?php
/**
 * Adyen_Payment
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the H&O Commercial License
 * that is bundled with this package in the file LICENSE_HO.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.h-o.nl/license
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@h-o.com so we can send you a copy immediately.
 *
 * @category  Adyen
 * @package   Adyen_Payment
 * @author    Paul Hachmang – H&O <info@h-o.nl>
 * @copyright 2015 Copyright © H&O (http://www.h-o.nl/)
 * @license   H&O Commercial License (http://www.h-o.nl/license)
 */
 
class Adyen_Payment_Model_Adyen_Hpp_Default
    extends Adyen_Payment_Model_Adyen_Hpp
{
    public function __construct()
    {
        parent::__construct();
//        var_dump($this);exit;
    }

    public function setCode($code)
    {
        $this->_code = $code;
    }
}
