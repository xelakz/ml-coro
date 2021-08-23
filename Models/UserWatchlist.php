<?php

namespace Models;

use Models\Model;

class UserWatchlist extends Model
{
    protected static $table = 'user_watchlist';

    public static function getUserWatchlists($connection, $schedule)
    {
        $masterEventIds = [];
        $sql = "SELECT ".self::$table.".master_event_id FROM " . self::$table 
        ." JOIN users ON users.id=".self::$table.".user_id AND users.status=1"
        ." JOIN event_groups on event_groups.master_event_id=".self::$table.".master_event_id" 
        ." JOIN events on events.id=event_groups.event_id"
        ." WHERE events.deleted_at is null AND game_schedule='{$schedule}'";
        $userWatchlists = $connection->query($sql);
        
        if ($userWatchlists) {
            $masterEventIdArray = $connection->fetchAll($userWatchlists);
            if (!empty($masterEventIdArray)) {
                foreach($masterEventIdArray as $event) {
                    $masterEventIds[] = $event['master_event_id'];
                }
            }
        }
        return $masterEventIds;
    }
}