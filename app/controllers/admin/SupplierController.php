<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/5/21
 * Time: 下午3:40
 */

namespace App\Admin\Controllers;

use App\Enums\HospitalLevel;
use App\Enums\SmsExtend;
use App\Exceptions\LogicException;
use App\Libs\Rule;
use App\Libs\Sms;
use App\Libs\Sphinx;
use App\Models\OrganizationUser;
use App\Models\Role;
use App\Models\OrganizationRelationship;
use App\Models\SupplierApply;
use App\Models\User;
use App\Enums\Status;
use App\Models\RuleOfShare;
use App\Models\Organization;
use App\Enums\MessageTemplate;
use App\Enums\OrganizationType;
use App\Exceptions\ParamException;
use App\Models\UserTempCache;
use Phalcon\Paginator\Adapter\QueryBuilder;

class SupplierController extends Controller
{
    /**
     * 申请列表
     */
    public function applyListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns([
                'S.Id', 'S.HospitalId', 'S.Name', 'S.LevelId', 'S.Type', 'S.Contact', 'S.ContactTel',
                'S.IDnumber', 'S.ProvinceId', 'S.CityId', 'S.AreaId', 'S.Address', 'S.Lng', 'S.Lat', 'S.Phone',
                'S.Ratio', 'S.DistributionOut', 'S.Created', 'S.Status', 'O.Name as HospitalName', 'O.MerchantCode',
            ])
            ->addFrom(SupplierApply::class, 'S')
            ->join(Organization::class, 'O.Id=S.HospitalId', 'O', 'left');
        if (is_numeric($data['Status'])) {
            $query->andWhere('S.Status=:Status:', ['Status' => $data['Status']]);
        }
        //医院名称
        if (!empty($data['Name']) && isset($data['Name'])) {
            $query->andWhere('S.Name=:Name:', ['Name' => $data['Name']]);
        }
        $query->orderBy('S.Id desc');
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
            $v['TypeName'] = OrganizationType::value($v['Type']);
            $v['LevelName'] = HospitalLevel::value($v['LevelId']);
            $v['StatusName'] = SupplierApply::STATUS_NAME[$v['Status']];
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        return $this->response->setJsonContent($result);
    }

    /**
     * 查看申请详情
     */
    public function readAction()
    {
        $supplierApply = SupplierApply::findFirst(sprintf('Id=%d', $this->request->get('Id')));
        if (!$supplierApply) {
            throw new ParamException(Status::BadRequest);
        }
        $organization = Organization::findFirst(sprintf('Id=%d', $supplierApply->HospitalId));
        $rule = RuleOfShare::findFirst(sprintf('Id=%d', $organization->RuleId));
        $result = $supplierApply->toArray();
        $result['HospitalName'] = $organization->Name;
        $result['MerchantCode'] = $organization->MerchantCode;
        $result['PlatFormFee'] = $rule->Ratio;
        $this->response->setJsonContent($result);
    }

    /**
     * 供应商申请处理
     */
    public function editAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            $supplierApply = SupplierApply::findFirst(sprintf('Id=%d', $data['Id']));
            if (!$supplierApply) {
                throw $exception;
            }
            if ($supplierApply->Status === SupplierApply::STATUS_PASS) {
                throw new LogicException('不能重复操作', Status::BadRequest);
            }
            $white = ['Status', 'Explain'];
            if ($supplierApply->save($data, $white) === false) {
                $exception->loadFromModel($supplierApply);
                throw $exception;
            }
            $supplierApply->refresh();
            $user_exist = false;
            if ($supplierApply->Status === SupplierApply::STATUS_PASS) {
                $now = time();
                $hospital = Organization::findFirst(sprintf('Id=%d', $supplierApply->HospitalId));
                $organizationId = $data['OrganizationId'];
                $data = $supplierApply->toArray();
                if (is_numeric($organizationId) && $organizationId > 0) {
                    $organization = Organization::findFirst(sprintf('Id=%d', $organizationId));
                    $oldRelation = OrganizationRelationship::findFirst(['conditions' => 'MainId=?0 and MinorId=?1', 'bind' => [$hospital->Id, $organization->Id]]);
                    if ($oldRelation) {
                        throw new LogicException('不能重复建立关系', Status::BadRequest);
                    }
                } else {
                    $organization = new Organization();
                    //机构号
                    $rule = new Rule();
                    $merchantCode = $rule->MerchanCode($data['ProvinceId'], Organization::ISMAIN_HOSPITAL);
                    $data['MerchantCode'] = $merchantCode;
                    $data['CreateTime'] = $now;
                    $data['IsMain'] = Organization::ISMAIN_SUPPLIER;
                    unset($data['Id'], $data['Money'], $data['Balance']);
                    //设置验证场景
                    $organization->setScene(Organization::SCENE_SUPPLIER_CREATE);
                    if ($organization->save($data) === false) {
                        $exception->loadFromModel($organization);
                        throw $exception;
                    }
                    $defaultPassword = substr($data['Phone'], -6, 6);
                    $phone = null;
                    //创建管理员
                    $oldUser = User::findFirst([
                        'conditions' => 'Phone=?0',
                        'bind'       => [$data['Phone']],
                    ]);
                    $organizationUser = new OrganizationUser;
                    if (!$oldUser) {
                        $user = new User();
                        $user->Name = $data['Contact'];
                        $user->Phone = $data['Phone'];
                        $user->IDnumber = $data['IDnumber'];
                        if (!empty($data['Password']) && isset($data['Password'])) {
                            $defaultPassword = substr($data['Phone'], -6, 6);
                        }
                        $user->Password = $this->security->hash($defaultPassword);
                        if ($user->save() === false) {
                            $exception->loadFromModel($user);
                            throw $exception;
                        }
                        $userData['UserId'] = $user->Id;
                    } else {
                        $userData['UserId'] = $oldUser->Id;
                        $user = $oldUser;
                    }
                    $phone = $user->Phone;
                    $oldOrganizationUser = OrganizationUser::findFirst(['conditions' => 'OrganizationId=?0 and UserId=?1', 'bind' => [$organization->Id, $user->Id]]);
                    if ($oldOrganizationUser) {
                        $user_exist = true;
                        $organizationUser = $organization;
                        unset($userData['UserId'], $userData['OrganizationId']);
                    } else {
                        $userData['OrganizationId'] = $organization->Id;
                        $userData['CreateTime'] = $now;
                        $userData['Role'] = Role::DEFAULT_SUPPLIER;
                        $userData['Display'] = OrganizationUser::DISPLAY_OFF;
                        $userData['UseStatus'] = OrganizationUser::USESTATUS_ON;
                        $userData['Label'] = OrganizationUser::LABEL_ADMIN;
                    }
                    /*if ($organizationUser->save($userData) === false) {
                        $exception->loadFromModel($organizationUser);
                        throw $exception;
                    }*/
                    $organizationUser->validation();
                    $organizationUser->assign($userData);
                    $cache = new UserTempCache();
                    $cache->Phone = $data['Phone'];
                    $cache->MerchantCode = $organization->MerchantCode;
                    $cache->Content = serialize($organizationUser);
                    $cache->Message = MessageTemplate::load('account_create_supplier', MessageTemplate::METHOD_SMS, $hospital->Name, $organization->MerchantCode, $phone, $user_exist ? '未改变' : $defaultPassword, $hospital->ContactTel);
                    $cache->Code = SmsExtend::CODE_CREATE_ADMIN_SUPPLIER;
                    if ($cache->save() === false) {
                        $exception->loadFromModel($cache);
                        throw $exception;
                    }
                }
                //创建分润规则
                $shareRule = new RuleOfShare();
                $shareRule->Type = RuleOfShare::RULE_RATIO;
                $shareRule->Remark = '创建供应商';
                $shareRule->Intro = '';
                $shareRule->Fixed = 0;
                $shareRule->CreateOrganizationId = $data['HospitalId'];
                $shareRule->Ratio = $data['Ratio'];
                $shareRule->DistributionOut = $data['DistributionOut'];
                $shareRule->UpdateTime = $now;
                $shareRule->Name = $organization->Name . '手续费';
                $shareRule->OrganizationId = $organization->Id;
                $shareRule->Style = RuleOfShare::STYLE_HOSPITAL_SUPPLIER;
                $shareRule->setScene(RuleOfShare::SCENE_SUPPLIER_CREATE);
                if ($shareRule->save() === false) {
                    $exception->loadFromModel($shareRule);
                    throw $exception;
                }
                //与上级单位建立关联关系
                $organizationShip = new OrganizationRelationship();
                $info = [];
                $info['SalesmanId'] = $data['SalesmanId'];
                $info['MainId'] = $data['HospitalId'];
                $info['MinorId'] = $organization->Id;
                $info['RuleId'] = $shareRule->Id;
                $info['MainName'] = $hospital->Name;
                $info['MinorName'] = $organization->Name;
                $info['MinorType'] = $data['Type'];
                if ($organizationShip->save($info) === false) {
                    $exception->loadFromModel($organizationShip);
                    throw $exception;
                }
            }
            $this->db->commit();
            if ($supplierApply->Status === SupplierApply::STATUS_PASS) {
                $content = sprintf(SmsExtend::CODE_CREATE_ADMIN_SUPPLIER_MESSAGE, $hospital->Name, $organization->MerchantCode);
                $sms = new Sms($this->queue);
                $sms->sendMessage((string)$phone, $content, SmsExtend::CODE_CREATE_ADMIN_SUPPLIER);
                //写入sphinx
                $sphinx = new Sphinx($this->sphinx, 'organization');
                $result = $sphinx->where('=', (int)$organization->Id, 'id')->fetch();
                if ($result) {
                    if (!empty($result['pids'])) {
                        $sphinx_data['pids'] = explode(',', $result['pids']);
                    }
                    $sphinx_data['pids'][] = $data['HospitalId'];
                    $sphinx->update($sphinx_data, (int)$organization->Id);
                } else {
                    $sphinx_data = array_change_key_case($organization->toArray(), CASE_LOWER);
                    $sphinx_data['alias'] = $organization->Name;
                    $sphinx_data['type'] = $organization->Type;
                    $sphinx_data['pids'][] = $data['HospitalId'];
                    $sphinx_data['sharesectionids'] = [];
                    $sphinx_data['sharecomboids'] = [];
                    $sphinx->save($sphinx_data);
                }
            }
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 模糊查询医院
     */
    public function nameListAction()
    {
        $supplierApply = SupplierApply::findFirst(sprintf('Id=%d', $this->request->get('Id')));
        if (!$supplierApply) {
            throw new ParamException(Status::BadRequest);
        }
        $sphinx = new Sphinx($this->sphinx, 'organization');
        $name = $sphinx->match($supplierApply->Name, 'name')->fetchAll();
        $ids = array_column($name ? $name : [], 'id');
        $query = Organization::query()->columns(['Id', 'Name']);
        if (count($ids)) {
            $query->inWhere('Id', $ids);
        } else {
            $query->inWhere('Id', [-1]);
        }
        $data = array_merge($query->execute()->toArray(), [['Id' => null, 'Name' => '不存在供应商，新建一个']]);
        $this->response->setJsonContent($data);
    }
}