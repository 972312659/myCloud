<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/6/1
 * Time: 上午9:10
 * For: 疾病管理
 */

namespace App\Admin\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Sphinx;
use App\Models\Organization;
use App\Models\OrganizationAndSection;
use App\Models\Section;
use App\Models\Sickness;
use App\Models\SicknessAndOrganization;
use App\Models\SicknessAndSection;
use App\Models\SicknessSection;
use Phalcon\Paginator\Adapter\QueryBuilder;

class SicknessController extends Controller
{
    /**
     * 创建、更新疾病
     */
    public function createSicknessAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $change = false;
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $oldSickness = Sickness::findFirst(sprintf('Id=%d', $data['SicknessId']));
                if (!$oldSickness) {
                    throw $exception;
                }
                if (!isset($data['Name']) || empty($data['Name'])) {
                    throw $exception;
                }
                $sicknessAndOrganization = SicknessAndOrganization::findFirst([
                    'conditions' => 'SicknessSectionId=?0 and SicknessId=?1',
                    'bind'       => [$data['SectionId'], $oldSickness->Id],
                ]);
                if ($sicknessAndOrganization) {
                    throw new LogicException('疾病下已关联医院科室，不能修改', Status::BadRequest);
                }
                $data['Name'] = trim($data['Name']);
                if ($data['Name'] != $oldSickness->Name) {
                    $change = true;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $section = SicknessSection::findFirst(sprintf('Id=%d', $data['SectionId']));
            if (!$section) {
                throw $exception;
            }
            $sickness_exist = Sickness::findFirst([
                'conditions' => 'Name=?0',
                'bind'       => [$data['Name']],
            ]);
            if ($sickness_exist) {
                $sicknessId = $sickness_exist->Id;
            } else {
                $sickness = new Sickness();
                if ($sickness->save($data) === false) {
                    $exception->loadFromModel($sickness);
                    throw $exception;
                }
                $sicknessId = $sickness->Id;
            }
            $relation_exist = SicknessAndSection::findFirst([
                'conditions' => 'SicknessId=?0 and SectionId=?1',
                'bind'       => [$sicknessId, $section->Id],
            ]);
            if (!$relation_exist) {
                $sicknessAndSection = new SicknessAndSection();
                $sicknessAndSection->SicknessId = $sicknessId;
                $sicknessAndSection->SectionId = $section->Id;
                if ($sicknessAndSection->save() === false) {
                    $exception->loadFromModel($sicknessAndSection);
                    throw $exception;
                }
            }
            if ($this->request->isPut()) {
                if ($change) {
                    $oldRelation = SicknessAndSection::findFirst([
                        'conditions' => 'SicknessId=?0 and SectionId=?1',
                        'bind'       => [$oldSickness->Id, $section->Id],
                    ]);
                    if ($oldRelation) {
                        $oldRelation->delete();
                    }
                }
            }
            $this->db->commit();
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 创建、更新科室
     */
    public function createSectionAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $section = new SicknessSection();
                if (!isset($data['Pid']) || empty($data['Pid'])) {
                    $data['Pid'] = 0;
                }
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $section = SicknessSection::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$section) {
                    throw $exception;
                }
                if ($section->Pid) {
                    //二级科室与疾病
                    $relations = SicknessAndSection::findFirst([
                        'conditions' => "SectionId=?0",
                        'bind'       => [$section->Id],
                    ]);
                    if ($relations) {
                        throw new LogicException('科室下存在疾病，不能修改', Status::BadRequest);
                    }
                } else {
                    //一级科室与二级科室
                    $children = SicknessSection::findFirst([
                        'conditions' => "Pid=?0",
                        'bind'       => [$section->Id],
                    ]);
                    if ($children) {
                        throw new LogicException('一级科室下存在二级科室，不能修改', Status::BadRequest);
                    }
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if (is_numeric($data['Pid']) && $data['Pid'] > 0) {
                if (isset($data['Pid']) && is_numeric($data['Pid'])) {
                    $parent = SicknessSection::findFirst(sprintf('Id=%d', $data['Pid']));
                    if (!$parent) {
                        throw $exception;
                    }
                    if ($parent->Pid) {
                        throw new LogicException('一级科室错误', Status::BadRequest);
                    }
                }
            }
            if ($section->save($data) === false) {
                $exception->loadFromModel($section);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 疾病列表
     */
    public function sicknessListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns(['D.Id as ParentSectionId', 'A.SectionId', 'A.SicknessId', 'D.Name as ParentSectionName', 'C.Name as SectionName', 'B.Name as SicknessName', 'A.Status'])
            ->addFrom(SicknessAndSection::class, 'A')
            ->leftJoin(Sickness::class, 'B.Id=A.SicknessId', 'B')
            ->leftJoin(SicknessSection::class, 'C.Id=A.SectionId', 'C')
            ->leftJoin(SicknessSection::class, 'D.Id=C.Pid', 'D');
        //一级科室
        if (isset($data['ParentSectionName']) && !empty($data['ParentSectionName'])) {
            $query->andWhere('D.Name=:ParentSectionName:', ['ParentSectionName' => $data['ParentSectionName']]);
        }
        //二级科室
        if (isset($data['SectionName']) && !empty($data['SectionName'])) {
            $query->andWhere('C.Name=:SectionName:', ['SectionName' => $data['SectionName']]);
        }
        //病种
        if (isset($data['SicknessName']) && !empty($data['SicknessName'])) {
            $query->andWhere('B.Name=:SicknessName:', ['SicknessName' => $data['SicknessName']]);
        }
        //状态
        if (isset($data['Status']) && !empty($data['Status'])) {
            $query->andWhere('A.Status=:Status:', ['Status' => $data['Status']]);
        }
        $paginator = new QueryBuilder(
            [
                "builder" => $query,
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }

    /**
     * 一级科室或者二级科室列表
     */
    public function sectionListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = SicknessSection::query();
        switch ($data['Way']) {
            case 'Parent':
                $query->where('Pid=0');
                break;
            case 'Child':
                $query->where('Pid!=0');
                break;
        }
        if (isset($data['Pid']) && !empty($data['Pid'])) {
            $query->andWhere(sprintf('Pid=%d', $data['Pid']));
        }
        if (isset($data['IsAll']) && is_numeric($data['IsAll']) && $data['IsAll']) {
            $this->response->setJsonContent($query->execute());
        } else {
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

    /**
     * 禁用开光
     */
    public function switchAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $sicknessAndSection = SicknessAndSection::findFirst([
                'conditions' => 'SectionId=?0 and SicknessId=?1',
                'bind'       => [$this->request->getPut('SectionId', 'int'), $this->request->getPut('SicknessId', 'int')],
            ]);
            $sicknessAndSection->Status = $sicknessAndSection->Status ? SicknessAndSection::STATUS_OFF : SicknessAndSection::STATUS_ON;
            if ($sicknessAndSection->save() === false) {
                $exception->loadFromModel($sicknessAndSection);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 医院列表
     */
    public function hospitalsAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = Organization::query()
            ->where(sprintf('IsMain=%d', Organization::ISMAIN_HOSPITAL));
        //医院名字搜索
        $ids = [];
        if (isset($data['HospitalName']) && !empty($data['HospitalName'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['HospitalName'], 'name')->fetchAll();
            $ids = array_merge($ids, array_column($name ? $name : [], 'id'));

        }
        //科室名字搜索
        if (isset($data['SectionName']) && !empty($data['SectionName'])) {
            $sphinx = new Sphinx($this->sphinx, 'section');
            $name = $sphinx->match($data['SectionName'], 'name')->fetchAll();
            $section_ids = array_column($name ? $name : [], 'id');
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->where('!=', Organization::ISMAIN_SLAVE, 'ismain')->andWhere('in', $section_ids, 'sharesectionids')->fetchAll();
            $ids = array_merge($ids, array_column($name ? $name : [], 'id'));
        }
        if ((isset($data['HospitalName']) && !empty($data['HospitalName'])) || (isset($data['SectionName']) && !empty($data['SectionName']))) {
            if (count($ids)) {
                $query->inWhere('Id', $ids);
            } else {
                $query->inWhere('Id', [-1]);
            }
        }
        $query->andWhere(sprintf('Id!=0'));
        if (isset($data['IsAll']) && is_numeric($data['IsAll']) && $data['IsAll']) {
            $this->response->setJsonContent($query->execute());
        } else {
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

    /**
     * 医院所对应的科室列表
     */
    public function sectionsAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['S.Name as SectionName', 'OS.SectionId', 'OS.OrganizationId', 'if(SO.OrganizationSectionId>0,1,0) as sign'])
            ->addFrom(OrganizationAndSection::class, 'OS')
            ->leftJoin(Section::class, 'S.Id=OS.SectionId', 'S')
            ->leftJoin(SicknessAndOrganization::class, 'SO.OrganizationId=OS.OrganizationId and SO.OrganizationSectionId=OS.SectionId', 'SO')
            ->where('OS.OrganizationId=:OrganizationId:', ['OrganizationId' => $data['OrganizationId']]);
        //科室名字搜索
        if (isset($data['SectionName']) && !empty($data['SectionName'])) {
            $sphinx = new Sphinx($this->sphinx, 'section');
            $name = $sphinx->match($data['SectionName'], 'name')->fetchAll();
            $section_ids = array_column($name ? $name : [], 'id');
            $query->inWhere('OS.SectionId', $section_ids);
        }
        if (isset($data['IsAll']) && is_numeric($data['IsAll']) && $data['IsAll']) {
            $this->response->setJsonContent($query->getQuery()->execute());
        } else {
            $paginator = new QueryBuilder(
                [
                    "builder" => $query,
                    "limit"   => $pageSize,
                    "page"    => $page,
                ]
            );
            $this->outputPagedJson($paginator);
        }
    }

    /**
     * 关联平台医院科室
     */
    public function relationAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPost();
            $sicknessAndOrganization = new SicknessAndOrganization();
            if ($sicknessAndOrganization->save($data) === false) {
                $exception->loadFromModel($sicknessAndOrganization);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 删除关联
     */
    public function delRelationAction()
    {
        if (!$this->request->isDelete()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }
        $data = $this->request->getPut();
        $sicknessAndOrganization = SicknessAndOrganization::findFirst([
            'conditions' => 'SicknessSectionId=?0 and SicknessId=?1 and OrganizationId=?2 and OrganizationSectionId=?3',
            'bind'       => [$data['SicknessSectionId'], $data['SicknessId'], $data['OrganizationId'], $data['OrganizationSectionId']],
        ]);
        if (!$sicknessAndOrganization) {
            throw new LogicException('参数错误', Status::BadRequest);
        }
        $sicknessAndOrganization->delete();
    }

    /**
     * 列表
     */
    public function relationListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns([
                'SP.Name as ParentSectionName', 'SS.Name as SickSectionName', 'O.Name as HospitalName', 'S.Name as HospitalSectionName',
                'SO.Status', 'SO.SicknessSectionId', 'SO.SicknessId', 'SO.OrganizationId', 'SO.OrganizationSectionId',
            ])
            ->addFrom(SicknessAndOrganization::class, 'SO')
            ->leftJoin(SicknessSection::class, 'SS.Id=SO.SicknessSectionId', 'SS')
            ->leftJoin(SicknessSection::class, 'SP.Id=SS.Pid', 'SP')
            ->leftJoin(Sickness::class, 'SN.Id=SO.SicknessId', 'SN')
            ->leftJoin(Organization::class, 'O.Id=SO.OrganizationId', 'O')
            ->leftJoin(OrganizationAndSection::class, 'OS.OrganizationId=SO.OrganizationId and OS.SectionId=SO.OrganizationSectionId', 'OS')
            ->leftJoin(Section::class, 'S.Id=SO.OrganizationSectionId', 'S')
            ->where('SicknessSectionId=:SicknessSectionId:', ['SicknessSectionId' => $data['SicknessSectionId']])
            ->andWhere('SicknessId=:SicknessId:', ['SicknessId' => $data['SicknessId']]);
        $paginator = new QueryBuilder(
            [
                "builder" => $query,
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        $sphinx = new Sphinx($this->sphinx, 'organization');
        foreach ($datas as &$data) {
            $result = $sphinx->where('=', $data['OrganizationId'], 'id')->andWhere('=', $data['OrganizationSectionId'], 'sharesectionids')->fetch();
            if ($result) {
                if (!$data['Status']) {
                    $one = SicknessAndOrganization::findFirst([
                        'conditions' => 'SicknessSectionId=?0 and SicknessId=?1 and OrganizationId=?2 and OrganizationSectionId=?3',
                        'bind'       => [$data['SicknessSectionId'], $data['SicknessId'], $data['OrganizationId'], $data['OrganizationSectionId']],
                    ]);
                    $one->Status = SicknessAndOrganization::STATUS_ON;
                    $one->save();
                    $data['Status'] = SicknessAndOrganization::STATUS_ON;
                }
            } else {
                if ($data['Status']) {
                    $one = SicknessAndOrganization::findFirst([
                        'conditions' => 'SicknessSectionId=?0 and SicknessId=?1 and OrganizationId=?2 and OrganizationSectionId=?3',
                        'bind'       => [$data['SicknessSectionId'], $data['SicknessId'], $data['OrganizationId'], $data['OrganizationSectionId']],
                    ]);
                    $one->Status = SicknessAndOrganization::STATUS_OFF;
                    $one->save();
                    $data['Status'] = SicknessAndOrganization::STATUS_OFF;
                }
            }
        }
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }
}