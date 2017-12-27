<?php

/**
 * Sagepay Extension for CiviCRM - Circle Interactive 2010-16
 * @author andyw@circle
 * RM: Customisations have been annotated using a custom PHPDoc tag @custom 
 */

class CRM_Core_Payment_Sagepay extends CRM_Core_Payment {

    public $logging_level      = 0;
    protected $_mode           = null;
    static private $_singleton = null;

    /**
     * Constructor
     */
    public function __construct($mode, &$paymentProcessor) {

        $this->_mode             = $mode;
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName    = ts('Sagepay');
        $this->logging_level     = CIVICRM_SAGEPAY_LOGGING;

    }

    /**
     * Return singleton instance of class
     */
    public static function &singleton($mode = 'test', &$paymentProcessor, &$paymentForm = null, $force = false) {

        $processorName = $paymentProcessor['name'];
        if (is_null(self::$_singleton[$processorName]))
            self::$_singleton[$processorName] = new self($mode, $paymentProcessor);
        return self::$_singleton[$processorName];

    }

    /**
     * Add params to the notification URL, which are relevant to webforms. These params would be retrieved in
     * CRM_Core_Payment_Sagepay_IPN when redirecting.
     * @author RM
     * @custom
     * @param $registrationParams
     */
    protected function addReturnParams(&$registrationParams) {
        // This is only true for Webforms, and not for Civi's native forms
        if (!empty($registrationParams['return'])) {
            $parsedUrl = drupal_parse_url($registrationParams['return']);

            $matches = [];
            preg_match('/node\/([0-9]+)\/done/', $parsedUrl['path'], $matches);

            $params = [];
            if (isset($matches[1])) {
                $params[] = "node={$matches[1]}";
            }
            if (isset($parsedUrl['query'])) {
                if (isset($parsedUrl['query']['sid'])) {
                    $params[] = "sid={$parsedUrl['query']['sid']}";
                }
                if (isset($parsedUrl['query']['token'])) {
                    $params[] = "token={$parsedUrl['query']['token']}";
                }
            }

            if ($params) {
                $registrationParams['NotificationURL'] .= '&' . implode('&', $params);
            }
        }
    }

    public function checkConfig() {

        $error = [];

        if (!$this->_paymentProcessor['user_name'])
            $errors[] = 'No username supplied for Sagepay payment processor';

        if (!empty($errors))
            return '<p>' . implode('</p><p>', $errors) . '</p>';

        return null;

    }

    /**
     * doDirectPayment - not req'd for billing mode 'notify'
     */
    public function doDirectPayment(&$params) {
        return null;
    }

    /**
     * Initialize REPEAT transaction
     */
    public function doRepeatCheckout(&$params) {
        
        $repeatParams = array(
            'VPSProtocol'         => '3.00',
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
        CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $repeatParams);

        // Send REPEAT registration post
        $response = $this->requestPost($this->_paymentProcessor['url_recur'], http_build_query($repeatParams));

        sagepay_log('Sending REPEAT registration post. Params = ' . print_r($repeatParams, true));
        sagepay_log('REPEAT post response. Response = ' . print_r($response, true));

        // Use pre-existing functionality in the payment notification class to complete financial transaction records,
        // contributions, contribution_recur records etc
        $ipn = new CRM_Core_Payment_Sagepay_IPN($this);
        return $ipn->processRepeatTransaction($params, $response);

    }

