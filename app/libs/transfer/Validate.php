<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/1/25
 * Time: 9:31 AM
 */

namespace App\Libs\transfer;


use App\Models\Organization;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\OrganizationAndSection;
use App\Models\OrganizationUser;
use App\Models\TransferFlow;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;

class Validate
{
    /**
     * 补充说明
     */
    public function addedExplanation(TransferFlow $flow)
    {
        //验证上传
        if (!$flow->TherapiesExplain || ctype_space($flow->TherapiesExplain)) {
            if (!$flow->TherapiesExplainImages) {
                throw new LogicException('请完善治疗方案', Status::BadRequest);
            }
        }
        if (!$flow->DiagnosisExplain || ctype_space($flow->DiagnosisExplain)) {
            if (!$flow->DiagnosisExplainImages) {
                throw new LogicException('请完善诊断结论', Status::BadRequest);
            }
        }
        if (!$flow->FeeExplain || ctype_space($flow->FeeExplain)) {
            if (!$flow->FeeExplainImages) {
                throw new LogicException('请完善收费汇总', Status::BadRequest);
            }
        }
    }

    /**
     * 验证该医院的科室和医生
     * @param Organization $organization
     * @param $sectionId
     * @param $doctorId
     * @param $scene
     * @return array
     * @throws LogicException
     * @throws ParamException
     */
    public function sectionAndDoctor(Organization $organization, $sectionId, $doctorId, $scene = '')
    {
        switch ($scene) {
            case 'flow':
                $scene = '流转';
                $field = 'Flow';
                break;
            default:
                $scene = '';
                $field = 'Accept';
        }

        $exp = new ParamException(Status::BadRequest);
        try {
            $validator = new Validation();
            $validator->rules($field . 'SectionId', [
                new PresenceOf(['message' => $scene . '科室不能为空']),
                new Digit(['message' => $scene . '科室数据错误']),
            ]);
            $validator->rules($field . 'DoctorId', [
                new PresenceOf(['message' => $scene . '医生不能为空']),
                new Digit(['message' => $scene . '医生数据错误']),
            ]);
            $ret = $validator->validate([$field . 'SectionId' => $sectionId, $field . 'DoctorId' => $doctorId]);
            if ($ret->count() > 0) {
                $exp->loadFromMessage($ret);
                throw $exp;
            }
            //验证科室
            /** @var OrganizationAndSection $organizationAndSection */
            $organizationAndSection = OrganizationAndSection::findFirst([
                'conditions' => 'OrganizationId=?0 and SectionId=?1',
                'bind'       => [$organization->Id, $sectionId],
            ]);
            if (!$organizationAndSection || $organizationAndSection->Display != OrganizationAndSection::DISPLAY_ON) {
                throw new LogicException($scene . '科室信息错误', Status::BadRequest);
            }
            $sectionId = $organizationAndSection->SectionId;
            $sectionName = $organizationAndSection->Section->Name;
            /** @var OrganizationUser $organizationUser */
            $organizationUser = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1 and SectionId=?2',
                'bind'       => [$organization->Id, $doctorId, $organizationAndSection->SectionId],
            ]);
            if (!$organizationUser || $organizationUser->Display != OrganizationUser::DISPLAY_ON) {
                throw new LogicException($scene . '医生信息错误', Status::BadRequest);
            }
            $doctorId = $organizationUser->UserId;
            $doctorName = $organizationUser->User->Name;
            return [
                'SectionId'   => $sectionId,
                'SectionName' => $sectionName,
                'DoctorId'    => $doctorId,
                'DoctorName'  => $doctorName,
            ];
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 验证覆盖范围（门诊、住院）
     * @param $outpatientOrInpatient
     * @return mixed
     * @throws LogicException
     * @throws ParamException
     */
    public function outpatientOrInpatient($outpatientOrInpatient, $scene = 'Flow')
    {
        $exp = new ParamException(Status::BadRequest);
        try {
            $validator = new Validation();
            $validator->rules($scene . 'OutpatientOrInpatient', [
                new PresenceOf(['message' => '入院方式不能为空']),
                new Digit(['message' => '入院方式数据错误']),
            ]);
            $ret = $validator->validate([$scene . 'OutpatientOrInpatient' => $outpatientOrInpatient]);
            if ($ret->count() > 0) {
                $exp->loadFromMessage($ret);
                throw $exp;
            }
            return $outpatientOrInpatient;
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 验证费用
     */
    public function cost($cost): int
    {
        $exp = new ParamException(Status::BadRequest);
        try {
            $validator = new Validation();
            $validator->rules('Cost', [
                new PresenceOf(['message' => '诊疗费用金额不能为空']),
                new Digit(['message' => '诊疗费用金额错误']),
            ]);
            $ret = $validator->validate(['Cost' => $cost]);
            if ($ret->count() > 0) {
                $exp->loadFromMessage($ret);
                throw $exp;
            }
            return (int)$cost;
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }
}