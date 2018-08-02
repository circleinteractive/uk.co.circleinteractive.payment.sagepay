<?php
return [
  0 => [
    'name' => 'Cron:Job.ProcessSagepayRecurringPayments',
    'entity' => 'Job',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => ts('Process Sagepay Recurring Payments'),
      'description' => ts('Processes any Sagepay recurring payments that are due'),
      'run_frequency' => 'Always',
      'api_entity' => 'Job',
      'api_action' => 'ProcessSagepayRecurringPayments',
      'parameters' => '',
    ],
  ],
];
