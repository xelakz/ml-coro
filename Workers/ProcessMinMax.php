<?php

namespace Workers;

use Models\{
    EventMarket,
    League,
    UserWatchlist    
};

use Carbon\Carbon;
use Co\System;
use Exception;
use Ramsey\Uuid\Uuid;

class ProcessMinMax
{
    public static function handle($connection, $providers, $schedule) {

        logger('info', 'minmax', "[".strtoupper($schedule)."] Processing event markets...");
        try {
            $userWatchlists  = UserWatchlist::getUserWatchlists($connection, $schedule);
            $majorLeagues  = League::getMajorLeagues($connection, $schedule);
            $events = array_unique(array_merge($userWatchlists, $majorLeagues));
            if (!empty($events)) {
                $masterEventIds = implode(",",$events);
                $eventMarkets = EventMarket::getMarketsByMasterEventIds($connection,$masterEventIds);
                if ($eventMarkets) {
                    $eventMarketArray = $connection->fetchAll($eventMarkets);
                    if ($eventMarketArray) {
                        foreach($eventMarketArray as $market) {
                            //Push to Kafka
                            $requestId = (string) Uuid::uuid4();
                            $requestTs = getMilliseconds();
                            //Generate kafka json payload here
                            $payload = [
                                'request_uid'    => $requestId,
                                'request_ts'    => $requestTs,
                                'command'       => 'minmax',
                                'sub_command'   => 'scrape',
                                'data' => [
                                    'provider'      => $providers[$market['provider_id']],
                                    'sport'         => (string) $market['sport_id'],
                                    'schedule'      => $market['game_schedule'],
                                    'event_id'      => (string) $market['event_id'],
                                    'market_id'      => (string) $market['bet_identifier'],
                                    'odds'          => (string) $market['odds'],
                                    'memUID'        => $market['mem_uid']
                                ]
                            ];
                            $topic = getenv('KAFKA_MINMAXHIGH', 'minmax-high_req');
                            if (!in_array(getenv('APP_ENV'), ['testing'])) {
                                kafkaPush($topic, $payload, $requestId);
                                logger('info', 'minmax', "[".strtoupper($schedule)."] Pushed this event market bet_identifier: " . $market['bet_identifier'] . " - mem_uid:".$market['mem_uid']." to kafka");
                            }
                        }
                    }
                }
            } else {
                logger('info', 'minmax', "[".strtoupper($schedule)."] There are no event markets to process.");
            }
        } catch (Exception $e) {
            logger('error', 'minmax', "[".strtoupper($schedule)."] Something went wrong during Processing of event markets...", (array) $e);
        }
    }
}
