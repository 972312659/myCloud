<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/2/28
 * Time: 9:50 AM
 */

namespace App\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Libs\ReportDate;
use App\Libs\statistic\Manage;
use App\Models\HospitalStatistic;
use Phalcon\Paginator\Adapter\NativeArray;

class StatisticController extends Controller
{
    /**
     * 不同周期类型对应的数值
     * @var array
     * 'key'=>[1=>天,2=>周,3=>月]
     */
    private $dateAmount = [1 => 30, 2 => 30, 3 => 12];

    /**
     * 基础信息
     */
    public function baseAction()
    {
        $hospitalId = $this->session->get('auth')['OrganizationId'];
        $yesterday = date("Ymd", strtotime("-1 day"));
        /** @var HospitalStatistic $yesterdayStatistic */
        $yesterdayStatistic = HospitalStatistic::findFirst([
            'conditions' => 'Date=?0 and HospitalId=?1',
            'bind'       => [$yesterday, $hospitalId],
        ]);

        //今天周几
        $week = (int)date('w');
        if ($week == 2) {
            /** @var HospitalStatistic $mondayStatistic */
            $mondayStatistic = $yesterdayStatistic;
        } else {
            switch ($week) {
                case 0:
                    $monday = date("Ymd", strtotime("-6 day"));
                    break;
                case 1:
                    $monday = date("Ymd", strtotime("-1 week"));
                    break;
                default:
                    $days = $week - 1;
                    $monday = date("Ymd", strtotime("-{$days} day"));
            }
            /** @var HospitalStatistic $mondayStatistic */
            $mondayStatistic = HospitalStatistic::findFirst([
                'conditions' => 'Date=?0 and HospitalId=?1',
                'bind'       => [$monday, $hospitalId],
            ]);
        }

        //今天几号
        $month = (int)date('d');
        if ($month == 2) {
            /** @var HospitalStatistic $beginningOfMonthStatistic */
            $beginningOfMonthStatistic = $yesterdayStatistic;
        } else {
            switch ($month) {
                case 1:
                    $beginningOfMonth = date('Ym01', strtotime('-1 month'));
                    break;
                default:
                    $beginningOfMonth = date('Ym01');
            }
            /** @var HospitalStatistic $beginningOfMonthStatistic */
            $beginningOfMonthStatistic = HospitalStatistic::findFirst([
                'conditions' => 'Date=?0 and HospitalId=?1',
                'bind'       => [$beginningOfMonth, $hospitalId],
            ]);
        }

        $this->response->setJsonContent([
            'DateTime'          => date("Y-m-d 23:59:59", strtotime("-1 day")),
            'SlaveToday'        => $yesterdayStatistic->TodaySlave,
            'SlaveWeek'         => $week == 2 ? $yesterdayStatistic->TodaySlave : $yesterdayStatistic->TotalSlave - ($mondayStatistic ? ($mondayStatistic->TotalSlave - $mondayStatistic->TodaySlave) : 0),
            'SlaveMonth'        => $month == 2 ? $yesterdayStatistic->TodaySlave : $yesterdayStatistic->TotalSlave - ($beginningOfMonthStatistic ? ($beginningOfMonthStatistic->TotalSlave - $beginningOfMonthStatistic->TodaySlave) : 0),
            'SlaveTotal'        => $yesterdayStatistic->TotalSlave,
            'TransferToday'     => $yesterdayStatistic->TodayTransfer,
            'TransferWeek'      => $week == 2 ? $yesterdayStatistic->TodayTransfer : $yesterdayStatistic->TotalTransfer - ($mondayStatistic ? ($mondayStatistic->TotalTransfer - $mondayStatistic->TodayTransfer) : 0),
            'TransferMonth'     => $month == 2 ? $yesterdayStatistic->TodayTransfer : $yesterdayStatistic->TotalTransfer - ($beginningOfMonthStatistic ? ($beginningOfMonthStatistic->TotalTransfer - $beginningOfMonthStatistic->TodayTransfer) : 0),
            'TransferTotal'     => $yesterdayStatistic->TotalTransfer,
            'TransferCostToday' => $yesterdayStatistic->TodayTransferCost,
            'TransferCostWeek'  => $week == 2 ? $yesterdayStatistic->TodayTransferCost : $yesterdayStatistic->TotalTransferCost - ($mondayStatistic ? ($mondayStatistic->TotalTransferCost - $mondayStatistic->TodayTransferCost) : 0),
            'TransferCostMonth' => $month == 2 ? $yesterdayStatistic->TodayTransferCost : $yesterdayStatistic->TotalTransferCost - ($beginningOfMonthStatistic ? ($beginningOfMonthStatistic->TotalTransferCost - $beginningOfMonthStatistic->TodayTransferCost) : 0),
            'TransferCostTotal' => $yesterdayStatistic->TotalTransferCost,
        ]);
    }

