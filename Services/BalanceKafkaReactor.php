<?php

use Classes\WalletService;
use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\Connectors\CoroutinePostgreSQLConnector;
use Swoole\Coroutine\PostgreSQL;
use Workers\ProcessBalance;
use Carbon\Carbon;

require_once __DIR__ . '/../Bootstrap.php';

function preProcess()
{
    global $dbPool;

    $connection = $dbPool->borrow();

    PreProcess::init($connection);
    PreProcess::loadEnabledProviders();
    preProcess::loadEnabledProviderAccounts();

    $dbPool->return($connection);

}

function reactor($queue)
{
    global $count;
    global $activeProcesses;
    global $swooleTable;

    $walletProvider = [];
    foreach ($swooleTable['enabledProviders'] as $alias => $provider) {
        $walletProvider[$alias] = new WalletService(
            getenv('WALLET_URL', '127.0.0.1'),
            $alias,
            md5($alias)
        );

        $getAccessToken[$alias] = $walletProvider[$alias]->getAccessToken('wallet');
    }
    while (true) {
        $message = $queue->consume(0);
        if ($message) {
            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    logger('info', 'balance-reactor', 'consuming...', (array) $message);
                    if ($message->payload) {
                        getPipe(getenv('BALANCE_PROCESSES_NUMBER', 1));

                        $payload = json_decode($message->payload, true);
                        balanceHandler($walletProvider, $getAccessToken, $payload, $message->offset);

                        $activeProcesses++;
                        $count++;
                    }
                    break;
                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    logger('info', 'balance-reactor', "No more messages; will wait for more");
                    echo "No more messages; will wait for more\n";
                    break;
                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    // Kafka message timed out. Ignore
                    break;
                default:
                    logger('info', 'balance-reactor', $message->errstr(), $message->err);
                    throw new Exception($message->errstr(), $message->err);
                    break;
            }
        } else {
            Co\System::sleep(0.001);
        }
    }
}

function balanceHandler($walletProvider, $getAccessToken, $message, $offset)
{
    global $swooleTable;
    global $dbPool;

    global $countToExpiration;

    try {
        if (
            empty($message['data']['provider']) ||
            empty($message['data']['username']) ||
            empty($message['data']['available_balance']) ||
            empty($message['data']['currency'])
        ) {
            logger('info', 'balance-reactor', 'Validation Error: Invalid Payload', (array) $message);
            return;
        }

        if (!$swooleTable['enabledProviders']->exists($message['data']['provider'])) {
            logger('info', 'balance-reactor', 'Validation Error: Invalid Provider', (array) $message);
            return;
        }

        $previousTS = $swooleTable['timestamps']['balance:' . $message['data']['provider']]['ts'];
        $messageTS  = $message["request_ts"];
        if ($messageTS < $previousTS) {
            logger('info', 'balance-reactor', 'Validation Error: Timestamp is old', (array) $message);
            // return;
        }

        $swooleTable['timestamps']['balance:' . $message['data']['provider']]['ts'] = $messageTS;

        $providerWalletService = $walletProvider[$message['data']['provider']];
        $expiresIn             = $getAccessToken[$message['data']['provider']]->data->expires_in;
        $refreshToken          = $getAccessToken[$message['data']['provider']]->data->refresh_token;

        $timestampNow = Carbon::now()->timestamp;
        if ($countToExpiration + $expiresIn <= $timestampNow) {
            $getAccessToken[$message['data']['provider']] = $providerWalletService->refreshToken($refreshToken);

            $countToExpiration = $timestampNow;

            if ($getAccessToken[$message['data']['provider']]->status) {
                if ($countToExpiration + $getAccessToken[$message['data']['provider']]->data->expires_in <= $timestampNow) {
                    $getAccessToken[$message['data']['provider']] = $providerWalletService->getAccessToken('wallet');
                }
            } else {
                $getAccessToken[$message['data']['provider']] = $providerWalletService->getAccessToken('wallet');
            }
        }

        $accessToken = $getAccessToken[$message['data']['provider']]->data->access_token;
        $swooleTable['walletClients']->set($message['data']['provider'] . '-users', [
            'token' => $accessToken
        ]);
        go(function () use ($dbPool, $swooleTable, $providerWalletService, $message, $offset) {
            try {
                $connection = $dbPool->borrow();

                ProcessBalance::handle($connection, $swooleTable, $providerWalletService, $message, $offset);

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

$activeProcesses   = 0;
$topics            = [
    getenv('KAFKA_SCRAPING_BALANCES', 'BALANCE'),
];
$queue             = createConsumer($topics);
$dbPool            = null;
$countToExpiration = Carbon::now()->timestamp;
makeProcess();

function reloadEnabledProviderAccounts()
{
    global $dbPool;

    $connection = $dbPool->borrow();

    PreProcess::loadEnabledProviderAccounts();

    $dbPool->return($connection);
}

Co\run(function () use ($queue, $activeProcesses) {
    global $dbPool;
    global $wallet;
    global $http;

    $count = 0;

    // Swoole\Timer::tick(1000, "checkRate");
    $dbPool = databaseConnectionPool();

    $dbPool->init();
    defer(function () use ($dbPool) {
        logger('info', 'balance-reactor', "Closing connection pool");
        echo "Closing connection pool\n";
        $dbPool->close();
    });

    preProcess();
    Swoole\Timer::tick(300000, "reloadEnabledProviderAccounts");  // 5 mins reload
    reactor($queue);
});