<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 2:33 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class Illness extends Model
{
    const Rheumatism = 1;//风湿

    public $Id;

    public $Name;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'Illness';
    }
}