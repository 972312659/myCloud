<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/19
 * Time: 下午5:39
 */

namespace App\Libs\interiorTrade;

use App\Enums\BillTitle;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\salesman\TransferBonus;
use App\Libs\transfer\Rule as SupplierRule;
use App\Models\Bill;
use App\Models\InteriorTrade;
use App\Models\InteriorTradeAndOrder;
use App\Models\InteriorTradeAndTransfer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\SalesmanBonus as SalesmanBonusModel;
use App\Models\Transfer;
use Phalcon\Db\RawValue;

class Prepaid
{
    protected $interiorTrade;
    protected $time;
    protected $auth;

    public function __Construct(InteriorTrade $interiorTrade, $auth, $time)
    {
        $this->interiorTrade = $interiorTrade;
        $this->auth = $auth;
        $this->time = $time;
    }

    public function transfer(int $transferId)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $type = Bill::TYPE_PROFIT;
            /** @var Transfer $transfer */
            $transfer = Transfer::findFirst(sprintf('Id=%d', $transferId));
            if (!$transfer) {
                throw $exception;
            }
            /** @var InteriorTradeAndTransfer $interiorTradeAndTransfer */
            $interiorTradeAndTransfer = InteriorTradeAndTransfer::findFirst([
                'conditions' => 'InteriorTradeId=?0 and TransferId=?1',
                'bind'       => [$this->interiorTrade->Id, $transfer->Id],
            ]);
            if (!$interiorTradeAndTransfer) {
                throw $exception;
            }

            $transfer->Status = Transfer::FINISH;
            $transfer->EndTime = $this->time;
            /** @var Organization $hospital */
            $hospital = Organization::findFirst(sprintf('Id=%d', $transfer->AcceptOrganizationId));
            /** @var Organization $cloud */
            $cloud = Organization::findFirst(Organization::PEACH);
            /** @var Organization $b */
            $b = Organization::findFirst(sprintf('Id=%d', $transfer->SendOrganizationId));
            if (!$hospital || !$cloud || !$b) {
                throw $exception;
            }
            //分润
            //平台、b初始分润值
            $money_cloud = $interiorTradeAndTransfer->ShareCloud;
            $money_b = $interiorTradeAndTransfer->Amount;
            //B应该出的费用
            $money_B = $this->interiorTrade->Total;


            //业务经理奖励
            /** @var SalesmanBonusModel $salesmanBonus */
            $salesmanBonus = SalesmanBonusModel::findFirst([
                'conditions' => 'OrganizationId=?0 and ReferenceType=?1 and ReferenceId=?2',
                'bind'       => [$this->auth['OrganizationId'], SalesmanBonusModel::ReferenceType_Transfer, $interiorTradeAndTransfer->TransferId],
            ]);
            $transferBonus = new TransferBonus();
            $bonus = $transferBonus->salesmanBonus($salesmanBonus);
            $money_salesman = $bonus->Bonus ?: 0;

            //如果是分销 小b的大B初始分润值
            $money_b_B = $money_B - ($money_cloud + $money_b + $money_salesman);

