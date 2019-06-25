<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/4/9
 * Time: 11:50 AM
 */

namespace App\Libs\appOps;

use App\Enums\AppOps;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\OrganizationUserAppOps;
use Phalcon\Di\FactoryDefault;

class Manager
{
    /**
     * 处理用户操作权限
     */
    public static function userAppOps(int $userId, array $data)
    {
        $auth = FactoryDefault::getDefault()->get('session')->get('auth');
        $data_tmp = [];
        foreach ($data as $item) {
            foreach ($item['Data'] as $datum) {
                //验证数据
                $checked = array_sum(array_column($datum['Ops'], 'Checked'));
                if ($datum['Choice'] == AppOps::Checked_On) {
                    if ($checked == 0) {
                        throw new LogicException($datum['Name'] . '必选', Status::BadRequest);
                    }
                }
                //单选
                if ($datum['Type'] == AppOps::Type_Radio) {
                    if ($checked > 1) {
                        throw new LogicException($datum['Name'] . '单选', Status::BadRequest);
                    }
                }
                foreach ($datum['Ops'] as $ops) {
                    if ($ops['Checked'] == AppOps::Checked_On) {
                        $data_tmp[$item['Id']][$datum['Id']][] = $ops['Id'];
                    }
                }
            }
        }
        $userAppOps = OrganizationUserAppOps::find([
            'conditions' => 'OrganizationId=?0 and UserId=?1',
            'bind'       => [$auth['OrganizationId'], $userId],
        ]);
        $exist = [];
        if (count($userAppOps->toArray())) {
            foreach ($userAppOps as $appOps) {
                /** @var OrganizationUserAppOps $appOps */
                if (!isset($data_tmp[$appOps->OpsType])) {
                    $appOps->delete();
                    continue;
                }
                if (isset($data_tmp[$appOps->OpsType][$appOps->ParentOpsId]) && in_array($appOps->OpsId, $data_tmp[$appOps->OpsType][$appOps->ParentOpsId])) {
                    $exist[$appOps->OpsType][$appOps->ParentOpsId][] = $appOps->OpsId;
                } else {
                    $appOps->delete();
                }
            }
        }
        if ($data_tmp) {
            $exception = new ParamException(Status::BadRequest);
            try {
                foreach ($data_tmp as $opsType => $info) {
                    foreach ($info as $parentOpsId => $item) {
                        foreach ($item as $value) {
                            if (!isset($exist[$opsType]) || !isset($exist[$opsType][$parentOpsId]) || !in_array($value, $exist[$opsType][$parentOpsId])) {
                                /** @var OrganizationUserAppOps $organizationUserAppOps */
                                $organizationUserAppOps = new OrganizationUserAppOps();
                                $organizationUserAppOps->OrganizationId = $auth['OrganizationId'];
                                $organizationUserAppOps->UserId = $userId;
                                $organizationUserAppOps->ParentOpsId = $parentOpsId;
                                $organizationUserAppOps->OpsId = $value;
                                $organizationUserAppOps->OpsType = $opsType;

                                if (!$organizationUserAppOps->save()) {
                                    $exception->loadFromModel($organizationUserAppOps);
                                    throw $exception;
                                }
                            }
                        }
                    }
                }
            } catch (ParamException $e) {
                throw $e;
            }
        }
    }

}