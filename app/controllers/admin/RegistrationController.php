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
use App\Models\ExDoctor;
use App\Models\ExHospital;
use App\Models\ExSection;
use Phalcon\Paginator\Adapter\QueryBuilder;

class RegistrationController extends Controller
{
    public function hospitalAction()
    {
        if (!$this->request->isPut()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }
        $hospital = ExHospital::findFirst($this->request->get('Id'));
        if (!$hospital) {
            throw new LogicException('医院不存在', Status::BadRequest);
        }
        if ($hospital->save($this->request->getPut()) === false) {
            $exception = new ParamException(Status::BadRequest);
            $exception->loadFromModel($hospital);
            throw $exception;
        }
        $this->response->setStatusCode(Status::OK);
        $this->response->setJsonContent($hospital);
    }

    public function sectionAction()
    {
        if (!$this->request->isPut()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }
        $section = ExSection::findFirst($this->request->get('Id'));
        if (!$section) {
            throw new LogicException('科室不存在', Status::BadRequest);
        }
        if ($section->save($this->request->getPut()) === false) {
            $exception = new ParamException(Status::BadRequest);
            $exception->loadFromModel($section);
            throw $exception;
        }
        $this->response->setStatusCode(Status::OK);
        $this->response->setJsonContent($section);
    }

    public function doctorAction()
    {
        if (!$this->request->isPut()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }
        $doctor = ExDoctor::findFirst($this->request->get('Id'));
        if (!$doctor) {
            throw new LogicException('医生不存在', Status::BadRequest);
        }
        if ($doctor->save($this->request->getPut()) === false) {
            $exception = new ParamException(Status::BadRequest);
            $exception->loadFromModel($doctor);
            throw $exception;
        }
        $this->response->setStatusCode(Status::OK);
        $this->response->setJsonContent($doctor);
    }

    public function doctorListAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = ExDoctor::query();
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }

    public function SectionListAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = ExSection::query();
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