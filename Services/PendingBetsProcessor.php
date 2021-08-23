<?php

use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\Connectors\CoroutinePostgreSQLConnector;
use Swoole\Coroutine\PostgreSQL;
use Workers\{
    ProcessOldPendingBets
};

require_once __DIR__ . '/../Bootstrap.php';

function preProcess()
{
    global $dbPool;

    $connection = $dbPool->borrow();

    PreProcess::init($connection);
    PreProcess::loadOldPendingBets();

    $dbPool->return($connection);
}

function resetProcess()
{
    global $dbPool;
    
    $connection = $dbPool->borrow();

    PreProcess::loadOldPendingBets();

    $dbPool->return($connection);

    try {
        setWalletClients();
    } catch (Exception $e) {
        logger('error', 'bets-processor', 'Error', (array) $e);
    }
}

$dbPool = null;
Co\run(function () use ($queue) {
    global $dbPool;
    global $swooleTable;

    $dbPool = databaseConnectionPool();

    $dbPool->init();
    defer(function () use ($dbPool) {
        logger('info', 'bets-processor', "Closing connection pool");
        echo "Closing connection pool\n";
        $dbPool->close();
    });

    preProcess();

    Swoole\Timer::tick(10000, "resetProcess");

    /**
     * Co-Routine Asynchronous Worker for handling
     * Leagues Auto-matching.
     */
    go(function () use ($dbPool, $swooleTable) {
        while (true) {
            try {
                $connection = $dbPool->borrow();
                ProcessOldPendingBets::handle($connection, $swooleTable);
                $dbPool->return($connection);
                Co\System::sleep(0.001);
            } catch (Exception $e) {
                echo $e->getMessage();
            }
            Co\System::sleep(10);
        }
    });

});