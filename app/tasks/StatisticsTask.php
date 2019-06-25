<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/2/26
 * Time: 1:36 PM
 */
use Phalcon\Cli\Task;

class StatisticsTask extends Task
{
    /**
     * 控台数据统计
     * @param array $params [0] 开始日期 20170930
     * @param array $params [1] 结束日期 20171010
     */
    public function peachAction(array $params = [])
    {
        $yesterday = (int)date('Ymd', time() - 86400);
        $dates = [$yesterday];
        //如果带有时间参数，则从起止时间开始计算
        if ($params) {
            $params[1] = isset($params[1]) ? $params[1] : $yesterday;
            $dates = \App\Libs\DiffBetweenTwoDays::getDateFromRange($params[0], $params[1]);
        }

        foreach ($dates as $date) {
            //当天的起止时间
            $todayBeginTime = strtotime($date);
            $todayEndTime = strtotime($date) + 86400;
            $yesterdayDate = (int)date('Ymd', $todayBeginTime - 86400);

            //获取昨日数据
            /** @var \App\Models\PeachStatistic $peachStatistic */
            $peachStatistic = \App\Models\PeachStatistic::findFirst([
                'conditions' => 'Date=?0',
                'bind'       => [$yesterdayDate],
            ]);

            //新增医院
            $todayHospitalCounts = \App\Models\Organization::count("CreateTime>={$todayBeginTime} and CreateTime<{$todayEndTime} and IsMain in (1,3) and Id!=0");
            $totalHospitalCounts = $peachStatistic ? $peachStatistic->TotalHospitalCounts + $todayHospitalCounts : 0;
            //新增网点
            $todayClinicCounts = \App\Models\Organization::count("CreateTime>={$todayBeginTime} and CreateTime<{$todayEndTime} and IsMain=2");
            $totalClinicCounts = $peachStatistic ? $peachStatistic->TotalClinicCounts + $todayClinicCounts : 0;
            //新增转诊单
            $todayTransferCounts = \App\Models\Transfer::count("StartTime>={$todayBeginTime} and StartTime<{$todayEndTime}");
            $totalTransferCounts = $peachStatistic ? $peachStatistic->TotalTransferCounts + $todayTransferCounts : 0;
            //完成转诊单
            $sql = "select sum(if(a.GenreOne=1,a.ShareOne,a.ShareOne*a.Cost/100)) GenreOneCost,sum(if(a.CloudGenre=1,a.ShareCloud,a.ShareCloud*a.Cost/100)) GenreCloudCost,sum(a.Cost) as TotalCost,sum(if(b.GenreTwo=1,b.ShareTwo,b.ShareTwo*a.Cost/100)) GenreTwoCost 
from TransferFlow a left join Transfer b on b.Id=a.TransferId where b.EndTime>={$todayBeginTime} and b.EndTime<{$todayEndTime} and b.Status=8";
            $finishTransfers = $this->db->query($sql)->fetch();

            //总转诊金额
            $todayTransferCost = (int)$finishTransfers['TotalCost'];
            $totalTransferCost = $peachStatistic ? $peachStatistic->TotalTransferCost + $todayTransferCost : 0;
            //总首诊佣金
            $todayTransferGenre = (int)$finishTransfers['GenreOneCost'] + (int)$finishTransfers['GenreTwoCost'];
            $totalTransferGenre = $peachStatistic ? $peachStatistic->TotalTransferGenre + $todayTransferGenre : 0;
            //总手续费
            $todayTransferPlatform = (int)$finishTransfers['GenreCloudCost'];
            $totalTransferPlatform = $peachStatistic ? $peachStatistic->TotalTransferPlatform + $todayTransferPlatform : 0;

            $sql = 'REPLACE INTO PeachStatistic (`Date`, `TodayHospitalCounts`,`TotalHospitalCounts`,`TodayClinicCounts`,`TotalClinicCounts`,`TodayTransferCounts`,`TotalTransferCounts`,`TodayTransferCost`,`TotalTransferCost`,`TodayTransferGenre`,`TotalTransferGenre`,`TodayTransferPlatform`,`TotalTransferPlatform`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $r = $this->db->execute($sql, [$date, $todayHospitalCounts, $totalHospitalCounts, $todayClinicCounts, $totalClinicCounts, $todayTransferCounts, $totalTransferCounts, $todayTransferCost, $totalTransferCost, $todayTransferGenre, $totalTransferGenre, $todayTransferPlatform, $totalTransferPlatform]);
            if (!$r) {
                var_dump($sql, $r);
            }
        }
        echo 'ok' . PHP_EOL;
    }

