<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/1/31
 * Time: 下午4:16
 */

namespace App\Admin\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\AppResource;
use App\Models\AppVersion;
use Phalcon\Paginator\Adapter\QueryBuilder;

class AppversionController extends Controller
{
    /**
     * 创建版本
     */
    public function versionAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->get();
            $data['Created'] = time();
            $version = new AppVersion();
            if ($version->save($data) === false) {
                $exception->loadFromModel($version);
                throw $exception;
            }
            $resource = new AppResource();
            if ($resource->save($data) === false) {
                $exception->loadFromModel($resource);
                throw $exception;
            }
            //资源地址
            $path = $data['Platform'] == AppResource::PLATFORM_IOS ? AppResource::PATH_GIT_IOS : AppResource::PATH_GIT_ANDROID;
            $path_zip = AppResource::PATH_ZIP;
            //打包
            exec("cd {$path};git archive -o {$path_zip}{$data['HashKey']}.zip {$data['HashKey']}", $out, $code);
            if ($code !== 0) {
                throw new LogicException('打包资源失败', Status::InternalServerError);
            }
            $this->db->commit();
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($version);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 创建资源
     */
    public function resourceAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->get();
            $data['Created'] = time();
            $resource = new AppResource();
            if ($resource->save($data) === false) {
                $exception->loadFromModel($resource);
                throw $exception;
            }
            //资源地址
            $path = $data['Platform'] == AppResource::PLATFORM_IOS ? AppResource::PATH_GIT_IOS : AppResource::PATH_GIT_ANDROID;
            $path_zip = AppResource::PATH_ZIP;
            //打包
            exec("cd {$path};git archive -o {$path_zip}{$data['HashKey']}.zip {$data['HashKey']}", $out, $code);
            if ($code !== 0) {
                throw new LogicException('打包资源失败', Status::InternalServerError);
            }
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($resource);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function versionListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = AppVersion::query();
        if (is_numeric($data['Platform'])) {
            $query->where(sprintf('Platform=%d', $data['Platform']));
        }
        $query->orderBy('Created desc');
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }

    public function resourceListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = AppResource::query();
        if (is_numeric($data['Platform'])) {
            $query->where(sprintf('Platform=%d', $data['Platform']));
        }
        $query->orderBy('Created desc');
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }
}