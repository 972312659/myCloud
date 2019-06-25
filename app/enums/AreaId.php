<?php

namespace App\Enums;

class AreaId
{
    private static $map = [
        ['id' => 1, 'number' => '11'],
        ['id' => 2, 'number' => '31'],
        ['id' => 3, 'number' => '12'],
        ['id' => 4, 'number' => '50'],
        ['id' => 5, 'number' => '13'],
        ['id' => 6, 'number' => '14'],
        ['id' => 7, 'number' => '41'],
        ['id' => 8, 'number' => '21'],
        ['id' => 9, 'number' => '22'],
        ['id' => 10, 'number' => '23'],
        ['id' => 11, 'number' => '15'],
        ['id' => 12, 'number' => '32'],
        ['id' => 13, 'number' => '37'],
        ['id' => 14, 'number' => '34'],
        ['id' => 15, 'number' => '33'],
        ['id' => 16, 'number' => '35'],
        ['id' => 17, 'number' => '42'],
        ['id' => 18, 'number' => '43'],
        ['id' => 19, 'number' => '44'],
        ['id' => 20, 'number' => '45'],
        ['id' => 21, 'number' => '36'],
        ['id' => 22, 'number' => '51'],
        ['id' => 23, 'number' => '46'],
        ['id' => 24, 'number' => '52'],
        ['id' => 25, 'number' => '53'],
        ['id' => 26, 'number' => '54'],
        ['id' => 27, 'number' => '61'],
        ['id' => 28, 'number' => '62'],
        ['id' => 29, 'number' => '63'],
        ['id' => 30, 'number' => '64'],
        ['id' => 31, 'number' => '65'],
        ['id' => 32, 'number' => '71'],
        ['id' => 84, 'number' => '0'],
        ['id' => 52993, 'number' => '81'],
    ];

    public static function options()
    {
        return array_column(self::$map, 'id');
    }

    public static function value($id)
    {
        foreach (self::$map as $item) {
            if ($item['id'] == $id) {
                return $item['number'];
            }
        }
        return null;
    }

    public static function map()
    {
        return self::$map;
    }
}
