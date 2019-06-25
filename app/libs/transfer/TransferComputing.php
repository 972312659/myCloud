<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/8/13
 * Time: 下午3:28
 */

namespace App\Libs\transfer;


use App\Enums\Status;
use App\Enums\TransferHint;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\salesman\TransferBonus;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\ProfitGroup;
use App\Models\ProfitRule;
use App\Models\RuleOfShareSub;
use App\Models\Transfer;
use App\Models\TransferFlow;
use Phalcon\Di\FactoryDefault;

class TransferComputing
{
    //平台
    public $cloudGenre;//百分比还是固定
    public $shareCloud;//数值
    public $shareNum;//平台手续费
    //网点
    public $genreOne;//百分比还是固定
    public $shareOne;//数值
    public $shareOneNum;//网点的首诊佣金
    //网点所在医院
    public $genreTwo;//百分比还是固定
    public $shareTwo;//百分比数值
    public $shareTwoNum;//网点所在医院的佣金


    /**
     * 自有转诊
     * @param Transfer $transfer 转诊单
     * @param int $cost          金额
     * @return array
     * @throws LogicException
     * @throws ParamException
     * @return array
     */
    public function computing(Transfer $transfer, int $cost): array
    {
        //平台手续费
        $shareNum = $this->shareNum($transfer, $cost);
        $this->cloudGenre = $transfer->CloudGenre;
        $this->shareCloud = $shareNum['Ratio'];
        $this->shareNum = $shareNum['ShareNum'];
        //共享转诊网点上级医院佣金
        $this->genreTwo = $transfer->GenreTwo;
        $this->shareTwo = $transfer->ShareTwo;
        $this->shareTwoNum = $transfer->Genre === Transfer::GENRE_SELF ? 0 : floor($transfer->Genre == Transfer::GENRE_SELF ? 0 : ($transfer->GenreTwo === 1 ? $transfer->ShareTwo : (int)($cost * $transfer->ShareTwo / 100)));;

        //修订：共享转诊同样匹配医院规则
        switch ($transfer->Source) {
            case Transfer::SOURCE_COMBO:
                //套餐
                if ($this->flowCount($transfer)) {
                    //流转后
                    $this->profitRule($transfer, $cost, $transfer->AcceptSectionId, $transfer->OutpatientOrInpatient);
                } else {
                    //未流转前
                    $this->genreOne = $transfer->GenreOne;
                    $this->shareOne = $transfer->ShareOne;
                    $this->shareOneNum = floor($transfer->GenreOne === 1 ? $transfer->ShareOne : (int)($cost * $transfer->ShareOne / 100));
                }
                break;
            default:
                $this->profitRule($transfer, $cost, $transfer->AcceptSectionId, $transfer->OutpatientOrInpatient);
        }


        return [
            'CloudGenre'  => $this->cloudGenre,
            'ShareCloud'  => $this->shareCloud,
            'ShareNum'    => $this->shareNum,
            'GenreOne'    => $this->genreOne,
            'ShareOne'    => $this->shareOne,
            'ShareOneNum' => $this->shareOneNum,
            'GenreTwo'    => $this->genreTwo,
            'ShareTwo'    => $this->shareTwo,
            'ShareTwoNum' => $this->shareTwoNum,
            'AllNum'      => $this->shareNum + $this->shareOneNum + $this->shareTwoNum,
        ];
    }