    /**
     * 网点数量
     * Type     1=>增量，2=>总量
     * DateType 1=>天，2=>周，3=>月
     * Amount   周期数
     */
    public function slaveAction()
    {
        $dateType = (int)$this->request->get('DateType') ?: 1;
        $type = (int)$this->request->get('Type') ?: 1;
        $yesterday = date("Ymd", strtotime("-1 day"));

        $RealAmount = $this->dateAmount[$dateType];

        $amount = $type == 2 || ($type == 1 && $dateType == 1) ? $RealAmount : $RealAmount + 1;
        $dates = ReportDate::getLastDates($dateType, $amount, $yesterday);

        $result = Manage::manageForType($dates, 'Slave', $type, $dateType, $RealAmount);

        $this->response->setJsonContent($result);
    }

    /**
     * 转诊订单
     * Type     1=>增量，2=>总量
     * DateType 1=>天，2=>周，3=>月
     * Amount   周期数
     */
    public function transferBeginAction()
    {
        $dateType = (int)$this->request->get('DateType') ?: 1;
        $type = (int)$this->request->get('Type') ?: 1;
        $yesterday = date("Ymd", strtotime("-1 day"));

        $RealAmount = $this->dateAmount[$dateType];

        $amount = $type == 2 || ($type == 1 && $dateType == 1) ? $RealAmount : $RealAmount + 1;
        $dates = ReportDate::getLastDates($dateType, $amount, $yesterday);

        $result = Manage::manageForType($dates, 'Transfer', $type, $dateType, $RealAmount);

        $this->response->setJsonContent($result);
    }

    /**
     * 结算金额
     * Type     1=>增量，2=>总量
     * DateType 1=>天，2=>周，3=>月
     * Amount   周期数
     * MoneyType 1=>订单结算金额 2=>网点首诊费 3=>平台手续费
     */
    public function transferFinishAction()
    {
        $dateType = (int)$this->request->get('DateType') ?: 1;
        $type = (int)$this->request->get('Type') ?: 1;
        $moneyType = (int)$this->request->get('MoneyType') ?: 1;
        $yesterday = date("Ymd", strtotime("-1 day"));

        $RealAmount = $this->dateAmount[$dateType];

        $amount = $type == 2 || ($type == 1 && $dateType == 1) ? $RealAmount : $RealAmount + 1;
        $dates = ReportDate::getLastDates($dateType, $amount, $yesterday);

        $columns = $moneyType == 1 ? 'TransferCost' : ($moneyType == 2 ? 'TransferGenre' : 'TransferPlatform');
        $result = Manage::manageForType($dates, $columns, $type, $dateType, $RealAmount);

        $this->response->setJsonContent($result);
    }

    /**
     * 业务经理
     */
    public function salesmanAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $totalPage = 1;
        $data['DateType'] = isset($data['DateType']) && is_numeric($data['DateType']) ? $data['DateType'] : 2;

        $hospitalId = $this->session->get('auth')['OrganizationId'];
        $yesterday = (int)date("Ymd", strtotime("-1 day"));

