<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/Classes/DotEnv.php';
require_once __DIR__ . '/Classes/SwooleTable.php';
require_once __DIR__ . '/Classes/PreProcess.php';
require_once __DIR__ . '/Classes/WalletService.php';

use DevCoder\DotEnv;
use RdKafka\{Conf, Producer};
use Classes\WalletService;

(new DotEnv(__DIR__ . '/.env'))->load();

$log = null;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/config.php';

instantiateLogger();


$producerConf = new Conf();
$producerConf->set('metadata.broker.list', getenv('KAFKA_BROKERS', 'kafka:9092'));
$kafkaProducer    = new Producer($producerConf);

$swooleTable = new SwooleTable;
foreach ($config['swoole_tables'] as $table => $details) {
    $swooleTable->createTable($table, $details);
};

$swooleTable = $swooleTable->table;

$wallet = new WalletService(
    getenv('WALLET_URL', '127.0.0.1'),
    getenv('WALLET_CLIENT_ID', 'wallet-id'),
    getenv('WALLET_CLIENT_SECRET', 'wallet-secret')
);

// $swooleStats = SwooleStats::getInstance();

$rootDir = __DIR__;