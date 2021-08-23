<?php

namespace Models;

class SystemConfiguration
{
    private static $table = 'system_configurations';

    public static function getAllConfig($connection)
    {
        $sql = "SELECT type, value FROM " . self::$table;
        return $connection->query($sql);
    }

    public static function getProviderMaintenanceConfigData($connection)
    {
        $sql = "SELECT type, value FROM " . self::$table . " WHERE type LIKE '%_MAINTENANCE'";
        return $connection->query($sql);
    }

    public static function getPrimaryProvider($connection)
    {
        $sql = "SELECT type, value FROM " . self::$table . " WHERE type = 'PRIMARY_PROVIDER'";
        return $connection->query($sql);
    }

    public static function getMaxMissingCount4Deletion($connection, $schedule)
    {
        $sql = "SELECT type, value FROM " . self::$table . " WHERE type = '" . strtoupper($schedule) . "_MISSING_MAX_COUNT_FOR_DELETION'";
        return $connection->query($sql);
    }

    public static function getOddsEventRequestInterval($connection)
    {
        $sql = "SELECT type, value FROM " . self::$table . " WHERE type = 'SCRAPING_PRODUCER_REQUEST_INTERVAL'";
        return $connection->query($sql);
    }

    public static function getOddsEventScrapingConfigs($connection, $whereIn)
    {
        $whereSql = " type IN ('" . implode("', '", $whereIn) . "')";
        $sql      = "SELECT type, value FROM " . self::$table . " WHERE " . $whereSql;
        return $connection->query($sql);
    }

    public static function getBalanceRequestInterval($connection)
    {
        $sql = "SELECT type, value FROM " . self::$table . " WHERE type = 'BALANCE_PRODUCER_REQUEST_INTERVAL'";
        return $connection->query($sql);
    }

    public static function getBetConfig($connection, $type)
    {
        $sql = "SELECT type, value FROM " . self::$table . " WHERE type = '{$type}'";
        return $connection->query($sql);
    }

    public static function getSettlementRequestInterval($connection)
    {
        $sql = "SELECT type, value FROM " . self::$table . " WHERE type = 'SETTLEMENT_PRODUCER_REQUEST_INTERVAL'";
        return $connection->query($sql);
    }

    public static function getSettlementRequestTimer($connection)
    {
        $sql = "SELECT type, value FROM " . self::$table . " WHERE type = 'SETTLEMENTS_REQUEST_TIMER'";
        return $connection->query($sql);
    }

    public static function getSessionStatusRequestInterval($connection)
    {
        $sql = "SELECT type, value FROM " . self::$table . " WHERE type = 'SESSION_STATUS_PRODUCER_REQUEST_INTERVAL'";
        return $connection->query($sql);
    }

    public static function getSessionStatusDbInterval($connection)
    {
        $sql = "SELECT type, value FROM " . self::$table . " WHERE type = 'SESSION_STATUS_PRODUCER_DB_INTERVAL'";
        return $connection->query($sql);
    }

    public static function getSessionCategoryDbInterval($connection)
    {
        $sql = "SELECT type, value FROM " . self::$table . " WHERE type = 'SESSION_CATEGORY_PRODUCER_DB_INTERVAL'";
        return $connection->query($sql);
    }

    public static function getOpenOrderRequestInterval($connection)
    {
        $sql = "SELECT type, value FROM " . self::$table . " WHERE type = 'OPEN_ORDER_PRODUCER_REQUEST_INTERVAL'";
        return $connection->query($sql);
    }

    public static function getOpenOrderRequestTimer($connection)
    {
        $sql = "SELECT type, value FROM " . self::$table . " WHERE type = 'OPEN_ORDERS_REQUEST_TIMER'";
        return $connection->query($sql);
    }
}