    /**
     * 医院数据统计
     * @param array $params [0] 开始日期 20170930
     * @param array $params [1] 结束日期 20171010
     */
    public function hospitalAction(array $params = [])
    {
        $yesterday = (int)date('Ymd', time() - 86400);
        $dates = [$yesterday];
        //如果带有时间参数，则从起止时间开始计算
        if ($params) {
            $params[1] = isset($params[1]) ? $params[1] : $yesterday;
            $dates = \App\Libs\DiffBetweenTwoDays::getDateFromRange($params[0], $params[1]);
        }

        foreach ($dates as $date) {
            //当天的起止时间
            $todayBeginTime = strtotime($date);
            $todayEndTime = strtotime($date) + 86400;
            $yesterdayDate = (int)date('Ymd', $todayBeginTime - 86400);
            //获取昨日数据
            $hospitalStatistics = \App\Models\HospitalStatistic::find([
                'conditions' => 'Date=?0',
                'bind'       => [$yesterdayDate],
            ])->toArray();
            $hospitalStatistics_tmp = [];
            if (count($hospitalStatistics)) {
                foreach ($hospitalStatistics as $statistic) {
                    $hospitalStatistics_tmp[$statistic['HospitalId']] = [
                        'TotalSlave'            => $statistic['TotalSlave'],
                        'TotalTransfer'         => $statistic['TotalTransfer'],
                        'TotalTransferCost'     => $statistic['TotalTransferCost'],
                        'TotalTransferGenre'    => $statistic['TotalTransferGenre'],
                        'TotalTransferPlatform' => $statistic['TotalTransferPlatform'],
                    ];
                }
            }
            unset($hospitalStatistics);

            //当前所有的医院
            $hospitals = \App\Models\Organization::find([
                'conditions' => 'IsMain=1 and Id>0 and CreateTime<=' . $todayEndTime,
                'columns'    => 'Id',
            ]);

            //获取网点数
            $sql = "select count(*) as SlaveAmount,MainId from OrganizationRelationship a left join Organization b on b.Id=a.MinorId where a.Created>={$todayBeginTime} and a.Created<{$todayEndTime} and b.IsMain=2 and b.Fake=0 group by a.MainId";
            $slaves = $this->db->query($sql)->fetchAll();
            $slaves_tmp = [];
            if (count($slaves)) {
                foreach ($slaves as $slave) {
                    $slaves_tmp[$slave['MainId']] = (int)$slave['SlaveAmount'];
                }
            }
            unset($slaves);

            //获取新增转诊单
            $sql = "select count(*) SlaveAmount,AcceptOrganizationId from Transfer where StartTime>={$todayBeginTime} and StartTime<{$todayEndTime} and IsFake=0 group by AcceptOrganizationId";
            $newTransfers = $this->db->query($sql)->fetchAll();
            $newTransfers_tmp = [];
            if (count($newTransfers)) {
                foreach ($newTransfers as $newTransfer) {
                    $newTransfers_tmp[$newTransfer['AcceptOrganizationId']] = (int)$newTransfer['SlaveAmount'];
                }
            }
            unset($newTransfers);

            //获取完结的转诊单
            $sql = "select b.AcceptOrganizationId,sum(if(a.GenreOne=1,a.ShareOne,a.ShareOne*a.Cost/100)) GenreOneCost,sum(if(a.CloudGenre=1,a.ShareCloud,a.ShareCloud*a.Cost/100)) GenreCloudCost,sum(a.Cost) as TotalCost,sum(if(b.GenreTwo=1,b.ShareTwo,b.ShareTwo*a.Cost/100)) GenreTwoCost 
from TransferFlow a left join Transfer b on b.Id=a.TransferId where b.EndTime>={$todayBeginTime} and b.EndTime<{$todayEndTime} and b.Status=8 and b.IsFake=0 GROUP BY b.AcceptOrganizationId";
            $finishTransfers = $this->db->query($sql)->fetchAll();
            $finishTransfers_tmp = [];
            if (count($finishTransfers)) {
                foreach ($finishTransfers as $finishTransfer) {
                    $finishTransfers_tmp[$finishTransfer['AcceptOrganizationId']] = [
                        'GenreCost'      => (int)$finishTransfer['GenreOneCost'] + (int)$finishTransfer['GenreTwoCost'],
                        'GenreCloudCost' => (int)$finishTransfer['GenreCloudCost'],
                        'TotalCost'      => (int)$finishTransfer['TotalCost'],
                    ];
                }
            }
            unset($finishTransfers);

            foreach ($hospitals as $hospital) {
                $key = $hospital->Id;
                $todaySlave = isset($slaves_tmp[$key]) ? $slaves_tmp[$key] : 0;
                $totalSlave = isset($hospitalStatistics_tmp[$key]) ? $hospitalStatistics_tmp[$key]['TotalSlave'] + $todaySlave : $todaySlave;

                $todayTransfer = isset($newTransfers_tmp[$key]) ? $newTransfers_tmp[$key] : 0;
                $totalTransfer = isset($hospitalStatistics_tmp[$key]) ? $hospitalStatistics_tmp[$key]['TotalTransfer'] + $todayTransfer : $todayTransfer;

                $todayTransferCost = isset($finishTransfers_tmp[$key]) ? $finishTransfers_tmp[$key]['TotalCost'] : 0;
                $totalTransferCost = isset($hospitalStatistics_tmp[$key]) ? $hospitalStatistics_tmp[$key]['TotalTransferCost'] + $todayTransferCost : $todayTransferCost;

                $todayTransferGenre = isset($finishTransfers_tmp[$key]) ? $finishTransfers_tmp[$key]['GenreCost'] : 0;
                $totalTransferGenre = isset($hospitalStatistics_tmp[$key]) ? $hospitalStatistics_tmp[$key]['TotalTransferGenre'] + $todayTransferGenre : $todayTransferGenre;

                $todayTransferPlatform = isset($finishTransfers_tmp[$key]) ? $finishTransfers_tmp[$key]['GenreCloudCost'] : 0;
                $totalTransferPlatform = isset($hospitalStatistics_tmp[$key]) ? $hospitalStatistics_tmp[$key]['TotalTransferPlatform'] + $todayTransferPlatform : $todayTransferPlatform;

                $sql = 'REPLACE INTO HospitalStatistic (`Date`, `HospitalId`,`TodaySlave`,`TotalSlave`,`TodayTransfer`,`TotalTransfer`,`TodayTransferCost`,`TotalTransferCost`,`TodayTransferGenre`,`TotalTransferGenre`,`TodayTransferPlatform`,`TotalTransferPlatform`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)';
                $r = $this->db->execute($sql, [$date, $hospital->Id, $todaySlave, $totalSlave, $todayTransfer, $totalTransfer, $todayTransferCost, $totalTransferCost, $todayTransferGenre, $totalTransferGenre, $todayTransferPlatform, $totalTransferPlatform]);
                if (!$r) {
                    var_dump($sql, $r);
                }
            }
        }
        echo 'ok' . PHP_EOL;
    }

