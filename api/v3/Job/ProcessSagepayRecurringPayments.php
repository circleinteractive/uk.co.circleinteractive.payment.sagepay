<?php
use _ExtensionUtil as E;

/**
 * Job.ProcessOfflineRecurringPayments API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_job_ProcessOfflineRecurringPayments($params) {
  foreach (CRM_Core_Payment_Sagepay::getIDs() as $ppID) {
    System::singleton()->getById($ppID)->handlePaymentCron();
  }
  // No records processed
  return civicrm_api3_create_success(TRUE);
}
