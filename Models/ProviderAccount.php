<?php

namespace Models;

Class ProviderAccount
{
    private static $table = 'provider_accounts';

    public static function getAll($connection)
    {
        $sql = "SELECT * FROM " . self::$table . " WHERE deleted_at is null";
        return $connection->query($sql);
    }

    public static function getEnabledProviderAccounts($connection)
    {
        $sql = "SELECT
                pa.*,
                p.alias
            FROM " . self::$table . " as pa

            LEFT JOIN providers as p
                ON p.id = pa.provider_id

            WHERE pa.deleted_at is null
            AND pa.is_idle = true
            AND pa.is_enabled = true
            AND p.is_enabled = true
            AND pa.deleted_at is null";

        return $connection->query($sql);
    }

    public static function getByProviderAndTypes($connection, $providerId, $providerTypes)
    {
        $sql = "SELECT username, password, type, is_enabled, usage FROM " . self::$table . " WHERE provider_id = '{$providerId}' AND type IN ('" . implode("', '", array_keys($providerTypes)) . "') AND deleted_at is null";
        return $connection->query($sql);
    }

    public static function updateToActive($connection, $providerAccountId, $usage = 'OPEN')
    {
        $sql = "UPDATE " . self::$table . " SET deleted_at = null, is_idle = true, is_enabled = true, usage = '{$usage}' WHERE id = '{$providerAccountId}'";
        return $connection->query($sql);
    }

    public static function updateToInactive($connection, $providerAccountId, $usage = 'OPEN')
    {
        $sql = "UPDATE " . self::$table . " SET deleted_at = null, is_idle = false, is_enabled = false, usage = '{$usage}' WHERE id = '{$providerAccountId}'";
        return $connection->query($sql);
    }

    public static function getProviderAccountByUsername($connection, $username)
    {
        $sql = "SELECT * FROM " . self::$table . " WHERE username = '{$username}' AND deleted_at is null";
        return $connection->query($sql);
    }

    public static function getProviderAccountsByLine($connection, $getProviderAccountsByLine)
    {
        $sql = "SELECT * FROM " . self::$table . " WHERE line = '{$getProviderAccountsByLine}' AND deleted_at is null";
        return $connection->query($sql);
    }
}