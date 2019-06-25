<?php

namespace App\Libs\Order;

use App\Models\InteriorTrade;
use App\Models\Order;
use App\Models\OrderAndProductUnit;
use App\Models\OrderLog;
use App\Models\OrderRefund;
use App\Models\ProductUnitStatus;
use Phalcon\Db\Adapter;
use Phalcon\Db\RawValue;

class Manager
{
    /**
     * @var Adapter
     */
    protected $db;

    public function __construct(Adapter $db)
    {
        $this->db = $db;
    }

    public function cancel(Order $order, $cause)
    {
        //返还库存
        $items = OrderAndProductUnit::find([
            'conditions' => 'OrderId = ?0',
            'bind' => [$order->Id]
        ]);

        foreach ($items as $item) {
            /** @var ProductUnitStatus $status */
            $status = ProductUnitStatus::findFirst([
                'conditions' => 'ProductUnitId = ?0',
                'bind' => [$item->ProductUnitId],
                'for_update' => true
            ]);

            //sku 可能已经被删除了, 这种情况直接忽略后续的处理
            if (empty($status)) {
                continue;
            }

            $status->Stock = new RawValue('Stock+' . $item->Quantity);
            $status->save();
        }

        //order 变更为 取消
        $log = new OrderLog();
        $this->changeStatus($order, Order::STATUS_CLOSED, $log);
        $log->save();

        //取消审核单
        $sql = 'UPDATE InteriorTrade SET Status = '.InteriorTrade::STATUS_CANCEL.
            ' WHERE Id IN(SELECT InteriorTradeId FROM InteriorTradeAndOrder WHERE OrderId = :oid)';

        $this->db->query($sql, ['oid' => $order->Id])->execute();
        $order->CloseCause = $cause;
        $order->save();
    }

    /**
     * 申请退款
     *
     * @param Order $order
     * @param $reason
     * @param $type
     * @return OrderRefund
     */
    public function applyRefund(Order $order, $reason, $type): OrderRefund
    {
        //退款单
        $refund = new OrderRefund();
        $refund->SerialNumber = time() << 32 | substr('0000000' . $order->BuyerOrganizationId, -7, 7);
        $refund->OrderId = $order->Id;
        $refund->Reason = $reason;
        $refund->Feedback = '';
        $refund->Status = OrderRefund::STATUS_PENDING;
        $refund->Created = time();
        $refund->Type = $type;
        $refund->OrderStatus = $order->Status;

        switch ($order->Status) {
            case Order::STATUS_RECEIVED:
                //不退邮费
                $refund->Amount = (int)$order->RealAmount - (int)$order->Postage;
                break;
            case Order::STATUS_WAIT_SEND:
                //退邮费
                $refund->Amount = (int)$order->RealAmount;
        }

        $refund->save();
        $refund->refresh();

        $log = new OrderLog();
        $this->changeStatus($order, Order::STATUS_REFUNDING, $log);
        $log->save();
        $order->IsRefund = 1;
        $order->save();

        return $refund;
    }

    public function canCancel(Order $order)
    {
        return in_array($order->Status, [
            Order::STATUS_WAIT_PAY,
        ]);
    }

    public function canRefund(Order $order)
    {
        return in_array($order->Status, [
            Order::STATUS_RECEIVED,
            Order::STATUS_WAIT_SEND
        ]);
    }

    public function changeStatus(Order $order, $status, OrderLog $log)
    {
        $log->Created = time();
        $log->OrderId = $order->Id;
        $log->SellerId = $order->SellerOrganizationId;
        $log->BuyerId = $order->BuyerOrganizationId;
        $log->PreStatus = $order->Status;
        $log->CurrentStatus = $status;

        $order->Status = $status;
    }

    public function createInteriorTrade(Order $order): InteriorTrade
    {
        $interior = new InteriorTrade();
        $interior->Amount = $order->Amount;
        $interior->Total = $order->Amount;
        $interior->Created = time();

        return $interior;
    }

    /**
     * @return Adapter
     */
    public function getDb(): Adapter
    {
        return $this->db;
    }

    public function begin()
    {
        $this->db->begin();
    }

    public function commit()
    {
        $this->db->commit();
    }

    public function rollback()
    {
        $this->db->rollback();
    }

//    public function rollbackStatus(Order $order)
//    {
//        $log = new OrderLog();
//        $this->changeStatus($order, $current_status->PreStatus, $log);
//        $log->save();
//        $order->save();
//    }
}
