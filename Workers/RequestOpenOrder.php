<?php

namespace Workers;

use Models\SystemConfiguration;
use Co\System;

class RequestOpenOrder
{
    public static function handle($dbPool, $swooleTable)
    {
        try {
            $openOrderTime                = time();
            $systemConfigurationsTimer = null;

            $connection              = $dbPool->borrow();
            $refreshDBIntervalResult = SystemConfiguration::getOpenOrderRequestInterval($connection);
            $refreshDBInterval       = $connection->fetchArray($refreshDBIntervalResult);
            $dbPool->return($connection);

            while (true) {
                $connection = $dbPool->borrow();
                $response   = self::logicHandler($connection, $swooleTable, $openOrderTime, $refreshDBInterval['value'], $systemConfigurationsTimer);
                $dbPool->return($connection);

                if ($response) {
                    $openOrderTime = $response;
                }
                System::sleep(1);
            }
        } catch (Exception $e) {
            logger('error', 'app', 'Something went wrong', $e);
        }

    }

    public static function logicHandler($connection, $swooleTable, &$openOrderTime, $refreshDBInterval, &$systemConfigurationsTimers)
    {
        if (empty($refreshDBInterval)) {
            $openOrderTime = time();
            logger('error', 'app', 'Open Orders: No refresh DB interval');
            return $openOrderTime;
        }

        if (($openOrderTime % $refreshDBInterval == 0) or empty($systemConfigurationsTimer)) {
            $openOrderTimerResult = SystemConfiguration::getOpenOrderRequestTimer($connection);
            $openOrderTimer = $connection->fetchArray($openOrderTimerResult);
            if ($openOrderTimer) {
                $systemConfigurationsTimer = $openOrderTimer['value'];
            }
        }

        if ($systemConfigurationsTimer) {
            // logger('info', 'app', 'Open Orders: openOrderTime % systemConfigurationsTimer==' . (time() - $openOrderTime) .' == ' . ((time() - $openOrderTime) % (int) $systemConfigurationsTimer));
            if ((time() - $openOrderTime) % (int) $systemConfigurationsTimer == 0) {
                $openOrderTime = time();
                foreach ($swooleTable['enabledSports'] as $key => $row) {
                    self::sendKafkaPayload($swooleTable, getenv('KAFKA_SCRAPE_OPEN_ORDERS_POSTFIX', '_openorder_req'), 'orders', 'scrape', $key);
                }
            }
        }
        return $openOrderTime;
    }

    public static function sendKafkaPayload($swooleTable, $topic, $command, $subcommand, $sportId = null)
    {
        $providerAccountsTable = $swooleTable['providerAccounts'];
        $maintenanceTable      = $swooleTable['maintenance'];

        foreach ($providerAccountsTable as $key => $providerAccount) {
            $username        = $providerAccount['username'];
            $provider        = strtolower($providerAccount['alias']);
            $payload         = getPayloadPart($command, $subcommand);
            $payload['data'] = [
                'provider' => $provider,
                'username' => $username
            ];

            if ($sportId) {
                $payload['data']['sport'] = $sportId;
            }

            if ($maintenanceTable->exists($provider) && empty($maintenanceTable[$provider]['under_maintenance'])) {
                go(function () use ($provider, $topic, $payload) {
                    System::sleep(rand(1, 10));
                    kafkaPush($provider . $topic, $payload, $payload['request_uid']);
                    logger('info', 'app', $provider . $topic . "Open Orders: Payload Sent", $payload);
                });
            }
        }
    }
}
