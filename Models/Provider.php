<?php

namespace Models;

Class Provider
{
    private static $table = 'providers';

    public static function getActiveProviders($connection)
    {
        $sql = "SELECT p.*, c.code as currency_code FROM " . self::$table . " as p 
                JOIN currency as c ON c.id = p.currency_id WHERE is_enabled = true";
        return $connection->query($sql);
    }
}