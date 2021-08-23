<?php

namespace Workers;

use Models\{
    Order,
    OrderLog
};
use \Swoole\Database\RedisConfig;
use Helper\RedisPool;
use Carbon\Carbon;
use Exception;

class ProcessOldPendingBets
{
    private static $swooleTable;

    const PLACED_BET_KEY = "MLBET-";

    public static function handle($connection, $swooleTable)
    {
        global $wallet;

        try {
            self::$swooleTable   = $swooleTable;
            $oldPendingBets      = $swooleTable['oldPendingBets'];

            if ($oldPendingBets->count() > 0) {
                $pool = new RedisPool((new RedisConfig())
                    ->withHost(getenv('REDIS_HOST'))
                    ->withPort(getenv('REDIS_PORT'))
                );

                $redis = $pool->get();

                foreach($oldPendingBets as $key => $oldPendingBet) {
                    try {
                        logger('info', 'old-pending-bets', "Processing order id: " . $key . "...");
                        $result = json_decode($redis->get(self::PLACED_BET_KEY.$key), true);
                        if ($result) {
                            kafkaPush(getenv('KAFKA_BET_PLACED', 'PLACED-BET'), $result, $result['request_uid']);
                            logger('info', 'old-pending-bets', '[PLACED-BET] Payload sent: ' . $result['request_uid']);
                        } else {
                            $status = 'FAILED';
                            $reason = 'Placement Expired';
                            
                            $orderUpdate = Order::update($connection, [
                                'status'       => strtoupper($status),
                                'reason'       => $reason,
                                'updated_at'   => Carbon::now()
                            ], [
                                'id' => $key
                            ]);

                            $orderLogs = OrderLog::create($connection, [
                                'provider_id'   => $oldPendingBet['provider_id'],
                                'sport_id'      => $oldPendingBet['sport_id'],
                                'bet_id'        => $oldPendingBet['bet_id'],
                                'bet_selection' => $oldPendingBet['bet_selection'],
                                'status'        => $status,
                                'user_id'       => $oldPendingBet['user_id'],
                                'reason'        => $reason,
                                'order_id'      => $key,
                                'created_at'    => Carbon::now(),
                                'updated_at'    => Carbon::now(),
                            ]);

                            logger('info', 'old-pending-bets', "Order id: " . $key . " set to FAILED");
                            
                            $orderResult        = Order::getDataByBetId($connection, $oldPendingBet['bet_id'], true);
                            $order              = $connection->fetchAssoc($orderResult);
                            
                            $amount             = $order['stake'];
                            $walletClientsTable = $swooleTable['walletClients'];
                            $accessToken        = $walletClientsTable['ml-users']['token'];
                            $currencyCode       = trim(strtoupper($order['code']));
                            $uuid               = $order['uuid'];
                            $getBalance         = $wallet->getBalance($accessToken, $uuid, $currencyCode);
                            $creditDebitReason  = "[RETURN_STAKE][BET FAILED/CANCELLED] - transaction for order id " . $key;

                            $walletLedger = $wallet->addBalance($accessToken, $uuid, $currencyCode, $amount, $creditDebitReason);
                        
                            logger('info', 'old-pending-bets', 'Add balance for ledger id: ' . $walletLedger->data->id);
                        }
                    } catch (Exception $e1) {
                        logger('error', 'old-pending-bets', 'Error', (array) $e1);
                    }
                }
            }
            logger('info', 'old-pending-bets', "Done processing old pending bets");
        } catch (Exception $e) {
            logger('error', 'old-pending-bets', 'Error', (array) $e);
        }
    }
}