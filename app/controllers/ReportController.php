<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/21
 * Time: 下午4:03
 */

namespace App\Controllers;


use App\Enums\Status;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\csv\FrontCsv;
use App\Libs\Sphinx;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\Section;
use App\Models\SlaveReport;
use App\Models\Transfer;
use App\Models\User;
use Phalcon\Paginator\Adapter\QueryBuilder;

class ReportController extends Controller
{
    /**
     * 转诊量报表
     */
    public function transferAction()
    {
        $data = $this->request->getPost();
        $auth = $this->session->get('auth');
        $query = $this->modelsManager->createBuilder()
            ->columns('O.Name,S.TransferDay,S.Date')
            ->addFrom(SlaveReport::class, 'S')
            ->join(Organization::class, 'O.Id=S.OrganizationId', 'O', 'left')
            ->where('S.HospitalId=:OrganizationId:', ['OrganizationId' => $auth['OrganizationId']]);
        //以时间来进行搜索 ex: Month='2017-08'
        if (!empty($data['Month']) && isset($data['Month'])) {
            //日期格式 example:2017-08
            $startTime = date('Ym01', strtotime($data['Month'] . '-01'));
            $endTime = date('Ymd', strtotime("{$startTime} +1 month -1 day"));
            $query->betweenWhere('S.Date', $startTime, $endTime);
        } else {
            //未传Month则默认为当前月
            $startTime = date('Ym01');
            $query->andWhere('S.Date>=:StartTime:', ['StartTime' => $startTime]);
        }
        //名字搜索
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ?: [], 'id');
            if (count($ids)) {
                $query->inWhere('S.OrganizationId', $ids);
            } else {
                $query->inWhere('S.OrganizationId', [-1]);
            }
        }
        $slaves = $query->getQuery()->execute();
        $result = [];
        if (count($slaves)) {
            foreach ($slaves as $slave) {
                $day = abs(date('d', strtotime($slave->Date)));
                $result[$slave->Name][$day] = $slave->TransferDay;
            }
        }
        $this->response->setJsonContent($result);
    }

    /**
     * 分润报表
     */
    public function shareAction()
    {
        $data = $this->request->getPost();
        $auth = $this->session->get('auth');
        $query = $this->modelsManager->createBuilder()
            ->columns('O.Name,S.ShareDay,S.Date')
            ->addFrom(SlaveReport::class, 'S')
            ->join(Organization::class, 'O.Id=S.OrganizationId', 'O', 'left')
            ->where('S.HospitalId=:OrganizationId:', ['OrganizationId' => $auth['OrganizationId']]);
        //以时间来进行搜索 ex: Month='2017-08'
        if (!empty($data['Month']) && isset($data['Month'])) {
            //日期格式 example:2017-08
            $startTime = date('Ym01', strtotime($data['Month'] . '-01'));
            $endTime = date('Ymd', strtotime("{$startTime} +1 month -1 day"));
            $query->betweenWhere('S.Date', $startTime, $endTime);
        } else {
            //未传Month则默认为当前月
            $startTime = date('Ym01');
            $query->andWhere('S.Date>=:StartTime:', ['StartTime' => $startTime]);
        }
        //名字搜索
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ?: [], 'id');
            if (count($ids)) {
                $query->inWhere('S.OrganizationId', $ids);
            } else {
                $query->inWhere('S.OrganizationId', [-1]);
            }
        }
        $slaves = $query->getQuery()->execute();
        $result = [];
        if (count($slaves)) {
            foreach ($slaves as $slave) {
                $day = abs(date('d', strtotime($slave->Date)));
                $result[$slave->Name][$day] = $slave->ShareDay;
            }
        }
        $this->response->setJsonContent($result);
    }

    /**
     * 当月转诊量排行
     */
    public function rankingsAction()
    {
        $data = $this->request->getPost();
        $auth = $this->session->get('auth');
        $query = $this->modelsManager->createBuilder()
            ->columns('O.MinorName,S.OrganizationId,S.TransferMonth,S.ShareMonth')
            ->addFrom(SlaveReport::class, 'S')
            ->join(OrganizationRelationship::class, 'O.MinorId=S.OrganizationId', 'O', 'left')
            ->where('S.HospitalId=:OrganizationId:', ['OrganizationId' => $auth['OrganizationId']])
            ->andWhere('O.MainId=:MainId:', ['MainId' => $auth['OrganizationId']]);
        //以时间来进行搜索 ex: Month='2017-08'
        if (!empty($data['Month']) && isset($data['Month'])) {
            //日期格式 example:2017-08
            $startTime = date('Ym01', strtotime($data['Month'] . '-01'));
            $month = $data['Month'];
            $date = date('Ymd', strtotime("{$startTime} +1 month -1 day"));
        } else {
            //未传Month则默认为当前月
            $month = date('Y-m', strtotime('-1 day'));
            $date = date('Ymd', strtotime('-1 day'));
        }
        $query->andWhere('Date=:Date:', ['Date' => $date]);
        //名字搜索
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ?: [], 'id');
            if (count($ids)) {
                $query->inWhere('S.OrganizationId', $ids);
            } else {
                $query->inWhere('S.OrganizationId', [-1]);
            }
        }
        switch ($data['Style']) {
            case 'Transfer':
                //转诊单排行
                $query->orderBy('TransferMonth desc,OrganizationId asc');
                break;
            default:
                //分润金额排行
                $query->orderBy('ShareMonth desc,OrganizationId asc');
        }
        $slaves = $query->getQuery()->execute();
        $result = ['Month' => $month, 'Rankings' => $slaves];
        $this->response->setJsonContent($result);
    }

    /**
     * 详情
     */
    public function detailsAction()
    {
        $data = $this->request->getPost();
        $auth = $this->session->get('auth');
        $query = $this->modelsManager->createBuilder()
            ->columns('O.MinorName,S.Date,S.TransferDay,S.ShareDay')
            ->addFrom(SlaveReport::class, 'S')
            ->join(OrganizationRelationship::class, 'O.MinorId=S.OrganizationId', 'O', 'left')
            ->where('S.HospitalId=:HospitalId:', ['HospitalId' => $auth['OrganizationId']])
            ->andWhere('O.MainId=:MainId:', ['MainId' => $auth['OrganizationId']])
            ->andWhere('O.MinorId=:MinorId:', ['MinorId' => $data['OrganizationId']])
            ->andWhere('S.OrganizationId=:OrganizationId:', ['OrganizationId' => $data['OrganizationId']]);
        //以时间来进行搜索 ex: Month='2017-08'
        if (!empty($data['Month']) && isset($data['Month'])) {
            //日期格式 example:2017-08
            $startTime = date('Ym01', strtotime($data['Month'] . '-01'));
            $endTime = date('Ymd', strtotime("{$startTime} +1 month -1 day"));
            $query->betweenWhere('S.Date', $startTime, $endTime);
        } else {
            //未传Month则默认为当前月
            $startTime = date('Ym01');
            $query->andWhere('S.Date>=:StartTime:', ['StartTime' => $startTime]);
        }
        $slaves = $query->getQuery()->execute();
        $this->response->setJsonContent($slaves);
    }

    /**
     * 转诊数据列表
     */
    public function transferListAction()
    {
        $data = $this->request->get();
        $auth = $this->session->get('auth');
        if (!$auth) {
            throw new ParamException(Status::Unauthorized);
        }
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->addFrom(Transfer::class, 'T')
            ->leftJoin(OrganizationRelationship::class, "P.MainId={$auth['OrganizationId']} and P.MinorId=T.SendOrganizationId", 'P')
            ->leftJoin(Organization::class, 'O.Id=T.SendOrganizationId', 'O')
            ->leftJoin(User::class, 'U.Id=P.SalesmanId', 'U')
            ->leftJoin(Section::class, 'S.Id=T.AcceptSectionId', 'S')
            ->where(sprintf('T.AcceptOrganizationId=%d', $auth['OrganizationId']))
            ->andWhere(sprintf('T.Sign=%d', 0))
            ->andWhere(sprintf('T.IsDeleted=%d', 0));
        $time = false;
        $salesMan = false;
        $status = false;
        $sendOrganization = false;
        $timeQuantum = '';
        $statusStr = '';
        $minorName = '';
        $salesManName = '';
        //开始时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("T.StartTime>=:StartTime:", ['StartTime' => $data['StartTime']]);
            $time = true;
            $timeQuantum .= date('Y.m.d', $data['StartTime']) . '-';
        } else {
            $timeQuantum .= date('Y.m.d', $auth['OrgCreateTime']) . '-';
        }
        //结束时间
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                throw new ParamException(Status::BadRequest);
            }
            $query->andWhere("T.StartTime<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
            $time = true;
            $timeQuantum .= date('Y.m.d', $data['EndTime']);
        } else {
            $timeQuantum .= date('Y.m.d', time());
        }
        //业务经理
        if (!empty($data['SalesmanId']) && isset($data['SalesmanId']) && is_numeric($data['SalesmanId'])) {
            $query->andWhere('P.SalesmanId=:SalesmanId:', ['SalesmanId' => $data['SalesmanId']]);
            $salesMan = true;
            $salesManName = User::findFirst(sprintf('Id=%d', $data['SalesmanId']))->Name;
        }
        //转诊单状态
        if (!empty($data['Status']) && isset($data['Status']) && is_array($data['Status'])) {
            $query->inWhere("T.Status", $data['Status']);
            $status = true;
            $tempArr = [];
            foreach ($data['Status'] as $datum) {
                $tempArr[$datum] = Transfer::STATUS_NAME[$datum];
            }
            $statusStr = implode('、', $tempArr);
        }
        //网点
        if (!empty($data['MinorName']) && isset($data['MinorName'])) {
            $query->andWhere('P.MinorName=:MinorName:', ['MinorName' => $data['MinorName']]);
            $sendOrganization = true;
            $minorName .= $data['MinorName'];
        }
        $tempQuery = $query;
        $tempCount = $tempQuery->columns(['sum(T.Cost) as CostCount', 'sum(if(T.GenreOne=0,0,if(T.GenreOne=1,T.ShareOne,T.Cost*T.ShareOne/100))) as TotalShareNum'])->getQuery()->execute()[0];
        $costCount = $tempCount->CostCount;
        $totalShareNum = $tempCount->TotalShareNum;
        $costCount = $costCount ?: 0;
        $totalShareNum = $totalShareNum ?: 0;
        $query->orderBy('T.StartTime desc')
            ->columns([
                'T.Id', 'T.PatientName', 'T.StartTime', 'S.Name as SectionName', 'T.Cost', 'T.GenreOne', 'T.ShareOne', 'T.Status',
                'if(P.MinorName is null,O.Name,P.MinorName) as MinorName', 'U.Name as SalesmanName',
            ]);
        //导出csv
        if (isset($data['Export']) && !empty($data['Export'])) {
            $csv = new FrontCsv($query);
            $csv->transferList();
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
        foreach ($datas as &$data) {
            $data['ShareOneNum'] = ($data['GenreOne'] ? ($data['GenreOne'] == 1 ? $data['ShareOne'] : (float)($data['Cost'] * $data['ShareOne'] / 100)) : 0);
            //显示形式
            $data['ShareOneNum'] = $data['Status'] == Transfer::FINISH ? ($data['ShareOneNum'] ? '¥' . Alipay::fen2yuan($data['ShareOneNum']) : 0) : ($data['ShareOneNum'] ? '¥' . Alipay::fen2yuan($data['ShareOneNum']) : '');
            $data['Cost'] = $data['Status'] >= Transfer::LEAVE ? ($data['Cost'] ? '¥' . Alipay::fen2yuan($data['Cost']) : 0) : ($data['Cost'] ? '¥' . Alipay::fen2yuan($data['Cost']) : '');
            $data['StatusName'] = Transfer::STATUS_NAME[$data['Status']];
        }
        if ($time && $salesMan && $status && $sendOrganization) {
            //四种同时检索（业务经理姓名、网点名称）
            $str = sprintf('业务经理%s所开的%s网点转诊单状态为已出院的转诊单', $salesManName, $minorName);
        } elseif ($salesMan && $status && $sendOrganization) {
            //业务经理、网点名称、订单状态
            $str = sprintf('业务经理%s所开的%s网点转诊单状态为%s的转诊单', $salesManName, $minorName, $statusStr);
        } elseif ($time && $status && $sendOrganization) {
            //时间、网点名称、订单状态
            $str = sprintf('%s网点转诊单状态为%s的转诊单', $minorName, $statusStr);
        } elseif ($time && $salesMan && $sendOrganization) {
            //时间、业务经理、网点名称
            $str = sprintf('业务经理%s所开的%s网点', $salesManName, $minorName);
        } elseif ($time && $salesMan && $status) {
            //时间、业务经理、订单状态
            $str = sprintf('业务经理%s所开的网点转诊单状态为%s的转诊单', $salesManName, $statusStr);
        } elseif ($time && $salesMan) {
            //时间、业务经理
            $str = sprintf('业务经理%s所开网点', $salesManName);
        } elseif ($time && $status) {
            //时间、订单状态
            $str = sprintf('订单状态为%s的转诊单', $statusStr);
        } elseif ($time && $sendOrganization) {
            //时间、网点名称
            $str = sprintf('%s网点', $minorName);
        } elseif ($salesMan && $status) {
            //业务经理、订单状态
            $str = sprintf('业务经理%s所开的网点转诊单状态为%s的转诊单', $salesManName, $statusStr);
        } elseif ($salesMan && $sendOrganization) {
            //业务经理、网点名称
            $str = sprintf('业务经理%s所开网点%s', $salesManName, $minorName);
        } elseif ($status && $sendOrganization) {
            //网点名称、订单状态
            $str = sprintf('%s网点转诊单状态为%s的转诊单', $minorName, $statusStr);
        } elseif ($time) {
            //时间
            $str = '';
        } elseif ($salesMan) {
            //业务经理
            $str = sprintf('业务经理%s所开网点', $salesManName);
        } elseif ($status) {
            //订单状态
            $str = sprintf('订单状态为%s的转诊单', $statusStr);
        } elseif ($sendOrganization) {
            //诊所
            $str = sprintf('%s网点', $minorName);
        } else {
            $str = '';
        }
        //累计转诊单数、转诊金额、累计分润金额
        $lastStr = '累计转诊%s单，累计转诊金额%s元，网点累计佣金金额%s元';
        $message = $timeQuantum . '，' . $str . sprintf($lastStr, $count, Alipay::fen2yuan($costCount), Alipay::fen2yuan($totalShareNum));
        $result = [];
        $result['Message'] = ['Message' => $message];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }
}