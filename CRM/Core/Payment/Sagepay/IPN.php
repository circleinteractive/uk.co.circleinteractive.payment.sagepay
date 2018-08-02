<?php
/**
 * Sagepay Extension for CiviCRM - Circle Interactive 2010-16
 * Callback Notification Class
 *
 * RM: Customisations have been annotated using a custom PHPDoc tag @custom
 */
class CRM_Core_Payment_Sagepay_IPN extends CRM_Core_Payment_BaseIPN {
  protected $sagepay;
  static $_paymentProcessor = NULL;

  public function __construct($sagepay) {
    $this->sagepay = $sagepay;
    parent::__construct();
  }

  public function main($component = 'contribute') {
    $sagepay = &$this->sagepay;
    $token = new CRM_Core_Payment_Sagepay_Token();

    $objects = $ids = $input = [];

    $this->component = $input['component'] = $component;

    // Get contribution and contact ids from querystring
    $ids['contact'] = self::retrieve('cid', 'Integer', 'GET', TRUE);
    $ids['contribution'] = self::retrieve('conid', 'Integer', 'GET', TRUE);
    $ids['vendor'] = strtolower(self::retrieve('v', 'String', 'GET', TRUE));
    $ids['paymentProcessor'] = self::retrieve('processor_id', 'Integer', 'GET', TRUE);

    // req'd for cancel url
    define('SAGEPAY_QFKEY', self::retrieve('qf', 'String', 'GET', FALSE));

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
      $input['trxn_id'], $input['invoice'], $input['paymentStatus'], $input['TxAuthNo'],
      $ids['vendor'], $input['AVSCV2'], $security_key, $input['AddressResult'],
      $input['PostCodeResult'], $input['CV2Result'], $input['GiftAid'], $input['3DSecureStatus'],
      $input['CAVV'], $input['AddressStatus'], $input['PayerStatus'], $input['CardType'],
      $input['Last4Digits'], $input['DeclineCode'], $input['ExpiryDate'], $input['FraudResponse'],
      $input['BankAuthCode']
    ])));

    // Compare our locally constructed signature to the VPS signature returned by Sagepay
    if ($signature !== $input['VPSSignature']) {

      CRM_Core_Error::debug_log_message('Invalid VPS Signature: ' . print_r($input, TRUE));

      // Not matched, send INVALID response and return
      $url = ($component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact';
      $cancel = ($component == 'event') ? '_qf_Register_display' : '_qf_Main_display';
      $redirectURL = CRM_Utils_System::url($url, "$cancel=1&cancel=1&qfKey=" . SAGEPAY_QFKEY, TRUE, NULL, FALSE, TRUE);

      echo "Status=INVALID\r\n" .
        "StatusDetail=Unable to match VPS signature.\r\n" .
        "RedirectURL=$redirectURL\r\n";

      sagepay_log('Transaction failed: Invalid VPS Signature');

      return;
    }

    // VPS Signature check OK ...

    if ($component == 'event') {
      $ids['event'] = self::retrieve('eid', 'Integer', 'GET', TRUE);
      $ids['participant'] = self::retrieve('pid', 'Integer', 'GET', TRUE);
    }
    else {
      // Get optional ids
      $ids['membership'] = self::retrieve('mid', 'Integer', 'GET', FALSE);
      $ids['contributionRecur'] = self::retrieve('crid', 'Integer', 'GET', FALSE);
      $ids['contributionPage'] = self::retrieve('cpid', 'Integer', 'GET', FALSE);
      $ids['related_contact'] = self::retrieve('rcid', 'Integer', 'GET', FALSE);
      $ids['onbehalf_dupe_alert'] = self::retrieve('obda', 'Integer', 'GET', FALSE);
    }

    CRM_Core_Error::debug_log_message('input: ' . print_r($input, TRUE));
    CRM_Core_Error::debug_log_message('ids: ' . print_r($ids, TRUE));

    if (!$this->validateData($input, $ids, $objects)) {
      sagepay_log('Transaction failed: Unable to validate data', __CLASS__ . '::' . __METHOD__, __LINE__);
      return FALSE;
    }

    self::$_paymentProcessor = &$objects['paymentProcessor'];

    // Contribution ...
    if ($component == 'contribute') {
      // Recurring ...
      if (isset($ids['contributionRecur']) and !empty($ids['contributionRecur'])) {
        // check if first contribution is completed, else complete first contribution
        $first = TRUE;
        if ($objects['contribution']->contribution_status_id == 1) {
          $first = FALSE;
        }
        return $this->recur($input, $ids, $objects, $first);
      }
      else {
        sagepay_log('Transaction success: contribution');
        return $this->single($input, $ids, $objects, FALSE, FALSE);
      }
    }
    else {
      sagepay_log('Transaction success: event');
      return $this->single($input, $ids, $objects, FALSE, FALSE);
    }
  }

  /**
   * There is no IPN notification for a REPEAT transaction. Instead, we get an instant response,
   * then call this function to complete transaction / mark as failed.
   */
  public function processRepeatTransaction(&$params, &$response) {
    $sagepay = &$this->sagepay;
    $objects = [];

    if ($response['Status'] == 'OK') {
      // Spoof sufficient notification params to allow IPN code to complete the transaction ..
      $ids = [
        'contact' => $params['contactID'],
        'contribution' => $params['contributionID'],
        'contributionRecur' => $params['recurID'],
        'membership' => $params['membershipID'],
      ];

      $input = [
        'component' => 'contribute',
        'paymentStatus' => $response['Status'],
        'invoice' => $params['RelatedVendorTxCode'],
        'trxn_id' => $response['VPSTxId'],
        'amount' => $params['amount'],
      ];

      define('SAGEPAY_QFKEY', ''); // Not relevant in this context, but define something to prevent warnings

      if (!$this->validateData($input, $ids, $objects)) {
        sagepay_log('Transaction failed: Unable to validate data', __CLASS__ . '::' . __METHOD__, __LINE__);
        return FALSE;
      }

      // Suppress any output which may occur, then run IPN completion code
      ob_start();
      $this->recur($input, $ids, $objects, $first);

      // Grab output and log if logging level >= 4
      $output = ob_get_clean();
      sagepay_log("Processed REPEAT transaction:\n" . $output);

      return TRUE;

    }
    else {
      // Handle != OK responses ..

      sagepay_log(sprintf(
        'Payment failure on recurring payment at Sagepay on contribution_id %d using contribution_recur_id %d.\n' .
        'Sagepay system responded: %s',
        $ids['contribution'],
        $ids['contributionRecur'],
        print_r($response, TRUE)
      ));

      // Update contribution_recur record failure count
      $sagepay->updateFailureCount($params['recurID']);

      // Mark contribution as Failed
      $status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());

      try {
        civicrm_api3("Contribution", "create", [
          'id' => $params['contributionID'],
          'contribution_status_id' => $status_id['Failed']
        ]);
      }
      catch (CiviCRM_API3_Exception $e) {
        sagepay_log("Failed updating contribution status to 'Failed' on transaction: " . $e->getMessage());
      }
      return FALSE;
    }
  }

  public static function retrieve($name, $type, $location = 'POST', $abort = TRUE) {
    static $store = NULL;
    $value = CRM_Utils_Request::retrieve($name, $type, $store, FALSE, NULL, $location);
    if ($abort && $value === NULL) {
      CRM_Core_Error::debug_log_message("Could not find an entry for $name in $location");
      echo "Failure: Missing Parameter";
      exit();
    }
    return $value;
  }

  protected function getInput(&$input, &$ids) {
    if (!@$this->getBillingID($ids)) {
      return FALSE;
    }
    $input['payment_processor_id'] = self::retrieve('processor_id', 'Integer', 'GET', FALSE);

    $input['VPSProtocol'] = self::retrieve('VPSProtocol', 'Money', 'POST', FALSE);
    $input['TxType'] = self::retrieve('TxType', 'String', 'POST', FALSE);
    $input['invoice'] = self::retrieve('VendorTxCode', 'String', 'POST', FALSE);
    $input['trxn_id'] = self::retrieve('VPSTxId', 'String', 'POST', FALSE);
    $input['paymentStatus'] = self::retrieve('Status', 'String', 'POST', FALSE);
    $input['StatusDetail'] = self::retrieve('StatusDetail', 'String', 'POST', FALSE);
    $input['TxAuthNo'] = self::retrieve('TxAuthNo', 'String', 'POST', FALSE);
    $input['AVSCV2'] = self::retrieve('AVSCV2', 'String', 'POST', FALSE);
    $input['AddressResult'] = self::retrieve('AddressResult', 'String', 'POST', FALSE);
    $input['PostCodeResult'] = self::retrieve('PostCodeResult', 'String', 'POST', FALSE);
    $input['CV2Result'] = self::retrieve('CV2Result', 'String', 'POST', FALSE);
    $input['GiftAid'] = self::retrieve('GiftAid', 'String', 'POST', FALSE);
    $input['3DSecureStatus'] = self::retrieve('3DSecureStatus', 'String', 'POST', FALSE);
    $input['CAVV'] = self::retrieve('CAVV', 'String', 'POST', FALSE);
    $input['AddressStatus'] = self::retrieve('AddressStatus', 'String', 'POST', FALSE);
    $input['PayerStatus'] = self::retrieve('PayerStatus', 'String', 'POST', FALSE);
    $input['CardType'] = self::retrieve('CardType', 'String', 'POST', FALSE);
    $input['Last4Digits'] = self::retrieve('Last4Digits', 'String', 'POST', FALSE);
    $input['VPSSignature'] = self::retrieve('VPSSignature', 'String', 'POST', FALSE);

    # added for protocol v3.0 ..
    $input['DeclineCode'] = self::retrieve('DeclineCode', 'String', 'POST', FALSE);
    $input['ExpiryDate'] = self::retrieve('ExpiryDate', 'String', 'POST', FALSE);
    $input['FraudResponse'] = self::retrieve('FraudResponse', 'String', 'POST', FALSE);
    $input['BankAuthCode'] = self::retrieve('BankAuthCode', 'String', 'POST', FALSE);
  }

  public function recur(&$input, &$ids, &$objects, $first) {
 	  $contribution_status_id = array_flip(CRM_Contribute_PseudoConstant::contributionStatus());

    $recur = &$objects['contributionRecur'];
    $sagepay = &$this->sagepay;
    $token = new CRM_Core_Payment_Sagepay_Token();

    $sagepay_recur_data = $token->get([
      'data_type' => 'recurring',
      'entity_id' => $recur->id
    ]);

    // Make sure invoice id is valid and matches the contribution record
    if ($recur->invoice_id != $input['invoice']) {
      sagepay_log("Invoice values don't match between database and Sagepay request");
      echo "Failure: Invoice values don't match between database and Sagepay request\r\n";
      return FALSE;
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
            // NB: $recur->installments will be NULL in the case of an indefinite recurring period - eg: membership auto-renew
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
        return $this->single($input, $ids, $objects, TRUE, $first);

    }

    function single(&$input, &$ids, &$objects, $recur = FALSE, $first = FALSE) {

        $contribution =& $objects['contribution'];

        // make sure the invoice is valid and matches what we have in the contribution record
        if (!$recur || ($recur && $first)) {
            if ($contribution->invoice_id != $input['invoice']) {
                CRM_Core_Error::debug_log_message("Invoice values dont match between database and IPN request");
                CRM_Core_Error::debug_log_message("contribution->invoice_id=" . $contribution->invoice_id);
                CRM_Core_Error::debug_log_message("input['invoice']=" . $input['invoice']);
                echo "Failure: Invoice values dont match between database and IPN request<p>";
                return FALSE;
            }
        } else {
            $contribution->invoice_id = md5(uniqid(rand(), TRUE));
        }

        $input['amount'] = $contribution->total_amount;
        if (!$recur ) {
            if ($contribution->total_amount != $input['amount']) {
                CRM_Core_Error::debug_log_message("Amount values dont match between database and IPN request");
                echo "Failure: Amount values dont match between database and IPN request<p>";
                return FALSE;
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

        $cancelURL   = CRM_Utils_System::url($url, "$cancel=1&cancel=1&qfKey=" . SAGEPAY_QFKEY, TRUE, NULL, FALSE, TRUE);

        /** @custom This sets the cancel URL to the webform's URL, in case the user came from a webform. */
        if (($node = self::retrieve('node', 'String', 'GET', FALSE)) !== NULL) {
            $cancelURL = CRM_Utils_System::url("node/{$node}", NULL, TRUE, NULL, FALSE, TRUE);
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
            // $cancelURL = CRM_Utils_System::url($url, "$cancel=1&cancel=1&qfKey=" . SAGEPAY_QFKEY, TRUE, NULL, FALSE, TRUE);

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
        // $returnURL = CRM_Utils_System::url($url, "_qf_ThankYou_display=1&qfKey=" . SAGEPAY_QFKEY, TRUE, NULL, FALSE, TRUE);
        // end custom

        /**
         * @custom This builds the return URL based on whether the form submitted was a Webform or a Civi's native form.
         */
        if (($node = self::retrieve('node', 'String', 'GET', FALSE)) !== NULL) {
            $query = array();
            if (($sid = self::retrieve('sid', 'String', 'GET', FALSE)) !== NULL) {
                $query[] = "sid={$sid}";
            }
            if (($token = self::retrieve('token', 'String', 'GET', FALSE)) !== NULL) {
                $query[] = "token={$token}";
            }

            $returnURL = CRM_Utils_System::url("node/{$node}/done", implode('&', $query), TRUE, NULL, FALSE, TRUE);
        } else {
            $returnURL = CRM_Utils_System::url($url, "_qf_ThankYou_display=1&qfKey=" . SAGEPAY_QFKEY, TRUE, NULL, false, true);
        }
        // end custom

        echo "Status=OK\r\n" .
             "RedirectURL=$returnURL\r\n";

    }

}
