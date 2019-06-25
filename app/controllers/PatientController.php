<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 2:32 PM
 */

namespace App\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\illness\RelationDoctorAndPatient;
use App\Libs\illness\RelationOrganizationAndPatient;
use App\Libs\user\ID;
use App\Models\Illness;
use App\Models\OrganizationUser;
use App\Models\Patient;
use App\Models\FileCreateAttribute;
use App\Models\PatientAndIllnessFileCreated;
use App\Models\CaseBook;
use App\Models\Organization;
use App\Models\PatientAndIllness;
use App\Validators\IDCardNo;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Paginator\Adapter\QueryBuilder;

class PatientController extends Controller
{
    public function initialize()
    {
        $auth = $this->session->get('auth');
        if (!$auth['Identification']['Hou']) {
            throw new LogicException('请学习并取得认证', Status::BadRequest);
        }

    }

    /**
     * 机构新建患者
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            /** @var Patient $patient */
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }

            $data = $this->request->getPost();
            $patient = new Patient();
            /** @var OrganizationUser $organizationUser */
            $organizationUser = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$this->session->get('auth')['OrganizationId'], $this->session->get('auth')['Id'],],
            ]);
            /** @var Illness $illness */
            $illness = Illness::findFirst(sprintf('Id=%d', $data['IllnessId']));
            if (!$organizationUser || !$illness) {
                throw $exception;
            }

            $Id = new ID($data['IDnumber']);
            $data['Age'] = $Id->age();
            $data['Gender'] = $Id->gender() == "男" ? Patient::GENDER_MALE : Patient::GENDER_LADY;

            if (!$patient->save($data)) {
                $exception->loadFromModel($patient);
                throw $exception;
            }

            $relationDoctorAndPatient = new RelationDoctorAndPatient($organizationUser, $patient, $illness, true);
            $relationDoctorAndPatient->create();

            $relationOrganizationAndPatient = new RelationOrganizationAndPatient($organizationUser, $patient);
            $relationOrganizationAndPatient->create();
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
     * 获取患者基本信息
     */
    public function readAction()
    {
        /** @var Patient $patient */
        $patient = Patient::findFirst(['conditions' => 'IDnumber=?0', 'bind' => [$this->request->get('IDnumber')]]);
        /** @var Illness $illness */
        $illness = Illness::findFirst(sprintf('Id=%d', $this->request->get('IllnessId')));
        $result = [];
        if ($patient) {
            $ID = new ID($patient->IDnumber);
            $result = $patient->toArray();
            $result['Age'] = $ID->age();
            //建档机构
            $result['OrganizationName'] = '';
            if ($illness) {
                $patientAndIllness = PatientAndIllness::findFirst(['conditions' => 'IllnessId=?0 and IsFileCreate=?1', 'bind' => [$illness->Id, PatientAndIllness::IsFileCreate_Yes]]);
                $result['OrganizationName'] = $patientAndIllness ? $patientAndIllness->Organization->Name : '';
            }
        }
        $this->response->setJsonContent($result);
    }

    /**
     * 获取患者所对应疾病的建档数据
     */
    public function patientFileAction()
    {
        $data = $this->request->get();
        /** @var Illness $illness */
        $illness = Illness::findFirst(sprintf('Id=%d', $data['IllnessId']));
        $result = [];
        if ($illness) {
            $fileCreate = $this->modelsManager->createBuilder()
                ->columns(['A.Name', 'P.Value'])
                ->addFrom(PatientAndIllnessFileCreated::class, 'P')
                ->leftJoin(FileCreateAttribute::class, '', 'A')
                ->where(sprintf('IllnessId=%d', $illness->Id))
                ->andWhere('P.IDnumber=:IDnumber:', ['IDnumber' => $data['IDnumber']])
                ->getQuery()->execute();
            $result['IllnessName'] = $illness->Name;
            $result['FileCreate'] = $fileCreate;
        }
        $this->response->setJsonContent($result);
    }

    /**
     * 验证身份证、姓名是否需要新建
     */
    public function needCreateAction()
    {
        $validator = new Validation();
        $validator->rules('IDnumber', [
            new PresenceOf(['message' => '请输入密码']),
            new IDCardNo(['message' => '身份证号码错误']),
        ]);
        $validator->rules('Name', [
            new PresenceOf(['message' => '姓名不能为空']),

        ]);
        $validator->rules('IllnessId', [
            new Digit(['message' => '疾病出错']),
        ]);
        $exception = new ParamException(Status::BadRequest);
        $ret = $validator->validate($this->request->getPost());
        if (count($ret) > 0) {
            $exception->loadFromMessage($ret);
            throw $exception;
        }
        $data = $this->request->getPost();

        /** @var Patient $patient */
        $patient = Patient::findFirst(['conditions' => 'IDnumber=?0', 'bind' => [$data['IDnumber']]]);
        if ($patient && $patient->Name != $data['Name']) {
            throw new LogicException('姓名输入错误', Status::BadRequest);
        }

        if (!$patient) {
            //不存在
            $this->response->setJsonContent(['NeedCreate' => true]);
        } else {
            try {
                $this->db->begin();
                //都正确则建立关系
                /** @var OrganizationUser $organizationUser */
                $organizationUser = OrganizationUser::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1',
                    'bind'       => [$this->session->get('auth')['OrganizationId'], $this->session->get('auth')['Id'],],
                ]);
                /** @var Illness $illness */
                $illness = Illness::findFirst(sprintf('Id=%d', $data['IllnessId']));
                if (!$organizationUser || !$illness) {
                    throw $exception;
                }

                $patientAndIllness = PatientAndIllness::findFirst(['conditions' => 'IDnumber=?0 and IllnessId=?1 and IsFileCreate=?2', 'bind' => [$patient->IDnumber, $illness->Id, PatientAndIllness::IsFileCreate_Yes]]);
                $relationDoctorAndPatient = new RelationDoctorAndPatient($organizationUser, $patient, $illness, $patientAndIllness ? false : true);
                $relationDoctorAndPatient->create();

                $relationOrganizationAndPatient = new RelationOrganizationAndPatient($organizationUser, $patient);
                $relationOrganizationAndPatient->create();

                $this->response->setJsonContent(['NeedCreate' => false]);
                $this->db->commit();
            } catch (ParamException $e) {
                $this->db->rollback();
                throw $e;
            }
        }
    }

    /**
     * 根据身份证获取年龄，性别
     */
    public function getIdMessageAction()
    {
        $validator = new Validation();
        $validator->rules('IDnumber', [
            new PresenceOf(['message' => '请输入密码']),
            new IDCardNo(['message' => '身份证号码错误']),
        ]);
        $exception = new ParamException(Status::BadRequest);
        $ret = $validator->validate($this->request->getPost());
        if (count($ret) > 0) {
            $exception->loadFromMessage($ret);
            throw $exception;
        }
        $id = $this->request->getPost('IDnumber');
        $Id = new ID($id);
        $this->response->setJsonContent(['Age' => $Id->age(), 'Gender' => $Id->gender()]);
    }

    /**
     * 该医生患者列表
     */
    public function patientListAction()
    {
        $auth = $this->session->get('auth');
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['I.IllnessId', 'P.IDnumber', 'P.Name', 'P.Gender', 'P.Weight', 'O.Name as OrganizationName'])
            ->addFrom(PatientAndIllness::class, 'I')
            ->leftJoin(Patient::class, 'P.IDnumber=I.IDnumber', 'P')
            ->leftJoin(PatientAndIllness::class, 'F.IDnumber=P.IDnumber and F.IsFileCreate=' . PatientAndIllness::IsFileCreate_Yes, 'F')
            ->leftJoin(Organization::class, 'O.Id=F.OrganizationId', 'O')
            ->where('I.OrganizationId=:OrganizationId:', ['OrganizationId' => $auth['OrganizationId']])
            ->andWhere('I.DoctorId=:DoctorId:', ['DoctorId' => $auth['Id']])
            ->andWhere('I.IllnessId=:IllnessId:', ['IllnessId' => $data['IllnessId']]);
        //患者姓名
        if (isset($data['Name']) && !empty($data['Name'])) {
            $query->andWhere('P.Name=:Name:', ['Name' => $data['Name']]);
        }
        //患者身份证
        if (isset($data['IDnumber']) && !empty($data['IDnumber'])) {
            $query->andWhere('P.IDnumber=:IDnumber:', ['IDnumber' => $data['IDnumber']]);
        }
        $query->orderBy('I.Updated desc,I.Created desc');
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
            $data['GenderName'] = Patient::GENDER_NAME[$data['Gender']];
            $ID = new ID($data['IDnumber']);
            $data['Age'] = $ID->age();
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 病例列表
     */
    public function patientCaseListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->addFrom(CaseBook::class, 'C')
            ->where('C.IDnumber=:IDnumber:', ['IDnumber' => $data['IDnumber']])
            ->orderBy('C.Created desc');
        if (isset($data['Created']) && is_numeric($data['Created'])) {
            $query->andWhere(sprintf('Created<%d', $data['Created']));
            $page = 1;
        }
        if (isset($data['IllnessId']) && is_numeric($data['IllnessId'])) {
            $query->andWhere('C.IllnessId=:IllnessId:', ['IllnessId' => $data['IllnessId']]);
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
        $westernSyndromeId = 0;
        if (count($datas)) {
            foreach ($datas as $k => &$data) {
                $data['Content'] = unserialize($data['Content']);
                if ($k == 0) {
                    $westernSyndromeId = reset(\App\Libs\illness\Illness::getSyndromeIds([$data['Content']->Treatment->Id], false));
                }
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['WesternSyndromeId'] = $westernSyndromeId;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 得到患者的最后一个方案
     */
    public function getLastCasebookAction()
    {
        /** @var CaseBook $casebook */
        $casebook = CaseBook::findFirst([
            'conditions' => 'IDnumber=?0 and IllnessId=?1',
            'bind'       => [$this->request->get('IDnumber'), $this->request->get('IllnessId') ?: Illness::Rheumatism],
            'order'      => 'Created desc',
        ]);
        $result = [];
        if ($casebook) {
            $result = $casebook->toArray();
            $result['Content'] = unserialize($casebook->Content);
        }
        $this->response->setJsonContent($result);
    }
}