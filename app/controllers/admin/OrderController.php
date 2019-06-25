<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/21
 * Time: 上午11:35
 */

namespace App\Admin\Controllers;


use App\Enums\Mongo;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Libs\ExpressHundred;
use App\Libs\product\Mapper;
use App\Models\ExpressCompany;
use App\Models\Order;
use App\Models\OrderAndProductUnit;
use App\Models\OrderExpress;
use App\Models\OrderInfo;
use App\Models\OrderRefund;
use Phalcon\Paginator\Adapter\QueryBuilder;

class OrderController extends Controller
{
    /**
     * 商品订单列表
     */
    public function productOrderListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['Id', 'OrderNumber', 'RealAmount', 'Status', 'Created'])
            ->addFrom(Order::class, 'O')
            ->orderBy('Created desc');
        //状态
        if (isset($data['Status']) && is_numeric($data['Status'])) {
            $query->andWhere("O.Status=:Status:", ['Status' => $data['Status']]);
        }
        //编号
        if (!empty($data['OrderNumber']) && isset($data['OrderNumber'])) {
            $query->andWhere("O.OrderNumber=:OrderNumber:", ['OrderNumber' => $data['OrderNumber']]);
        }
        //时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("O.Created>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                $this->response->setStatusCode(Status::BadRequest);
                return;
            }
            $query->andWhere("O.Created<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
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
            $data['StatusName'] = Order::STATUS_NAME[$data['Status']];
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 读取一条商品订单
     */
    public function readProductOrderAction()
    {
        /** @var Order $order */
        $order = Order::findFirst(sprintf('Id=%d', $this->request->get('Id')));
        if (!$order) {
            throw new LogicException('订单未找到', Status::BadRequest);
        }
        $result = $order->toArray();
        $result['StatusName'] = Order::STATUS_NAME[$order->Status];
        $result['OrderInfo'] = OrderAndProductUnit::find([
            'conditions' => 'ChildOrderId=?0',
            'bind'       => [$order->Id],
        ])->toArray();

        $mapper = new Mapper($this->getDI()->getShared(Mongo::database));
        foreach ($result['OrderInfo'] as &$item) {
            /** @var \App\Libs\product\structure\Product $product */
            $product = $mapper->mongoIdToProduct($item['ProductVersion']);
            $item['Name'] = $product->Name;
            $item['Manufacturer'] = $product->Manufacturer;
        }

        //收货人信息
        /** @var OrderInfo $orderInfo */
        $orderInfo = OrderInfo::findFirst(sprintf('Id=%d', $order->OrderInfoId));
        $result['AcceptInfo'] = $orderInfo->toArray();
        $result['AcceptInfo']['Province'] = $orderInfo->Province->Name;
        $result['AcceptInfo']['City'] = $orderInfo->City->Name;
        $result['AcceptInfo']['Area'] = $orderInfo->Area->Name;

        //快递信息
        $result['Express'] = [];
        /** @var OrderExpress $orderExpress */
        $orderExpress = OrderExpress::findFirst(['conditions' => 'OrderId=?0', 'bind' => [$order->Id]]);
        if ($orderExpress) {
            /** @var ExpressCompany $expressCompany */
            $expressCompany = ExpressCompany::findFirst(['conditions' => 'Com=?0', 'bind' => [$orderExpress->Com]]);
            $express = new ExpressHundred();
            $express_result = $express->get($orderExpress->Com, $orderExpress->Number);
            $result['Express']['Company'] = $expressCompany->Name;
            $result['Express']['Number'] = $orderExpress->Number;
            $result['Express']['Info'] = $express_result['status'] ? array_column($express_result['message'], 'context') : [];
        }

        //售后信息
        $result['Refund'] = [];
        $orderRefund = OrderRefund::findFirst(['conditions' => 'OrderId=?0', 'bind' => [$order->Id]]);
        if ($orderRefund) {
            $result['Refund'] = $orderRefund->toArray();
        }
        $this->response->setJsonContent($result);
    }
}