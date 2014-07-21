<?php

/* 
 * Sagepay Extension for CiviCRM - Circle Interactive 2012
 * Author: andyw@circle
 *
 * Distributed under GPL v2
 * https://www.gnu.org/licenses/gpl-2.0.html
 */

// Define logging level (0 = off, 4 = log everything)
define('SAGEPAY_LOGGING_LEVEL', 4);

// Number of failures at which to cancel a recurring payment
define('SAGEPAY_CANCEL_RECURRING_ON_N_FAILURES', 10);

require_once 'CRM/Core/Payment.php';

class uk_co_circleinteractive_payment_sagepay extends CRM_Core_Payment {
    
    public $logging_level      = 0;
    protected $_mode           = null;
    static private $_singleton = null; 
    
    public static function &singleton($mode = 'test', &$paymentProcessor, &$paymentForm = null, $force = false) {
        
        $processorName = $paymentProcessor['name'];
        if (is_null(self::$_singleton[$processorName]))
            self::$_singleton[$processorName] = new uk_co_circleinteractive_payment_sagepay($mode, $paymentProcessor);
        return self::$_singleton[$processorName];
    
    }
    
    public function __construct($mode, &$paymentProcessor) {
        
        $this->_mode             = $mode;
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName    = ts('Sagepay');
        $this->logging_level     = SAGEPAY_LOGGING_LEVEL;
                    
    }
    
    // Mini api to internal data
    public function api($action, $data_type, $params = array()) {
        
        // Check data type is supported
        switch ($data_type) {
            case 'key':
            case 'recurring':
                if (!isset($params['entity_id']))
                    return array(
                        'error'   => 1, 
                        'message' => 'No entity id supplied'
                    );
            case 'log message':
                break;
            default:
                return array(
                    'error'   => 1,
                    'message' => 'Unsupported data type: ' . $data_type
                );
        }

        require_once 'CRM/Core/DAO.php';
        
        switch ($action) {
            
            // Retrieve data row
            case 'select':
            case 'get':
                if ($data = CRM_Core_DAO::singleValueQuery("
                   SELECT data FROM civicrm_sagepay WHERE data_type = %1 AND entity_id = %2    
                ", array(
                      1 => array($data_type, 'String'),
                      2 => array($params['entity_id'], 'Integer')
                   )
                )) 
                    return unserialize($data);
                else
                    return array(
                        'error'   => 1,
                        'message' => 'Item not found :('
                    );
            
            // Insert / create data row
            case 'create':
            case 'insert':
                
                // Map data types to entity types
                $entity_ref = array(
                    'key'         => 'contribution',
                    'recurring'   => 'contribution_recur',
                    'log message' => isset($params['entity_type']) ? $params['entity_type'] : 'message'
                );
                
                CRM_Core_DAO::executeQuery("
                   INSERT INTO civicrm_sagepay
                     (id, created, data_type, entity_type, entity_id, data)
                   VALUES
                     (NULL, NOW(), %1, %2, %3, %4)
                ", array(
                      1 => array($data_type, 'String'),
                      2 => array(isset($entity_ref[$data_type]) ? $entity_ref[$data_type] : 'nothing', 'String'),
                      3 => array(isset($params['entity_id']) ? $params['entity_id'] : 0, 'Integer'),
                      4 => array(serialize($params['data']), 'String')
                   )
                );
                
                return array('ok' => 1);
           
