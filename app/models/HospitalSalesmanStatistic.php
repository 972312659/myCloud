<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/2/26
 * Time: 1:52 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class HospitalSalesmanStatistic extends Model
{
    public $Date;

    public $HospitalId;

    public $UserId;

    public $TodaySlave;

    public $TotalSlave;

    public $TodayTransfer;

    public $TotalTransfer;

    public $TodayTransferCost;

    public $TotalTransferCost;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'HospitalSalesmanStatistic';
    }
}