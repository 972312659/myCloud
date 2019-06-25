<?php

namespace App\Models;

use Phalcon\Mvc\Model;

/**
 * Class OrganizationFeature
 * 此类修改请务必同时修改tool相应的model
 * @package App\Models
 */
class OrganizationFeature extends Model
{
    public $OrganizationId;

    public $FeatureId;

    public function getSource()
    {
        return 'OrganizationFeature';
    }
}