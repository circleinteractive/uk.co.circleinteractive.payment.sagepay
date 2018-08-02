<?php

require_once 'sagepay.civix.php';
use CRM_Sagepay_ExtensionUtil as E;
use Civi\Payment\System;

// Enable logging
define('CIVICRM_SAGEPAY_LOGGING', TRUE);

// Number of failures after which to cancel a recurring payment
define('CIVICRM_SAGEPAY_RECURRING_MAX_FAILURES', 10);

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function sagepay_civicrm_config(&$config) {
  _sagepay_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function sagepay_civicrm_xmlMenu(&$files) {
  _sagepay_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function sagepay_civicrm_install() {
  _sagepay_civix_civicrm_install();

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
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1;
  ");
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function sagepay_civicrm_postInstall() {
  _sagepay_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function sagepay_civicrm_uninstall() {
  _sagepay_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function sagepay_civicrm_enable() {
  _sagepay_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function sagepay_civicrm_disable() {
  _sagepay_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function sagepay_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _sagepay_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function sagepay_civicrm_managed(&$entities) {
  _sagepay_civix_civicrm_managed($entities);
  $entities[] = [
    'module' => 'uk.co.circleinteractive.payment.sagepay',
    'name' => 'Sagepay',
    'entity' => 'PaymentProcessorType',
    'params' => [
      'version' => 3,
      'name' => 'Sagepay',
      'title' => 'Sagepay',
      'description' => 'Sagepay payment processor (using the Server protocol).',
      'class_name' => 'Payment_Sagepay',
      'billing_mode' => 'notify',
      'user_name_label' => 'Vendor ID',
      'url_site_default' => 'https://live.sagepay.com/gateway/service/vspserver-register.vsp',
      'url_recur_default' => 'https://live.sagepay.com/gateway/service/repeat.vsp',
      'url_site_test_default' => 'https://test.sagepay.com/gateway/service/vspserver-register.vsp',
      'url_recur_test_default' => 'https://test.sagepay.com/gateway/service/repeat.vsp',
      'is_recur' => TRUE,
      'payment_type' => 1,
      'is_active' => TRUE,
    ],
  ];
  $entities[] = [
    'module' => 'uk.co.circleinteractive.payment.sagepay',
    'name' => 'Cron:Job.ProcessSagepayRecurringPayments',
    'entity' => 'Job',
    'params' => [
      'version' => 3,
      'name' => ts('Process Sagepay Recurring Payments'),
      'description' => ts('Processes any Sagepay recurring payments that are due'),
      'run_frequency' => 'Always',
      'api_entity' => 'Job',
      'api_action' => 'ProcessSagepayRecurringPayments',
      'parameters' => '',
      'is_active' => TRUE,
    ],
  ];
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function sagepay_civicrm_caseTypes(&$caseTypes) {
  _sagepay_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules
 */
function sagepay_civicrm_angularModules(&$angularModules) {
  _sagepay_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function sagepay_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _sagepay_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_entityTypes
 */
function sagepay_civicrm_entityTypes(&$entityTypes) {
  _sagepay_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Helper function for logging
 */
function sagepay_log($message) {
  if (defined('CIVICRM_SAGEPAY_LOGGING') and CIVICRM_SAGEPAY_LOGGING) {
    CRM_Core_Error::debug_log_message($message);
    if (function_exists('watchdog')) {
      watchdog('civicrm_sagepay', $message, [], WATCHDOG_INFO);
    }
  }
}
