<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class OrganizationTypeFeature extends Model
{
    public $Id;

    public $OrganizationTypeId;

    public $FeatureId;

    public function getSource()
    {
        return 'OrganizationTypeFeature';
    }
}