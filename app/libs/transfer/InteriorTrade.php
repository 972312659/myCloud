<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/1/22
 * Time: 10:52 AM
 */

namespace App\Libs\transfer;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\salesman\TransferBonus;
use App\Models\InteriorTradeAndTransfer;
use App\Models\Organization;
use App\Models\Transfer;
use App\Models\InteriorTrade as InteriorTradeModel;
use App\Models\TransferFlow;
use Phalcon\Di\FactoryDefault;

class InteriorTrade
{
    public function create(Transfer $transfer)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = FactoryDefault::getDefault()->get('session')->get('auth');
            //计算
            $transferComputing = new TransferComputing();
            $transferComputing = $transferComputing->interiorTrade($transfer);

            //生成财务审核单
            $interiorTrade = new InteriorTradeModel();
            $interiorTrade->SendOrganizationId = $auth['OrganizationId'];
            $interiorTrade->SendOrganizationName = $auth['OrganizationName'];
            $interiorTrade->AcceptOrganizationId = $transfer->SendOrganizationId;
            $interiorTrade->AcceptOrganizationName = $transfer->SendOrganizationName;
            $interiorTrade->Status = InteriorTradeModel::STATUS_WAIT;
            $interiorTrade->Style = InteriorTradeModel::STYLE_TRANSFER;
            $interiorTrade->Created = time();
            $interiorTrade->Total = $transferComputing['Total'];
            if ($interiorTrade->save() === false) {
                $exception->loadFromModel($interiorTrade);
                throw $exception;
            }
            $interiorTrade->refresh();
            //建立关系
            $interiorTradeTransfer = new InteriorTradeAndTransfer();
            $interiorTradeTransfer->TransferId = $transfer->Id;
            $interiorTradeTransfer->InteriorTradeId = $interiorTrade->Id;
            $interiorTradeTransfer->Amount = $transferComputing['ShareOneNum'];
            $interiorTradeTransfer->ShareCloud = $transferComputing['ShareCloudNum'];
            if ($interiorTradeTransfer->save() === false) {
                $exception->loadFromModel($interiorTradeTransfer);
                throw $exception;
            }
            //业务经理奖励
            $transferBonus = new TransferBonus();
            $bonus = $transferBonus->bonusMoney($transfer);
            $transferBonus->create($interiorTrade, $bonus);

        } catch (ParamException $e) {
            throw $e;
        }
    }

    public function resubmissionForTransferFlow(Organization $organization, Transfer $transfer, Validate $validate, array $data)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $flow = new Flow($transfer);
            $transferComputing = new TransferComputing();
            //平台手续费
            $shareNum = $transferComputing->shareNum($transfer, 0);
            $shareCloud = $shareNum['Ratio'];
            $count = count($data);
            //顺序排列
            $sort = 0;
            foreach ($data as $k => $datum) {
                if ($sort > $datum['Id']) {
                    throw $exception;
                }
                $sort = $datum['Id'];
                /** @var TransferFlow $transferFlow */
                $transferFlow = TransferFlow::findFirst(sprintf('Id=%d', $datum['Id']));
                if (!$transferFlow || $transferFlow->TransferId != $transfer->Id) {
                    throw $exception;
                }
                /** 来自套餐的转诊，原始单不能修改金额，流转后可以修改 */
                if ($transfer->Source == Transfer::SOURCE_COMBO && $k == 0) {
                    if ($transferFlow->Cost != $datum['Cost'] || $transferFlow->GenreOne != $datum['GenreOne'] || $transferFlow->ShareOne != $datum['ShareOne']) {
                        throw new LogicException('来自套餐单的转诊单不能修改金额', Status::BadRequest);
                    }
                }

                $info = [
                    'AcceptSectionId'       => $transferFlow->SectionId,
                    'AcceptDoctorId'        => $transferFlow->DoctorId,
                    'OutpatientOrInpatient' => $transferFlow->OutpatientOrInpatient,
                ];
                $change = false;

                if ($transferFlow->SectionId != $datum['SectionId'] || $transferFlow->DoctorId != $datum['DoctorId']) {
                    $change = true;
                    $sectionAndDoctor = $validate->sectionAndDoctor($organization, $datum['SectionId'], $datum['DoctorId']);
                    $transferFlow->SectionId = $sectionAndDoctor['SectionId'];
                    $transferFlow->SectionName = $sectionAndDoctor['SectionName'];
                    $transferFlow->DoctorId = $sectionAndDoctor['DoctorId'];
                    $transferFlow->DoctorName = $sectionAndDoctor['DoctorName'];
                    $info['AcceptSectionId'] = $sectionAndDoctor['SectionId'];
                    $info['AcceptDoctorId'] = $sectionAndDoctor['DoctorId'];
                }
                if ($transferFlow->OutpatientOrInpatient != $datum['OutpatientOrInpatient']) {
                    $change = true;
                    $outpatientOrInpatient = $validate->outpatientOrInpatient($datum['OutpatientOrInpatient']);
                    $transferFlow->OutpatientOrInpatient = $outpatientOrInpatient;
                    $info['OutpatientOrInpatient'] = $outpatientOrInpatient;
                }

                if ($transferFlow->CanModify && ($change || $transferFlow->Cost != $datum['Cost'])) {
                    $transferFlow->Cost = $datum['Cost'];
                    $share = $transferComputing->transferFlow($transfer, $transferFlow, $datum['Cost'], $datum['SectionId'], $datum['OutpatientOrInpatient']);
                    $transferFlow->GenreOne = $share['GenreOne'];
                    $transferFlow->ShareOne = $share['ShareOne'];
                }
                $transferFlow->ShareCloud = $shareCloud;
                $transferFlow->ClinicRemark = $datum['ClinicRemark'];
                $transferFlow->FinishRemark = $datum['FinishRemark'];
                $transferFlow->Created = $datum['Created'];
                $transferFlow->TherapiesExplain = $datum['TherapiesExplain'];
                $transferFlow->ReportExplain = $datum['ReportExplain'];
                $transferFlow->DiagnosisExplain = $datum['DiagnosisExplain'];
                $transferFlow->FeeExplain = $datum['FeeExplain'];
                $transferFlow->TherapiesExplainImages = !empty($datum['TherapiesExplainImages']) ? $datum['TherapiesExplainImages'] : null;
                $transferFlow->ReportExplainImages = !empty($datum['ReportExplainImages']) ? $datum['ReportExplainImages'] : null;
                $transferFlow->DiagnosisExplainImages = !empty($datum['DiagnosisExplainImages']) ? $datum['DiagnosisExplainImages'] : null;
                $transferFlow->FeeExplainImages = !empty($datum['FeeExplainImages']) ? $datum['FeeExplainImages'] : null;
                if (!$transferFlow->save()) {
                    $exception->loadFromModel($transferFlow);
                    throw $exception;
                }

                if ($count == $k + 1 && $change) {
                    $flow->updateTransferInfo($info);
                }
            }
            //更新Transfer
            $totalCost = TransferComputing::totalCost($transfer);
            $transfer->Cost = $totalCost['TotalCost'];
            if ($totalCost['Count'] > 1) {
                $transfer->GenreOne = Transfer::FIXED;
                $transfer->ShareOne = $totalCost['ShareOneNum'];
            }
            $transfer->Status = Transfer::REPEAT;
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
}