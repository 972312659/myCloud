<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/4/9
 * Time: 1:06 PM
 */

namespace App\Libs\appOps;


use App\Enums\AppOps;
use App\Models\OrganizationUserAppOps;
use Phalcon\Di\FactoryDefault;

class Control
{
    /**
     * 处理
     * @param int $id
     * @return array
     */
    public static function manager(int $id)
    {
        $result = [
            'Access' => false,//有访问权限
            'Other'  => false,//其他权限
        ];
        $auth = FactoryDefault::getDefault()->get('session')->get('auth');
        /** @var OrganizationUserAppOps $userAppOps */
        $userAppOps = OrganizationUserAppOps::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1 and ParentOpsId=?2 and OpsType=?3',
            'bind'       => [$auth['OrganizationId'], $auth['Id'], $id, AppOps::OpsType_View],
        ]);
        if ($userAppOps) {
            $result['Access'] = true;
            if ($userAppOps->OpsId == 1) $result['Other'] = true;
        }
        return $result;
    }

    /**
     * 转诊列表数据权限
     * @return array
     */
    public static function transferList()
    {
        $id = 1;
        $manager = self::manager($id);

        $result = [
            'Access' => $manager['Access'],//有访问权限
            'All'    => $manager['Other'],//全部访问权限
        ];
        return $result;
    }

    /**
     * 转诊单详情显示金额权限
     * @return array
     */
    public static function transferRead()
    {
        $id = 2;
        $manager = self::manager($id);

        $result = [
            'Access'    => $manager['Access'],//有访问权限
            'MoneyShow' => $manager['Other'],//金额访问权限
        ];
        return $result;
    }
}