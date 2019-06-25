<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/21
 * Time: 下午3:29
 */

namespace App\Admin\Controllers;

use App\Exceptions\ParamException;
use App\Libs\csv\AdminCsv;
use App\Libs\Sphinx;
use App\Enums\Status;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\Transfer;
use Phalcon\Paginator\Adapter\QueryBuilder;

class SlaveController extends Controller
{
    /**
     * 医院下级列表
     */
    public function listAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder();
        $query->columns([
            'O.Id', 'O.Name', 'O.MerchantCode', 'O.CreateTime', 'O.Contact', 'O.Phone', 'O.ProvinceId', 'O.CityId', 'O.AreaId', 'O.LevelId', 'O.Verifyed', 'O.Type', 'O.Score', 'O.TransferAmount', 'O.RuleId',
            'LP.Name as Province', 'LC.Name as City', 'LA.Name as Area',
            'B.MerchantCode as MainMerchantCode', 'B.Name as MainName',
        ])
            ->addFrom(Organization::class, 'O')
            ->leftJoin(Location::class, 'LP.Id=O.ProvinceId', 'LP')
            ->leftJoin(Location::class, 'LC.Id=O.CityId', 'LC')
            ->leftJoin(Location::class, 'LA.Id=O.AreaId', 'LA')
            ->leftJoin(OrganizationRelationship::class, 'R.MinorId=O.Id', 'R')
            ->leftJoin(Organization::class, 'B.Id=R.MainId', 'B')
            ->notInWhere('O.Id', [Organization::PEACH])
            ->andWhere('O.IsMain=2');
        //创建时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("O.CreateTime>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                $this->response->setStatusCode(Status::BadRequest);
                return;
            }
            $query->andWhere("O.CreateTime<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        //省市
        if (!empty($data['ProvinceId']) && isset($data['ProvinceId']) && is_numeric($data['ProvinceId'])) {
            $query->andWhere("O.ProvinceId=:ProvinceId:", ['ProvinceId' => $data['ProvinceId']]);
        }
        if (!empty($data['CityId']) && isset($data['CityId']) && is_numeric($data['CityId'])) {
            $query->andWhere("O.CityId=:CityId:", ['CityId' => $data['CityId']]);
        }
        //名称
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
        //类型
        if (!empty($data['Type']) && isset($data['Type']) && is_numeric($data['Type'])) {
            $query->andWhere("O.Type=:Type:", ['Type' => $data['Type']]);
        }
        //是否是刷单
        if (!empty($data['Fake']) && isset($data['Fake']) && is_numeric($data['Fake'])) {
            $query->andWhere('O.Fake=:Fake:', ['Fake' => $data['Type']]);
        }
        //所属医院
        if (!empty($data['MainName']) && isset($data['MainName'])) {
            $query->andWhere("B.Name=:MainName:", ['MainName' => $data['MainName']]);
        }
        //商户号
        if (!empty($data['MerchantCodeType']) && isset($data['MerchantCodeType']) && is_numeric($data['MerchantCodeType']) && !empty($data['MerchantCode']) && isset($data['MerchantCode'])) {
            switch ((int)$data['MerchantCodeType']) {
                case 1://全部
                    $query->andWhere('O.MerchantCode=:MerchantCodeSelf: or B.MerchantCode=:MerchantCodeMain:', ['MerchantCodeSelf' => $data['MerchantCode'], 'MerchantCodeMain' => $data['MerchantCode']]);
                    break;
                case 2://网点
                    $query->andWhere('O.MerchantCode=:MerchantCode:', ['MerchantCode' => $data['MerchantCode']]);
                    break;
                case 3://医院
                    $query->andWhere('B.MerchantCode=:MerchantCode:', ['MerchantCode' => $data['MerchantCode']]);
                    break;
            }
        }
        $query->orderBy('O.CreateTime desc');
        //导出表格
        if (isset($data['Export']) && !empty($data['Export'])) {
            $csv = new AdminCsv($query);
            $csv->slave();
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
        $transferCount = Transfer::query()
            ->columns(['SendOrganizationId Id', 'sum(Cost) Cost', 'count(*)  Count', 'sum(if(CloudGenre=1,ShareCloud,Cost*ShareCloud/100)) as Platform'])
            ->inWhere('SendOrganizationId', array_column($datas, 'Id'))
            ->andWhere(sprintf('Status=%d', Transfer::FINISH))
            ->groupBy('SendOrganizationId')
            ->execute()->toArray();
        $transferCount_tmp = [];
        if (count($transferCount)) {
            foreach ($transferCount as $item) {
                $transferCount_tmp[$item['Id']] = ['Cost' => $item['Cost'], 'Count' => $item['Count'], 'Platform' => $item['Platform']];
            }
        }
        foreach ($datas as &$data) {
            $data['Cost'] = isset($transferCount_tmp[$data['Id']]) ? (isset($transferCount_tmp[$data['Id']]['Cost']) ? floor($transferCount_tmp[$data['Id']]['Cost']) : 0) : 0;
            $data['Count'] = isset($transferCount_tmp[$data['Id']]) ? (isset($transferCount_tmp[$data['Id']]['Count']) ? floor($transferCount_tmp[$data['Id']]['Count']) : 0) : 0;
            $data['Platform'] = isset($transferCount_tmp[$data['Id']]) ? (isset($transferCount_tmp[$data['Id']]['Platform']) ? floor($transferCount_tmp[$data['Id']]['Platform']) : 0) : 0;
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 查看详情
     */
    public function readAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $id = $this->request->get('Id', 'int');
            $organization = Organization::findFirst(sprintf('Id=%d', $id));
            if (!$organization) {
                throw $exception;
            }
            $result = $organization->toArray();
            $result['Province'] = $organization->Province->Name;
            $result['City'] = $organization->City->Name;
            $result['Area'] = $organization->Area->Name;
            if ($organization->Verifyed === Organization::VERIFYED) {
                $result['BusinessLicense'] = $organization->Aptitude->BusinessLicense;
            }
            $transferCount = Transfer::query()
                ->columns(['SendOrganizationId Id', 'sum(Cost) Cost', 'count(*)  Count', 'sum(if(CloudGenre=1,ShareCloud,Cost*ShareCloud/100)) as Platform'])
                ->where(sprintf('SendOrganizationId=%d', $organization->Id))
                ->andWhere(sprintf('Status=%d', Transfer::FINISH))
                ->groupBy('SendOrganizationId')
                ->execute()->getFirst();
            $result['Count'] = $transferCount ? $transferCount->Count : 0;
            $result['Cost'] = $transferCount ? floor($transferCount->Cost) : 0;
            $result['Platform'] = $transferCount ? floor($transferCount->Platform) : 0;
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        }
        return;
    }
}