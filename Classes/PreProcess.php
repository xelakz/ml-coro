<?php

use Models\{
    Provider,
    ProviderAccount,
    SystemConfiguration,
    Order,
    Team,
    League,
    Event,
    EventMarket,
    Sport,
    SportOddType,
};

class PreProcess
{
    private static $connection;

    public static function init($connection)
    {
        self::$connection = $connection;
    }

    public static function loadEnabledSports()
    {
        global $swooleTable;

        foreach ($swooleTable['enabledSports'] as $k => $st) {
            $swooleTable['enabledSports']->del($k);
        }

        $result = Sport::getActiveSports(self::$connection);
        while ($sport = self::$connection->fetchAssoc($result)) {
            $swooleTable['enabledSports'][$sport['id']]["value"] = $sport['slug'];
        }
    }

    public static function loadSportsOddTypes()
    {
        global $swooleTable;

        foreach ($swooleTable['sportsOddTypes'] as $k => $st) {
            $swooleTable['sportsOddTypes']->del($k);
        }

        $result = SportOddType::getOddTypes(self::$connection);
        while ($sot = self::$connection->fetchAssoc($result)) {
            $swooleTable['sportsOddTypes'][$sot['sport_id'] . '-' . $sot['type']]["value"] = $sot['odd_type_id'];
        }
    }

    public static function loadEnabledProviders()
    {
        global $swooleTable;

        foreach ($swooleTable['enabledProviders'] as $k => $st) {
            $swooleTable['enabledProviders']->del($k);
        }

        $result = Provider::getActiveProviders(self::$connection);
        while ($p = self::$connection->fetchAssoc($result)) {
            $swooleTable['enabledProviders'][strtolower($p["alias"])]["value"]      = $p["id"];
            $swooleTable['enabledProviders'][strtolower($p["alias"])]["currencyId"] = $p["currency_id"];
            $swooleTable['enabledProviders'][strtolower($p["alias"])]["currency_code"] = $p["currency_code"];
        }
    }

    public static function loadLeagues()
    {
        global $swooleTable;

        foreach ($swooleTable['leagues'] as $k => $ml) {
            $swooleTable['leagues']->del($k);
        }

        $result = League::getActiveLeagues(self::$connection);
        while ($league = self::$connection->fetchAssoc($result)) {
            $swooleTable['leagues']->set(md5(implode(':', [$league['sport_id'], $league['provider_id'], $league['name']])),
                [
                    'id'          => $league['id'],
                    'name'        => $league['name'],
                    'sport_id'    => $league['sport_id'],
                    'provider_id' => $league['provider_id'],
                ]
            );
        }
    }

    public static function loadTeams()
    {
        global $swooleTable;

        foreach ($swooleTable['teams'] as $k => $ml) {
            $swooleTable['teams']->del($k);
        }

        $result = Team::getActiveTeams(self::$connection);
        while ($team = self::$connection->fetchAssoc($result)) {
            $swooleTable['teams']->set(md5(implode(':', [$team['name'], $team['sport_id'], $team['provider_id']])),
                [
                    'id'          => $team['id'],
                    'name'        => $team['name'],
                    'sport_id'    => $team['sport_id'],
                    'provider_id' => $team['provider_id'],
                ]
            );
        }
    }

    public static function loadEvents()
    {
        global $swooleTable;

        foreach ($swooleTable['events'] as $k => $ml) {
            $swooleTable['events']->del($k);
        }

        $result = Event::getActiveEvents(self::$connection);
        while ($event = self::$connection->fetchAssoc($result)) {
            $swooleTable['events']->set(md5(implode(':', [$event['sport_id'], $event['provider_id'], $event['event_identifier']])),
                [
                    'id'               => $event['id'],
                    'sport_id'         => $event['sport_id'],
                    'provider_id'      => $event['provider_id'],
                    'missing_count'    => $event['missing_count'],
                    'league_id'        => $event['league_id'],
                    'team_home_id'     => $event['team_home_id'],
                    'team_away_id'     => $event['team_away_id'],
                    'ref_schedule'     => $event['ref_schedule'],
                    'game_schedule'    => $event['game_schedule'],
                    'event_identifier' => $event['event_identifier']
                ]
            );
        }
    }

