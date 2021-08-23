<?php

use RdKafka\{KafkaConsumer, Conf, Consumer, TopicConf};
use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\Connectors\CoroutinePostgreSQLConnector;
use Swoole\Coroutine\PostgreSQL;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Ramsey\Uuid\Uuid;
use Carbon\Carbon;

function getPipe($maxProcessNumber = 1)
{
    global $activeProcesses;

    while (true) {
        if ($activeProcesses < $maxProcessNumber) {
            return true;
        }
        Co\System::sleep(0.001);
    }
}

function makeProcess()
{
    global $activeProcesses;

    $activeProcesses = 0;
}

function freeUpProcess()
{
    global $activeProcesses;

    $activeProcesses--;
}

function checkRate()
{
    global $count;
    global $activeProcesses;

    echo "\n" . $count . " .. [" . $activeProcesses . "]..\n";
    $count = 0;
}

function databaseConnectionPool($isOddEventService = false)
{
    global $config;

    $dbString = [];
    foreach ($config['database']['pgsql'] as $key => $db) {
        $dbString[] = $key . '=' . $db;
    }

    $connectionString = implode(' ', $dbString);

    if ($isOddEventService) {
        $config['database']['connection_pool']['maxActive'] = getenv('ODDS_EVENTS_PROCESSES_NUMBER', 100);
    }

    $pool = new ConnectionPool(
        $config['database']['connection_pool'],
        new CoroutinePostgreSQLConnector,
        [
            'connection_strings' => $connectionString
        ]
    );

    $pool->init();
    Co\System::sleep(0.5);
    defer(function () use ($pool) {
        echo "Closing connection pool\n";
        $pool->close();
    });

    return $pool;
}

function instantiateLogger()
{
    global $config;
    global $log;

    foreach ($config['logger'] as $key => $logConfig) {
        switch ($logConfig['level']) {
            case 'debug':
                $level = 100;
                break;
            case 'info':
                $level = 200;
                break;
            case 'error':
            default:
                $level = 400;
                break;
        }


        $log[$key] = new Logger($key);
        $log[$key]->pushHandler(new StreamHandler(__DIR__ . '/Log/' . $logConfig['name'], $level));

    }
}

function logger($type, $loggerName = 'app', ?string $data = null, $context = [], $extraData = [])
{
    global $log;

    $log[$loggerName]->{$type}($data, $context, $extraData);
}

function getMilliseconds()
{
    $mt = explode(' ', microtime());
    return bcadd($mt[1], $mt[0], 8);
}

function kafkaPush($kafkaTopic, $message, $key)
{
    global $kafkaProducer;

    try {
        $topic = $kafkaProducer->newTopic($kafkaTopic);
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, json_encode($message), $key);

        for ($flushRetries = 0; $flushRetries < 10; $flushRetries++) {
            $result = $kafkaProducer->flush(10000);
            if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
                break;
            }
        }
        Logger('info', 'app', 'Sending to Kafka Topic: ' . $kafkaTopic, $message);
    } catch (Exception $e) {
        Logger('error', 'app', 'Error', (array) $e);
    }
}

function createConsumer($topics)
{
    // LOW LEVEL CONSUMER
    $conf = new Conf();
    $conf->set('group.id', getenv('KAFKA_GROUP_ID', 'ml-db'));

    $rk = new Consumer($conf);
    $rk->addBrokers(getenv('KAFKA_BROKERS', 'kafka:9092'));

    $queue = $rk->newQueue();
    foreach ($topics as $t) {
        $topicConf = new TopicConf();
        $topicConf->set('auto.commit.interval.ms', 100);
        $topicConf->set('offset.store.method', 'broker');
        $topicConf->set('auto.offset.reset', 'latest');

        $topic = $rk->newTopic($t, $topicConf);
        logger('info', 'app', "Setting up " . $t);
        echo "Setting up " . $t . "\n";
        $topic->consumeQueueStart(0, RD_KAFKA_OFFSET_STORED, $queue);
    }

    return $queue;
}

function getPayloadPart($command, $subCommand)
{
    $requestId = (string) Uuid::uuid4();
    $requestTs = getMilliseconds();

    return [
        'request_uid' => $requestId,
        'request_ts'  => $requestTs,
        'command'     => $command,
        'sub_command' => $subCommand
    ];
}