    // Initialize transaction
    public function doTransferCheckout(&$params, $component = 'contribute') {

        if ($component != 'contribute' && $component != 'event')
            CRM_Core_Error::fatal(ts('Component is invalid'));

        if (!isset($params['TxType']))
            $params['TxType'] = 'PAYMENT';

        // Construct notification url querystring params
        // SP will reject notification urls over 255 chars, so param keys are kept
        // brief to avoid this
        $ppIDs = self::getIDs();
        $notifyParams = [
            'processor_id'   => $this->_mode == 'test' ? $ppIDs['test'] : $ppIDs['live'],
            'cid'            => $params['contactID'],
            'conid'          => $params['contributionID'],
            'mo'             => $component,
            'v'              => $this->_paymentProcessor['user_name'],
            'qf'             => $params['qfKey']
        ];

        if (CRM_Utils_Array::value('is_recur', $params))
            $notifyParams['crid'] = $params['contributionRecurID'];

        // If Event, add notification params for event id and participant id
        if ($component == 'event') {

            $notifyParams += [
                'eid' => $params['eventID'],
                'pid' => $params['participantID']
            ];

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

        $notifyURL = CRM_Utils_System::url('civicrm/payment/ipn', http_build_query($notifyParams), true, null, false, true, false);

        // Retrieve contact info
        $cid = isset($relatedContactID) ? $relatedContactID : $params['contactID'];
        $contact = civicrm_api3("Contact", "getsingle", [
            'version'    => '3',
            'contact_id' => $cid
        ]);        	

        // Use billing address when configured to via setting
        if ($use_billing = CRM_Core_BAO_Setting::getItem('uk.co.circleinteractive.payment.sagepay', 'use_billing')) {
            $location_type_id = CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_location_type WHERE name = 'Billing'");
            try {
                $address = civicrm_api3('address', 'get', [
                    'contact_id'       => $cid,
                    'location_type_id' => $location_type_id
                ]);
                if ($address['values'])
                    $address = reset($address['values']);
            } catch (CiviCRM_API3_Exception $e) {
              CRM_Core_Error::statusBounce('Unable to get billing address for the current contact: ' . $e->getMessage(), NULL, 'Sagepay');
            }
        }

        // Query ISO Country code for this country_id ..
        if ($contact['country_id'])
            $country_iso_code = CRM_Core_PseudoConstant::countryIsoCode($contact['country_id']);

        // Construct params list to send to Sagepay ..

        $registrationParams = [

            'Vendor'             => $this->_paymentProcessor['user_name'],
            'VPSProtocol'        => '3.00',
            'TxType'             => $params['TxType'],
            'VendorTxCode'       => $params['invoiceID'],
            'Amount'             => sprintf("%.2f", $params['amount']),
            'Currency'           => $params['currencyID'],
            'Description'        => 'Payment from CiviCRM',
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

        ];

        if (isset($params['item_name'])) {
            $registrationParams['Description'] = substr($params['item_name'], 0, 100);
        }
        elseif (isset($params['description'])) {
            $registrationParams['Description'] = substr($params['description'], 0, 100);
        }

        // Require additional state params where country is US
        if ($country_iso_code == 'US')
            $registrationParams['DeliveryState'] =
            $registrationParams['BillingState']  =
            $contact['state_province'];

        /**
         * @custom Hack to make sure the hook 'webform_civicrm_civicrm_alterPaymentProcessorParams'
         * updates the values of 'return' and 'cancel_return' items in the $registrationParams. This hack only
         * comes into action when the user is submitting a webform, as opposed to civi's native page.
         */
        if (!empty($params['webform_redirect_success']) && !empty($params['webform_redirect_cancel'])) {
            $registrationParams['return'] = $registrationParams['cancel_return'] = 'nothing';
        }

        // Allow other modules / extensions to modify params before sending registration post
        CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $registrationParams);

        /** @custom */
        $this->addReturnParams($registrationParams);
        // end custom

        // Send payment registration post
        sagepay_log('Sending registration post. Params = ' . print_r($registrationParams, true));
        $response = $this->requestPost($this->_paymentProcessor['url_site'], http_build_query($registrationParams));
        sagepay_log('Registration post response. Response = ' . print_r($response, true));

        // If ok ...
        if ($response['Status'] == 'OK') {

            // Make a note of security key (will be compared during notification callback)
            $token = new CRM_Core_Payment_Sagepay_Token();
            $token->create([
            	'data_type' => 'key',
                'entity_id' => $params['contributionID'],
                'data'      => $response['SecurityKey']
            ]);
            // Redirect user to Sagepay
            CRM_Utils_System::redirect($response["NextURL"]);

        } else {

            // Status: not OK
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

            // Display error(s)
            CRM_Core_Error::statusBounce($errmsg, NULL, 'Sagepay');

        }

    }

    /**
     * Get an array of payment processor ids for processor
     */
    public static function getIDs() {

        try {

            $result = civicrm_api3('PaymentProcessor', 'get', [
                'class_name'     => 'Payment_Sagepay',
                'return.id'      => 1,
                'return.is_test' => 1
            ]);

        } catch (CiviCRM_API3_Exception $e) {
            CRM_Core_Error::fatal(ts("Unable to get instance ids for payment processor 'Sagepay': %1", [
                1 => $e->getMessage()
            ]));
        }

        $processors = [];

        foreach ($result['values'] as $processor)
            $processors[$processor['is_test'] ? 'test' : 'live'] = $processor['id'];

        return $processors;

    }

    /**
     * Callback function for running any due recurring payments
     */
    public function handlePaymentCron() {

        $contribution_status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());