    /**
     * 匹配规则
     * @param Transfer $transfer
     * @param $cost
     * @param $sectionId
     * @throws LogicException
     */
    public function profitRule(Transfer $transfer, $cost, $sectionId, $outpatientOrInpatient)
    {
        $organizationId = FactoryDefault::getDefault()->get('session')->get('auth')['OrganizationId'];
        $success = false;
        if ($transfer->Genre === Transfer::GENRE_SELF) {

        }

        $ruleId = null;
        $minorName = null;
        switch ($transfer->Genre) {
            case Transfer::GENRE_SELF:
                //自有
                /** @var OrganizationRelationship $organizationRelationship */
                $organizationRelationship = OrganizationRelationship::findFirst([
                    'conditions' => 'MainId=?0 and MinorId=?1',
                    'bind'       => [$organizationId, $transfer->SendOrganizationId],
                ]);
                if (!$organizationRelationship) {
                    throw new LogicException('该网点已不存在', Status::BadRequest);
                }
                $profitRules = ProfitRule::query()
                    ->where('GroupId is null')
                    ->orWhere('GroupId=:GroupId:')
                    ->andWhere('OrganizationId=:OrganizationId:')
                    ->bind(['GroupId' => $organizationRelationship->RuleId, 'OrganizationId' => $organizationId])
                    ->orderBy('Priority asc')
                    ->execute();

                $ruleId = $organizationRelationship->RuleId;
                $minorName = $organizationRelationship->MinorName;
                break;
            default:
                //共享
                /** @var OrganizationRelationship $organizationRelationship */
                $profitRules = ProfitRule::query()
                    ->where('GroupId is null')
                    ->andWhere(sprintf('OrganizationId=%d', $organizationId))
                    ->orderBy('Priority asc')
                    ->execute();
                break;
        }
        //筛选匹配
        if (count($profitRules->toArray())) {
            foreach ($profitRules as $rule) {
                /**@var ProfitRule $rule */
                //金额、科室、时间
                if (
                    ($rule->MinAmount <= $cost && ($rule->MaxAmount == null || $rule->MaxAmount >= $cost)) &&
                    ($rule->SectionId == $sectionId || $rule->SectionId == null) &&
                    (
                        strtotime(date('Y-m-d 00:00:00', $rule->BeginTime)) <= $transfer->StartTime &&
                        ($rule->EndTime == null || strtotime(date('Y-m-d 24:00:00', $rule->EndTime)) >= $transfer->StartTime) &&
                        ($rule->OutpatientOrInpatient == null || $rule->OutpatientOrInpatient == $outpatientOrInpatient)
                    )
                ) {
                    $this->genreOne = $rule->IsFixed ? 1 : 2;
                    $this->shareOne = $rule->Value;
                    $this->shareOneNum = floor($rule->IsFixed ? $rule->Value : (int)($cost * $rule->Value / 100));
                    $success = true;
                    break;
                }
            }
        }

        //如果未能匹配到规格则抛出异常
        if (!$success) {
            if ($transfer->Genre === Transfer::GENRE_SELF) {
                $group = ProfitGroup::findFirst(sprintf('Id=%d', $ruleId));
                throw new LogicException(sprintf(TransferHint::UnMatchingProfitRule_Self, $minorName, ($group ? $group->Name : "全部分组")), Status::BadRequest);
            } else {
                throw new LogicException(sprintf(TransferHint::UnMatchingProfitRule_Share), Status::BadRequest);
            }
        }
    }

    /**
     * 计算流转次数
     * @param Transfer $transfer
     * @return mixed
     */
    public function flowCount(Transfer $transfer)
    {
        return TransferFlow::count(sprintf("TransferId=%d", $transfer->Id));
    }

    /**
     * 平台手续费
     */
    public function shareNum(Transfer $transfer, int $cost)
    {
        /** @var Organization $hospital */
        $hospital = Organization::findFirst(sprintf('Id=%d', $transfer->AcceptOrganizationId));
        if ($hospital->IsMain == Organization::ISMAIN_SUPPLIER) {
            $organizationRelation = OrganizationRelationship::findFirst([
                'conditions' => 'MainId=?0 and MinorId=?1',
                'bind'       => [$transfer->SendHospitalId, $transfer->AcceptOrganizationId],
            ]);
            if (!$organizationRelation) {
                throw new LogicException('二级供应商数据错误', Status::BadRequest);
            }
            $hospital = Organization::findFirst(sprintf('Id=%d', $transfer->SendHospitalId));
        }
        $time = $this->beginTime($hospital);
        $costTotal = $this->costTotal($hospital, $time);
        $ruleOfShareSubs = RuleOfShareSub::find([
            'conditions' => 'RuleOfShareId=?0',
            'bind'       => [$hospital->RuleId],
        ])->toArray();
        $ratio = 0;
        if (count($ruleOfShareSubs) == 1) {
            $ratio = $ruleOfShareSubs[0]['Value'];
        } elseif (count($ruleOfShareSubs) > 1) {
            foreach ($ruleOfShareSubs as $shareSub) {
                if ($shareSub['MaxAmount'] != null && $costTotal > $shareSub['MinAmount'] && $costTotal <= $shareSub['MaxAmount']) {
                    //区间内
                    $ratio = $shareSub['Value'];
                    break;
                } elseif ($shareSub['MaxAmount'] == null) {
                    //最大的区间
                    $ratio = $shareSub['Value'];
                    break;
                } elseif ($costTotal == 0) {
                    //初始
                    $ratio = $shareSub['Value'];
                    break;
                }
            }
        } else {
            throw new LogicException('平台分配手续费错误', Status::BadRequest);
        }
        return ['Ratio' => $ratio, 'ShareNum' => floor((int)($cost * $ratio / 100))];
    }

