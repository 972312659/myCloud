<?php

namespace App\Libs\Order;

use App\Enums\BillTitle;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Libs\Alipay;
use App\Models\Bill;
use App\Models\Order;
use App\Models\OrderAndProductUnit;
use App\Models\OrderLog;
use App\Models\OrderRefund;
use App\Models\Organization;
use App\Models\ProductUnitStatus;
use Phalcon\Db\RawValue;
use Phalcon\Di\FactoryDefault;

class Refund
{
    /**
     * @var Manager
     */
    protected $manager;

    /**
     * @var OrderRefund
     */
    protected $refund;

    /**
     * @var Order
     */
    protected $order;

    public function __construct(Manager $manager, Order $order)
    {
        $this->manager = $manager;
        $this->order = $order;
        $this->refund = $order->Refund;
    }

    public function agree()
    {
        $refund = $this->refund;
        $order = $this->order;
        $log = new OrderLog();

        switch ($refund->Type) {
            case OrderRefund::TYPE_ONLY_REFUND:
                $refund->Status = OrderRefund::STATUS_REFUNDED;
                $this->manager->changeStatus($order, Order::STATUS_REFUNDED, $log);//保存日志
                break;
            case OrderRefund::TYPE_RETURN_GOODS://需要退货,进入待发货状态
                $refund->Status = OrderRefund::STATUS_WAIT_SEND;
                break;
            default:
                throw new LogicException('退款单类型异常', Status::BadRequest);
        }

        //创建审核单
        /*$interior = $this->manager->createInteriorTrade($order);
        $interior->Style = InteriorTrade::STYLE_REFUND;
        $interior->SendOrganizationId = $order->BuyerOrganizationId;
        $interior->SendOrganizationName = $order->BuyerOrganizationName;
        $interior->Message = sprintf(
            '[%s] 申请退款, 订单号 [%s]',
            $order->BuyerOrganizationName,
            $order->OrderNumber
        );
        $interior->Status = InteriorTrade::STATUS_PREPAID;
        $interior->save();
        $interior->refresh();*/

        //返还商品库存
        $items = OrderAndProductUnit::find([
            'conditions' => 'OrderId = ?0',
            'bind'       => [$order->Id],
            'for_update' => true
        ]);

        foreach ($items as $item) {
            /** @var ProductUnitStatus $status */
            $status = ProductUnitStatus::findFirst([
                'conditions' => 'ProductUnitId = ?0',
                'bind'       => [$item->ProductUnitId],
                'for_update' => true
            ]);

            //sku 可能已经被删除了, 这种情况直接忽略后续的处理
            if (empty($status)) {
                continue;
            }

            $status->Stock = new RawValue('Stock+' . $item->Quantity);
            $status->save();
        }

        switch ($this->ensureStatus()) {
            case Order::STATUS_RECEIVED:
                $this->sellerToBuyer();
                break;
            case Order::STATUS_WAIT_SEND:
                $this->cloudToBuyer();
        }

        $refund->save();
        $log->save();
        $order->save();
    }

    /**
     * 拒绝退款
     * \\     */
    public function refuse()
    {
        $refund = $this->refund;

        $refund->Status = OrderRefund::STATUS_REFUSED;
        $refund->save();
        //回滚到上一个状态
        $this->rollbackStatus();
    }

    /**
     * 卖家退款给买家
     *
     * @throws LogicException
     */
    private function sellerToBuyer()
    {
        $order = $this->order;
        /** @var Organization $seller */
        $seller = Organization::findFirst([
            'conditions' => 'Id = ?0',
            'bind'       => [$order->SellerOrganizationId],
            'for_update' => true
        ]);

        //不退邮费
        $this->refundToBuyer($seller);
    }

    /**
     * 从平台退款给买家
     *
     * @throws LogicException
     */
    private function cloudToBuyer()
    {
        /** @var Organization $seller */
        $seller = Organization::findFirst([
            'conditions' => 'Id = ?0',
            'bind'       => [Organization::PEACH],
            'for_update' => true
        ]);

        //全额退
        $this->refundToBuyer($seller);
    }

    /**
     * 退款给买家
     * @param Organization $src
     * @throws LogicException
     */
    private function refundToBuyer(Organization $src)
    {
        $refund = $this->refund;
        $order = $this->order;
        $amount = $refund->Amount;

        $auth = FactoryDefault::getDefault()->get('session')->get('auth');
        $userId = $auth['Id'];

        //返还买家金额
        $buyer = Organization::findFirst([
            'conditions' => 'Id = ?0',
            'bind'       => [$order->BuyerOrganizationId],
            'for_update' => true
        ]);
        $buyer->Money = new RawValue('Money+' . $amount);
        $buyer->Balance = new RawValue('Balance+' . $amount);
        $buyer->save();
        $buyer->refresh();


        $src->Money = new RawValue('Money-' . $amount);
        $src->Balance = new RawValue('Balance-' . $amount);
        $src->save();
        $src->refresh();

        if ($src->Money < 0 || $src->Balance < 0) {
            throw new LogicException('余额不足', Status::BadRequest);
        }

        //买家账单
        $bill = new Bill();
        $bill->Created = time();
        $bill->Type = Bill::TYPE_PROFIT;
        $bill->OrganizationId = $order->BuyerOrganizationId;
        $bill->Title = sprintf(
            BillTitle::Product_PlatformToBuyer_Platform,
            $order->OrderNumber,
            Alipay::fen2yuan($amount)
        );
        $bill->Fee = $amount;
        $bill->Balance = $buyer->Balance;
        $bill->ReferenceType = Bill::REFERENCE_TYPE_ORDER;
        $bill->ReferenceId = $order->Id;
        $bill->UserId = $userId;
        $bill->save();

        //卖家账单
        $bill = new Bill();
        $bill->Created = time();
        $bill->Type = Bill::TYPE_PROFIT;
        $bill->OrganizationId = $order->SellerOrganizationId;
        $bill->Title = sprintf(
            BillTitle::Product_PlatformToBuyer_Platform,
            $order->OrderNumber,
            Alipay::fen2yuan($amount)
        );
        $bill->Fee = '-' . $amount;
        $bill->Balance = $src->Balance;
        $bill->ReferenceType = Bill::REFERENCE_TYPE_ORDER;
        $bill->ReferenceId = $order->Id;
        $bill->UserId = $userId;
        $bill->save();
    }

    /**
     *
     * 确保订单当前状态以及在进入 [退款中] 之前的状态是正确的
     * @throws LogicException
     */
    private function ensureStatus()
    {
        /** @var OrderLog $last_log */
        $last_log = OrderLog::findFirst([
            'conditions' => 'OrderId = ?0',
            'bind' => [$this->order->Id],
            'order'      => 'Id DESC'
        ]);

        if ($last_log->CurrentStatus != Order::STATUS_REFUNDING
            || !in_array($last_log->PreStatus, [Order::STATUS_WAIT_SEND, Order::STATUS_RECEIVED])
        ) {
            throw new LogicException('订单状态异常', Status::BadRequest);
        }

        return $last_log->PreStatus;
    }

    private function rollbackStatus()
    {
        $log = new OrderLog();
        $this->manager->changeStatus($this->order, $this->refund->OrderStatus, $log);
        $this->order->save();
        $log->save();
    }
}
