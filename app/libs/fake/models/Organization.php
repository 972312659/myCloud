<?php

namespace App\Libs\fake\models;

use Phalcon\Mvc\Model;

/**
 * Class Organization
 * @package App\Libs\fake\transfer
 *
 * @property int Id
 * @property string Name
 * @property int MoneyFake
 * @property int BalanceFake
 */
class Organization extends Model
{
    /**
     * @var User $user
     */
    public $user;

    public function getSource()
    {
        return 'Organization';
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }
}
