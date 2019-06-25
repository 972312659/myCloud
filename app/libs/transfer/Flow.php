<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/21
 * Time: 下午5:24
 */

namespace App\Libs\transfer;

use App\Models\Organization;
use App\Models\Transfer;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\TransferFlow;
use App\Models\TransferLog;
use Phalcon\Di\FactoryDefault;

class Flow
{
    /**
     * @var Transfer 上一个转诊
     */
    protected $parentTransfer;
    /**
     * 转诊单总金额
     */
    public $parentTransferCost = 0;
    /**
     * 流转科室id
     */
    public $flowAcceptSectionId;
    /**
     * 流转科室名字
     */
    public $flowAcceptSectionName;
    /**
     * 流转医生id
     */
    public $flowAcceptDoctorId;
    /**
     * 流转医生名字
     */
    public $flowAcceptDoctorName;
    /**
     * 覆盖范围
     */
    public $flowOutpatientOrInpatient;
    /**
     * 现在时间
     * @var int
     */
    protected $time;
    /**
     * 流转后的接诊备注
     */
    protected $remark;
    /**
     * 此流转单接诊备注
     */
    protected $clinicRemark;
    /**
     * 此流转单结算备注
     */
    protected $finishRemark;

    public function __construct(Transfer $parentTransfer)
    {
        $this->parentTransfer = $parentTransfer;
        $this->time = time();
    }

    public function remark(array $remark)
    {
        $this->clinicRemark = $this->parentTransfer->Remake;
        $this->finishRemark = $remark['FinishRemark'];
        if (isset($remark['ClinicRemark'])) {
            $this->remark = $remark['ClinicRemark'];
            $this->parentTransfer->Remake = $remark['ClinicRemark'];
        }
        if (!isset($remark['Remark'])) $this->parentTransfer->Explain = $remark['FinishRemark'];
    }

    public function create($flowSectionId, $flowDoctorId, $flowOutpatientOrInpatient)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            //验证数据
            $this->validation($flowSectionId, $flowDoctorId, $flowOutpatientOrInpatient);

