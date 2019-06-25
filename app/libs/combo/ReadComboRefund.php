<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/3/15
 * Time: 11:14 AM
 */

namespace App\Libs\combo;


use App\Models\ComboAndOrder;
use App\Models\ComboOrder;
use App\Models\ComboOrderBatch;
use App\Models\ComboRefund;
use App\Models\ComboRefundLog;
use App\Models\Organization;
use Phalcon\Di\FactoryDefault;

class ReadComboRefund
{
    /** @var  ComboRefund */
    private $comboRefund;
    /** @var  array */
    private $result = [];
    private $auth;

    public function __construct(ComboRefund $comboRefund)
    {
        $this->comboRefund = $comboRefund;
        $this->auth = FactoryDefault::getDefault()->get('session')->get('auth');
    }

    public function show()
    {
        if ($this->auth['HospitalId'] == $this->auth['OrganizationId']) {
            $this->forHospital();
        } else {
            $this->forSlave();
        }
        return $this->result;
    }

    public function forHospital()
    {
        $this->base();
        $this->log();
        if ($this->comboRefund->ReferenceType == ComboRefund::ReferenceType_Slave) {
            /** @var ComboOrderBatch $comboBatch */
            $comboBatch = ComboOrderBatch::findFirst(sprintf('Id=%d', $this->comboRefund->ReferenceId));
            $read = new ReadComboOrderBatch($comboBatch);
            $info = $read->refundUse();
        } else {
            /** @var ComboOrder $comboOrder */
            $comboOrder = ComboOrder::findFirst(sprintf('Id=%d', $this->comboRefund->ReferenceId));
            $read = new ReadComboOrder($comboOrder);
            $info = $read->refundUse();
        }
        switch ($this->result['Status']) {
            case 1:
                $this->result['Status'] = 6;
                break;
            case 2:
                $this->result['Status'] = 5;
                break;
        }
        $this->result = array_merge($this->result, [
            'SendHospitalId'          => $info['SendHospitalId'],
            'SendOrganizationId'      => $info['SendOrganizationId'],
            'SendOrganizationName'    => $info['SendOrganizationName'],
            'HospitalId'              => $info['HospitalId'],
            'HospitalName'            => $info['HospitalName'],
            'PatientName'             => $info['PatientName'],
            'PatientAge'              => $info['PatientAge'],
            'PatientSex'              => $info['PatientSex'],
            'PatientAddress'          => $info['PatientAddress'],
            'PatientId'               => $info['PatientId'],
            'PatientTel'              => $info['PatientTel'],
            'Genre'                   => $info['Genre'],
            'Explain'                 => $info['Explain'],
            'Message'                 => $info['Message'],
            'SlaveShare'              => $info['SlaveShare'],
            'CloudShare'              => $info['CloudShare'],
            'HospitalShare'           => $info['HospitalShare'],
            'SendOrganizationTel'     => $info['SendOrganizationTel'],
            'SendOrganizationContact' => $info['SendOrganizationContact'],
            'Salesman'                => $info['Salesman'],
            'SalesmanPhone'           => $info['SalesmanPhone'],
            'InvoicePrice'            => $info['InvoicePrice'],
        ]);
    }

    public function forSlave()
    {
        $this->base();
        $this->hospitalInfo();
        $this->slaveOtherInfo();
        $this->log();
    }

    public function base()
    {
        $this->result = [
            'Id'             => $this->comboRefund->Id,
            'Name'           => $this->comboRefund->ComboName,
            'OrderNumber'    => (string)$this->comboRefund->OrderNumber,
            'Created'        => $this->comboRefund->Created,
            'FinishTime'     => $this->comboRefund->FinishTime,
            'Quantity'       => $this->comboRefund->Quantity,
            'QuantityRefund' => $this->comboRefund->Quantity,
            'ReferenceType'  => $this->comboRefund->ReferenceType,
            'ReferenceId'    => $this->comboRefund->ReferenceId,
            'Price'          => $this->comboRefund->Price,
            'ApplyReason'    => $this->comboRefund->ApplyReason,
            'RefuseReason'   => $this->comboRefund->RefuseReason,
            'Status'         => $this->comboRefund->Status,
            'Image'          => $this->comboRefund->Image,
            'StatusName'     => ComboRefund::STATUS_NAME[$this->comboRefund->Status],
            'Refund'         => [
                'Created'     => $this->comboRefund->Created,
                'ApplyReason' => $this->comboRefund->ApplyReason,
                'Quantity'    => $this->comboRefund->Quantity,
                'Price'       => $this->comboRefund->Price,
                'TotalPrice'  => $this->comboRefund->Price * $this->comboRefund->Quantity,
            ],
        ];
    }

