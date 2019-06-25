<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/11
 * Time: 下午7:15
 */

namespace App\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Sphinx;
use App\Models\Equipment;
use App\Models\EquipmentAndSection;
use App\Models\Organization;
use App\Models\OrganizationAndEquipment;
use App\Models\Section;
use Phalcon\Http\Response;
use Phalcon\Paginator\Adapter\QueryBuilder;

class EquipmentController extends Controller
{
    /**
     * 控台暂时不需要使用这个接口
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $equipment = new Equipment();
                $data = $this->request->getPost();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $equipment = Equipment::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$equipment) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if ($equipment->save($data) === false) {
                $exception->loadFromModel($equipment);
                throw $exception;
            }

            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($equipment);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function readAction($id)
    {
        $response = new Response();
        $equipment = Equipment::findFirst(sprintf('Id=%d', $id));
        if (!$equipment) {
            $response->setStatusCode(Status::BadRequest);
            return $response;
        }
        $response->setJsonContent($equipment);
        return $response;
    }

    public function listingAction()
    {
        $response = new Response();
        $equipment = Equipment::find();
        $response->setJsonContent($equipment);
        return $response;

    }

    /**
     * 删除设备
     * @param $id
     * @return Response
     */
    public function deleteAction($id)
    {
        $response = new Response();
        if ($this->request->isDelete()) {
            $equipment = Equipment::findFirst(sprintf('Id=%d', $id));
            if (!$equipment) {
                $response->setStatusCode(Status::BadRequest);
                return $response;
            }
            $equipment->delete();
            $response->setJsonContent(['message' => 'success']);
            return $response;
        }
        $response->setStatusCode(Status::MethodNotAllowed);
        return $response;
    }

