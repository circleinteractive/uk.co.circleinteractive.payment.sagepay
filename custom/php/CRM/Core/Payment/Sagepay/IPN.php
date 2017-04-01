<?php

/**
 * Sagepay Extension for CiviCRM - Circle Interactive 2010-16
 * Callback Notification Class
 * @author andyw@circle
 *
 * RM: Customisations have been annotated using a custom PHPDoc tag @custom
 */

class CRM_Core_Payment_Sagepay_IPN extends CRM_Core_Payment_BaseIPN {
	
	protected $sagepay;
    static    $_paymentProcessor = null;
    
    function __construct($sagepay) {
        $this->sagepay = $sagepay;
        parent::__construct();
    }
    
    function main($component = 'contribute') {
        
        $sagepay = &$this->sagepay;
        $token   = new CRM_Core_Payment_Sagepay_Token();
        
        $objects = $ids 
                 = $input 
                 = [];
                 
        $this->component = $input['component'] 
                         = $component;

        // Get contribution and contact ids from querystring
        $ids['contact']          = self::retrieve('cid', 'Integer', 'GET', true);
        $ids['contribution']     = self::retrieve('conid', 'Integer', 'GET', true);
        $ids['vendor']           = strtolower(self::retrieve('v', 'String',  'GET', true));
        $ids['paymentProcessor'] = self::retrieve('processor_id', 'Integer', 'GET', true);

        // req'd for cancel url 
        define('SAGEPAY_QFKEY', self::retrieve('qf', 'String', 'GET', false));
            
        // Get post data
        $this->getInput($input, $ids);
        
        // Rebuild vps signature using the security key stored during the registration phase.
        // Check Sagepay integration guide for more info on this procedure.
        $security_key = $token->get([
        	'data_type' => 'key',
            'entity_id' => $ids['contribution']
        ]);

        // Bail out if there was an error retrieving the security key
        if (is_array($security_key) and isset($security_key['error'])) {
            echo "Status=ERROR\r\n" . 
                 "StatusDetail=Unable to retrieve security key - {$security_key['error']}\r\n" . 
                 "RedirectURL=$cancelURL\r\n";
            return;
        }
        
        $input['security_key'] = $security_key;
        
        $signature = strtoupper(md5(implode('', $test = [
            $input['trxn_id'],        $input['invoice'],       $input['paymentStatus'], $input['TxAuthNo'],
            $ids['vendor'],           $input['AVSCV2'],        $security_key,           $input['AddressResult'],
            $input['PostCodeResult'], $input['CV2Result'],     $input['GiftAid'],       $input['3DSecureStatus'],
            $input['CAVV'],           $input['AddressStatus'], $input['PayerStatus'],   $input['CardType'],
            $input['Last4Digits'],    $input['DeclineCode'],   $input['ExpiryDate'],    $input['FraudResponse'],
            $input['BankAuthCode']
        ])));
                
        // Compare our locally constructed signature to the VPS signature returned by Sagepay
        if ($signature !== $input['VPSSignature']) {

            CRM_Core_Error::debug_log_message('Invalid VPS Signature: ' . print_r($input, true));
            
            // Not matched, send INVALID response and return 
            $url         = ($component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact';
            $cancel      = ($component == 'event') ? '_qf_Register_display'   : '_qf_Main_display';
            $redirectURL = CRM_Utils_System::url($url, "$cancel=1&cancel=1&qfKey=" . SAGEPAY_QFKEY, true, null, false, true); 
            
            echo "Status=INVALID\r\n" . 
                 "StatusDetail=Unable to match VPS signature.\r\n" . 
                 "RedirectURL=$redirectURL\r\n";
            
            sagepay_log('Transaction failed: Invalid VPS Signature');

            return;
        }
        
        // VPS Signature check OK ...
        
        if ($component == 'event') {
            
            $ids['event']       = self::retrieve('eid', 'Integer', 'GET', true);
            $ids['participant'] = self::retrieve('pid', 'Integer', 'GET', true);
        
        } else {
            
            // Get optional ids
            $ids['membership']          = self::retrieve('mid',  'Integer', 'GET', false);
            $ids['contributionRecur']   = self::retrieve('crid', 'Integer', 'GET', false);
            $ids['contributionPage']    = self::retrieve('cpid', 'Integer', 'GET', false);
            $ids['related_contact']     = self::retrieve('rcid', 'Integer', 'GET', false);
            $ids['onbehalf_dupe_alert'] = self::retrieve('obda', 'Integer', 'GET', false);
        
        }

        CRM_Core_Error::debug_log_message('input: ' . print_r($input, true));
        CRM_Core_Error::debug_log_message('ids: ' . print_r($ids, true));

        if (!$this->validateData($input, $ids, $objects)) {
            sagepay_log('Transaction failed: Unable to validate data', __CLASS__ . '::' . __METHOD__, __LINE__);
            return false;
        }

        self::$_paymentProcessor = &$objects['paymentProcessor'];
        
        // Contribution ...
        if ($component == 'contribute') {
            
            // Recurring ...
            if (isset($ids['contributionRecur']) and !empty($ids['contributionRecur'])) {
                
                // check if first contribution is completed, else complete first contribution
                $first = true;
                if ($objects['contribution']->contribution_status_id == 1)
                    $first = false;
                
                return $this->recur($input, $ids, $objects, $first);
            
            // Not recurring ...
            } else {
                sagepay_log('Transaction success: contribution');
                return $this->single($input, $ids, $objects, false, false);
            }
        
        // Event ...
        } else {
            sagepay_log('Transaction success: event');
            return $this->single($input, $ids, $objects, false, false);
        }
        
    }
    
    // There is no IPN notification for a REPEAT transaction. Instead, we get an instant response,
    // then call this function to complete transaction / mark as failed.
    public function processRepeatTransaction(&$params, &$response) {
        
        $sagepay = &$this->sagepay;
        $objects = [];
   
        if ($response['Status'] == 'OK') {
            
            // Spoof sufficient notification params to allow IPN code to complete the transaction ..
            $ids = [
                'contact'           => $params['contactID'],
                'contribution'      => $params['contributionID'],
                'contributionRecur' => $params['recurID'],
                'membership'        => $params['membershipID']
            ];
            
            $input = [
                'component'     => 'contribute',
                'paymentStatus' => $response['Status'],
                'invoice'       => $params['RelatedVendorTxCode'],
                'trxn_id'       => $response['VPSTxId'],
                'amount'        => $params['amount']
            ];
            
            define('SAGEPAY_QFKEY', ''); // Not relevant in this context, but define something to prevent warnings
            
            if (!$this->validateData($input, $ids, $objects)) {
                sagepay_log('Transaction failed: Unable to validate data', __CLASS__ . '::' . __METHOD__, __LINE__);
                return false;
            }

            // Suppress any output which may occur, then run IPN completion code
            ob_start();
            $this->recur($input, $ids, $objects, $first);
            
            // Grab output and log if logging level >= 4
            $output = ob_get_clean();
            sagepay_log("Processed REPEAT transaction:\n" . $output);
            
            return true;
            
        } else {
            
            // Handle != OK responses ..
            
            sagepay_log(sprintf(
                'Payment failure on recurring payment at Sagepay on contribution_id %d using contribution_recur_id %d.\n' .
                'Sagepay system responded: %s',
                $ids['contribution'],
                $ids['contributionRecur'],
                print_r($response, true)
            ));
            
            // Update contribution_recur record failure count
            $sagepay->updateFailureCount($params['recurID']);
            
            // Mark contribution as Failed
            $status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());
            
            try {
	            civicrm_api3("Contribution", "create", [
	                'id'                     => $params['contributionID'],
	                'contribution_status_id' => $status_id['Failed']
	            ]);
	        } catch (CiviCRM_API3_Exception $e) {
	        	sagepay_log("Failed updating contribution status to 'Failed' on transaction: " . $e->getMessage());
	        }          
            
            return false;  
        
        }
    
    }
    