    /**
     * 销售人员数据统计
     * @param array $params [0] 开始日期 20170930
     * @param array $params [1] 结束日期 20171010
     */
    public function salesmanAction(array $params = [])
    {
        $yesterday = (int)date('Ymd', time() - 86400);
        $dates = [$yesterday];
        //如果带有时间参数，则从起止时间开始计算
        if ($params) {
            $params[1] = isset($params[1]) ? $params[1] : $yesterday;
            $dates = \App\Libs\DiffBetweenTwoDays::getDateFromRange($params[0], $params[1]);
        }
        foreach ($dates as $date) {
            //当天的起止时间
            $todayBeginTime = strtotime($date);
            $todayEndTime = strtotime($date) + 86400;
            $yesterdayDate = (int)date('Ymd', $todayBeginTime - 86400);
            //获取昨日数据
            $salesmanStatistics = \App\Models\HospitalSalesmanStatistic::find([
                'conditions' => 'Date=?0',
                'bind'       => [$yesterdayDate],
            ])->toArray();
            $salesmanStatistics_tmp = [];
            if (count($salesmanStatistics)) {
                foreach ($salesmanStatistics as $statistic) {
                    $salesmanStatistics_tmp[$statistic['HospitalId'] . ':' . $statistic['UserId']] = ['TotalSlave' => $statistic['TotalSlave'], 'TotalTransfer' => $statistic['TotalTransfer'], 'TotalTransferCost' => $statistic['TotalTransferCost']];
                }
            }
            unset($salesmanStatistics);

            //销售人员
            $organizationUsers = \App\Models\OrganizationUser::query()
                ->columns(['OrganizationId', 'UserId'])
                ->andWhere('IsSalesman=1')
                ->andWhere('CreateTime<=' . $todayEndTime)
                ->execute();
            //新增的网点
            $sql = "select count(*) as SlaveAmount,MainId,SalesmanId from OrganizationRelationship a left join Organization b on b.Id=a.MinorId where a.Created>={$todayBeginTime} and a.Created<{$todayEndTime} and b.IsMain=2 and b.Fake=0 group by a.MainId,a.SalesmanId";
            $slaves = $this->db->query($sql)->fetchAll();
            $slaves_tmp = [];
            if (count($slaves)) {
                foreach ($slaves as $slave) {
                    $slaves_tmp[$slave['MainId'] . ':' . $slave['SalesmanId']] = (int)$slave['SlaveAmount'];
                }
            }
            unset($slaves);

            //该医院新增转诊单
            $sql = "select count(*) as TransferAmount,c.MainId,c.SalesmanId from (select a.SalesmanId,a.MainId from OrganizationRelationship a 
left join Transfer b on b.AcceptOrganizationId=a.MainId and b.SendOrganizationId=a.MinorId
where b.StartTime>={$todayBeginTime} and b.StartTime<{$todayEndTime} and b.Genre=1 and b.IsFake=0) c
group by c.MainId,c.SalesmanId";
            $transfers = $this->db->query($sql)->fetchAll();
            $transfers_tmp = [];
            if (count($transfers)) {
                foreach ($transfers as $transfer) {
                    $transfers_tmp[$transfer['MainId'] . ':' . $transfer['SalesmanId']] = (int)$transfer['TransferAmount'];
                }
            }
            unset($transfers);

            //该医院完结的转诊单
            $sql = "select sum(c.Cost) as TransferCost,c.MainId,c.SalesmanId from (select a.SalesmanId,a.MainId,b.Cost from OrganizationRelationship a 
left join Transfer b on b.AcceptOrganizationId=a.MainId and b.SendOrganizationId=a.MinorId
where b.EndTime>={$todayBeginTime} and b.EndTime<{$todayEndTime} and b.Genre=1 and b.Status=8 and b.IsFake=0) c
group by c.MainId,c.SalesmanId";
            $finishTransfers = $this->db->query($sql)->fetchAll();
            $finishTransfers_tmp = [];
            if (count($finishTransfers)) {
                foreach ($finishTransfers as $finishTransfer) {
                    $finishTransfers_tmp[$finishTransfer['MainId'] . ':' . $finishTransfer['SalesmanId']] = (int)$finishTransfer['TransferCost'];
                }
            }
            unset($finishTransfers);

