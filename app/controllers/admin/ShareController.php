<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/21
 * Time: 上午11:31
 */

namespace App\Admin\Controllers;

use App\Enums\DoctorTitle;
use App\Enums\HospitalLevel;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Sphinx;
use App\Models\ApplyOfShare;
use App\Models\Combo;
use App\Models\DoctorOfAptitude;
use App\Models\EquipmentOfAptitude;
use App\Models\HospitalOfAptitude;
use App\Models\Organization;
use App\Models\OrganizationAndEquipment;
use App\Models\OrganizationAndSection;
use App\Models\EquipmentPicture;
use App\Models\OrganizationUser;
use App\Models\Section;
use App\Models\Staff;
use App\Models\StaffShareLog;
use App\Models\User;
use Phalcon\Http\Response;
use Phalcon\Paginator\Adapter\QueryBuilder;

class ShareController extends Controller
{
    /**
     * 共享申请的列表
     * @return Response
     */
    public function listAction()
    {
        $data = $this->request->getPost();
        $auth = $this->session->get('auth');
        $hospitalId = $auth['HospitalId'];

        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $columns = 'A.Id,A.OrganizationId,A.IsHospital,A.SectionId,A.DoctorId,A.EquipmentId,A.ComboId,A.Status,A.Remark,A.StartTime,A.EndTime,O.Id as OrganizationId,O.Name as HospitalName,O.MerchantCode,O.Contact,O.LevelId';
        $query = $this->modelsManager->createBuilder();

        $query->addFrom(ApplyOfShare::class, 'A');
        $query->join(Organization::class, 'O.Id=A.OrganizationId', 'O', 'left');
        $query->join(Section::class, 'S.Id=A.SectionId', 'S', 'left');
        if ($hospitalId != Organization::PEACH) {
            $query->andWhere("A.OrganizationId=:OrganizationId:", ['OrganizationId' => $hospitalId]);
        }
        //搜索资质
        if (!empty($data['IsHospital']) && isset($data['IsHospital'])) {
            $columns .= ',HA.BusinessLicense,HA.Level,HA.Front,HA.Reverse';
            $query->columns($columns . ',HA.BusinessLicense,HA.Level,HA.Front,HA.Reverse');
            $query->andWhere('A.IsHospital=:IsHospital:', ['IsHospital' => $data['IsHospital']]);
            $query->join(HospitalOfAptitude::class, 'HA.OrganizationId=A.OrganizationId', 'HA', 'left');
        }
        //科室
        if (!empty($data['IsSection']) && isset($data['IsSection'])) {
            $query->andWhere('A.SectionId!=:IsSection:', ['IsSection' => 0]);
            $columns .= ',S.Name as SectionName';
        }
        //医生
        if (!empty($data['IsDoctor']) && isset($data['IsDoctor'])) {
            $query->andWhere('A.DoctorId!=:IsDoctor:', ['IsDoctor' => 0]);
            $columns .= ',DA.DoctorId,DA.Certificate,DA.Front,DA.Reverse,U.Name as DoctorName,OU.Title,SU.Name as SectionName';
            $query->join(DoctorOfAptitude::class, 'DA.OrganizationId=A.OrganizationId and DA.DoctorId=A.DoctorId', 'DA', 'left');
            $query->join(User::class, 'U.Id=A.DoctorId', 'U', 'left');
            $query->join(OrganizationUser::class, 'OU.OrganizationId=DA.OrganizationId and OU.UserId=DA.DoctorId', 'OU', 'left');
            $query->join(Section::class, 'SU.Id=OU.SectionId', 'SU', 'left');
        }
        //设备
        if (!empty($data['IsEquipment']) && isset($data['IsEquipment'])) {
            $query->andWhere('A.EquipmentId!=:IsEquipment:', ['IsEquipment' => 0]);
            $columns .= ',EA.Number,EA.Manufacturer';
            $query->join(EquipmentOfAptitude::class, 'EA.OrganizationId=A.OrganizationId and EA.EquipmentId=A.EquipmentId', 'EA', 'left');
        }
        //套餐
        if (!empty($data['IsCombo']) && isset($data['IsCombo'])) {
            $query->andWhere('A.ComboId!=:IsCombo:', ['IsCombo' => 0]);
            $columns .= ',C.Name as ComboName,C.Price,C.Intro,C.CreateTime';
            $query->join(Combo::class, 'C.Id=A.ComboId', 'C', 'left');
            //套餐名字搜索
            if (!empty($data['ComboName']) && isset($data['ComboName'])) {
                $sphinx = new Sphinx($this->sphinx, 'combo');
                $name = $sphinx->match($data['ComboName'], 'name')->fetchAll();
                $ids = array_column($name ? $name : [], 'id');
                if (count($ids)) {
                    $query->inWhere('C.Id', $ids);
                } else {
                    $query->inWhere('C.Id', [-1]);
                }
            }
        }
        //搜索医生
        if (!empty($data['DoctorId']) && isset($data['DoctorId'])) {
            $query->andWhere('A.DoctorId=:DoctorId:', ['DoctorId' => $data['DoctorId']]);
        }
        //搜索设备
        if (!empty($data['EquipmentId']) && isset($data['EquipmentId'])) {
            $query->andWhere("A.EquipmentId=:EquipmentId:", ['EquipmentId' => $data['EquipmentId']]);
        }
        //科室
        if (!empty($data['SectionName']) && isset($data['SectionName'])) {
            $sphinx = new Sphinx($this->sphinx, 'section');
            $name = $sphinx->match($data['SectionName'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                if (!empty($data['IsDoctor']) && isset($data['IsDoctor'])) {
                    $query->inWhere('SU.Id', $ids);
                } else {
                    $query->inWhere('S.Id', $ids);
                }
            } else {
                if (!empty($data['IsDoctor']) && isset($data['IsDoctor'])) {
                    $query->inWhere('SU.Id', [-1]);
                } else {
                    $query->inWhere('S.Id', [-1]);
                }
            }
        }
        //商户号
        if (!empty($data['MerchantCode']) && isset($data['MerchantCode'])) {
            $query->andWhere("O.MerchantCode=:MerchantCode:", ['MerchantCode' => $data['MerchantCode']]);
        }
        //商户名
        if (!empty($data['HospitalName']) && isset($data['HospitalName'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['HospitalName'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('O.Id', $ids);
            } else {
                $query->inWhere('O.Id', [-1]);
            }
        }
        //状态
        if (isset($data['Status']) && is_numeric($data['Status'])) {
            $query->andWhere("A.Status=:Status:", ['Status' => $data['Status']]);
        }
        $query->columns($columns);
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
            $data['LevelName'] = HospitalLevel::value($data['LevelId']);
            if (isset($data['Title'])) {
                $data['TitleName'] = DoctorTitle::value($data['Title']);
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        return $this->response->setJsonContent($result);
    }

    /**
     * 读取详情
     */
    public function readAction()
    {
        $id = $this->request->get('Id');
        $apply = ApplyOfShare::findFirst(sprintf('Id=%d', $id));
        if (!$apply) {
            return $this->response->setStatusCode(Status::BadRequest);
        }
        $aptitude = [];
        if ($apply->IsHospital) {
            $hospitalOfAptitude = HospitalOfAptitude::findFirst(sprintf('OrganizationId=%d', $apply->OrganizationId));
            $aptitude = $hospitalOfAptitude ? $hospitalOfAptitude->toArray() : [];
        }
        if ($apply->DoctorId) {
            $doctorOfAptitude = DoctorOfAptitude::findFirst(["OrganizationId=:OrganizationId: and DoctorId=:DoctorId:", "bind" => ['OrganizationId' => $apply->OrganizationId, 'DoctorId' => $apply->DoctorId]]);
            $aptitude = $doctorOfAptitude ? $doctorOfAptitude->toArray() : [];
            $user = OrganizationUser::findFirst([
                "OrganizationId=?0 and UserId=?1",
                "bind" => [$apply->OrganizationId, $apply->DoctorId],
            ]);
            $doctor = $user ? $user->toArray() : [];
            unset($doctor['Id'], $doctor['Password']);
            $doctor['Name'] = $user->User->Name;
            $doctor['TitleName'] = DoctorTitle::value($doctor['Title']);
            $doctor['SectionName'] = $user->Section->Name;
            $aptitude = array_merge($aptitude, $doctor);
        }
        if ($apply->EquipmentId) {
            $equipmentOfAptitude = EquipmentOfAptitude::findFirst(["OrganizationId=:OrganizationId: and EquipmentId=:EquipmentId:", "bind" => ['OrganizationId' => $apply->OrganizationId, 'EquipmentId' => $apply->EquipmentId]]);
            $aptitude = $equipmentOfAptitude ? $equipmentOfAptitude->toArray() : [];
            $equipment = OrganizationAndEquipment::findFirst(["OrganizationId=:OrganizationId: and EquipmentId=:EquipmentId:", "bind" => ['OrganizationId' => $apply->OrganizationId, 'EquipmentId' => $apply->EquipmentId]]);
            $equipment_new = $equipment ? $equipment->toArray() : [];
            unset($equipment_new['Number']);
            $equipment_new['EquipmentName'] = $equipment->Equipment->Name;
            $equipmentPictures = EquipmentPicture::find(["OrganizationId=:OrganizationId: and EquipmentId=:EquipmentId:", "bind" => ['OrganizationId' => $apply->OrganizationId, 'EquipmentId' => $apply->EquipmentId]]);
            $equipmentPictures = $equipmentPictures ? array_column($equipmentPictures->toArray(), 'Image') : [];
            $equipment_new['EquipmentImages'] = $equipmentPictures;
            $aptitude = array_merge($aptitude, $equipment_new);
        }
        if ($apply->ComboId) {
            $combo = Combo::findFirst(sprintf('Id=%d', $apply->ComboId));
            $aptitude = $combo ? $combo->toArray() : [];
            unset($aptitude['Id'], $aptitude['Status']);
        }
        $result = array_merge($apply->toArray(), $aptitude);
        $result['HospitalName'] = $apply->Organization->Name;
        if ($apply->SectionId) {
            $result['SectionName'] = $apply->Section->Name;
            $result['SectionIntro'] = OrganizationAndSection::findFirst(["OrganizationId=:OrganizationId: and SectionId=:SectionId:", "bind" => ['OrganizationId' => $apply->OrganizationId, 'SectionId' => $apply->SectionId]])->Intro;
        }
        return $this->response->setJsonContent($result);
    }

    /**
     * 处理申请
     */
    public function verifyAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $this->db->begin();
                $id = $this->request->getPut('Id');
                $status = $this->request->getPut('Status');
                switch ($status) {
                    case ApplyOfShare::PASS:
                        $status_hospital = Organization::VERIFYED;
                        $status_section = OrganizationAndSection::SHARE_SHARE;
                        $status_doctor = OrganizationUser::SHARE_SHARE;
                        $status_equipment = OrganizationAndEquipment::SHARE_SHARE;
                        $status_combo = Combo::SHARE_SHARE;
                        break;
                    default:
                        $status_hospital = Organization::FAIL;
                        $status_section = OrganizationAndSection::SHARE_FAILED;
                        $status_doctor = OrganizationUser::SHARE_FAILED;
                        $status_equipment = OrganizationAndEquipment::SHARE_FAILED;
                        $status_combo = Combo::SHARE_FAILED;
                }

                $apply = ApplyOfShare::findFirst(sprintf('Id=%d', $id));
                if (!$apply) {
                    throw $exception;
                }
                $log = new StaffShareLog();
                $log->StaffId = $this->session->get('auth')['Id'];
                $log->ApplyId = $apply->Id;
                $log->StatusBefore = $apply->Status;
                $log->StatusAfter = $status;
                $log->Created = time();
                $apply->Status = $status;
                if ($apply->Status == ApplyOfShare::PASS) {
                    $apply->EndTime = time();
                }
                if ($apply->save() === false) {
                    $exception->loadFromModel($apply);
                    throw $exception;
                }
                $apply->refresh();
                //操作记录
                if ($log->save() === false) {
                    $exception->loadFromModel($log);
                    throw $exception;
                }
                $hospitalId = $apply->OrganizationId;
                $content = '成功';
                if ($apply->IsHospital) {
                    $organization = Organization::findFirst(sprintf('Id=%d', $hospitalId));
                    $organization->Verifyed = $status_hospital;
                    if ($organization->save() === false) {
                        $exception->loadFromModel($organization);
                        throw $exception;
                    }
                } elseif ($apply->SectionId) {
                    $section = OrganizationAndSection::findFirst(["OrganizationId=:OrganizationId: and SectionId=:SectionId:", "bind" => ["OrganizationId" => $hospitalId, "SectionId" => $apply->SectionId]]);
                    if (!$section) {
                        $apply->delete();
                        $content = '该医院已将该科室删除,现已清除此申请记录';
                    } else {
                        $section->Share = $status_section;
                        if ($section->save() === false) {
                            $exception->loadFromModel($section);
                            throw $exception;
                        }
                    }
                } elseif ($apply->DoctorId) {
                    $doctor = OrganizationUser::findFirst([
                        'conditions' => 'OrganizationId=?0 and UserId=?1',
                        'bind'       => [$apply->OrganizationId, $apply->DoctorId],
                    ]);
                    if (!$doctor) {
                        throw $exception;
                    }
                    $doctor->Share = $status_doctor;
                    if ($doctor->save() === false) {
                        $exception->loadFromModel($doctor);
                        throw $exception;
                    }
                } elseif ($apply->EquipmentId) {
                    $equipment = OrganizationAndEquipment::findFirst(["OrganizationId=:OrganizationId: and EquipmentId=:EquipmentId:", "bind" => ["OrganizationId" => $hospitalId, "EquipmentId" => $apply->EquipmentId]]);
                    if (!$equipment) {
                        $apply->delete();
                        $content = '该医院已将该设备删除,现已清除此申请记录';
                    } else {
                        $equipment->Share = $status_equipment;
                        if ($equipment->save() === false) {
                            $exception->loadFromModel($equipment);
                            throw $exception;
                        }
                    }
                } elseif ($apply->ComboId) {
                    $combo = Combo::findFirst(sprintf('Id=%d', $apply->ComboId));
                    if (!$combo) {
                        $apply->delete();
                        $content = '该医院已将该套餐删除,现已清除此申请记录';
                    } else {
                        $combo->Share = $status_combo;
                        $combo->Audit = ($status_combo == Combo::SHARE_SHARE ? Combo::Audit_PASS : Combo::Audit_FAILED);
                        if ($combo->save() === false) {
                            $exception->loadFromModel($combo);
                            throw $exception;
                        }
                    }
                }
                $this->db->commit();
                //更新sphinx共享科室
                if ($apply->Status == ApplyOfShare::PASS) {
                    $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'organization');
                    $result = $sphinx->where('=', (int)$apply->OrganizationId, 'id')->fetch();
                    $sphinx_data = [];
                    if ($apply->SectionId) {
                        if (!empty($result['sharesectionids'])) {
                            $sphinx_data['sharesectionids'] = explode(',', $result['sharesectionids']);
                        }
                        $sphinx_data['sharesectionids'][] = $apply->SectionId;
                    }
                    if (count($sphinx_data)) {
                        $sphinx->update($sphinx_data, $apply->OrganizationId);
                    }
                }
                $this->response->setJsonContent(['message' => $content]);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 共享操作记录列表
     */
    public function logsAction()
    {
        $query = $this->modelsManager->createBuilder()
            ->columns('L.Id,L.ApplyId,L.StaffId,L.StatusBefore,L.StatusAfter,L.Created,S.Name')
            ->addFrom(StaffShareLog::class, 'L')
            ->join(Staff::class, 'S.Id=L.StaffId', 'S', 'left')
            ->where('L.ApplyId=:ApplyId:', ['ApplyId' => $this->request->get('Id', 'int')])
            ->getQuery()
            ->execute();
        $this->response->setJsonContent($query);
    }
}