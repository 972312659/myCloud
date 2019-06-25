<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/5/31
 * Time: 9:21 AM
 */

namespace App\Libs\module;

use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Models\Module;
use App\Models\ModuleFeature;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\Role;
use App\Models\RoleFeature;
use Phalcon\Di\FactoryDefault;


class ManagerOrganization extends Manager
{
    protected $auth;

    protected $organizationModule;

    const redisName_Prefix = '_PHCR';
    const redisName_OrganizationFeature = "Cache:OrganizationFeature:";
    const redisName_RoleFeature = 'Cache:RoleFeature:';

    public function __construct()
    {
        $this->auth = FactoryDefault::getDefault()->get('session')->get('auth');
    }

    /**
     * 判断是否为超级管理员
     */
    public function isAdmin()
    {
        /** @var Organization $org */
        $org = Organization::findFirst([
            'conditions' => 'Id=?0',
            'bind'       => [$this->auth['OrganizationId']],
        ]);
        return $org->Phone == $this->auth['Phone'];
    }

    /**
     * 机构所有的模块
     */
    public function getOrganizationModule()
    {
        $now = date('Y-m-d H:i:s');
        $parentModule = OrganizationModule::find([
            'conditions' => 'OrganizationId=?0 and ValidTimeBeg<=?1 and ValidTimeEnd>=?2 and ParentCode=?3',
            'bind'       => [$this->auth['OrganizationId'], $now, $now, ''],
        ])->toArray();
        $childModule = OrganizationModule::query()->inWhere('ParentCode', array_column($parentModule, 'ModuleCode'))->andWhere('OrganizationId=' . $this->auth['OrganizationId'])->execute()->toArray();
        $organizationModule = array_merge($parentModule, $childModule);
        $this->organizationModule = $organizationModule;
        return $this->organizationModule;
    }

    /**
     * 机构当前所有模块所拥有的Feature
     */
    public function feature()
    {
        if (FactoryDefault::getDefault()->get('config')->get('application')->get('debug')) {
            $moduleFeatures = $this->moduleFeature();
        } else {
            $key = self::redisName_Prefix . self::redisName_OrganizationFeature . $this->auth['OrganizationId'];
            $moduleFeatures = json_decode(FactoryDefault::getDefault()->get('redis')->get($key));
            if (!$moduleFeatures) {
                $moduleFeatures = $this->moduleFeature();
                FactoryDefault::getDefault()->get('redis')->setex($key, json_encode($moduleFeatures), strtotime(date('Y-m-d', strtotime('+1 day'))) - time());
            }
        }
        return $moduleFeatures;
    }

    public function moduleFeature()
    {
        $this->getOrganizationModule();
        $moduleCode = [];
        if ($this->organizationModule) {
            $moduleCode = array_merge($moduleCode, array_column($this->organizationModule, 'ModuleCode'));
        }
        $moduleFeatures = ModuleFeature::query()
            ->columns(['FeatureId'])
            ->inWhere('ModuleCode', $moduleCode)
            ->execute()->toArray();

        $defaultFeatures = [];
        foreach ($this->getDefaultFeature() as $item) {
            if ($this->auth['IsMain'] == $item['Type']) {
                $defaultFeatures[] = $item['FeatureId'];
            }
        }
        return array_values(array_unique(array_merge(array_column($moduleFeatures, 'FeatureId'), $defaultFeatures)));
    }

    /**
     * 当前用户的所有权限
     */
    public function roleFeature()
    {
        $userFeatures = $this->feature();
        if (!$this->isAdmin()) {
            $roleFeatures = RoleFeature::find([
                'conditions' => 'RoleId=?0',
                'bind'       => [$this->auth['Role']],
                'cache'      => [
                    'key' => self::redisName_RoleFeature . $this->auth['Role'],
                ],
                'hydration'  => true,
            ])->toArray();
            $userFeatures = array_intersect($userFeatures, array_column($roleFeatures, 'FeatureId'));
        }
        return array_values($userFeatures);
    }

