<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/6/1
 * Time: 上午9:39
 * For: 重疾管理
 */

namespace App\Controllers;

use App\Enums\HospitalLevel;
use App\Enums\OrganizationType;
use App\Libs\Sphinx;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationAndSection;
use App\Models\OrganizationRelationship;
use App\Models\RuleOfShare;
use App\Models\Section;
use App\Models\Sickness;
use App\Models\SicknessAndOrganization;
use App\Models\SicknessAndSection;
use App\Models\SicknessSection;
use Phalcon\Paginator\Adapter\QueryBuilder;

class SicknessController extends Controller
{
    /**
     * 展示一级、二级科室
     */
    public function sectionListAction()
    {
        $data = $this->request->getPost();
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
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }

    /**
     * 疾病列表
     */
    public function sickListAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns(['S.Id as SicknessId', 'S.Name as SicknessName',])
            ->addFrom(Sickness::class, 'S')
            ->join(SicknessAndOrganization::class, 'SAO.SicknessId=S.Id', 'SAO')
            ->join(SicknessAndSection::class, 'E.SectionId=SAO.SicknessSectionId and E.SicknessId=SAO.SicknessId', 'E')
            ->where(sprintf('E.Status=%d', SicknessAndSection::STATUS_ON));
        //病种
        if (isset($data['SicknessName']) && !empty($data['SicknessName'])) {
            $sphinx = new Sphinx($this->sphinx, 'sickness');
            $name = $sphinx->match($data['SicknessName'], 'name')->fetchAll();;
            $ids = array_column($name ?: [], 'id');
            $query->inWhere('S.Id', $ids);
        }
        //状态
        if (isset($data['Status']) && !empty($data['Status'])) {
            $query->andWhere('SAO.Status=:Status:', ['Status' => $data['Status']]);
        }
        $query->groupBy('S.Id');
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
     * 疾病下对应的医院、科室
     */
    public function hospitalListAction()
    {
        $auth = $this->session->get('auth');
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'sickness');
        $result = $sphinx->where('=', (int)$data['SicknessId'], 'id')->fetch();
        $ids_new = [];
        if ($result['organizations']) {
            $ids = explode(',', $result['organizations']);
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $data['Lat'] = (float)$data['Lat'];
            $data['Lng'] = (float)$data['Lng'];
            $sphinx->distance($data['Lat'], $data['Lng'])->where('in', $ids, 'id');
            switch ($data['Sort']) {
                //好评优先
                case 'Evaluate':
                    $sphinx->orderBy('score desc');
                    break;
                //接诊最多
                case 'TransferAmount':
                    $sphinx->orderBy('transferamount desc');
                    break;
                //距离排序
                case 'Distance':
                    $sphinx->orderBy('dist asc');
                    break;
            }
            //类型
            if (isset($data['Type']) && is_numeric($data['Type']) && $data['Type']) {
                $sphinx->andWhere('=', (int)$data['Type'], 'type');
            }
            //省市
            if (!empty($data['ProvinceId']) && isset($data['ProvinceId']) && is_numeric($data['ProvinceId'])) {
                $sphinx->andWhere('=', (int)$data['ProvinceId'], 'CityId');
            }
            //城市
            if (isset($data['CityId']) && is_numeric($data['CityId']) && $data['CityId']) {
                $sphinx->andWhere('=', (int)$data['CityId'], 'CityId');
            }
            //地区
            if (isset($data['AreaId']) && is_numeric($data['AreaId']) && $data['AreaId']) {
                $sphinx->andWhere('=', (int)$data['AreaId'], 'AreaId');
            }
            if (!isset($data['ShowAll']) || !$data['ShowAll']) {
                $sphinx->limit($page, $pageSize);
            }
            $result = $sphinx->fetchAll();
            $ids_new = array_column($result ?: [], 'id');
        }

