<?php

use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\Connectors\CoroutinePostgreSQLConnector;
use Swoole\Coroutine\PostgreSQL;
use Workers\{
    RequestOddsEvent,
    RequestBalance,
    RequestSettlement,
    RequestSessionStatus,
    RequestSessionCategory,
    RequestOpenOrder,
    ProduceAdminBetSettlement,
};

require_once __DIR__ . '/../Bootstrap.php';
require_once __DIR__ . '/../Defaults/scraping.php';

function preProcess()
{
    global $dbPool;

    $connection = $dbPool->borrow();

    PreProcess::init($connection);
    PreProcess::loadEnabledProviders();
    PreProcess::loadEnabledSports();
    PreProcess::loadMaintenance();
    PreProcess::loadEnabledProviderAccounts();

    $dbPool->return($connection);
}


function reloadEnabledProviderAccounts()
{
    global $dbPool;

    $connection = $dbPool->borrow();

    PreProcess::loadEnabledProviderAccounts();

    $dbPool->return($connection);
}


$dbPool = null;
makeProcess();

Co\run(function () use ($queue, $activeProcesses) {
    global $dbPool;
    global $swooleTable;

    $dbPool = databaseConnectionPool();

    $dbPool->init();
    defer(function () use ($dbPool) {
        logger('info', 'app', "Closing connection pool");
        echo "Closing connection pool\n";
        $dbPool->close();
    });

    preProcess();
    Swoole\Timer::tick(300000, "reloadEnabledProviderAccounts");  // 5 mins reload

    go(function () use ($dbPool, $swooleTable) {
        RequestOddsEvent::handle($dbPool, $swooleTable);
    });

    go(function () use ($dbPool, $swooleTable) {
        RequestBalance::handle($dbPool, $swooleTable);
    });

    go(function () use ($dbPool, $swooleTable) {
        RequestSettlement::handle($dbPool, $swooleTable);
    });

    go(function () use ($dbPool, $swooleTable) {
        RequestSessionStatus::handle($dbPool, $swooleTable);
    });

    go(function () use ($dbPool, $swooleTable) {
        RequestSessionCategory::handle($dbPool, $swooleTable);
    });

    go(function () use ($dbPool, $swooleTable) {
        RequestOpenOrder::handle($dbPool, $swooleTable);
    });

    go(function () use ($dbPool, $swooleTable) {
        ProduceAdminBetSettlement::handle($dbPool, $swooleTable);
    });
});