    /**
     * 新建organizationModule
     */
    public static function createOrganizationModule($organizationId, $userName, $validTimeEnd, $parentCode = '', $moduleCode = 'M_TRANSFER')
    {
        $date = date('Y-m-d H:i:s');
        $organizationModule = new OrganizationModule();
        $organizationModule->OrganizationId = $organizationId;
        $organizationModule->SysCode = Module::SysCode_YunWeb;
        $organizationModule->ParentCode = $parentCode;
        $organizationModule->ModuleCode = $moduleCode;
        $organizationModule->ValidTimeBeg = $date;
        $organizationModule->ValidTimeEnd = empty($validTimeEnd) ? '1900-01-01 00:00:00' : $validTimeEnd;
        $organizationModule->IsDisable = 0;
        $organizationModule->AddUser = $userName;
        $organizationModule->AddTime = $date;
        $organizationModule->ModifyUser = $userName;
        $organizationModule->ModifyTime = $date;
        $organizationModule->IsDelete = 0;
        $organizationModule->save();
    }

    /**
     * 修改organizationModule
     */
    public static function updateOrganizationModule($organizationId, $expire, $userName)
    {
        /** @var OrganizationModule $organizationModule */
        $organizationModule = OrganizationModule::findFirst([
            'conditions' => 'OrganizationId=?0 and SysCode=?1 and ModuleCode=?2',
            'bind'       => [$organizationId, Module::SysCode_YunWeb, 'M_TRANSFER'],
        ]);
        $organizationModule->ValidTimeEnd = date('Y-m-d H:i:s', strtotime($expire) + 86399);
        $organizationModule->IsDisable = 0;
        $organizationModule->ModifyUser = $userName;
        $organizationModule->ModifyTime = date('Y-m-d H:i:s');
        $organizationModule->IsDelete = 0;
        $organizationModule->save();
    }

    /**
     * 关联模块
     */
    public static function relationModule($organizationId, $userName, $modules)
    {
        if (!empty($modules)) {
            foreach ($modules as $module) {
                if (empty($module['ParentCode']) && empty($module['ValidTimeBeg'])) {
                    throw new LogicException('请选择模块权限的时间', Status::BadRequest);
                }
            }
        }

        $organizationModule = OrganizationModule::find([
            'conditions' => 'OrganizationId=?0',
            'bind'       => [$organizationId],
        ]);

        //角色权限
        $roles = Role::find([
            'conditions' => 'OrganizationId=?0',
            'bind'       => [$organizationId],
        ])->toArray();
        $roleId = [];
        if (!empty($roles)) {
            $roleId = array_column($roles, 'Id');
        }
        $oldModuleCode = [];
        $date = date('Y-m-d H:i:s');
        $redisKey = self::redisName_Prefix . self::redisName_OrganizationFeature . $organizationId;
        if (!empty($organizationModule->toArray())) {
            $moduleCode = array_column($modules, 'ModuleCode');
            foreach ($organizationModule as $item) {
                /** @var OrganizationModule $item */
                if (empty($moduleCode) || !in_array($item->ModuleCode, $moduleCode)) {
                    $item->delete();
                    //清除缓存
                    FactoryDefault::getDefault()->get('redis')->delete($redisKey);
                    self::delRoleCache($roleId);

                } else {
                    $oldModuleCode[] = $item->ModuleCode;
                    foreach ($modules as $module) {
                        if ($item->ModuleCode == $module['ModuleCode']) {
                            $update = false;
                            if ((!empty($module['ValidTimeEnd'])) && $item->ValidTimeEnd != $module['ValidTimeEnd']) {
                                $item->ValidTimeEnd = $module['ValidTimeEnd'];
                                $update = true;
                            }
                            if ($item->ValidTimeBeg != $module['ValidTimeBeg']) {
                                $update = true;
                                $item->ValidTimeBeg = $module['ValidTimeBeg'];

                            }
                            if ($update) {
                                $item->ModifyUser = $userName;
                                $item->ModifyTime = $date;
                                $item->save();
                            }
                        }
                    }
                }
            }
        }

        if (!empty($modules)) {
            //清除缓存
            FactoryDefault::getDefault()->get('redis')->delete($redisKey);
            self::delRoleCache($roleId);
            foreach ($modules as $module) {
                if (empty($oldModuleCode) || !in_array($module['ModuleCode'], $oldModuleCode)) {
                    self::createOrganizationModule($organizationId, $userName, $module['ValidTimeEnd'], $module['ParentCode'], $module['ModuleCode']);
                }
            }
        }
    }

    /**
     * 清除角色id
     */
    public static function delRoleCache(array $roleId)
    {
        if (!empty($roleId)) {
            foreach ($roleId as $role) {
                FactoryDefault::getDefault()->get('redis')->delete(self::redisName_Prefix . self::redisName_RoleFeature . $role);
            }
        }
    }
}