    static function retrieve($name, $type, $location = 'POST', $abort = true) {
        
        static $store = null;
        $value = CRM_Utils_Request::retrieve($name, $type, $store, false, null, $location);
        if ($abort && $value === null) {
            CRM_Core_Error::debug_log_message("Could not find an entry for $name in $location");
            echo "Failure: Missing Parameter";
            exit();
        }
        return $value;
        
    }
    
    protected function getInput(&$input, &$ids) {
        
        if (!@$this->getBillingID($ids))
            return false;
        
        $input['payment_processor_id'] = self::retrieve('processor_id', 'Integer', 'GET', false);

        $input['VPSProtocol']    = self::retrieve('VPSProtocol',    'Money',  'POST', false);
        $input['TxType']         = self::retrieve('TxType',         'String', 'POST', false);
        $input['invoice']        = self::retrieve('VendorTxCode',   'String', 'POST', false);
        $input['trxn_id']        = self::retrieve('VPSTxId',        'String', 'POST', false);
        $input['paymentStatus']  = self::retrieve('Status',         'String', 'POST', false);
        $input['StatusDetail']   = self::retrieve('StatusDetail',   'String', 'POST', false);
        $input['TxAuthNo']       = self::retrieve('TxAuthNo',       'String', 'POST', false);
        $input['AVSCV2']         = self::retrieve('AVSCV2',         'String', 'POST', false);
        $input['AddressResult']  = self::retrieve('AddressResult',  'String', 'POST', false);
        $input['PostCodeResult'] = self::retrieve('PostCodeResult', 'String', 'POST', false);
        $input['CV2Result']      = self::retrieve('CV2Result',      'String', 'POST', false);
        $input['GiftAid']        = self::retrieve('GiftAid',        'String', 'POST', false);
        $input['3DSecureStatus'] = self::retrieve('3DSecureStatus', 'String', 'POST', false);
        $input['CAVV']           = self::retrieve('CAVV',           'String', 'POST', false);
        $input['AddressStatus']  = self::retrieve('AddressStatus',  'String', 'POST', false);
        $input['PayerStatus']    = self::retrieve('PayerStatus',    'String', 'POST', false);
        $input['CardType']       = self::retrieve('CardType',       'String', 'POST', false);
        $input['Last4Digits']    = self::retrieve('Last4Digits',    'String', 'POST', false);
        $input['VPSSignature']   = self::retrieve('VPSSignature',   'String', 'POST', false);

        # added for protocol v3.0 ..
        $input['DeclineCode']    = self::retrieve('DeclineCode',   'String', 'POST', false);
        $input['ExpiryDate']     = self::retrieve('ExpiryDate',    'String', 'POST', false);
        $input['FraudResponse']  = self::retrieve('FraudResponse', 'String', 'POST', false);
        $input['BankAuthCode']   = self::retrieve('BankAuthCode',  'String', 'POST', false);

            
    }
    
