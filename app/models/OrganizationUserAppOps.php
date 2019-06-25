<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/4/9
 * Time: 11:37 AM
 */

namespace App\Models;

use Phalcon\Mvc\Model;


class OrganizationUserAppOps extends Model
{

    public $OrganizationId;

    public $UserId;

    public $OpsType;

    public $ParentOpsId;

    public $OpsId;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'OrganizationUserAppOps';
    }
}