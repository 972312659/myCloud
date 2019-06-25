<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/2/26
 * Time: 1:52 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class HospitalStatistic extends Model
{
    public $Date;

    public $HospitalId;

    public $TodaySlave;

    public $TotalSlave;

    public $TodayTransfer;

    public $TotalTransfer;

    public $TodayTransferCost;

    public $TotalTransferCost;

    public $TodayTransferGenre;

    public $TotalTransferGenre;

    public $TodayTransferPlatform;

    public $TotalTransferPlatform;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'HospitalStatistic';
    }
}