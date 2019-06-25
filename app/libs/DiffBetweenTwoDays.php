<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/5/7
 * Time: 下午3:10
 */

namespace App\Libs;


class DiffBetweenTwoDays
{
    /**
     * @param $day1
     * @param $day2
     * @return float|int 两个日期相差天数
     */
    public static function diffBetweenTwoDays($day1, $day2)
    {
        $second1 = strtotime($day1);
        $second2 = strtotime($day2);

        if ($second1 < $second2) {
            $tmp = $second2;
            $second2 = $second1;
            $second1 = $tmp;
        }
        return ($second1 - $second2) / 86400;
    }

    /**
     * @param string $startdate 开始日期
     * @param string $enddate   结束日期
     * @return array     包含每天的数组
     */
    public static function getDateFromRange(string $startdate, string $enddate): array
    {
        $stimestamp = strtotime($startdate);
        $etimestamp = strtotime($enddate);

        // 计算日期段内有多少天
        $days = ($etimestamp - $stimestamp) / 86400 + 1;

        // 保存每天日期
        $date = [];

        for ($i = 0; $i < $days; $i++) {
            $date[] = date('Ymd', $stimestamp + (86400 * $i));
        }
        return $date;
    }
}