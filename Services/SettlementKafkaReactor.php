<?php

use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\Connectors\CoroutinePostgreSQLConnector;
use Swoole\Coroutine\PostgreSQL;
use Workers\ProcessSettlement;
use Carbon\Carbon;

require_once __DIR__ . '/../Bootstrap.php';

function preProcess()
{
    global $dbPool;

    $connection = $dbPool->borrow();

    PreProcess::init($connection);
    PreProcess::loadEnabledProviders();
    preProcess::loadActiveOrders();

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
                    logger('info','settlements-reactor', 'consuming...', (array) $message);
					if ($message->payload) {
                        getPipe(getenv('SETTLEMENT_PROCESSES_NUMBER', 1));

                        $payload = json_decode($message->payload, true);
                        settlementeHandler($payload, $message->offset);
						
						$activeProcesses++;
						$count++;
                    }
					break;
				case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    logger('info','settlements-reactor', "No more messages; will wait for more");
					echo "No more messages; will wait for more\n";
					break;
				case RD_KAFKA_RESP_ERR__TIMED_OUT:
					// Kafka message timed out. Ignore
					break;
				default:
                    logger('info','settlements-reactor', $message->errstr(), $message->err);
					throw new Exception($message->errstr(), $message->err);
					break;
			}
		} else {
			Co\System::sleep(0.001);
		}
	}
}

function settlementeHandler($message, $offset)
{
    global $swooleTable;
    global $dbPool;
    global $wallet;
    global $getAccessToken;
    global $countToExpiration;

    try {
        $userWalletService = $wallet;
        $expiresIn         = $getAccessToken->data->expires_in;
        $refreshToken      = $getAccessToken->data->refresh_token;

        $timestampNow = Carbon::now()->timestamp;
        if ($countToExpiration + $expiresIn <= $timestampNow) {
            $getRefreshToken         = $userWalletService->refreshToken($refreshToken);
            
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

        $previousTS = $swooleTable['timestamps']['settlements']["ts"];
        $messageTS  = $message["request_ts"];
        if ($messageTS < $previousTS) {
            logger('info', 'settlements-reactor', 'Timestamp is old', $message);

            return;
        }
        $swooleTable['timestamps']['settlements']['ts'] = $messageTS;

        if (empty($message['data'])) {
            logger('info', 'settlements-reactor', 'Empty Data', $message);
            return;
        }

        go(function() use($dbPool, $swooleTable, $message, $offset) {
            try {
                $connection = $dbPool->borrow();

                ProcessSettlement::handle($connection, $swooleTable, $message, $offset);

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

function reloadActiveOrders(){
    PreProcess::loadActiveOrders();
}

$activeProcesses   = 0;
$topics            = [
                        getenv('KAFKA_SCRAPING_SETTLEMENTS', 'SCRAPING-SETTLEMENTS'),
                     ];
$queue             = createConsumer($topics);
$dbPool            = null;
$getAccessToken    = $wallet->getAccessToken('wallet');

$countToExpiration = Carbon::now()->timestamp;
makeProcess();

Co\run(function() use ($queue, $activeProcesses) {
    global $dbPool;
    global $wallet;
    global $http;

	$count = 0;

    $dbPool = databaseConnectionPool();

    $dbPool->init();
    defer(function () use ($dbPool) {
        logger('info','settlements-reactor',  "Closing connection pool");
        echo "Closing connection pool\n";
        $dbPool->close();
    });

    preProcess();
    Swoole\Timer::tick(300000, "reloadActiveOrders");  // 5 mins reload
    reactor($queue);
});