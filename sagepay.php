<?php

/**
 * Sagepay payment processor for CiviCRM
 * @author andyw@circle, 01/07/2016
 */

use Civi\Payment\System;

// Enable logging
define('CIVICRM_SAGEPAY_LOGGING', true);

// Number of failures after which to cancel a recurring payment
define('CIVICRM_SAGEPAY_RECURRING_MAX_FAILURES', 10);

/**
 * Job api callback for running recurring payments via a Scheduled Task
 * @param array $params  currently unused
 */
function civicrm_api3_job_process_sagepay_recurring_payments($params) {
    foreach (CRM_Core_Payment_Sagepay::getIDs() as $ppID)
        System::singleton()->getById($ppID)->handlePaymentCron();
}

/**
 * Implementation of hook_civicrm_config
 */
function sagepay_civicrm_config(&$config) {

    # initialize include path
    set_include_path(__DIR__ . '/custom/php/' . PATH_SEPARATOR . get_include_path());

    # initialize template path
    $templates = &CRM_Core_Smarty::singleton()->template_dir;
    
    if (!is_array($templates))
        $templates = [$templates];
    
    array_unshift($templates, __DIR__ . '/custom/templates');

}

/**
 * Implementation of hook_civicrm_enable
 * Perform upgrade tasks here (if applicable) - because version 4.0 changes extension type from 'payment' to
 * 'module', it didn't seem possible to do this via hook_civicrm_upgrade
 */
