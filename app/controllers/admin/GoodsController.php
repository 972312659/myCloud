<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/21
 * Time: 上午11:32
 */

namespace App\Admin\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\ServicePackage;
use Phalcon\Paginator\Adapter\QueryBuilder;

class GoodsController extends Controller
{
    /**
     * 抢号服务包创建与修改
     */
    public function createPackageAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $oldServicePackage = ServicePackage::findFirst();
                if ($oldServicePackage) {
                    throw new LogicException('请勿重复添加', Status::BadRequest);
                }
                $data = $this->request->getPost();
                $servicePackage = new ServicePackage();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $servicePackage = ServicePackage::findFirst(sprintf('Id=%d', $data['Id']));
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if ($servicePackage->save($data) === false) {
                $exception->loadFromModel($servicePackage);
                throw $exception;
            }
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($servicePackage);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 抢号服务包列表
     */
    public function packageListAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = ServicePackage::query();
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }

    /**
     * 查看抢号服务包详情
     */
    public function readPackageAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $servicePackage = ServicePackage::findFirst(sprintf('Id=%d', $this->request->get('Id', 'int')));
            if (!$servicePackage) {
                throw $exception;
            }
            $this->response->setJsonContent($servicePackage);
        } catch (ParamException $e) {
            throw $e;
        }
    }
}