<?php

namespace Workers;

use Models\{
    OddType,
    Order,
    ExchangeRate,
    Source,
    OrderLog,
    ProviderAccountOrder,
    OrderTransaction
};
use Carbon\Carbon;
use Exception;

class ProcessSettlement
{
    private static $swooleTable;

    const TYPE_CHARGE    = 'Credit';
    const TYPE_DISCHARGE = 'Debit';

    public static function handle($connection, $swooleTable, $message, $offset)
    {
        try {
            self::$swooleTable   = $swooleTable;
            $orders              = $swooleTable['activeOrders'];
            $providersTable      = $swooleTable['enabledProviders'];
            $settlements         = $message['data'];
            $result              = OddType::getColMinusOne($connection);
            $colMinusOneFetchAll = $connection->fetchAll($result);

            $colMinusOne = [];
            foreach ($colMinusOneFetchAll as $data) {
                $colMinusOne[] = $data['id'];
            }
            
            $sourceResult      = Source::getByReturnStake($connection);
            $returnBetSourceId = $connection->fetchAssoc($sourceResult);

            foreach ($settlements as $settlement) {
                lockProcess($settlement['bet_id'], 'settlement');
                $swooleTable['lockHashData']->del($settlement['bet_id']);

                if (empty($settlement['status'])) {
                    logger('error', 'settlements', 'Empty Settlement Status', $settlement);
                    continue;
                }

                foreach ($orders as $key => $order) {
                    $swooleTable['lockHashData'][$settlement['bet_id']]['type'] = 'settlement';

                    if (!$providersTable->exists($settlement['provider'])) {
                        logger('info', 'settlements', 'Invalid Provider', $settlement);
                        continue;
                    }

                    $providerId         = $providersTable->get($settlement['provider'])['value'];
                    $providerCurrencyId = $providersTable->get($settlement['provider'])['currencyId'];

                    preg_match_all('!\d+!', $order['betId'], $mlBetIdArray);
                    preg_match_all('!\d+!', $settlement['bet_id'], $providerBetIdArray);

                    $mlBetIdArrayIndex0       = $mlBetIdArray[0];
                    $mlBetId                  = end($mlBetIdArrayIndex0);
                    $providerBetIdArrayIndex0 = $providerBetIdArray[0];
                    $providerBetId            = end($providerBetIdArrayIndex0);

                    if ($mlBetId == $providerBetId) {
                        if ($order['status'] == 'SUCCESS' || ($order['status'] == 'PENDING' && !empty($order['betId']))) {
                            self::settledBets($connection, $settlement, $providerId, $providerCurrencyId, $colMinusOne, $providerBetId, $returnBetSourceId);
                            $orders->del($key);
                        }

                        break;
                    }
                }

                $swooleTable['lockHashData']->del($settlement['bet_id']);
            }

            logger('info', 'settlements', 'Processed', $message);
        } catch (Exception $e) {
            logger('error', 'settlements', 'Error', (array) $e);
        }
    }

