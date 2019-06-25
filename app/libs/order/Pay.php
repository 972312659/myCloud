<?php

namespace App\Libs\Order;

use App\Enums\BillTitle;
use App\Enums\Status;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Models\OrderLog;
use App\Models\Bill;
use App\Models\Order;
use App\Models\Organization;
use Phalcon\Db\RawValue;
use Phalcon\Di\FactoryDefault;

class Pay
{
    /**
     * @var Manager
     */
    protected $manager;

    public function __construct(Manager $manager)
    {
        $this->manager = $manager;
    }

    public function slavePay(Order $order)
    {
        $auth = FactoryDefault::getDefault()->get('session')->get('auth');

        /** @var Organization $buyer */
        $buyer = Organization::findFirst([
            'conditions' => 'Id = ?0',
            'bind'       => [$order->BuyerOrganizationId],
            'for_update' => true
        ]);

        if ($buyer->Balance < $order->Amount) {
            throw new \LogicException('账户余额不足');
        }

        $exception = new ParamException(Status::BadRequest);

        // 扣除可用余额
        $buyer->Money = new RawValue(sprintf('Money-%d', $order->Amount));
        $buyer->Balance = new RawValue(sprintf('Balance-%d', $order->Amount));
        $buyer->save();
        $buyer->refresh();

        $buyer_bill = new Bill();
        $buyer_bill->OrganizationId = $order->BuyerOrganizationId;
        $buyer_bill->Title = sprintf(
            BillTitle::Product_BuyerToPlatform_Buyer,
            $order->OrderNumber,
            Alipay::fen2yuan($order->Amount)
        );
        $buyer_bill->Fee = Bill::outCome($order->Amount);
        $buyer_bill->Balance = $buyer->Balance;
        $buyer_bill->UserId = $auth['Id'];
        $buyer_bill->Type = Bill::TYPE_PAYMENT;
        $buyer_bill->Created = time();
        $buyer_bill->ReferenceType = Bill::REFERENCE_TYPE_ORDER;
        $buyer_bill->ReferenceId = $order->Id;
        if ($buyer_bill->save() === false) {
            $exception->loadFromModel($buyer_bill);
            throw $exception;
        }

        $peach = Organization::findFirst(Organization::PEACH);
        $peach->Money = new RawValue(sprintf('Money+%d', $order->Amount));
        $peach->Balance = new RawValue(sprintf('Balance+%d', $order->Amount));
        if ($peach->save() === false) {
            $exception->loadFromModel($peach);
            throw $exception;
        }
        $peach->refresh();

        $peach_bill = new Bill();
        $peach_bill->Title = sprintf(
            BillTitle::Product_BuyerToPlatform_Platform,
            $order->OrderNumber,
            Alipay::fen2yuan($order->Amount)
        );
        $peach_bill->OrganizationId = $peach->Id;
        $peach_bill->Fee = Bill::inCome($order->Amount);
        $peach_bill->Balance = $peach->Balance;
        $peach_bill->UserId = $auth['Id'];
        $peach_bill->Type = Bill::TYPE_PROFIT;
        $peach_bill->Created = time();
        $peach_bill->ReferenceType = Bill::REFERENCE_TYPE_ORDER;
        $peach_bill->ReferenceId = $order->Id;
        if ($peach_bill->save() === false) {
            $exception->loadFromModel($peach_bill);
            throw $exception;
        }

        $log = new OrderLog();
        $this->manager->changeStatus($order, Order::STATUS_WAIT_SEND, $log);
        $log->save();
        $order->save();
    }
}
