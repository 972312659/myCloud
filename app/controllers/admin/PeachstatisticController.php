<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/10/12
 * Time: 上午10:31
 */

namespace App\Admin\Controllers;


use App\Enums\Status;
use App\Exceptions\ParamException;
use App\Libs\ReportDate;
use App\Libs\statistic\Manage;
use App\Models\HospitalSalesmanStatistic;
use App\Models\HospitalStatistic;
use App\Models\Organization;
use App\Models\User;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use App\Models\PeachStatistic;
use Phalcon\Paginator\Adapter\QueryBuilder;

class PeachstatisticController extends Controller
{

    /**
     * 新增数据信息统计
     */

    public function getInfoAction(){

        //todo redis 取数据

        $yesterday = date("Ymd", strtotime("-1 day"));
        /** @var PeachStatistic $yesterdayStatistic */
        $yesterdayStatistic = PeachStatistic::findFirst([
            'conditions' => 'Date=?0',
            'bind'       => [$yesterday],
        ]);

        /** @var PeachStatistic $newYesStatistic */
        $newYesStatistic = PeachStatistic::findFirst([
            'conditions' => 'Date<=?0 and TotalHospitalCounts > 0 and TotalClinicCounts > 0  and TotalTransferCost > 0 ORDER BY Date DESC',
            'bind'       => [$yesterday],
        ]);

        //今天周几
        $week = (int)date('w');
        if ($week == 2) {
            /** @var PeachStatistic $mondayStatistic*/
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
            /** @var PeachStatistic $weekYesStatistic */
            $weekYesStatistic = PeachStatistic::findFirst([
                'conditions' => 'Date<=?0 and  Date>=?1 and TotalHospitalCounts > 0 and TotalClinicCounts > 0  and TotalTransferCost > 0 ORDER BY Date DESC',
                'bind'       => [$yesterday,$monday],
            ]);
            /** @var PeachStatistic $mondayStatistic */
            $mondayStatistic = PeachStatistic::findFirst([
                'conditions' => 'Date=?0',
                'bind'       => [$monday],
            ]);
        }


        //今天几号
        $month = (int)date('d');
        if ($month == 2) {
            /** @var PeachStatistic $beginningOfMonthStatistic */
            $beginningOfMonthStatistic = $yesterdayStatistic;
        } else {
            switch ($month) {
                case 1:
                    $beginningOfMonth = date('Ym01', strtotime('-1 month'));
                    break;
                default:
                    $beginningOfMonth = date('Ym01');
            }
            /** @var PeachStatistic $beginningOfMonthStatistic */
            $beginningOfMonthStatistic = PeachStatistic::findFirst([
                'conditions' => 'Date=?0',
                'bind'       => [$beginningOfMonth],
            ]);
            /** @var PeachStatistic $yesterdayStatistic */
            $monthYesStatistic = PeachStatistic::findFirst([
                'conditions' => 'Date<=?0 and  Date>=?1 and TotalHospitalCounts > 0 and TotalClinicCounts > 0  and TotalTransferCost > 0  ORDER BY Date DESC',
                'bind'       => [$yesterday,$beginningOfMonth],
            ]);
        }


        $this->response->setJsonContent([
            'DateTime'          => date("Y-m-d 23:59:59", strtotime("-1 day")),

            'HospitalToday'     => $yesterdayStatistic->TodayHospitalCounts??0,
            'HospitalWeek'      => $week == 2 ? ($yesterdayStatistic->TodayHospitalCounts??0): ($weekYesStatistic->TotalHospitalCounts??0) - ($mondayStatistic->TotalHospitalCounts??0) + ($mondayStatistic->TodayHospitalCounts??0),
            'HospitalMonth'     => $month == 2 ? ($yesterdayStatistic->TodayHospitalCounts??0) : ($monthYesStatistic->TotalHospitalCounts??0) - ($beginningOfMonthStatistic->TotalHospitalCounts??0) + ($beginningOfMonthStatistic->TodayHospitalCounts??0),
            'HospitalTotal'     => $yesterdayStatistic->TotalHospitalCounts??$newYesStatistic->TotalHospitalCounts,

            'SlaveToday'        => $yesterdayStatistic->TodayClinicCounts??0,
            'SlaveWeek'         => $week == 2 ? ($yesterdayStatistic->TodayClinicCounts??0) : ($weekYesStatistic->TotalClinicCounts??0) - ($mondayStatistic->TotalClinicCounts??0) + ($mondayStatistic->TodayClinicCounts??0),
            'SlaveMonth'        => $month == 2 ? ($yesterdayStatistic->TodayClinicCounts??0) : ($monthYesStatistic->TotalClinicCounts??0) - ($beginningOfMonthStatistic->TotalClinicCounts??0) + ($beginningOfMonthStatistic->TodayClinicCounts??0),
            'SlaveTotal'        => $yesterdayStatistic->TotalClinicCounts??$newYesStatistic->TotalClinicCounts,

            'TransferToday'     => $yesterdayStatistic->TodayTransferCounts??0,
            'TransferWeek'      => $week == 2 ? ($yesterdayStatistic->TodayTransferCounts??0) : ($weekYesStatistic->TotalTransferCounts??0) - ($mondayStatistic->TotalTransferCounts??0) +  ($mondayStatistic->TodayTransferCounts??0),
            'TransferMonth'     => $month == 2 ? ($yesterdayStatistic->TodayTransferCounts??0)  : ($monthYesStatistic->TotalTransferCounts??0) - ($beginningOfMonthStatistic->TotalTransferCounts??0) + ($beginningOfMonthStatistic->TodayTransferCounts??0) ,
            'TransferTotal'     => $yesterdayStatistic->TotalTransferCounts??$newYesStatistic->TotalTransferCounts,

            'TransferCostToday' => $yesterdayStatistic->TodayTransferCost??0,
            'TransferCostWeek'  => $week == 2 ? ($yesterdayStatistic->TodayTransferCost??0)  : ($weekYesStatistic->TotalTransferCost??0) - ($mondayStatistic->TotalTransferCost??0) + ($mondayStatistic->TodayTransferCost??0),
            'TransferCostMonth' => $month == 2 ? ($yesterdayStatistic->TodayTransferCost??0)  : ($monthYesStatistic->TotalTransferCost??0) - ($beginningOfMonthStatistic->TotalTransferCost??0) + ($beginningOfMonthStatistic->TodayTransferCost??0),
            'TransferCostTotal' => $yesterdayStatistic->TotalTransferCost??$newYesStatistic->TotalTransferCost,

        ]);
    }


