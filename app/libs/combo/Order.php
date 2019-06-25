<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/3/13
 * Time: 12:28 PM
 */

namespace App\Libs\combo;


use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Sms;
use App\Models\Combo;
use App\Models\ComboAndOrder;
use App\Models\ComboOrder;
use App\Models\ComboOrderBatch;
use App\Models\ComboRefund;
use App\Models\Organization;
use Phalcon\Db\RawValue;
use Phalcon\Di\FactoryDefault;

class Order
{
    public $auth;

    public function __construct()
    {
        $this->auth = FactoryDefault::getDefault()->get('session')->get('auth');
    }

    /**
     * @param Combo $combo
     * @param int $quantityBuy
     * @return ComboOrderBatch
     * @throws ParamException
     */
    public function createComboOrderBatch(Combo $combo, int $quantityBuy)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            /** @var ComboOrderBatch $comboOrderBatch */
            $comboOrderBatch = new ComboOrderBatch();
            $comboOrderBatch->ComboId = $combo->Id;
            $comboOrderBatch->HospitalId = $this->auth['HospitalId'];
            $comboOrderBatch->OrganizationId = $this->auth['OrganizationId'];
            $comboOrderBatch->OrganizationName = $this->auth['OrganizationName'];
            $comboOrderBatch->Name = $combo->Name;
            $comboOrderBatch->Price = $combo->Price;
            $comboOrderBatch->Way = $combo->Way;
            $comboOrderBatch->Genre = $combo->OrganizationId == $this->auth['HospitalId'] ? ComboOrder::GENRE_SELF : ComboOrder::GENRE_SHARE;
            $comboOrderBatch->MoneyBack = $combo->MoneyBack;
            $comboOrderBatch->Amount = $combo->Amount;
            $comboOrderBatch->InvoicePrice = $combo->InvoicePrice ?: 0;
            $comboOrderBatch->QuantityBuy = $quantityBuy;
            $comboOrderBatch->QuantityUnAllot = $quantityBuy;
            $comboOrderBatch->QuantityBack = 0;
            $comboOrderBatch->QuantityApply = 0;

            $comboOrderBatch->Image = $combo->Image ? reset(explode(',', $combo->Image)) : '';

            if (!$comboOrderBatch->save()) {
                $exception->loadFromModel($comboOrderBatch);
                throw $exception;
            }

            //减少库存
            self::comboStockChange($combo, $comboOrderBatch->QuantityBuy, false);

            return $comboOrderBatch;
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 生成子订单
     * @param Combo $combo
     * @param ComboOrderBatch $comboOrderBatch
     * @param $patientInfo
     * @return ComboOrder
     * @throws ParamException
     * @throws LogicException
     */
    public function createComboOrder(Combo $combo, ComboOrderBatch $comboOrderBatch, int $status, $patientInfo)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            /** @var Organization $hospital */
            $hospital = Organization::findFirst($combo->OrganizationId);
            if (!$hospital) throw $exception;

            //大订单可分配数为0的时候不能再分配
            if ($comboOrderBatch->QuantityUnAllot == 0) {
                throw new LogicException('无可分配的套餐', Status::BadRequest);
            }

            //验证患者信息
            $verify = new Verify($patientInfo);
            $verify->patientInfo();

            /** @var  $comboOrder */
            $comboOrder = new ComboOrder();
            $comboOrder->HospitalId = $hospital->Id;
            $comboOrder->HospitalName = $hospital->Name;
            $comboOrder->Genre = $comboOrderBatch->Genre;
            $comboOrder->PatientName = isset($patientInfo['PatientName']) ? $patientInfo['PatientName'] : '';
            $comboOrder->PatientAge = isset($patientInfo['PatientAge']) ? $patientInfo['PatientAge'] : null;
            $comboOrder->PatientSex = isset($patientInfo['PatientSex']) ? $patientInfo['PatientSex'] : null;
            $comboOrder->PatientAddress = isset($patientInfo['PatientAddress']) ? $patientInfo['PatientAddress'] : '';
            $comboOrder->PatientId = isset($patientInfo['PatientId']) ? $patientInfo['PatientId'] : null;
            $comboOrder->PatientTel = isset($patientInfo['PatientTel']) ? $patientInfo['PatientTel'] : null;
            $comboOrder->Message = isset($patientInfo['Message']) ? $patientInfo['Message'] : '';
            $comboOrder->Status = $status;

