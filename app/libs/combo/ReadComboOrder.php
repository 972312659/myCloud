<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/3/15
 * Time: 11:13 AM
 */

namespace App\Libs\combo;


use App\Models\Combo;
use App\Models\ComboAndOrder;
use App\Models\ComboOrder;
use App\Models\ComboOrderBatch;
use App\Models\ComboOrderLog;
use App\Models\ComboRefund;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\RuleOfShare;
use App\Models\User;
use Phalcon\Di\FactoryDefault;

class ReadComboOrder
{
    /** @var  ComboOrder */
    private $comboOrder;

    /** @var  array */
    private $result;

    /** @var array */
    private $auth;

    public function __construct(ComboOrder $comboOrder)
    {
        $this->comboOrder = $comboOrder;
        $this->auth = FactoryDefault::getDefault()->get('session')->get('auth');
    }

    public function show()
    {
        if ($this->auth['HospitalId'] == $this->auth['OrganizationId']) {
            self::forHospital();
        } else {
            self::forSlave();
        }
        return $this->result;
    }

    public function refundUse()
    {
        $this->base();
        $this->comboAndOrder();
        $this->slaveInfo();
        $this->slaveCommission();
        $this->otherCommission();
        $this->salesman();
        return $this->result;
    }

    public function forHospital()
    {
        $this->base();
        $this->comboAndOrder();
        $this->slaveInfo();
        $this->slaveCommission();
        $this->otherCommission();
        $this->salesman();
        $this->log();
        $this->refund();
    }

    public function forSlave()
    {
        $this->base();
        $this->comboAndOrder();
        $this->slaveCommission();
        $this->log();
    }

    public function base()
    {
        $this->result = [
            'Id'                   => $this->comboOrder->Id,
            'OrderNumber'          => (string)$this->comboOrder->OrderNumber,
            'SendHospitalId'       => $this->comboOrder->SendHospitalId,
            'SendOrganizationId'   => $this->comboOrder->SendOrganizationId,
            'SendOrganizationName' => $this->comboOrder->SendOrganizationName,
            'HospitalId'           => $this->comboOrder->HospitalId,
            'HospitalName'         => $this->comboOrder->HospitalName,
            'PatientName'          => $this->comboOrder->PatientName,
            'PatientAge'           => $this->comboOrder->PatientAge,
            'PatientSex'           => $this->comboOrder->PatientSex,
            'PatientAddress'       => $this->comboOrder->PatientAddress,
            'PatientId'            => $this->comboOrder->PatientId,
            'PatientTel'           => $this->comboOrder->PatientTel,
            'Created'              => $this->comboOrder->Created,
            'Status'               => $this->comboOrder->Status,
            'Genre'                => $this->comboOrder->Genre,
            'Explain'              => $this->comboOrder->Explain,
            'Message'              => $this->comboOrder->Message,
            'StatusName'           => ComboOrder::STATUS_NAME[$this->comboOrder->Status],
        ];
    }

    public function comboAndOrder()
    {
        /** @var ComboAndOrder $comboAndOrder */
        $comboAndOrder = ComboAndOrder::findFirst(sprintf('ComboOrderId=%d', $this->comboOrder->Id));
        /** @var ComboOrderBatch $comboOrderBatch */
        $comboOrderBatch = ComboOrderBatch::findFirst(sprintf('Id=%d', $comboAndOrder->ComboOrderBatchId));

        $this->result = array_merge($this->result, [
            'Name'         => $comboAndOrder->Name,
            'Price'        => $comboAndOrder->Price,
            'Way'          => $comboAndOrder->Way,
            'Amount'       => $comboAndOrder->Amount,
            'InvoicePrice' => $comboOrderBatch->InvoicePrice,
            'Image'        => $comboAndOrder->Image,
            'CreateTime'   => $comboOrderBatch->CreateTime,
            'PayTime'      => $comboOrderBatch->PayTime,
        ]);
    }

    public function slaveCommission()
    {
        $this->result['SlaveShare'] = (int)($this->result['Way'] == Combo::WAY_FIXED ? $this->result['Amount'] : $this->result['Price'] * $this->result['Amount'] / 100);

    }

    public function otherCommission()
    {
        if ($this->comboOrder->Genre == ComboOrder::GENRE_SHARE) {
            /** @var Organization $hospital */
            $hospital = Organization::findFirst(sprintf('Id=%d', $this->auth['OrganizationId']));
            /** @var RuleOfShare $rule */
            $rule = RuleOfShare::findFirst(sprintf('Id=%d', $hospital->RuleId));
            $this->result['CloudShare'] = (int)$this->result['Price'] * $rule->Ratio / 100;

            $supplier = OrganizationRelationship::findFirst([
                "MainId=:MainId: and MinorId=:MinorId:",
                'bind' => ["MainId" => $this->comboOrder->SendHospitalId, "MinorId" => $this->comboOrder->HospitalId],
            ]);
            if ($supplier) {
                //供应商
                $rule = RuleOfShare::findFirst([
                    'conditions' => 'CreateOrganizationId=?0 and OrganizationId=?1',
                    'bind'       => [$this->comboOrder->SendHospitalId, $this->comboOrder->HospitalId],
                ]);
            }
            $this->result['HospitalShare'] = (int)($this->result['Price'] * $rule->DistributionOut / 100);
        }
    }

