<?php

namespace Workers;

use Models\ProviderAccount;
use Exception;

class ProcessSessionStop
{
    public static function handle($connection, $swooleTable, $message, $offset)
    {
        $categoryTypes = [
            'SCRAPER'         => 'odds',
            'SCRAPER_MIN_MAX' => 'minmax',
            'BET_NORMAL'      => 'bet',
        ];

        try {
            // Set the starting time of this function, to keep running stats
            $startTime             = microtime(true);
            $inactiveAccount       = $message['data'];
            $providerAccountsTable = $swooleTable['providerAccounts'];
            $result                = ProviderAccount::getProviderAccountByUsername($connection, $inactiveAccount['username']);
            $providerAccount       = $connection->fetchArray($result);

            if ($providerAccount) {
                $line                         = $providerAccount['line'];
                $result                       = ProviderAccount::getProviderAccountsByLine($connection, $line);
                $providerAccountsWithSameLine = $connection->fetchAll($result);

                if ($providerAccountsWithSameLine) {
                    foreach ($providerAccountsWithSameLine as $providerAccount) {
                        $providerAccountId        = $providerAccount['id'];
                        $inactiveAccount['usage'] = $inactiveAccount['usage'] ?: "open";
                        $resultUpdate             = ProviderAccount::updateToInactive($connection, $providerAccountId, strtoupper($inactiveAccount['usage']));

                        if ($resultUpdate) {
                            $providerAccountsTable->del($providerAccountId);
                        } else {
                            throw new Exception('Something went wrong');
                        }
                    }

                    $message['sub_command'] = 'sync-stop';
                    unset($message['data']['username']);
                    ProcessSessionSync::handle($connection, $swooleTable, $message, $offset);
                }
            }

            logger('info', 'session-transform', 'Session Transform Processed', $message);
        } catch (Exception $e) {
            logger('error', 'session-transform', 'Error', (array) $e);
        }
    }
}