function lockProcess(string $hash, string $type)
{
    global $swooleTable;

    do {
        Co\System::sleep(0.001);
    } while (
        $swooleTable['lockHashData']->exists($hash) &&
        $swooleTable['lockHashData'][$hash]['type'] == $type
    );
}

function swooleStats($timer, $which)
{
    global $swooleTable;
    if ($which == "events") {
        logger('info', 'stats', "**************** start swoole table dump ****************");
        foreach ($swooleTable['statsCountEventsPerSecond'] as $k => $s) {
            if ($k > (time() - 60)) {
                logger('info', 'stats', $k);
                logger('info', 'stats', json_encode($s));
                logger('info', 'stats', json_encode($swooleTable['statsTimeEventsPerSecond'][$k]));

                logger('info', 'stats',
                    'Event For second ['.$k.']: Total Messages: '.$s["total"].' ('.$swooleTable['statsTimeEventsPerSecond'][$k]["total"].
                    ') .. Processed: '.$s["processed"].' ('.$swooleTable['statsTimeEventsPerSecond'][$k]["processed"].') Errors: '.
                    (
                        $s["error"]+
                        $s["timestamp_error"]+
                        $s["payload_error"]+
                        $s["hash_error"]+
                        $s["inactiveSport_error"]+
                        $s["inactiveProvider_error"]
                    )
                   );
            } else {
                $swooleTable['statsCountEventsPerSecond']->del($k);
                $swooleTable['statsTimeEventsPerSecond']->del($k);
            }
        }
        logger('info', 'stats', "**************** end swoole table dump ****************");
    } else if ($which == 'odds') {
        logger('info', 'stats', "**************** start swoole table dump ****************");
        foreach ($swooleTable['statsCountOddsPerSecond'] as $k => $s) {
            if ($k > (time() - 30)) {
                logger('info', 'stats',
                    'Odds For second ['.$k.']: Total Messages: '.$s["total"].' ('.$swooleTable['statsTimeOddsPerSecond'][$k]["total"].
                    ') .. Processed: '.$s["processed"].' ('.$swooleTable['statsTimeOddsPerSecond'][$k]["processed"].') Errors: '.
                    (
                        $s["error"]+
                        $s["timestamp_error"]+
                        $s["payload_error"]+
                        $s["hash_error"]+
                        $s["inactiveSport_error"]+
                        $s["inactiveProvider_error"]
                    )
                   );
            } else {
                $swooleTable['statsCountOddsPerSecond']->del($k);
                $swooleTable['statsTimeOddsPerSecond']->del($k);
            }
        }
        logger('info', 'stats', "**************** end swoole table dump ****************");
    }
}