        // Retrieve all recurring payments for Sagepay that are 'In progress' and have a payment due
        $recur = CRM_Core_DAO::executeQuery("
           SELECT * FROM civicrm_contribution_recur
            WHERE next_sched_contribution_date < NOW()
              AND contribution_status_id = 5
              AND payment_processor_id = %1
        ",  [
                1 => [$this->_paymentProcessor['id'], 'Positive']
            ]
        );

        // For each of those ..
        while ($recur->fetch()) {
            
            try {
                $existing = civicrm_api3("Contribution", "get", [
                    'version'               => '3',
                    'contact_id'            => $recur->contact_id,
                    'contribution_recur_id' => $recur->id
                ]);
            } catch (CiviCRM_API3_Exception $e) {
                sagepay_log('Contribution get failed: ' . $e->getMessage(), __CLASS__ . '::' . __METHOD__, __LINE__);
                continue;
            }

            if (!isset($existing['values'])) {
                sagepay_log('Unable to find any existing contributions for contribution_recur_id ' . $recur->id);
                continue;
            }

            $existing = reset($existing['values']);

            if (empty($existing)) {
                sagepay_log('Unable to find any existing contributions for contribution_recur_id ' . $recur->id);
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
            $existing['contribution_status_id'] = $contribution_status_id['Pending'];

            $contribution = $existing;

            try {
                civicrm_api3("Contribution", "create", $contribution);
            } catch (CiviCRM_API3_Exception $e) {
                sagepay_log('Contribution create failed: ' . $e->getMessage() . ' in ' . __CLASS__ . '::' . __METHOD__ . ' at line ' . __LINE__);
                continue;
            }

            // Get membership_id if contribution is for a membership
            $membership_id = CRM_Core_DAO::singleValueQuery("
                SELECT membership_id FROM civicrm_membership_payment WHERE contribution_id = %1
            ",  [
                    1 => [$first_contribution_id, 'Integer']
                ]
            );

            // Get Related Tx params saved when the first contribution was created
            $token = new CRM_Core_Payment_Sagepay_Token();
            $relatedTxParams = $token->get([
                'data_type' => 'recurring',
                'entity_id' => $recur->id
            ]);

            if (!$relatedTxParams) {
                sagepay_log(
                    'Error retrieving relatedTxParams for contribution_recur_id ' . $recur->id . ' in ' .
                    __CLASS__ . '::' . __METHOD__ . ' at line ' .  __LINE__
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
                    // Change standard ids to the 'special' ones used by Civi's payment interface.
                    [
                        'TxType'         => 'REPEAT',
                        'contributionID' => $contribution['id'],
                        'contactID'      => $contribution['contact_id'],
                        'invoiceID'      => $contribution['invoice_id'],
                        'currencyID'     => $contribution['currency'],
                        'membershipID'   => $membership_id,
                        'recurID'        => $recur->id,
                        'item_name'      => $contribution['source'] .
                            (!empty($contribution['amount_level']) ? ' - ' . $contribution['amount_level'] : '')
                    ]

            );

        }

    }

    /**
     * Callback function for payment notifications
     */
    public function handlePaymentNotification() {

    	ob_start();

        sagepay_log('Payment notification received. Request = ' . print_r($_REQUEST, true));

        $module = CRM_Utils_Array::value('mo', $_GET);
        $ipn    = new CRM_Core_Payment_Sagepay_IPN($this);

        // Attempt to determine component type ...
        switch ($module) {
            case 'contribute':
            case 'event':
                $ipn->main($module);
                break;
            default:
                sagepay_log("Could not get module name from request url");
                echo "Could not get module name from request url\r\n";
        }

        $output = ob_get_clean();
        sagepay_log('Sent notification response: ' . $output);
        echo $output;

    }

    /**
     * Send POST request using cURL 
     * @param string $url   url to send data to
     * @param string $data  urlencoded key/value pairs to send
     */
    protected function requestPost($url, $data){

        if (!function_exists('curl_init'))
            CRM_Core_Error::fatal(ts('CiviCRM Sagepay extension requires the component \'php5-curl\'.'));

        set_time_limit(60);

        $output  = [];
        $session = curl_init();

        // Set curl options
        curl_setopt_array($session, [
            CURLOPT_URL            => $url,
            CURLOPT_HEADER         => 0,
            CURLOPT_POST           => 1,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1
        ]);

        // Send request and split response into name/value pairs
        $response = explode(chr(10), curl_exec($session));

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

    public function updateFailureCount($contribution_recur_id) {

        // Increment failure count on contribution_recur record
        CRM_Core_DAO::executeQuery("
        	UPDATE civicrm_contribution_recur SET failure_count = failure_count + 1 WHERE id = %1
        ", [
            1 => [$contribution_recur_id, 'Integer']
        ]);

        // Check failure count
        if (CRM_Core_DAO::singleValueQuery("
        	SELECT failure_count FROM civicrm_contribution_recur WHERE id = %1
        ", [
            1 => [$contribution_recur_id, 'Integer']
        ]) >= CIVICRM_SAGEPAY_RECURRING_MAX_FAILURES) {

            // If max failures reached, mark contribution_recur as failed. Do not attempt any further payments.
            $contribution_status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());

            CRM_Core_DAO::executeQuery("
            	UPDATE civicrm_contribution_recur SET cancel_date=NOW(), contribution_status_id = %1 WHERE id = %2
            ", [
                1 => [$contribution_status_id['Failed'], 'Integer'],
                2 => [$contribution_recur_id, 'Integer']
            ]);
        
        }
    
    }

}