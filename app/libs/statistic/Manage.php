<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/2/28
 * Time: 4:12 PM
 */

namespace App\Libs\statistic;

use App\Models\HospitalStatistic;
use Phalcon\Di\FactoryDefault;

class Manage
{
    public static function hospitalStatistics(array $dates, string $columns): array
    {
        $hospitalStatistics = HospitalStatistic::query()
            ->columns(['Date', $columns])
            ->where(sprintf('HospitalId=%d', FactoryDefault::getDefault()->get('session')->get('auth')['OrganizationId']))
            ->inWhere('Date', $dates)
            ->execute()->toArray();
        return $hospitalStatistics;
    }

    /**
     * @param array $dates
     * @param string $columns
     * @param int $type     1=>增量，2=>总量
     * @param int $dateType 1=>天，2=>周，3=>月
     * @param int $amount   数量
     * @return array
     */
    public static function manageForType(array $dates, string $columns, int $type, int $dateType, int $amount = 30)
    {
        $columns = $dateType == 1 && $type == 1 ? 'Today' . $columns : 'Total' . $columns;
        $hospitalStatistics = self::hospitalStatistics($dates, $columns);

        $result = [];
        $countHospitalStatistics = count($hospitalStatistics);
        if ($countHospitalStatistics) {
            $date = [];
            $value = [];
            if ($type == 1 && $dateType != 1) {
                $tmp = 0;
                foreach ($hospitalStatistics as $k => $statistics) {
                    if ($k == 0 && $countHospitalStatistics <= $amount) {
                        $date[] = $statistics['Date'];
                        $value[] = $statistics[$columns];
                    }
                    if ($k > 0) {
                        $date[] = $statistics['Date'];
                        $value[] = $statistics[$columns] - $tmp;
                    }
                    $tmp = $statistics[$columns];
                }
            } else {
                foreach ($hospitalStatistics as $statistics) {
                    $date[] = $statistics['Date'];
                    $value[] = $statistics[$columns];
                }
            }
            $date = self::dealDate($date, $dateType);
            $result = ['Date' => $date, 'Value' => $value];
        }

        return $result;
    }

    /**
     * 处理日期格式
     * @param array $dates
     * @param int $dateType 1=>天，2=>周，3=>月
     * @return array
     */
    public static function dealDate(array $dates, int $dateType): array
    {
        $result = [];
        $sameYear = 0;
        foreach ($dates as $date) {
            $year = (int)mb_substr($date, 0, 4);
            $month = (int)mb_substr($date, 4, 2);
            $day = (int)mb_substr($date, 6, 2);
            if ($dateType == 3) {
                $result[] = sprintf('%d年%d月', $year, $month);
            } else {
                if ($sameYear) {
                    if ($year !== $sameYear) {
                        $result[] = sprintf('%d/%d/%d', $year, $month, $day);
                    } else {
                        $result[] = sprintf('%d/%d', $month, $day);
                    }
                } else {
                    $result[] = sprintf('%d/%d', $month, $day);
                }
                $sameYear = $year;
            }
        }
        return $result;
    }
}