<?php

namespace Models;

Class Source
{
    private static $table = 'sources';

    public static function getByReturnStake($connection)
    {
        $sql = "SELECT * FROM " . self::$table . " WHERE source_name = 'RETURN_STAKE' ORDER BY id LIMIT 1";
        return $connection->query($sql);
    }

    public static function getBySourceName($connection, $sourceName)
    {
        $sql = "SELECT * FROM " . self::$table . " WHERE source_name = '{$sourceName}' ORDER BY id LIMIT 1";
        return $connection->query($sql);
    }
}