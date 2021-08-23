<?php

namespace Models;

Class ExchangeRate
{
    private static $table = 'exchange_rates';

    public static function getRate($connection, $fromCurrencyId, $toCurrencyId)
    {
        $sql = "SELECT * FROM " . self::$table . " WHERE from_currency_id = '{$fromCurrencyId}' AND to_currency_id = '{$toCurrencyId}' ORDER BY id DESC LIMIT 1";
        return $connection->query($sql);
    }
}