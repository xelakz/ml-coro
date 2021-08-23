<?php

use App\Facades\SwooleStats;
use RdKafka\{KafkaConsumer, Conf, Consumer, TopicConf};
use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\Connectors\CoroutinePostgreSQLConnector;
use Swoole\Coroutine\PostgreSQL;
use Workers\{ProcessOdds, ProcessEvent};

require_once __DIR__ . '/../Bootstrap.php';

function makeConsumer()
{
    global $swooleTable;

    // LOW LEVEL CONSUMER
    $topics = [
        getenv('KAFKA_SCRAPING_ODDS', 'SCRAPING-ODDS'),
        getenv('KAFKA_SCRAPING_EVENTS', 'SCRAPING-PROVIDER-EVENTS')
    ];

    if ($swooleTable['enabledProviders']->count() > 0) {
        foreach ($swooleTable['enabledProviders'] as $k => $st) {
            $topics[] = $k . '_req';
        }
    }

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

function preProcess()
{
    global $dbPool;

    $connection = $dbPool->borrow();

    PreProcess::init($connection);
    PreProcess::loadLeagues();
    PreProcess::loadTeams();
    PreProcess::loadEvents();
    PreProcess::loadEventMarkets();

    PreProcess::loadEnabledProviders();
    PreProcess::loadEnabledSports();
    PreProcess::loadSportsOddTypes();
    preProcess::loadMaintenance();
    preProcess::loadSystemConfig();

    $dbPool->return($connection);
}

function reactor($queue)
{
    global $count;
    global $activeProcesses;
    global $oddsEventQueue;

    while (true) {
        $message = $queue->consume(0);
        if ($message) {
            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    logger('info', 'odds-events-reactor', 'consuming...', (array) $message);
                    if ($message->payload) {
                        $key = getPipe(getenv('ODDS_EVENTS_PROCESSES_NUMBER', 1));

                        $payload = json_decode($message->payload, true);
                        switch ($payload['command']) {
                            case 'odd':
                                if ($payload['sub_command'] == 'transform') {
                                    oddHandler($payload, $message->offset);
                                } else if ($payload['sub_command'] == 'scrape') {
                                    if (!empty($oddsEventQueue[$payload['data']['provider'] . ':' . $payload['data']['schedule'] . ':' . $payload['data']['sport']]) && 
                                    count($oddsEventQueue[$payload['data']['provider'] . ':' . $payload['data']['schedule'] . ':' . $payload['data']['sport']]) % 10 == 0) {
                                        array_shift($oddsEventQueue[$payload['data']['provider'] . ':' . $payload['data']['schedule'] . ':' . $payload['data']['sport']]);
                                    }
                                    $oddsEventQueue[$payload['data']['provider'] . ':' . $payload['data']['schedule'] . ':' . $payload['data']['sport']][] = $payload['request_uid'];
                                    logger('info', 'odds-events-reactor', 'Request UIDs', $oddsEventQueue);
                                    freeUpProcess();
                                }
                                
                                break;
                            case 'event':
                            default:
                                // go("eventHandler", $key, $payload, $message->offset);
                                eventHandler($payload, $message->offset);
                                break;
                        }

                        $activeProcesses++;
                        $count++;
                    }
                    break;
                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    echo "No more messages; will wait for more\n";
                    break;
                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    // echo "Timed out\n";
                    break;
                default:
                    throw new Exception($message->errstr(), $message->err);
                    break;
            }
        } else {
            Co\System::sleep(0.001);
        }
    }
}

