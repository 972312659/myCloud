<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/3/14
 * Time: 12:47 PM
 */

namespace App\Libs\combo;

use App\Enums\BillTitle;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Models\Bill;
use App\Models\Combo;
use App\Models\ComboAndOrder;
use App\Models\ComboOrder;
use App\Models\ComboOrderBatch;
use App\Models\ComboRefund;
use App\Models\Organization;
use Phalcon\Db\RawValue;
use Phalcon\Di\FactoryDefault;

class Pay
{
    /**
     * 网点打包购买套餐
     * @param ComboOrderBatch $comboOrderBatch
     * @throws LogicException
     * @throws ParamException
     */
    public static function slavePayPeach(ComboOrderBatch $comboOrderBatch)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = FactoryDefault::getDefault()->get('session')->get('auth');
            /** @var Organization $peach */
            $peach = Organization::findFirst(Organization::PEACH);
            /** @var Organization $slave */
            $slave = Organization::findFirst(sprintf('Id=%d', $comboOrderBatch->OrganizationId));
            if (!$peach || !$slave) {
                throw $exception;
            }

            $money = self::comboOrderBatchMoney($comboOrderBatch);

            // 扣除可用余额
            $slave->Money = new RawValue(sprintf('Money-%d', $money));
            $slave->Balance = new RawValue(sprintf('Balance-%d', $money));
            if ($slave->save() === false) {
                $exception->loadFromModel($slave);
                throw $exception;
            }
            // 余额不足回滚,返回false
            $slave->refresh();
            if ($slave->Money < 0 || $slave->Balance < 0) {
                throw new LogicException('余额不足', Status::BadRequest);
            }

            $slave_bill = new Bill();
            $slave_bill->Title = sprintf(BillTitle::ComboOrderBatch_Slave_Out, $comboOrderBatch->Name, $comboOrderBatch->OrderNumber, Alipay::fen2yuan($money));
            $slave_bill->OrganizationId = $slave->Id;
            $slave_bill->Fee = Bill::outCome($money);
            $slave_bill->Balance = $slave->Balance;
            $slave_bill->UserId = $auth['Id'];
            $slave_bill->Type = Bill::TYPE_PAYMENT;
            $slave_bill->Created = time();
            $slave_bill->ReferenceType = Bill::REFERENCE_TYPE_COMBOORDERBATCH;
            $slave_bill->ReferenceId = $comboOrderBatch->Id;
            if ($slave_bill->save() === false) {
                $exception->loadFromModel($slave_bill);
                throw $exception;
            }

            //将套餐费放入平台账户
            $peach->Money = new RawValue(sprintf('Money+%d', $money));
            $peach->Balance = new RawValue(sprintf('Balance+%d', $money));
            if ($peach->save() === false) {
                $exception->loadFromModel($peach);
                throw $exception;
            }
            $peach->refresh();

