<?php

namespace App\Models;

use Phalcon\Mvc\Model;

/**
 * Class RoleFeature
 * 此类修改请务必同时修改tool相应的model
 * @package App\Models
 */
class RoleFeature extends Model
{
    public $RoleId;

    public $FeatureId;

    public function getSource()
    {
        return 'RoleFeature';
    }
}