            if (!$comboOrder->save()) {
                $exception->loadFromModel($comboOrder);
                throw $exception;
            }

            //记录日志
            if ($comboOrder->Status == ComboOrder::STATUS_PAYED) {
                $comboOrder->log();
            }

            /** @var ComboAndOrder $comboAndOrder */
            $comboAndOrder = new ComboAndOrder();
            $comboAndOrder->ComboOrderId = $comboOrder->Id;
            $comboAndOrder->ComboId = $combo->Id;
            $comboAndOrder->ComboOrderBatchId = $comboOrderBatch->Id;
            $comboAndOrder->Name = $comboOrderBatch->Name;
            $comboAndOrder->Price = $comboOrderBatch->Price;
            $comboAndOrder->Way = $comboOrderBatch->Way == Combo::WAY_RATIO ? Combo::WAY_RATIO : Combo::WAY_FIXED;
            $comboAndOrder->Amount = $comboOrderBatch->Way == Combo::WAY_NOTHING ? 0 : $comboOrderBatch->Amount;
            $comboAndOrder->Image = $comboOrderBatch->Image;
            if (!$comboAndOrder->save()) {
                $exception->loadFromModel($comboAndOrder);
                throw $exception;
            }

            //可分配数量减1
            $comboOrderBatch->QuantityUnAllot -= 1;

            //如果待分配为0
            if ($comboOrder->Status == ComboOrder::STATUS_PAYED && $comboOrderBatch->QuantityUnAllot == 0) {
                $comboOrderBatch->FinishTime = time();
                $comboOrderBatch->Status = ComboOrderBatch::STATUS_USED;
            }

            if (!$comboOrderBatch->save()) {
                $exception->loadFromModel($comboOrderBatch);
                throw $exception;
            }

