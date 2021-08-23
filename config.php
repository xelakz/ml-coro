<?php

$config = [
    'database'      => [
        'connection_pool' => [
            'minActive'         => 10,
            'maxActive'         => 10,
            'maxWaitTime'       => 100,
            'maxIdleTime'       => 300,
            'idleCheckInterval' => 5,
        ],
        'pgsql'           => [
            'host'     => getenv('DB_HOST', '127.0.0.1'),
            'port'     => getenv('DB_PORT', 5432),
            'dbname'   => getenv('DB_DATABASE', 'multilinev2'),
            'user'     => getenv('DB_USERNAME', 'postgres'),
            'password' => getenv('DB_PASSWORD', 'password')
        ]
    ],
    'swoole_tables' => [
        'timestamps'       => [
            'size'   => 100,
            'column' => [
                ['name' => 'ts', 'type' => \Swoole\Table::TYPE_FLOAT],
            ],
        ],
        'eventOddsHash'    => [
            'size'   => 2000,
            'column' => [
                ['name' => 'hash', 'type' => \Swoole\Table::TYPE_STRING, "size" => 37],
            ],
        ],
        'leagues'          => [
            'size'   => 10000, //@TODO needs to be adjusted once additional provider comes in
            'column' => [
                ['name' => 'id', 'type' => \Swoole\Table::TYPE_INT],
                ['name' => 'name', 'type' => \Swoole\Table::TYPE_STRING, "size" => 80],
                ['name' => 'sport_id', 'type' => \Swoole\Table::TYPE_INT],
                ['name' => 'provider_id', 'type' => \Swoole\Table::TYPE_INT],
            ],
        ],
        'teams'            => [
            'size'   => 20000, //@TODO needs to be adjusted once additional provider comes in
            'column' => [
                ['name' => 'id', 'type' => \Swoole\Table::TYPE_INT],
                ['name' => 'name', 'type' => \Swoole\Table::TYPE_STRING, "size" => 80],
                ['name' => 'sport_id', 'type' => \Swoole\Table::TYPE_INT],
                ['name' => 'provider_id', 'type' => \Swoole\Table::TYPE_INT],
            ],
        ],
        'enabledSports'    => [
            'size'   => 100,
            'column' => [
                ['name' => 'value', 'type' => \Swoole\Table::TYPE_INT],
            ],
        ],
        'enabledProviders' => [
            'size'   => 100,
            'column' => [
                ['name' => 'value', 'type' => \Swoole\Table::TYPE_FLOAT],
                ['name' => 'currencyId', 'type' => \Swoole\Table::TYPE_INT],
                ["name" => "currency_code", "type" => \Swoole\Table::TYPE_STRING, "size" => 3],
            ],
        ],
        "events"           => [
            "size"   => 10000,
            "column" => [
                ["name" => "id", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "sport_id", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "provider_id", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "missing_count", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "league_id", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "team_home_id", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "team_away_id", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "ref_schedule", "type" => \Swoole\Table::TYPE_STRING, "size" => 20],
                ["name" => "game_schedule", "type" => \Swoole\Table::TYPE_STRING, "size" => 6],
                ["name" => "event_identifier", "type" => \Swoole\Table::TYPE_STRING, "size" => 30]
            ],
        ],
        "eventMarkets"     => [
            "size"   => 100000,
            "column" => [
                ["name" => "id", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "bet_identifier", "type" => \Swoole\Table::TYPE_STRING, "size" => 20],
                ["name" => "event_id", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "provider_id", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "odd_type_id", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "market_event_identifier", "type" => \Swoole\Table::TYPE_STRING, "size" => 30],
                ["name" => "market_flag", "type" => \Swoole\Table::TYPE_STRING, "size" => 5],
                ["name" => "is_main", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "odd_label", "type" => \Swoole\Table::TYPE_STRING, "size" => 10],
                ["name" => "odds", "type" => \Swoole\Table::TYPE_FLOAT],
            ],
        ],
        "eventMarketList"  => [
            "size"   => 20000,
            "column" => [
                ["name" => "marketIDs", "type" => \Swoole\Table::TYPE_STRING, "size" => 3000]
            ],
        ],
        "sportsOddTypes"   => [
            "size"   => 50,
            "column" => [
                ["name" => "value", "type" => \Swoole\Table::TYPE_INT]
            ],
        ],
        "activeOrders"     => [
            "size"   => 10000,
            "column" => [
                ["name" => "createdAt", "type" => \Swoole\Table::TYPE_STRING, "size" => 50],
                ["name" => "betId", "type" => \Swoole\Table::TYPE_STRING, "size" => 30],
                ["name" => "orderExpiry", "type" => \Swoole\Table::TYPE_STRING, "size" => 3],
                ["name" => "username", "type" => \Swoole\Table::TYPE_STRING, "size" => 20],
                ["name" => "userCurrencyId", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "status", "type" => \Swoole\Table::TYPE_STRING, "size" => 20],
            ],
        ],
        "oldPendingBets"     => [
            "size"   => 10000,
            "column" => [
                ['name' => 'provider_id', 'type' => \Swoole\Table::TYPE_INT],
                ['name' => 'sport_id', 'type' => \Swoole\Table::TYPE_INT],
                ["name" => "bet_id", "type" => \Swoole\Table::TYPE_STRING, "size" => 30],
                ["name" => "bet_selection", "type" => \Swoole\Table::TYPE_STRING, "size" => 255],
                ['name' => 'user_id', 'type' => \Swoole\Table::TYPE_INT],
            ],
        ],
        "providerAccounts" => [
            "size"   => 100,
            "column" => [
                ["name" => "provider_id", "type" => \Swoole\Table::TYPE_INT],
                ["name" => "username", "type" => \Swoole\Table::TYPE_STRING, "size" => 32],
                ["name" => "punter_percentage", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "credits", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "alias", "type" => \Swoole\Table::TYPE_STRING, "size" => 16],
                ['name' => 'type', 'type' => \Swoole\Table::TYPE_STRING, "size" => 50],
                ['name' => 'uuid', 'type' => \Swoole\Table::TYPE_STRING, "size" => 32],
            ]
        ],
        'maintenance'      => [
            "size"   => 64,
            "column" => [
                ["name" => "under_maintenance", "type" => \Swoole\Table::TYPE_STRING, 'size' => 5],
            ]
        ],
        'walletClients'    => [
            "size"   => 64,
            "column" => [
                ["name" => "token", "type" => \Swoole\Table::TYPE_STRING, 'size' => 32],
            ]
        ],
        'lockHashData'    => [
            "size"   => 50000,
            "column" => [
                ["name" => "type", "type" => \Swoole\Table::TYPE_STRING, 'size' => 10],
            ]
        ],
        'systemConfig' => [
            'size' => 100,
            'column' => [
                [ 'name' => 'value', 'type' => \Swoole\Table::TYPE_STRING, 'size' => 255 ],
            ],
        ],
        'statsCountEventsPerSecond' => [
            "size"   => 400,
            "column" => [
                ["name" => "total", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "processed", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "timestamp_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "payload_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "hash_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "inactiveSport_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "inactiveProvider_error", "type" => \Swoole\Table::TYPE_FLOAT],
            ]
        ],
        'statsTimeEventsPerSecond'  => [
            "size"   => 400,
            "column" => [
                ["name" => "total", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "processed", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "timestamp_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "payload_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "hash_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "inactiveSport_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "inactiveProvider_error", "type" => \Swoole\Table::TYPE_FLOAT],
            ]
        ],
        'statsCountOddsPerSecond'   => [
            "size"   => 400,
            "column" => [
                ["name" => "total", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "processed", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "timestamp_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "payload_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "hash_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "inactiveSport_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "inactiveProvider_error", "type" => \Swoole\Table::TYPE_FLOAT],
            ]
        ],
        'statsTimeOddsPerSecond'    => [
            "size"   => 400,
            "column" => [
                ["name" => "total", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "processed", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "timestamp_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "payload_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "hash_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "inactiveSport_error", "type" => \Swoole\Table::TYPE_FLOAT],
                ["name" => "inactiveProvider_error", "type" => \Swoole\Table::TYPE_FLOAT],
            ]
        ],
    ],
    'logger'        => [
        'app'                 => [
            'name'  => 'app.log',
            'level' => 'debug'
        ],
        'odds-events-reactor' => [
            'name'  => 'odds-event-reactor.log',
            'level' => 'debug'
        ],
        'odds'                => [
            'name'  => 'odds.log',
            'level' => 'debug'
        ],
        'event'               => [
            'name'  => 'event.log',
            'level' => 'debug'
        ],
        'balance-reactor'     => [
            'name'  => 'balance-reactor.log',
            'level' => 'debug'
        ],
        'balance'             => [
            'name'  => 'balance.log',
            'level' => 'debug'
        ],
        'bets-processor' => [
            'name'  => 'bets-processor.log',
            'level' => 'debug'
        ],
        'old-pending-bets'         => [
            'name'  => 'old-pending-bets.log',
            'level' => 'debug'
        ],
        'maintenance-reactor' => [
            'name'  => 'maintenance-reactor.log',
            'level' => 'debug'
        ],
        'maintenance'         => [
            'name'  => 'maintenance.log',
            'level' => 'debug'
        ],
        'minmax'              => [
            'name'  => 'minmax.log',
            'level' => 'debug'
        ],
        'sessions-reactor'    => [
            'name'  => 'sessions-reactor.log',
            'level' => 'debug'
        ],
        'session-sync'        => [
            'name'  => 'session-sync.log',
            'level' => 'debug'
        ],
        'session-transform'   => [
            'name'  => 'session-transform.log',
            'level' => 'debug'
        ],
        'settlements-reactor' => [
            'name'  => 'settlements-reactor.log',
            'level' => 'debug'
        ],
        'settlements'         => [
            'name'  => 'settlements.log',
            'level' => 'debug'
        ],
        'stats'         => [
            'name'  => 'stats.log',
            'level' => 'debug'
        ],
    ]
];