            $peach_bill = new Bill();
            $peach_bill->Title = sprintf(BillTitle::ComboOrderBatch_Peach_In, $auth['HospitalName'], $auth['OrganizationName'], $comboOrderBatch->OrderNumber, Alipay::fen2yuan($money));
            $peach_bill->OrganizationId = $peach->Id;
            $peach_bill->Fee = Bill::inCome($money);
            $peach_bill->Balance = $peach->Balance;
            $peach_bill->UserId = $auth['Id'];
            $peach_bill->Type = Bill::TYPE_PROFIT;
            $peach_bill->Created = time();
            $peach_bill->ReferenceType = Bill::REFERENCE_TYPE_COMBOORDERBATCH;
            $peach_bill->ReferenceId = $comboOrderBatch->Id;
            if ($peach_bill->save() === false) {
                $exception->loadFromModel($peach_bill);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 患者到院治疗，出院时，平台将套餐款支付给医院
     * @param ComboOrder $comboOrder
     * @throws LogicException
     * @throws ParamException
     */
    public static function peachPayHospital(ComboOrder $comboOrder)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = FactoryDefault::getDefault()->get('session')->get('auth');
            /** @var Organization $peach */
            $peach = Organization::findFirst(Organization::PEACH);
            /** @var Organization $hospital */
            $hospital = Organization::findFirst(sprintf('Id=%d', $comboOrder->HospitalId));
            if (!$peach || !$hospital) {
                throw $exception;
            }
            // 扣除可用余额
            $money = self::comboOrderMoney($comboOrder);
            //平台账户将套餐费返还
            $peach->Money = new RawValue(sprintf('Money-%d', $money));
            $peach->Balance = new RawValue(sprintf('Balance-%d', $money));
            if ($peach->save() === false) {
                $exception->loadFromModel($peach);
                throw $exception;
            }
            // 余额不足回滚,返回false
            $peach->refresh();
            if ($peach->Money < 0 || $peach->Balance < 0) {
                throw new LogicException('请致电桃子互联网医院', Status::BadRequest);
            }
            $peach_bill = new Bill();
            $peach_bill->Title = sprintf(BillTitle::ComboOrderBatch_Peach_Out, $comboOrder->OrderNumber, Alipay::fen2yuan($money));
            $peach_bill->OrganizationId = $peach->Id;
            $peach_bill->Fee = Bill::outCome($money);
            $peach_bill->Balance = $peach->Balance;
            $peach_bill->UserId = $auth['Id'];
            $peach_bill->Type = Bill::TYPE_PAYMENT;
            $peach_bill->Created = time();
            $peach_bill->ReferenceType = Bill::REFERENCE_TYPE_COMBOORDER;
            $peach_bill->ReferenceId = $comboOrder->Id;
            if ($peach_bill->save() === false) {
                $exception->loadFromModel($peach_bill);
                throw $exception;
            }

            //套餐费收入
            $hospital->Money = new RawValue(sprintf('Money+%d', $money));
            $hospital->Balance = new RawValue(sprintf('Balance+%d', $money));
            if ($hospital->save() === false) {
                $exception->loadFromModel($hospital);
                throw $exception;
            }
            $hospital->refresh();
            $slave_bill = new Bill();
            $slave_bill->Title = sprintf(BillTitle::ComboOrderBatch_Hospital_In, $comboOrder->SendHospital->Name, $comboOrder->SendOrganizationName, $comboOrder->OrderNumber, Alipay::fen2yuan($money), $comboOrder->PatientName);
            $slave_bill->OrganizationId = $hospital->Id;
            $slave_bill->Fee = Bill::inCome($money);
            $slave_bill->Balance = $hospital->Balance;
            $slave_bill->UserId = $auth['Id'];
            $slave_bill->Type = Bill::TYPE_PROFIT;
            $slave_bill->Created = time();
            $slave_bill->ReferenceType = Bill::REFERENCE_TYPE_COMBOORDER;
            $slave_bill->ReferenceId = $comboOrder->Id;
            if ($slave_bill->save() === false) {
                $exception->loadFromModel($slave_bill);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 退款，平台将套餐费返还给网点
     * @param ComboRefund $comboRefund
     * @throws LogicException
     * @throws ParamException
     */
    public static function refund(ComboRefund $comboRefund)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = FactoryDefault::getDefault()->get('session')->get('auth');
            /** @var Organization $peach */
            $peach = Organization::findFirst(Organization::PEACH);
            /** @var Organization $slave */
            $slave = Organization::findFirst(sprintf('Id=%d', $comboRefund->BuyerOrganizationId));
            if (!$peach || !$slave) {
                throw $exception;
            }

            $money = self::comboRefundMoney($comboRefund);

            // 扣除可用余额
            //平台账户将套餐费返还
            $peach->Money = new RawValue(sprintf('Money-%d', $money));
            $peach->Balance = new RawValue(sprintf('Balance-%d', $money));
            if ($peach->save() === false) {
                $exception->loadFromModel($peach);
                throw $exception;
            }
            // 余额不足回滚,返回false
            $peach->refresh();
            if ($peach->Money < 0 || $peach->Balance < 0) {
                throw new LogicException('请致电桃子互联网医院', Status::BadRequest);
            }
            $peach_bill = new Bill();
            $peach_bill->Title = sprintf(BillTitle::ComboRefund_Peach_ComboOrderBatch_Out, $comboRefund->OrderNumber, Alipay::fen2yuan($money));
            $peach_bill->OrganizationId = $peach->Id;
            $peach_bill->Fee = Bill::outCome($money);
            $peach_bill->Balance = $peach->Balance;
            $peach_bill->UserId = $auth['Id'];
            $peach_bill->Type = Bill::TYPE_PAYMENT;
            $peach_bill->Created = time();
            $peach_bill->ReferenceType = Bill::REFERENCE_TYPE_COMBO_REFUND;
            $peach_bill->ReferenceId = $comboRefund->Id;
            if ($peach_bill->save() === false) {
                $exception->loadFromModel($peach_bill);
                throw $exception;
            }
            //套餐费退回
            $slave->Money = new RawValue(sprintf('Money+%d', $money));
            $slave->Balance = new RawValue(sprintf('Balance+%d', $money));
            if ($slave->save() === false) {
                $exception->loadFromModel($slave);
                throw $exception;
            }
            $slave->refresh();
            $slave_bill = new Bill();
            $slave_bill->Title = sprintf(BillTitle::ComboOrderBatch_Slave_In, $comboRefund->ComboName, $comboRefund->OrderNumber, Alipay::fen2yuan($money));
            $slave_bill->OrganizationId = $slave->Id;
            $slave_bill->Fee = Bill::inCome($money);
            $slave_bill->Balance = $slave->Balance;
            $slave_bill->UserId = $auth['Id'];
            $slave_bill->Type = Bill::TYPE_PROFIT;
            $slave_bill->Created = time();
            $slave_bill->ReferenceType = Bill::REFERENCE_TYPE_COMBO_REFUND;
            $slave_bill->ReferenceId = $comboRefund->Id;
            if ($slave_bill->save() === false) {
                $exception->loadFromModel($slave_bill);
                throw $exception;
            }

            //将套餐的库存数量回滚
            /** @var Combo $combo */
            $combo = Combo::findFirst($comboRefund->ComboId);
            if ($combo) Order::comboStockChange($combo, $comboRefund->Quantity, true);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 大订单的总金额
     * @param ComboOrderBatch $comboOrderBatch
     * @return int
     */
    public static function comboOrderBatchMoney(ComboOrderBatch $comboOrderBatch)
    {
        return $comboOrderBatch->QuantityBuy * $comboOrderBatch->Price;
    }

    /**
     * 小订单的总金额
     * @param ComboOrder $comboOrder
     * @return int
     */
    public static function comboOrderMoney(ComboOrder $comboOrder)
    {
        $combos = ComboAndOrder::find([
            "conditions" => "ComboOrderId=?0",
            "bind"       => [$comboOrder->Id],
        ]);
        $money = 0;
        foreach ($combos as $combo) {
            $money += (int)$combo->Price;
        }
        return $money;
    }

    public static function comboRefundMoney(ComboRefund $comboRefund)
    {
        return $comboRefund->Quantity * $comboRefund->Price;
    }
}