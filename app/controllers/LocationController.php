<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/20
 * Time: 上午11:27
 */

namespace App\Controllers;


use App\Enums\Status;
use App\Models\Location;
use Phalcon\Http\Response;

/**
 * Class LocationController
 * @package App\Controllers
 */
class LocationController extends Controller
{
    public function listAction()
    {
        $response = new Response();
        $id = $this->request->get('LocationId');
        $pId = $id ? $id : 0;
        $locations = Location::find(sprintf('PId=%d', $pId));
        if (!$locations) {
            $response->setStatusCode(Status::NotFound);
            return $response;
        }
        $response->setJsonContent($locations);
        return $response;
    }

    /**
     * 三级json
     * @return Response
     */
    public function indexAction()
    {
        $response = new Response();
        $a = Location::find('PId=0');
        $a = $a->toArray();
        $b = Location::query()
            ->inWhere('PId', array_column($a, 'Id'))
            ->execute();
        $b = $b->toArray();
        $c = Location::query()
            ->inWhere('PId', array_column($b, 'Id'))
            ->execute();
        $c = $c->toArray();
        $new = [];
        foreach ($c as $v) {
            $h = ['value' => $v['Id'], 'text' => $v['Name']];
            $new[$v['PId']][] = $h;
        }
        $m = [];
        foreach ($b as $v) {
            $q = ['value' => $v['Id'], 'text' => $v['Name'], 'children' => $new[$v['Id']]];
            $m[$v['PId']][] = $q;
        }
        $n = [];
        foreach ($a as $v) {
            $p = ['value' => $v['Id'], 'text' => $v['Name'], 'children' => $m[$v['Id']]];
            $n[] = $p;
        }
        $response->setJsonContent($n);
        return $response;
    }
}