function oddHandler($message, $offset)
{
    global $swooleTable;
    global $dbPool;
    global $oddsEventQueue;

    $start = microtime(true);

    try {

        if (!is_array($message["data"]) || empty($message["data"])) {
            logger('info', 'odds-events-reactor', 'Validation Error: Invalid Payload', (array) $message);
            $statsArray = [
                "type"        => "odds",
                "status"      => 'PAYLOAD_ERROR',
                "time"        => microtime(true) - $start,
                "request_uid" => $message["request_uid"],
                "request_ts"  => $message["request_ts"],
                "offset"      => $offset,
            ];

            addStats($statsArray);
            return;
        }

        if (!$swooleTable['enabledSports']->exists($message["data"]["sport"])) {
            logger('info', 'odds-events-reactor', 'Validation Error: Sport is inactive', (array) $message);
            $statsArray = [
                "type"        => "odds",
                "status"      => 'SPORT_ERROR',
                "time"        => microtime(true) - $start,
                "request_uid" => $message["request_uid"],
                "request_ts"  => $message["request_ts"],
                "offset"      => $offset,
            ];

            addStats($statsArray);
            return;
        }

        if (!$swooleTable['enabledProviders']->exists($message["data"]["provider"])) {
            logger('info', 'odds-events-reactor', 'Validation Error: Provider is inactive', (array) $message);
            $statsArray = [
                "type"        => "odds",
                "status"      => 'PROVIDER_ERROR',
                "time"        => microtime(true) - $start,
                "request_uid" => $message["request_uid"],
                "request_ts"  => $message["request_ts"],
                "offset"      => $offset,
            ];

            addStats($statsArray);
            return;
        }

        if (empty($oddsEventQueue[$message['data']['provider'] . ':' . $message['data']['schedule'] . ':' . $message['data']['sport']]) || !in_array($message['request_uid'], $oddsEventQueue[$message['data']['provider'] . ':' . $message['data']['schedule'] . ':' . $message['data']['sport']])) {
            logger('info', 'odds-events-reactor', 'Validation Error: Request is old', (array) $message);
            $statsArray = [
                "type"        => "odds",
                "status"      => 'TIMESTAMP_ERROR',
                "time"        => microtime(true) - $start,
                "request_uid" => $message["request_uid"],
                "request_ts"  => $message["request_ts"],
                "offset"      => $offset,
            ];

            addStats($statsArray);
            return;
        }

        $toHashMessage = $message["data"];
        unset($toHashMessage['runningtime'], $toHashMessage['id']);
        $caching = 'odds-' . md5(json_encode((array) $toHashMessage));

        if (!empty($toHashMessage['events'][0]['eventId'])) {
            $eventId = $toHashMessage['events'][0]['eventId'];

            if ($swooleTable['eventOddsHash']->exists($eventId) && $swooleTable['eventOddsHash'][$eventId]['hash'] == $caching) {
                logger('info', 'odds-events-reactor', 'Validation Error: Hash data is the same as with the current hash data', (array) $message);
                $statsArray = [
                    "type"        => "odds",
                    "status"      => 'HASH_ERROR',
                    "time"        => microtime(true) - $start,
                    "request_uid" => $message["request_uid"],
                    "request_ts"  => $message["request_ts"],
                    "offset"      => $offset,
                ];
    
                addStats($statsArray);
                return;
            }
        }
        $swooleTable['eventOddsHash'][$eventId] = ['hash' => $caching];

        go(function () use (&$dbPool, $swooleTable, $message, $offset) {
            $maxReconnection = 20;
            $connectionCount = 0;
            do {
                try {
                    $connected = true;
                    $connection = $dbPool->borrow();

                    ProcessOdds::handle($connection, $swooleTable, $message, $offset);
                    $dbPool->return($connection);
                } catch(BorrowConnectionTimeoutException $be) {
                    $connected = false;
                } catch (Exception $e) {
                    $dbPool->return($connection);
                }
                $connectionCount++;
            } while (!$connected && $connectionCount < $maxReconnection);
        });
    } catch (Exception $e) {
        logger('info', 'odds-events-reactor', 'Exception Error', (array) $e);
    } finally {
        freeUpProcess();
        return true;
    }
}

