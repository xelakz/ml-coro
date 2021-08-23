<?php

namespace Models;

class SportOddType
{
    protected static $table = 'sport_odd_type';

    public static function getOddTypes($connection)
    {
        $sql = "SELECT sport_id, odd_types.type, sport_odd_type.odd_type_id FROM " . self::$table . " JOIN odd_types ON odd_types.id = sport_odd_type.odd_type_id";
        return $connection->query($sql);

    }
}