    public static function loadEventMarkets()
    {
        global $swooleTable;

        foreach ($swooleTable['eventMarkets'] as $k => $ml) {
            $swooleTable['eventMarkets']->del($k);
        }

        foreach ($swooleTable['eventMarketList'] as $k => $em) {
            $swooleTable['eventMarketList']->del($k);
        }

        $activeMarkets = [];

        $result = EventMarket::getActiveEventMarkets(self::$connection);
        while ($eventMarket = self::$connection->fetchAssoc($result)) {
            $swooleTable['eventMarkets']->set(md5(implode(':', [$eventMarket['provider_id'], $eventMarket['bet_identifier']])),
                [
                    'id'                      => $eventMarket['id'],
                    'bet_identifier'          => $eventMarket['bet_identifier'],
                    'event_id'                => $eventMarket['event_id'],
                    'provider_id'             => $eventMarket['provider_id'],
                    'odd_type_id'             => $eventMarket['odd_type_id'],
                    'market_event_identifier' => $eventMarket['market_event_identifier'],
                    'market_flag'             => $eventMarket['market_flag'],
                    'is_main'                 => $eventMarket['is_main'],
                    'odd_label'               => $eventMarket['odd_label'],
                    'odds'                    => $eventMarket['odds']
                ]
            );


            $activeMarkets[$eventMarket['event_id']][] = $eventMarket['bet_identifier'];
            $swooleTable['eventMarketList'][$eventMarket['event_id']]['marketIDs'] = implode(',', $activeMarkets[$eventMarket['event_id']]);
        }
    }

    public static function loadEnabledProviderAccounts()
    {
        global $swooleTable;

        foreach ($swooleTable['providerAccounts'] as $k => $pa) {
            $swooleTable['providerAccounts']->del($k);
        }

        $result = ProviderAccount::getEnabledProviderAccounts(self::$connection);

        while ($providerAccount = self::$connection->fetchAssoc($result)) {
            $swooleTable['providerAccounts'][$providerAccount['id']] = [
                'provider_id'       => $providerAccount['provider_id'],
                'username'          => $providerAccount['username'],
                'punter_percentage' => $providerAccount['punter_percentage'],
                'credits'           => $providerAccount['credits'],
                'alias'             => $providerAccount['alias'],
                'type'              => $providerAccount['type'],
                'uuid'              => $providerAccount['uuid']
            ];
        }
    }

    public static function loadActiveOrders()
    {
        global $swooleTable;

        foreach ($swooleTable['activeOrders'] as $k => $e) {
            $swooleTable['activeOrders']->del($k);
        }

        $result = Order::getActiveOrders(self::$connection);
        $orders = self::$connection->fetchAll($result);

        foreach ($orders as $order) {
            $swooleTable['activeOrders']->set($order['id'], [
                'createdAt'      => $order['created_at'],
                'betId'          => $order['bet_id'],
                'orderExpiry'    => $order['order_expiry'],
                'username'       => $order['username'],
                'userCurrencyId' => $order['user_currency_id'],
                'status'         => $order['status']
            ]);
        }
    }

    public static function loadOldPendingBets()
    {
        global $swooleTable;

        foreach ($swooleTable['oldPendingBets'] as $k => $e) {
            $swooleTable['oldPendingBets']->del($k);
        }

        $result = Order::getOldPendingBets(self::$connection);

        if (self::$connection->numRows($result)) {
            $oldPendingBets = self::$connection->fetchAll($result);

            foreach ($oldPendingBets as $oldPendingBet) {
                $swooleTable['oldPendingBets']->set($oldPendingBet['id'], [
                    'provider_id'   => $oldPendingBet['provider_id'],
                    'sport_id'      => $oldPendingBet['sport_id'],
                    'bet_id'        => $oldPendingBet['bet_id'],
                    'bet_selection' => $oldPendingBet['bet_selection'],
                    'user_id'       => $oldPendingBet['user_id']
                ]);
            }
        }
    }

    public static function loadMaintenance()
    {
        global $swooleTable;

        foreach ($swooleTable['maintenance'] as $k => $m) {
            $swooleTable['maintenance']->del($k);
        }

        $result = SystemConfiguration::getProviderMaintenanceConfigData(self::$connection);

        while ($maintenance = self::$connection->fetchAssoc($result)) {
            $maintenanceTypes = explode('_', $maintenance['type']);
            $provider         = strtolower($maintenanceTypes[0]);

            $swooleTable['maintenance']->set($provider, ['under_maintenance' => $maintenance['value']]);
        }
    }

    public static function loadSystemConfig()
    {
        global $swooleTable;

        foreach ($swooleTable['systemConfig'] AS $key => $value) {
            $swooleTable['systemConfig']->del($key);
        }

        $result = SystemConfiguration::getAllConfig(self::$connection);
        while ($data = self::$connection->fetchAssoc($result)) {
            $swooleTable['systemConfig']->set($data['type'], [
                'value' => $data['value']
            ]);
        }
    }
}