            //医院支付 数据入库 形成账单
            // 扣除可用余额
            $hospital->Money = new RawValue(sprintf('Money-%d', $money_B));
            $hospital->Balance = new RawValue(sprintf('Balance-%d', $money_B));
            $hospital->TransferAmount += 1;
            if ($hospital->save() === false) {
                $exception->loadFromModel($hospital);
                throw $exception;
            }
            // 余额不足回滚
            $hospital->refresh();
            if ($hospital->Money < 0 || $hospital->Balance < 0) {
                throw new LogicException('余额不足', Status::BadRequest);
            }
            $bill = new Bill();
            $hospital_bill['Title'] = $money_salesman ?
                sprintf(BillTitle::Transfer_Hospital_SalesmanBonus, $transfer->OrderNumber, $transfer->PatientName, $transfer->SendHospital->Name, Alipay::fen2yuan((int)$money_b_B), $transfer->SendOrganizationName, Alipay::fen2yuan((int)$money_b), $bonus->UserName, Alipay::fen2yuan((int)$money_salesman), Alipay::fen2yuan((int)$money_cloud), Alipay::fen2yuan((int)$money_B)) :
                sprintf(BillTitle::Transfer_Hospital, $transfer->OrderNumber, $transfer->PatientName, $transfer->SendHospital->Name, Alipay::fen2yuan((int)$money_b_B), $transfer->SendOrganizationName, Alipay::fen2yuan((int)$money_b), Alipay::fen2yuan((int)$money_cloud), Alipay::fen2yuan((int)$money_B));
            $hospital_bill['OrganizationId'] = $hospital->Id;
            $hospital_bill['Fee'] = Bill::outCome($money_B);
            $hospital_bill['Balance'] = $hospital->Balance;
            $hospital_bill['UserId'] = $this->auth['Id'];
            $hospital_bill['Type'] = Bill::TYPE_PAYMENT;
            $hospital_bill['Created'] = $this->time;
            $hospital_bill['ReferenceType'] = Bill::REFERENCE_TYPE_TRANSFER;
            $hospital_bill['ReferenceId'] = $transfer->Id;
            if ($bill->save($hospital_bill) === false) {
                $exception->loadFromModel($bill);
                throw $exception;
            }
            //小b分润数据入库 形成账单
            $b->Money = new RawValue(sprintf('Money+%d', $money_b));
            $b->Balance = new RawValue(sprintf('Balance+%d', $money_b));
            $b->TransferAmount = new RawValue('TransferAmount+1');
            if ($b->save() === false) {
                $exception->loadFromModel($b);
                throw $exception;
            }
            $b->refresh();
            $bill = new Bill();
            $b_bill['Title'] = sprintf(BillTitle::Transfer_Slave, $this->interiorTrade->SendOrganizationName, Alipay::fen2yuan((int)$money_b));
            $b_bill['OrganizationId'] = $b->Id;
            $b_bill['Fee'] = Bill::inCome($money_b);
            $b_bill['Balance'] = $b->Balance;
            $b_bill['UserId'] = $this->auth['Id'];
            $b_bill['Type'] = $type;
            $b_bill['Created'] = $this->time;
            $b_bill['ReferenceType'] = Bill::REFERENCE_TYPE_TRANSFER;
            $b_bill['ReferenceId'] = $transfer->Id;
            if ($bill->save($b_bill) === false) {
                $exception->loadFromModel($bill);
                throw $exception;
            }

            //平台分润数据入库 形成账单
            $cloud->Money = new RawValue(sprintf('Money+%d', $money_cloud));
            $cloud->Balance = new RawValue(sprintf('Balance+%d', $money_cloud));

            if ($cloud->save() === false) {
                $exception->loadFromModel($cloud);
                throw $exception;
            }
            $cloud->refresh();
            $bill = new Bill();
            $cloud_bill['Title'] = sprintf(BillTitle::Transfer_Platform, $this->interiorTrade->SendOrganizationName, $transfer->Genre == 1 ? '自有' : '共享', Alipay::fen2yuan((int)$money_cloud));
            $cloud_bill['OrganizationId'] = $cloud->Id;
            $cloud_bill['Fee'] = Bill::inCome($money_cloud);
            $cloud_bill['Balance'] = $cloud->Balance;
            $cloud_bill['UserId'] = $this->auth['Id'];
            $cloud_bill['Type'] = $type;
            $cloud_bill['Created'] = $this->time;
            $cloud_bill['ReferenceType'] = Bill::REFERENCE_TYPE_TRANSFER;
            $cloud_bill['ReferenceId'] = $transfer->Id;
            if ($bill->save($cloud_bill) === false) {
                $exception->loadFromModel($bill);
                throw $exception;
            }
            //共享分润
            $b_B = Organization::findFirst(sprintf('Id=%d', $transfer->SendHospitalId));
            if ($money_b_B != 0 && $b_B && $transfer->Genre == Transfer::GENRE_SHARE) {
                //小b上面大b分润数据入库  形成账单
                $b_B->Money = new RawValue(sprintf('Money+%d', $money_b_B));
                $b_B->Balance = new RawValue(sprintf('Balance+%d', $money_b_B));
                if ($b_B->save() === false) {
                    $exception->loadFromModel($b_B);
                    throw $exception;
                }
                $b_B->refresh();
                $bill = new Bill();
                $b_B_bill['Title'] = sprintf(BillTitle::Transfer_ShareHospital, $this->interiorTrade->AcceptOrganizationName, $transfer->Hospital->Name, Alipay::fen2yuan((int)$money_b_B));
                $b_B_bill['OrganizationId'] = $b_B->Id;
                $b_B_bill['Fee'] = Bill::inCome($money_b_B);
                $b_B_bill['Balance'] = $b_B->Balance;
                $b_B_bill['UserId'] = $this->auth['Id'];
                $b_B_bill['Type'] = $type;
                $b_B_bill['Created'] = $this->time;
                $b_B_bill['ReferenceType'] = Bill::REFERENCE_TYPE_TRANSFER;
                $b_B_bill['ReferenceId'] = $transfer->Id;
                if ($bill->save($b_B_bill) === false) {
                    $exception->loadFromModel($bill);
                    throw $exception;
                }
            }
            if ($transfer->save() === false) {
                $exception->loadFromModel($transfer);
                throw $exception;
            }

