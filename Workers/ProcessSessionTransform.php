<?php

namespace Workers;

use Models\ProviderAccount;
use Exception;

class ProcessSessionTransform
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
            $startTime = microtime(true);

            $activeAccounts        = $message['data']['active_sessions'];
            $inactiveAccounts      = $message['data']['inactive_sessions'];
            $providerAccountsTable = $swooleTable['providerAccounts'];

            $result           = ProviderAccount::getAll($connection);
            $providerAccounts = $connection->fetchAll($result);

            foreach ($providerAccounts as $providerAccount) {
                foreach ($activeAccounts as $activeAccount) {
                    if ($activeAccount['username'] != $providerAccount['username']) {
                        continue;
                    }

                    $providerAccountId = $providerAccount['id'];
                    if (in_array($activeAccount['category'], $categoryTypes)) {
                        $usage        = $activeAccount['usage'] ?: "open";
                        $resultUpdate = ProviderAccount::updateToActive($connection, $providerAccountId, strtoupper($usage));

                        if ($resultUpdate) {
                            if ($providerAccountsTable->exists($providerAccountId)) {
                                $providerAccountsTable[$providerAccountId]['provider_id']       = $providerAccount['provider_id'];
                                $providerAccountsTable[$providerAccountId]['username']          = $providerAccount['username'];
                                $providerAccountsTable[$providerAccountId]['punter_percentage'] = $providerAccount['punter_percentage'];
                                $providerAccountsTable[$providerAccountId]['credits']           = $providerAccount['credits'];
                                $providerAccountsTable[$providerAccountId]['type']              = $providerAccount['type'];
                            }
                        } else {
                            throw new Exception('Something went wrong');
                        }
                    }
                }

                foreach ($inactiveAccounts as $inactiveAccount) {
                    if ($inactiveAccount['username'] != $providerAccount['username']) {
                        continue;
                    }

                    $providerAccountId        = $providerAccount['id'];
                    $inactiveAccount['usage'] = $inactiveAccount['usage'] ?: "open";

                    if (in_array($inactiveAccount['category'], $categoryTypes)) {
                        $resultUpdate = ProviderAccount::updateToInactive($connection, $providerAccountId, strtoupper($inactiveAccount['usage']));
                        if ($resultUpdate) {
                            $providerAccountsTable->del($providerAccountId);
                        } else {
                            throw new Exception('Something went wrong');
                        }
                    }
                }
            }

            logger('info', 'session-transform', 'Session Transform Processed', $message);
        } catch (Exception $e) {
            logger('error', 'session-transform', 'Error', (array) $e);
        }
    }
}
