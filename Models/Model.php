<?php

namespace Models;

use Carbon\Carbon;

class Model
{
    protected static $table = '';

    public static function lastInsertedData($connection)
    {
        $sql = "SELECT * FROM " . static::$table . " ORDER BY id DESC LIMIT 1";
        echo $sql . "\n";
        return $connection->query($sql);
    }

    public static function softDelete($connection, $columnName, $value)
    {
        $sql = "UPDATE " . static::$table . " SET deleted_at = '" . Carbon::now() . "' WHERE {$columnName} = '{$value}'";
        echo $sql . "\n";
        return $connection->query($sql);
    }

    public static function delete($connection, $columnName, $value)
    {
        $sql = "DELETE FROM " . static::$table . " WHERE {$columnName} = '{$value}'";
        echo $sql . "\n";
        return $connection->query($sql);
    }

    public static function create($connection, $params, $return = false)
    {
        $sql    = "INSERT INTO " . static::$table . " (";
        $array2 = self::_index2string($params);

        $sql .= self::_field($array2);

        $sql .= ") VALUES (";

        $sql .= self::_sfield($params);

        $sql .= ")";

        if ($return) {
            $sql .= " RETURNING {$return}";
        }
        echo $sql . "\n";
        return $connection->query($sql);
    }

    public static function update($connection, $data, $where = null)
    {
        $sql = "UPDATE " . static::$table . " SET ";
        $sql .= self::_setValue($data, ',');
        $sql .= self::_where($where);
        echo $sql . "\n";
        return $connection->query($sql);
    }

    private function _where($array)
    {
        $str = "";
        if ($array) {
            $str .= " WHERE ";
            $str .= self::_setValue($array);
        }
        return $str;
    }

    private function _setValue($array, $delimiter = null)
    {
        $str = "";
        if ($array) {
            for ($i = 0; $i < count($array); $i++) {
                $str .= key($array);
                if (!strpos(key($array), "LIKE"))
                    $str .= " = ";
                if (is_null($array[key($array)])) {
                    $str .= " null";
                } else {
                    $str .= " '" . addslashes($array[key($array)]);

                    if (strpos(key($array), "LIKE"))
                        $str .= "%";
                    $str .= "'";
                }
                next($array);
                if ($i < count($array) - 1) {
                    if ($delimiter)
                        $str .= "$delimiter ";
                    else
                        $str .= " AND ";
                }
            }
        }
        return $str;
    }

    private function _field($array)
    {
        $str = implode(", ", $array);
        return $str;
    }

    private function _sfield($array)
    {
        $str = "";
        for ($i = 0; $i < count($array); $i++) {
            if (is_null($array[key($array)])) {
                $str .= "null";
            } else {
                $str .= "'" . str_replace("'", "''", $array[key($array)]) . "'";
            }

            next($array);
            if ($i < count($array) - 1) {
                $str .= ", ";
            }
        }
        return $str;
    }

    private function _index2string($array)
    {
        $array2 = array();
        for ($i = 0; $i < count($array); $i++) {
            $array2[key($array)] = key($array);
            next($array);
        }
        return $array2;
    }
}