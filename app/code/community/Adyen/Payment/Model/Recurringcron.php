<?php
require_once(Mage::getBaseDir()."/vendor/autoload.php");

class Adyen_Payment_Model_Recurringcron {


    /**
     * Cronjob for installments payment
     *
     */
    public function processNextInstallment()
    {
        $todayDate = Mage::getModel('core/date')->date('Y-m-d');
        $connection = Mage::getSingleton('core/resource')->getConnection('core_read');
        $connWrite = Mage::getSingleton('core/resource')->getConnection('core_write');

        //Fetching adyen installment orders
        $sql = "SELECT adyen_order_installments.amount, adyen_order_installments.order_id AS order_id, customer_email, remote_ip, order_currency_code,sales_flat_order.customer_id, sales_flat_order.customer_firstname,sales_flat_order.customer_lastname,adyen_order_installments.increment_id,adyen_order_installments.id, adyen_order_installments.attempt, number_installment, sales_flat_order.created_at, adyen_order_installments.payment_date, (SELECT GROUP_CONCAT(agreement_id separator ', ') FROM `sales_billing_agreement_order` WHERE sales_billing_agreement_order.order_id = adyen_order_installments.order_id ORDER BY agreement_id DESC) AS agreements FROM adyen_order_installments INNER JOIN sales_flat_order  ON sales_flat_order.entity_id = adyen_order_installments.order_id WHERE (state = 'complete' OR sales_flat_order.status = 'shipped') AND adyen_order_installments.due_date <= '".$todayDate."' AND (adyen_order_installments.updated_at IS NULL OR adyen_order_installments.updated_at <> '".$todayDate."') AND adyen_order_installments.done = 0 AND sales_flat_order.status != 'adyen_installment_failed' GROUP BY adyen_order_installments.id";

        $installments = $connection->fetchAll($sql);

        if (empty($installments)) {
            return true;
        }

        // Mage::log(print_r($res,true), Zend_Log::DEBUG, "recurring_cron.log", true);

        $testLiveMode = Mage::getStoreConfig('payment/adyen_abstract/demoMode');

        foreach ($installments as $data) {
            // Mage::log(print_r($data,true), Zend_Log::DEBUG, "recurring_cron_data.log", true);

            $data['attempt'] = $data['attempt'] + 1;

            if (empty($data['agreements'])) {
                $this->sendErrorNotification($testLiveMode, $data, 'Missing billing agreements');
                continue;
            }

            //fetch all agreements for this order with order by updated_at
            $sql = "SELECT sales_billing_agreement.reference_id AS reference_id FROM `sales_billing_agreement` WHERE sales_billing_agreement.agreement_id IN (".$data['agreements'].") AND sales_billing_agreement.status = 'active' ORDER BY sales_billing_agreement.updated_at DESC, sales_billing_agreement.created_at DESC LIMIT 1";

            $billingAgreement = $connection->fetchRow($sql);

            if (empty($billingAgreement) || empty($billingAgreement['reference_id'])) {
                $this->sendErrorNotification($testLiveMode, $data, 'Missing active billing agreement');
                continue;
            }



            $postFields = array(
                "amount" => array (
                    'value' => Mage::helper('adyen')->formatAmount($data['amount'], $data['order_currency_code']),
                    'currency' => $data['order_currency_code']
                ),
                "reference" => $data['increment_id'],
                "merchantAccount" => Mage::getStoreConfig('payment/adyen_abstract/merchantAccount'),
                "shopperEmail" => $data['customer_email'],
                "shopperReference"  => $data['customer_id'],
                "selectedRecurringDetailReference" => $billingAgreement['reference_id'],
                "recurring" => array(
                    "contract" => "RECURRING"
                ),
                "shopperInteraction" => "ContAuth"
            );

            $postJson = json_encode($postFields);

            $webServiceNameTest = Mage::getStoreConfig('payment/adyen_abstract/ws_username_test');
            $webServicePasswordTest = Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/adyen_abstract/ws_password_test'));
            $webServiceNameLive = Mage::getStoreConfig('payment/adyen_abstract/ws_username_live');
            $webServicePasswordLive = Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/adyen_abstract/ws_password_live'));


            $client = new \Adyen\Client();

            if ($testLiveMode == 'Y') {
                $client->setUsername("$webServiceNameTest");
                $client->setPassword("$webServicePasswordTest");
                $client->setEnvironment(\Adyen\Environment::TEST);
            } else {
                $client->setUsername("$webServiceNameLive");
                $client->setPassword("$webServicePasswordLive");
                $client->setEnvironment(\Adyen\Environment::LIVE);
            }


            $service = new \Adyen\Service\Payment($client);
            $params = json_decode($postJson, true);

            try {
                $result = $service->authorise($params);
            } catch(Exception $e) {
                $errorMessage = $e->getMessage();

                $apiLog = array(
                    'request' => $postFields,
                    'response' => array('error' => $errorMessage)
                );
                Mage::log(print_r($apiLog,true), Zend_Log::DEBUG, "recurring_cron_api.log", true);

                //Sending emails
                $this->sendErrorNotification($testLiveMode, $data, $errorMessage);
                continue;
            }

            $apiLog = array(
                'request' => $postFields,
                'response' => $result
            );

            Mage::log(print_r($apiLog,true), Zend_Log::DEBUG, "recurring_cron_api.log", true);

            //save api response in DB
            $sqlResponse = " UPDATE adyen_order_installments SET response= '".json_encode($result)."' , updated_at = '".$todayDate."', attempt = attempt + 1 WHERE id='".$data['id']."' ";
            $connWrite->query($sqlResponse);



            $paymentDate = Mage::getModel('core/date')->date('Y-m-d H:i:s');

            if (!empty($result['resultCode']) && $result['resultCode'] == 'Authorised') {
                //increment attempt
                $sql = "UPDATE adyen_order_installments SET done = 1 , payment_date = '".$paymentDate."' WHERE id=".$data['id'];
                $connWrite->query($sql);

                if ($data['number_installment'] == 2) {
                    if ($testLiveMode == 'Y') {
                        $this->sendEmail($data, 48);
                    } else {
                        $this->sendEmail($data, 46);
                    }
                } else if ($data['number_installment'] == 3) {
                    if ($testLiveMode == 'Y') {
                        $this->sendEmail($data, 49);
                    } else {
                        $this->sendEmail($data, 47);
                    }
                }
            } else {
                $this->sendErrorNotification($testLiveMode, $data);
            }
        }

    }//end processNextInstallment


