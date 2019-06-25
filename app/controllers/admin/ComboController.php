<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/3/15
 * Time: 10:40 PM
 */

namespace App\Admin\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\combo\Read;
use App\Libs\csv\AdminCsv;
use App\Libs\Sphinx;
use App\Models\Combo;
use App\Models\ComboOrderBatch;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\User;
use Phalcon\Mvc\Model\Query\Builder;
use Phalcon\Paginator\Adapter\NativeArray;
use Phalcon\Paginator\Adapter\QueryBuilder;

class ComboController extends Controller
{
    /**
     * 套餐单列表
     */
    public function listAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns(['C.Id', 'C.Status', 'C.Price', 'C.Name', 'O.Name as HospitalName', 'C.CreateTime', 'C.Way', 'C.Amount', 'C.Operator', 'C.OffTime'])
            ->addFrom(Combo::class, 'C')
            ->leftJoin(Organization::class, 'O.Id=C.OrganizationId', 'O');

        //套餐名字
        if (!empty($data['Name']) && isset($data['Name'])) {
            $query->andWhere('C.Name=:Name:', ['Name' => $data['Name']]);
        }

        $time = 'C.CreateTime';
        //上下架状态
        if (isset($data['Status']) && is_numeric($data['Status'])) {
            if ($data['Status'] == Combo::STATUS_ON) {
                $query->andWhere('Status=1');
            } else {
                $query->andWhere('Status!=1');
                $time = 'C.OffTime';
            }
        }

        //开始时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("{$time}>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        //结束时间
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                throw new LogicException('结束时间不得早于开始时间', Status::BadRequest);
            }
            $query->andWhere("{$time}<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }

        //医院名称
        if (!empty($data['HospitalName']) && isset($data['HospitalName'])) {
            $query->andWhere('O.Name=:HospitalName:', ['HospitalName' => $data['HospitalName']]);
        }
        $query->orderBy('C.CreateTime desc');

        //导出csv
        if (isset($data['Export']) && !empty($data['Export'])) {
            $csv = new AdminCsv($query);
            $csv->comboList();
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
            $data['StatusName'] = $data['Status'] == Combo::STATUS_ON ? '销售中' : '已下架';
            switch ($data['Way']) {
                case Combo::WAY_NOTHING:
                    $data['RuleName'] = '无佣金';
                    break;
                case Combo::WAY_FIXED:
                    $amount = Alipay::fen2yuan($data['Amount']);
                    $data['RuleName'] = "单笔 {$amount}";
                    break;
                case Combo::WAY_RATIO:
                    $data['RuleName'] = "金额{$data['Amount']}%";
                    break;
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 详情
     */
    public function readAction()
    {
        /** @var Combo $combo */
        $combo = Combo::findFirst(sprintf('Id=%d', $this->request->get('Id')));
        if (!$combo) {
            throw new LogicException('', Status::BadRequest);
        }
        $read = new Read($combo);
        $this->response->setJsonContent($read->consoleShow());
    }

    /**
     * 下架
     */
    public function soldOutAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            /** @var Combo $combo */
            $combo = Combo::findFirst(sprintf('Id=%d', $this->request->getPut('Id')));
            if (!$combo || $combo->Status !== Combo::STATUS_ON) {
                throw $exception;
            }
            $reason = $this->request->getPut('Reason');
            if (!$reason || empty($reason)) {
                throw new LogicException('必须填写下架原因', Status::BadRequest);
            }
            $combo->Operator = Combo::OPERATOR_PEACH;
            $combo->Status = Combo::STATUS_OFF;
            $combo->Reason = $this->request->getPut('Reason');
            $combo->OffTime = time();
            if (!$combo->save()) {
                $exception->loadFromModel($combo);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 套餐销售详情
     */
    public function saleListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;


        $phql = "select a.Id,a.Name,a.Price,h.Name as HospitalName,if(a.Status=1,'销售中','下架') StatusName,if(d.SalesQuantity is null,0,d.SalesQuantity) SalesQuantity,if(h.Allot is null,0,h.Allot) Allot from Combo a
left join (select b.ComboId,sum(b.QuantityBuy) SalesQuantity from ComboOrderBatch b left join Combo c on c.Id=b.ComboId where  b.Status in (2,3) group by b.ComboId) d on d.ComboId=a.Id
left join (select e.ComboId,count(e.ComboId) Allot from ComboAndOrder e left join Combo f on f.Id=e.ComboId left join ComboOrder m on m.Id=e.ComboOrderId where  m.Status in (2,3) group by e.ComboId) h on h.ComboId=a.Id
left join Organization h on h.Id=a.OrganizationId where 1=1
";
        $bind = [];
        //医院名称
        if (!empty($data['HospitalName']) && isset($data['HospitalName'])) {
            $phql .= " and h.Name=?";
            $bind[] = $data['HospitalName'];

        }
        //套餐名字
        if (!empty($data['Name']) && isset($data['Name'])) {
            $phql .= " and a.Name=?";
            $bind[] = $data['Name'];
        }
        $phql .= " order by a.CreateTime desc";

        //导出csv
        if (isset($data['Export']) && !empty($data['Export'])) {
            $csv = new AdminCsv(new Builder());
            $csv->saleList($phql, $bind);
        }
        $paginator = new NativeArray([
            'data'  => $this->db->query($phql, $bind)->fetchAll(),
            'limit' => $pageSize,
            'page'  => $page,
        ]);
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items;

        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 套餐购买详情
     */
    public function buyListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns(['O.Name as SendOrganizationName', 'SLU.Name as SlaveMan', 'SLU.Phone as SlaveManPhone', 'U.Name as Salesman', 'U.Phone as SalesmanPhone', 'B.QuantityBuy', 'B.CreateTime', 'B.QuantityUnAllot'])
            ->addFrom(ComboOrderBatch::class, 'B')
            ->leftJoin(Combo::class, 'C.Id=B.ComboId', 'C')
            ->leftJoin(OrganizationRelationship::class, 'R.MainId=B.HospitalId and R.MinorId=B.OrganizationId', 'R')
            ->leftJoin(Organization::class, 'O.Id=B.OrganizationId', 'O')
            ->leftJoin(OrganizationUser::class, 'OU.OrganizationId=B.OrganizationId', 'OU')
            ->leftJoin(User::class, 'U.Id=R.SalesmanId', 'U')
            ->leftJoin(User::class, 'SLU.Id=OU.UserId', 'SLU')
            ->inWhere('B.Status', [ComboOrderBatch::STATUS_WAIT_ALLOT, ComboOrderBatch::STATUS_USED])
            ->andWhere('B.ComboId=:ComboId:', ['ComboId' => $data['Id']]);

        //网点名称
        if (isset($data['SendOrganizationName']) && !empty($data['SendOrganizationName'])) {
            $query->andWhere("O.Name=:SendOrganizationName:", ['SendOrganizationName' => $data['SendOrganizationName']]);
        }
        //业务经理
        if (isset($data['Salesman']) && !empty($data['Salesman'])) {
            $query->andWhere("U.Name=:Salesman:", ['Salesman' => $data['Salesman']]);
        }
        //时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("B.CreateTime>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                throw new LogicException('结束时间不能早于开始时间', Status::BadRequest);
            }
            $query->andWhere("B.CreateTime<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        //导出csv
        if (isset($data['Export']) && !empty($data['Export'])) {
            $csv = new AdminCsv($query);
            $csv->buyList();
        }

        $paginator = new QueryBuilder(
            [
                'builder' => $query,
                'limit'   => $pageSize,
                'page'    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }
}