<?php

use RdKafka\{KafkaConsumer, Conf, Consumer, TopicConf};
use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\Connectors\CoroutinePostgreSQLConnector;
use Swoole\Coroutine\PostgreSQL;
use Workers\ProcessMaintenance;

require_once __DIR__ . '/../Bootstrap.php';

function makeConsumer() 
{
    // LOW LEVEL CONSUMER
    $topics = [
        getenv('KAFKA_SCRAPING_MAINTENANCE', 'PROVIDER-MAINTENANCE'),
    ];

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
        logger('info','app',  "Setting up " . $t);
        echo "Setting up " . $t . "\n";
		$topic->consumeQueueStart(0, RD_KAFKA_OFFSET_STORED,$queue);
	}

    return $queue;
}

function preProcess()
{
    global $dbPool;

    $connection = $dbPool->borrow();

    PreProcess::init($connection);
    PreProcess::loadEnabledProviders();
    preProcess::loadMaintenance();

    $dbPool->return($connection);
    
}

function reactor($queue) {
	global $count;
    global $activeProcesses;

	while (true) {
		$message = $queue->consume(0);
		if ($message) {
			switch ($message->err) {
				case RD_KAFKA_RESP_ERR_NO_ERROR:
                    logger('info','maintenance-reactor', 'consuming...', (array) $message);
					if ($message->payload) {
                        getPipe(getenv('MAINTENANCE_PROCESSES_NUMBER', 1));

                        $payload = json_decode($message->payload, true);
                        maintenanceHandler($payload, $message->offset);
						
						$activeProcesses++;
						$count++;
                    }
					break;
				case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    logger('info','maintenance-reactor', "No more messages; will wait for more");
					echo "No more messages; will wait for more\n";
					break;
				case RD_KAFKA_RESP_ERR__TIMED_OUT:
					// Kafka message timed out. Ignore
					break;
				default:
                    logger('info','maintenance-reactor', $message->errstr(), $message->err);
					throw new Exception($message->errstr(), $message->err);
					break;
			}
		} else {
			Co\System::sleep(0.001);
		}
	}
}

function maintenanceHandler($message, $offset)
{
    global $swooleTable;
    global $dbPool;

    try {
        if (empty($message['data'])) {
            logger('info', 'Invalid Payload', $message);
            return;
        }

        if (!$swooleTable['enabledProviders']->exists($message['data']['provider'])) {
            logger('info', 'Invalid Provider', $message);
            return;
        }

        go(function() use($dbPool, $swooleTable, $message, $offset) {
            try {
                $connection = $dbPool->borrow();

                ProcessMaintenance::handle($connection, $swooleTable, $message, $offset);

                $dbPool->return($connection);
            } catch (Exception $e) {
                echo $e->getMessage();
            }
            
        });
    } catch (Exception $e) {
        echo $e->getMessage();
    } finally {
        freeUpProcess();
        return true;
    }
}

$activeProcesses = 0;
$queue           = makeConsumer();
$dbPool          = null;
makeProcess();

Co\run(function() use ($queue, $activeProcesses) {
    global $dbPool;

	$count = 0;

    // Swoole\Timer::tick(1000, "checkRate");
    $dbPool = databaseConnectionPool();

    $dbPool->init();
    defer(function () use ($dbPool) {
        logger('info','maintenance-reactor',  "Closing connection pool");
        echo "Closing connection pool\n";
        $dbPool->close();
    });

    preProcess();
    reactor($queue);
});