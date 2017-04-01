<?php

class CRM_Core_Payment_Sagepay_Token {
    
    public function __call($method, $params) {
    
        if (count($params))
            $params = reset($params);

        $entity_ref = [
            'key'       => 'contribution',
            'recurring' => 'contribution_recur'
        ]; 
        
        if (!isset($params['data_type']))
            $params['data_type'] = 'key';

        switch ($method) {
            
            case 'delete':
                
                return CRM_Core_DAO::executeQuery("
                   DELETE FROM civicrm_sagepay WHERE data_type = %1 AND entity_id = %2
                ", [
                      1 => [$params['data_type'], 'String'],
                      2 => [$params['entity_id'], 'Integer']
                   ]
                );

            case 'create':
                
                return CRM_Core_DAO::executeQuery("
                   INSERT INTO civicrm_sagepay (id, created, data_type, entity_type, entity_id, data)
                   VALUES (NULL, NOW(), %1, %2, %3, %4)
                ", [
                      1 => [$params['data_type'], 'String'],
                      2 => [isset($entity_ref[$params['data_type']]) ? $entity_ref[$params['data_type']] : 'nothing', 'String'],
                      3 => [isset($params['entity_id']) ? $params['entity_id'] : 0, 'Integer'],
                      4 => [serialize($params['data']), 'String']
                   ]
                );

            case 'get':

                if ($data = CRM_Core_DAO::singleValueQuery("
                   SELECT data FROM civicrm_sagepay WHERE data_type = %1 AND entity_id = %2    
                ", [
                      1 => [$params['data_type'], 'String'],
                      2 => [$params['entity_id'], 'Integer']
                   ]
                ))
                    return unserialize($data);
                return null;

            case 'update':
                
                return CRM_Core_DAO::executeQuery("
                   UPDATE civicrm_sagepay SET data = %1 WHERE data_type = %2 AND entity_id = %3
                ", [
                      1 => [serialize($params['data']), 'String'],
                      2 => [$params['data_type'], 'String'],
                      3 => [$params['entity_id'], 'Integer']
                   ]
                );


        }

    }

}