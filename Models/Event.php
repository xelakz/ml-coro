<?php

namespace Models;

use Models\Model;

class Event extends Model
{
    protected static $table = 'events';

    public static function getActiveEvents($connection)
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE deleted_at is null";
        return $connection->query($sql);
    }

    public static function getEventByProviderParam($connection, $eventIdentifier, $providerId, $sportId)
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE event_identifier = '{$eventIdentifier}' AND provider_id = '{$providerId}' AND sport_id = '{$sportId}' ORDER BY id DESC LIMIT 1";
        echo $sql . "\n";
        return $connection->query($sql);
    }

    public static function getEventsById($connection, $eventId)
    {
        $sql = "SELECT e.*, eg.* FROM " . static::$table . " as e 
                LEFT JOIN event_groups as eg ON eg.event_id = e.id WHERE id = '{$eventId}'";
        return $connection->query($sql);
    }
}