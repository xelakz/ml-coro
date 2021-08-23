<?php

namespace Workers;

use Models\SystemConfiguration;
use Co\System;

class RequestSessionStatus
{
    public static function handle($dbPool, $swooleTable)
    {
        try {
            $sessionStatusTime = 0;

            $connection              = $dbPool->borrow();
            $refreshDBIntervalResult = SystemConfiguration::getSessionStatusDbInterval($connection);
            $refreshDBInterval       = $connection->fetchArray($refreshDBIntervalResult);
            $requestIntervalResult   = SystemConfiguration::getSessionStatusRequestInterval($connection);
            $requestInterval         = $connection->fetchArray($requestIntervalResult);
            $dbPool->return($connection);

            while (true) {
                $connection = $dbPool->borrow();
                $response   = self::logicHandler($connection, $swooleTable, $sessionStatusTime, $requestInterval['value'], $refreshDBInterval['value']);

                $dbPool->return($connection);
                if ($response) {
                    $sessionStatusTime = $response;
                }

                System::sleep(1);
            }
        } catch (Exception $e) {
            logger('error', 'app', 'Something went wrong', $e);
        }

    }

    public static function logicHandler($connection, $swooleTable, $sessionStatusTime, $requestInterval, $refreshDBInterval)
    {
        if (empty($refreshDBInterval)) {
            $sessionStatusTime++;
            logger('error', 'app', 'No refresh DB interval');
            return $sessionStatusTime;
        }

        if ($sessionStatusTime % $refreshDBInterval == 0) {
            $requestIntervalResult = SystemConfiguration::getSessionStatusRequestInterval($connection);
            $requestInterval       = $connection->fetchArray($requestIntervalResult);
            if (empty($requestInterval)) {
                $sessionStatusTime++;
                System::sleep(1);
                logger('error', 'app', 'No  request interval');
                return $sessionStatusTime;
            } else {
                $requestInterval = $requestInterval['value'];
            }
        }

        if ($sessionStatusTime % $requestInterval == 0) {
            foreach ($swooleTable['enabledProviders'] as $alias => $providers) {
                $payload         = getPayloadPart('session', 'status');
                $payload['data'] = [
                    'provider' => strtolower($alias)
                ];
                kafkaPush(strtolower($alias) . getenv('KAFKA_SESSION_REQUEST_POSTFIX', '_session_req'), $payload, $payload['request_uid']);
            }
        }

        $sessionStatusTime++;
        return $sessionStatusTime;
    }
}
