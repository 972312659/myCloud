<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/10/12
 * Time: 下午3:35
 */

namespace App\Libs;


class ReportDate
{
    public static function getDateFromRange(int $startdate, int $enddate): array
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


    public static function getWeekFromRange(int $startDate, int $endDate): array
    {
        //开始时间
        $startDate = date('Ymd', $startDate);
        //结束时间
        if (empty($endDate)) {
            $endDate = date('Ymd');
        } else {
            $endDate = date('Ymd', $endDate);
        }
        //跨越天数
        $n = (strtotime($endDate) - strtotime($startDate)) / 86400;
        //判断，跨度小于7天，可能是同一周，也可能是两周
        $endDate = date("Ymd", strtotime("$endDate +1 day"));
        if ($n < 7) {
            //查开始时间 在 那周 的 位置
            $day = date("w", strtotime($startDate)) - 1;
            //查开始时间  那周 的 周末
            $day = 7 - $day;
            $week_end = date("Ymd", strtotime("$startDate + {$day} day"));
            //判断周末时间是否大于时间段的结束时间，如果大于，那就是时间段在同一周，否则时间段跨两周
            if ($week_end >= $endDate) {
                $weekList[] = $startDate;
                $weekList[] = date("Ymd", strtotime("$endDate -1 day"));
            } else {
                $weekList[] = $startDate;
                $weekList[] = $week_end;
                $weekList[] = date("Ymd",strtotime("$endDate -1 day"));
            }
        } else {
            //如果跨度大于等于7天，可能是刚好1周或跨2周或跨N周，先找出开始时间 在 那周 的 位置和那周的周末时间
            $day = date("w", strtotime($startDate)) - 1;
            $day = 7 - $day;
            $week_end = date("Ymd", strtotime("$startDate +{$day} day"));
            //先把开始时间那周写入数组
            $weekList[] = date("Ymd",strtotime("$week_end -1 day"));
            //判断周末是否大于等于结束时间，不管大于(2周)还是等于(1周)，结束时间都是时间段的结束时间。
            if ($week_end >= $endDate) {
                $weekList[] = $week_end;
                $weekList[] = date("Ymd", strtotime("$endDate -1 day"));
            } else {
                //N周的情况用while循环一下，然后写入数组
                while($week_end <= $endDate){
                    $week_end    = date("Ymd",strtotime("$week_end +7 day"));
                    if($week_end <= $endDate){
                        $weekList[] = date("Ymd",strtotime("$week_end -1 day"));
                    }else{
                        $weekList[] = date("Ymd",strtotime("$endDate -1 day"));
                    }
                }
            }
        }
        return $weekList;
    }


    public static function getMonthFromRange(int $start, int $end)
    {
        if (!is_numeric($start) || !is_numeric($end) || ($end <= $start)) return '';
        $endDate = $end;
        $start = date('Ym', $start);
        $end = date('Ym', $end);
        //转为时间戳
        $start = strtotime($start . '01');
        $end = strtotime($end . '01');
        $i = 0;
        $d = [];
        while ($start <= $end) {
            //这里累加每个月的的总秒数 计算公式：上一月1号的时间戳秒数减去当前月的时间戳秒数
            $d[$i] = trim(date('Ymd', $start), ' ');
            $start += strtotime('+1 month', $start) - $start;
            $i++;
        }
        if($end != $endDate){
            $d[] = date('Ymd',$endDate);
        }
        return $d;
    }


    /**
     * @param int $type    类型（1=>天，2=>周，2=>月）
     * @param int $amount  数量（得到的数量）
     * @param string $date 年月日（20190227）
     * @return array
     */
    public static function getLastDates(int $type, int $amount, string $date): array
    {
        $dates = [$date];
        $amount--;
        switch ($type) {
            case 1:
                for ($i = 2; $i <= $amount + 1; $i++) $dates[] = date("Ymd", strtotime(" -{$i} day"));
                break;
            case 2:
                for ($i = 1; $i <= $amount; $i++) $dates[] = date("Ymd", strtotime(" -{$i} sunday"));
                break;
            case 3:
                for ($i = 1; $i <= $amount; $i++) $dates[] = date("Ymd", strtotime("last day of -{$i} month"));
                break;
        }
        return $dates;
    }
}