    /**
     * 为医院添加设备
     */
    public function addAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            $this->db->begin();
            $now = time();
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $organizationAndEquipment = new OrganizationAndEquipment();
                $data['CreateTime'] = $now;
                $data['UpdateTime'] = $now;
                $data['OrganizationId'] = $auth['OrganizationId'];
                $whiteList = ['OrganizationId', 'SectionId', 'EquipmentId', 'Number', 'Amount', 'Intro', 'CreateTime', 'UpdateTime', 'Display', 'Image'];
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $organizationAndEquipment = OrganizationAndEquipment::findFirst([
                    "OrganizationId=:OrganizationId: and EquipmentId=:EquipmentId:",
                    "bind" => ["OrganizationId" => $auth['OrganizationId'], "EquipmentId" => $data['EquipmentId']],
                ]);
                if (!$organizationAndEquipment) {
                    throw $exception;
                };
                $data['UpdateTime'] = $now;
                $whiteList = ['SectionId', 'Amount', 'Intro', 'UpdateTime', 'Display', 'Image', 'EquipmentId'];
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            //不使用内置设备，就需要新建设备，且不共享
            if (!empty($data['NewEquipment']) && isset($data['NewEquipment'])) {
                $data['NewEquipment'] = trim($data['NewEquipment']);
                if ($data['NewEquipment'] != $organizationAndEquipment->EquipmentId) {
                    $oldEquipment = Equipment::findFirst([
                        "Name=:Name:",
                        'bind' => ['Name' => $data['NewEquipment']],
                    ]);
                    if (!$oldEquipment) {
                        $equipment = new Equipment();
                        $equipmentData = [];
                        $equipmentData['Name'] = $data['NewEquipment'];
                        if ($equipment->save($equipmentData) === false) {
                            $exception->loadFromModel($equipment);
                            throw $exception;
                        }
                        $data['EquipmentId'] = $equipment->Id;
                    } else {
                        $data['EquipmentId'] = $oldEquipment->Id;
                    }
                    $oAndE = OrganizationAndEquipment::findFirst([
                        "OrganizationId=:OrganizationId: and EquipmentId=:EquipmentId:",
                        "bind" => ["OrganizationId" => $auth['OrganizationId'], "EquipmentId" => $data['EquipmentId']],
                    ]);
                    if ($oAndE) {
                        $exception->add('EquipmentId', '该设备已存在');
                        throw $exception;
                    }
                }
            }
            if ($organizationAndEquipment->save($data, $whiteList) === false) {
                $exception->loadFromModel($organizationAndEquipment);
                throw $exception;
            }
            if ($this->request->isPut()) {
                $equipmentAndSections = EquipmentAndSection::find([
                    'conditions' => 'OrganizationId=?0 and EquipmentId=?1',
                    'bind'       => [$organizationAndEquipment->OrganizationId, $data['EquipmentId']],
                ]);
                $equipmentAndSections->delete();
            }
            if (isset($data['SectionIds']) && is_array($data['SectionIds']) && count($data['SectionIds'])) {
                foreach ($data['SectionIds'] as $sectionId) {
                    $equipmentAndSection = new EquipmentAndSection();
                    $equipmentAndSection->OrganizationId = $organizationAndEquipment->OrganizationId;
                    $equipmentAndSection->EquipmentId = $organizationAndEquipment->EquipmentId;
                    $equipmentAndSection->SectionId = $sectionId;
                    if ($equipmentAndSection->save() === false) {
                        $exception->loadFromModel($equipmentAndSection);
                        throw $exception;
                    }
                }
            }
            $this->db->commit();
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($organizationAndEquipment);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 读取医院一条设备信息
     */
    public function showAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        $data = $this->request->getPut();
        $organizationAndEquipment = OrganizationAndEquipment::findFirst([
            "OrganizationId=:OrganizationId: and EquipmentId=:EquipmentId: and Number=:Number:",
            "bind" => ["OrganizationId" => $auth['OrganizationId'], "EquipmentId" => $data['EquipmentId'], "Number" => $data['Number']],
        ]);
        if (!$organizationAndEquipment) {
            $response->setStatusCode(Status::NotFound);
            return $response;
        }
        $result = $organizationAndEquipment->toArray();
        $sections = Section::query()->columns(['Name', 'Image'])->leftJoin(EquipmentAndSection::class, "E.SectionId=Id and OrganizationId={$auth['HospitalId']}", 'E')->where(sprintf('E.EquipmentId=%d', $organizationAndEquipment->EquipmentId))->execute()->toArray();
        $result['Sections'] = array_column($sections, 'Name');
        $result['EquipmentName'] = $organizationAndEquipment->Equipment->Name;
        $result['Image'] = $result['Image'] ?: Equipment::DEFAULT_IMAGE;
        $response->setJsonContent($result);
        return $response;
    }

    /**
     * list of equipment in hospital
     * @return Response
     */
    public function listAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns('OE . OrganizationId,OE . EquipmentId,OE . Number,OE . Amount,OE . Intro,OE . UpdateTime,OE . Display,OE . Image,E . Name as EquipmentName')
            ->addFrom(OrganizationAndEquipment::class, 'OE')
            ->join(Equipment::class, 'E . Id = OE . EquipmentId', 'E', 'left')
            ->where("OE.OrganizationId=:OrganizationId:", ['OrganizationId' => $auth['HospitalId']]);
        //显示状态
        if (!empty($data['Display']) && isset($data['Display'])) {
            $query->andWhere('OE . Display =:Display:', ['Display' => $data['Display']]);
        }
        //搜索设备名字
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'equipment');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->andWhere('E . Id in ' . sprintf('(%s)', implode(',', $ids)));
            } else {
                $query->andWhere('E . Id = -1');
            }
        }
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
        $equipmentAndSections = EquipmentAndSection::query()->inWhere('EquipmentId', array_column($datas, 'EquipmentId'))->andWhere(sprintf('OrganizationId=%d', $auth['HospitalId']))->execute()->toArray();
        $equipmentAndSections_new = [];
        if (count($equipmentAndSections)) {
            foreach ($equipmentAndSections as $equipmentAndSection) {
                $equipmentAndSections_new[$equipmentAndSection['EquipmentId']][] = $equipmentAndSection['SectionId'];
            }
        }
        foreach ($datas as &$data) {
            $data['Image'] = $data['Image'] ?: Equipment::DEFAULT_IMAGE;
            $data['SectionIds'] = $equipmentAndSections_new[$data['EquipmentId']];
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $response->setJsonContent($result);
        return $response;
    }

    /**
     * 删除医院的设备
     * @return Response
     */
    public function delAction()
    {
        $response = new Response();
        if ($this->request->isDelete()) {
            $auth = $this->session->get('auth');
            $data = $this->request->getPut();
            $organizationAndEquipment = OrganizationAndEquipment::findFirst([
                "OrganizationId=:OrganizationId: and EquipmentId=:EquipmentId:",
                "bind" => ["OrganizationId" => $auth['OrganizationId'], "EquipmentId" => $data['EquipmentId']],
            ]);
            if (!$organizationAndEquipment || (int)$organizationAndEquipment->OrganizationId !== (int)$this->user->OrganizationId) {
                $response->setStatusCode(Status::BadRequest);
                return $response;
            }
            $organizationAndEquipment->delete();
            return $response;
        }
        $response->setStatusCode(Status::MethodNotAllowed);
        return $response;
    }

    /**
     * 该医院该 该科室下的设备
     */
    public function sectionAction()
    {
        $response = new Response();
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['OE . OrganizationId', 'S . Id as SectionId', 'S.Name as SectionName', 'S.Image as SectionImage', 'OE . EquipmentId', 'OE . Number', 'OE . Amount', 'OE . Intro', 'OE . CreateTime', 'OE . UpdateTime', 'OE . Share', 'OE . Display', 'OE . Image', 'E . Name as EquipmentName', 'O . Name as OrganizationName'])
            ->addFrom(EquipmentAndSection::class, 'ES')
            ->leftJoin(OrganizationAndEquipment::class, 'OE.OrganizationId=ES.OrganizationId and OE.EquipmentId=ES.EquipmentId', 'OE')
            ->leftJoin(Equipment::class, 'E . Id = ES . EquipmentId', 'E')
            ->leftJoin(Organization::class, 'O . Id = ES . OrganizationId', 'O')
            ->leftJoin(Section::class, 'S.Id=ES.SectionId', 'S')
            ->where('ES . OrganizationId =:OrganizationId:', ['OrganizationId' => (int)$data['OrganizationId']])
            ->andWhere('ES . SectionId =:SectionId:', ['SectionId' => (int)$data['SectionId']])
            ->andWhere('OE . Display = 1')
            ->orderBy('OE . CreateTime');
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
        foreach ($datas as &$data) {
            $data['Image'] = $data['Image'] ?: Equipment::DEFAULT_IMAGE;
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $response->setJsonContent($result);
        return $response;
    }
}