            return $comboOrder;
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 套餐库存的变化
     * @param Combo $combo
     * @param int $quantityBuy
     * @param bool $isAddStock
     * @throws ParamException
     */
    public static function comboStockChange(Combo $combo, int $quantityBuy, bool $isAddStock)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            //减少库存
            if ($combo->Stock !== null) {
                $combo->Stock = new RawValue(sprintf($isAddStock ? 'Stock+%d' : 'Stock-%d', $quantityBuy));;
                if (!$combo->save()) {
                    $exception->loadFromModel($combo);
                    throw $exception;
                }
                $combo->refresh();
            }
        } catch (ParamException $e) {
            throw $e;
        }
    }


    /**
     * 支付成功后改变小订单状态 或者取消(超时)之后 改变小订单状态
     * 注意：ComboOrderBatch 和 ComboOrder 两个表状态必须对应
     * @param ComboOrderBatch $comboOrderBatch
     */
    public static function payedChangeComboOrderStatus(ComboOrderBatch $comboOrderBatch)
    {
        $comboAndOrders = ComboAndOrder::find([
            'conditions' => 'ComboOrderBatchId=?0',
            'bind'       => [$comboOrderBatch->Id],
        ])->toArray();
        $comboOrders = ComboOrder::query()->inWhere('Id', array_column($comboAndOrders, 'ComboOrderId'))->execute();
        if (count($comboOrders->toArray())) {
            foreach ($comboOrders as $comboOrder) {
                /** @var ComboOrder $comboOrder */
                $comboOrder->Status = $comboOrderBatch->Status;
                $comboOrder->save();
                if ($comboOrder->Status == ComboOrder::STATUS_PAYED) {
                    $comboOrder->log();
                    //发送消息给患者
                    $content = MessageTemplate::load(
                        'combo_to_patient',
                        MessageTemplate::METHOD_SMS,
                        $comboOrder->SendOrganizationName,
                        $comboOrder->HospitalName,
                        $comboOrderBatch->Name,
                        $comboOrder->OrderNumber
                    );
                    $sms = new Sms(FactoryDefault::getDefault()->get('queue'));
                    $sms->sendMessage((string)$comboOrder->PatientTel, $content);
                }
            }
        }
    }

    /**
     * 创建退款订单
     * @throws ParamException
     */
    public function createRefundOrder(array $data)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!in_array($data['ReferenceType'], [ComboRefund::ReferenceType_Slave, ComboRefund::ReferenceType_Patient])) {
                throw new LogicException('类型错误', Status::BadRequest);
            }
            if ($data['ReferenceType'] == ComboRefund::ReferenceType_Slave) {
                /** @var ComboOrderBatch $comboOrderBatch */
                $comboOrderBatch = ComboOrderBatch::findFirst(sprintf('Id=%d', $data['ReferenceId']));
                $info = $comboOrderBatch->toArray();
                //验证数量
                if ($comboOrderBatch->QuantityUnAllot == 0 || $comboOrderBatch->QuantityUnAllot < $data['Quantity']) {
                    throw new LogicException('退款数量超过可分配数量', Status::BadRequest);
                }
                //验证状态
                if ($comboOrderBatch->Status != ComboOrderBatch::STATUS_WAIT_ALLOT) {
                    throw new LogicException('不能退款', Status::BadRequest);
                }
                $info['Quantity'] = $data['Quantity'];
                $info['OrderNumber'] = Rule::orderNumber($comboOrderBatch->OrganizationId);

                //将可分配数量减少
                $comboOrderBatch->QuantityUnAllot = new RawValue(sprintf('QuantityUnAllot-%d', $info['Quantity']));
                $comboOrderBatch->QuantityApply = new RawValue(sprintf('QuantityApply+%d', $info['Quantity']));
                if (!$comboOrderBatch->save()) {
                    $exception->loadFromModel($comboOrderBatch);
                    throw $exception;
                }

            } else {
                /** @var ComboOrder $comboOrder */
                $comboOrder = ComboOrder::findFirst(sprintf('Id=%d', $data['ReferenceId']));
                /** @var ComboAndOrder $comboAndOrder */
                $comboAndOrder = ComboAndOrder::findFirst([
                    'conditions' => 'ComboOrderId=?0',
                    'bind'       => $comboOrder->Id,
                ]);
                /** @var ComboOrderBatch $comboOrderBatch */
                $comboOrderBatch = ComboOrderBatch::findFirst(sprintf('Id=%d', $comboAndOrder->ComboOrderBatchId));
                $info = [
                    'Id'             => $comboOrder->Id,
                    'OrderNumber'    => $comboOrder->OrderNumber,
                    'Name'           => $comboAndOrder->Name,
                    'ComboId'        => $comboAndOrder->ComboId,
                    'HospitalId'     => $comboOrder->HospitalId,
                    'OrganizationId' => $comboOrder->SendOrganizationId,
                    'Price'          => $comboAndOrder->Price,
                    'Quantity'       => 1,
                ];

                //改变小订单状态
                $comboOrder->Status = ComboOrder::STATUS_BACK_MONEY_ING;
                if (!$comboOrder->save()) {
                    $exception->loadFromModel($comboOrder);
                    throw $exception;
                }

                //记录日志
                $comboOrder->log($data['ApplyReason']);
            }
            if ($this->auth['OrganizationId'] != $info['OrganizationId']) {
                throw new LogicException('错误', Status::BadRequest);
            }
            //验证是否可以退款
            if ($comboOrderBatch->MoneyBack == ComboOrderBatch::MoneyBack_No) {
                throw new LogicException('此套餐单不支持退款', Status::BadRequest);
            }

            /** @var Combo $combo */
            $combo = Combo::findFirst(sprintf('Id=%d', $info['ComboId']));

            $refund = new ComboRefund();
            $refund->OrderNumber = $info['OrderNumber'];
            $refund->ComboId = $combo->Id;
            $refund->ComboName = $info['Name'];
            $refund->ReferenceType = $data['ReferenceType'];
            $refund->ReferenceId = $info['Id'];
            $refund->SellerOrganizationId = $combo->OrganizationId;
            $refund->BuyerOrganizationId = $info['OrganizationId'];
            $refund->Quantity = $info['Quantity'];
            $refund->Price = $info['Price'];
            $refund->ApplyReason = $data['ApplyReason'];
            $refund->Image = $comboOrderBatch->Image;
            if (!$refund->save()) {
                $exception->loadFromModel($refund);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        }
    }


    /**
     * 处理超时
     */
    public static function comboOrderBatchTimeout(ComboOrderBatch $comboOrderBatch, int $now)
    {
        //超时时间是60分钟
        $long = 60 * 60;
        if ($comboOrderBatch->Status === ComboOrderBatch::STATUS_WAIT_PAY) {
            if (($comboOrderBatch->CreateTime + $long) < $now) {
                //关闭大订单
                $comboOrderBatch->Status = ComboOrderBatch::STATUS_CLOSED;
                $comboOrderBatch->save();
                $comboOrderBatch->refresh();
                //关闭相应的小订单
                self::payedChangeComboOrderStatus($comboOrderBatch);
                //回滚套餐数量
                /** @var Combo $combo */
                $combo = Combo::findFirst(sprintf('Id=%d', $comboOrderBatch->ComboId));
                if ($combo->Stock !== null) {
                    $combo->Stock = new RawValue(sprintf('Stock+%d', $comboOrderBatch->QuantityBuy));
                    $combo->save();
                    $combo->refresh();
                }
            }
        }
    }

    /**
     * 批量处理超时
     */
    public function comboOrderBatchTimeoutBatch()
    {
        $comboOrderBatch = ComboOrderBatch::find([
            'conditions' => 'OrganizationId=?0 and Status=?1',
            'bind'       => [$this->auth['OrganizationId'], ComboOrderBatch::STATUS_WAIT_PAY],
        ]);
        if (count($comboOrderBatch->toArray())) {
            $now = time();
            foreach ($comboOrderBatch as $batch) {
                self::comboOrderBatchTimeout($batch, $now);
            }
        }
    }


    public static function changeStatus(ComboRefund $comboRefund)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($comboRefund->ReferenceType == ComboRefund::ReferenceType_Slave) {
                /** @var ComboOrderBatch $comboOrderBatch */
                $comboOrderBatch = ComboOrderBatch::findFirst(sprintf('Id=%d', $comboRefund->ReferenceId));

                if ($comboRefund->Status == ComboRefund::STATUS_PASS) {
                    if ($comboOrderBatch->QuantityUnAllot == 0) {
                        $comboOrderBatch->FinishTime = time();
                        $comboOrderBatch->Status = ComboOrderBatch::STATUS_USED;
                    }
                    $comboOrderBatch->QuantityBack += $comboRefund->Quantity;
                } else {
                    //将可分配数量减少
                    $comboOrderBatch->QuantityUnAllot += $comboRefund->Quantity;
                }
                $comboOrderBatch->QuantityApply -= $comboRefund->Quantity;

                if (!$comboOrderBatch->save()) {
                    $exception->loadFromModel($comboOrderBatch);
                    throw $exception;
                }

            } else {
                /** @var ComboOrder $comboOrder */
                $comboOrder = ComboOrder::findFirst(sprintf('Id=%d', $comboRefund->ReferenceId));
                $comboOrder->Status = $comboRefund->Status == ComboRefund::STATUS_PASS ? ComboOrder::STATUS_BACK_MONEY_END : ComboOrder::STATUS_PAYED;

                if (!$comboOrder->save()) {
                    $exception->loadFromModel($comboOrder);
                    throw $exception;
                }

                //记录日志
                if ($comboOrder->Status == ComboOrder::STATUS_PAYED) {
                    $comboOrder->log($comboRefund->RefuseReason);
                }
            }
        } catch (ParamException $e) {
            throw $e;
        }
    }
}