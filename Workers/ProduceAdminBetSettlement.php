<?php

namespace Workers;

use Models\{
    SystemConfiguration,
    AdminSettlement
};
use Co\System;
use Exception;

class ProduceAdminBetSettlement
{
    public static function handle($dbPool, $swooleTable)
    {

        try {
            $settlementTime            = 0;
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
            logger('error', 'bet-settlement', 'Something went wrong', (array) $e);
        }
    }

    public static function logicHandler($connection, $swooleTable, $settlementTime, $refreshDBInterval, &$systemConfigurationsTimer)
    {
        if (empty($refreshDBInterval)) {
            $settlementTime++;
            logger('error', 'bet-settlement', 'No refresh DB interval');
            return $settlementTime;
        }

        if ($settlementTime % $refreshDBInterval == 0) {
            $settlementTimerResult = SystemConfiguration::getSettlementRequestTimer($connection);
            $settlementTimer = $connection->fetchArray($settlementTimerResult);
            if ($settlementTimer) {
                $systemConfigurationsTimer = $settlementTimer['value'];
            }
        }


        if ($systemConfigurationsTimer) {
            if ($settlementTime % (int) $systemConfigurationsTimer == 0) {
                self::send2KafkaUnprocessedSettlement($connection);
            }
            $settlementTime++;
            return $settlementTime;
        }
    }

    public static function send2KafkaUnprocessedSettlement($connection)
    {
        $topic = getenv('KAFKA_SCRAPING_SETTLEMENTS', 'SCRAPING-SETTLEMENTS');
        $result = AdminSettlement::fetchUnprocessedPayloads($connection);
        while ($record = $connection->fetchAssoc($result)) {
            $payload = getPayloadPart('settlement', 'transform');
            $payload['data'] = [json_decode(unserialize($record['payload']), true)];

            kafkaPush($topic, $payload, $payload['request_uid']);
            AdminSettlement::updateToProcessed($connection, $record['id']);

            logger('error', 'bet-settlement', 'Payload processed', $payload);
        }
    }
}
