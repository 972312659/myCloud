<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class RolePermission extends Model
{
//    use ValidationTrait;

    public $RoleId;

    public $PermissionId;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'RolePermission';
    }
}
