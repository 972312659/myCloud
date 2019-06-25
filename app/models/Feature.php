<?php

namespace App\Models;

use Phalcon\Mvc\Model;

/**
 * Class Feature
 * 此类修改请务必同时修改tool相应的model
 * @package App\Models
 */
class Feature extends Model
{
    private static $temp;
    public $Id;
    public $Name;
    public $PId;
    public $Url;
    public $Count;
    public $Sort;
    public $Icon;
    public $Sign;

    /**
     * 构造功能树型结构(不包含根节点)
     * @param int $parentId 根id
     * @return array
     */
    public static function tree(int $parentId = 0): array
    {
        $features = self::find(['order' => 'Sort asc,Id asc'])->toArray();
        $num = \count($features);
        /**
         * @var Model\Criteria $q
         */
        // 计算feature的路由数量
        $q = Action::query();
        $q->groupBy('FeatureId');
        $q->conditions('FeatureId>0');
        $q->columns(['FeatureId', 'count(*) AS Count']);
        $actions = $q->execute()->toArray();
        for ($i = 0; $i < $num; $i++) {
            foreach ($actions as $action) {
                if ($features[$i]['Id'] === $action['FeatureId']) {
                    $features[$i]['ActionCount'] = $action['Count'];
                }
            }
        }

        // 计算feature关联的机构数量
        $q = OrganizationFeature::query();
        $q->groupBy('FeatureId');
        $q->columns(['FeatureId', 'count(*) AS Count']);
        $ofs = $q->execute()->toArray();
        for ($i = 0; $i < $num; $i++) {
            foreach ($ofs as $of) {
                if ($features[$i]['Id'] === $of['FeatureId']) {
                    $features[$i]['OrganizationCount'] = $of['Count'];
                }
            }
        }

        // 计算feature关联的角色数量
        $q = RoleFeature::query();
        $q->groupBy('FeatureId');
        $q->columns(['FeatureId', 'count(*) AS Count']);
        $rfs = $q->execute()->toArray();
        for ($i = 0; $i < $num; $i++) {
            foreach ($rfs as $rf) {
                if ($features[$i]['Id'] === $rf['FeatureId']) {
                    $features[$i]['RoleCount'] = $rf['Count'];
                }
            }
        }

        return self::buildTree($features, $parentId);
    }

    /**
     * @param array $elements
     * @param int $parentId
     * @return array
     */
    private static function buildTree(array $elements, int $parentId = 0): array
    {
        $branch = [];
        foreach ($elements as $element) {
            if ($element['PId'] === $parentId) {
                $children = self::buildTree($elements, $element['Id']);
                if ($children) {
                    $element['Children'] = $children;
                }
                $branch[] = $element;
            }
        }
        return $branch;
    }

    /**
     * 找到所有父节点(包含自身)
     * @param int $currentId
     * @return array
     */
    public static function traceParent(int $currentId)
    {
        if (self::$temp === null) {
            self::$temp = self::find();
        }
        $list = [];
        self::trace($list, $currentId);
        return $list;
    }

    /**
     * @param array $list
     * @param int $currentId
     */
    private static function trace(array &$list, int $currentId)
    {
        foreach (self::$temp as $feature) {
            if ($currentId === $feature->Id) {
                self::trace($list, $feature->PId);
                $list[] = $feature;
                break;
            }
        }
    }

    public function getSource()
    {
        return 'Feature';
    }
}