<?php

namespace Workers;

use Models\{
    SystemConfiguration,
    ProviderAccount
};
use Co\System;

class RequestSessionCategory
{
    const CATEGORY_TYPES = [
        'SCRAPER'         => 'odds',
        'SCRAPER_MIN_MAX' => 'minmax',
        'BET_NORMAL'      => 'bet',
        'BET_VIP'         => 'bet'
    ];

    public static function handle($dbPool, $swooleTable)
    {
        try {
            $sessionCategoryTime     = 0;
            $connection              = $dbPool->borrow();
            $refreshDBIntervalResult = SystemConfiguration::getSessionCategoryDbInterval($connection);
            $refreshDBInterval       = $connection->fetchArray($refreshDBIntervalResult);

            $dbPool->return($connection);

            while (true) {
                $connection = $dbPool->borrow();
                $response   = self::logicHandler($connection, $swooleTable, $sessionCategoryTime, $refreshDBInterval['value']);

                $dbPool->return($connection);

                if ($response) {
                    $sessionCategoryTime = $response;
                }

                System::sleep(1);
            }
        } catch (Exception $e) {
            logger('error', 'app', 'Something went wrong', $e);
        }

    }

    public static function logicHandler($connection, $swooleTable, $sessionCategoryTime, $refreshDBInterval)
    {
        if (empty($refreshDBInterval)) {
            $sessionCategoryTime++;
            logger('error', 'app', 'No refresh DB interval');

            return $sessionCategoryTime;
        }

        if ($sessionCategoryTime % $refreshDBInterval == 0) {
            $providerAccountResult = ProviderAccount::getEnabledProviderAccounts($connection);
            $providerAccounts      = [];

            while ($providerAccount = $connection->fetchAssoc($providerAccountResult)) {
                $providerAccounts[$providerAccount['id']] = $providerAccount['type'];
            }

            foreach ($swooleTable['providerAccounts'] as $key => $account) {
                if (array_key_exists($key, $providerAccounts) && $providerAccounts[$key] == $account['type']) {
                    $swooleTable['providerAccounts'][$key]['type'] = $providerAccounts[$key];
                    $payload                                       = getPayloadPart('session', 'category');
                    $payload['data']                               = [
                        'provider' => strtolower($account['alias']),
                        'username' => $account['username'],
                        'category' => self::CATEGORY_TYPES[$providerAccounts[$key]]
                    ];

                    kafkaPush(strtolower($account['alias']) . getenv('KAFKA_SESSION_REQUEST_POSTFIX', '_session_req'), $payload, $payload['request_uid']);
                }
            }
        }

        $sessionCategoryTime++;

        return $sessionCategoryTime;
    }
}
