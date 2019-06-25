<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/5/30
 * Time: 2:04 PM
 */

namespace App\Controllers;


use App\Libs\module\ManagerOrganization as ModuleManagerOrganization;
use App\Models\Organization;

class ModuleController extends Controller
{
    /**
     * 获取当前机构所拥有的功能
     */
    public function organizationAction()
    {
        if ($this->session->get('auth')['IsMain'] != Organization::ISMAIN_HOSPITAL) {
            $this->dispatcher->forward(['controller' => 'feature', 'action' => 'organization']);
        }
        $moduleManager = new ModuleManagerOrganization();
        $this->response->setJsonContent($moduleManager->feature());
    }

    /**
     * 当前角色所拥有的功能
     */
    public function currentRoleAction()
    {
        if ($this->session->get('auth')['IsMain'] != Organization::ISMAIN_HOSPITAL) {
            $this->dispatcher->forward(['controller' => 'feature', 'action' => 'currentRole']);
        }
        $moduleManager = new ModuleManagerOrganization();
        $this->response->setJsonContent($moduleManager->roleFeature());
    }
}