<?php

namespace Workers;

class ProcessMaintenance
{
    protected $message;
    protected $offset;

    public static function handle($connection, $swooleTable, $message, $offset)
    {
        try {
            $maintenance = $swooleTable['maintenance'];

            $maintenance->set(strtolower($message['data']['provider']), [
                'under_maintenance' => (string) $message['data']['is_undermaintenance'],
            ]);

            logger('info', 'maintenance', 'Maintenance Processed', $message);
        } catch (Exception $e) {
            logger('error', 'maintenance', 'Error', (array) $e);
        }
    }
}
