<?php

namespace Models;

use Models\Model;

class MasterEvent extends Model
{
    protected static $table = 'master_events';

    public static function getMasterEventIdByMasterLeagueId($connection, $schedule, $masterLeagueIds)
    {
        $sql = "SELECT master_events.id FROM " . static::$table 
            ." JOIN event_groups ON event_groups.master_event_id=". static::$table . ".id "
            ." JOIN events ON events.id=event_groups.event_id"
            ." WHERE master_league_id in ($masterLeagueIds) 
                AND game_schedule = '{$schedule}'
                AND events.deleted_at is null";
        return $connection->query($sql);
    }
}