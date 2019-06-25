<?php

namespace App\Enums;

class OrganizationType
{
    private static $map = [
        ['Type' => 1, 'Name' => '综合医院'],
        ['Type' => 2, 'Name' => '专科医院'],
        ['Type' => 3, 'Name' => '诊所'],
        ['Type' => 4, 'Name' => '药店'],
        ['Type' => 5, 'Name' => '医务室'],
        ['Type' => 6, 'Name' => '医生'],
        ['Type' => 7, 'Name' => '村卫生站'],
    ];

    public static function options()
    {
        return array_column(self::$map, 'Type');
    }

    public static function value($Type)
    {
        foreach (self::$map as $item) {
            if ($item['Type'] == $Type) {
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
