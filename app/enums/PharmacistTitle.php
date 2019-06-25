<?php

namespace App\Enums;

class PharmacistTitle
{
    private static $map = [
        ['Title' => 1, 'Name' => '药师'],
        ['Title' => 2, 'Name' => '主管药师'],
        ['Title' => 3, 'Name' => '副主任药师'],
        ['Title' => 4, 'Name' => '主任药师'],
        // ['Title' => 5, 'Name' => '其他'],
    ];

    public static function options()
    {
        return array_column(self::$map, 'Id');
    }

    public static function value($Id)
    {
        foreach (self::$map as $item) {
            if ($item['Title'] == $Id) {
                return $item['Name'];
            }
        }
        return null;
    }

    public static function map()
    {
        return self::$map;
    }
}
