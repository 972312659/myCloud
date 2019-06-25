<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/21
 * Time: 上午11:35
 */

namespace App\Admin\Controllers;

use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\combo\ReadComboOrder;
use App\Libs\csv\AdminCsv;
use App\Models\Combo;
use App\Models\ComboAndOrder;
use App\Models\ComboOrder;
use App\Models\ComboOrderLog;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use Phalcon\Mvc\Model\Query\Builder;
use Phalcon\Paginator\Adapter\NativeArray;
use Phalcon\Paginator\Adapter\QueryBuilder;

class ComboorderController extends Controller
{
    /**
     * 套餐订单列表
     */
    public function listAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $sql = "select C.Id,C.OrderNumber, A.Name as ComboName, OS.Name as SlaveName,
 OH.Name as HospitalName,A.Price, C.Status, C.Created from ComboOrder C
left join ComboAndOrder A on A.ComboOrderId=C.Id
left join Organization OS on OS.Id=C.SendOrganizationId
left join Organization OH on OH.Id=C.HospitalId 
left join (select ComboOrderId,min(LogTime) LogTime from ComboOrderLog where Status=3 group by ComboOrderId) L on L.ComboOrderId=C.Id
left join (select ComboOrderId,max(LogTime) LogTime from ComboOrderLog where Status=6 group by ComboOrderId) M on M.ComboOrderId=C.Id
where 1=1";
        $bind = [];
        //医院名称
        if (isset($data['HospitalName']) && !empty($data['HospitalName'])) {
            $sql .= ' and OH.Name=?';
            $bind[] = $data['HospitalName'];
        }
        //网点名称
        if (isset($data['SendOrganizationName']) && !empty($data['SendOrganizationName'])) {
            $sql .= ' and C.SendOrganizationName=?';
            $bind[] = $data['SendOrganizationName'];
        }
        //套餐名称
        if (isset($data['ComboName']) && !empty($data['ComboName'])) {
            $sql .= ' and A.Name=?';
            $bind[] = $data['ComboName'];
        }
        //状态
        if (isset($data['Status']) && !empty($data['Status']) && is_numeric($data['Status'])) {
            $sql .= ' and C.Status=?';
            $bind[] = $data['Status'];
        }

        $time = 'C.Created';
        if (isset($data['TypeTime']) && is_numeric($data['TypeTime'])) {
            switch ($data['TypeTime']) {
                case 1:
                    $time = 'C.Created';
                    break;
                case 2:
                    $time = 'L.LogTime';
                    break;
                case 3:
                    $time = 'M.LogTime';
                    break;
            }
        }
        //时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $sql .= " and {$time}>=?";
            $bind[] = $data['StartTime'];
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                throw new LogicException('错误的时间选择', Status::BadRequest);
            }
            $sql .= " and {$time}<=?";
            $bind[] = $data['EndTime'] + 86400;
        }

        $sql .= " order by C.Created desc ";

        //导出csv
        if (isset($data['Export']) && !empty($data['Export'])) {
            $csv = new AdminCsv(new Builder());
            $csv->comboOrderList($sql, $bind);
        }

        $paginator = new NativeArray([
            'data'  => $this->db->query($sql, $bind)->fetchAll(),
            'limit' => $pageSize,
            'page'  => $page,
        ]);
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items;
        foreach ($datas as &$data) {
            $data['StatusName'] = ComboOrder::STATUS_NAME[$data['Status']];
            $data['OrderNumber'] = (string)$data['OrderNumber'];
        }

        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 套餐订单详情
     */
    public function readAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            /** @var ComboOrder $order */
            $order = ComboOrder::findFirst(sprintf('Id=%d', $this->request->get('Id')));
            if (!$order) {
                throw $exception;
            }
            $read = new ReadComboOrder($order);
            $this->response->setJsonContent($read->consoleShow());
        } catch (ParamException $e) {
            throw $e;
        }
    }
}