function addStats($what)
{
    global $swooleTable;
    
    $type = $what["type"];

    if ($type == "events") {
        $statsTable = $swooleTable['statsCountEventsPerSecond'];
        $timeTable  = $swooleTable['statsTimeEventsPerSecond'];
        $now        = time();
    } else if ($type == "odds") {
        $statsTable = $swooleTable['statsCountOddsPerSecond'];
        $timeTable  = $swooleTable['statsTimeOddsPerSecond'];
        $now        = time();
    }

    if (in_array($type, ['odds', 'events'])) {
        if (!$statsTable->exists($now)) {
            $statsTable->set($now, [
                "total"            => 0,
                "processed"        => 0,
                "error"            => 0,
                "timestamp_error"        => 0,
                "payload_error"          => 0,
                "hash_error"             => 0,
                "inactiveSport_error"    => 0,
                "inactiveProvider_error" => 0,
            ]);
        }
        if (!$timeTable->exists($now)) {
            $timeTable->set($now, [
                "total"            => 0,
                "processed"        => 0,
                "error"            => 0,
                "timestamp_error"        => 0,
                "payload_error"          => 0,
                "hash_error"             => 0,
                "inactiveSport_error"    => 0,
                "inactiveProvider_error" => 0,
            ]);
        }


        $totalCount               = $statsTable[$now]["total"];
        $avgTime                  = $timeTable[$now]["total"];
        $allTime                  = ($avgTime * $totalCount) + $what["time"];
        $timeTable[$now]["total"] = $allTime / ($totalCount + 1);
        $statsTable->incr($now, "total", 1);
        switch ($what["status"]) {
            case 'NO_ERROR':
                $avgTime                      = $timeTable[$now]["processed"];
                $allTime                      = ($avgTime * $totalCount) + $what["time"];
                $timeTable[$now]["processed"] = $allTime / ($totalCount + 1);
                $statsTable->incr($now, "processed", 1);
                break;
            case 'TIMESTAMP_ERROR':
                $avgTime                  = $timeTable[$now]["timestamp_error"];
                $allTime                  = ($avgTime * $totalCount) + $what["time"];
                $timeTable[$now]["timestamp_error"] = $allTime / ($totalCount + 1);
                $statsTable->incr($now, "timestamp_error", 1);
                break;
            case 'PAYLOAD_ERROR':
                $avgTime                  = $timeTable[$now]["payload_error"];
                $allTime                  = ($avgTime * $totalCount) + $what["time"];
                $timeTable[$now]["payload_error"] = $allTime / ($totalCount + 1);
                $statsTable->incr($now, "payload_error", 1);
                break;
            case 'HASH_ERROR':
                $avgTime                  = $timeTable[$now]["hash_error"];
                $allTime                  = ($avgTime * $totalCount) + $what["time"];
                $timeTable[$now]["hash_error"] = $allTime / ($totalCount + 1);
                $statsTable->incr($now, "hash_error", 1);
                break;
            case 'ERROR':
                $avgTime                  = $timeTable[$now]["error"];
                $allTime                  = ($avgTime * $totalCount) + $what["time"];
                $timeTable[$now]["error"] = $allTime / ($totalCount + 1);
                $statsTable->incr($now, "error", 1);
                break;
            case 'SPORT_ERROR':
                $avgTime                          = $timeTable[$now]["inactiveSport_error"];
                $allTime                          = ($avgTime * $totalCount) + $what["time"];
                $timeTable[$now]["inactiveSport_error"] = $allTime / ($totalCount + 1);
                $statsTable->incr($now, "inactiveSport_error", 1);
                break;
            case 'PROVIDER_ERROR':
                $avgTime                             = $timeTable[$now]["inactiveProvider_error"];
                $allTime                             = ($avgTime * $totalCount) + $what["time"];
                $timeTable[$now]["inactiveProvider_error"] = $allTime / ($totalCount + 1);
                $statsTable->incr($now, "inactiveProvider_error", 1);
                break;
            default:
                logger('error', 'stats', 'Odds stats got a status that was NOT correct!');
                break;
        }
    }
}

function setWalletClients() {
    global $swooleTable;
    global $wallet;
    
    $userWalletService = $wallet;
    $getAccessToken    = $userWalletService->getAccessToken('wallet');
    $countToExpiration = Carbon::now()->timestamp;
    $expiresIn         = $getAccessToken->data->expires_in;
    $refreshToken      = $getAccessToken->data->refresh_token;

    $timestampNow = Carbon::now()->timestamp;
    if ($countToExpiration + $expiresIn <= $timestampNow) {
        $getRefreshToken   = $userWalletService->refreshToken($refreshToken);
        $countToExpiration = $timestampNow;

        if ($getRefreshToken->status) {
            if ($countToExpiration + $getRefreshToken->data->expires_in <= $timestampNow) {
                $getAccessToken = $userWalletService->getAccessToken('wallet');
            }
        } else {
            $getAccessToken = $userWalletService->getAccessToken('wallet');
        }
    }

    $accessToken = $getAccessToken->data->access_token;
    $swooleTable['walletClients']->set('ml-users', [
        'token' => $accessToken
    ]);
}

if (!function_exists('recheckStatus')) {
    function recheckStatus($status, $profitLoss, $actualToWin, $actualStake, $provider)
    {
        switch ($provider) {
            case 'hg':
                $decimalPlace = 1;
                // more logic here...
            break;

            case 'isn':
                $decimalPlace = 2;
                // more logic here...
            break;
            
            default:
                $decimalPlace = 2;
                // more logic here...
            break;
        }

        $multiplier  = 10 ** $decimalPlace;
        $actualToWin /= 2;
        $actualToWin = ceil($actualToWin * $multiplier) / $multiplier;

        if ((strtolower($status) == 'win') && ($profitLoss == $actualToWin)) {
            $status = 'HALF WIN';
        } else if ((strtolower($status) == 'lose') && ($actualStake / 2 == abs($profitLoss))) {
            $status = 'HALF LOSE';
        }

        return $status;
    }
}