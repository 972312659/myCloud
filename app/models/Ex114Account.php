<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/12/8
 * Time: 16:22
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class Ex114Account extends Model
{
    public $Id;

    public $Phone;

    public $Password;

    public $LastFailedTime;

    public function getSource()
    {
        return 'Ex114Account';
    }

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }
}