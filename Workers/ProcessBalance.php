<?php

namespace Workers;

use Models\ProviderAccount;
use Exception;

class ProcessBalance
{
    public static function handle($connection, $swooleTable, $providerWallet, $message, $offset)
    {

        try {
            $providerAccountsTable = $swooleTable['providerAccounts'];
            $providersTable        = $swooleTable['enabledProviders'];

            $username         = $message['data']['username'];
            $provider         = $message['data']['provider'];
            $availableBalance = $message['data']['available_balance'];

            $walletClientsTable = $swooleTable['walletClients'];
            $accessToken        = $walletClientsTable[$provider . '-users']['token'];

            $providerId = $providersTable[$provider]['value'];
            foreach ($providerAccountsTable as $k => $pa) {
                if (
                    $pa['username'] == $username &&
                    $pa['provider_id'] == $providerId &&
                    $pa['credits'] != $availableBalance
                ) {
                    $balance = $providerWallet->getBalance($accessToken, $pa['uuid'], $providersTable[$provider]['currency_code']);
                    if ($balance->status) {
                        $balanceDiff = $availableBalance - $balance->data->balance;
                        if ($balanceDiff > 0) {
                            $addBalance = $providerWallet->addBalance($accessToken, $pa['uuid'], $providersTable[$provider]['currency_code'], $balanceDiff, '[BALANCE] - Balance syncing');
                            if (!$addBalance->status) {
                                throw new Exception('Something went wrong');
                            }
                            logger('info', 'balance', "Balance added", $message['data']);
                        } else if ($balanceDiff < 0) {
                            $subtractBalance = $providerWallet->subtractBalance($accessToken, $pa['uuid'], $providersTable[$provider]['currency_code'], abs($balanceDiff), '[BALANCE] - Balance syncing');
                            if (!$subtractBalance->status) {
                                throw new Exception('Something went wrong');
                            }
                            logger('info', 'balance', "Balance subtracted", $message['data']);
                        } else {
                            logger('info', 'balance', "Balance is still the same", $message);
                        }
                        $providerAccountsTable[$k]['credits'] = $availableBalance;
                        logger('info', 'balance', "Balance Processed", $message['data']);
                    } else {
                        throw new Exception('Something went wrong');
                    }
                    break;
                }
            }
        } catch (Exception $e) {
            logger('error', 'balance', "Error", (array) $e);
        }
    }
}
