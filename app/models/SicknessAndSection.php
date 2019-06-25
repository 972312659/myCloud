<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/25
 * Time: 上午11:34
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class SicknessAndSection extends Model
{
    //启用状态 0=>禁用 1=>启用
    const STATUS_OFF = 0;
    const STATUS_ON = 1;

    public $SectionId;

    public $SicknessId;

    public $Status;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'SicknessAndSection';
    }

}