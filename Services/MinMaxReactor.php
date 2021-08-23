<?php
use Smf\ConnectionPool\ConnectionPool;
use Workers\ProcessMinMax;
use Models\Provider;

require_once __DIR__ . '/../Bootstrap.php';


$dbPool = null;
Co\run(function () use ($queue) {
    global $dbPool;
    $dbPool = databaseConnectionPool();
    $dbPool->init();
    defer(function () use ($dbPool) {
        logger('info', 'app', "Closing connection pool");
        echo "Closing connection pool\n";
        $dbPool->close();
    });

    $schedules = array(
        "inplay" => getenv('MINMAX_FREQUENCY_INPLAY'), 
        "today" => getenv('MINMAX_FREQUENCY_TODAY'),
        "early" => getenv('MINMAX_FREQUENCY_EARLY')
    );

    $connection = $dbPool->borrow();
    $providerQuery = Provider::getActiveProviders($connection);
    $providerArray = $connection->fetchAll($providerQuery);
    foreach($providerArray as $provider)
    {
        $providers[$provider['id']] = strtolower($provider['alias']);
    }
    $dbPool->return($connection);

    foreach($schedules as $schedule=>$interval) {
        go(function () use ($dbPool, $providers, $schedule, $interval) {
            while (true) {
                try {
                    $connection = $dbPool->borrow();
                    ProcessMinMax::handle($connection, $providers, $schedule);
                    $dbPool->return($connection);
                } catch (Exception $e) {
                    echo $e->getMessage();
                }
                Co::sleep($interval);
            }
        });
    }
});