    /**
     *
     * 数据统计接口
     */
    public function dataStatisticAction()
    {
        $validation = new Validation();
        $validation->rules('Obj', [
            new PresenceOf(['message' => '统计对象obj不能为空']),
        ]);
        $validation->rules('Type', [
            new PresenceOf(['message' => '查询时间type不能为空']),
        ]);
        $validation->rules('Date', [
            new PresenceOf(['message' => '增加类型date不能为空']),
        ]);
        $ex = new ParamException(Status::BadRequest);
        $data = $this->request->getPost();
        $ret = $validation->validate($data);
        if (count($ret) > 0) {
            $ex->loadFromMessage($ret);
            throw $ex;
        }
        $todayobj = $totalobj = '';
        if (!empty($data['Obj']) && isset($data['Obj'])) {
            $obj = $data['Obj'];
            switch ($obj){
                case 'Hospital':
                        $todayobj = 'TodayHospitalCounts';
                        $totalobj = 'TotalHospitalCounts';
                    break;
                case 'Clinic':
                        $todayobj = 'TodayClinicCounts';
                        $totalobj = 'TotalClinicCounts';
                    break;
                case 'Transfer':
                        $todayobj = 'TodayTransferCounts';
                        $totalobj = 'TotalTransferCounts';
                    break;
                case 'Cost':
                        $todayobj = 'TodayTransferCost';
                        $totalobj = 'TotalTransferCost';
                    break;
                case 'Genre':
                        $todayobj = 'TodayTransferGenre';
                        $totalobj = 'TotalTransferGenre';
                    break;
                case 'Platform':
                        $todayobj = 'TodayTransferPlatform';
                        $totalobj = 'TotalTransferPlatform';
                    break;
                default:
                        $ex->add('obj','obj值不符合');
                        throw $ex;
                    break;
            }
        }

        //以时间来进行搜索 ex: date=1 代表最近30天搜索
        if (!empty($data['Date']) && isset($data['Date'])) {
            switch ($data['Date']) {
                case 1://最近30天
                    if ($data['Type'] == 1) {
                        $modelsManager = PeachStatistic::query()
                            ->columns("Date as date,{$todayobj} as value");
                    } else {
                        $modelsManager = PeachStatistic::query()
                            ->columns("Date as date,{$totalobj} as value");
                    }

                    $endTime = date('Ymd', strtotime("today"));
                    $beginTime = date('Ymd', strtotime("{$endTime} - 30 day"));
                    $modelsManager->betweenWhere('Date', $beginTime,$endTime);
                    $results = $modelsManager->execute();
                    $hospital = [];
                    if(!empty($results)){
                        foreach ($results as $key=>$item){
                            $hospital['date'][] = $item['date'];
                            $hospital['value'][] = $item['value'];
                        }
                    }
                    $hospital['date'] = Manage::dealDate($hospital['date'],$data['Date']);
                    $this->response->setJsonContent($hospital);
                    break;
                case 2://最近30周
                    $hospital = [];
                    if ($data['Type'] == 1) {
                        $value = [];
                        $beginTotal = $beginToday =  0;
                        $endTime = strtotime("-1 day");
                        $endDate = date('Ymd', $endTime);
                        $beginTime = strtotime("{$endDate} - 31 week");
                        $dates = ReportDate::getWeekFromRange((int)$beginTime, (int)$endTime);
                        foreach ($dates as $key=>$date) {
                            $items = PeachStatistic::findFirst(array(
                                "columns" => array($totalobj,$todayobj),
                                "conditions" => "Date = ?1",
                                "bind"       => array(1 => $date)
                            ));
                            if($key == 0){
                                $beginTotal = $items->{$totalobj};
                            }else{
                                $nextTotal = $items->{$totalobj} - $beginTotal;
                                $value[] = $nextTotal;
                                $beginTotal = $items->{$totalobj};
                            }
                        }
                        array_splice($dates,0,1);
                    } else {
                        $value = [];
                        $endTime = strtotime("-1 day");
                        $endDate = date('Ymd', $endTime);
                        $beginTime = strtotime("{$endDate} - 30 week");
                        $dates = ReportDate::getWeekFromRange((int)$beginTime, (int)$endTime);
                        foreach ($dates as $key=>$date) {
                            $items = PeachStatistic::findFirst(array(
                                "columns" => array($todayobj,$totalobj),
                                "conditions" => "Date = ?1",
                                "bind"       => array(1 => $date)
                            ));
                            if($key == end($dates)){
                                $value[] = $items->{$totalobj} - $items->{$todayobj};
                            }else{
                                $value[] = $items->{$totalobj};
                            }
                        }
                    }
                    $hospital['date'] = Manage::dealDate($dates,$data['Date']);
                    $hospital['value'] = $value;
                    $this->response->setJsonContent($hospital);
                    break;
                case 3://最近12个月
                    $hospital = [];
                    if ($data['Type'] == 1) {
                        $value = [];
                        $beginTotal = $beginToday =  0;
                        $endTime = strtotime("-1 day");
                        $endDate = date('Ymd', $endTime);
                        $beginTime = strtotime("{$endDate} - 11 month");
                        $dates = ReportDate::getMonthFromRange((int)$beginTime, (int)$endTime);
                        foreach ($dates as $key=>$date) {
                            $items = PeachStatistic::findFirst(array(
                                "columns" => array($totalobj,$todayobj),
                                "conditions" => "Date = ?1",
                                "bind"       => array(1 => $date)
                            ));
                            if($key == 0){
                                $beginTotal = $items->{$totalobj};
                                $beginToday = $items->{$todayobj};
                            }elseif($date == end($dates)){
                                $nextTotal = $items->{$totalobj} - $beginTotal + $beginToday;
                                $value[] = $nextTotal;
                            }else{
                                $nextTotal = $items->{$totalobj} - $items->{$todayobj} - $beginTotal;
                                $value[] = $nextTotal;
                                $beginTotal = $items->{$totalobj};
                                $beginToday = $items->{$todayobj};
                            }
                        }
                        array_pop($dates);
                    } else {
                        $value = [];
                        $endTime = strtotime("-1 day");
                        $endDate = date('Ymd', $endTime);
                        $beginTime = strtotime("{$endDate} - 11 month");
                        $dates = ReportDate::getMonthFromRange((int)$beginTime, (int)$endTime);
                        foreach ($dates as $key=>$date) {
                            $items = PeachStatistic::findFirst(array(
                                "columns" => array($totalobj,$todayobj),
                                "conditions" => "Date = ?1",
                                "bind"       => array(1 => $date)
                            ));
                            if($date != end($dates)){
                                $value[] = $items->{$totalobj} - $items->{$todayobj};
                            }else{
                                $value[] = $items->{$totalobj};
                            }
                        }
                        array_splice($value,0,1);
                        array_pop($dates);
                    }
                    $hospital['date'] = Manage::dealDate($dates,$data['Date']);
                    $hospital['value'] = $value;
                        $this->response->setJsonContent($hospital);
                    break;
                default:
                    break;
            }
        }
    }




