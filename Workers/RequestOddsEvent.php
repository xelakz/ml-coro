<?php

namespace Workers;

use Models\SystemConfiguration;
use Carbon\Carbon;
use Co\System;

class RequestOddsEvent
{
    private static $kafkaTopic;
    private static $scheduleMapping;
    private static $scheduleMappingField;
    private static $systemConfigResult;
    private static $scheduleType;

    public static function init()
    {
        global $defaults;

        self::$kafkaTopic           = getenv('KAFKA_SCRAPE_REQUEST_POSTFIX', '_req');
        self::$scheduleMapping      = $defaults['scraping']['scheduleMapping'];
        self::$scheduleMappingField = $defaults['scraping']['scheduleMappingField'];
    }

    public static function handle($dbPool, $swooleTable)
    {
        logger('info', 'app', 'Starting Request Odds Event');

        self::init();

        try {
            $scrapeProduceTime       = 0;
            $connection              = $dbPool->borrow();
            $refreshDBIntervalResult = SystemConfiguration::getOddsEventRequestInterval($connection);
            $refreshDBInterval       = $connection->fetchArray($refreshDBIntervalResult);
            $dbPool->return($connection);

            while (true) {
                $connection = $dbPool->borrow();
                $response   = self::logicHandler($connection, $swooleTable, $scrapeProduceTime, $refreshDBInterval['value']);
                $dbPool->return($connection);
                if ($response) {
                    $scrapeProduceTime = $response;
                }

                System::sleep(1);
            }
        } catch (Exception $e) {
            logger('error', 'app', 'Something went wrong', $e);
        }
    }

    public static function logicHandler($connection, $swooleTable, $scrapeProduceTime, $refreshDBInterval)
    {
        if (empty($refreshDBInterval)) {
            $scrapeProduceTime++;
            logger('error', 'app', 'No refresh DB interval');
            return $scrapeProduceTime;
        }
        if ($scrapeProduceTime % $refreshDBInterval == 0) {
            self::refreshDbConfig($connection);
            logger('info', 'app', 'refresh request interval');
        }

        $request = [];
        $config = $connection->fetchAll(self::$systemConfigResult);
        foreach (self::$scheduleMapping as $key => $scheduleType) {
            foreach ($config as $conf) {
                if (in_array($conf['type'], $scheduleType)) {
                    $request[$key][self::$scheduleMappingField[$conf['type']]] = $conf['value'];
                }
            }
        }

        foreach ($request as $key => $req) {
            self::$scheduleType = $key;
            if ($scrapeProduceTime % $req['timer'] == 0) {
                for ($interval = 0; $interval < $req['requestNumber']; $interval++) {
                    self::sendPayload($swooleTable);
                    if (!empty($req['requestInterval'])) {
                        System::sleep($req['requestInterval']);
                    }
                }
            }
        }

        $scrapeProduceTime++;
        return $scrapeProduceTime;
    }

    private static function refreshDbConfig($connection)
    {

        $whereIn = [];
        foreach (self::$scheduleMapping as $scheduleType) {
            foreach ($scheduleType as $where) {
                $whereIn[] = $where;
            }
        }
        self::$systemConfigResult = SystemConfiguration::getOddsEventScrapingConfigs($connection, $whereIn);
    }

    private static function sendPayload($swooleTable)
    {
        foreach ($swooleTable['enabledProviders'] as $key => $provider) {
            foreach ($swooleTable['enabledSports'] as $sportId => $sport) {
                $payload         = getPayloadPart('odd', 'scrape');
                $payload['data'] = [
                    'provider' => strtolower($key),
                    'schedule' => self::$scheduleType,
                    'sport'    => $sportId
                ];

                // publish message to kafka
                kafkaPush(strtolower($key) . self::$kafkaTopic, $payload, $payload['request_uid']);

                logger('info', 'app', strtolower($key) . self::$kafkaTopic . " Payload Sent", $payload);
            }
        }
    }
}
