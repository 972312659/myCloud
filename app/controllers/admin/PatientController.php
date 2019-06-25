<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/11
 * Time: 4:14 PM
 */

namespace App\Admin\Controllers;

use App\Libs\Sphinx;
use App\Libs\user\ID;
use App\Models\Illness;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\FileCreateAttribute;
use App\Models\PatientAndIllnessFileCreated;
use App\Models\CaseBook;
use App\Models\PatientAndIllness;
use Phalcon\Paginator\Adapter\QueryBuilder;

class PatientController extends Controller
{
    public function listAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['P.IDnumber', 'P.Name', 'P.Weight', 'P.Gender', 'O.Name as OrganizationName'])
            ->addFrom(Patient::class, 'P')
            ->leftJoin(PatientAndIllness::class, "I.IDnumber=P.IDnumber and I.IllnessId={$data['IllnessId']} and I.IsFileCreate=" . PatientAndIllness::IsFileCreate_Yes, 'I')
            ->leftJoin(Organization::class, 'O.Id=I.OrganizationId', 'O');
        //医院名称
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('O.Id', $ids);
            } else {
                $query->inWhere('O.Id', [-1]);
            }
        }
        //患者姓名
        if (isset($data['PatientName']) && !empty($data['PatientName'])) {
            $query->andWhere('P.Name=:PatientName:', ['PatientName' => $data['PatientName']]);
        }
        //患者身份证
        if (isset($data['IDnumber']) && !empty($data['IDnumber'])) {
            $query->andWhere('P.IDnumber=:IDnumber:', ['IDnumber' => $data['IDnumber']]);
        }
        $query->orderBy('P.Created desc');
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
        if (count($datas)) {
            foreach ($datas as $k => &$data) {
                $data['Content'] = unserialize($data['Content']);
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }
}