    /**
     *
     * 获取医院数据统计
     * 时间可以筛选出总数排序
     * @deprecated
     *
     * */
    public function old_hospitalRecordsAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $yesterday = date('Ymd', strtotime("-1 day"));
        //以时间来进行搜索 ex: StartTime='20170801' && EndTime = '20170901'
        if (!empty($data['StartTime']) && isset($data['StartTime']) && !empty($data['EndTime']) && isset($data['EndTime']) && (date('Ymd', $data['StartTime']) < $yesterday) ) {

            $query = $this->modelsManager->createBuilder()
                ->columns('H.HospitalId as HospitalId,O.Name as HospitalName,
                min(H.Date) as minDate,min(H.TotalSlave) as minTotalSlave,
                min(TotalTransfer) as minTotalTransfer,min(TotalTransferCost) as minTotalTransferCost,
                ( ( max( H.TotalSlave ) - IF ( count( H.Date ) = 1, 0, MIN( H.TotalSlave ) ) ) ) as IncrementClinics,
                ( ( max( H.TotalTransfer ) - IF ( count( H.Date ) = 1, 0, MIN( H.TotalTransfer ) ) ) )  as IncrementTransfers,
                ( ( max( H.TotalTransferCost ) - IF ( count( H.Date ) = 1, 0, MIN( H.TotalTransferCost ) ) ) )  as IncrementBills, 
                    max(H.TotalSlave) as ClinicAmounts,
                    max(H.TotalTransfer) as TransferAmounts,
                    max(H.TotalTransferCost) as BillAmounts')
                ->addFrom(HospitalStatistic::class, 'H')
                ->leftjoin(Organization::class, 'O.Id=H.HospitalId', 'O')
                ->groupby('H.HospitalId');

            //开始时间
            if (!empty($data['StartTime']) && isset($data['StartTime'])) {
                $query->andWhere("H.Date>=:StartTime:", ['StartTime' => date("Ymd", $data['StartTime'] - 86400)]);
            }

            //结束时间
            if (!empty($data['EndTime']) && isset($data['EndTime']) && ($data['StartTime'] < $data['EndTime'])) {
                $query->andWhere("H.Date<=:EndTime:", ['EndTime' => date("Ymd", $data['EndTime'])]);
            }

        } else {

            $query = $this->modelsManager->createBuilder()
                ->columns('H.HospitalId as HospitalId,O.Name as HospitalName,
                H.TodaySlave as IncrementClinics,H.TotalSlave as ClinicAmounts,
                H.TodayTransfer as IncrementTransfers,H.TotalTransfer as TransferAmounts,
                H.TodayTransferCost as IncrementBills,H.TotalTransferCost as BillAmounts')
                ->addFrom(HospitalStatistic::class, 'H')
                ->join(Organization::class, 'O.Id=H.HospitalId', 'O', 'left');

            //未传则默认为当昨天
            $query->inWhere('H.Date', ['StartDate' => $yesterday]);
        }

        //以医院名称搜索
        if (!empty($data['HospitalName']) && isset($data['HospitalName'])) {
            $query->andWhere("O.Name=:HospitalName:", ['HospitalName' => $data['HospitalName']]);
        }

        //排序方式
        if (!empty($data['Prop']) && isset($data['Prop']) && !empty($data['Sort']) && isset($data['Sort'])) {
            $sortName = $data['Prop'];
            $sortType = isset($data['Sort']) ? $data['Sort'] : 'Desc';
            $query->orderBy("{$sortName} {$sortType}");
        }else{
            $query->orderBy("IncrementClinics DESC");
        }

        $paginator = new QueryBuilder(
            [
                "builder" => $query,
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );

        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        //补齐开始数据
        foreach ($datas as $key=>$item){
            //开始时间
            if (!empty($data['StartTime']) && isset($data['StartTime'])) {
                if( $item['minDate'] > date("Ymd", $data['StartTime']) ){
                    $datas[$key]['IncrementClinics'] = $item['IncrementClinics'] + $item['minTotalSlave'];
                    $datas[$key]['IncrementTransfers'] = $item['IncrementTransfers'] + $item['minTotalTransfer'];
                    $datas[$key]['IncrementBills'] = $item['IncrementBills'] + $item['minTotalTransferCost'];
                }
            }
            unset($datas[$key]['minDate']);
            unset($datas[$key]['minTotalSlave']);
        }
        if(!empty($data['EndTime']) && isset($data['EndTime'])){
            if($yesterday > date('Ymd',$data['EndTime'])){
                foreach ($datas as $key=>$item){
                    //补起截止昨日总数据
                    $TotalData = HospitalStatistic::findFirst([
                        'conditions' => 'Date<=?0 and HospitalId = ?1 order by Date Desc',
                        'bind'       => [$yesterday,$item['HospitalId']],
                    ]);
                    if($item['HospitalId'] == $TotalData->HospitalId){
                        $datas[$key]['ClinicAmounts'] = $TotalData->TotalSlave;
                        $datas[$key]['TransferAmounts'] = $TotalData->TotalTransfer;
                        $datas[$key]['BillAmounts'] = $TotalData->TotalTransferCost;
                    }
                }
                //排序方式
                if (!empty($data['Prop']) && isset($data['Prop']) && !empty($data['Sort']) && isset($data['Sort'])) {
                    $sortName = $data['Prop'];
                    $SortArr = array_column($datas,$sortName);
                    switch ($data['Sort']){
                        case 'Desc':
                            array_multisort($SortArr,SORT_DESC,$datas);
                            break;
                        case 'ASC':
                            array_multisort($SortArr,SORT_ASC,$datas);
                            break;
                        default:
                            array_multisort($SortArr,SORT_DESC,$datas);
                            break;
                    }
                }
            }
        }


        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }


    /**
     *
     *  获取含医院的统计数据
     *
     */

    private function getHosptitalTotalData($yesterday,$pageSize,$page,$increamQuery,$HospitalName,$sortName,$sortType){

        $query = $this->modelsManager->createBuilder()
            ->columns('H.HospitalId as HospitalId,O.Name as HospitalName,
                H.TodaySlave as IncrementClinics,H.TotalSlave as ClinicAmounts,
                H.TodayTransfer as IncrementTransfers,H.TotalTransfer as TransferAmounts,
                H.TodayTransferCost as IncrementBills,H.TotalTransferCost as BillAmounts')
            ->addFrom(HospitalStatistic::class, 'H')
            ->join(Organization::class, 'O.Id=H.HospitalId', 'O', 'left');

        //未传则默认为当昨天
        $query->inWhere('H.Date', ['StartDate' => $yesterday]);
        //以医院名称搜索
        if (!empty($HospitalName) && isset($HospitalName)) {
            $query->andWhere("O.Name=:HospitalName:", ['HospitalName' => $HospitalName]);
        }
        //排序方式
        if (!empty($sortName) && isset($sortName) && !empty($sortType) && isset($sortType)) {
            $query->orderBy("{$sortName} {$sortType},HospitalId asc");
        }else{
            $query->orderBy("IncrementClinics DESC,HospitalId asc");
        }
        $paginator = new QueryBuilder(
            [
                "builder" => $query,
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        foreach($datas as $key=>$item){
            $tempQuery = clone $increamQuery;
            $increamQuery->andWhere('H.HospitalId=:HospitalId:', ['HospitalId'=>$item['HospitalId']]);
            $paginator = new QueryBuilder(
                [
                    "builder" => $increamQuery,
                    "limit"   => 1,
                    "page"    => 1,
                ]
            );
            $increamQuery = $tempQuery;
            $increamPages = $paginator->getPaginate();
            $increamDate = $increamPages->items->toArray();
            if(!empty($increamDate)){
                $datas[$key]['IncrementClinics'] = $increamDate[0]['IncrementClinics'];
                $datas[$key]['IncrementTransfers'] = $increamDate[0]['IncrementTransfers'];
                $datas[$key]['IncrementBills'] = $increamDate[0]['IncrementBills'];
            }else{
                $datas[$key]['IncrementClinics'] = 0;
                $datas[$key]['IncrementTransfers'] = 0;
                $datas[$key]['IncrementBills'] = 0;
            }
        }
        //排序方式
        if (!empty($sortName) && isset($sortName) && !empty($sortType) && isset($sortType)) {
            $SortArr = array_column($datas,$sortName);
            switch ($sortType){
                case 'Desc':
                    array_multisort($SortArr,SORT_DESC,$datas);
                    break;
                case 'Asc':
                    array_multisort($SortArr,SORT_ASC,$datas);
                    break;
                default:
                    array_multisort($SortArr,SORT_DESC,$datas);
                    break;
            }
        }else{
            $SortArr = array_column($datas,'IncrementClinics');
            array_multisort($SortArr,SORT_DESC,$datas);
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }


    /**
     *
     * 获取医院数据统计
     *
     * 时间仅仅筛选增量
     *
     * */
    public function hospitalRecordsAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $yesterday = date('Ymd', strtotime("-1 day"));
        //以时间来进行搜索 ex: StartTime='20170801' && EndTime = '20170901'
        if (!empty($data['StartTime']) && isset($data['StartTime']) && !empty($data['EndTime']) && isset($data['EndTime']) && (date('Ymd', $data['StartTime']) < $yesterday) ) {

            $query = $this->modelsManager->createBuilder()
                ->columns('H.HospitalId as HospitalId,O.Name as HospitalName,
                min(H.Date) as minDate,min(H.TotalSlave) as minTotalSlave,
                min(TotalTransfer) as minTotalTransfer,min(TotalTransferCost) as minTotalTransferCost,
                ( ( max( H.TotalSlave ) - IF ( count( H.Date ) = 1, 0, MIN( H.TotalSlave ) ) ) ) as IncrementClinics,
                ( ( max( H.TotalTransfer ) - IF ( count( H.Date ) = 1, 0, MIN( H.TotalTransfer ) ) ) )  as IncrementTransfers,
                ( ( max( H.TotalTransferCost ) - IF ( count( H.Date ) = 1, 0, MIN( H.TotalTransferCost ) ) ) )  as IncrementBills, 
                    max(H.TotalSlave) as ClinicAmounts,
                    max(H.TotalTransfer) as TransferAmounts,
                    max(H.TotalTransferCost) as BillAmounts')
                ->addFrom(HospitalStatistic::class, 'H')
                ->leftjoin(Organization::class, 'O.Id=H.HospitalId', 'O')
                ->groupby('H.HospitalId');

            //开始时间
            if (!empty($data['StartTime']) && isset($data['StartTime'])) {
                $query->andWhere("H.Date>=:StartTime:", ['StartTime' => date("Ymd", $data['StartTime'] - 86400)]);
            }

            //结束时间
            if (!empty($data['EndTime']) && isset($data['EndTime']) && ($data['StartTime'] < $data['EndTime']) && (date('Ymd', $data['EndTime']) < $yesterday) ) {
                $query->andWhere("H.Date<=:EndTime:", ['EndTime' => date("Ymd", $data['EndTime'])]);
                //以医院名称搜索
                if (!empty($data['HospitalName']) && isset($data['HospitalName'])) {
                    $HospitalName = $data['HospitalName'];
                    $query->andWhere("O.Name=:HospitalName:", ['HospitalName' => $HospitalName]);
                }else{
                    $HospitalName = '';
                }
                //排序方式
                if (!empty($data['Prop']) && isset($data['Prop']) && !empty($data['Sort']) && isset($data['Sort'])) {
                    $sortName = $data['Prop'];
                    $sortType = isset($data['Sort']) ? $data['Sort'] : 'Desc';
                    $query->orderBy("{$sortName} {$sortType},HospitalId asc");
                }else{
                    $sortName = $sortType  = '';
                    $query->orderBy("IncrementClinics DESC,HospitalId asc");
                }
                $this->getHosptitalTotalData($yesterday,$pageSize,$page,$query,$HospitalName,$sortName,$sortType);
                return false;
            }

        } else {

            $query = $this->modelsManager->createBuilder()
                ->columns('H.HospitalId as HospitalId,O.Name as HospitalName,
                H.TodaySlave as IncrementClinics,H.TotalSlave as ClinicAmounts,
                H.TodayTransfer as IncrementTransfers,H.TotalTransfer as TransferAmounts,
                H.TodayTransferCost as IncrementBills,H.TotalTransferCost as BillAmounts')
                ->addFrom(HospitalStatistic::class, 'H')
                ->join(Organization::class, 'O.Id=H.HospitalId', 'O', 'left');

            //未传则默认为当昨天
            $query->inWhere('H.Date', ['StartDate' => $yesterday]);
        }

        //以医院名称搜索
        if (!empty($data['HospitalName']) && isset($data['HospitalName'])) {
            $query->andWhere("O.Name=:HospitalName:", ['HospitalName' => $data['HospitalName']]);
        }

        //排序方式
        if (!empty($data['Prop']) && isset($data['Prop']) && !empty($data['Sort']) && isset($data['Sort'])) {
            $sortName = $data['Prop'];
            $sortType = isset($data['Sort']) ? $data['Sort'] : 'Desc';
            $query->orderBy("{$sortName} {$sortType},HospitalId asc");
        }else{
            $query->orderBy("IncrementClinics DESC,HospitalId asc");
        }

        $paginator = new QueryBuilder(
            [
                "builder" => $query,
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );

        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        //补齐开始数据
        foreach ($datas as $key=>$item){
            //开始时间
            if (!empty($data['StartTime']) && isset($data['StartTime'])) {
                if( $item['minDate'] > date("Ymd", $data['StartTime']) ){
                    $datas[$key]['IncrementClinics'] = $item['IncrementClinics'] + $item['minTotalSlave'];
                    $datas[$key]['IncrementTransfers'] = $item['IncrementTransfers'] + $item['minTotalTransfer'];
                    $datas[$key]['IncrementBills'] = $item['IncrementBills'] + $item['minTotalTransferCost'];
                }
            }
            unset($datas[$key]['minDate']);
            unset($datas[$key]['minTotalSlave']);
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }


    /**
     *
     * 获取业务经理数据统计
     *
     * 时间可以筛选出总数排序
     *
     * @deprecated
     *
     * */

    public function old_managerRecordsAction()
    {
        $exception = new ParamException(Status::BadRequest);
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        if (!empty($data['HospitalId']) && isset($data['HospitalId'])) {
            $hospitalId = $data['HospitalId'];
        }else{
            throw $exception;
        }
        $yesterday = date('Ymd', strtotime("-1 day"));
        //以时间来进行搜索 ex: StartTime='20170801' && EndTime = '20170901'
        if (!empty($data['StartTime']) && isset($data['StartTime']) && !empty($data['EndTime']) && isset($data['EndTime']) && (date('Ymd', $data['StartTime']) < $yesterday) ) {

            $query = $this->modelsManager->createBuilder()
                ->columns('H.UserId as UserId,U.Name as ManagerName,
                min(H.Date) as minDate,min(H.TotalSlave) as minTotalSlave,
                min(H.TotalTransfer) as minTotalTransfer,min(H.TotalTransferCost) as minTotalTransferCost,
                ( ( max( H.TotalSlave ) - IF ( count( H.Date ) = 1, 0, MIN( H.TotalSlave ) ) ) ) as IncrementClinics,
                ( ( max( H.TotalTransfer ) - IF ( count( H.Date ) = 1, 0, MIN( H.TotalTransfer ) ) ) )  as IncrementTransfers,
                ( ( max( H.TotalTransferCost ) - IF ( count( H.Date ) = 1, 0, MIN( H.TotalTransferCost ) ) ) )  as IncrementBills, 
                    max(H.TotalSlave) as ClinicAmounts,
                    max(H.TotalTransfer) as TransferAmounts,
                    max(H.TotalTransferCost) as BillAmounts')
                ->addFrom(HospitalSalesmanStatistic::class, 'H')
                ->leftJoin(User::class, 'U.Id=H.UserId', 'U')
                ->where(sprintf('H.HospitalId=%d', $hospitalId))
                ->groupby('H.UserId');

            //开始时间
            if (!empty($data['StartTime']) && isset($data['StartTime'])) {
                $query->andWhere("H.Date>=:StartTime:", ['StartTime' => date("Ymd", $data['StartTime'] - 86400)]);
            }

            //结束时间
            if (!empty($data['EndTime']) && isset($data['EndTime'])) {
                if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                    throw $exception;
                }
                $query->andWhere("H.Date<=:EndTime:", ['EndTime' => date("Ymd", $data['EndTime'])]);
            }

        } else {

            $query = $this->modelsManager->createBuilder()
                ->columns('H.UserId as UserId,U.Name as ManagerName,
                     H.TodaySlave as IncrementClinics,
                     H.TodayTransfer as IncrementTransfers,
                     H.TodayTransferCost  as IncrementBills, 
                     H.TotalSlave as ClinicAmounts,
                     H.TotalTransfer as TransferAmounts,
                     H.TotalTransferCost as BillAmounts')
                ->addFrom(HospitalSalesmanStatistic::class, 'H')
                ->leftJoin(User::class, 'U.Id=H.UserId', 'U')
                ->where(sprintf('H.HospitalId=%d', $hospitalId));
            //未传则默认为当昨天
//          $query->andWhere('H.Date<=:StartDate: and H.TotalSlave > 0 and H.TotalTransfer > 0 and H.TotalTransferCost > 0', ['StartDate' => $yesterday]);
            $query->inWhere('H.Date', ['StartDate' => $yesterday]);
        }

        //以医院名称搜索
        if (!empty($data['ManagerName']) && isset($data['ManagerName'])) {
            $query->andWhere("U.Name=:ManagerName:", ['ManagerName' => $data['ManagerName']]);
        }

        //排序方式
        if (!empty($data['Prop']) && isset($data['Prop']) && !empty($data['Sort']) && isset($data['Sort'])) {
            $sortName = $data['Prop'];
            $sortType = isset($data['Sort']) ? $data['Sort'] : 'Desc';
            $query->orderBy("{$sortName} {$sortType}");
        }else{
            $query->orderBy("IncrementClinics DESC");
        }

        $paginator = new QueryBuilder(
            [
                "builder" => $query,
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );

        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();

        //补齐开始数据
        foreach ($datas as $key=>$item){
            //开始时间
            if (!empty($data['StartTime']) && isset($data['StartTime'])) {
                if( $item['minDate'] > date("Ymd", $data['StartTime']) ){
                    $datas[$key]['IncrementClinics'] = $item['IncrementClinics'] + $item['minTotalSlave'];
                    $datas[$key]['IncrementTransfers'] = $item['IncrementTransfers'] + $item['minTotalTransfer'];
                    $datas[$key]['IncrementBills'] = $item['IncrementBills'] + $item['minTotalTransferCost'];
                }
            }
            unset($datas[$key]['minDate']);
            unset($datas[$key]['minTotalSlave']);
        }
        if(!empty($data['EndTime']) && isset($data['EndTime'])){
            if($yesterday > date('Ymd',$data['EndTime'])){
                foreach ($datas as $key=>$item){
                    //补齐截止昨日总数据
                    $TotalData = HospitalSalesmanStatistic::findFirst([
                        'conditions' => 'Date<=?0 and UserId = ?1  and HospitalId =?2 order by Date Desc',
                        'bind'       => [$yesterday,$item['UserId'],$hospitalId],
                    ]);
                    if($item['UserId'] == $TotalData->UserId){
                        $datas[$key]['ClinicAmounts'] = $TotalData->TotalSlave;
                        $datas[$key]['TransferAmounts'] = $TotalData->TotalTransfer;
                        $datas[$key]['BillAmounts'] = $TotalData->TotalTransferCost;
                    }
                }
                //排序方式
                if (!empty($data['Prop']) && isset($data['Prop']) && !empty($data['Sort']) && isset($data['Sort'])) {
                    $sortName = $data['Prop'];
                    $SortArr = array_column($datas,$sortName);
                    switch ($data['Sort']){
                        case 'Desc':
                            array_multisort($SortArr,SORT_DESC,$datas);
                            break;
                        case 'ASC':
                            array_multisort($SortArr,SORT_ASC,$datas);
                            break;
                        default:
                            array_multisort($SortArr,SORT_DESC,$datas);
                            break;
                    }
                }
            }
        }

        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }



    /**
     *
     *  获取含业务人员的统计数据
     *
     */

    private function getManagerTotalData($hospitalId,$yesterday,$pageSize,$page,$increamQuery,$ManagerName,$sortName,$sortType){

        $query = $this->modelsManager->createBuilder()
            ->columns('H.UserId as UserId,U.Name as ManagerName,
                     H.TodaySlave as IncrementClinics,
                     H.TodayTransfer as IncrementTransfers,
                     H.TodayTransferCost  as IncrementBills, 
                     H.TotalSlave as ClinicAmounts,
                     H.TotalTransfer as TransferAmounts,
                     H.TotalTransferCost as BillAmounts')
            ->addFrom(HospitalSalesmanStatistic::class, 'H')
            ->leftJoin(User::class, 'U.Id=H.UserId', 'U')
            ->where(sprintf('H.HospitalId=%d', $hospitalId)
            );
        //未传则默认为当昨天
        $query->inWhere('H.Date', ['StartDate' => $yesterday]);
        //以业务员名称搜索
        if (!empty($ManagerName) && isset($ManagerName)) {
            $query->andWhere("U.Name=:ManagerName:", ['ManagerName' => $ManagerName]);
        }
        //排序方式
        if (!empty($sortName) && isset($sortName) && !empty($sortType) && isset($sortType)) {
            $query->orderBy("{$sortName} {$sortType},UserId asc");
        }else{
            $query->orderBy("IncrementClinics DESC,UserId asc");
        }
        $paginator = new QueryBuilder(
            [
                "builder" => $query,
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        foreach($datas as $key=>$item){
            $tempQuery = clone $increamQuery;
            $increamQuery->andWhere('H.UserId=:UserId:', ['UserId'=>$item['UserId']]);
            $paginator = new QueryBuilder(
                [
                    "builder" => $increamQuery,
                    "limit"   => 1,
                    "page"    => 1,
                ]
            );
            $increamQuery = $tempQuery;
            $increamPages = $paginator->getPaginate();
            $increamDate = $increamPages->items->toArray();
            if(!empty($increamDate)){
                $datas[$key]['IncrementClinics'] = $increamDate[0]['IncrementClinics'];
                $datas[$key]['IncrementTransfers'] = $increamDate[0]['IncrementTransfers'];
                $datas[$key]['IncrementBills'] = $increamDate[0]['IncrementBills'];
            }else{
                $datas[$key]['IncrementClinics'] = 0;
                $datas[$key]['IncrementTransfers'] = 0;
                $datas[$key]['IncrementBills'] = 0;
            }
        }
        //排序方式
        if (!empty($sortName) && isset($sortName) && !empty($sortType) && isset($sortType)) {
            $SortArr = array_column($datas,$sortName);
            switch ($sortType){
                case 'Desc':
                    array_multisort($SortArr,SORT_DESC,$datas);
                    break;
                case 'Asc':
                    array_multisort($SortArr,SORT_ASC,$datas);
                    break;
                default:
                    array_multisort($SortArr,SORT_DESC,$datas);
                    break;
            }
        }else{
            $SortArr = array_column($datas,'IncrementClinics');
            array_multisort($SortArr,SORT_DESC,$datas);
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }


    /**
     *
     * 获取业务经理数据统计
     *
     * 时间仅仅筛选增量
     *
     *
     * */

    public function managerRecordsAction()
    {
        $exception = new ParamException(Status::BadRequest);
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        if (!empty($data['HospitalId']) && isset($data['HospitalId'])) {
            $hospitalId = $data['HospitalId'];
        }else{
            throw $exception;
        }
        $yesterday = date('Ymd', strtotime("-1 day"));
        //以时间来进行搜索 ex: StartTime='20170801' && EndTime = '20170901'
        if (!empty($data['StartTime']) && isset($data['StartTime']) && !empty($data['EndTime']) && isset($data['EndTime']) && (date('Ymd', $data['StartTime']) < $yesterday) ) {

            $query = $this->modelsManager->createBuilder()
                ->columns('H.UserId as UserId,U.Name as ManagerName,
                min(H.Date) as minDate,min(H.TotalSlave) as minTotalSlave,
                min(H.TotalTransfer) as minTotalTransfer,min(H.TotalTransferCost) as minTotalTransferCost,
                ( ( max( H.TotalSlave ) - IF ( count( H.Date ) = 1, 0, MIN( H.TotalSlave ) ) ) ) as IncrementClinics,
                ( ( max( H.TotalTransfer ) - IF ( count( H.Date ) = 1, 0, MIN( H.TotalTransfer ) ) ) )  as IncrementTransfers,
                ( ( max( H.TotalTransferCost ) - IF ( count( H.Date ) = 1, 0, MIN( H.TotalTransferCost ) ) ) )  as IncrementBills, 
                    max(H.TotalSlave) as ClinicAmounts,
                    max(H.TotalTransfer) as TransferAmounts,
                    max(H.TotalTransferCost) as BillAmounts')
                ->addFrom(HospitalSalesmanStatistic::class, 'H')
                ->leftJoin(User::class, 'U.Id=H.UserId', 'U')
                ->where(sprintf('H.HospitalId=%d', $hospitalId))
                ->groupby('H.UserId');

            //开始时间
            if (!empty($data['StartTime']) && isset($data['StartTime'])) {
                $query->andWhere("H.Date>=:StartTime:", ['StartTime' => date("Ymd", $data['StartTime'] - 86400)]);
            }

            //结束时间
            if (!empty($data['EndTime']) && isset($data['EndTime']) && ($data['StartTime'] < $data['EndTime']) && (date('Ymd', $data['EndTime']) < $yesterday) ) {
                $query->andWhere("H.Date<=:EndTime:", ['EndTime' => date("Ymd", $data['EndTime'])]);
                //以业务员名称搜索
                if (!empty($data['ManagerName']) && isset($data['ManagerName'])) {
                    $ManagerName = $data['ManagerName'];
                    $query->andWhere("U.Name=:ManagerName:", ['ManagerName' => $ManagerName]);
                }else{
                    $ManagerName = '';
                }

                //排序方式
                if (!empty($data['Prop']) && isset($data['Prop']) && !empty($data['Sort']) && isset($data['Sort'])) {
                    $sortName = $data['Prop'];
                    $sortType = isset($data['Sort']) ? $data['Sort'] : 'Desc';
                    $query->orderBy("{$sortName} {$sortType},UserId asc");
                }else{
                    $sortName = $sortType  = '';
                    $query->orderBy("IncrementClinics DESC,UserId asc");
                }

                $this->getManagerTotalData($hospitalId,$yesterday,$pageSize,$page,$query,$ManagerName,$sortName,$sortType);
                return false;
            }

        } else {

            $query = $this->modelsManager->createBuilder()
                ->columns('H.UserId as UserId,U.Name as ManagerName,
                     H.TodaySlave as IncrementClinics,
                     H.TodayTransfer as IncrementTransfers,
                     H.TodayTransferCost  as IncrementBills, 
                     H.TotalSlave as ClinicAmounts,
                     H.TotalTransfer as TransferAmounts,
                     H.TotalTransferCost as BillAmounts')
                ->addFrom(HospitalSalesmanStatistic::class, 'H')
                ->leftJoin(User::class, 'U.Id=H.UserId', 'U')
                ->where(sprintf('H.HospitalId=%d', $hospitalId));
            //未传则默认为当昨天
            $query->inWhere('H.Date', ['StartDate' => $yesterday]);

        }

        //以业务员名称搜索
        if (!empty($data['ManagerName']) && isset($data['ManagerName'])) {
            $query->andWhere("U.Name=:ManagerName:", ['ManagerName' => $data['ManagerName']]);
        }

        //排序方式
        if (!empty($data['Prop']) && isset($data['Prop']) && !empty($data['Sort']) && isset($data['Sort'])) {
            $sortName = $data['Prop'];
            $sortType = isset($data['Sort']) ? $data['Sort'] : 'Desc';
            $query->orderBy("{$sortName} {$sortType},UserId asc");
        }else{
            $query->orderBy("IncrementClinics DESC,UserId asc");
        }

        $paginator = new QueryBuilder(
            [
                "builder" => $query,
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );

        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();

        //补齐开始数据
        foreach ($datas as $key=>$item){
            //开始时间
            if (!empty($data['StartTime']) && isset($data['StartTime'])) {
                if( $item['minDate'] > date("Ymd", $data['StartTime']) ){
                    $datas[$key]['IncrementClinics'] = $item['IncrementClinics'] + $item['minTotalSlave'];
                    $datas[$key]['IncrementTransfers'] = $item['IncrementTransfers'] + $item['minTotalTransfer'];
                    $datas[$key]['IncrementBills'] = $item['IncrementBills'] + $item['minTotalTransferCost'];
                }
            }
            unset($datas[$key]['minDate']);
            unset($datas[$key]['minTotalSlave']);
        }

        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }


}