            //处理业务经理奖励
            $transferBonus->payment($this->interiorTrade, $salesmanBonus, $transfer);

            //更新供应商手续费规则
            // $transfer->refresh();
            $rule = new SupplierRule();
            $rule->supplierFeeUpdate((int)$transfer->AcceptOrganizationId);
            return ['transfer' => $transfer, 'money_cloud' => $money_cloud, 'money_b' => $money_b, 'money_B' => $money_B, 'money_b_B' => $money_b_B];
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function accounts()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $hospital = Organization::findFirst(sprintf('Id=%d', $this->auth['OrganizationId']));
            $slave = Organization::findFirst(sprintf('Id=%d', $this->interiorTrade->AcceptOrganizationId));
            // 医院扣除可用余额
            $hospital->Money = new RawValue(sprintf('Money-%d', $this->interiorTrade->Amount));
            $hospital->Balance = new RawValue(sprintf('Balance-%d', $this->interiorTrade->Amount));
            if ($hospital->save() === false) {
                $exception->loadFromModel($hospital);
                throw $exception;
            }
            // 余额不足回滚
            $hospital->refresh();
            if ($hospital->Money < 0 || $hospital->Balance < 0) {
                throw new LogicException('余额不足', Status::BadRequest);
            }
            // 网点收款
            $slave->Money = new RawValue(sprintf('Money+%d', $this->interiorTrade->Amount));
            $slave->Balance = new RawValue(sprintf('Balance+%d', $this->interiorTrade->Amount));
            if ($slave->save() === false) {
                $exception->loadFromModel($slave);
                throw $exception;
            }
            $slave->refresh();
            //生成医院账单
            $hospital_bill = new Bill();
            $hospital_bill->Title = sprintf(BillTitle::InteriorTrade_Out, $this->interiorTrade->AcceptOrganizationName, Alipay::fen2yuan((int)$this->interiorTrade->Amount));
            $hospital_bill->OrganizationId = $hospital->Id;
            $hospital_bill->Fee = Bill::outCome($this->interiorTrade->Amount);
            $hospital_bill->Balance = $hospital->Balance;
            $hospital_bill->UserId = $this->auth['Id'];
            $hospital_bill->Type = Bill::TYPE_PAYMENT;
            $hospital_bill->Created = $this->time;
            $hospital_bill->ReferenceType = Bill::REFERENCE_TYPE_INTERIORTRADE;
            $hospital_bill->ReferenceId = $this->interiorTrade->Id;
            if ($hospital_bill->save() === false) {
                $exception->loadFromModel($hospital_bill);
                throw $exception;
            }
            //生成网点账单
            $slave_bill = new Bill();
            $slave_bill->Title = sprintf(BillTitle::InteriorTrade_In, $this->interiorTrade->SendOrganizationName, Alipay::fen2yuan((int)$this->interiorTrade->Amount));
            $slave_bill->OrganizationId = $slave->Id;
            $slave_bill->Fee = Bill::inCome($this->interiorTrade->Amount);
            $slave_bill->Balance = $slave->Balance;
            $slave_bill->UserId = $this->auth['Id'];
            $slave_bill->Type = Bill::TYPE_PROFIT;
            $slave_bill->Created = $this->time;
            $slave_bill->ReferenceType = Bill::REFERENCE_TYPE_INTERIORTRADE;
            $slave_bill->ReferenceId = $this->interiorTrade->Id;
            if ($slave_bill->save() === false) {
                $exception->loadFromModel($slave_bill);
                throw $exception;
            }
            return ['hospital' => $hospital, 'slave' => $slave];
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function product()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            /** @var InteriorTradeAndOrder $interiorTradeAndOrder */
            $interiorTradeAndOrder = InteriorTradeAndOrder::findFirst([
                'conditions' => 'InteriorTradeId=?0',
                'bind'       => [$this->interiorTrade->Id],
            ]);
            if (!$interiorTradeAndOrder) {
                throw $exception;
            }
            /** @var Order $parentOrder */
            $parentOrder = Order::findFirst(sprintf('Id=%d', $interiorTradeAndOrder->OrderId));
            if (!$parentOrder) {
                throw $exception;
            }
            if ($parentOrder->IsParent === Order::IsParent_both) {
                /** 单个订单 */
                $orders = [$parentOrder];
            } else {
                /** 多个订单 */
                $orders = Order::find([
                    'conditions' => 'ParentId=?0',
                    'bind'       => [$parentOrder->Id],
                ]);
                if (!count($orders->toArray())) {
                    throw $exception;
                }
            }
            /** @var Organization $organization */
            $organization = Organization::findFirst(sprintf('Id=%d', $this->auth['OrganizationId']));
            /** @var Organization $cloud */
            $cloud = Organization::findFirst(Organization::PEACH);
            foreach ($orders as $order) {
                /** @var Order $order */
                $money = $order->RealAmount;

                //订单买家支付给平台 生成账单
                $organization->Money = new RawValue(sprintf('Money-%d', $money));
                $organization->Balance = new RawValue(sprintf('Balance-%d', $money));
                if ($organization->save() === false) {
                    $exception->loadFromModel($organization);
                    throw $exception;
                }
                // 余额不足回滚
                $organization->refresh();
                if ($organization->Money < 0 || $organization->Balance < 0) {
                    throw new LogicException('余额不足', Status::BadRequest);
                }

                $organization_bill = new Bill();
                $organization_bill->Title = sprintf(BillTitle::Product_BuyerToPlatform_Buyer, $order->OrderNumber, Alipay::fen2yuan($money));
                $organization_bill->OrganizationId = $organization->Id;
                $organization_bill->Fee = Bill::outCome($money);
                $organization_bill->Balance = $organization->Balance;
                $organization_bill->UserId = $this->auth['Id'];
                $organization_bill->Type = Bill::TYPE_PAYMENT;
                $organization_bill->Created = $this->time;
                $organization_bill->ReferenceType = Bill::REFERENCE_TYPE_ORDER;
                $organization_bill->ReferenceId = $order->Id;
                if ($organization_bill->save() === false) {
                    $exception->loadFromModel($organization_bill);
                    throw $exception;
                }

                //订单费用平台收入 形成账单
                $cloud->Money = new RawValue(sprintf('Money+%d', $money));
                $cloud->Balance = new RawValue(sprintf('Balance+%d', $money));
                if ($cloud->save() === false) {
                    $exception->loadFromModel($cloud);
                    throw $exception;
                }
                $cloud->refresh();

                $cloud_bill = new Bill();
                $cloud_bill->Title = sprintf(BillTitle::Product_BuyerToPlatform_Platform, $order->OrderNumber, Alipay::fen2yuan($money));
                $cloud_bill->OrganizationId = $cloud->Id;
                $cloud_bill->Fee = Bill::inCome($money);
                $cloud_bill->Balance = $cloud->Balance;
                $cloud_bill->UserId = $this->auth['Id'];
                $cloud_bill->Type = Bill::TYPE_PROFIT;
                $cloud_bill->Created = $this->time;
                $cloud_bill->ReferenceType = Bill::REFERENCE_TYPE_ORDER;
                $cloud_bill->ReferenceId = $order->Id;
                if ($cloud_bill->save() === false) {
                    $exception->loadFromModel($cloud_bill);
                    throw $exception;
                }

                //订单状态
                $order->Status = Order::STATUS_WAIT_SEND;
                if (!$order->save()) {
                    $exception->loadFromModel($order);
                    throw $exception;
                }
            }
            /** 多个订单处理总订单，将状态变为已支付成功 */
            if ($parentOrder->IsParent !== Order::IsParent_both) {
                $parentOrder->Status = Order::STATUS_WAIT_SEND;
                if (!$parentOrder->save()) {
                    $exception->loadFromModel($parentOrder);
                    throw $exception;
                }
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }
}