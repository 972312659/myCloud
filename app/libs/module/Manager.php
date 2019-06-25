<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/5/30
 * Time: 11:11 AM
 */

namespace App\Libs\module;


use App\Models\DefaultFeature;
use App\Models\DefaultModule;
use App\Models\Module;
use App\Models\ModuleFeature;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\RoleFeature;
use App\Plugins\DispatcherListener;
use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Model\Resultset;

class Manager
{
    protected $defaultModule;

    /**
     * 默认的模块
     */
    public function getDefaultModule()
    {
        $defaultModule = DefaultModule::find()->toArray();

        $this->defaultModule = $defaultModule;
        return $this->defaultModule;
    }

    /**
     * 默认的feature
     */
    public static function getDefaultFeature()
    {
        $manager = new self();
        if (FactoryDefault::getDefault()->get('config')->get('application')->get('debug')) {
            $moduleFeatures = $manager->defaultModuleFeatures();
            /** @var \Phalcon\Mvc\Model\Resultset\Simple $oldFeatures */
            $oldFeatures = $manager->defaultFeature();
            $result = $manager->createDefaultFeature($moduleFeatures, $oldFeatures);
        } else {
            $result = \apcu_entry(DispatcherListener::DEFAULT_FEATURE_KEY, function () use ($manager) {
                $moduleFeatures = $manager->defaultModuleFeatures();
                /** @var \Phalcon\Mvc\Model\Resultset\Simple $oldFeatures */
                $oldFeatures = $manager->defaultFeature();
                return $manager->createDefaultFeature($moduleFeatures, $oldFeatures);
            });
        }
        return $result;
    }

    public function defaultModuleFeatures()
    {
        $manager = new self();
        $defaultModule = $manager->getDefaultModule();
        $moduleFeatures = ModuleFeature::query()
            ->columns(['FeatureId'])
            ->inWhere('ModuleCode', array_column($defaultModule, 'ModuleCode'))
            ->andWhere('SysCode=:SysCode:', ['SysCode' => Module::SysCode_YunWeb])
            ->execute();
        return $moduleFeatures;
    }

    /**
     * 旧的feature方式
     */
    public function defaultFeature()
    {
        return DefaultFeature::find(['Type!=1']);
    }

    /**
     * 通过旧的feature构建新的
     */
    public function createDefaultFeature($moduleFeatures, \Phalcon\Mvc\Model\Resultset\Simple $oldFeatures): array
    {
        $result = $oldFeatures->toArray();
        $a = [];
        foreach ($moduleFeatures as $moduleFeature) {
            $result[] = ['Type' => 1, 'FeatureId' => $moduleFeature->FeatureId];
            $a[] = $moduleFeature->FeatureId;
        }
        return $result;
    }
}