    function recur(&$input, &$ids, &$objects, $first) {
        
        require_once 'CRM/Contribute/PseudoConstant.php';
        $contribution_status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());
        
        $recur   = &$objects['contributionRecur'];
        $sagepay = &$this->sagepay;
        $token   = new CRM_Core_Payment_Sagepay_Token();
        
        $sagepay_recur_data = $token->get([
        	'data_type' => 'recurring',
        	'entity_id' => $recur->id
        ]);
        
        // Make sure invoice id is valid and matches the contribution record
        if ($recur->invoice_id != $input['invoice']) {
            sagepay_log("Invoice values don't match between database and Sagepay request");
            echo "Failure: Invoice values don't match between database and Sagepay request\r\n";
            return false;
        }
        
        $now = date('YmdHis');

        // Fix dates that already exist
        foreach (['create', 'start', 'end', 'cancel', 'modified'] as $date) {
            $name = "{$date}_date";
            if (isset($recur->$name) and $recur->$name) 
                $recur->$name = CRM_Utils_Date::isoToMysql($recur->$name);
        }
            
        $first ? $recur->start_date    = 
                 $recur->modified_date = 
                 $now 
               :
                 $recur->modified_date = 
                 $now;

        if ($recur->contribution_status_id != $contribution_status_id['Completed'])
            $recur->contribution_status_id = $contribution_status_id['In Progress'];
                
