<?php

namespace App\Enums;

class HospitalLevel
{
    private static $map = [
        ['LevelId' => 1, 'Name' => '一级甲等'],
        ['LevelId' => 2, 'Name' => '一级乙等'],
        ['LevelId' => 3, 'Name' => '一级丙等'],
        ['LevelId' => 4, 'Name' => '一级其他等级'],
        ['LevelId' => 5, 'Name' => '二级甲等'],
        ['LevelId' => 6, 'Name' => '二级乙等'],
        ['LevelId' => 7, 'Name' => '二级丙等'],
        ['LevelId' => 8, 'Name' => '二级其他等级'],
        ['LevelId' => 9, 'Name' => '三级特等'],
        ['LevelId' => 10, 'Name' => '三级甲等'],
        ['LevelId' => 11, 'Name' => '三级乙等'],
        ['LevelId' => 12, 'Name' => '三级丙等'],
        ['LevelId' => 13, 'Name' => '三级其他等级'],
        ['LevelId' => 14, 'Name' => '其他等级'],
    ];

    public static function options()
    {
        return array_column(self::$map, 'LevelId');
    }

    public static function value($LevelId)
    {
        foreach (self::$map as $item) {
            if ($item['LevelId'] == $LevelId) {
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
