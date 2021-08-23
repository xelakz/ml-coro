<?php

namespace Workers;

use Exception;
use Carbon\Carbon;
use Models\{
    SystemConfiguration,
    League,
    Team,
    Event,
    EventMarket
};

class ProcessOdds
{
    const SCHEDULE_EARLY  = 'early';
    const SCHEDULE_TODAY  = 'today';
    const SCHEDULE_INPLAY = 'inplay';

    public static function handle($connection, $swooleTable, $message, $offset)
    {
        logger('info', 'odds', 'Process Odds starting ' . $offset);

        $start = microtime(true);
        $statsArray = [
            "type"        => "odds",
            "status"      => 'NO_ERROR',
            "time"        => 0,
            "request_uid" => $message["request_uid"],
            "request_ts"  => $message["request_ts"],
            "offset"      => $offset,
        ];

        try {
            $leaguesTable         = $swooleTable['leagues'];
            $teamsTable           = $swooleTable['teams'];
            $eventsTable          = $swooleTable['events'];
            $eventMarketsTable    = $swooleTable['eventMarkets'];
            $eventMarketListTable = $swooleTable['eventMarketList'];
            $providersTable       = $swooleTable['enabledProviders'];
            $sportsOddTypesTable  = $swooleTable['sportsOddTypes'];

            $messageData       = $message["data"];
            $sportId           = $messageData["sport"];
            $provider          = $messageData["provider"];
            $providerId        = $providersTable[$provider]['value'];
            $homeTeam          = trim($messageData["homeTeam"]);
            $awayTeam          = trim($messageData["awayTeam"]);
            $leagueName        = trim($messageData["leagueName"]);
            $gameSchedule      = $messageData["schedule"];
            $referenceSchedule = date("Y-m-d H:i:s", strtotime($messageData["referenceSchedule"]));
            $homescore         = $messageData["home_score"] ?: 0;
            $awayscore         = $messageData["away_score"] ?: 0;
            $score             = $homescore . ' - ' . $awayscore;
            $runningtime       = $messageData["runningtime"];
            $homeRedcard       = $messageData["home_redcard"] ?: 0;
            $awayRedcard       = $messageData["away_redcard"] ?: 0;
            $events            = $messageData["events"];
            $eventIdentifier   = $events[0]["eventId"];

            $primaryProviderResult = SystemConfiguration::getPrimaryProvider($connection);
            $primaryProvider       = $connection->fetchArray($primaryProviderResult);

            $timestamp = Carbon::now();

            /** leagues */
            $leagueId        = null;
            $leagueIndexHash = md5(implode(':', [$sportId, $providerId, $leagueName]));
            lockProcess($leagueIndexHash, 'league');
            if ($leaguesTable->exists($leagueIndexHash)) {
                $leagueId = $leaguesTable[$leagueIndexHash]['id'];
                logger('info', 'odds', 'league ID from $leaguesTable ' . $leagueId);
            } else {
                $swooleTable['lockHashData'][$leagueIndexHash]['type'] = 'league';
                try {
                    $leagueResult = League::create($connection, [
                        'sport_id'    => $sportId,
                        'provider_id' => $providerId,
                        'name'        => $leagueName,
                        'created_at'  => $timestamp
                    ], 'id');
                    var_dump(json_encode($connection));
                } catch (Exception $e) {
                    logger('error', 'odds', 'Another worker already created the league');

                    $statsArray['status'] = "ERROR";
                    $statsArray["time"] = microtime(true) - $startTime;
                    addStats($statsArray);
                    return;
                }

                $league   = $connection->fetchArray($leagueResult);
                $leagueId = $league['id'];

                logger('info', 'odds', 'League Created ' . $leagueId, [
                    'sport_id'    => $sportId,
                    'provider_id' => $providerId,
                    'name'        => $leagueName
                ]);

                $leaguesTable[$leagueIndexHash] = [
                    'id'          => $leagueId,
                    'sport_id'    => $sportId,
                    'provider_id' => $providerId,
                    'name'        => $leagueName,
                ];
            }

            /** home team **/
            $teamHomeId    = null;
            $teamIndexHash = md5($homeTeam . ':' . $sportId . ':' . $providerId);
            lockProcess($teamIndexHash, 'team');
            if ($teamsTable->exists($teamIndexHash)) {
                $teamHomeId = $teamsTable[$teamIndexHash]['id'];
                logger('info', 'odds', 'team ID from $teamsTable ' . $teamHomeId);
            } else {
                $swooleTable['lockHashData'][$teamIndexHash]['type'] = 'team';
                try {
                    $teamResult = Team::create($connection, [
                        'provider_id' => $providerId,
                        'name'        => $homeTeam,
                        'sport_id'    => $sportId,
                        'created_at'  => $timestamp
                    ], 'id');
                    var_dump(json_encode($connection));
                } catch (Exception $e) {
                    logger('error', 'odds', 'Another worker already created the team');

                    $statsArray['status'] = "ERROR";
                    $statsArray["time"] = microtime(true) - $startTime;
                    addStats($statsArray);
                    return;
                }

                $team       = $connection->fetchArray($teamResult);
                $teamHomeId = $team['id'];

                logger('info', 'odds', 'Team Created ' . $teamHomeId, [
                    'provider_id' => $providerId,
                    'name'        => $homeTeam,
                    'sport_id'    => $sportId
                ]);

                $teamsTable[$teamIndexHash] = [
                    'id'          => $teamHomeId,
                    'provider_id' => $providerId,
                    'name'        => $homeTeam,
                    'sport_id'    => $sportId,
                ];
            }

            $swooleTable['lockHashData']->del($teamIndexHash);
            /** end home team **/

            /** away team **/
            $teamAwayId    = null;
            $teamIndexHash = md5($awayTeam . ':' . $sportId . ':' . $providerId);
            lockProcess($teamIndexHash, 'team');
            if ($teamsTable->exists($teamIndexHash)) {
                $teamAwayId = $teamsTable[$teamIndexHash]['id'];
                logger('info', 'odds', 'team ID from $teamsTable ' . $teamAwayId);
            } else {
                $swooleTable['lockHashData'][$teamIndexHash]['type'] = 'team';
                try {
                    $teamResult = Team::create($connection, [
                        'provider_id' => $providerId,
                        'name'        => $awayTeam,
                        'sport_id'    => $sportId,
                        'created_at'  => $timestamp
                    ], 'id');
                    var_dump(json_encode($connection));
                } catch (Exception $e) {
                    logger('error', 'odds', 'Another worker already created the team');

                    $statsArray['status'] = "ERROR";
                    $statsArray["time"] = microtime(true) - $startTime;
                    addStats($statsArray);
                    return;
                }

                $team       = $connection->fetchArray($teamResult);
                $teamAwayId = $team['id'];

                logger('info', 'odds', 'Team Created ' . $teamAwayId, [
                    'provider_id' => $providerId,
                    'name'        => $awayTeam,
                    'sport_id'    => $sportId,
                ]);

                $teamsTable[$teamIndexHash] = [
                    'id'          => $teamAwayId,
                    'provider_id' => $providerId,
                    'name'        => $awayTeam,
                    'sport_id'    => $sportId,
                ];
            }

            $swooleTable['lockHashData']->del($teamIndexHash);
            /** end away team **/

            /* events */
            $eventId        = null;
            $eventIndexHash = md5(implode(':', [$sportId, $providerId, $eventIdentifier]));

            lockProcess($eventIndexHash, 'event');
            if ($eventsTable->exists($eventIndexHash)) {
                $eventId = $eventsTable[$eventIndexHash]['id'];

                if ($gameSchedule == self::SCHEDULE_EARLY && $eventsTable[$eventIndexHash]['game_schedule'] == self::SCHEDULE_TODAY) {
                    logger('error', 'odds', 'Event is already in today', $message);

                    $statsArray['status'] = "ERROR";
                    $statsArray["time"] = microtime(true) - $startTime;
                    addStats($statsArray);
                    return;
                }

                if ($gameSchedule == self::SCHEDULE_TODAY && $eventsTable[$eventIndexHash]['game_schedule'] == self::SCHEDULE_INPLAY) {
                    logger('error', 'odds', 'Event is already in play', $message);

                    $statsArray['status'] = "ERROR";
                    $statsArray["time"] = microtime(true) - $startTime;
                    addStats($statsArray);
                    return;
                }

                $missingCount = 0;
                Event::update($connection, [
                    'ref_schedule'  => $referenceSchedule,
                    'missing_count' => $missingCount,
                    'game_schedule' => $gameSchedule,
                    'score'         => $score,
                    'running_time'  => $runningtime,
                    'home_penalty'  => $homeRedcard,
                    'away_penalty'  => $awayRedcard,
                    'deleted_at'    => null,
                    'updated_at'    => $timestamp
                ], [
                    'event_identifier' => $eventIdentifier
                ]);
                var_dump(json_encode($connection));

                logger('info', 'odds', 'Event Updated event identifier ' . $eventIdentifier, [
                    'ref_schedule'  => $referenceSchedule,
                    'missing_count' => $missingCount,
                    'game_schedule' => $gameSchedule,
                    'score'         => $score,
                    'running_time'  => $runningtime,
                    'home_penalty'  => $homeRedcard,
                    'away_penalty'  => $awayRedcard,
                    'deleted_at'    => null,
                    'updated_at'    => $timestamp
                ]);
            } else {
                $swooleTable['lockHashData'][$eventIndexHash]['type'] = 'event';
                $eventResult = Event::getEventByProviderParam($connection, $eventIdentifier, $providerId, $sportId);
                var_dump(json_encode($connection));

                if ($connection->numRows($eventResult) > 0) {
                    $event = $connection->fetchArray($eventResult);
                    try {
                        $missingCount = 0;
                        Event::update($connection, [
                            'sport_id'      => $sportId,
                            'provider_id'   => $providerId,
                            'league_id'     => $leagueId,
                            'team_home_id'  => $teamHomeId,
                            'team_away_id'  => $teamAwayId,
                            'ref_schedule'  => $referenceSchedule,
                            'missing_count' => $missingCount,
                            'game_schedule' => $gameSchedule,
                            'score'         => $score,
                            'running_time'  => $runningtime,
                            'home_penalty'  => $homeRedcard,
                            'away_penalty'  => $awayRedcard,
                            'updated_at'    => $timestamp,
                            'deleted_at'    => null
                        ], [
                            'event_identifier' => $eventIdentifier
                        ]);
                        var_dump(json_encode($connection));

                        logger('info', 'odds', 'Event Updated event identifier ' . $eventIdentifier, [
                            'sport_id'      => $sportId,
                            'provider_id'   => $providerId,
                            'league_id'     => $leagueId,
                            'team_home_id'  => $teamHomeId,
                            'team_away_id'  => $teamAwayId,
                            'ref_schedule'  => $referenceSchedule,
                            'missing_count' => $missingCount,
                            'game_schedule' => $gameSchedule,
                            'score'         => $score,
                            'running_time'  => $runningtime,
                            'home_penalty'  => $homeRedcard,
                            'away_penalty'  => $awayRedcard
                        ]);
                    } catch (Exception $e) {
                        logger('error', 'odds', 'Another worker already created the event');

                        $statsArray['status'] = "ERROR";
                        $statsArray["time"] = microtime(true) - $startTime;
                        addStats($statsArray);
                        return;
                    }

                    $eventId = $event['id'];
                } else {
                    $missingCount = 0;
                    $eventResult = Event::create($connection, [
                        'event_identifier' => $eventIdentifier,
                        'sport_id'         => $sportId,
                        'provider_id'      => $providerId,
                        'league_id'        => $leagueId,
                        'team_home_id'     => $teamHomeId,
                        'team_away_id'     => $teamAwayId,
                        'ref_schedule'     => $referenceSchedule,
                        'missing_count'    => $missingCount,
                        'game_schedule'    => $gameSchedule,
                        'score'            => $score,
                        'running_time'     => $runningtime,
                        'home_penalty'     => $homeRedcard,
                        'away_penalty'     => $awayRedcard,
                        'created_at'       => $timestamp
                    ], 'id');
                    var_dump(json_encode($connection));

                    $event       = $connection->fetchArray($eventResult);

                    logger('info', 'odds', 'Event Created ' . $event['id'], [
                        'event_identifier' => $eventIdentifier,
                        'sport_id'         => $sportId,
                        'provider_id'      => $providerId,
                        'league_id'        => $leagueId,
                        'team_home_id'     => $teamHomeId,
                        'team_away_id'     => $teamAwayId,
                        'ref_schedule'     => $referenceSchedule,
                        'missing_count'    => $missingCount,
                        'game_schedule'    => $gameSchedule,
                        'score'            => $score,
                        'running_time'     => $runningtime,
                        'home_penalty'     => $homeRedcard,
                        'away_penalty'     => $awayRedcard
                    ]);

                    $eventId = $event['id'];
                }
            }

            $eventsTable[$eventIndexHash] = [
                'id'               => $eventId,
                'sport_id'         => $sportId,
                'provider_id'      => $providerId,
                'missing_count'    => $missingCount,
                'league_id'        => $leagueId,
                'team_home_id'     => $teamHomeId,
                'team_away_id'     => $teamAwayId,
                'ref_schedule'     => $referenceSchedule,
                'game_schedule'    => $gameSchedule,
                'event_identifier' => $eventIdentifier
            ];

            $swooleTable['lockHashData']->del($eventIndexHash);
            /* end events */

            $currentMarketsParsed     = [];
            $activeEventMarkets       = [];
            $activeMasterEventMarkets = [];
            $newMarkets               = [];
            $newMasterMarkets         = [];
            if ($eventMarketListTable->exists($eventId)) {
                $activeEventMarkets = $currentMarkets = explode(',', $eventMarketListTable[$eventId]['marketIDs']);
                if (is_array($currentMarkets)) {
                    foreach ($currentMarkets as $currentMarket) {
                        $currentMarketsParsed[$currentMarket] = 1;
                    }
                }
            }

            //$connection->query("BEGIN;");
            /* event market*/
            foreach ($events as $event) {
                if (!empty($event)) {
                    $marketEventIdentifiers[] = $event["eventId"];
                    $marketType               = $event["market_type"] == 1 ? 1 : 0;//true or false
                    $marketOdds               = $event["market_odds"];

                    foreach ($marketOdds as $odd) {
                        $selections = $odd["marketSelection"];
                        if (empty($selections)) {
                            $eventMarketSoftDeleteResult = EventMarket::softDelete($connection, 'event_id', $eventId);
                            var_dump(json_encode($connection));

                            if (is_array($activeEventMarkets)) {
                                foreach ($activeEventMarkets as $marketId) {
                                    $eventMarketsTable->del(md5(implode(':', [$providerId, $marketId])));
                                }
                            }
                            $eventMarketListTable->del($eventId);

                            logger('info', 'odds', 'Event Market Deleted with event ID ' . $eventId);
                            break 2;
                        }
                        //TODO: how do we fill this table??
                        if (!$sportsOddTypesTable->exists($sportId . '-' . $odd["oddsType"])) {
                            logger('error', 'odds', 'Odds Type doesn\'t exist', $message);

                            $statsArray['status'] = "ERROR";
                            $statsArray["time"] = microtime(true) - $startTime;
                            addStats($statsArray);
                            return;
                        }

                        $oddTypeId = $sportsOddTypesTable[$sportId . '-' . $odd["oddsType"]]['value'];
                        foreach ($selections as $selection) {
                            $marketFlag = strtoupper($selection["indicator"]);
                            $marketId  = $selection["market_id"];
                            if ($marketId == "") {
                                if (is_array($activeEventMarkets)) {
                                    foreach ($activeEventMarkets as $activeEventMarket) {
                                        $eventMarket = $eventMarketsTable[md5(implode(':', [$providerId, $activeEventMarket]))];
                                        if (
                                            $eventMarket['odd_type_id'] == $oddTypeId &&
                                            $eventMarket['provider_id'] == $providerId &&
                                            $eventMarket['market_event_identifier'] == $event["eventId"] &&
                                            $eventMarket['market_flag'] == $marketFlag
                                        ) {
                                            $eventMarketSoftDeleteResult = EventMarket::softDelete($connection, 'bet_identifier', $activeEventMarket);
                                            var_dump(json_encode($connection));
                                            $eventMarketsTable->del(md5(implode(':', [$providerId, $activeEventMarket])));

                                            logger('info', 'odds', 'Event Market Deleted with bet identifier ' . $activeEventMarket);
                                            break;
                                        }
                                    }
                                }

                                continue;
                            }

                            $odds   = $selection["odds"];
                            $points = array_key_exists('points', $selection) ? $selection["points"] : "";

                            if (!empty($currentMarketsParsed[$marketId])) {
                                unset($currentMarketsParsed[$marketId]);
                            }

                            if (gettype($odds) == 'string') {
                                $odds = explode(' ', $selection["odds"]);

                                if (count($odds) > 1) {
                                    $points = $points == "" ? $odds[0] : $points;
                                    $odds   = $odds[1];
                                } else {
                                    $odds = $odds[0];
                                }
                            }

                            $odds = trim($odds) == '' ? 0 : (float) $odds;

                            if (!empty($odds)) {
                                $eventMarketId        = null;
                                $eventMarketIndexHash = md5(implode(':', [$providerId, $marketId]));
                                if ($eventMarketsTable->exists($eventMarketIndexHash)) {
                                    $eventMarketId = $eventMarketsTable[$eventMarketIndexHash]['id'];
                                    try {
                                        if (!(
                                            $eventMarket['odds'] == $odds &&
                                            $eventMarket['odd_label'] == $points &&
                                            $eventMarket['is_main'] == $marketType &&
                                            $eventMarket['market_flag'] == $marketFlag &&
                                            $eventMarket['event_id'] == $eventId &&
                                            $eventMarket['market_event_identifier'] == $event["eventId"]
                                        )) {
                                            $eventMarketUpdateResult = EventMarket::updateDataByEventMarketId($connection, $eventMarketId, [
                                                'odds'                    => $odds,
                                                'odd_label'               => $points,
                                                'is_main'                 => $marketType,
                                                'market_flag'             => $marketFlag,
                                                'event_id'                => $eventId,
                                                'market_event_identifier' => $event["eventId"],
                                                'deleted_at'              => null,
                                                'updated_at'              => $timestamp
                                            ]);

                                            var_dump(json_encode($connection));
                                            logger('info', 'odds', 'Event Market Update event market ID ' . $eventMarketId, [
                                                'odds'                    => $odds,
                                                'odd_label'               => $points,
                                                'is_main'                 => $marketType,
                                                'market_flag'             => $marketFlag,
                                                'event_id'                => $eventId,
                                                'market_event_identifier' => $event["eventId"]
                                            ]);
                                        }
                                    } catch (Exception $e) {
                                        logger('error', 'odds', 'Another worker already updated the event market');

                                        $statsArray['status'] = "ERROR";
                                        $statsArray["time"] = microtime(true) - $startTime;
                                        addStats($statsArray);
                                        return;
                                    }
                                } else {
                                    $eventMarketResult = EventMarket::getDataByBetIdentifier($connection, $marketId);
                                    var_dump(json_encode($connection));
                                    $eventMarket       = $connection->fetchArray($eventMarketResult);
                                    if (!empty($eventMarket['id'])) {
                                        $eventMarketId = $eventMarket['id'];
                                        try {
                                            EventMarket::updateDataByEventMarketId($connection, $eventMarketId, [
                                                'event_id'                => $eventId,
                                                'odd_type_id'             => $oddTypeId,
                                                'odds'                    => $odds,
                                                'odd_label'               => $points,
                                                'bet_identifier'          => $marketId,
                                                'is_main'                 => $marketType,
                                                'market_flag'             => $marketFlag,
                                                'provider_id'             => $providerId,
                                                'market_event_identifier' => $event["eventId"],
                                                'deleted_at'              => null,
                                                'updated_at'              => $timestamp
                                            ]);
                                            var_dump(json_encode($connection));

                                            logger('info', 'odds', 'Event Market Update event market ID ' . $eventMarketId, [
                                                'event_id'                => $eventId,
                                                'odd_type_id'             => $oddTypeId,
                                                'odds'                    => $odds,
                                                'odd_label'               => $points,
                                                'bet_identifier'          => $marketId,
                                                'is_main'                 => $marketType,
                                                'market_flag'             => $marketFlag,
                                                'provider_id'             => $providerId,
                                                'market_event_identifier' => $event["eventId"]
                                            ]);
                                        } catch (Exception $e) {
                                            logger('error', 'odds', 'Another worker already updated the event market ' . $eventMarketId, [
                                                'event_id'                => $eventId,
                                                'odd_type_id'             => $oddTypeId,
                                                'odds'                    => $odds,
                                                'odd_label'               => $points,
                                                'bet_identifier'          => $marketId,
                                                'is_main'                 => $marketType,
                                                'market_flag'             => $marketFlag,
                                                'provider_id'             => $providerId,
                                                'market_event_identifier' => $event["eventId"]
                                            ]);

                                            $statsArray['status'] = "ERROR";
                                            $statsArray["time"] = microtime(true) - $startTime;
                                            addStats($statsArray);
                                            return;
                                        }
                                    } else {
                                        try {
                                            $memUid = md5($eventId . $oddTypeId . $marketFlag . $marketId);
                                            $eventMarketResult = EventMarket::create($connection, [
                                                'event_id'                => $eventId,
                                                'odd_type_id'             => $oddTypeId,
                                                'odds'                    => $odds,
                                                'odd_label'               => $points,
                                                'bet_identifier'          => $marketId,
                                                'is_main'                 => $marketType,
                                                'market_flag'             => $marketFlag,
                                                'provider_id'             => $providerId,
                                                'market_event_identifier' => $event["eventId"],
                                                'mem_uid'                 => $memUid,
                                                'deleted_at'              => null,
                                                'created_at'              => $timestamp
                                            ], 'id');
                                            var_dump(json_encode($connection));
                                            $eventMarket       = $connection->fetchArray($eventMarketResult);
                                        } catch (Exception $e) {
                                            logger('error', 'odds', 'Another worker already created the event market', [
                                                'event_id'                => $eventId,
                                                'odd_type_id'             => $oddTypeId,
                                                'odds'                    => $odds,
                                                'odd_label'               => $points,
                                                'bet_identifier'          => $marketId,
                                                'is_main'                 => $marketType,
                                                'market_flag'             => $marketFlag,
                                                'provider_id'             => $providerId,
                                                'market_event_identifier' => $event["eventId"],
                                                'deleted_at'              => null,
                                                'created_at'              => $timestamp
                                            ]);

                                            $statsArray['status'] = "ERROR";
                                            $statsArray["time"] = microtime(true) - $startTime;
                                            addStats($statsArray);
                                            return;
                                        }
                                        $eventMarketId = $eventMarket['id'];

                                        logger('info', 'odds', 'Event Market Created ' . $eventMarketId, [
                                            'event_id'                => $eventId,
                                            'odd_type_id'             => $oddTypeId,
                                            'odds'                    => $odds,
                                            'odd_label'               => $points,
                                            'bet_identifier'          => $marketId,
                                            'is_main'                 => $marketType,
                                            'market_flag'             => $marketFlag,
                                            'provider_id'             => $providerId,
                                            'market_event_identifier' => $event["eventId"]
                                        ]);
                                    }
                                }

                                $eventMarketsTable[$eventMarketIndexHash] = [
                                    'id'                      => $eventMarketId,
                                    'bet_identifier'          => $marketId,
                                    'event_id'                => $eventId,
                                    'provider_id'             => $providerId,
                                    'odd_type_id'             => $oddTypeId,
                                    'market_event_identifier' => $event["eventId"],
                                    'market_flag'             => $marketFlag,
                                    'is_main'                 => $marketType,
                                    'odd_label'               => $points,
                                    'odds'                    => $odds,
                                ];

                                $newMarkets[] = $marketId;
                                $eventMarketListTable->set($eventId, ['marketIDs' => implode(',', $newMarkets)]);
                            } else {
                                if (is_array($activeEventMarkets) && in_array($marketId, $activeEventMarkets)) {
                                    EventMarket::softDelete($connection, 'bet_identifier', $marketId);
                                    var_dump(json_encode($connection));

                                    $eventMarketsTable->del(md5(implode(':', [$providerId, $marketId])));

                                    logger('info', 'odds', 'Event Market Deleted with bet identifier ' . $marketId);
                                }
                            }
                        }
                    }
                }
            }
            /* end  master event market  and event market*/

            $noLongerActiveMarkets = $currentMarketsParsed;
            if (!empty($noLongerActiveMarkets) && is_array($activeEventMarkets)) {
                foreach ($activeEventMarkets as $activeEventMarket) {
                    if (
                    array_key_exists($activeEventMarket, $noLongerActiveMarkets)
                    ) {
                        EventMarket::softDelete($connection, 'bet_identifier', $activeEventMarket);
                        var_dump(json_encode($connection));
                        $eventMarketsTable->del(md5(implode(':', [$providerId, $activeEventMarket])));

                        logger('info', 'odds', 'Event Market Deleted with bet identifier ' . $activeEventMarket);
                    }
                }
            }
            //$connection->query("COMMIT;");
        } catch (Exception $e) {
            var_dump((array) $e);
            //$connection->query("ROLLBACK;");
            $activeEventMarkets = explode(',', $eventMarketListTable->get($eventIdentifier, 'marketIDs'));
            foreach ($activeEventMarkets as $marketId) {
                if (!empty($marketId)) {
                    $eventMarketsTable->del(md5(implode(':', [$providerId, $marketId])));
                }
            }
            logger('error', 'odds', $e, $message);
        }

        $statsArray["time"] = microtime(true) - $startTime;
        addStats($statsArray);
    }
}