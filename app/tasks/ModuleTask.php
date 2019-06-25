<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/6/3
 * Time: 2:14 PM
 */
use Phalcon\Cli\Task;

class ModuleTask extends Task
{
    public function setOrganizationModuleAction()
    {
        $organizations = \App\Models\Organization::find([
            'conditions' => 'IsMain=1 and Id!=0',
        ]);
        foreach ($organizations as $organization) {
            $date = date('Y-m-d H:i:s', $organization->CreateTime);
            $expire = $organization->Expire ? date('Y-m-d H:i:s', strtotime($organization->Expire) + 86399) : "1990-01-01 00:00:00";
            /** @var \App\Models\Organization $organization */
            $sql = 'REPLACE INTO OrganizationModule (OrganizationId, SysCode,ParentCode,ModuleCode,ValidTimeBeg,ValidTimeEnd,IsDisable,AddUser,AddTime,ModifyUser,ModifyTime,IsDelete) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)';
            $this->db->execute($sql, [$organization->Id, 'yun-web', '', 'M_TRANSFER', $date, $expire, 0, 'init', $date, 'init', date('Y-m-d H:i:s'), 0]);

        }

        // $suppliers = \App\Models\Organization::find([
        //     'conditions' => 'IsMain=3',
        // ]);
        // foreach ($suppliers as $organization) {
        //     $date = date('Y-m-d H:i:s', $organization->CreateTime);
        //     $expire = $organization->Expire . ' 23:59:59';
        //     /** @var \App\Models\Organization $organization */
        //     $sql = 'REPLACE INTO OrganizationModule (OrganizationId, SysCode,ParentCode,ModuleCode,ValidTimeBeg,ValidTimeEnd,IsDisable,AddUser,AddTime,ModifyUser,ModifyTime,IsDelete) VALUES (?,?,?,?,?)';
        //     $this->db->execute($sql, [$organization->Id, 'yun-web', '', 'M_TRANSFER', $date, $expire, 0, 'init', $date, date('Y-m-d H:i:s'), 0]);
        //
        // }
    }
}