        $supplierIds = [];
        if (count($ids_new)) {
            $relations = OrganizationRelationship::query()->inWhere('MinorId', $supplierIds)->andWhere('MainId=' . $auth['HospitalId'])->execute()->toArray();
            if (count($relations)) {
                foreach ($relations as $relation) {
                    $supplierIds[] = $relation['MinorId'];
                }
            }
        }
        $self = [];
        $supplierRules = [];
        if (count($ids_new)) {
            //自有分润
            if (in_array($auth['HospitalId'], $ids_new)) {
                $self = RuleOfShare::query()
                    ->leftJoin(OrganizationRelationship::class, 'R.RuleId=Id', 'R')
                    ->where(sprintf('R.MainId=%d', $auth['HospitalId']))
                    ->andWhere(sprintf('R.MinorId=%d', $auth['OrganizationId']))
                    ->limit(1)->execute()->getFirst();
                if ($self) {
                    $self = $self->toArray();
                } else {
                    $self = [];
                }
            }
            //专供分润
            if (count($supplierIds)) {
                $supplier_rules = RuleOfShare::query()->inWhere('OrganizationId', $supplierIds)->andWhere(sprintf('CreateOrganizationId=%d', $auth['HospitalId']))->execute()->toArray();
                foreach ($supplier_rules as $rule) {
                    $supplierRules[$rule['OrganizationId']] = $rule;
                }
            }
        }
        $field = 'field(O.Id,' . implode(',', $ids_new) . ')';
        $dist_new = [];
        if (count($result) && is_array($result)) {
            foreach ($result as $item) {
                if (isset($item['id']) && isset($item['dist'])) {
                    $dist_new[$item['id']] = $item['dist'];
                }
            }
        }
        $query = $this->modelsManager->createBuilder()
            ->columns([
                'O.Id as HospitalId', 'O.Name', 'O.Lat', 'O.Lng', 'O.LevelId', 'O.RuleId', 'O.Type as HospitalType',
                'O.ProvinceId', 'O.CityId', 'O.AreaId', 'O.Address', 'O.Type', 'O.Score', 'O.TransferAmount', 'O.Logo as Image',
                'OS.IsSpecial', 'OS.Display', 'OS.Share', 'OS.Intro',
                'S.Name as SectionName', 'S.Image as SectionImage', 'S.Id as SectionId',
                'LP.Name as Province', 'LC.Name as City', 'LA.Name as Area',
                'R.Fixed', 'R.Ratio', 'R.DistributionOut', 'R.Type as ShareType',
                'SAO.OrganizationId',
            ])
            ->addFrom(SicknessAndOrganization::class, 'SAO')
            ->leftJoin(Organization::class, 'O.Id=SAO.OrganizationId', 'O')
            ->leftJoin(OrganizationAndSection::class, 'OS.OrganizationId=SAO.OrganizationId and OS.SectionId=SAO.OrganizationSectionId', 'OS')
            ->leftJoin(Section::class, 'S.Id=OS.SectionId', 'S')
            ->leftJoin(RuleOfShare::class, 'R.Id=O.RuleId', 'R')
            ->leftJoin(Location::class, 'LP.Id=O.ProvinceId', 'LP')
            ->leftJoin(Location::class, 'LC.Id=O.CityId', 'LC')
            ->leftJoin(Location::class, 'LA.Id=O.AreaId', 'LA')
            ->leftJoin(SicknessAndSection::class, 'E.SectionId=SAO.SicknessSectionId and E.SicknessId=SAO.SicknessId', 'E')
            ->where(sprintf('E.Status=%d', SicknessAndSection::STATUS_ON))
            ->inWhere('SAO.OrganizationId', $ids_new)
            ->andWhere('SAO.SicknessId=:SicknessId:', ['SicknessId' => $data['SicknessId']]);
        //省市
        if (!empty($data['ProvinceId']) && isset($data['ProvinceId']) && is_numeric($data['ProvinceId'])) {
            $query->andWhere("O.ProvinceId=:ProvinceId:", ['ProvinceId' => $data['ProvinceId']]);
        }
        if (!empty($data['CityId']) && isset($data['CityId']) && is_numeric($data['CityId'])) {
            $query->andWhere("O.CityId=:CityId:", ['CityId' => $data['CityId']]);
        }
        if (!empty($data['AreaId']) && isset($data['AreaId']) && is_numeric($data['AreaId'])) {
            $query->andWhere("O.AreaId=:AreaId:", ['AreaId' => $data['AreaId']]);
        }
        //类型
        if (!empty($data['Type']) && isset($data['Type']) && is_numeric($data['Type'])) {
            $query->andWhere("O.Type=:Type:", ['Type' => $data['Type']]);
        }
        if (count($ids_new)) {
            $query->orderBy($field);
        }
        if (isset($data['ShowAll']) && is_numeric($data['ShowAll']) && $data['ShowAll']) {
            $datas = $query->getQuery()->execute()->toArray();
            foreach ($datas as &$data) {
                $data['Image'] = $data['Image'] ?: Section::DEFAULT_IMAGE;
                $data['Dist'] = $dist_new[$data['OrganizationId']];
                $data['LevelName'] = HospitalLevel::value($data['LevelId']);
                $data['HospitalType'] = OrganizationType::value($data['HospitalType']);
                $data['ShareType'] = RuleOfShare::RULE_RATIO;
                if ($data['OrganizationId'] == $auth['HospitalId']) {
                    $data['ShareType'] = isset($self['Type']) ? $self['Type'] : null;
                    $data['DistributionOut'] = (isset($self['Type']) && $self['Type'] == RuleOfShare::RULE_FIXED) ? (isset($self['Fixed']) ? $self['Fixed'] : 0) : (isset($self['Ratio']) ? $self['Ratio'] : 0);
                } elseif (in_array($data['OrganizationId'], $supplierIds)) {
                    $data['DistributionOut'] = $supplierRules[$data['OrganizationId']]['Ratio'];
                }
            }
            return $this->response->setJsonContent($datas);
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
            $data['Image'] = $data['Image'] ?: Section::DEFAULT_IMAGE;
            $data['Dist'] = $dist_new[$data['OrganizationId']];
            $data['LevelName'] = HospitalLevel::value($data['LevelId']);
            $data['HospitalType'] = OrganizationType::value($data['HospitalType']);
            $data['ShareType'] = RuleOfShare::RULE_RATIO;
            if ($data['OrganizationId'] == $auth['HospitalId']) {
                $data['ShareType'] = isset($self['Type']) ? $self['Type'] : null;
                $data['DistributionOut'] = (isset($self['Type']) && $self['Type'] == RuleOfShare::RULE_FIXED) ? (isset($self['Fixed']) ? $self['Fixed'] : 0) : (isset($self['Ratio']) ? $self['Ratio'] : 0);
            } elseif (in_array($data['OrganizationId'], $supplierIds)) {
                $data['DistributionOut'] = $supplierRules[$data['OrganizationId']]['Ratio'];
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        $this->response->setJsonContent($result);
    }
}