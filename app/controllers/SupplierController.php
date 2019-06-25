<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/5/9
 * Time: 下午3:34
 * For: 供应商管理
 */

namespace App\Controllers;

use App\Enums\DoctorTitle;
use App\Enums\HospitalLevel;
use App\Exceptions\LogicException;
use App\Libs\Sphinx;
use App\Models\Combo;
use App\Models\Equipment;
use App\Models\EquipmentAndSection;
use App\Models\Location;
use App\Models\OrganizationAndEquipment;
use App\Models\OrganizationAndSection;
use App\Models\OrganizationCombo;
use App\Models\OrganizationSection;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\Section;
use App\Models\SupplierApply;
use App\Models\User;
use App\Enums\Status;
use App\Models\RuleOfShare;
use App\Models\Organization;
use App\Enums\OrganizationType;
use App\Exceptions\ParamException;
use Phalcon\Paginator\Adapter\QueryBuilder;

class SupplierController extends Controller
{
    /**
     * 创建供应商申请
     */
    public function applyAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $supplierApply = new SupplierApply();
                $data['HospitalId'] = $this->user->OrganizationId;
                if (empty($data['SalesmanId']) || !isset($data['SalesmanId'])) {
                    $data['SalesmanId'] = $this->user->Id;
                }
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $supplierApply = SupplierApply::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$supplierApply) {
                    throw $exception;
                }
                $data['Status'] = SupplierApply::STATUS_WAIT;
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data['Phone'] = trim($data['Phone']);
            $organization = Organization::findFirst([
                'conditions' => 'Phone=?0',
                'bind'       => [$data['Phone']],
            ]);
            if ($organization) {
                throw new LogicException('手机号码已被占用', Status::BadRequest);
            }
            $data['Password'] = substr($data['Phone'], -6, 6);
            if (!empty($data['Name']) && isset($data['Name'])) {
                $data['Name'] = trim($data['Name']);
            }
            if ($supplierApply->save($data) === false) {
                $exception->loadFromModel($supplierApply);
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

    /**
     * 申请列表
     */
    public function applyListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = SupplierApply::query()
            ->where('HospitalId=:HospitalId:');
        $bind['HospitalId'] = $this->user->OrganizationId;
        if (is_numeric($data['Status'])) {
            $query->andWhere('Status=:Status:');
            $bind['Status'] = $data['Status'];
        }
        if (isset($data['Name']) && !empty($data['Name'])) {
            $query->andWhere("Name like :Name:");
            $bind['Name'] = '%' . $data['Name'] . '%';
        }
        $query->bind($bind);
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        foreach ($datas as &$v) {
            $v['TypeName'] = OrganizationType::value($v['Type']);
            $v['LevelName'] = HospitalLevel::value($v['LevelId']);
            $v['StatusName'] = SupplierApply::STATUS_NAME[$v['Status']];
            if ($v['Status'] == SupplierApply::STATUS_UNPASS) {
                $v['StatusName'] .= ',原因：' . $v['Explain'];
            }
        }
        $fee = RuleOfShare::findFirst([
            'conditions' => 'OrganizationId=?0 and CreateOrganizationId=?1',
            'bind'       => [$this->user->OrganizationId, RuleOfShare::STYLE_PLATFORM],
        ])->Ratio;
        $result = [];
        $result['Data'] = $datas;
        $result['Fee'] = $fee;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        return $this->response->setJsonContent($result);
    }

    /**
     * 供应商详情
     */
    public function supplierReadAction()
    {
        $supplier = $this->modelsManager->createBuilder()
            ->columns([
                'O.MerchantCode', 'O.CreateTime', 'O.Contact', 'O.ContactTel', 'O.LevelId', 'O.ProvinceId', 'O.CityId', 'O.AreaId', 'O.Address', 'O.Type', 'O.Phone', 'O.ProvinceId', 'O.CityId', 'O.AreaId', 'O.Name', 'O.Lng', 'O.Lat',
                'R.MainId', 'R.MinorId', 'R.MainName', 'R.MinorName', 'R.SalesmanId', 'R.RuleId', 'R.MinorType',
                'S.Ratio', 'S.Type as ShareType', 'S.DistributionOut', 'SU.Name as SalesmanName',
                'U.IDnumber', 'LP.Name as Province', 'LC.Name as City', 'LA.Name as Area',
            ])
            ->addFrom(Organization::class, 'O')
            ->leftJoin(OrganizationRelationship::class, 'R.MinorId=O.Id', 'R')
            ->leftJoin(RuleOfShare::class, 'S.OrganizationId=R.MinorId and S.CreateOrganizationId=R.MainId', 'S')
            ->leftJoin(User::class, 'U.Phone=O.Phone', 'U')
            ->leftJoin(Location::class, 'LP.Id=O.ProvinceId', 'LP')
            ->leftJoin(Location::class, 'LC.Id=O.CityId', 'LC')
            ->leftJoin(Location::class, 'LA.Id=O.AreaId', 'LA')
            ->leftJoin(User::class, 'SU.Id=R.SalesmanId', 'SU')
            ->inWhere('O.Type', [Organization::TYPE_SYNTHESIZE, Organization::TYPE_JUNIOR])
            ->andwhere('R.MainId=:MainId:', ['MainId' => $this->user->OrganizationId])
            ->andWhere('R.MinorId=:MinorId:', ['MinorId' => $this->request->get('Id')])
            ->getQuery()->execute()->toArray()[0];
        if (!$supplier) {
            throw new ParamException(Status::BadRequest);
        }
        $supplier['Fee'] = RuleOfShare::findFirst([
            'conditions' => 'OrganizationId=?0 and CreateOrganizationId=?1',
            'bind'       => [$this->user->OrganizationId, RuleOfShare::STYLE_PLATFORM],
        ])->Ratio;
        $supplier['LevelId'] = HospitalLevel::value($supplier['LevelId']);
        $supplier['Type'] = OrganizationType::value($supplier['Type']);
        $this->response->setJsonContent($supplier);
    }

    /**
     * 供应商列表
     */
    public function listAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns([
                'O.MerchantCode', 'O.CreateTime', 'O.Contact', 'O.ContactTel', 'O.LevelId', 'O.ProvinceId', 'O.CityId', 'O.AreaId', 'O.Address', 'O.Type', 'O.Phone', 'O.ProvinceId', 'O.CityId', 'O.AreaId', 'O.Name', 'O.Lng', 'O.Lat', 'O.IsMain', 'O.TransferAmount',
                'R.MainId', 'R.MinorId', 'R.MainName', 'R.MinorName', 'R.SalesmanId', 'R.RuleId', 'R.MinorType',
                'S.Ratio', 'S.Type as ShareType',
                'U.IDnumber',
            ])
            ->addFrom(OrganizationRelationship::class, 'R')
            ->leftJoin(Organization::class, 'O.Id=R.MinorId', 'O')
            ->leftJoin(RuleOfShare::class, 'S.OrganizationId=R.MinorId and S.CreateOrganizationId=R.MainId', 'S')
            ->leftJoin(User::class, 'U.Phone=O.Phone', 'U')
            ->where('R.MainId=:MainId:', ['MainId' => $this->user->OrganizationId])
            ->inWhere('O.IsMain', [Organization::ISMAIN_HOSPITAL, Organization::ISMAIN_SUPPLIER]);
        //医院名字
        if (isset($data['Name']) && !empty($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['Name'], 'alias')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->andWhere('R.MinorId in ' . sprintf('(%s)', implode(',', $ids)));
            } else {
                $query->andWhere('R.MinorId=0');
            }
        }
        $query->orderBy('O.CreateTime desc');
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
        foreach ($datas as &$v) {
            $v['MinorTypeName'] = OrganizationType::value($v['MinorType']);
            $v['Count'] = $v['TransferAmount'];
        }
        $fee = RuleOfShare::findFirst([
            'conditions' => 'OrganizationId=?0 and CreateOrganizationId=?1',
            'bind'       => [$this->user->OrganizationId, RuleOfShare::STYLE_PLATFORM],
        ])->Ratio;
        $result = [];
        $result['Data'] = $datas;
        $result['Fee'] = $fee;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        return $this->response->setJsonContent($result);
    }

    /**
     * 采购其他医院列表
     * IsMap=1 地图（包含其他医院和供应商）
     * 未传参数IsMap 列表（只包含医院）
     */
    public function displayAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $data = $this->request->get();
            $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
            $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

            $organization = Organization::findFirst(sprintf('Id=%d', $this->user->OrganizationId));
            if (!$organization) {
                throw $exception;
            }
            if (!$organization->Lat || !$organization->Lng) {
                throw new LogicException('请完善坐标', Status::BadRequest);
            }
            //医院名字搜索
            $name_ids = [];
            if (isset($data['HospitalName']) && !empty($data['HospitalName'])) {
                $sphinx = new Sphinx($this->sphinx, 'organization');
                $name = $sphinx->match($data['HospitalName'], 'name')->fetchAll();
                $name_ids = array_column($name ? $name : [], 'id');
            }
            //科室名字搜索
            $section_ids = [];
            if (isset($data['SectionName']) && !empty($data['SectionName'])) {
                $sphinx = new Sphinx($this->sphinx, 'section');
                $name = $sphinx->match($data['SectionName'], 'name')->fetchAll();
                $section_ids = array_column($name ? $name : [], 'id');
            }
            //套餐名字搜索
            $combo_ids = [];
            if (isset($data['ComboName']) && !empty($data['ComboName'])) {
                $sphinx = new Sphinx($this->sphinx, 'combo');
                $name = $sphinx->match($data['ComboName'], 'name')->fetchAll();
                $combo_ids = array_column($name ? $name : [], 'id');
            }
            $sphinx = new Sphinx($this->sphinx, 'organization');
            switch ($data['Way']) {
                case 'Combo':
                    //套餐
                    $sphinx->distance($organization->Lat, $organization->Lng, ['sharecomboids', 'length(sharecomboids) as length'])->where('>', 0, 'length')->andWhere('=', Organization::ISMAIN_HOSPITAL, 'ismain')->andWhere('!=', $this->user->OrganizationId, 'id')->andWhere('!=', $this->user->OrganizationId, 'pids');
                    break;
                default:
                    //科室
                    $sphinx->distance($organization->Lat, $organization->Lng, ['sharesectionids', 'length(sharesectionids) as length'])->where('>', 0, 'length')->andWhere('=', Organization::ISMAIN_HOSPITAL, 'ismain')->andWhere('!=', $this->user->OrganizationId, 'id')->andWhere('!=', $this->user->OrganizationId, 'pids');

            }
            if (isset($data['HospitalName']) && !empty($data['HospitalName'])) {
                $sphinx->andWhere('in', $name_ids, 'id');
            }
            if (isset($data['SectionName']) && !empty($data['SectionName'])) {
                $sphinx->andWhere('in', $section_ids, 'sharesectionids');
            }
            if (isset($data['ComboName']) && !empty($data['ComboName'])) {
                $sphinx->andWhere('in', $combo_ids, 'sharecomboids');
            }
            if (isset($data['Dist']) && is_numeric($data['Dist'])) {
                $sphinx->andWhere('<=', (int)$data['Dist'], 'dist');
            }
            $result = $sphinx->orderBy('dist asc')->fetchAll();
            $dist_new = [];
            if (count($result)) {
                foreach ($result as $item) {
                    $dist_new[$item['id']] = $item['dist'];
                }
            }
            $ids = count($result) ? array_column($result, 'id') : [];
            //供应商id
            if (isset($data['IsMap']) && is_numeric($data['IsMap']) && $data['IsMap']) {
                $sphinx_supplier = new Sphinx($this->sphinx, 'organization');
                $sphinx_supplier->distance($organization->Lat, $organization->Lng, ['sharecomboids', 'length(sharecomboids) as length'])->where('>', 0, 'length')->andWhere('!=', Organization::ISMAIN_SLAVE, 'ismain')->andWhere('=', $this->user->OrganizationId, 'pids')->andWhere('<=', (int)$data['Dist'], 'dist');
                if (isset($data['Name']) && !empty($data['Name'])) {
                    $sphinx_supplier->andWhere('in', $name_ids, 'id');
                }
                if (isset($data['SectionName']) && !empty($data['SectionName'])) {
                    $sphinx_supplier->andWhere('in', $section_ids, 'sharesectionids');
                }
                $result_supplier = $sphinx_supplier->orderBy('dist asc')->limit($page, $pageSize)->fetchAll();
                $supplier_ids = count($result_supplier) ? array_column($result_supplier, 'id') : [];
                $ids = array_merge($ids, $supplier_ids);
                $query = Organization::query()
                    ->columns(['Id as HospitalId', 'Intro', 'Name', 'Logo', 'Lat', 'Lng', 'Type'])
                    ->inWhere('Id', $ids);
                $paginator = new QueryBuilder(
                    [
                        "builder" => $query->createBuilder(),
                        "limit"   => $pageSize,
                        "page"    => $page,
                    ]
                );
                $pages = $paginator->getPaginate();
                $totalPage = $pages->total_pages;
                $count = $pages->total_items;
                $datas = $pages->items->toArray();
                foreach ($datas as &$data) {
                    $data['IsSupplier'] = 0;
                    if (in_array($data['HospitalId'], $supplier_ids)) {
                        $data['IsSupplier'] = 1;
                    }
                }
                $result = [];
                $result['Data'] = $datas;
                $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
                $this->response->setJsonContent($result);
            } else {
                $field = 'field(O.Id,' . implode(',', $ids) . ')';
                $query = $this->modelsManager->createBuilder();
                switch ($data['Way']) {
                    case 'Combo':
                        //套餐
                        $query->columns([
                            'O.Id as HospitalId', 'O.Name as HospitalName', 'O.Contact', 'O.ContactTel', 'O.LevelId', 'O.Address', 'O.Type',
                            'LP.Name as Province', 'LC.Name as City', 'LA.Name as Area',
                            'C.Id as ComboId', 'C.Name', 'C.Price', 'C.Intro', 'C.PassTime', 'C.Image', 'C.Way', 'C.Amount',
                            'OC.ComboId as Sign',
                        ])
                            ->addFrom(Combo::class, 'C')
                            ->leftJoin(Organization::class, 'O.Id=C.OrganizationId', 'O')
                            ->leftJoin(OrganizationCombo::class, "OC.OrganizationId={$this->user->OrganizationId} and OC.HospitalId=C.OrganizationId and OC.ComboId=C.Id", 'OC')
                            ->inWhere('O.Id', $ids);
                        if (isset($data['ComboName']) && !empty($data['ComboName'])) {
                            $query->inWhere('C.Id', $combo_ids);
                        }
                        if (count($ids)) {
                            $field .= ',C.Id desc';
                        }
                        break;
                    default:
                        $query->columns([
                            'O.Id as HospitalId', 'O.Name as HospitalName', 'O.Contact', 'O.ContactTel', 'O.LevelId', 'O.Address', 'O.Type',
                            'S.Name as SectionName', 'S.Id as SectionId',
                            'LP.Name as Province', 'LC.Name as City', 'LA.Name as Area',
                            'SO.SectionId as Sign',
                        ])
                            ->addFrom(OrganizationAndSection::class, 'OS')
                            ->leftJoin(Organization::class, 'O.Id=OS.OrganizationId', 'O')
                            ->leftJoin(Section::class, 'S.Id=OS.SectionId', 'S')
                            ->leftJoin(OrganizationSection::class, "SO.OrganizationId={$this->user->OrganizationId} and SO.HospitalId=OS.OrganizationId and SO.SectionId=OS.SectionId", 'SO')
                            ->inWhere('O.Id', $ids)
                            ->andWhere('OS.Display=:Display:', ['Display' => OrganizationAndSection::DISPLAY_ON])
                            ->andWhere('OS.Share=:Share:', ['Share' => OrganizationAndSection::SHARE_SHARE]);
                        if (isset($data['SectionName']) && !empty($data['SectionName'])) {
                            $query->inWhere('S.Id', $section_ids);
                        }
                        if (count($ids)) {
                            $field .= ',OS.Rank desc,S.Id asc';
                        }
                }
                $query->leftJoin(Location::class, 'LP.Id=O.ProvinceId', 'LP')
                    ->leftJoin(Location::class, 'LC.Id=O.CityId', 'LC')
                    ->leftJoin(Location::class, 'LA.Id=O.AreaId', 'LA');
                if (count($ids)) {
                    $query->orderBy($field);
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
                    $data['LevelName'] = HospitalLevel::value($data['LevelId']);
                    $data['TypeName'] = OrganizationType::value($data['Type']);
                    $data['Dist'] = $dist_new[$data['HospitalId']] ?: null;
                    $data['Sign'] = $data['Sign'] ? 1 : 0;
                }
                $result = [];
                $result['Data'] = $datas;
                $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
                $this->response->setJsonContent($result);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 供应商科室、套餐列表
     */
    public function supplierAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $organization = Organization::findFirst(sprintf('Id=%d', $this->user->OrganizationId));
        //医院名字搜索
        $name_ids = [];
        if (isset($data['HospitalName']) && !empty($data['HospitalName'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['HospitalName'], 'name')->fetchAll();
            $name_ids = array_column($name ? $name : [], 'id');
        }
        //科室名字搜索
        $section_ids = [];
        if (isset($data['SectionName']) && !empty($data['SectionName'])) {
            $sphinx = new Sphinx($this->sphinx, 'section');
            $name = $sphinx->match($data['SectionName'], 'name')->fetchAll();
            $section_ids = array_column($name ? $name : [], 'id');
        }
        //套餐名字搜索
        $combo_ids = [];
        if (isset($data['ComboName']) && !empty($data['ComboName'])) {
            $sphinx = new Sphinx($this->sphinx, 'combo');
            $name = $sphinx->match($data['ComboName'], 'name')->fetchAll();
            $combo_ids = array_column($name ? $name : [], 'id');
        }
        $sphinx = new Sphinx($this->sphinx, 'organization');
        $sphinx->distance($organization->Lat, $organization->Lng, ['sharecomboids', 'length(sharecomboids) as combolength', 'sharesectionids', 'length(sharesectionids) as sectionlength'])->where('!=', 2, 'ismain')->andWhere('=', $organization->Id, 'pids');
        if (isset($data['HospitalName']) && !empty($data['HospitalName'])) {
            $sphinx->andWhere('in', $name_ids, 'id');
        }
        if (isset($data['SectionName']) && !empty($data['SectionName'])) {
            $sphinx->andWhere('in', $section_ids, 'sharesectionids');
        }
        if (isset($data['ComboName']) && !empty($data['ComboName'])) {
            $sphinx->andWhere('in', $combo_ids, 'sharecomboids');
        }
        if (isset($data['Dist']) && is_numeric($data['Dist'])) {
            $sphinx->andWhere('<=', (int)$data['Dist'], 'dist');
        }
        $result = $sphinx->orderBy('dist asc')->fetchAll();
        $ids = count($result) ? array_column($result, 'id') : [];
        $field = 'field(O.Id,' . implode(',', $ids) . ')';
        $dist_new = [];
        if (count($result)) {
            foreach ($result as $item) {
                $dist_new[$item['id']] = $item['dist'];
            }
        }
        $query = $this->modelsManager->createBuilder();
        switch ($data['Way']) {
            case 'Combo':
                //套餐
                $query->columns(['O.Id as HospitalId', 'O.Name as HospitalName', 'O.Contact', 'O.ContactTel', 'O.LevelId', 'O.Address', 'O.Type', 'R.MinorName as HospitalName', 'O.Intro as HospitalIntro', 'C.Id as ComboId', 'C.Name', 'C.Price', 'C.Intro', 'C.PassTime', 'C.Image', 'C.Way', 'C.Amount', 'OC.ComboId as Sign'])
                    ->addFrom(Combo::class, 'C')
                    ->leftJoin(Organization::class, 'O.Id=C.OrganizationId', 'O')
                    ->leftJoin(OrganizationRelationship::class, 'R.MinorId=O.Id', 'R')
                    ->leftJoin(OrganizationCombo::class, "OC.OrganizationId={$this->user->OrganizationId} and OC.HospitalId=C.OrganizationId and OC.ComboId=C.Id", 'OC')
                    ->inWhere('O.Id', $ids)
                    ->andWhere('C.Status=:Status:', ['Status' => Combo::STATUS_ON])
                    ->andWhere('C.Audit=:Audit:', ['Audit' => Combo::Audit_PASS])
                    ->andWhere('C.Share=:Share:', ['Share' => Combo::SHARE_SHARE])
                    ->andWhere('(C.PassTime>:PassTime: or C.PassTime=:OtherTime:)', ['PassTime' => time(), 'OtherTime' => 0]);
                //套餐名字搜索
                if (isset($data['ComboName']) && !empty($data['ComboName'])) {
                    $query->inWhere('C.Id', $combo_ids);
                }
                if (count($ids)) {
                    $field .= ',C.Id desc';
                }
                break;
            default:
                //科室
                $query->columns(['O.Id as HospitalId', 'O.Name as HospitalName', 'O.Contact', 'O.ContactTel', 'O.LevelId', 'O.Address', 'O.Type', 'R.MinorName as HospitalName', 'O.Intro as HospitalIntro', 'S.Id as SectionId', 'S.Name as SectionName', 'OS.IsSpecial', 'OS.Intro', 'E.SectionId as Sign'])
                    ->addFrom(Section::class, 'S')
                    ->leftJoin(OrganizationAndSection::class, 'OS.SectionId=S.Id', 'OS')
                    ->leftJoin(Organization::class, 'O.Id=OS.OrganizationId', 'O')
                    ->leftJoin(OrganizationRelationship::class, 'R.MinorId=O.Id', 'R')
                    ->leftJoin(OrganizationSection::class, "E.OrganizationId={$this->user->OrganizationId} and E.HospitalId=OS.OrganizationId and E.SectionId=OS.SectionId", 'E')
                    ->inWhere('O.Id', $ids)
                    ->andWhere('OS.Display=:Display:', ['Display' => OrganizationAndSection::DISPLAY_ON])
                    ->andWhere('OS.Share=:Share:', ['Share' => OrganizationAndSection::SHARE_SHARE]);
                //科室名字搜索g
                if (isset($data['SectionName']) && !empty($data['SectionName'])) {
                    $query->inWhere('S.Id', $section_ids);
                }
                if (count($ids)) {
                    $field .= ',OS.Rank desc,S.Id asc';
                }
        }
        $query->andWhere('R.MainId=:MainId:', ['MainId' => $organization->Id]);
        if (count($ids)) {
            $query->orderBy($field);
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
            $data['LevelName'] = HospitalLevel::value($data['LevelId']);
            $data['TypeName'] = OrganizationType::value($data['Type']);
            $data['Sign'] = $data['Sign'] ? 1 : 0;
            $data['Dist'] = $dist_new[$data['HospitalId']] ?: null;
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 该医院对应科室、套餐列表
     */
    public function detailsListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder();
        switch ($data['Way']) {
            case 'Combo':
                //套餐
                $query->columns([
                    'O.Id as HospitalId', 'O.Contact', 'O.ContactTel', 'O.LevelId', 'O.Address', 'O.Name as HospitalName', 'O.Intro as HospitalIntro',
                    'C.Name', 'C.Price', 'C.Intro', 'C.PassTime', 'C.Image', 'C.Way', 'C.Amount', 'OC.ComboId as Sign',
                    'LP.Name as Province', 'LC.Name as City', 'LA.Name as Area',
                ])
                    ->addFrom(Combo::class, 'C')
                    ->leftJoin(Organization::class, 'O.Id=C.OrganizationId', 'O')
                    ->leftJoin(OrganizationCombo::class, "OC.OrganizationId={$this->user->OrganizationId} and OC.HospitalId=O.Id and OC.ComboId=C.Id", 'OC')
                    ->where('C.Status=:Status:', ['Status' => Combo::STATUS_ON])
                    ->andWhere('C.Audit=:Audit:', ['Audit' => Combo::Audit_PASS])
                    ->andWhere('C.Share=:Share:', ['Share' => Combo::SHARE_SHARE])
                    ->andWhere('C.PassTime>:PassTime:', ['PassTime' => time()])
                    ->orWhere('C.PassTime=:OtherTime:', ['OtherTime' => 0])
                    ->orderBy('C.Id desc');
                break;
            default:
                //科室
                $query->columns([
                    'O.Id as HospitalId', 'O.Contact', 'O.ContactTel', 'O.LevelId', 'O.Address', 'O.Name as HospitalName', 'O.Intro as HospitalIntro',
                    'S.Id as SectionId', 'S.Name', 'OS.IsSpecial', 'OS.Intro', 'E.SectionId as Sign',
                    'LP.Name as Province', 'LC.Name as City', 'LA.Name as Area',
                ])
                    ->addFrom(Section::class, 'S')
                    ->leftJoin(OrganizationAndSection::class, 'OS.SectionId=S.Id', 'OS')
                    ->leftJoin(Organization::class, 'O.Id=OS.OrganizationId', 'O')
                    ->leftJoin(OrganizationSection::class, "E.OrganizationId={$this->user->OrganizationId} and E.HospitalId=O.Id and E.SectionId=OS.SectionId", 'E')
                    ->where('OS.Display=:Display:', ['Display' => OrganizationAndSection::DISPLAY_ON])
                    ->andWhere('OS.Share=:Share:', ['Share' => OrganizationAndSection::SHARE_SHARE]);
        }
        $query->leftJoin(Location::class, 'LP.Id=O.ProvinceId', 'LP')
            ->leftJoin(Location::class, 'LC.Id=O.CityId', 'LC')
            ->leftJoin(Location::class, 'LA.Id=O.AreaId', 'LA');
        $query->andWhere('O.Id=:HospitalId:', ['HospitalId' => $data['HospitalId']]);
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
        $hospitalInfo = [];
        foreach ($datas as &$data) {
            $data['Sign'] = $data['Sign'] ? 1 : 0;
            $hospitalInfo = ['HospitalName' => $data['HospitalName'], 'Address' => $data['Province'] . $data['City'] . $data['Area'] . $data['Address'], 'Contact' => $data['Contact'], 'ContactTel' => $data['ContactTel']];
        }
        $result = [];
        $result['HospitalInfo'] = $hospitalInfo;
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 做科室/套餐关联
     */
    public function relationAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            switch ($data['Way']) {
                case 'Combo':
                    //套餐
                    if (!is_array($data['ComboIds'])) {
                        throw new LogicException('套餐参数错误', Status::BadRequest);
                    }
                    $ids = $data['ComboIds'];
                    $item = 'ComboId';
                    $model = new OrganizationCombo();
                    break;
                default:
                    //科室
                    if (!is_array($data['SectionIds'])) {
                        throw new LogicException('科室参数错误', Status::BadRequest);
                    }
                    $ids = $data['SectionIds'];
                    $item = 'SectionId';
                    $model = new OrganizationSection();
            }
            if (isset($ids) && is_array($ids) && count($ids)) {
                $relation = OrganizationRelationship::findFirst([
                    'conditions' => 'MainId=?0 and MinorId=?1',
                    'bind'       => [$this->user->OrganizationId, $data['HospitalId']],
                ]);
                $type = $relation ? OrganizationSection::TYPE_SUPPLIER : OrganizationSection::TYPE_SHARE;
                foreach ($ids as $id) {
                    $clone_model = clone $model;
                    $info['OrganizationId'] = $this->user->OrganizationId;
                    $info['HospitalId'] = $data['HospitalId'];
                    $info['Type'] = $type;
                    $info[$item] = $id;
                    if ($clone_model->save($info) === false) {
                        $exception->loadFromModel($clone_model);
                        throw $exception;
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
     * 删除采购科室、套餐
     */
    public function delRelationAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            switch ($data['Way']) {
                case 'Combo':
                    $relation = OrganizationCombo::findFirst([
                        'conditions' => 'OrganizationId=?0 and HospitalId=?1 and ComboId=?2 and Type!=?3',
                        'bind'       => [$this->user->OrganizationId, $data['HospitalId'], $data['ComboId'], OrganizationCombo::TYPE_SELF],
                    ]);
                    break;
                default:
                    $relation = OrganizationSection::findFirst([
                        'conditions' => 'OrganizationId=?0 and HospitalId=?1 and SectionId=?2 and Type!=?3',
                        'bind'       => [$this->user->OrganizationId, $data['HospitalId'], $data['SectionId'], OrganizationSection::TYPE_SELF],
                    ]);
            }
            if (!$relation) {
                throw $exception;
            }
            $relation->delete();
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 已采购的科室、套餐列表
     */
    public function existListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder();
        switch ($data['Way']) {
            case 'Combo':
                $query->columns([
                    'A.HospitalId', 'A.ComboId', 'A.Type', 'O.Name as HospitalName', 'O.Contact', 'O.ContactTel', 'O.Type as HospitalType', 'O.LevelId',
                    'C.Name as ComboName', 'C.Price', 'C.Intro', 'C.Image', 'C.Way', 'C.Amount',
                ])
                    ->addFrom(OrganizationCombo::class, 'A')
                    ->leftJoin(Organization::class, 'O.Id=A.HospitalId', 'O')
                    ->leftJoin(Combo::class, 'C.Id=A.ComboId', 'C');
                //套餐名字
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
                break;
            default:
                $query->columns([
                    'A.HospitalId', 'A.SectionId', 'A.Type', 'S.Name as SectionName', 'S.Image',
                    'O.Name as HospitalName', 'O.Contact', 'O.ContactTel', 'O.Type as HospitalType', 'O.LevelId',
                    'OS.IsSpecial', 'OS.Intro',
                ])
                    ->addFrom(OrganizationSection::class, 'A')
                    ->leftJoin(Organization::class, 'O.Id=A.HospitalId', 'O')
                    ->leftJoin(OrganizationAndSection::class, 'OS.OrganizationId=A.HospitalId and OS.SectionId=A.SectionId', 'OS')
                    ->leftJoin(Section::class, 'S.Id=A.SectionId', 'S');
                //科室名称
                if (!empty($data['SectionName']) && isset($data['SectionName'])) {
                    $sphinx = new Sphinx($this->sphinx, 'section');
                    $name = $sphinx->match($data['SectionName'], 'name')->fetchAll();
                    $ids = array_column($name ? $name : [], 'id');
                    if (count($ids)) {
                        $query->inWhere('S.Id', $ids);
                    } else {
                        $query->inWhere('S.Id', [-1]);
                    }
                }

        }
        $query->andWhere('A.OrganizationId=:OrganizationId:', ['OrganizationId' => $this->user->OrganizationId])
            ->andWhere('A.HospitalId!=:HospitalId:', ['HospitalId' => $this->user->OrganizationId]);
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
        //类型
        if (isset($data['Type']) && is_numeric($data['Type'])) {
            $query->andWhere('A.Type=:Type:', ['Type' => $data['Type']]);
        } else {
            $query->andWhere('A.Type!=:Type:', ['Type' => OrganizationSection::TYPE_SELF]);
        }
        $query->orderBy('A.Sort desc');
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
            $data['Type'] = $data['Type'] == 2 ? '自有供应商' : '平台供应商';
            $data['HospitalType'] = OrganizationType::value($data['HospitalType']);
            $data['LevelName'] = HospitalLevel::value($data['LevelId']);
        }
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 采购时查看科室详情
     */
    public function readSectionAction()
    {
        $section = OrganizationAndSection::findFirst([
            "OrganizationId=?0 and SectionId=?1",
            'bind' => [$this->request->get('HospitalId', 'int'), $this->request->get('SectionId', 'int')],
        ]);
        if (!$section) {
            throw new ParamException(Status::BadRequest);
        }
        $result = $section->toArray();
        $result['SectionName'] = $section->Section->Name;
        $result['Image'] = $section->Section->Image;
        //医生
        $result['Doctors'] = $this->modelsManager->createBuilder()
            ->columns(['U.Name as DoctorName', 'U.Id as DoctorId', 'OU.OrganizationId as HospitalId', 'OU.Image', 'OU.Title', 'OU.Intro', 'OU.Skill', 'OU.Direction', 'OU.Experience', 'OU.Label', 'OU.Score', 'OU.Title'])
            ->addFrom(OrganizationUser::class, 'OU')
            ->leftJoin(User::class, 'U.Id=OU.UserId', 'U')
            ->where('OU.OrganizationId=:OrganizationId:', ['OrganizationId' => $section->OrganizationId])
            ->andWhere('OU.SectionId=:SectionId:', ['SectionId' => $section->SectionId])
            ->andWhere('OU.Identified=:Identified:', ['Identified' => OrganizationUser::IDENTIFIED_ON])
            ->andWhere('OU.Display=:Display:', ['Display' => OrganizationUser::DISPLAY_ON])
            ->andWhere('OU.Share=:Share:', ['Share' => OrganizationUser::SHARE_SHARE])
            ->getQuery()->execute()->toArray();
        if (count($result['Doctors'])) {
            foreach ($result['Doctors'] as &$doctor) {
                $doctor['TitleName'] = DoctorTitle::value($doctor['Title']);
                $doctor['Skill'] = strip_tags($doctor['Skill']);
            }
        }
        //设备
        $result['Equipments'] = $this->modelsManager->createBuilder()
            ->columns(['OE.OrganizationId', 'OE.EquipmentId', 'EAE.SectionId', 'OE.Number', 'OE.Amount', 'OE.Intro', 'OE.UpdateTime', 'OE.Display', 'OE.Image', 'E.Name as EquipmentName'])
            ->addFrom(EquipmentAndSection::class, 'EAE')
            ->leftJoin(OrganizationAndEquipment::class, 'OE.OrganizationId=EAE.OrganizationId and OE.EquipmentId=EAE.EquipmentId', 'OE')
            ->leftJoin(Equipment::class, 'E.Id=OE.EquipmentId', 'E')
            ->where('EAE.OrganizationId=:OrganizationId:', ['OrganizationId' => $section->OrganizationId])
            ->andWhere('EAE.SectionId=:SectionId:', ['SectionId' => $section->SectionId])
            ->andWhere('OE.Display=:Display:', ['Display' => OrganizationAndEquipment::DISPLAY_ON])
            ->andWhere('OE.Share=:Share:', ['Share' => OrganizationAndEquipment::SHARE_SHARE])
            ->getQuery()->execute()->toArray();
        $this->response->setJsonContent($result);
    }


    /**
     * 采购时查看套餐详情
     */
    public function readComboAction()
    {
        $data = $this->request->get();
        $combo = Combo::findFirst([
            'conditions' => 'Id=?0 and OrganizationId=?1',
            'bind'       => [$data['ComboId'], $data['HospitalId']],
        ]);
        if (!$combo) {
            throw new ParamException(Status::BadRequest);
        }
        $result = $combo->toArray();
        $relation = OrganizationRelationship::findFirst([
            'conditions' => 'MainId=?0 and MinorId=?1',
            'bind'       => [$this->user->OrganizationId, $data['HospitalId']],
        ]);
        if ($relation) {
            //自有供应商
            $relation_rule = RuleOfShare::findFirst(sprintf('Id=%d', $relation->RuleId));
            $result['Share_b'] = $combo->Price * $relation_rule->DistributionOut / 100;
            $result['share_B'] = $combo->Price * $relation_rule->Ratio / 100;
        } else {
            //平台供应商
            $hospital = Organization::findFirst(sprintf('Id=%d', $combo->OrganizationId));
            $hospital_rule = RuleOfShare::findFirst(sprintf('Id=%d', $hospital->RuleId));
            $result['Share_b'] = $combo->Price * $hospital_rule->DistributionOut / 100;
            $result['share_B'] = $combo->Price * $hospital_rule->DistributionOutB / 100;
        }
        $result['Intro'] = strip_tags($result['Intro']);
        $this->response->setJsonContent($result);
    }
}