    public function slaveInfo()
    {
        /** @var OrganizationUser $slave */
        $slave = OrganizationUser::findFirst(sprintf('OrganizationId=%d', $this->comboOrder->SendOrganizationId));
        /** @var User $user */
        $user = User::findFirst(sprintf('Id=%d', $slave->UserId));
        $this->result['SendOrganizationTel'] = $user->Phone;
        $this->result['SendOrganizationContact'] = $user->Name;
    }

    public function salesman()
    {
        $this->result['Salesman'] = '';
        /** @var OrganizationRelationship $organizationRelation */
        $organizationRelation = OrganizationRelationship::findFirst([
            'conditions' => 'MainId=?0 and MinorId=?1',
            'bind'       => [$this->comboOrder->SendHospitalId, $this->comboOrder->SendOrganizationId],
        ]);
        if ($organizationRelation) {
            /** @var User $user */
            $user = User::findFirst(sprintf('Id=%d', $organizationRelation->SalesmanId));
            $this->result['Salesman'] = $user->Name;
            $this->result['SalesmanPhone'] = $user->Phone;
        }
    }

    public function log()
    {
        $logs = ComboOrderLog::find([
            'conditions' => 'ComboOrderId=?0',
            'bind'       => [$this->comboOrder->Id],
        ]);
        if ($this->auth['HospitalId'] == $this->auth['OrganizationId']) {
            $statusName = [2 => '创建套餐单', 3 => '已使用', 5 => '已退款', 6 => '申请退款', 100 => '拒绝退款'];
        } else {
            $statusName = [2 => '分配时间', 3 => '已使用', 5 => '退款时间', 6 => '申请退款时间', 100 => '拒绝时间'];
        }
        //控台
        if (!isset($this->auth['HospitalId'])) {
            $statusName = [2 => '创建套餐单', 3 => '已使用', 5 => '已退款', 6 => '申请退款', 100 => '拒绝退款'];
        }
        $result = [];
        if (count($logs->toArray())) {
            $statusTwoRepeat = false;
            foreach ($logs as $log) {
                /** @var ComboOrderLog $log */
                if (!in_array($log->Status, [2, 3, 5, 6])) {
                    continue;
                }
                if (!$statusTwoRepeat && $log->Status == 2) {
                    $this->result['AllotTime'] = $log->LogTime;
                }
                $status = $statusName[$log->Status];
                //不要分配时间的日志
                if ($log->Status == ComboOrder::STATUS_PAYED && $statusTwoRepeat) {
                    $status = $statusName[100];
                }
                if (isset($this->auth['HospitalId']) && isset($this->auth['OrganizationId']) && $this->auth['OrganizationId'] != $this->auth['HospitalId']) {
                    if ($log->Status == ComboOrder::STATUS_PAYED && !$statusTwoRepeat) {
                        $statusTwoRepeat = true;
                        continue;
                    }
                } else {
                    if ($log->Status == ComboOrder::STATUS_PAYED && !$statusTwoRepeat) {
                        $statusTwoRepeat = true;
                    }
                }

                $result[] = [
                    'Status'     => $log->Status,
                    'StatusName' => $status,
                    'UserName'   => $log->UserName,
                    'LogTime'    => $log->LogTime,
                    'Content'    => $log->Content,
                ];
            }
        }
        $this->result['Logs'] = $result;
    }

    public function refund()
    {
        /** @var ComboRefund $refund */
        $refund = ComboRefund::findFirst([
            'ReferenceType=?0 and ReferenceId=?1 and Status=?2',
            'bind'  => [ComboRefund::ReferenceType_Patient, $this->comboOrder->Id, ComboRefund::STATUS_WAIT],
            'order' => 'Created desc',
        ]);
        if ($refund) {
            $this->result['Refund'] = [
                'Created'     => $refund->Created,
                'ApplyReason' => $refund->ApplyReason,
                'Quantity'    => $refund->Quantity,
                'Price'       => $refund->Price,
            ];
        }
    }

    public function consoleShow()
    {
        $this->base();
        $this->comboAndOrder();
        $this->slaveInfo();
        $this->slaveCommission();
        $this->otherCommission();
        $this->salesman();
        $this->refund();
        $this->log();
        return $this->result;
    }
}