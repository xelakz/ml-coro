<?php

class SwooleTable
{
    public $table;

    public function createTable($table, $details)
    {
        $this->table[$table] = new Swoole\Table($details['size']);
        foreach ($details['column'] as $column) {
            if (!empty($column['size'])) {
                $this->table[$table]->column($column['name'], $column['type'], $column['size']);
            } else {
                $this->table[$table]->column($column['name'], $column['type']);
            }
        }
        $this->table[$table]->create();
    }
}