function sagepay_civicrm_enable() {

    # if an upgrade from < 4.0 is needed
    if (CRM_Core_DAO::singleValueQuery("
        SELECT 1 FROM civicrm_payment_processor_type WHERE class_name = 'uk.co.circleinteractive.payment.sagepay'
    ")) {

        # perform upgrade tasks
        foreach ([

             "Update payment processor type record for Sagepay processor." =>

                 "UPDATE civicrm_payment_processor_type 
                     SET class_name='Payment_Sagepay',
                         url_site_test_default='https://test.sagepay.com/gateway/service/vspserver-register.vsp',
                         url_recur_test_default='https://test.sagepay.com/gateway/service/repeat.vsp'
                   WHERE class_name='uk.co.circleinteractive.payment.sagepay'",

             "Update payment processor record for Sagepay processor (live)." =>

                 "UPDATE civicrm_payment_processor 
                     SET class_name='Payment_Sagepay'
                   WHERE class_name='uk.co.circleinteractive.payment.sagepay'
                     AND !is_test",

             "Update payment processor record for Sagepay processor (test)." =>

                 "UPDATE civicrm_payment_processor 
                     SET class_name='Payment_Sagepay',
                         url_site='https://test.sagepay.com/gateway/service/vspserver-register.vsp',
                         url_recur='https://test.sagepay.com/gateway/service/repeat.vsp'
                   WHERE class_name='uk.co.circleinteractive.payment.sagepay'
                     AND is_test",

             "Changing type of Sagepay extension from 'payment' to 'module'." =>

                 "UPDATE civicrm_extension  
                     SET `type`='module'
                   WHERE full_name='uk.co.circleinteractive.payment.sagepay'",

             "Changing api action for scheduled job." =>
                 "UPDATE civicrm_job 
                     SET api_action='process_sagepay_recurring_payments', parameters = NULL
                   WHERE api_action='run_payment_cron'"

            ] as $task => $query) {

            CRM_Core_Error::debug_log_message($task);
            CRM_Core_DAO::executeQuery($query);

        }

    }

}

function sagepay_civicrm_disable() {
    $config = null;
    sagepay_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_install
 */
function sagepay_civicrm_install() {

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

    civicrm_api3('PaymentProcessorType', 'create', [

        'name'                   => 'Sagepay',
        'title'                  => 'Sagepay',
        'description'            => 'Sagepay payment processor (using the Server protocol)',
        'class_name'             => 'Payment_Sagepay',
        'billing_mode'           => 4,
        'user_name_label'        => 'Vendor ID',
        'url_site_default'       => 'https://live.sagepay.com/gateway/service/vspserver-register.vsp',
        'url_recur_default'      => 'https://live.sagepay.com/gateway/service/repeat.vsp',
        'url_site_test_default'  => 'https://test.sagepay.com/gateway/service/vspserver-register.vsp',
        'url_recur_test_default' => 'https://test.sagepay.com/gateway/service/repeat.vsp',
        'is_recur'               => 1,
        'payment_type'           => 1,
        'is_active'              => 1
   
    ]);

}

/**
 * Implementations of hook_civicrm_uninstall
 */
function sagepay_civicrm_uninstall() {
    
    civicrm_api3('PaymentProcessorType', 'delete', [
        'id' => civicrm_api3('PaymentProcessorType', 'getvalue', [
            'class_name' => 'Payment_Sagepay',
            'return'     => 'id'
        ])
    ]);

}

/**
 * Implementation of hook_civicrm_managed
 */
function sagepay_civicrm_managed(&$entities) {
    
    // this doesn't work unfortunately, but does when you pass the exact same params
    // to civicrm_api3() - so just doing that in the install hook for now.
    // would be great if this stuff actually worked though!
    /*
    $entities[] = [
        
        'module' => 'uk.co.circleinteractive.payment.sagepay',
        'name'   => 'Sagepay',
        'entity' => 'PaymentProcessorType',
        
        'params' => [
            
            'version'                => 3,
            'name'                   => 'Sagepay',
            'title'                  => 'Sagepay',
            'description'            => 'Sagepay payment processor (using the Server protocol)',
            'class_name'             => 'Payment_Sagepay',
            'billing_mode'           => 'form',
            'user_name_label'        => 'Vendor ID',
            'url_site_default'       => 'https://live.sagepay.com/gateway/service/vspserver-register.vsp',
            'url_recur_default'      => 'https://live.sagepay.com/gateway/service/repeat.vsp',
            'url_site_test_default'  => 'https://test.sagepay.com/gateway/service/vspserver-register.vsp',
            'url_recur_test_default' => 'https://test.sagepay.com/gateway/service/repeat.vsp',
            'is_recur'               => 1,
            'payment_type'           => 1,
            'is_active'              => 1
       
        ]

    ];
    */

    $entities[] = [

        'module' => 'uk.co.circleinteractive.payment.sagepay',
        'name'   => 'Sagepay',
        'entity' => 'Job',
        'update' => 'never',
     
        'params' => [
            
            'version'       => 3,
            'name'          => ts('Process Sagepay Recurring Payments'),
            'description'   => ts('Processes any Sagepay recurring payments that are due'),
            'run_frequency' => 'Always',
            'api_entity'    => 'job',
            'api_action'    => 'process_sagepay_recurring_payments',
            'is_active'     => 1

        ]

    ];

}

/**
 * Helper function for logging
 */
function sagepay_log($message) {
    if (defined('CIVICRM_SAGEPAY_LOGGING') and CIVICRM_SAGEPAY_LOGGING) {
        CRM_Core_Error::debug_log_message($message);
        if (function_exists('watchdog'))
            watchdog('civicrm_sagepay', $message, [], WATCHDOG_INFO);
    }
}

/**
 * Class uk_co_circleinteractive_payment_sagepay
 * This is here to prevent 500 errors before the upgrade is run
 */
class uk_co_circleinteractive_payment_sagepay extends CRM_Core_Payment {

    public static function &singleton($mode = 'test', &$paymentProcessor, &$paymentForm = null, $force = false) {
        $processorName = $paymentProcessor['name'];
        if (is_null(self::$_singleton[$processorName]))
            self::$_singleton[$processorName] = new uk_co_circleinteractive_payment_sagepay($mode, $paymentProcessor);
        return self::$_singleton[$processorName];
    }

    public function __construct($mode, &$paymentProcessor) {}
    public function checkConfig() {}
    public function doDirectPayment(&$params) {}
    public function doTransferCheckout(&$params, $component = 'contribute') {}

}
