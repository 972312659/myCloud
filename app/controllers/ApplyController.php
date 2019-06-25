<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/29
 * Time: 下午5:22
 */

namespace App\Controllers;


use App\Enums\DoctorTitle;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\ApplyOfShare;
use App\Models\Combo;
use App\Models\DoctorOfAptitude;
use App\Models\Equipment;
use App\Models\EquipmentOfAptitude;
use App\Models\HospitalOfAptitude;
use App\Models\Organization;
use App\Models\OrganizationAndEquipment;
use App\Models\OrganizationAndSection;
use App\Models\EquipmentPicture;
use App\Models\OrganizationUser;
use App\Models\Section;
use App\Models\User;
use Phalcon\Http\Response;
use Phalcon\Paginator\Adapter\QueryBuilder;


class ApplyController extends Controller
{
    public function hospitalAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get("auth");
            $this->db->begin();
            if ($this->request->isPost()) {
                $hospital = new HospitalOfAptitude();
                $data = $this->request->getPost();
                $data['OrganizationId'] = $auth['OrganizationId'];
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $hospital = HospitalOfAptitude::findFirst(sprintf('OrganizationId=%d', $auth['OrganizationId']));
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if ($hospital->save($data) === false) {
                $exception->loadFromModel($hospital);
                throw $exception;
            }
            $apply = new ApplyOfShare();
            $apply_data['StartTime'] = time();
            $apply_data['Status'] = ApplyOfShare::WAIT;
            $apply_data['IsHospital'] = 1;
            $apply_data['OrganizationId'] = $auth['OrganizationId'];
            if ($apply->save($apply_data) === false) {
                $exception->loadFromModel($apply);
                throw $exception;
            }
            $organization = Organization::findFirst(sprintf('Id=%d', $auth['HospitalId']));
            $organization->Verifyed = Organization::WAIT;
            if ($organization->save() === false) {
                $exception->loadFromModel($organization);
                throw $exception;
            }
            $this->db->commit();
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($hospital);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function doctorAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get("auth");
            if (!$auth) {
                throw new LogicException('用户未登录', Status::Unauthorized);
            }
            $this->db->begin();
            $organization = Organization::findFirst(sprintf('Id=%d', $auth['OrganizationId']));
            if ($organization->Verifyed !== Organization::VERIFYED) {
                if ($organization->Verifyed === 5) {
                    $exception->add('DoctorId', '请打开医院共享');
                } else {
                    $exception->add('DoctorId', '申请失败,医院资质未审核');
                }
                throw $exception;
            }
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $oldDoctor = DoctorOfAptitude::findFirst(["OrganizationId=:OrganizationId: and DoctorId=:DoctorId:", "bind" => ['OrganizationId' => $auth['OrganizationId'], 'DoctorId' => $data['DoctorId']]]);
                if ($oldDoctor) {
                    $exception->add('DoctorId', '该医生已经完成申请，如需要修改请直接进行修改');
                    throw $exception;
                }
                $doctor = new DoctorOfAptitude();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $doctor = DoctorOfAptitude::findFirst(["OrganizationId=:OrganizationId: and DoctorId=:DoctorId:", "bind" => ['OrganizationId' => $auth['OrganizationId'], 'DoctorId' => $data['DoctorId']]]);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $user = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$auth['OrganizationId'], $data['DoctorId']],
            ]);
            $sectionId = $user->SectionId;
            //不能重复提交申请
            $section = OrganizationAndSection::findFirst(["OrganizationId=:OrganizationId: and SectionId=:SectionId:", "bind" => ['OrganizationId' => $auth['OrganizationId'], 'SectionId' => $sectionId]]);
            if ($section->Share !== 2) {
                $exception->add('DoctorId', '申请失败,科室未审核');
                throw $exception;
            }
            $data['OrganizationId'] = $auth['OrganizationId'];
            if ($doctor->save($data) === false) {
                $exception->loadFromModel($doctor);
                throw $exception;
            }
            $apply = new ApplyOfShare();
            $apply_data['StartTime'] = time();
            $apply_data['Status'] = 0;
            $apply_data['DoctorId'] = $data['DoctorId'];
            $apply_data['OrganizationId'] = $auth['OrganizationId'];
            if ($apply->save($apply_data) === false) {
                $exception->loadFromModel($apply);
                throw $exception;
            }
            $user->Share = 3;
            if ($user->save() === false) {
                $exception->loadFromModel($user);
                throw $exception;
            }
            $this->db->commit();
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($doctor);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $exception;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function equipmentAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $auth = $this->session->get("auth");
            if (!$auth) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            $organization = Organization::findFirst(sprintf('Id=%d', $auth['OrganizationId']));
            if ($organization->Verifyed !== Organization::VERIFYED) {
                $exception->add('EquipmentId', '申请失败,医院资质未审核');
                throw $exception;
            }
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $oldEquipment = EquipmentOfAptitude::findFirst(["OrganizationId=:OrganizationId: and EquipmentId=:EquipmentId:", "bind" => ['OrganizationId' => $auth['OrganizationId'], 'EquipmentId' => $data['EquipmentId']]]);
                if ($oldEquipment) {
                    $exception->add('EquipmentId', '该设备已经完成申请，如需要修改请直接进行修改');
                    throw $exception;
                }
                $oldEquipment = new EquipmentOfAptitude();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $oldEquipment = EquipmentOfAptitude::findFirst(["OrganizationId=:OrganizationId: and EquipmentId=:EquipmentId:", "bind" => ['OrganizationId' => $auth['OrganizationId'], 'EquipmentId' => $data['EquipmentId']]]);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $equipment = OrganizationAndEquipment::findFirst(["OrganizationId=:OrganizationId: and EquipmentId=:EquipmentId:", "bind" => ['OrganizationId' => $auth['OrganizationId'], 'EquipmentId' => $data['EquipmentId']]]);
            $sectionId = $equipment->SectionId;
            $section = OrganizationAndSection::findFirst(["OrganizationId=:OrganizationId: and SectionId=:SectionId:", "bind" => ['OrganizationId' => $auth['OrganizationId'], 'SectionId' => $sectionId]]);
            if ($section->Share !== 2) {
                $exception->add('EquipmentId', '申请失败,科室未审核');
                throw $exception;
            }
            $data['OrganizationId'] = $auth['OrganizationId'];
            if ($oldEquipment->save($data) === false) {
                $exception->loadFromModel($oldEquipment);
                throw $exception;
            }
            //添加设备购买凭证
            if (!empty($data['Images']) && isset($data['Images'])) {
                foreach ((array)$data['Images'] as $v) {
                    $picture = new EquipmentPicture();
                    $picture->Image = $v;
                    $picture->OrganizationId = $oldEquipment->OrganizationId;
                    $picture->EquipmentId = $oldEquipment->EquipmentId;
                    if ($picture->save() === false) {
                        $exception->loadFromModel($picture);
                        throw $exception;
                    }
                }
            }
            $apply = new ApplyOfShare();
            $apply_data['StartTime'] = time();
            $apply_data['Status'] = 0;
            $apply_data['EquipmentId'] = $data['EquipmentId'];
            $apply_data['OrganizationId'] = $auth['OrganizationId'];
            if ($apply->save($apply_data) === false) {
                $exception->loadFromModel($apply);
                throw $exception;
            }
            $equipment->Share = 3;
            if ($equipment->save() === false) {
                $exception->loadFromModel($equipment);
                throw $exception;
            }
            $this->db->commit();
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($oldEquipment);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function sectionAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if ($this->request->isPost()) {
                $auth = $this->session->get('auth');
                $sections = $this->request->getPost('Sections');
                $organization = Organization::findFirst(sprintf('Id=%d', $auth['OrganizationId']));
                if ($organization->Verifyed !== Organization::VERIFYED) {
                    if ($organization->Verifyed === 5) {
                        throw new LogicException('请打开医院共享', Status::BadRequest);
                    }
                    throw new LogicException('申请失败,医院资质未审核', Status::BadRequest);
                }
                $data['OrganizationId'] = $auth['HospitalId'];
                $data['Status'] = 0;
                $data['StartTime'] = time();
                foreach ($sections as $section) {
                    $apply = new ApplyOfShare();
                    $data['SectionId'] = $section;
                    if ($apply->save($data) === false) {
                        $exception->loadFromModel($apply);
                        throw $exception;
                    }
                    $sec = OrganizationAndSection::findFirst([
                        "OrganizationId=:OrganizationId: and SectionId=:SectionId:",
                        "bind" => ["OrganizationId" => $apply->OrganizationId, "SectionId" => $apply->SectionId],
                    ]);
                    if (!$sec) {
                        throw $exception;
                    }
                    $sec->Share = 3;
                    if ($sec->save() === false) {
                        $exception->loadFromModel($sec);
                        throw $exception;
                    }
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $this->db->commit();
            $this->response->setStatusCode(Status::Created);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function comboAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if ($this->request->isPost()) {
                $auth = $this->session->get('auth');
                $combos = $this->request->getPost('Combos');
                $organization = Organization::findFirst(sprintf('Id=%d', $auth['OrganizationId']));
                if ($organization->Verifyed !== Organization::VERIFYED) {
                    if ($organization->Verifyed === 5) {
                        throw new LogicException('请打开医院共享', Status::BadRequest);
                    }
                    throw new LogicException('申请失败,医院资质未审核', Status::BadRequest);
                }
                $data['OrganizationId'] = $auth['HospitalId'];
                $data['Status'] = 0;
                $data['StartTime'] = time();
                foreach ($combos as $combo) {
                    $apply = new ApplyOfShare();
                    $data['ComboId'] = $combo;
                    if ($apply->save($data) === false) {
                        $exception->loadFromModel($apply);
                        throw $exception;
                    }
                    $com = Combo::findFirst([
                        "OrganizationId=:OrganizationId: and Id=:Id:",
                        "bind" => ["OrganizationId" => $apply->OrganizationId, "Id" => $combo],
                    ]);
                    if (!$com) {
                        throw $exception;
                    }
                    $com->Share = 3;
                    if ($com->save() === false) {
                        $exception->loadFromModel($com);
                        throw $exception;
                    }
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $this->db->commit();
            $this->response->setStatusCode(Status::Created);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function readAction()
    {
        $response = new Response();
        $id = $this->request->get('Id');
        $apply = ApplyOfShare::findFirst(sprintf('Id=%d', $id));
        if (!$apply) {
            $response->setStatusCode(Status::NotFound);
            return $response;
        }
        $aptitude = [];
        if (!$apply->IsHopital) {
            $aptitude = HospitalOfAptitude::findFirst(sprintf('OrganizationId=%d', $apply->OrganizationId))->toArray();
        }
        if (!$apply->DoctorId) {
            $aptitude = DoctorOfAptitude::findFirst(["OrganizationId=:OrganizationId: and DoctorId=:DoctorId:", "bind" => ['OrganizationId' => $apply->OrganizationId, 'DoctorId' => $apply->DoctorId]])->toArray();
        }
        if (!$apply->EquipmentId) {
            $aptitude = EquipmentOfAptitude::findFirst(["OrganizationId=:OrganizationId: and EquipmentId=:EquipmentId:", "bind" => ['OrganizationId' => $apply->OrganizationId, 'EquipmentId' => $apply->EquipmentId]])->toArray();
        }
        $result = [];
        $result['HospitalName'] = $apply->Organization->Name;
        if (!$apply->SectionId) {
            $result['SectionName'] = $apply->Section->Name;
        } elseif (!$apply->EquipmentId) {
            $result['Images'] = EquipmentPicture::find(["OrganizationId=:OrganizationId: and EquipmentId=:EquipmentId:", "bind" => ["OrganizationId" => $apply->OrganizationId, "EquipmentId" => $apply->EquipmentId]])->toArray();
        }
        $result[] = array_merge($apply->toArray(), $aptitude);
        $response->setJsonContent($result);
        return $response;
    }

    /**
     * 共享申请的列表
     * @return Response
     */
    public function listAction()
    {
        $response = new Response();
        $data = $this->request->getPost();
        $auth = $this->session->get('auth');
        $hospitalId = $auth['HospitalId'];

        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $columns = 'A.Id,A.OrganizationId,A.IsHospital,A.SectionId,A.DoctorId,A.EquipmentId,A.ComboId,A.Status,A.Remark,A.StartTime,A.EndTime,O.Name as HospitalName';
        $query = $this->modelsManager->createBuilder();

        $query->addFrom('App\Models\ApplyOfShare', 'A');
        if ($hospitalId != Organization::PEACH) {
            $query->where("OrganizationId=:OrganizationId:", ['OrganizationId' => $hospitalId]);
        }

        //搜索资质
        if (!empty($data['IsHospital']) && isset($data['IsHospital'])) {
            $columns .= ',HA.BusinessLicense,HA.Level,HA.Front,HA.Reverse';
            $query->columns($columns . ',HA.BusinessLicense,HA.Level,HA.Front,HA.Reverse');
            $query->andWhere('A.IsHospital=:IsHospital:', ['IsHospital' => $data['IsHospital']]);
            $query->join(HospitalOfAptitude::class, 'HA.OrganizationId=A.OrganizationId', 'HA', 'left');
        }
        //搜索医生
        if (!empty($data['DoctorId']) && isset($data['DoctorId'])) {
            $columns .= ',DA.DoctorId,DA.Certificate,DA.Front,DA.Reverse,U.Name as DoctorName';
            $query->andWhere('A.DoctorId=:DoctorId:', ['DoctorId' => $data['DoctorId']]);
            $query->join(DoctorOfAptitude::class, 'DA.OrganizationId=A.OrganizationId and DA.DoctorId=A.DoctorId', 'DA', 'left');
            $query->Join(User::class, 'U.Id=DoctorId', 'U', 'left');
        }
        //搜索设备
        if (!empty($data['EquipmentId']) && isset($data['EquipmentId'])) {
            $columns .= ',EA.Number,EA.Manufacturer,EA.VoucherImages';
            $query->andWhere("A.EquipmentId=:EquipmentId:", ['EquipmentId' => $data['EquipmentId']]);
            $query->join(EquipmentOfAptitude::class, 'EA.OrganizationId=A.OrganizationId and EA.EquipmentId=A.EquipmentId', 'EA', 'left');
        }
        //科室
        if (!empty($data['SectionId']) && isset($data['SectionId'])) {
            $columns .= ',S.Name as SectionName';
            $query->andWhere("A.SectionId=:SectionId:", ['SectionId' => $data['SectionId']]);
            $query->Join(Section::class, 'O.Id=SectionId', 'S', 'left');
        }
        //套餐
        if (!empty($data['ComboId']) && isset($data['ComboId'])) {
            $columns .= ',C.Name as ComboName,C.Price,C.Intro,C.CreateTime';
            $query->andWhere("A.ComboId=:ComboId:", ['ComboId' => $data['ComboId']]);
            $query->join(Combo::class, 'C.Id=A.ComboId', 'C', 'left');
        }
        //商户号
        if (!empty($data['MerchantCode']) && isset($data['MerchantCode'])) {
            $query->andWhere("O.MerchantCode=:MerchantCode:", ['MerchantCode' => $data['MerchantCode']]);
        }
        //商户名
        if (!empty($data['Name']) && isset($data['Name'])) {
            $query->andWhere("O.Name=:Name:", ['Name' => $data['Name']]);
        }
        //状态
        if (!empty($data['Status']) && isset($data['Status'])) {
            $query->andWhere("A.Status=:Status:", ['Status' => $data['Status']]);
        }
        $query->columns($columns);
        $query->Join(Organization::class, 'O.Id=OrganizationId', 'O', 'left');

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
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $response->setJsonContent($result);
        return $response;
    }

    /**
     * 医院共享申请
     */
    public function hospitalShowAction()
    {
        $response = new Response();
        $aptitude = HospitalOfAptitude::findFirst(sprintf('OrganizationId=%d', $this->user->OrganizationId));
        $hospital = Organization::findFirst(sprintf('Id=%d', $this->user->OrganizationId));
        if ($aptitude) {
            $result = $aptitude->toArray();
        }
        $result['Verifyed'] = $hospital->Verifyed;
        $response->setJsonContent($result);
        return $response;
    }

    /**
     * 医院端科室共享列表
     */
    public function sectionShowAction()
    {
        $response = new Response();
        $data = $this->request->getPost();

        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $aptitude = OrganizationAndSection::query();
        $aptitude->where('OrganizationId=:OrganizationId:');
        $bind['OrganizationId'] = $this->user->OrganizationId;
        if (!empty($data['UnShare']) && isset($data['UnShare'])) {
            $aptitude->andWhere('Share in (1,4)');
        } else {
            $aptitude->andWhere('Share != 1');
        }
        $aptitude->bind($bind);
        $paginator = new QueryBuilder(
            [
                "builder" => $aptitude->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        $sections = Section::query()
            ->columns('Id,Name')
            ->inWhere('Id', array_column($datas, 'SectionId'))
            ->execute()
            ->toArray();
        $sections_new = [];
        foreach ($sections as $v) {
            $sections_new[$v['Id']] = $v['Name'];
        }
        foreach ($datas as &$data) {
            $data['SectionName'] = $sections_new[$data['SectionId']];
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $response->setJsonContent($result);
        return $response;
    }

    /**
     * 医院端医生共享申请
     */
    public function doctorShowAction()
    {
        $response = new Response();
        $data = $this->request->getPost();

        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $aptitude = $this->modelsManager->createBuilder()
            ->columns(['U.Id', 'U.Name', 'OU.OrganizationId', 'OU.Title', 'OU.SectionId', 'OU.Skill', 'OU.Intro', 'OU.Direction', 'OU.Experience', 'OU.Image', 'OU.Share', 'S.Name as SectionName'])
            ->addFrom(OrganizationUser::class, 'OU')
            ->join(User::class, 'U.Id=OU.UserId', 'U', 'left')
            ->join(Section::class, 'S.Id=OU.SectionId', 'S', 'left')
            ->where('OU.OrganizationId=:OrganizationId:', ['OrganizationId' => $this->user->OrganizationId])
            ->andWhere('OU.IsDoctor=:IsDoctor:', ['IsDoctor' => 1]);
        if (!empty($data['UnShare']) && isset($data['UnShare'])) {
            $aptitude->inWhere('OU.Share', [1, 4]);
        } else {
            $aptitude->andWhere('OU.Share!=1');
        }
        if (!empty($data['SectionId']) && isset($data['SectionId'])) {
            $aptitude->andWhere('OU.SectionId=:SectionId:', ['SectionId' => $data['SectionId']]);
        }
        $paginator = new QueryBuilder(
            [
                "builder" => $aptitude,
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        foreach ($datas as &$data) {
            $data['TitleName'] = DoctorTitle::value($data['Title']);
            $data['Skill'] = strip_tags($data['Skill']);
            $data['Intro'] = strip_tags($data['Intro']);
            $data['Direction'] = strip_tags($data['Direction']);
            $data['Experience'] = strip_tags($data['Experience']);
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $response->setJsonContent($result);
        return $response;
    }

    /**
     * 医院端设备共享申请
     */
    public function equipmentShowAction()
    {
        $response = new Response();
        $data = $this->request->getPost();
        $organizationId = $this->user->OrganizationId;
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $aptitude = OrganizationAndEquipment::query();
        $aptitude->where('OrganizationId=:OrganizationId:');
        $bind['OrganizationId'] = $organizationId;
        if (!empty($data['UnShare']) && isset($data['UnShare'])) {
            $aptitude->andWhere('Share in (1,4)');
        } else {
            $aptitude->andWhere('Share != 1');
        }
        if (!empty($data['SectionId'] && isset($data['SectionId']))) {
            $aptitude->andWhere('SectionId=:SectionId:');
            $bind['SectionId'] = $data['SectionId'];
        }
        $aptitude->bind($bind);
        $paginator = new QueryBuilder(
            [
                "builder" => $aptitude->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        $equipmentNames = Equipment::query()
            ->columns('Id,Name')
            ->inWhere('Id', array_column($datas, 'EquipmentId'))
            ->execute()
            ->toArray();
        $equipmentNames_new = [];
        foreach ($equipmentNames as $v) {
            $equipmentNames_new[$v['Id']] = $v['Name'];
        }
        $equipments = EquipmentOfAptitude::find(sprintf('OrganizationId=%d', $organizationId))->toArray();
        $equipments_new = [];
        foreach ($equipments as $v) {
            $equipments_new[$v['EquipmentId']] = ['Number' => $v['Number'], 'Manufacturer' => $v['Manufacturer']];
        }
        $pictures = EquipmentPicture::query()
            ->where('OrganizationId=' . $organizationId)
            ->execute()
            ->toArray();
        $pictures_new = [];
        foreach ($pictures as $picture) {
            $pictures_new[$picture['EquipmentId']][] = $picture['Image'];
        }
        foreach ($datas as &$data) {
            $data['EquipmentName'] = $equipmentNames_new[$data['EquipmentId']];
            $data['VoucherNumber'] = $equipments_new[$data['EquipmentId']]['Number'];
            $data['Manufacturer'] = $equipments_new[$data['EquipmentId']]['Manufacturer'];
            $data['VoucherImages'] = $pictures_new[$data['EquipmentId']];
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $response->setJsonContent($result);
        return $response;
    }

    /**
     * 医院端套餐共享申请
     */
    public function comboShowAction()
    {
        $data = $this->request->getPost();

        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $aptitude = Combo::query()->where(sprintf('OrganizationId=%d', $this->session->get('auth')['OrganizationId']));
        if (!empty($data['UnShare']) && isset($data['UnShare'])) {
            $aptitude->inWhere('Share', [1, 4]);
        } else {
            $aptitude->andWhere('Share != 1');
        }
        $paginator = new QueryBuilder(
            [
                "builder" => $aptitude->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }

    /**
     * 删除申请
     */
    public function delAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            $organizationId = $this->user->OrganizationId;
            switch ($data['Way']) {
                case 'Doctor':
                    //医生
                    $target = OrganizationUser::findFirst([
                        'conditions' => 'OrganizationId=?0 and UserId=?1',
                        'bind'       => [$organizationId, $data['DoctorId']],
                    ]);
                    if (!$target) {
                        throw $exception;
                    }
                    $target->Share = OrganizationUser::SHARE_CLOSED;
                    $apply = ApplyOfShare::findFirst([
                        'conditions' => 'OrganizationId=?0 and DoctorId=?1',
                        'bind'       => [$organizationId, $data['DoctorId']],
                    ]);
                    $doctorOfAptitude = DoctorOfAptitude::findFirst([
                        'conditions' => "OrganizationId=:OrganizationId: and DoctorId=:DoctorId:",
                        "bind"       => ['OrganizationId' => $organizationId, 'DoctorId' => $data['DoctorId']],
                    ]);
                    if ($doctorOfAptitude) {
                        $doctorOfAptitude->delete();
                    }
                    break;
                case 'Section':
                    //科室
                    $target = OrganizationAndSection::findFirst([
                        'conditions' => 'OrganizationId=?0 and SectionId=?1',
                        'bind'       => [$organizationId, $data['SectionId']],
                    ]);
                    if (!$target) {
                        throw $exception;
                    }
                    $target->Share = OrganizationAndSection::SHARE_CLOSED;
                    $apply = ApplyOfShare::findFirst([
                        'conditions' => 'OrganizationId=?0 and SectionId=?1',
                        'bind'       => [$organizationId, $data['SectionId']],
                    ]);
                    break;
                default:
                    //套餐
                    $target = Combo::findFirst(sprintf('Id=%d', $data['ComboId']));
                    if (!$target) {
                        throw $exception;
                    }
                    $target->Share = Combo::SHARE_CLOSED;
                    $apply = ApplyOfShare::findFirst([
                        'conditions' => 'OrganizationId=?0 and ComboId=?1',
                        'bind'       => [$organizationId, $data['ComboId']],
                    ]);
            }
            if ($target->save() === false) {
                $exception->loadFromModel($target);
                throw $exception;
            }
            if ($apply) {
                $apply->delete();
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
     * 启用Share 由 5->2
     */
    public function openAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            $organizationId = $this->user->OrganizationId;
            switch ($data['Way']) {
                case 'Doctor':
                    //医生
                    $target = OrganizationUser::findFirst([
                        'conditions' => 'OrganizationId=?0 and UserId=?1',
                        'bind'       => [$organizationId, $data['DoctorId']],
                    ]);
                    if (!$target || $target->Share !== OrganizationUser::SHARE_PAUSE) {
                        throw $exception;
                    }
                    $target->Share = OrganizationUser::SHARE_SHARE;
                    break;
                case 'Section':
                    //科室
                    $target = OrganizationAndSection::findFirst([
                        'conditions' => 'OrganizationId=?0 and SectionId=?1',
                        'bind'       => [$organizationId, $data['SectionId']],
                    ]);
                    if (!$target || $target->Share !== OrganizationAndSection::SHARE_PAUSE) {
                        throw $exception;
                    }
                    $target->Share = OrganizationAndSection::SHARE_SHARE;
                    break;
                default:
                    //套餐
                    $target = Combo::findFirst(sprintf('Id=%d', $data['ComboId']));
                    if (!$target || $target->Share !== Combo::SHARE_PAUSE) {
                        throw $exception;
                    }
                    $target->Share = Combo::SHARE_SHARE;
            }
            if ($target->save() === false) {
                $exception->loadFromModel($target);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }
}