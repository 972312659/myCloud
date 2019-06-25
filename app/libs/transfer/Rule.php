<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/1/17
 * Time: 1:58 PM
 */

namespace App\Libs\transfer;


use App\Enums\Status;
use App\Exceptions\ParamException;
use App\Models\Organization;
use App\Models\RuleOfShare;
use App\Models\RuleOfShareSub;

class Rule
{
    /**
     * 一级供应商更新二级供应商手续费规则
     */
    public function supplierFeeUpdate(int $organizationId)
    {
        /** @var Organization $organization */
        $organization = Organization::findFirst(sprintf('Id=%d', $organizationId));
        if ($organization && $organization->IsMain == Organization::ISMAIN_HOSPITAL) {
            /** @var RuleOfShare $rule */
            $rule = RuleOfShare::findFirst(sprintf('Id=%d', $organization->RuleId));
            if ($rule) {
                $ruleOfShareSubs = RuleOfShareSub::find([
                    'conditions' => 'RuleOfShareId=?0',
                    'bind'       => [$organization->RuleId],
                ])->toArray();
                if (count($ruleOfShareSubs) > 1) {
                    $ratio = $rule->Ratio;
                    $transferComputing = new TransferComputing();
                    $time = $transferComputing->beginTime($organization);
                    $costTotal = $transferComputing->costTotal($organization, $time);
                    foreach ($ruleOfShareSubs as $shareSub) {
                        if ($shareSub['MaxAmount'] != null && $costTotal > $shareSub['MinAmount'] && $costTotal <= $shareSub['MaxAmount']) {
                            $ratio = $shareSub['Value'];
                            break;
                        } elseif ($shareSub['MaxAmount'] == null) {
                            $ratio = $shareSub['Value'];
                        }
                    }
                    if ($rule->Ratio != $ratio) {
                        $rule->Ratio = $ratio;
                        $exception = new ParamException(Status::BadRequest);
                        try {
                            if ($rule->save() === false) {
                                $exception->loadFromModel($rule);
                                throw $exception;
                            }
                        } catch (ParamException $e) {
                            throw $e;
                        }
                    }
                }
            }
        }
    }
}