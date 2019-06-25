<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/2/26
 * Time: 3:35 PM
 */
use Phalcon\Cli\Task;

class UserTask extends Task
{
    public function salesmanAction()
    {
        $organizationRelations = \App\Models\OrganizationRelationship::find();
        foreach ($organizationRelations as $relation) {
            $sql = "UPDATE OrganizationUser set IsSalesman=1 WHERE OrganizationId=? and UserId=?";
            $this->db->execute($sql, [$relation->MainId, $relation->SalesmanId]);
        }
    }

    /**
     * 数据权限
     */
    public function appOpsAction()
    {
        $organizations = \App\Models\Organization::find([
            'conditions' => 'IsMain!=2',
        ])->toArray();
        $organizationUser = \App\Models\OrganizationUser::query()
            ->inWhere('OrganizationId', array_column($organizations, 'Id'))
            ->execute();
        $appOps = \App\Enums\AppOps::map();
        foreach ($organizationUser as $user) {
            // foreach ($appOps[\App\Enums\AppOps::OpsType_View] as $ops) {
            //     $sql = 'REPLACE INTO OrganizationUserAppOps (OrganizationId, UserId,ParentOpsId,OpsId) VALUES (?,?,?,?)';
            //     $this->db->execute($sql, [$user->OrganizationId, $user->UserId, $ops['Id'], 1]);
            // }
            foreach ($appOps[\App\Enums\AppOps::OpsType_Operation]['Data'] as $ops) {
                foreach ($ops['Ops'] as $op) {
                    $sql = 'REPLACE INTO OrganizationUserAppOps (OrganizationId, UserId,OpsType,ParentOpsId,OpsId) VALUES (?,?,?,?,?)';
                    $this->db->execute($sql, [$user->OrganizationId, $user->UserId, \App\Enums\AppOps::OpsType_Operation, $ops['Id'], $op['Id']]);
                }
            }
        }
    }
}