    public function sendErrorNotification ($testLiveMode, $data, $response=null) {
        if (!empty($response)) {
            $connWrite = Mage::getSingleton('core/resource')->getConnection('core_write');

            //Saving api response
            $todayDate = Mage::getModel('core/date')->date('Y-m-d');
            $sqlResponse = "UPDATE adyen_order_installments SET attempt = ".$data['attempt'].",response= '".$response."' , updated_at = '".$todayDate."' WHERE id='".$data['id']."' ";
            $connWrite->query($sqlResponse);

            $errorLog = array(
                'installmentData' => $data,
                'errorMessage' => $response
            );

            Mage::log(print_r($errorLog,true), Zend_Log::DEBUG, "recurring_cron_error.log", true);
        }

        //Sending emails
        if ($data['attempt'] == 1) {
            if ($testLiveMode == 'Y') {
               //first attempt fails
                $this->sendEmail($data, 45);
            } else {
                //first attempt fails
                $this->sendEmail($data, 43);
            }
        } else if ($data['attempt'] == 2) {
            if ($testLiveMode == 'Y') {
                //if second attempt fails
                $this->sendEmail($data, 46);
            } else {
                //second attempt fails
                $this->sendEmail($data, 44);
            }
        } else if ($data['attempt'] >= 3) {
            //setting order status
            // $sql = "update sales_flat_order set status = 'adyen_installment_failed' where increment_id ='".$data['increment_id']."' ";
            // $connWrite->query($sql);
            //if third or more attempt fails
            if ($testLiveMode == 'Y') {
                $this->sendEmail($data, 47);
            } else {
                $this->sendEmail($data, 45);
            }
        }
    }


