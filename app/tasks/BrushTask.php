<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/8/3
 * Time: 下午3:36
 */

use Phalcon\Cli\Task;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\Transfer;
use App\Models\TransferLog;

class BrushTask extends Task
{
    public function brushAction()
    {
        $organizations = Organization::query()
            ->columns('Id,Name,CreateTime')
            ->where('IsMain=1')
            ->andWhere('Id!=0')
            ->orderBy('CreateTime asc');
        foreach ($organizations as $organization){
            //所有网点
            $slaves = Organization::query()
                ->columns('Id,Name,R.Name as AliasName,R.RuleId')
                ->leftJoin(\App\Models\OrganizationRelationship::class,'R.MinorId=Id','R')
                ->where(sprintf('R.MainId=%d',$organization->Id))
                ->execute();
            var_dump($slaves->toArray());die;


        }
    }
}