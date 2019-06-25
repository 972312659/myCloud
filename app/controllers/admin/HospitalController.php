<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/21
 * Time: 上午10:24
 */

namespace App\Admin\Controllers;


use App\Enums\RedisName;
use App\Enums\HospitalLevel;
use App\Enums\SmsExtend;
use App\Exceptions\LogicException;
use App\Libs\csv\AdminCsv;
use App\Libs\module\ManagerOrganization as ManagerModuleOrganization;
use App\Libs\Rule;
use App\Libs\Sms;
use App\Libs\Sphinx;
use App\Models\DefaultModule;
use App\Models\Module;
use App\Models\OrganizationModule;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\Role;
use App\Models\RuleOfShareSub;
use App\Models\Staff;
use App\Models\StaffHospitalLog;
use App\Models\User;
use App\Enums\Status;
use App\Models\Location;
use App\Models\RuleOfShare;
use App\Models\Organization;
use App\Enums\MessageTemplate;
use App\Exceptions\ParamException;
use App\Models\UserTempCache;
use Phalcon\Paginator\Adapter\QueryBuilder;

class HospitalController extends Controller
{
    /**
     * 创建医院
     * @throws ParamException
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $now = time();
            $this->db->begin();
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $data['Phone'] = trim($data['Phone']);
                //一个手机号只能创建一个机构
                $oldOrganization = Organization::findFirst([
                    'conditions' => 'Phone=?0',
                    'bind'       => [$data['Phone']],
                ]);
                if ($oldOrganization) {
                    throw new LogicException('该号码已被注册', Status::BadRequest);
                }
                $organization = new Organization();
                $data['IsMain'] = Organization::ISMAIN_HOSPITAL;
                if (empty($data['Address']) || !isset($data['Address'])) {
                    $data['Address'] = '';
                }
                //机构号
                $rule = new Rule();
                $merchantCode = $rule->MerchanCode($data['ProvinceId'], $data['IsMain']);
                $data['MerchantCode'] = $merchantCode;
                $data['CreateTime'] = $now;
                //创建分润规则
                $shareRule = new RuleOfShare();
                $main = true;
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $organization = Organization::findFirst(sprintf('Id=%d', $data['Id']));
                $shareRule = RuleOfShare::findFirst(sprintf('Id=%d', $organization->RuleId));
                if (!$organization) {
                    throw $exception;
                }
                if ($organization->IsMain == Organization::ISMAIN_HOSPITAL && !$shareRule) {
                    throw $exception;
                }
                $main = $organization->IsMain == Organization::ISMAIN_HOSPITAL ? true : false;
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            //设置验证场景
            $organization->setScene(Organization::SCENE_ADMIN_HOSPITAL_CREATE);
            if ($main) {
                if (!is_array($data['Ratio']) || empty($data['Ratio'])) {
                    throw new LogicException('平台手续费参数不能为空', Status::BadRequest);
                }
                $ratio = 0;
                foreach ($data['Ratio'] as $datum) {
                    $ratio = $datum['Value'];
                    break;
                }
                if ($this->request->isPost()) {
                    $shareRule->setScene(RuleOfShare::SCENE_ADMIN_HOSPITAL_CREATE);
                    $shareRule->Fixed = 0;
                    $shareRule->Ratio = $ratio;
                } else {
                    $ruleOfShareSubs = RuleOfShareSub::find([
                        'conditions' => 'RuleOfShareId=?0',
                        'bind'       => [$shareRule->Id],
                    ]);
                    $ruleOfShareSubs->delete();
                }
                $shareRule->DistributionOutB = $data['DistributionOutB'];
                $shareRule->DistributionOut = $data['DistributionOut'];
                $shareRule->Type = RuleOfShare::RULE_RATIO;
                $shareRule->Remark = '按合同';
                if ($shareRule->save() === false) {
                    $exception->loadFromModel($shareRule);
                    throw $exception;
                }
                $continuous = 0;
                foreach ($data['Ratio'] as $datum) {
                    if ($continuous != $datum['Min']) {
                        throw new LogicException('平台手续费金额数值必须是连续的', Status::BadRequest);
                    }
                    $continuous = $datum['Max'];
                    /** @var RuleOfShareSub $ruleOfShareOfSub */
                    $ruleOfShareOfSub = new RuleOfShareSub();
                    $ruleOfShareOfSub->RuleOfShareId = $shareRule->Id;
                    $ruleOfShareOfSub->MinAmount = $datum['Min'];
                    $ruleOfShareOfSub->MaxAmount = is_numeric($datum['Max']) && $datum['Max'] > 0 ? $datum['Max'] : null;
                    $ruleOfShareOfSub->IsFixed = RuleOfShareSub::IS_FIXED_NO;
                    $ruleOfShareOfSub->Value = $datum['Value'];
                    if ($ruleOfShareOfSub->save() === false) {
                        $exception->loadFromModel($ruleOfShareOfSub);
                        throw $exception;
                    }
                }
                $data['RuleId'] = $shareRule->Id;
            } else {
                unset($data['RuleId']);
            }
            unset($data['Money'], $data['Balance'], $data['EpacsBalance'], $data['EpacsAvaBalance'], $data['BalanceFake'], $data['MoneyFake']);
            if ($organization->save($data) === false) {
                $exception->loadFromModel($organization);
                throw $exception;
            }
            //模块选择
            ManagerModuleOrganization::relationModule($organization->Id, $this->session->get('auth')['Name'], $data['Modules']);
            //发送短信给商户
            if ($this->request->isPost()) {
                //创建管理员
                $oldUser = User::findFirst([
                    'conditions' => 'Phone=?0',
                    'bind'       => [$data['Phone']],
                ]);
                $organizationUser = new OrganizationUser;
                $defaultPassword = substr($data['Phone'], -6, 6);
                if (!$oldUser) {
                    $user = new User();
                    $user->Name = $data['Contact'];
                    $user->Phone = $data['Phone'];
                    $user->IDnumber = $data['IDnumber'];
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
                $userData['OrganizationId'] = $organization->Id;
                $userData['CreateTime'] = $now;
                $userData['Role'] = Role::DEFAULT_B;
                $userData['Display'] = OrganizationUser::DISPLAY_OFF;
                $userData['UseStatus'] = OrganizationUser::USESTATUS_ON;
                $userData['Label'] = OrganizationUser::LABEL_ADMIN;
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
                $cache->Message = MessageTemplate::load('account_create_major', MessageTemplate::METHOD_SMS, $organization->MerchantCode, $user->Phone, $oldUser ? '未改变' : $defaultPassword);
                $cache->Code = SmsExtend::CODE_CREATE_ADMIN_HOSPITAL;
                if ($cache->save() === false) {
                    $exception->loadFromModel($cache);
                    throw $exception;
                }
                $shareRule->Name = $organization->Name . '佣金规则';
                $shareRule->OrganizationId = $organization->Id;
                if ($shareRule->save() === false) {
                    $exception->loadFromModel($shareRule);
                    throw $exception;
                }

                $content = sprintf(SmsExtend::CODE_CREATE_ADMIN_HOSPITAL_MESSAGE, $organization->MerchantCode);
                $sms = new Sms($this->queue);
                $sms->sendMessage((string)$user->Phone, $content, SmsExtend::CODE_CREATE_ADMIN_HOSPITAL);
            }
            $this->db->commit();
            if ($this->request->isPost()) {
                //写入sphinx
                $sphinx = new Sphinx($this->sphinx, 'organization');
                $sphinx_data = array_change_key_case($organization->toArray(), CASE_LOWER);
                $sphinx_data['alias'] = $organization->Name;
                $sphinx_data['type'] = $organization->Type;
                $sphinx_data['pids'] = [];
                $sphinx_data['sharesectionids'] = [];
                $sphinx_data['sharecomboids'] = [];
                $sphinx->save($sphinx_data);
            }
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($organization);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 医院列表
     */
    public function listAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns([
                'O.Id', 'O.Name', 'O.MerchantCode', 'O.CreateTime', 'O.Contact', 'O.ContactTel', 'O.Phone', 'O.ProvinceId', 'O.CityId', 'O.AreaId', 'O.LevelId',
                'O.Verifyed', 'O.Type', 'O.Score', 'O.TransferAmount', 'O.RuleId', 'O.IsMain', 'O.Expire',
                'LP.Name as Province', 'LC.Name as City', 'LA.Name as Area',
            ])
            ->addFrom(Organization::class, 'O')
            ->join(Location::class, 'LP.Id=O.ProvinceId', 'LP', 'left')
            ->join(Location::class, 'LC.Id=O.CityId', 'LC', 'left')
            ->join(Location::class, 'LA.Id=O.AreaId', 'LA', 'left')
            ->notInWhere('O.Id', [Organization::PEACH])
            ->andWhere('O.IsMain!=' . Organization::ISMAIN_SLAVE);
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
        if (!empty($data['AreaId']) && isset($data['AreaId']) && is_numeric($data['AreaId'])) {
            $query->andWhere("O.AreaId=:AreaId:", ['AreaId' => $data['AreaId']]);
        }
        //类型
        if (!empty($data['Type']) && isset($data['Type']) && is_numeric($data['Type'])) {
            $query->andWhere("O.Type=:Type:", ['Type' => $data['Type']]);
        }
        //共享状态
        if (!empty($data['Verifyed']) && isset($data['Verifyed']) && is_numeric($data['Verifyed'])) {
            $query->andWhere("O.Verifyed=:Verifyed:", ['Verifyed' => $data['Verifyed']]);
        }
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
        //医院过期状态
        if (isset($data['IsExpired']) && is_numeric($data['IsExpired'])) {
            //0=>正常,1=>过期
            $expiredConditions = $data['IsExpired'] == 1 ? "O.Expire<:Expire:" : "O.Expire>=:Expire:";
            if ($data['IsExpired']) {
                $query->andWhere($expiredConditions . ' or O.Expire is null', ['Expire' => date('Y-m-d')]);
                $query->andWhere('O.IsMain=' . Organization::ISMAIN_HOSPITAL);
            } else {
                $query->andWhere($expiredConditions . ' or O.IsMain=' . Organization::ISMAIN_SUPPLIER, ['Expire' => date('Y-m-d')]);
            }
        }
        $query->orderBy('O.CreateTime desc');
        //导出表格
        if (isset($data['Export']) && !empty($data['Export'])) {
            $csv = new AdminCsv($query);
            $csv->hospital();
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
            $data['ExpireStatus'] = date('Y-m-d') > $data['Expire'] ? ($data['IsMain'] == Organization::ISMAIN_HOSPITAL ? '到期' : '正常') : '正常';
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 医院详情
     */
    public function readAction()
    {
        $hospital = Organization::findFirst(sprintf('Id=%d', $this->request->get('Id', 'int')));
        if (!$hospital) {
            return $this->response->setStatusCode(Status::BadRequest);
        }
        $admin = OrganizationUser::findFirst([
            'conditions' => 'OrganizationId=?0 and Label=?1',
            'bind'       => [$hospital->Id, OrganizationUser::LABEL_ADMIN],
        ]);
        $result = [];
        if ($admin) {
            $result = $admin->toArray();
            $result['UserName'] = $admin->User->Name;
        }
        $rule = RuleOfShare::findFirst([
            'conditions' => 'Id=?0',
            'bind'       => [$hospital->RuleId],
        ]);
        if ($rule) {
            $rule = $rule->toArray();
            $rule['RuleId'] = $rule['Id'];
            unset($rule['OrganizationId'], $rule['Intro'], $rule['Name'], $rule['Id'], $rule['Type']);
            $ruleOfShareSubs = RuleOfShareSub::find([
                'conditions' => 'RuleOfShareId=?0',
                'bind'       => [$rule['RuleId']],
            ])->toArray();
            $ruleOfShareSubs_new = [];
            if (count($ruleOfShareSubs)) {
                foreach ($ruleOfShareSubs as $shareSub) {
                    $ruleOfShareSubs_new[] = ['Min' => $shareSub['MinAmount'], 'Max' => $shareSub['MaxAmount'], 'Value' => $shareSub['Value']];
                }
            }
            $result = array_merge($result, $rule);
            $result['Ratio'] = $ruleOfShareSubs_new;
        } else {
            $result['Ratio'][] = ['Max' => null, 'Min' => 0, 'Value' => 0];
        }
        return $this->response->setJsonContent(array_merge($hospital->toArray(), $result));
    }

    /**
     * 医院操作记录查询
     */
    public function logsAction()
    {
        $query = $this->modelsManager->createBuilder()
            ->columns('SH.Id,SH.OrganizationId,SH.StaffId,SH.Operated,SH.Created,S.Name')
            ->addFrom(StaffHospitalLog::class, 'SH')
            ->join(Staff::class, 'S.Id=SH.StaffId', 'S', 'left')
            ->where('SH.OrganizationId=:OrganizationId:', ['OrganizationId' => $this->request->get('Id', 'int')])
            ->orderBy('SH.Id Desc')
            ->getQuery()
            ->execute();
        $this->response->setJsonContent($query);
    }

    /**
     * 该医院的人员列表
     */
    public function userListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['U.Id', 'U.Name', 'U.Sex', 'U.Phone', 'OU.OrganizationId', 'OU.Image', 'OU.Role', 'OU.SectionId', 'OU.IsDoctor', 'OU.Label', 'OU.Switch'])
            ->addFrom(OrganizationUser::class, 'OU')
            ->join(User::class, 'U.Id=OU.UserId', 'U', 'left')
            ->where("OU.OrganizationId=:OrganizationId:", ['OrganizationId' => $data['OrganizationId']])
            ->orderBy('OU.Role asc,OU.Label asc');
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
     * 该医院的角色列表
     */
    public function roleListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = Role::query()
            ->where(sprintf('OrganizationId=%d', $data['OrganizationId']));
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
     * 替换管理员
     */
    public function replaceAdminAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $organizationId = $this->request->getPut('OrganizationId', 'int');
            $organization = Organization::findFirst(sprintf('Id=%d', $organizationId));
            if (!$organization) {
                throw $exception;
            }
            $role = $organization->IsMain == Organization::ISMAIN_HOSPITAL ? Role::DEFAULT_B : Role::DEFAULT_SUPPLIER;
            $adminId = $this->request->getPut('AdminId', 'int');
            $old_admin = OrganizationUser::findFirst([
                'conditions' => 'Role=?0 and OrganizationId=?1',
                'bind'       => [$role, $organizationId],
            ]);
            if ($old_admin->UserId == $adminId) {
                return true;
            }
            $replace_admin = OrganizationUser::findFirst([
                'conditions' => 'UserId=?0 and OrganizationId=?1',
                'bind'       => [$adminId, $organizationId],
            ]);
            if (!$old_admin || !$replace_admin) {
                throw new LogicException('人员错误', Status::BadRequest);
            }
            $phone = $replace_admin->User->Phone;
            $existOrganization = Organization::findFirst([
                'conditions' => 'Id!=?0 and Phone=?1',
                'bind'       => [$organization->Id, $phone],
            ]);
            if ($existOrganization) {
                throw new LogicException('一个用户只能是一家机构的管理员', Status::BadRequest);
            }
            $this->db->begin();
            //将老管理员变成非管理员
            $old_admin->Role = $replace_admin->Role;
            $old_admin->Label = $replace_admin->Label;
            if ($old_admin->save() === false) {
                $exception->loadFromModel($old_admin);
                throw $exception;
            }
            //设置管理员
            $replace_admin->Role = $role;
            $replace_admin->Label = OrganizationUser::LABEL_ADMIN;
            if ($replace_admin->save() === false) {
                $exception->loadFromModel($replace_admin);
                throw $exception;
            }
            //替换organization管理员phone
            $organization->Phone = $phone;
            if ($organization->save() === false) {
                $exception->loadFromModel($organization);
                throw $exception;
            }
            $this->db->commit();
            //清理缓存
            $this->redis->del(RedisName::Permission . $organizationId . '_' . $old_admin->UserId);
            $this->redis->del(RedisName::Permission . $organizationId . '_' . $replace_admin->UserId);
            return $this->response->setStatusCode(Status::Created);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 二级供应商升级成一级供应商
     */
    public function upgradeAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            /** @var Organization $organization */
            $organization = Organization::findFirst(sprintf('Id=%d', $data['OrganizationId']));
            if (!$organization) {
                throw $exception;
            }

            if (!is_array($data['Ratio']) || empty($data['Ratio'])) {
                throw new LogicException('平台手续费参数不能为空', Status::BadRequest);
            }
            $ratio = 0;
            foreach ($data['Ratio'] as $datum) {
                $ratio = $datum['Value'];
                break;
            }

            //设置验证场景
            $organization->setScene(Organization::SCENE_ADMIN_HOSPITAL_CREATE);
            $shareRule = new RuleOfShare();
            $shareRule->setScene(RuleOfShare::SCENE_ADMIN_HOSPITAL_CREATE);
            $shareRule->setScene(RuleOfShare::SCENE_ADMIN_HOSPITAL_CREATE);
            $shareRule->Fixed = 0;
            $shareRule->Ratio = $ratio;
            $shareRule->DistributionOutB = $data['DistributionOutB'];
            $shareRule->DistributionOut = $data['DistributionOut'];
            $shareRule->Type = RuleOfShare::RULE_RATIO;
            $shareRule->Remark = '按合同';
            $shareRule->Name = $organization->Name . '佣金规则';
            $shareRule->OrganizationId = $organization->Id;
            $shareRule->CreateOrganizationId = Organization::PEACH;
            if ($shareRule->save() === false) {
                $exception->loadFromModel($shareRule);
                throw $exception;
            }
            foreach ($data['Ratio'] as $datum) {
                /** @var RuleOfShareSub $ruleOfShareOfSub */
                $ruleOfShareOfSub = new RuleOfShareSub();
                $ruleOfShareOfSub->RuleOfShareId = $shareRule->Id;
                $ruleOfShareOfSub->MinAmount = $datum['Min'];
                $ruleOfShareOfSub->MaxAmount = is_numeric($datum['Max']) && $datum['Max'] > 0 ? $datum['Max'] : null;
                $ruleOfShareOfSub->IsFixed = RuleOfShareSub::IS_FIXED_NO;
                $ruleOfShareOfSub->Value = $datum['Value'];
                if ($ruleOfShareOfSub->save() === false) {
                    $exception->loadFromModel($ruleOfShareOfSub);
                    throw $exception;
                }
            }
            $data['RuleId'] = $shareRule->Id;
            $data['IsMain'] = Organization::ISMAIN_HOSPITAL;
            unset($data['Money'], $data['Balance']);
            if ($organization->save($data) === false) {
                $exception->loadFromModel($organization);
                throw $exception;
            }

            //修改管理员权限
            $organizationUser = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and Label=?1',
                'bind'       => [$organization->Id, OrganizationUser::LABEL_ADMIN],
            ]);
            $organizationUser->Role = Role::DEFAULT_B;
            if ($organizationUser->save() === false) {
                $exception->loadFromModel($organizationUser);
                throw $exception;
            }
            $this->redis->delete(RedisName::Permission . $organizationUser->OrganizationId . '_' . $organizationUser->UserId);

            $this->db->commit();
            //发送短信给商户
            $content = MessageTemplate::load('account_supplier_to_major', MessageTemplate::METHOD_SMS);
            $sms = new Sms($this->queue);
            $sms->sendMessage((string)$organizationUser->User->Phone, $content);
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($organization);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 查看上级医院列表
     */
    public function superiorsAction()
    {
        $superiors = $this->modelsManager->createBuilder()
            ->columns(['R.MainId', 'R.MinorId', 'O.Name as HospitalName', 'O.MerchantCode', 'OS.Ratio as PlatformFee', 'S.Ratio', 'S.DistributionOut'])
            ->addFrom(OrganizationRelationship::class, 'R')
            ->leftJoin(Organization::class, 'O.Id=R.MainId', 'O')
            ->leftJoin(RuleOfShare::class, 'S.Id=R.RuleId', 'S')
            ->leftJoin(RuleOfShare::class, 'OS.Id=O.RuleId', 'OS')
            ->where('R.MinorId=:MinorId:', ['MinorId' => $this->request->get('OrganizationId')])
            ->getQuery()->execute();
        $this->response->setJsonContent($superiors);
    }

    /**
     * 查看该医院的所有供应商
     */
    public function suppliersAction()
    {
        $suppliers = $this->modelsManager->createBuilder()
            ->columns(['R.MainId', 'R.MinorId', 'O.Name as HospitalName', 'O.MerchantCode'])
            ->addFrom(OrganizationRelationship::class, 'R')
            ->leftJoin(Organization::class, 'O.Id=R.MinorId', 'O')
            ->where('R.MainId=:MainId:', ['MainId' => $this->request->get('OrganizationId')])
            ->inWhere('O.IsMain', [Organization::ISMAIN_HOSPITAL, Organization::ISMAIN_SUPPLIER])
            ->getQuery()->execute();
        $this->response->setJsonContent($suppliers);
    }

    /**
     * 修改一级医院和二级医院的手续费
     */
    public function updateSupplierFeeAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            $rule = RuleOfShare::findFirst([
                'conditions' => 'CreateOrganizationId=?0 and OrganizationId=?1',
                'bind'       => [$data['MainId'], $data['MinorId']],
            ]);
            if (!$rule) {
                throw $exception;
            }
            $withe = ['Ratio', 'DistributionOut'];
            if ($rule->save($data, $withe) === false) {
                $exception->loadFromModel($rule);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 根据id获取机构名称
     */
    public function getNamesAction()
    {
        $ids = $this->request->get('Id');
        if (is_array($ids) && count($ids)) {
            $organizations = Organization::query()
                ->columns(['Id', 'Name'])
                ->inWhere('Id', $ids)
                ->execute();
            $result = [];
            if (count($organizations->toArray())) {
                foreach ($organizations as $organization) {
                    $result[] = ['Id' => $organization->Id, 'Name' => $organization->Name];
                }
            }
            $this->response->setJsonContent($result);
        }
    }

    /**
     * 模块列表
     */
    public function moduleListAction()
    {
        $module = Module::find(['conditions' => "ModuleCode!='M_HOSPITAL'"]);
        $defaultModule = array_column(DefaultModule::find()->toArray(), 'ModuleCode');
        $result = [];
        $parents = [];
        $children = [];
        foreach ($module as $k => $item) {
            /** @var Module $item */
            if (in_array($item->ModuleCode, $defaultModule)) {
                continue;
            }
            if (empty($item->ParentCode)) {
                $parents[] = $item->toArray();
            } else {
                $children[$item->ParentCode][] = $item->toArray();
            }
        }
        $organizationModule = OrganizationModule::find([
            'conditions' => 'OrganizationId=?0',
            'bind'       => [$this->request->get('OrganizationId')],
        ])->toArray();

        foreach ($parents as $parent) {
            $parent['Children'] = isset($children[$parent['ModuleCode']]) ? $children[$parent['ModuleCode']] : [];
            $parent['Checked'] = false;
            $parent['ValidTimeBeg'] = '';
            $parent['ValidTimeEnd'] = '';
            if (!empty($organizationModule)) {
                foreach ($organizationModule as $item) {
                    if ($item['ModuleCode'] == $parent['ModuleCode']) {
                        $parent['Checked'] = true;
                        $parent['ValidTimeBeg'] = $item['ValidTimeBeg'];
                        $parent['ValidTimeEnd'] = $item['ValidTimeEnd'];
                        break;
                    }
                }

                if (!empty($parent['Children'])) {
                    foreach ($parent['Children'] as &$child) {
                        $child['Checked'] = false;
                        foreach ($organizationModule as $value) {
                            if ($child['ModuleCode'] == $value['ModuleCode']) {
                                $child['Checked'] = true;
                                break;
                            }
                        }
                    }
                }
            }
            $result[] = $parent;
        }


        $this->response->setJsonContent($result);
    }
}