    public function sendEmail($data, $templateId) {
        setlocale(LC_TIME, "fr_FR");
        //send email to admin ,info about failure
        // Get Store ID
        $store = Mage::app()->getStore()->getId();

        //Getting the Store E-Mail Sender Name.
        $senderName = Mage::getStoreConfig('trans_email/ident_general/name');

        //Getting the Store General E-Mail.
        $senderEmail = Mage::getStoreConfig('trans_email/ident_general/email');

        $customerName = $data['customer_firstname']." ".$data['customer_lastname'];
        $customerEmail = $data['customer_email'];

        $installmentDetails = $this->getInstallmentDetails($data['increment_id']);
        $failedInstallmentDetails = $this->getFailedInstallmentDetails($data['increment_id'], $data['number_installment']);
        $secondInstallmentAmount = Mage::getModel('directory/currency')->formatTxt($installmentDetails[1]['amount'], array('display' => Zend_Currency::NO_SYMBOL));
        $secondInstallmentDate = strftime("%a %d %b", strtotime($installmentDetails[1]['payment_date']));
        $thirdInstallmentAmount = Mage::getModel('directory/currency')->formatTxt($installmentDetails[2]['amount'], array('display' => Zend_Currency::NO_SYMBOL));
        $thirdInstallmentDueDate = strftime("%a %d %b", strtotime($installmentDetails[2]['due_date']));
        $thirdInstallmentDate = strftime("%a %d %b", strtotime($installmentDetails[2]['payment_date']));
        $failedInstallmentAmount = Mage::getModel('directory/currency')->formatTxt($failedInstallmentDetails[0]['amount'], array('display' => Zend_Currency::NO_SYMBOL));
        $failedInstallmentDate = strftime("%a %d %b", strtotime($failedInstallmentDetails[0]['due_date']));

        //Variables.
        $emailTemplateVariables = array();
        $emailTemplateVariables['customername'] = $customerName;
        $emailTemplateVariables['customeremail'] = $customerEmail;
        $emailTemplateVariables['emailEncoded'] = urlencode($customerEmail);
        $emailTemplateVariables['orderId'] = $data['increment_id'];
        $emailTemplateVariables['orderEntityId'] = $data['order_id'];
        // $emailTemplateVariables['response'] = json_encode($result);
        $emailTemplateVariables['secondInstallmentAmount'] =  $secondInstallmentAmount.' &euro;';
        $emailTemplateVariables['secondInstallmentDate'] = utf8_encode($secondInstallmentDate);
        $emailTemplateVariables['thirdInstallmentAmount'] =  $thirdInstallmentAmount.' &euro;';
        $emailTemplateVariables['thirdInstallmentDueDate'] = utf8_encode($thirdInstallmentDueDate);
        $emailTemplateVariables['thirdInstallmentDate'] = utf8_encode($thirdInstallmentDate);
        $emailTemplateVariables['failedAmount'] = $failedInstallmentAmount.' &euro;';
        $emailTemplateVariables['failedDate'] = utf8_encode($failedInstallmentDate);
        $emailTemplateVariables['orderDate'] = utf8_encode(strftime("%a %d %b", strtotime($data['created_at'])));
        $recepientEmails = array($customerEmail);

        $translate  = Mage::getSingleton('core/translate');
        $sender = array('name' => $senderName,
                    'email' => $senderEmail);

        // Send Transactional Email (transactional email template ID from admin)
        Mage::getModel('core/email_template')
            ->sendTransactional($templateId, $sender, $recepientEmails, '', $emailTemplateVariables, $store);

        $translate->setTranslateInline(true);
    }


    public function getInstallmentDetails($increment_id) {
        $conn = Mage::getSingleton('core/resource')->getConnection('core_read');
        $sql = "select * from adyen_order_installments where increment_id = '".$increment_id."' ";
        $res = $conn->fetchAll($sql);
        return $res;
    }

    public function getFailedInstallmentDetails($increment_id, $installmentNo) {
        $conn = Mage::getSingleton('core/resource')->getConnection('core_read');
        $sql = "select * from adyen_order_installments where increment_id = '".$increment_id."' and number_installment = '".$installmentNo."'";
        $res = $conn->fetchAll($sql);
        return $res;
    }


}//end class