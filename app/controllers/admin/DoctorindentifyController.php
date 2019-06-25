<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/12/20
 * Time: 下午6:21
 */

namespace App\Admin\Controllers;


use App\Enums\DoctorTitle;
use App\Enums\PharmacistTitle;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Sphinx;
use App\Models\DoctorIdentify;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\UserSignature;
use Phalcon\Paginator\Adapter\QueryBuilder;

class DoctorindentifyController extends Controller
{
    /**
     * 医生职业资格证审核列表
     */
    public function listAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns(['D.OrganizationId', 'D.UserId', 'D.Image', 'D.Status', 'D.Created', 'D.Number', 'D.IdentifyType', 'D.MedicineClass', 'O.Name as OrganizationName', 'U.Name as DoctorName', "if(S.UserId is null,'未设置','已设置') as Signed", "OU.Title"])
            ->addFrom(DoctorIdentify::class, 'D')
            ->join(OrganizationUser::class, 'OU.OrganizationId=D.OrganizationId and OU.UserId=D.UserId', 'OU')
            ->leftJoin(Organization::class, 'O.Id=D.OrganizationId', 'O')
            ->leftJoin(User::class, 'U.Id=D.UserId', 'U')
            ->leftJoin(UserSignature::class, 'S.UserId=D.UserId', 'S')
            ->orderBy('D.Created desc');
        //医生姓名
        if (!empty($data['DoctorName']) && isset($data['DoctorName'])) {
            $sphinx = new Sphinx($this->sphinx, 'user');
            $name = $sphinx->match($data['DoctorName'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('U.Id', $ids);
            } else {
                $query->inWhere('U.Id', [-1]);
            }
        }
        //医院名称
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
        //审核状态
        if (isset($data['Status']) && is_numeric($data['Status'])) {
            $query->andWhere('D.Status=:Status:', ['Status' => $data['Status']]);
        }
        //认证职业
        if (isset($data['IdentifyType']) && is_numeric($data['IdentifyType'])) {
            $query->andWhere('D.IdentifyType=:IdentifyType:', ['IdentifyType' => $data['IdentifyType']]);
        }
        //认证职业
        if (isset($data['MedicineClass']) && is_numeric($data['MedicineClass'])) {
            $query->andWhere('D.MedicineClass=:MedicineClass:', ['MedicineClass' => $data['MedicineClass']]);
        }

        //电子签名状态
        if (isset($data['Signed']) && is_numeric($data['Signed'])) {
            switch ($data['Signed']) {
                case 1:
                    $query->andWhere('S.UserId is not null');
                    break;
                case 2:
                    $query->andWhere('S.UserId is null');
                    break;
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
        foreach ($datas as &$data) {
            $data['TitleName'] = $data['IdentifyType'] == DoctorIdentify::IdentifyType_Physician ? DoctorTitle::value($data['Title']) : PharmacistTitle::value($data['Title']);
            $data['MedicineClassName'] = $data['IdentifyType'] == DoctorIdentify::IdentifyType_Physician ? DoctorIdentify::MedicineClassName[$data['MedicineClass']] : DoctorIdentify::MedicineClassPrescriptionName[$data['MedicineClass']];
            if ($data['IdentifyType'] == DoctorIdentify::IdentifyType_Physician) {
                $data['IdentifyTypeName'] = DoctorIdentify::MedicineClassName[$data['MedicineClass']] . '医师';
            } else {
                $data['IdentifyTypeName'] = DoctorIdentify::MedicineClassPrescriptionName[$data['MedicineClass']] . '药师';
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    public function readAction()
    {
        /** @var DoctorIdentify $doctorIdentify */
        $doctorIdentify = DoctorIdentify::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1',
            'bind'       => [$this->request->get('OrganizationId', 'int'), $this->request->get('UserId', 'int')],
        ]);
        $result = $doctorIdentify->toArray();
        if ($result) {
            /** @var OrganizationUser $organizationUser */
            $organizationUser = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$doctorIdentify->OrganizationId, $doctorIdentify->UserId],
            ]);
            /** @var User $user */
            $user = User::findFirst(sprintf('Id=%d', $doctorIdentify->UserId));
            $result['DoctorName'] = $user->Name;
            $result['IDnumber'] = $user->IDnumber;
            $result['Sex'] = $user->Sex == 1 ? '男' : ($user->Sex == 2 ? '女' : '其他');
            $result['Phone'] = $user->Phone;
            $result['OrganizationTitleName'] = $organizationUser->IsDoctor == OrganizationUser::IS_DOCTOR_YES ? DoctorTitle::value($organizationUser->Title) : PharmacistTitle::value($organizationUser->Title);
            $result['OrganizationName'] = $doctorIdentify->Organization->Name;

            if ($result['IdentifyType'] == DoctorIdentify::IdentifyType_Physician) {
                $result['IdentifyTypeName'] = DoctorIdentify::MedicineClassName[$result['MedicineClass']] . '医师';
            } else {
                $result['Image'] = $organizationUser->Image;
                $result['IdentifyTypeName'] = DoctorIdentify::MedicineClassPrescriptionName[$result['MedicineClass']] . '药师';
            }
        }
        $this->response->setJsonContent($result);
    }

    public function updateAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            /** @var DoctorIdentify $doctorIdentify */
            $doctorIdentify = DoctorIdentify::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$this->request->getPut('OrganizationId', 'int'), $this->request->getPut('UserId', 'int')],
            ]);
            if (!$doctorIdentify) {
                throw $exception;
            }
            /** @var OrganizationUser $doctor */
            $doctor = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$doctorIdentify->OrganizationId, $doctorIdentify->UserId],
            ]);
            if (!$doctor) {
                throw $exception;
            }
            $doctorIdentify->Status = $this->request->getPut('Status', 'int');
            $doctorIdentify->Reason = $this->request->getPut('Reason') ?: '';
            $doctorIdentify->AuditTime = time();
            if ($doctorIdentify->save() === false) {
                $exception->loadFromModel($doctorIdentify);
                throw $exception;
            }
            switch ($doctorIdentify->Status) {
                case DoctorIdentify::STATUS_SUCCESS:
                    $doctor->Identified = OrganizationUser::IDENTIFIED_ON;
                    break;
                case DoctorIdentify::STATUS_REFUSE:
                    $doctor->Identified = OrganizationUser::IDENTIFIED_OFF;
                    break;
            }
            if ($doctor->save() === false) {
                $exception->loadFromModel($doctor);
                throw $exception;
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
}