function eventHandler($message, $offset)
{
    global $swooleTable;
    global $dbPool;
    global $oddsEventQueue;

    $start = microtime(true);

    try {
        if (!is_array($message["data"]) || empty($message["data"])) {
            logger('info', 'odds-events-reactor', 'Validation Error: Data should be valid', (array) $message);
            $statsArray = [
                "type"        => "events",
                "status"      => 'PAYLOAD_ERROR',
                "time"        => microtime(true) - $start,
                "request_uid" => $message["request_uid"],
                "request_ts"  => $message["request_ts"],
                "offset"      => $offset,
            ];

            addStats($statsArray);
            return;
        }

        if (!$swooleTable['enabledSports']->exists($message["data"]["sport"])) {
            logger('info', 'odds-events-reactor', 'Validation Error: Sport is inactive', (array) $message);
            $statsArray = [
                "type"        => "events",
                "status"      => 'SPORT_ERROR',
                "time"        => microtime(true) - $start,
                "request_uid" => $message["request_uid"],
                "request_ts"  => $message["request_ts"],
                "offset"      => $offset,
            ];

            addStats($statsArray);
            return;
        }

        if (!$swooleTable['enabledProviders']->exists($message["data"]["provider"])) {
            logger('info', 'odds-events-reactor', 'Validation Error: Provider is inactive', (array) $message);
            $statsArray = [
                "type"        => "events",
                "status"      => 'PROVIDER_ERROR',
                "time"        => microtime(true) - $start,
                "request_uid" => $message["request_uid"],
                "request_ts"  => $message["request_ts"],
                "offset"      => $offset,
            ];

            addStats($statsArray);
            return;
        }

        if (empty($oddsEventQueue[$message['data']['provider'] . ':' . $message['data']['schedule'] . ':' . $message['data']['sport']]) || !in_array($message['request_uid'], $oddsEventQueue[$message['data']['provider'] . ':' . $message['data']['schedule'] . ':' . $message['data']['sport']])) {
            logger('info', 'odds-events-reactor', 'Validation Error: Request is old', (array) $message);
            $statsArray = [
                "type"        => "events",
                "status"      => 'TIMESTAMP_ERROR',
                "time"        => microtime(true) - $start,
                "request_uid" => $message["request_uid"],
                "request_ts"  => $message["request_ts"],
                "offset"      => $offset,
            ];

            addStats($statsArray);
            return;
        }

        go(function () use (&$dbPool, $swooleTable, $message, $offset) {
            $maxReconnection = 20;
            $connectionCount = 0;
            do {
                try {
                    $connected = true;
                    $connection = $dbPool->borrow();

                    ProcessEvent::handle($connection, $swooleTable, $message, $offset);
                    $dbPool->return($connection);
                } catch(BorrowConnectionTimeoutException $be) {
                    $connected = false;
                } catch (Exception $e) {
                    $dbPool->return($connection);
                }
                $connectionCount++;
            } while (!$connected && $connectionCount < $maxReconnection);
        });
    } catch (Exception $e) {
        logger('info', 'odds-events-reactor', 'Exception Error', (array) $e);
    } finally {
        freeUpProcess();
        return true;
    }


}

function checkTableCounts() 
{
	global $swooleTable;

    logger('info', 'odds-events-reactor', 'Count Leagues:' . $swooleTable['leagues']->count());
    logger('info', 'odds-events-reactor', 'Count Teams:' . $swooleTable['teams']->count());
    logger('info', 'odds-events-reactor', 'Count Events:' . $swooleTable['events']->count());
    logger('info', 'odds-events-reactor', 'Count Event Markets:' . $swooleTable['eventMarkets']->count());
    logger('info', 'odds-events-reactor', 'Count Providers:' . $swooleTable['enabledProviders']->count());
    logger('info', 'odds-events-reactor', 'Count Sports:' . $swooleTable['enabledSports']->count());
    logger('info', 'odds-events-reactor', 'Count Sport Odd Types:' . $swooleTable['sportsOddTypes']->count());
    logger('info', 'odds-events-reactor', 'Count Maintenance:' . $swooleTable['maintenance']->count());

    logger('info', 'odds-events-reactor', 'Count Stats Count Events Per Second:' . $swooleTable['statsCountEventsPerSecond']->count());
    logger('info', 'odds-events-reactor', 'Count Stats Time Events Per Second:' . $swooleTable['statsTimeEventsPerSecond']->count());
    logger('info', 'odds-events-reactor', 'Count Stats Count Odds Per Second:' . $swooleTable['statsCountOddsPerSecond']->count());
    logger('info', 'odds-events-reactor', 'Count Stats Time Odds Per Second:' . $swooleTable['statsTimeOddsPerSecond']->count());
}

function clearHashData() 
{
    global $swooleTable;

    foreach ($swooleTable['eventOddsHash'] as $k => $eoh) {
        $swooleTable['eventOddsHash']->del($k);
    }
}

$activeProcesses = 0;
$count           = 0;
$dbPool          = null;
$oddsEventQueue  = [];

Co\run(function () {
    global $dbPool;

    // Swoole\Timer::tick(1000, "checkRate");
    $dbPool = databaseConnectionPool(true);

    $dbPool->init();
    defer(function () use ($dbPool) {
        logger('info', 'odds-events-reactor', "Closing connection pool");
        $dbPool->close();
    });

    preProcess();
    $queue = makeConsumer();

    Swoole\Timer::tick(10000, "checkTableCounts");
    Swoole\Timer::tick(360000, "clearHashData");
    Swoole\Timer::tick(10000, "swooleStats", 'odds');
    Swoole\Timer::tick(10000, "swooleStats", 'events');
    reactor($queue, $dbPool);
});