<?php

namespace App\Controllers;

use App\Models\ExpressCompany;

class ExpressController extends Controller
{
    public function indexAction()
    {
        $res = ExpressCompany::find([
            'order' => 'Id Desc'
        ]);

        $this->response->setJsonContent(array_map(function ($v) {
            return [
                'Id' => $v['Id'],
                'Name' => $v['Name'],
                'Com' => $v['Com']
            ];
        }, $res->toArray()));
    }
}
