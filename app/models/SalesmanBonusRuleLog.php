<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/4/2
 * Time: 3:41 PM
 */

namespace App\Models;

use Phalcon\Mvc\Model;


class SalesmanBonusRuleLog extends Model
{

    public $Id;

    public $SalesmanBonusRuleId;

    public $OrganizationId;

    public $UserId;

    public $Describe;

    public $LogTime;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'SalesmanBonusRuleLog';
    }

    public function beforeCreate()
    {
        $auth = $this->getDI()->getShared('session')->get('auth');
        $this->LogTime = time();
        $this->OrganizationId = $auth['OrganizationId'];
        $this->UserId = $auth['Id'];
    }

}