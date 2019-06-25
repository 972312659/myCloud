<?php

namespace App\Libs\Order;

use App\Enums\BillTitle;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Models\Bill;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderRefund;
use App\Models\Organization;
use Phalcon\Db\RawValue;
use Phalcon\Di\FactoryDefault;

class Receive
{
    /**
     * @var Manager
     */
    protected $manager;

    public function __construct(Manager $manager)
    {
        $this->manager = $manager;
    }

    public function buyerReceive(Order $order)
    {
        $this->manager->begin();
        $auth = FactoryDefault::getDefault()->get('session')->get('auth');

        try {
            $buyer = Organization::findFirst([
                'conditions' => 'Id = ?0',
                'bind' => [$order->BuyerOrganizationId],
                'for_update' => true
            ]);

            if (!$buyer) {
                throw new LogicException('未找到机构', Status::BadRequest);
            }
            $exception = new ParamException(Status::BadRequest);

            /** @var Organization $cloud */
            $cloud = Organization::findFirst([
                'conditions' => 'Id = ?0',
                'bind' => [Organization::PEACH],
                'for_update' => true
            ]);
            /** @var Organization $seller */
            $seller = Organization::findFirst([
                'conditions' => 'Id = ?0',
                'bind' => [$order->SellerOrganizationId],
                'for_update' => true
            ]);

            $cloud->Money = new RawValue(sprintf('Money-%d', $order->RealAmount));
            $cloud->Balance = new RawValue(sprintf('Balance-%d', $order->RealAmount));
            $cloud->save();
            $cloud->refresh();

            $cloud_bill = new Bill();
            $cloud_bill->OrganizationId = $cloud->Id;
            $cloud_bill->Title = sprintf(
                BillTitle::Product_PlatformToSeller_Platform,
                $order->OrderNumber,
                Alipay::fen2yuan($order->Amount),
                $seller->Name
            );
            $cloud_bill->Fee = Bill::inCome($order->RealAmount);
            $cloud_bill->Balance = $cloud->Balance;
            $cloud_bill->UserId = $auth['Id'];
            $cloud_bill->Type = Bill::TYPE_PAYMENT;
            $cloud_bill->Created = time();
            $cloud_bill->ReferenceType = Bill::REFERENCE_TYPE_ORDER;
            $cloud_bill->ReferenceId = $order->Id;
            if ($cloud_bill->save() === false) {
                $exception->loadFromModel($cloud_bill);
                throw $exception;
            }

            $seller->Money = new RawValue(sprintf('Money+%d', $order->RealAmount));
            $seller->Balance = new RawValue(sprintf('Balance+%d', $order->RealAmount));
            $seller->save();
            $seller->refresh();

            $seller_bill = new Bill();
            $seller_bill->Title = sprintf(
                BillTitle::Product_PlatformToSeller_Seller,
                $order->OrderNumber,
                Alipay::fen2yuan($order->RealAmount)
            );
            $seller_bill->OrganizationId = $order->BuyerOrganizationId;
            $seller_bill->Fee = Bill::inCome($order->RealAmount);
            $seller_bill->Balance = $seller->Balance;
            $seller_bill->UserId = $auth['Id'];
            $seller_bill->Type = Bill::TYPE_PROFIT;
            $seller_bill->Created = time();
            $seller_bill->ReferenceType = Bill::REFERENCE_TYPE_ORDER;
            $seller_bill->ReferenceId = $order->Id;
            if ($seller_bill->save() === false) {
                $exception->loadFromModel($seller_bill);
                throw $exception;
            }

            $log = new OrderLog();
            $this->manager->changeStatus($order, Order::STATUS_RECEIVED, $log);
            $log->save();
            $order->save();

            $this->manager->commit();
        } catch (\Exception $e) {
            $this->manager->rollback();
            throw $e;
        }
    }

    public function sellerReceive(Order $order)
    {
        $this->manager->begin();
        $auth = FactoryDefault::getDefault()->get('session')->get('auth');

        try {
            $buyer = Organization::findFirst([
                'conditions' => 'Id = ?0',
                'bind' => [$order->BuyerOrganizationId],
                'for_update' => true
            ]);

            $exception = new ParamException(Status::BadRequest);

            /** @var OrderRefund $refund */
            $refund = $order->Refund;
            $refund->Status = OrderRefund::STATUS_REFUNDED;

            /** @var Organization $seller */
            $seller = Organization::findFirst([
                'conditions' => 'Id = ?0',
                'bind' => [$order->SellerOrganizationId],
                'for_update' => true
            ]);

            //扣除商家退款金额
            $seller->Money = new RawValue(sprintf('Money-%d', $refund->Amount));
            $seller->Balance = new RawValue(sprintf('Balance-%d', $refund->Amount));
            $seller->save();
            $seller->refresh();

            $seller_bill = new Bill();
            $seller_bill->Title = sprintf(
                BillTitle::Product_PlatformToBuyer_Platform,
                $order->OrderNumber,
                Alipay::fen2yuan($refund->Amount)
            );
            $seller_bill->OrganizationId = $order->BuyerOrganizationId;
            $seller_bill->Fee = Bill::inCome($refund->Amount);
            $seller_bill->Balance = $seller->Balance;
            $seller_bill->UserId = $auth['Id'];
            $seller_bill->Type = Bill::TYPE_PAYMENT;
            $seller_bill->Created = time();
            $seller_bill->ReferenceType = Bill::REFERENCE_TYPE_ORDER;
            $seller_bill->ReferenceId = $order->Id;
            if ($seller_bill->save() === false) {
                $exception->loadFromModel($seller_bill);
                throw $exception;
            }

            //退款金额返回给买家
            $buyer->Money = new RawValue(sprintf('Money+%d', $refund->Amount));
            $buyer->Balance = new RawValue(sprintf('Balance+%d', $refund->Amount));
            $buyer->save();
            $buyer->refresh();

            $buyer_bill = new Bill();
            $buyer_bill->Title = sprintf(
                BillTitle::Product_PlatformToBuyer_Buyer,
                $order->OrderNumber,
                Alipay::fen2yuan($refund->Amount)
            );
            $buyer_bill->OrganizationId = $order->BuyerOrganizationId;
            $buyer_bill->Fee = Bill::inCome($refund->Amount);
            $buyer_bill->Balance = $seller->Balance;
            $buyer_bill->UserId = $auth['Id'];
            $buyer_bill->Type = Bill::TYPE_PROFIT;
            $buyer_bill->Created = time();
            $buyer_bill->ReferenceType = Bill::REFERENCE_TYPE_ORDER;
            $buyer_bill->ReferenceId = $order->Id;
            if ($buyer_bill->save() === false) {
                $exception->loadFromModel($seller_bill);
                throw $exception;
            }

            $log = new OrderLog();
            $this->manager->changeStatus($order, Order::STATUS_REFUNDED, $log);
            $log->save();
            $order->save();
            $refund->save();

            $this->manager->commit();
        } catch (\Exception $e) {
            $this->manager->rollback();
            throw $e;
        }
    }
}
