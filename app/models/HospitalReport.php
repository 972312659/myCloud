<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class HospitalReport extends Model
{
    public $Id;

    public $OrganizationId;

    public $Date;

    public $TransferDay;

    public $TransferMonth;

    public $TransferYear;

    public $CostDay;

    public $CostMonth;

    public $CostYear;

    public function initialize()
    {

    }

    public function getSource()
    {
        return 'HospitalReport';
    }

}