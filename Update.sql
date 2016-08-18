# Update payment processor type record for Sagepay processor.
UPDATE civicrm_payment_processor_type
   SET class_name='Payment_Sagepay',
       url_site_test_default='https://test.sagepay.com/gateway/service/vspserver-register.vsp',
       url_recur_test_default='https://test.sagepay.com/gateway/service/repeat.vsp'
 WHERE class_name='uk.co.circleinteractive.payment.sagepay';

# Update payment processor record for Sagepay processor (live).
UPDATE civicrm_payment_processor
   SET class_name='Payment_Sagepay'
 WHERE class_name='uk.co.circleinteractive.payment.sagepay'
   AND !is_test;

# Update payment processor record for Sagepay processor (test).
UPDATE civicrm_payment_processor
   SET class_name='Payment_Sagepay',
       url_site='https://test.sagepay.com/gateway/service/vspserver-register.vsp',
       url_recur='https://test.sagepay.com/gateway/service/repeat.vsp'
 WHERE class_name='uk.co.circleinteractive.payment.sagepay'
   AND is_test;

# Changing type of Sagepay extension from 'payment' to 'module'.
UPDATE civicrm_extension
   SET `type`='module'
 WHERE full_name='uk.co.circleinteractive.payment.sagepay';

# Changing api action for scheduled job.
UPDATE civicrm_job
   SET api_action='process_sagepay_recurring_payments', parameters = NULL
 WHERE api_action='run_payment_cron';
