<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/5/22
 * Time: 4:23 PM
 */

namespace App\Controllers;


use App\Libs\Sphinx;

class SphinxController extends Controller
{
    public function getUserAction()
    {
        $sphinx = new Sphinx($this->sphinx, 'user');
        $users = $sphinx->match($this->request->get('Name'), 'name')->fetchAll();
        $this->response->setJsonContent($users);
    }

    public function getOrganizationAction()
    {
        $sphinx = new Sphinx($this->sphinx, 'organization');
        $users = $sphinx->match($this->request->get('Name'), 'name')->fetchAll();
        $this->response->setJsonContent($users);
    }

    public function getSectionAction()
    {
        $sphinx = new Sphinx($this->sphinx, 'section');
        $users = $sphinx->match($this->request->get('Name'), 'name')->fetchAll();
        $this->response->setJsonContent($users);
    }
}