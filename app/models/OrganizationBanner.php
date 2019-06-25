<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/9/14
 * Time: 17:03
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class OrganizationBanner extends Model
{
    /**
     * app
     */
    const PLATFORM_APP = 1;

    /**
     * pc
     */
    const PLATFORM_PC = 2;

    const CHOICE_CONTENT = 1;
    const CHOICE_URL = 2;

    public $Id;

    public $OrganizationId;

    public $Name;

    public $Path;

    public $Url;

    public $Content;

    public $Type;

    public $Sort;

    public $Updated;

    public $Choice;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
    }

    public function getSource()
    {
        return 'OrganizationBanner';
    }

    public function beforeUpdate()
    {
        $this->Updated = time();
    }
}