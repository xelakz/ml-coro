<?php

namespace Workers;

use Models\{
    SystemConfiguration,
    Order
};
use Carbon\Carbon;
use Co\System;

class RequestSettlement
{
    public static function handle($dbPool, $swooleTable)
    {
        try {
            $settlementTime            = time();
            $systemConfigurationsTimer = null;

            $connection              = $dbPool->borrow();
            $refreshDBIntervalResult = SystemConfiguration::getSettlementRequestInterval($connection);
            $refreshDBInterval       = $connection->fetchArray($refreshDBIntervalResult);
            $dbPool->return($connection);

            while (true) {
                $connection = $dbPool->borrow();
                $response   = self::logicHandler($connection, $swooleTable, $settlementTime, $refreshDBInterval['value'], $systemConfigurationsTimer);
                $dbPool->return($connection);

                if ($response) {
                    $settlementTime = $response;
                }
                System::sleep(1);
            }
        } catch (Exception $e) {
            logger('error', 'app', 'Settlement: Something went wrong', $e);
        }
    }

    public static function logicHandler($connection, $swooleTable, &$settlementTime, $refreshDBInterval, &$systemConfigurationsTimer)
    {
        if (empty($refreshDBInterval)) {
            $settlementTime = time();
            logger('error', 'app', 'Settlement: No refresh DB interval');
            return $settlementTime;
        }

        if (((time() - $settlementTime) % $refreshDBInterval == 0) or empty($systemConfigurationsTimer)) {
            $settlementTimerResult = SystemConfiguration::getSettlementRequestTimer($connection);
            $settlementTimer       = $connection->fetchArray($settlementTimerResult);
            if ($settlementTimer) {
                $systemConfigurationsTimer = $settlementTimer['value'];
            }
        }

        if ($systemConfigurationsTimer) {
            foreach ($swooleTable['enabledSports'] as $sportId => $sRow) {
                // logger('info', 'app', 'Settlement: settlementTime % systemConfigurationsTimer==' . (time() - $settlementTime) .' == ' . ((time() - $settlementTime) % (int) $systemConfigurationsTimer));
                if ((time() - $settlementTime) % (int) $systemConfigurationsTimer == 0) {
                    $settlementTime = time();
                    foreach ($swooleTable['providerAccounts'] as $paId => $pRow) {
                        $providerAlias = strtolower($pRow['alias']);
                        $username      = $pRow['username'];
                        $command       = 'settlement';
                        $subCommand    = 'scrape';

                        if ($swooleTable['maintenance']->exists($providerAlias) && empty($swooleTable['maintenance'][$providerAlias]['under_maintenance'])) {
                            $providerUnsettledDatesResult = Order::getUnsettledDates($connection, $paId);

                            while ($providerUnsettledDate = $connection->fetchAssoc($providerUnsettledDatesResult)) {
                                // logger('info', 'app', 'Settlement: ' . $username . '==' . $providerUnsettledDate['unsettled_date']);
                                $previous_day = Carbon::createFromFormat('Y-m-d', $providerUnsettledDate['unsettled_date'])->subDays(1)->format('Y-m-d');
                                $unsettled_date = Carbon::createFromFormat('Y-m-d', $providerUnsettledDate['unsettled_date'])->format('Y-m-d');
                                $subHours5 = Carbon::now()->subHours(5)->format('Y-m-d');

                                $payload['data'] = [
                                    'sport'           => $sportId,
                                    'provider'        => $providerAlias,
                                    'username'        => $username,
                                    'settlement_date' => $previous_day,
                                ];
                                go(function () use ($providerAlias, $payload, $command, $subCommand) {
                                    System::sleep(rand(1, 3));
                                    $payloadPart = getPayloadPart($command, $subCommand);
                                    $payload = array_merge($payloadPart, $payload);
                                    kafkaPush($providerAlias . getenv('KAFKA_SCRAPE_SETTLEMENT_POSTFIX', '_settlement_req'), $payload, $payload['request_uid']);
                                    logger('info', 'app', 'Settlement: unsettled_date '. $providerAlias . getenv('KAFKA_SCRAPE_SETTLEMENT_POSTFIX', '_settlement_req') . " Payload Sent", $payload);
                                });

                                if (Carbon::now()->format('Y-m-d') != $unsettled_date) {
                                    $payload['data'] = [
                                        'sport'           => $sportId,
                                        'provider'        => $providerAlias,
                                        'username'        => $username,
                                        'settlement_date' => Carbon::createFromFormat('Y-m-d', $providerUnsettledDate['unsettled_date'])->format('Y-m-d'),
                                    ];
                                    go(function () use ($providerAlias, $payload, $command, $subCommand) {
                                        System::sleep(rand(1, 3));
                                        $payloadPart = getPayloadPart($command, $subCommand);
                                        $payload = array_merge($payloadPart, $payload);
                                        kafkaPush($providerAlias . getenv('KAFKA_SCRAPE_SETTLEMENT_POSTFIX', '_settlement_req'), $payload, $payload['request_uid']);
                                        logger('info', 'app', 'Settlement: current <> unsettled_date ' . $unsettled_date . '->' . $providerAlias . getenv('KAFKA_SCRAPE_SETTLEMENT_POSTFIX', '_settlement_req') . " Payload Sent", $payload);
                                    });
                                }

                                if ($previous_day != $subHours5) {
                                    $payload['data'] = [
                                        'sport'           => $sportId,
                                        'provider'        => $providerAlias,
                                        'username'        => $username,
                                        'settlement_date' => Carbon::now()->subHours(5)->format('Y-m-d'),
                                    ];
                                    go(function () use ($providerAlias, $payload, $command, $subCommand) {
                                        System::sleep(rand(60, 300));
                                        $payloadPart = getPayloadPart($command, $subCommand);
                                        $payload = array_merge($payloadPart, $payload);
                                        kafkaPush($providerAlias . getenv('KAFKA_SCRAPE_SETTLEMENT_POSTFIX', '_settlement_req'), $payload, $payload['request_uid']);
                                        logger('info', 'app', 'Settlement: subHours5 ' . $subHours5 . '->'  . $providerAlias . getenv('KAFKA_SCRAPE_SETTLEMENT_POSTFIX', '_settlement_req') . " Payload Sent", $payload);
                                    });
                                }
                            }
                        }
                    }
                }
            }
            return $settlementTime;
        }
    }
}