    /**
     * 金额累计开始的时间戳
     * @param Organization $organization
     * @return false|int
     */
    public function beginTime(Organization $organization)
    {
        $createTime = date('Y-m-d', $organization->CreateTime);
        $i = 0;
        do {
            $time = date('Y-m-d', strtotime("$createTime +{$i}year"));
            $i++;
            $time = strtotime("{$time} +1year");
        } while ($time < time());
        $i--;
        $time = strtotime("$createTime +{$i}year");
        return $time;

    }

    /**
     * 总转诊金额
     * @param Organization $organization
     * @param int $time 发起转诊的起始计算时间
     * @return mixed
     */
    public function costTotal(Organization $organization, int $time)
    {
        return Transfer::sum([
            'conditions' => sprintf('AcceptOrganizationId=%s and Status=%s and IsFake=0 and StartTime>=%s', $organization->Id, Transfer::FINISH, $time),
            'column'     => 'Cost',
        ]) ?: 0;
    }

    /**
     * 计算流转提交财务审核单
     */
    public function interiorTrade(Transfer $transfer)
    {
        $transferFlows = TransferFlow::find([
            'conditions' => 'TransferId=?0',
            'bind'       => [$transfer->Id],
        ]);
        $cost = 0;
        $shareOneNum = 0;
        $shareCloudNum = 0;
        foreach ($transferFlows as $flow) {
            /** @var TransferFlow $flow */
            $cost += $flow->Cost;
            $shareOneNum += $this->amount($flow->Cost, $flow->ShareOne, $flow->GenreOne == Transfer::FIXED);
            $shareCloudNum += $this->amount($flow->Cost, $flow->ShareCloud, $flow->CloudGenre == Transfer::FIXED);
        }
        $shareTwoNum = $transfer->Genre == Transfer::GENRE_SELF ? 0 : $this->amount($cost, $transfer->ShareTwo ?: 0, $transfer->GenreTwo == Transfer::FIXED);
        //业务人员奖励
        $transferBonus = new TransferBonus();
        $bonus = $transferBonus->bonusMoney($transfer);
        $salesmanNum = $bonus->Bonus ?: 0;

        return [
            'ShareCloudNum' => floor($shareCloudNum),
            'ShareOneNum'   => floor($shareOneNum),
            'ShareTwoNum'   => floor($shareTwoNum),
            'Total'         => (int)(floor($shareCloudNum) + floor($shareOneNum) + floor($shareTwoNum) + $salesmanNum),
        ];
    }

    /**
     * 根据固定或者百分比计算费用
     * @param int $cost     总共花费
     * @param int $value    值
     * @param bool $isFixed 是否是固定
     * @return float|int
     */
    public static function amount(int $cost, int $value, bool $isFixed = false)
    {
        return $isFixed ? $value : $cost * $value / 100;
    }

    /**
     * 计算流转单的总费用
     */
    public static function totalCost(Transfer $transfer)
    {
        $flows = TransferFlow::find([
            'conditions' => 'TransferId=?0',
            'bind'       => [$transfer->Id],
        ]);
        $count = 0;
        $totalCost = 0;
        $shareOneNum = 0;
        if (count($flows->toArray())) {
            foreach ($flows as $flow) {
                /** @var TransferFlow $flow */
                $count++;
                $totalCost += $flow->Cost;
                $shareOneNum += self::amount($flow->Cost, $flow->ShareOne, $flow->GenreOne == Transfer::FIXED);
            }
        }
        return [
            'Count'       => $count,
            'TotalCost'   => $totalCost,
            'ShareOneNum' => $shareOneNum,
        ];
    }

    /**
     * 在财务审核不通过，重新提交的时候，根据选择的科室和提交的金额，计算参数
     * @param Transfer $transfer
     * @param TransferFlow $transferFlow
     * @param $cost
     * @param $sectionId
     * @param $outpatientOrInpatient
     * @return array
     */
    public function transferFlow(Transfer $transfer, TransferFlow $transferFlow, $cost, $sectionId, $outpatientOrInpatient)
    {
        //网点佣金
        if ($transferFlow->CanModify) {
            $this->profitRule($transfer, $cost, $sectionId, $outpatientOrInpatient);
            return [
                'GenreOne' => $this->genreOne,
                'ShareOne' => $this->shareOne,
            ];
        }
    }
}