    public function hospitalInfo()
    {
        /** @var Organization $hospital */
        $hospital = Organization::findFirst(sprintf('Id=%d', $this->comboRefund->SellerOrganizationId));
        $this->result = array_merge($this->result, [
            'HospitalName' => $hospital->Name,
            'HospitalTel'  => $hospital->Tel,
        ]);
    }

    public function log()
    {
        $logs = ComboRefundLog::find([
            'conditions' => 'ComboRefundId=?0',
            'bind'       => [$this->comboRefund->Id],
        ])->toArray();
        if (count($logs)) {
            $statusName = [1 => '发起退款申请', 2 => '退款成功', 3 => '拒绝退款'];
            foreach ($logs as &$log) {
                $log['StatusName'] = $statusName[$log['Status']];
            }
        }
        $this->result['Logs'] = $logs;
    }

    public function slaveOtherInfo()
    {
        if ($this->comboRefund->ReferenceType == ComboRefund::ReferenceType_Patient) {
            /** @var ComboOrder $comboOrder */
            $comboOrder = ComboOrder::findFirst(sprintf('Id=%d', $this->comboRefund->ReferenceId));
            /** @var ComboAndOrder $comboAndOrder */
            $comboAndOrder = ComboAndOrder::findFirst(sprintf('ComboOrderId=%d', $comboOrder->Id));
            /** @var ComboOrderBatch $comboOrderBatch */
            $comboOrderBatch = ComboOrderBatch::findFirst(sprintf('Id=%d', $comboAndOrder->ComboOrderBatchId));
            $this->result = array_merge($this->result, [
                'PatientName' => $comboOrder->PatientName,
                'PatientTel'  => $comboOrder->PatientTel,
                'Message'     => $comboOrder->Message,
            ]);
        } else {
            /** @var ComboOrderBatch $comboOrderBatch */
            $comboOrderBatch = ComboOrderBatch::findFirst(sprintf('Id=%d', $this->comboRefund->ReferenceId));
        }
        $this->result = array_merge($this->result, [
            'Way'          => $comboOrderBatch->Way,
            'Amount'       => $comboOrderBatch->Amount,
            'InvoicePrice' => $comboOrderBatch->InvoicePrice,
            'CreateTime'   => $comboOrderBatch->CreateTime,
            'PayTime'      => $comboOrderBatch->PayTime,
            'FinishTime'   => $comboOrderBatch->FinishTime,
        ]);
        switch ($this->comboRefund->Status) {
            case ComboRefund::STATUS_PASS:
                /** @var ComboRefundLog $log */
                $log = ComboRefundLog::findFirst([
                    'conditions' => 'ComboRefundId=?0 and Status=?1',
                    'bind'       => [$this->comboRefund->Id, ComboRefund::STATUS_PASS],
                ]);
                if ($log) {
                    $this->result = array_merge($this->result, [
                        'RefundedTime' => $log->LogTime,
                    ]);
                }
                break;
            case ComboRefund::STATUS_UNPASS:
                /** @var ComboRefundLog $log */
                $log = ComboRefundLog::findFirst([
                    'conditions' => 'ComboRefundId=?0 and Status=?1',
                    'bind'       => [$this->comboRefund->Id, ComboRefund::STATUS_UNPASS],
                ]);
                if ($log) {
                    $this->result = array_merge($this->result, [
                        'RefusedTime' => $log->LogTime,
                    ]);
                }
                break;

        }

    }
}