        if ($first) {
            
            // Create internal recurring record, storing VPSTxId, VendorTxCode etc for REPEAT transactions
            $token->create([
            	'data_type' => 'recurring',
            	'entity_id' => $recur->id,
            	'data' => [                      
            		'RelatedVPSTxId'      => $input['trxn_id'],
                    'RelatedVendorTxCode' => $input['invoice'],
                    'RelatedSecurityKey'  => $input['security_key'],
                    'RelatedTxAuthNo'     => $input['TxAuthNo'],
                    'installments'        => $recur->installments,
                    'current_installment' => 1
                ]
            ]);

            // And set recur transaction id to that of the first contribution
            $recur->trxn_id = $input['trxn_id'];
        
        } else {
            
            // Last contribution complete? Then mark contribution_recur as complete.
            // NB: $recur->installments will be null in the case of an indefinite recurring period - eg: membership auto-renew 
            if ($recur->installments and ++$sagepay_recur_data['current_installment'] >= $recur->installments) {
                
                $recur->contribution_status_id = $contribution_status_id['Completed'];
                $recur->end_date               = $now;
                
                // And delete internal recurring record
                $token->delete([
                	'data_type' => 'recurring',
                    'entity_id' => $recur->id
                ]);
            
            } else {

                // Otherwise, update internal record with new installment count
                $token->update([
                	'data_type' => 'recurring',
                    'entity_id' => $recur->id,
                    'data'      => $sagepay_recur_data
                ]);

            }
        
        }
        
        // If not completed, update next_sched_contribution date
        if ($recur->contribution_status_id != $contribution_status_id['Completed'])      
            $recur->next_sched_contribution = date(
                'YmdHis', 
                strtotime('+' . $recur->frequency_interval . ' ' . $recur->frequency_unit)
            );
        
        // Reset failure count since transaction was successful
        $recur->failure_count = 0;
        
        // Save contribution_recur record
        $recur->save();
                
