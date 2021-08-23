<?php

namespace Models;

Class OddType
{
    private static $table = 'odd_types';

    public static function getColMinusOne($connection)
    {
        $sql = "SELECT id FROM " . self::$table . " WHERE type IN ('1X2', 'HT 1X2', 'OE')";
        return $connection->query($sql);
    }
}