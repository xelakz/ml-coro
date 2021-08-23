<?php

namespace Models;

use Models\Model;

class AdminSettlement extends Model
{
    protected static $table = "admin_settlements";

    public static function fetchUnprocessedPayloads($connection)
    {
        $sql = "SELECT * FROM " . self::$table . " WHERE processed = false";
        return $connection->query($sql);
    }

    public static function updateToProcessed($connection, $adminSettlementId)
    {
        $sql = "UPDATE " . self::$table . " SET processed = true WHERE id = '{$adminSettlementId}'";
        return $connection->query($sql);
    }
}