        // And complete single transaction / contribution record
        return $this->single($input, $ids, $objects, true, $first);
    
    }
    
    function single(&$input, &$ids, &$objects, $recur = false, $first = false) {
        
        $contribution =& $objects['contribution'];
        
        // make sure the invoice is valid and matches what we have in the contribution record
        if (!$recur || ($recur && $first)) {
            if ($contribution->invoice_id != $input['invoice']) {
                CRM_Core_Error::debug_log_message("Invoice values dont match between database and IPN request");
                CRM_Core_Error::debug_log_message("contribution->invoice_id=" . $contribution->invoice_id);
                CRM_Core_Error::debug_log_message("input['invoice']=" . $input['invoice']);
                echo "Failure: Invoice values dont match between database and IPN request<p>";
                return false;
            }
        } else {
            $contribution->invoice_id = md5(uniqid(rand(), true));
        }
        
        $input['amount'] = $contribution->total_amount;
        if (!$recur ) {
            if ($contribution->total_amount != $input['amount']) {
                CRM_Core_Error::debug_log_message("Amount values dont match between database and IPN request");
                echo "Failure: Amount values dont match between database and IPN request<p>";
                return false;
            }
        } else {
            $contribution->total_amount = $input['amount'];
        }
           
        require_once 'CRM/Core/Transaction.php';
        $transaction = new CRM_Core_Transaction();
        
        $participant = &$objects['participant'];
        $membership  = &$objects['membership'];
        $status      = $input['paymentStatus'];
        
        $url         = ($this->component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact';
        $cancel      = ($this->component == 'event') ? '_qf_Register_display'   : '_qf_Main_display';

        $cancelURL   = CRM_Utils_System::url($url, "$cancel=1&cancel=1&qfKey=" . SAGEPAY_QFKEY, true, null, false, true);

        /** @custom This sets the cancel URL to the webform's URL, in case the user came from a webform. */
        if (($node = self::retrieve('node', 'String', 'GET', false)) !== null) {
            $cancelURL = CRM_Utils_System::url("node/{$node}", null, true, null, false, true);
        }
        // end custom

        // Check status returned by gateway ...
        
        if ($status == 'REJECTED' || $status == 'ERROR' || $status == 'NOTAUTHED') {
            
            // Card rejected, not authed, unspecified error
            $result = $this->failed($objects, $transaction);
                    
            echo "Status=ERROR\r\n" . 
                 "RedirectURL=$cancelURL\r\n" .
                 "StatusDetail=Status: $status in notification callback\r\n";
            
            return $result;
        
        } else if ($status == 'ABORT') {
            
            // Cancelled by user
            $result = $this->cancelled($objects, $transaction);
                    
            echo "Status=OK\r\n" . 
                 "RedirectURL=$cancelURL\r\n" .
                 "StatusDetail=Cancelled by user\r\n";
            
            return $result;
        
        } else if ($status != 'OK') {
            
            // Anything else other than OK
            $result = $this->unhandled($objects, $transaction);
            
            $url       = ($this->component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact';
            $cancel    = ($this->component == 'event') ? '_qf_Register_display'   : '_qf_Main_display';
            /**
             * @custom This is commented out so as to allow redirection to the URL passed by 'failureUrl'
             * GET variable, which is set in 'uk_co_circleinteractive_payment_sagepay'
             */
            // $cancelURL = CRM_Utils_System::url($url, "$cancel=1&cancel=1&qfKey=" . SAGEPAY_QFKEY, true, null, false, true);
            
            echo "Status=INVALID\r\n" .
                 "RedirectURL=$cancelURL\r\n" . 
                 "StatusDetail=Unsupported authcode\r\n";
            
            return $result;
            
        }
        
        // If we arrived here, Status = OK
        
        // Check if contribution is already complete, if so ignore this ipn
        if ($contribution->contribution_status_id == 1) {
            $transaction->commit();
            CRM_Core_Error::debug_log_message("Contribution has already been handled");
        } else {
            $this->completeTransaction($input, $ids, $objects, $transaction, $recur);
        }

        $url = ($input['component'] == 'event' ) ? 'civicrm/event/register' : 'civicrm/contribute/transact';

        /** @custom This is commented out in favour of the logic below */
        // $returnURL = CRM_Utils_System::url($url, "_qf_ThankYou_display=1&qfKey=" . SAGEPAY_QFKEY, true, null, false, true);
        // end custom

        /**
         * @custom This builds the return URL based on whether the form submitted was a Webform or a Civi's native form.
         */
        if (($node = self::retrieve('node', 'String', 'GET', false)) !== null) {
            $query = array();
            if (($sid = self::retrieve('sid', 'String', 'GET', false)) !== null) {
                $query[] = "sid={$sid}";
            }
            if (($token = self::retrieve('token', 'String', 'GET', false)) !== null) {
                $query[] = "token={$token}";
            }

            $returnURL = CRM_Utils_System::url("node/{$node}/done", implode('&', $query), true, null, false, true);
        } else {
            $returnURL = CRM_Utils_System::url($url, "_qf_ThankYou_display=1&qfKey=" . SAGEPAY_QFKEY, true, null, false, true);
        }
        // end custom

        echo "Status=OK\r\n" .
             "RedirectURL=$returnURL\r\n";
        
    }
    
}