        $date = [$yesterday];
        //开始时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $startTime = (int)date("Ymd", $data['StartTime'] - 86400);
            if ($startTime > $yesterday) {
                throw new LogicException(Status::BadRequest, '开始日期只能早于今天');
            }
            $date[] = (int)date("Ymd", $data['StartTime'] - 86400);
        }
        //结束时间
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                throw new LogicException(Status::BadRequest, '结束日期不能早于开始日期');
            }
            $endDate = (int)date("Ymd", $data['EndTime']);
            if ($yesterday > $endDate) {
                unset($date[0]);
                $date[] = $endDate;
            }
        }

        $date = array_unique($date);
        $count = count($date);
        $datas = [];
        if ($count) {
            $dateString = '(' . implode(',', $date) . ')';
            if ($count == 1) {
                $phql = "select CONVERT(IFNULL(a.TotalSlave,0),SIGNED) TotalSlave,CONVERT(IFNULL(a.TotalTransfer,0),SIGNED) TotalTransfer,CONVERT(IFNULL(a.TotalTransferCost,0),SIGNED) TotalTransferCost,CONVERT(IFNULL(a.TodaySlave,0),SIGNED) NewSlave,CONVERT(IFNULL(a.TodayTransfer,0),SIGNED) NewTransfer,CONVERT(IFNULL(a.TodayTransferCost,0),SIGNED) NewTransferCost,u.Id UserId,u.Name SalesmanName 
from OrganizationUser ou 
left join User u on u.Id=ou.UserId
left join  HospitalSalesmanStatistic a on a.UserId=ou.UserId and a.HospitalId=ou.OrganizationId
where ou.OrganizationId={$hospitalId} and a.`Date` in {$dateString}";
            } else {
                $phql = "select 
CONVERT(IFNULL(b.TotalSlave,0),SIGNED) TotalSlave,CONVERT(IFNULL(b.TotalTransfer,0),SIGNED) TotalTransfer,CONVERT(IFNULL(b.TotalTransferCost,0),SIGNED) TotalTransferCost,CONVERT(IFNULL(a.TodaySlave ,0),SIGNED) NewSlave,CONVERT(IFNULL(a.TodayTransfer ,0),SIGNED) NewTransfer,CONVERT(IFNULL(a.TodayTransferCost ,0),SIGNED) NewTransferCost,u.Id UserId,u.Name SalesmanName 
from 
(select * from OrganizationUser where OrganizationId={$hospitalId} and IsSalesman=1) ou
left join
(select UserId,CONVERT((MAx(TotalSlave)-if(count(Date)=1,0,MIN(TotalSlave))),SIGNED) TodaySlave,CONVERT((MAx(TotalTransfer)-if(count(Date)=1,0,MIN(TotalTransfer))),SIGNED) TodayTransfer,CONVERT((MAx(TotalTransferCost)-if(count(Date)=1,0,MIN(TotalTransferCost))),SIGNED) TodayTransferCost from HospitalSalesmanStatistic where HospitalId={$hospitalId} and `Date` in {$dateString} group by UserId) a 
on a.UserId=ou.UserId
left join User u on u.Id=ou.UserId
left join (select UserId,TotalSlave,TotalTransfer,TotalTransferCost from HospitalSalesmanStatistic where HospitalId={$hospitalId} and `Date`={$yesterday}) b on b.UserId=u.Id";
            }
            $bind = [];
            //业务经理查询
            if (isset($data['SalesmanName']) && !empty($data['SalesmanName'])) {
                $phql .= " where u.Name=?";
                $bind[] = $data['SalesmanName'];
            }
            //排序
            switch ($data['SortName']) {
                case 'NewSlave':
                    $sortName = 'a.TodaySlave';
                    break;
                case 'NewTransfer':
                    $sortName = 'a.TodayTransfer';
                    break;
                case 'NewTransferCost':
                    $sortName = 'a.TodayTransferCost';
                    break;
                case 'TotalTransfer':
                    $sortName = $count == 1 ? 'a.TotalTransfer' : 'b.TotalTransfer';
                    break;
                case 'TotalTransferCost':
                    $sortName = $count == 1 ? 'a.TotalTransferCost' : 'b.TotalTransferCost';
                    break;
                default:
                    $sortName = $count == 1 ? 'a.TotalSlave' : 'b.TotalSlave';
            }
            $sortType = isset($data['SortType']) && $data['SortType'] == 'ascending' ? 'asc' : 'desc';
            $phql .= " ORDER BY {$sortName} {$sortType},u.Id asc";

            $paginator = new NativeArray([
                'data'  => $this->db->query($phql, $bind)->fetchAll(),
                'limit' => $pageSize,
                'page'  => $page,
            ]);
            $pages = $paginator->getPaginate();
            $totalPage = $pages->total_pages;
            $count = $pages->total_items;
            $datas = $pages->items;
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 单个业务经理
     */
    public function readSalesmanAction()
    {
        //todo 下个版本
    }
}