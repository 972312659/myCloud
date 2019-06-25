<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/21
 * Time: 上午11:25
 */

namespace App\Admin\Controllers;

use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\Section;
use App\Models\Staff;
use App\Models\StaffSectionLog;
use Phalcon\Paginator\Adapter\QueryBuilder;

class SectionController extends Controller
{
    /**
     * 科室列表
     */
    public function listAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->addFrom(Section::class, 'S');
        $columns = 'S.Id,S.Name,S.IsBuilt,S.Image,S.Poster';
        //1=>基础科室 2=>非基础科室
        if (!empty($data['IsBuilt']) && isset($data['IsBuilt']) && is_numeric($data['IsBuilt'])) {
            $query->andWhere('S.IsBuilt=:IsBuilt:', ['IsBuilt' => $data['IsBuilt']]);
        }
        //科室名字
        if (!empty($data['Name']) && isset($data['Name'])) {
            $query->andWhere('S.Name=:Name:', ['Name' => $data['Name']]);
        }
        //商户号
        if (!empty($data['OrganizationMerchantCode']) && isset($data['OrganizationMerchantCode'])) {

        }
        //商户名
        if (!empty($data['OrganizationName']) && isset($data['OrganizationName'])) {

        }
        $query->columns($columns);
        $paginate = new QueryBuilder([
            'builder' => $query,
            'limit'   => $pageSize,
            'page'    => $page,
        ]);
        $this->outputPagedJson($paginate);
    }

    /**
     * 新增 修改科室
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $section = new Section();
                $data = $this->request->getPost();
                if (empty($data['IsBuilt']) || !isset($data['IsBuilt'])) {
                    $data['IsBuilt'] = Section::BUILT_INSIDE;
                }
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $section = Section::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$section) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if ($section->save($data) === false) {
                $exception->loadFromModel($section);
                throw $exception;
            }
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($section);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 科室操作记录列表
     */
    public function logsAction()
    {
        $query = $this->modelsManager->createBuilder()
            ->columns('L.Id,L.SectionId,L.StaffId,L.Operated,L.Created,S.Name')
            ->addFrom(StaffSectionLog::class, 'L')
            ->join(Staff::class, 'S.Id=L.StaffId', 'S', 'left')
            ->where('L.SectionId=:SectionId:', ['SectionId' => $this->request->get('Id', 'int')])
            ->getQuery()
            ->execute();
        $this->response->setJsonContent($query);
    }
}