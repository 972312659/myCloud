<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/12
 * Time: 2:25 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class SyndromeRelation extends Model
{
    public $ChineseSyndromeId;

    public $WesternSyndromeId;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'SyndromeRelation';
    }
}