            $transfer = new Transfer();
            $transfer->PatientName = $this->parentTransfer->PatientName;
            $transfer->PatientAge = $this->parentTransfer->PatientAge;
            $transfer->PatientSex = $this->parentTransfer->PatientSex;
            $transfer->PatientAddress = $this->parentTransfer->PatientAddress;
            $transfer->PatientId = $this->parentTransfer->PatientId;
            $transfer->PatientTel = $this->parentTransfer->PatientTel;
            $transfer->SendHospitalId = $this->parentTransfer->SendHospitalId;
            $transfer->SendOrganizationId = $this->parentTransfer->SendOrganizationId;
            $transfer->SendOrganizationName = $this->parentTransfer->SendOrganizationName;
            $transfer->TranStyle = $this->parentTransfer->TranStyle;
            $transfer->OldSectionName = $this->parentTransfer->AcceptSectionName;
            $transfer->OldDoctorName = $this->parentTransfer->AcceptDoctorName;
            $transfer->AcceptOrganizationId = $this->parentTransfer->AcceptOrganizationId;
            $transfer->AcceptSectionId = $this->flowAcceptSectionId;
            $transfer->AcceptDoctorId = $this->flowAcceptDoctorId;
            $transfer->AcceptSectionName = $this->flowAcceptSectionName;
            $transfer->AcceptDoctorName = $this->flowAcceptDoctorName;
            $transfer->Disease = '【' . $this->parentTransfer->AcceptSectionName . '】流转病人';
            $transfer->StartTime = $this->time;
            $transfer->ClinicTime = $this->time;
            $transfer->Status = Transfer::TREATMENT;
            $transfer->OrderNumber = time() << 32 | substr('0000000' . $this->parentTransfer->SendOrganizationId, -7, 7);;
            $transfer->Remake = $this->parentTransfer->PatientName;
            $transfer->Explain = $this->parentTransfer->PatientName;
            $transfer->Cost = $this->parentTransfer->PatientName;
            $transfer->Source = Transfer::SOURCE_NORMAL;
            $transfer->SendMessageToPatient = $this->parentTransfer->SendMessageToPatient;
            $transfer->OutpatientOrInpatient = $this->flowOutpatientOrInpatient;
            $transfer->Pid = $this->parentTransfer->Id;
            //分润
            $transfer->Cost = 0;
            $transfer->Genre = $this->parentTransfer->Genre;
            $transfer->GenreOne = $this->parentTransfer->GenreOne;
            $transfer->GenreTwo = $this->parentTransfer->GenreTwo;
            $transfer->CloudGenre = $this->parentTransfer->CloudGenre;
            $transfer->ShareOne = $this->parentTransfer->ShareOne;
            $transfer->ShareTwo = $this->parentTransfer->ShareTwo;
            $transfer->ShareCloud = $this->parentTransfer->ShareCloud;
            if (!$transfer->save()) {
                $exception->loadFromModel($transfer);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function validation($flowSectionId, $flowDoctorId, $flowOutpatientOrInpatient)
    {
        /** @var Organization $organization */
        $organization = Organization::findFirst(sprintf('Id=%d', $this->parentTransfer->AcceptOrganizationId));

        $validate = new Validate();
        //验证科室和医生
        $sectionAndDoctor = $validate->sectionAndDoctor($organization, $flowSectionId, $flowDoctorId);
        $this->flowAcceptSectionId = $sectionAndDoctor['SectionId'];
        $this->flowAcceptSectionName = $sectionAndDoctor['SectionName'];
        $this->flowAcceptDoctorId = $sectionAndDoctor['DoctorId'];
        $this->flowAcceptDoctorName = $sectionAndDoctor['DoctorName'];
        //验证覆盖范围(门诊还是住院)
        $flowOutpatientOrInpatient = $validate->outpatientOrInpatient($flowOutpatientOrInpatient);
        $this->flowOutpatientOrInpatient = $flowOutpatientOrInpatient;

    }

    public function createTransferFlowModel(array $data)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            /** @var TransferFlow $transferFlow */
            $transferFlow = new TransferFlow();
            $transferFlow->TransferId = $this->parentTransfer->Id;
            $transferFlow->SectionId = $this->parentTransfer->AcceptSectionId;
            $transferFlow->SectionName = $this->parentTransfer->AcceptSectionName;
            $transferFlow->DoctorId = $this->parentTransfer->AcceptDoctorId;
            $transferFlow->DoctorName = $this->parentTransfer->AcceptDoctorName;
            $transferFlow->OutpatientOrInpatient = $this->parentTransfer->OutpatientOrInpatient;
            $transferFlow->ClinicRemark = $this->clinicRemark;
            $transferFlow->FinishRemark = $this->finishRemark;

            $transferFlow->Created = $data['LeaveTime'];
            $transferFlow->GenreOne = $data['GenreOne'];
            $transferFlow->ShareOne = $data['ShareOne'];
            $transferFlow->CloudGenre = $data['CloudGenre'];
            $transferFlow->ShareCloud = $data['ShareCloud'];
            $transferFlow->Cost = $data['Cost'];
            $transferFlow->TherapiesExplain = $data['TherapiesExplain'];
            $transferFlow->ReportExplain = $data['ReportExplain'];
            $transferFlow->DiagnosisExplain = $data['DiagnosisExplain'];
            $transferFlow->FeeExplain = $data['FeeExplain'];
            $transferFlow->TherapiesExplainImages = !empty($data['TherapiesExplainImages']) ? $data['TherapiesExplainImages'] : null;
            $transferFlow->ReportExplainImages = !empty($data['ReportExplainImages']) ? $data['ReportExplainImages'] : null;
            $transferFlow->DiagnosisExplainImages = !empty($data['DiagnosisExplainImages']) ? $data['DiagnosisExplainImages'] : null;
            $transferFlow->FeeExplainImages = !empty($data['FeeExplainImages']) ? $data['FeeExplainImages'] : null;
            $transferFlow->CanModify = !TransferFlow::findFirst(['conditions' => 'TransferId=?0', 'bind' => [$this->parentTransfer->Id]]) && $this->parentTransfer->Source == Transfer::SOURCE_COMBO ? TransferFlow::CanModify_No : TransferFlow::CanModify_Yes;

            if (!$transferFlow->save()) {
                $exception->loadFromModel($transferFlow);
                throw $exception;
            }
            $transferFlow->refresh();
            return $transferFlow;
        } catch (ParamException $e) {
            throw $e;
        }
    }

    public function updateTransfer()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            //更新
            $this->parentTransfer->AcceptSectionId = $this->flowAcceptSectionId;
            $this->parentTransfer->AcceptSectionName = $this->flowAcceptSectionName;
            $this->parentTransfer->AcceptDoctorId = $this->flowAcceptDoctorId;
            $this->parentTransfer->AcceptDoctorName = $this->flowAcceptDoctorName;
            $this->parentTransfer->OutpatientOrInpatient = $this->flowOutpatientOrInpatient;
            $this->parentTransfer->TherapiesExplain = null;
            $this->parentTransfer->ReportExplain = null;
            $this->parentTransfer->DiagnosisExplain = null;
            $this->parentTransfer->FeeExplain = null;
            $this->parentTransfer->Explain = null;
            $this->parentTransfer->Cost = $this->parentTransferCost;
            if (!$this->parentTransfer->save()) {
                $exception->loadFromModel($this->parentTransfer);
                throw $exception;
            }

        } catch (ParamException $e) {
            throw $e;
        }
    }

    public function setParentTransferCost($parentTransferCost)
    {
        $this->parentTransferCost = $parentTransferCost;
    }

    public function parentTransferComputing($cost)
    {
        $computing = new TransferComputing();
        $result = $computing->computing($this->parentTransfer, $cost);
        return [
            'Cost'       => $cost,
            'CloudGenre' => $result['CloudGenre'],
            'ShareCloud' => $result['ShareCloud'],
            'GenreOne'   => $result['GenreOne'],
            'ShareOne'   => $result['ShareOne'],
        ];
    }

    public function transferLog()
    {
        $auth = FactoryDefault::getDefault()->get('session')->get('auth');
        TransferLog::addLog($auth['OrganizationId'], $auth['OrganizationName'], $auth['Id'], $auth['Name'], $this->parentTransfer->Id, (int)$this->parentTransfer->Status, $this->time);
    }

    public function updateTransferInfo($Info)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $validate = new Validate();
            $needValidate_SectionAndDoctor = false;
            $needValidate_OutpatientOrInpatient = false;
            if ($this->parentTransfer->AcceptSectionId != $Info['AcceptSectionId'] || $this->parentTransfer->AcceptDoctorId != $Info['AcceptDoctorId']) {
                $needValidate_SectionAndDoctor = true;
                /** @var Organization $organization */
                $organization = Organization::findFirst(sprintf('Id=%d', $this->parentTransfer->AcceptOrganizationId));
                $sectionAndDoctor = $validate->sectionAndDoctor($organization, $Info['AcceptSectionId'], $Info['AcceptDoctorId']);
                $this->parentTransfer->AcceptSectionId = $sectionAndDoctor['SectionId'];
                $this->parentTransfer->AcceptSectionName = $sectionAndDoctor['SectionName'];
                $this->parentTransfer->AcceptDoctorId = $sectionAndDoctor['DoctorId'];
                $this->parentTransfer->AcceptDoctorName = $sectionAndDoctor['DoctorName'];
            }
            if ($this->parentTransfer->OutpatientOrInpatient != $Info['OutpatientOrInpatient']) {
                $needValidate_OutpatientOrInpatient = true;
                $this->parentTransfer->OutpatientOrInpatient = $validate->outpatientOrInpatient($Info['OutpatientOrInpatient']);
            }
            if ($needValidate_SectionAndDoctor || $needValidate_OutpatientOrInpatient) {
                if (!$this->parentTransfer->save()) {
                    $exception->loadFromModel($this->parentTransfer);
                    throw $exception;
                }
            }
        } catch (ParamException $e) {
            throw $e;
        }
    }
}