            foreach ($organizationUsers as $organizationUser) {
                $key = $organizationUser->OrganizationId . ':' . $organizationUser->UserId;

                $todaySlave = isset($slaves_tmp[$key]) ? $slaves_tmp[$key] : 0;
                $totalSlave = isset($salesmanStatistics_tmp[$key]) ? $salesmanStatistics_tmp[$key]['TotalSlave'] + $todaySlave : $todaySlave;

                $todayTransfer = isset($transfers_tmp[$key]) ? $transfers_tmp[$key] : 0;
                $totalTransfer = isset($salesmanStatistics_tmp[$key]) ? $salesmanStatistics_tmp[$key]['TotalTransfer'] + $todayTransfer : $todayTransfer;

                $todayTransferCost = isset($finishTransfers_tmp[$key]) ? $finishTransfers_tmp[$key] : 0;
                $totalTransferCost = isset($salesmanStatistics_tmp[$key]) ? $salesmanStatistics_tmp[$key]['TotalTransferCost'] + $todayTransferCost : $todayTransferCost;

                $sql = 'REPLACE INTO HospitalSalesmanStatistic (`Date`, `HospitalId`,`UserId`,`TodaySlave`,`TotalSlave`,`TodayTransfer`,`TotalTransfer`,`TodayTransferCost`,`TotalTransferCost`) VALUES (?,?,?,?,?,?,?,?,?)';
                $r = $this->db->execute($sql, [$date, $organizationUser->OrganizationId, $organizationUser->UserId, $todaySlave, $totalSlave, $todayTransfer, $totalTransfer, $todayTransferCost, $totalTransferCost]);
                if (!$r) {
                    var_dump($sql, $r);
                }
            }
        }
        echo 'ok' . PHP_EOL;
    }

    /**
     * 将医院id分割成多等份以便来查询
     */
    public function chunkHospital(int $createTime)
    {
        //计算总个数
        $hospitals = \App\Models\Organization::find([
            'conditions' => 'IsMain=1 and Id>0 and CreateTime<=' . $createTime,
            'columns'    => 'Id',
        ])->toArray();
        return array_chunk(array_column($hospitals, 'Id'), 200);
    }

}