           // Update data row
           case 'update':
                CRM_Core_DAO::executeQuery("
                   UPDATE civicrm_sagepay SET data = %1 WHERE data_type = %2 AND entity_id = %3
                ", array(
                      1 => array(serialize($params['data']), 'String'),
                      2 => array($data_type, 'String'),
                      3 => array($params['entity_id'], 'Integer')
                   )
                );
                return array('ok' => 1);
           
           // Delete data row
           case 'delete':
                CRM_Core_DAO::executeQuery("
                   DELETE FROM civicrm_sagepay WHERE data_type = %1 AND entity_id = %2
                ", array(
                      1 => array($data_type, 'String'),
                      2 => array($params['entity_id'], 'Integer')
                   )
                );
                return array('ok' => 1);
                
        }
    }
    
    public function checkConfig() {
        
        $error = array();
        
        if (!$this->_paymentProcessor['user_name']) 
            $errors[] = 'No username supplied for Sagepay payment processor';
        
        if (!empty($errors)) 
            return '<p>' . implode('</p><p>', $errors) . '</p>';
        
        return null;
    
    }
    
    public function disable() {
        // .. code to run when extension disabled ..
    }
    
    // Not req'd for billingMode=notify
    public function doDirectPayment(&$params) {
        return null;    
    }
    
    // Initialize REPEAT transaction
    public function doRepeatCheckout(&$params) {
        
        $config = &CRM_Core_Config::singleton();
        $repeatParams = array(
            'VPSProtocol'         => '2.23',
            'TxType'              => 'REPEAT',
            'Vendor'              => $this->_paymentProcessor['user_name'],
            'VendorTxCode'        => $params['invoiceID'],
            'Amount'              => sprintf("%.2f", $params['amount']),
            'Currency'            => $params['currencyID'],
            'Description'         => substr($params['item_name'], 0, 100),
            'RelatedVPSTxId'      => $params['RelatedVPSTxId'],
            'RelatedVendorTxCode' => $params['RelatedVendorTxCode'],
            'RelatedSecurityKey'  => $params['RelatedSecurityKey'],
            'RelatedTxAuthNo'     => $params['RelatedTxAuthNo']
        );
        
        // Allow other modules / extensions to modify params before sending registration post
        
        require_once('CRM/Utils/Hook.php');
        CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $repeatParams);
        
        // Construct post string from registrationParams array
        
        $post = '';
        foreach ($repeatParams as $key => $value)
            $post .= ($key != 'VPSProtocol' ? '&' : '') . $key . '=' . urlencode($value);
    
        // Send REPEAT registration post
        
        $url      = $this->_paymentProcessor['url_recur'];
        $response = $this->requestPost($url, $post);
        
        $this->log('Sending REPEAT registration post. Params = ' . print_r($repeatParams, true), 4);
        $this->log('REPEAT post response. Response = ' . print_r($response, true), 4); 
        
        // Use pre-existing functionality in the payment notification class to complete financial transaction records,
        // contributions, contribution_recur records etc
        require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'ipn.php';
        $ipn = new uk_co_circleinteractive_payment_sagepay_notify($this);
        return $ipn->processRepeatTransaction($params, $response);
                
    }
    
    // Initialize transaction
    public function doTransferCheckout(&$params, $component = 'contribute') {
       
        $config = &CRM_Core_Config::singleton();
        if ($component != 'contribute' && $component != 'event')
            CRM_Core_Error::fatal(ts('Component is invalid'));
     
        if (!isset($params['TxType']))
            $params['TxType'] = 'PAYMENT';
        
        // Construct notification url querystring params
        // SP will reject notification urls over 255 chars, so param keys are kept
        // brief to avoid this
        $notifyParams = array(
            'processor_name' => 'Sagepay',
            'cid'            => $params['contactID'],
            'conid'          => $params['contributionID'],
            'mo'             => $component,
            'v'              => $this->_paymentProcessor['user_name'],
            'qf'             => $params['qfKey']
        );

        if (@$params['is_recur'])
            $notifyParams['crid'] = $params['contributionRecurID'];
            
        // If Event, add notification params for event id and participant id
        if ($component == 'event') {
            
            $notifyParams += array(
                'eid' => $params['eventID'],
                'pid' => $params['participantID']
            );
        
        // If Contribution ..
        } else {
            
            // Add membership id where applicable
            if ($membershipID = CRM_Utils_Array::value('membershipID', $params)) 
                $notifyParams['mid'] = $membershipID;
            
            // Related contact stuff, if applicable
            if ($relatedContactID = CRM_Utils_Array::value('related_contact', $params)) {
                $notifyParams['rcid'] = $relatedContactID;
                if ($onBehalfDupeAlert = CRM_Utils_Array::value('onbehalf_dupe_alert', $params))
                    $notifyParams['obda'] = $onBehalfDupeAlert;
            }
            
        }
        
        // Construct notification url
        $querystring = array();
        foreach ($notifyParams as $key => $value)
            $querystring[] = $key . '=' . urlencode($value);
        
        $notifyURL = CRM_Utils_System::url('civicrm/payment/ipn', implode('&', $querystring), true, null, false, true, false);
            
        $cid = isset($relatedContactID) ? $relatedContactID : $params['contactID'];
        
        // Query contact record via Civi api
        if (self::getCRMVersion() >= 3.4) {
            
            // Use api v3 where possible ..
            require_once 'api/api.php';
            $contact = civicrm_api("Contact", "get",
                array(
                    'version'    => '3',
                    'contact_id' => $cid
                )
            );
            !$contact['is_error'] ? $contact = reset(@$contact['values']) : $is_error = true;
            if (isset($is_error) and $is_error) {
                $this->error('Contact get (api v3) failed', __CLASS__ . '::' . __METHOD__, __LINE__);
                CRM_Core_Error::fatal('Error retrieving contact record (api v3) in ' . __CLASS__ . '::' . __METHOD__);
            }
            
        } else {
            
            // Revert to v2 if not ..
            require_once 'api/v2/Contact.php';
            $contact = civicrm_contact_get(
                $ref = array(
                    'contact_id' => $cid
                )
            );
            if (!isset($contact[$cid])) {
                $this->error('Contact get (api v2) failed', __CLASS__ . '::' . __METHOD__, __LINE__);
                CRM_Core_Error::fatal('Error retrieving contact record (api v2) in ' . __CLASS__ . '::' . __METHOD__);
            }
            $contact = $contact[$cid];
        
        }
        
        // Query ISO Country code for this country_id ..
        
        if ($contact['country_id'])
            $country_iso_code = CRM_Core_PseudoConstant::countryIsoCode($contact['country_id']);
                
        // Construct params list to send to Sagepay ..
        
        $registrationParams = array(
                            
            'Vendor'             => $this->_paymentProcessor['user_name'],
            'VPSProtocol'        => '2.23',
            'TxType'             => $params['TxType'],
            'VendorTxCode'       => $params['invoiceID'],
            'Amount'             => sprintf("%.2f", $params['amount']),
            'Currency'           => $params['currencyID'],
            'Description'        => substr($params['item_name'], 0, 100),
            'NotificationURL'    => $notifyURL,
            'FailureURL'         => $notifyURL,
            'BillingFirstnames'  => $contact['first_name'],
            'BillingSurname'     => $contact['last_name'],
            'BillingAddress1'    => $contact['street_address'],
            'BillingCity'        => $contact['city'],
            'BillingPostCode'    => $contact['postal_code'],
            'BillingCountry'     => $country_iso_code,
            'DeliveryFirstnames' => $contact['first_name'],
            'DeliverySurname'    => $contact['last_name'],
            'DeliveryAddress1'   => $contact['street_address'],
            'DeliveryCity'       => $contact['city'],
            'DeliveryPostcode'   => $contact['postal_code'],
            'DeliveryCountry'    => $country_iso_code,
            'CustomerEMail'      => $contact['email'],
            'Basket'             => '',
            'AllowGiftAid'       => 0,
            'Apply3DSecure'      => 0,
            'ApplyAVSCV2'        => '',
            'Profile'            => 'NORMAL'
            
        );
        
        // Require additional state params where country is US
        
        if ($country_iso_code == 'US')
            $registrationParams['DeliveryState'] = 
            $registrationParams['BillingState']  = 
            $contact['state_province'];
                
        // Allow other modules / extensions to modify params before sending registration post
        
        require_once('CRM/Utils/Hook.php');
        CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $registrationParams);
        
        // Construct post string from registrationParams array
        
        $post = '';
        foreach ($registrationParams as $key => $value)
            $post .= ($key != 'Vendor' ? '&' : '') . $key . '=' . urlencode($value);
    
        // Send payment registration post
        
        $url      = $this->_paymentProcessor['url_site'];
        $response = $this->requestPost($url, $post);
        
        $this->log('Sending registration post. Params = ' . print_r($registrationParams, true), 4);
        $this->log('Registration post response. Response = ' . print_r($response, true), 4); 
        
        // If ok ...
        
        if ($response['Status'] == 'OK') {
            
            // Make a note of security key (will be compared during notification callback)
            $this->api('create', 'key', array(
                'entity_id' => $params['contributionID'], 
                'data'      => $response['SecurityKey']
            ));
            
            // Redirect user to Sagepay
            CRM_Utils_System::redirect($response["NextURL"]);
        
        } else {
            
            // If we got to here, things have apparently not gone according to plan ...
            
            // Construct an error message 
            $errmsg = '';
            
            if (empty($registrationParams['Amount']))
                $errmsg .= "Amount field was empty.<br />";
            if (empty($registrationParams['BillingFirstnames']) or empty($registrationParams['BillingSurname']))
                $errmsg .= "Missing name field(s).<br />";
            if (empty($registrationParams['BillingAddress1']) or empty($registrationParams['BillingCity']))
                $errmsg .= "Missing address field(s).<br />";
            if (empty($registrationParams['BillingPostCode']))
                $errmsg .= "Missing postcode field.<br />";
            if (empty($registrationParams['BillingCountry']))
                $errmsg .= "Missing country field.<br />";
            if (!$errmsg)
                $errmsg .= "{$response['StatusDetail']}";
            if ($errmsg)
                $errmsg = "The following errors occurred when submitting payment to Sage Pay:<br />" .
                          $errmsg . "<br />Please contact the site administrator.";
                          
            // Redirect user back to where they started and display error(s)
            CRM_Core_Error::fatal($errmsg);
                
        }
        
    }
        
    public function enable() {   
        // .. code to run when extension enabled ..
    }
    
    public function error($message, $function=null, $lineno=null) {
        
        // If logging level = 0, don't log errors
        if (!$this->logging_level)
            return;
            
        if ($function)
            $message .= ' in ' . $function;
        if ($lineno)
            $message .= ' at line ' . $lineno;
        
        $this->api('insert', 'log message', array(
            'entity_type' => 'error',
            'data'        => $message
        ));
        
        // Also post to Drupal db log, if available ...
        if (function_exists('watchdog'))
            watchdog('civicrm_sagepay', $message, array(), WATCHDOG_ERROR);
        
    }
    
    // New callback function for cron calls as of Civi 4.2
    public function handlePaymentCron() {

        require_once 'CRM/Contribute/PseudoConstant.php';
        $contribution_status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());
        $ppIDs = self::getIDs();

        // Retrieve all recurring payments for Sagepay that are 'In progress' and have a payment due
        $recur = CRM_Core_DAO::executeQuery("
           SELECT * FROM civicrm_contribution_recur
            WHERE next_sched_contribution < NOW()
              AND contribution_status_id = 5
              AND payment_processor_id IN (%1, %2)
        ", array(
              1 => array($ppIDs[0], 'Integer'),
              2 => array($ppIDs[1], 'Integer')
           )
        );
       
        // For each of those ..
        while ($recur->fetch()) {

            require_once 'api/api.php';
            
            $existing = civicrm_api("Contribution", "get",
                array(
                    'version'               => '3',
                    'contact_id'            => $recur->contact_id,
                    'contribution_recur_id' => $recur->id
                )
            );
            if (!($existing['is_error'] ? false : $existing = reset(@$existing['values']))) {
                $this->error('Contribution get failed: ' . $existing['error_message'], __CLASS__ . '::' . __METHOD__, __LINE__);
                continue;
            }
            
            $first_contribution_id = $existing['contribution_id'];

            // Use the first installment contribution as the basis for the new one, 
            // but unset the ids first, so a new record is created
            
            unset($existing['id'], $existing['contribution_id']);
            
            // unset any empty keys
            foreach (array_keys($existing) as $key)
                if (empty($existing[$key]))
                    unset($existing[$key]);

            // Create new invoice_id for new contribution
            $existing['invoice_id']   = $existing['trxn_id']      = md5(uniqid(rand(), true));
            $existing['receive_date'] = $existing['receipt_date'] = date('YmdHis');
            
            $contribution = civicrm_api("Contribution", "create", 
                array_merge($existing, array(
                    'version'                => '3',
                    'contribution_status_id' => $contribution_status_id['Pending']
                ))
            );
            if (!($contribution['is_error'] ? false : $contribution = reset(@$contribution['values']))) {
                $this->error('Contribution create failed: ' . $contribution['error_message'], __CLASS__ . '::' . __METHOD__, __LINE__);
                continue;
            }
                
            // Get membership_id if contribution is for a membership
            $membership_id = CRM_Core_DAO::singleValueQuery("
                SELECT membership_id FROM civicrm_membership_payment WHERE contribution_id = %1
            ",  array(
                   1 => array($first_contribution_id, 'Integer') 
                )
            );
            
            // Get Related Tx params saved when the first contribution was created
            $relatedTxParams = $this->api('get', 'recurring',
                array(
                    'entity_id' => $recur->id
                )
            );
            if (isset($relatedTxParams['error'])) {
                $this->error(
                    'Error retrieving relatedTxParams for contribution_recur_id ' . $recur->id,
                    __CLASS__ . '::' . __METHOD__, __LINE__
                );
                continue;
            }
            
            // Source can be returned as 'source' or 'contribution_source' it would seem .. (?)
            if (!isset($contribution['source']))
                $contribution['source'] = isset($contribution['contribution_source']) ? 
                    $contribution['contribution_source'] : 'Recurring contribution';

            // Run doRepeatCheckout to perform REPEAT registration post
            $this->doRepeatCheckout(
                $ref = ((array)$recur) + $contribution + $relatedTxParams + 
                // Change standard ids to the 'special' ones used by PP interface.
                array(
                    'TxType'         => 'REPEAT', 
                    'contributionID' => $contribution['id'],
                    'contactID'      => $contribution['contact_id'],
                    'invoiceID'      => $contribution['invoice_id'],
                    'currencyID'     => $contribution['currency'],
                    'membershipID'   => $membership_id,
                    'recurID'        => $recur->id,
                    'item_name'      => $contribution['source'] . 
                        (!empty($contribution['amount_level']) ? ' - ' . $contribution['amount_level'] : '')
                )
            );

        }
        
    }
 
    // New callback function for payment notifications as of Civi 4.2
    public function handlePaymentNotification() {

        require_once 'CRM/Utils/Array.php';
        $module = CRM_Utils_Array::value('mo', $_GET);

        $this->log('Payment notification received. Request = ' . print_r($_REQUEST, true), 4);

        require_once dirname(__FILE__) . '/ipn.php';
        $ipn = new uk_co_circleinteractive_payment_sagepay_notify($this);

        // Attempt to determine component type ...
        switch ($module) {
            case 'contribute':
            case 'event':
                $ipn->main($module);
                break;
            default:
                require_once 'CRM/Core/Error.php';
                CRM_Core_Error::debug_log_message("Could not get module name from request url");
                echo "Could not get module name from request url\r\n";
        }
        
        $output = ob_get_clean();
        $this->log('Sent notification response ' . $output, 4);
        echo $output;
   
    }
    
    public function install() {
         
         // On install, create a table for keeping track of security keys
         require_once "CRM/Core/DAO.php";
         CRM_Core_DAO::executeQuery("
            CREATE TABLE IF NOT EXISTS `civicrm_sagepay` (
              `id` int(10) unsigned NOT NULL auto_increment,
              `created` datetime NOT NULL,
              `data_type` varchar(16) NOT NULL,
              `entity_type` varchar(32) NOT NULL,
              `entity_id` int(10) unsigned NOT NULL,
              `data` longtext NOT NULL,
              PRIMARY KEY  (`id`),
              KEY `entity_id` (`entity_id`),
              KEY `data_type` (`data_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;
         ");
                 
        // Create entry in civicrm_job table for cron call
        if (CRM_Core_DAO::checkTableExists('civicrm_job')) {
            if (self::getCRMVersion() < 4.3) {
                CRM_Core_DAO::executeQuery("
                    INSERT INTO civicrm_job (
                       id, domain_id, run_frequency, last_run, name, description, 
                       api_prefix, api_entity, api_action, parameters, is_active
                    ) VALUES (
                       NULL, %1, 'Daily', NULL, 'Process Sagepay Recurring Payments', 
                       'Processes any Sagepay recurring payments that are due',
                       'civicrm_api3', 'job', 'run_payment_cron', 'processor_name=Sagepay', 0
                    )
                    ", array(
                        1 => array(CIVICRM_DOMAIN_ID, 'Integer')
                    )
                );
            } else {

                $result = civicrm_api('job', 'create', array(
                    'version'       => 3,
                    'name'          => ts('Process Sagepay Recurring Payments'),
                    'description'   => ts('Processes any Sagepay recurring payments that are due'),
                    'run_frequency' => 'Daily',
                    'api_entity'    => 'job',
                    'api_action'    => 'run_payment_cron',
                    'parameters'    => 'processor_name=Sagepay',
                    'is_active'     => 0
                ));
            
            }

        }
    }
    
    public function log($message, $level=4) {
        if ($this->logging_level >= $level) {
            $this->api('insert', 'log message',
                array(
                    'entity_type' => 'message',
                    'data'        => $message
                )
            );
            if (function_exists('watchdog')) 
                watchdog('civicrm_sagepay', $message, array(), WATCHDOG_INFO);
        }
    }
            
    public function uninstall() {
    
        // On uninstall, remove sagepay data table
        require_once "CRM/Core/DAO.php";
        
        CRM_Core_DAO::executeQuery("DROP TABLE civicrm_sagepay");
        
        // Also, remove the entry we created in civicrm_job
        if (CRM_Core_DAO::checkTableExists('civicrm_job'))
            CRM_Core_DAO::executeQuery("
                DELETE FROM civicrm_job 
                      WHERE api_entity = 'job'
                        AND api_action = 'run_payment_cron'
            ");
    
    }
    
    public function updateFailureCount($contribution_recur_id) {
        
        // Increment failure count on contribution_recur record
        require_once 'CRM/Core/DAO.php';
        CRM_Core_DAO::executeQuery(
            "UPDATE civicrm_contribution_recur SET failure_count = failure_count + 1 WHERE id = %1",
            array(
                1 => array($contribution_recur_id, 'Integer')
            )
        );
        
        // Check failure count
        if (CRM_Core_DAO::singleValueQuery(
            "SELECT failure_count FROM civicrm_contribution_recur WHERE id = %1",
            array(
                1 => array($contribution_recur_id, 'Integer')
            )
        ) >= SAGEPAY_CANCEL_RECURRING_ON_N_FAILURES) {
            
            // If max failures reached, mark contribution_recur as failed. Do not attempt any further payments.
            require_once 'CRM/Contribute/PseudoConstant.php';
            $contribution_status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());
            
            CRM_Core_DAO::executeQuery(
                "UPDATE civicrm_contribution_recur SET cancel_date=NOW(), contribution_status_id = %1 WHERE id = %2",
                array(
                    1 => array($contribution_status_id['Failed'], 'Integer'),
                    2 => array($contribution_recur_id, 'Integer')
                )
            );
        }  
    }
    
    // Get Civi version as float
    public function getCRMVersion() {
        $crmversion = explode('.', ereg_replace('[^0-9\.]','', CRM_Utils_System::version()));
        return floatval($crmversion[0] . '.' . $crmversion[1]);
    }
    
    protected function getIDs() {
        $ids = array();
        $dao = CRM_Core_DAO::executeQuery("
            SELECT id FROM civicrm_payment_processor 
             WHERE class_name = 'uk.co.circleinteractive.payment.sagepay'
        ");
        while ($dao->fetch())
            $ids[] = $dao->id;
        return $ids;
    }
        
    // Send POST request using cURL 
    protected function requestPost($url, $data){
        
        if (!function_exists('curl_init'))
            CRM_Core_Error::fatal(ts('CiviCRM Sagepay extension requires the component \'php5-curl\'.'));
        
        set_time_limit(60);
        
        $output  = array();
        $session = curl_init();
        
        // Set curl options
        curl_setopt_array($session, array(
            CURLOPT_URL            => $url,
            CURLOPT_HEADER         => 0,
            CURLOPT_POST           => 1,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2
        ));
        
        // Send request and split response into name/value pairs
        $response = split(chr(10), curl_exec($session));
        
        // Check that a connection was made
        if (curl_error($session)){
            // If it wasn't...
            $output['Status'] = "FAIL";
            $output['StatusDetail'] = curl_error($session);
        }
    
        curl_close($session);
    
        // Tokenise the response
        for ($i=0; $i<count($response); $i++){
            $splitAt = strpos($response[$i], "=");
            $output[trim(substr($response[$i], 0, $splitAt))] = trim(substr($response[$i], ($splitAt+1)));
        }
        return $output;
    }
        
};