    private function settledBets($connection, $data, $providerId, $providerCurrencyId, $colMinusOne, $providerBetId, $returnBetSourceId)
    {
        $status              = strtoupper($data['status']);
        $balance             = 0;
        $stake               = 0;
        $sourceName          = "RETURN_STAKE";
        $stakeReturnToLedger = false;
        $transferAmount      = 0;

        if ($status == "WON") {
            $status = "WIN";
        }

        if ($status == "LOSS") {
            $status = "LOSE";
        }

        $orderResult        = Order::getDataByBetId($connection, $providerBetId, true);
        $order              = $connection->fetchAssoc($orderResult);
        $exchangeRateResult = ExchangeRate::getRate($connection, $providerCurrencyId, $order['currency_id']);
        $exchangeRate       = $connection->fetchAssoc($exchangeRateResult);
        $status             = recheckStatus($data['status'], $data['profit_loss'], $order['ato_win'], $order['astake'], $data['provider']);

        switch ($status) {
            case 'WIN':
                $stake               = $order['stake'];
                $balance             = $order['to_win'];
                $sourceName          = "BET_WIN";
                $stakeReturnToLedger = true;
                $charge              = 'Credit';
                $transferAmount      = $order['to_win'];

                break;
            case 'LOSE':
                $balance    = $order['stake'] * -1;
                $sourceName = "BET_LOSE";
                $charge     = 'Debit';

                break;
            case 'HALF WIN':
                $stake               = $order['stake'];
                $balance             = $order['to_win'] / 2;
                $sourceName          = "BET_HALF_WIN";
                $stakeReturnToLedger = true;
                $charge              = 'Credit';
                $transferAmount      = $order['to_win'] / 2;

                break;
            case 'HALF LOSE':
                $balance        = ($order['stake'] / 2) * -1;
                $sourceName     = "BET_HALF_LOSE";
                $charge         = 'Credit';
                $transferAmount = $order['stake'] / 2;

                break;
            case 'PUSH':
            case 'VOID':
            case 'DRAW':
            case 'CANCELLED':
            case 'REJECTED':
            case 'ABNORMAL BET':
            case 'REFUNDED':
                $balance        = 0;
                $charge         = 'Credit';
                $transferAmount = $order['stake'];

                break;
        }

        $sourceResult = Source::getBySourceName($connection, $sourceName);
        $source       = $connection->fetchAssoc($orderResult);
        $sourceId     = $source['id'];
        $score        = !empty($data['score']) ? $data['score'] : "0 - 0";
        $score        = array_map('trim', explode("-", $score));
        $finalScore   = $score[0] . ' - ' . $score[1];

        try {
            $orderUpdate = Order::updateByBetIdNumber($connection, $providerBetId, [
                'status'       => strtoupper($status),
                'profit_loss'  => $balance,
                'reason'       => $data['reason'],
                'settled_date' => Carbon::now(),
                'updated_at'   => Carbon::now(),
                'final_score'  => $finalScore
            ]);

            $orderLogs = OrderLog::create($connection, [
                'provider_id'   => $providerId,
                'sport_id'      => $data['sport'],
                'bet_id'        => $order['bet_id'],
                'bet_selection' => $order['bet_selection'],
                'status'        => $status,
                'user_id'       => $order['user_id'],
                'reason'        => $data['reason'],
                'profit_loss'   => $balance,
                'order_id'      => $order['id'],
                'settled_date'  => Carbon::now(),
                'created_at'    => Carbon::now(),
                'updated_at'    => Carbon::now(),
            ]);

            $orderLogResult    = OrderLog::lastInsertedData($connection);
            $orderLog          = $connection->fetchArray($orderLogResult);
            $orderLogsId       = $orderLog['id'];
            $chargeType        = $charge;
            $receiver          = $order['user_id'];
            $transferAmount    = $transferAmount == 0 ? 0 : $transferAmount;
            $currency          = $order['currency_id'];
            $creditDebitReason = "[{$sourceName}] - transaction for order id " . $order['id'];
            $ledger            = self::makeTransaction($connection, $order, $transferAmount, $currency, $sourceId, $chargeType, $creditDebitReason);

            $providerAccountOrderResult = ProviderAccountOrder::create($connection, [
                'order_log_id'       => $orderLogsId,
                'exchange_rate_id'   => $exchangeRate['id'],
                'actual_stake'       => $data['stake'],
                'actual_to_win'      => !in_array($order['odd_type_id'], $colMinusOne) ? $data['stake'] * $data['odds'] : $data['stake'] * ($data['odds'] - 1),
                'actual_profit_loss' => $data['profit_loss'],
                'exchange_rate'      => $exchangeRate['exchange_rate'],
                'created_at'          => Carbon::now(),
                'updated_at'          => Carbon::now(),
            ]);

            $orderTransactionResult = OrderTransaction::create($connection, [
                'order_logs_id'       => $orderLogsId,
                'user_id'             => $order['user_id'],
                'source_id'           => $sourceId,
                'currency_id'         => $order['currency_id'],
                'wallet_ledger_id'    => $ledger->id,
                'provider_account_id' => $order['provider_account_id'],
                'reason'              => $data['reason'],
                'amount'              => $balance,
                'created_at'          => Carbon::now(),
                'updated_at'          => Carbon::now(),
            ]);

            if ($stakeReturnToLedger == true) {
                $transferAmount    = $stake;
                $receiver          = $order['user_id'];
                $currency          = $order['currency_id'];
                $source            = $returnBetSourceId['id'];
                $chargeType        = "Credit";
                $creditDebitReason = "[RETURN_STAKE] - transaction for order id " . $order['id'];
                $ledger            = self::makeTransaction($connection, $order, $transferAmount, $currency, $source, $chargeType, $creditDebitReason);

                $orderTransactionResult = OrderTransaction::create($connection, [
                    'order_logs_id'       => $orderLogsId,
                    'user_id'             => $order['user_id'],
                    'source_id'           => $returnBetSourceId['id'],
                    'currency_id'         => $order['currency_id'],
                    'wallet_ledger_id'    => $ledger->id,
                    'provider_account_id' => $order['provider_account_id'],
                    'reason'              => $data['reason'],
                    'amount'              => $stake,
                    'created_at'          => Carbon::now(),
                    'updated_at'          => Carbon::now(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error(json_encode([
                'WS_SETTLED_BETS' => [
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                    'file'    => $e->getFile(),
                    'data'    => $data,
                ]
            ]));
        }
    }

    private function makeTransaction($connection, $receiver, $amount, $currency, $source, $type, $creditDebitReason)
    {
        global $wallet;

        $swooleTable        = self::$swooleTable;
        $uuid               = $receiver['uuid'];
        $debit              = doubleval(0);
        $credit             = doubleval(0);
        $walletClientsTable = $swooleTable['walletClients'];
        $accessToken        = $walletClientsTable['ml-users']['token'];
        $currencyCode       = trim(strtoupper($receiver['code']));
        $getBalance         = $wallet->getBalance($accessToken, $uuid, $currencyCode);

        if (!$getBalance->status) {
            if($type == self::TYPE_DISCHARGE) {
                // no account yet but already deducted
                throw new Exception(self::ERR_NEW_WALLET_DEDUCT);
            }

            $walletLedger = $wallet->addBalance($accessToken, $uuid, $currencyCode, $amount, $creditDebitReason);
        } else {
            if ($type == self::TYPE_CHARGE) {
                $walletLedger = $wallet->addBalance($accessToken, $uuid, $currencyCode, $amount, $creditDebitReason);
            } else {
                if($getBalance->data->balance < $amount){
                    throw new Exception(self::ERR_WALLET_DEDUCT);
                }

                $walletLedger = $wallet->subtractBalance($accessToken, $uuid, $currencyCode, $amount, $creditDebitReason);
            }
        }

        if (empty($walletLedger->status)) {
            throw new Exception(self::ERR_TRANSACTION);
        }

        return (object) [
            'id' => $walletLedger->data->id
        ];
    }
}
