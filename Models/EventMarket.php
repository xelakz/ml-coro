<?php

namespace Models;

use Models\Model;

class EventMarket extends Model
{
    protected static $table = 'event_markets';

    public static function updateDataByEventMarketId($connection, $eventMarketId, $data)
    {
        return static::update($connection, $data, [
            'id' => $eventMarketId
        ]);
    }

    public static function getDataByBetIdentifier($connection, $betIdentifier)
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE bet_identifier = '{$betIdentifier}' LIMIT 1";
        echo $sql . "\n";
        return $connection->query($sql);
    }

    public static function getActiveEventMarkets($connection)
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE deleted_at is null";
        return $connection->query($sql);
    }

    public static function getMarketsByMasterEventIds($connection, $masterEventIds)
    {
        $sql = "SELECT e.sport_id, e.game_schedule, em.*, emg.* FROM " . static::$table . " as em 
                LEFT JOIN event_market_groups as emg ON emg.event_market_id = em.id
                LEFT JOIN events as e on e.id=em.event_id
                JOIN event_groups as eg ON eg.event_id = em.event_id WHERE eg.master_event_id in ($masterEventIds)
                AND em.is_main = true
                AND em.deleted_at is null";
        return $connection->query($sql);
    }
}