<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/8
 * Time: 下午3:55
 */

namespace App\Controllers;


use App\Enums\DoctorTitle;
use App\Enums\HospitalLevel;
use App\Enums\MessageTemplate;
use App\Enums\OrganizationType;
use App\Enums\RedisName;
use App\Enums\SmsExtend;
use App\Enums\SmsTemplateNo;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\csv\FrontCsv;
use App\Libs\Rule;
use App\Libs\Sms;
use App\Libs\Sphinx;
use App\Models\HospitalOfAptitude;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationBanner;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\ProfitGroup;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTempCache;
use Phalcon\Http\Response;
use Phalcon\Paginator\Adapter\QueryBuilder;

class OrganizationController extends Controller
{
    /**
     * 创建网点
     * 一个手机号只能创建一个网点
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $this->db->begin();
                $auth = $this->session->get('auth');
                if (!$auth) {
                    throw new LogicException('未登录', Status::Unauthorized);
                }
                $data = $this->request->getPost();
                $now = time();
                $data['Phone'] = trim($data['Phone']);
                $data['IsMain'] = Organization::ISMAIN_SLAVE;
                $rule = new Rule();
                $merchantCode = $rule->MerchanCode($data['ProvinceId'], $data['IsMain']);
                $data['MerchantCode'] = $merchantCode;
                $data['CreateTime'] = $now;
                if (empty($data['RuleId']) || !isset($data['RuleId'])) {
                    $exception->add('RuleId', '请选择网点分组');
                    throw $exception;
                }
                $profitGroup = ProfitGroup::findFirst([
                    'conditions' => 'Id=?0 and OrganizationId=?1',
                    'bind'       => [$data['RuleId'], $auth['OrganizationId']],
                ]);
                if (!$profitGroup) {
                    $exception->add('RuleId', '网点分组错误');
                    throw $exception;
                }
                //创建管理员
                $oldUser = User::findFirst([
                    'conditions' => 'Phone=?0',
                    'bind'       => [$data['Phone']],
                ]);
                $organizationUser = new OrganizationUser;
                $defaultPassword = substr($data['Phone'], -6, 6);
                if (!$oldUser) {
                    $user = new User();
                    //设置验证场景
                    $user->setScene(User::SCENE_ORGANIZATION_CREATE);
                    $userData = [];
                    $userData['Name'] = (isset($data['Contact']) ? $data['Contact'] : $data['Name']);
                    $userData['Phone'] = $data['Phone'];
                    $userData['Password'] = $this->security->hash($defaultPassword);
                    if ($user->save($userData) === false) {
                        $exception->loadFromModel($user);
                        throw $exception;
                    }
                    $userData['UserId'] = $user->Id;
                } else {
                    $userData['UserId'] = $oldUser->Id;
                    $user = $oldUser;
                }
                $oldOrganization = Organization::findFirst([
                    'conditions' => 'Phone=?0 and IsMain=?1',
                    'bind'       => [$user->Phone, Organization::ISMAIN_SLAVE],
                ]);
                if (!$oldOrganization) {
                    //新建网点
                    $organization = new Organization();
                    //设置验证场景
                    $organization->setScene(Organization::SCENE_ORGANIZATION_CREATE);
                    $whiteList = ['IsMain', 'MerchantCode', 'CreateTime', 'Name', 'LevelId', 'ProvinceId', 'CityId', 'AreaId', 'Address', 'Contact', 'Tel', 'Phone', 'Logo', 'Type', 'Lat', 'Lng', 'License'];
                    if ($organization->save($data, $whiteList) === false) {
                        $exception->loadFromModel($organization);
                        throw $exception;
                    }
                } else {
                    if (OrganizationRelationship::findFirst(["MainId=:MainId: and MinorId=:MinorId:", "bind" => ["MainId" => $auth['HospitalId'], "MinorId" => $oldOrganization->Id]])) {
                        $exception->add('Phone', '该账号已经存在');
                        throw $exception;
                    }
                    $organization = $oldOrganization;
                }
                /** @var Organization $mainOrganization */
                $mainOrganization = Organization::findFirst(sprintf('Id=%d', $auth['OrganizationId']));
                $code = mt_rand(100000, 999999);
                $create_user = false;
                if (!OrganizationUser::findFirst(['conditions' => 'OrganizationId=?0 and UserId=?1', 'bind' => [$organization->Id, $userData['UserId']]])) {
                    $userData['OrganizationId'] = $organization->Id;
                    $userData['CreateTime'] = $now;
                    $userData['Role'] = Role::DEFAULT_b;
                    $userData['Display'] = OrganizationUser::DISPLAY_OFF;
                    $userData['UseStatus'] = OrganizationUser::USESTATUS_ON;
                    $userData['Image'] = OrganizationUser::DEFAULT_SLAVE_IMAGE;
                    /*if ($organizationUser->save($userData) === false) {
                        $exception->loadFromModel($organizationUser);
                        throw $exception;
                    }*/
                    $organizationUser->validation();
                    $organizationUser->assign($userData);
                    if (UserTempCache::findFirst(['conditions' => 'Phone=?0 and Code=?1', 'bind' => [$data['Phone'], SmsExtend::CODE_CREATE_ADMIN_SLAVE]])) {
                        throw new LogicException('已创建，请勿重复提交', Status::BadRequest);
                    }
                    $cache = new UserTempCache();
                    $cache->Phone = $data['Phone'];
                    $cache->MerchantCode = $code;
                    $cache->Content = serialize($organizationUser);
                    // $cache->Message = MessageTemplate::load('account_create_slave', MessageTemplate::METHOD_SMS, $mainOrganization->Name, $mainOrganization->MerchantCode, $user->Phone, $oldUser ? '未改变' : $defaultPassword, $mainOrganization->Tel ? $mainOrganization->Tel : '');
                    //修改为json字符串
                    $cache->Message = json_encode([
                        'hospitalname'  => $mainOrganization->Name,
                        'merchantno'    => $mainOrganization->MerchantCode,
                        'loginname'     => $user->Phone,
                        'loginpass'     => $oldUser ? '未改变' : $defaultPassword,
                        'hospitalphone' => $mainOrganization->Tel ?: '',
                    ]);
                    $cache->Code = SmsExtend::CODE_CREATE_ADMIN_SLAVE;
                    if ($cache->save() === false) {
                        $exception->loadFromModel($cache);
                        throw $exception;
                    }
                    $create_user = true;
                }
                //与上级单位建立关联关系
                $organzaitionShip = new OrganizationRelationship();
                $info = [];
                $info['SalesmanId'] = $data['SalesmanId'];
                if (!isset($data['SalesmanId'])) {
                    $info['SalesmanId'] = $auth['Id'];
                }
                $info['RuleId'] = $data['RuleId'];
                $info['MainId'] = $mainOrganization->Id;
                $info['MinorId'] = $organization->Id;
                $info['MainName'] = $mainOrganization->Name;
                $info['MinorName'] = $organization->Name;
                $info['MinorType'] = $data['Type'];
                $info['Created'] = $now;
                $info['Way'] = (isset($data['Way']) && in_array((int)$data['Way'], [OrganizationRelationship::WAY_PC, OrganizationRelationship::WAY_MOBILE_WEB])) ? (int)$data['Way'] : OrganizationRelationship::WAY_PC;
                if ($organzaitionShip->save($info) === false) {
                    $exception->loadFromModel($organzaitionShip);
                    throw $exception;
                }
                $this->db->commit();
                //写入sphinx
                $sphinx = new Sphinx($this->sphinx, 'organization');
                $sphinx_data = array_change_key_case($organization->toArray(), CASE_LOWER);
                $sphinx_data['alias'] = $organization->Name;
                $sphinx_data['pids'] = array_column(OrganizationRelationship::find([
                    'conditions' => 'MinorId=?0',
                    'bind'       => [$organization->Id],
                ])->toArray(), 'MainId');
                $sphinx_data['sharesectionids'] = [];
                $sphinx_data['sharecomboids'] = [];
                $sphinx->save($sphinx_data);
                //短信通知
                if ($create_user) {
                    // $content = sprintf(SmsExtend::CODE_CREATE_ADMIN_SLAVE_MESSAGE, $mainOrganization->Name, $code);
                    $templateParam = [
                        'hospitalname' => $mainOrganization->Name,
                        'activatecode' => $code,
                    ];
                } else {
                    // $content = sprintf(SmsExtend::CODE_CREATE_RELATION_SLAVE_MESSAGE, $mainOrganization->Name, $mainOrganization->MerchantCode);
                    $templateParam = [
                        'hospitalname' => $mainOrganization->Name,
                        'activatecode' => $mainOrganization->MerchantCode,
                    ];
                }
                // $sms = new Sms($this->queue);
                // $sms->sendMessage((string)$user->Phone, $content, SmsExtend::CODE_CREATE_ADMIN_SLAVE);
                //改为java发送短信
                Sms::useJavaSendMessage((string)$user->Phone, SmsTemplateNo::CREATE_SLAVE, $templateParam);
                $this->response->setStatusCode(Status::Created);
                $this->response->setJsonContent($organization);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
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
     * 大B修改小B
     */
    public function updateAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $data = $this->request->getPut();
                $auth = $this->session->get('auth');
                if (empty($data['RuleId']) || !isset($data['RuleId'])) {
                    $exception->add('RuleId', '请选择网点分组');
                    throw $exception;
                }
                $organization = OrganizationRelationship::findFirst([
                    "MainId=:MainId: and MinorId=:MinorId:",
                    "bind" => ['MainId' => $auth['OrganizationId'], 'MinorId' => $data['Id']],
                ]);
                if (!$organization) {
                    throw $exception;
                }
                if ($organization->RuleId != $data['RuleId']) {
                    $profitGroup = ProfitGroup::findFirst([
                        'conditions' => 'Id=?0 and OrganizationId=?1',
                        'bind'       => [$data['RuleId'], $auth['OrganizationId']],
                    ]);
                    if (!$profitGroup) {
                        $exception->add('RuleId', '网点分组错误');
                        throw $exception;
                    }
                }
                unset($data['Money'], $data['Balance']);
                if ($organization->save($data) === false) {
                    $exception->loadFromModel($organization);
                    throw $exception;
                }
                //写入sphinx
                $sphinx = new Sphinx($this->sphinx, 'organization');
                $sphinx_data = array_change_key_case(Organization::findFirst($organization->MinorId)->toArray(), CASE_LOWER);
                $sphinx_data['alias'] = $organization->MinorName;
                $sphinx_data['type'] = $organization->MinorType;
                $sphinx_data['sharesectionids'] = [];
                $sphinx_data['sharecomboids'] = [];
                $sphinx_data['pids'] = array_column(OrganizationRelationship::find([
                    'conditions' => 'MinorId=?0',
                    'bind'       => [$organization->MinorId],
                ])->toArray(), 'MainId');
                $sphinx->save($sphinx_data);
                $this->response->setStatusCode(Status::Created);
                $this->response->setJsonContent($organization);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function readAction()
    {
        $query = $this->modelsManager->createBuilder()
            ->columns([
                'R.MainId', 'R.MinorId', 'R.MainName', 'R.MinorName', 'R.SalesmanId', 'R.RuleId', 'R.MinorType', 'R.Created as CreateTime',
                'O.MerchantCode', 'O.Contact', 'O.Phone', 'O.ProvinceId', 'O.CityId', 'O.AreaId', 'O.TransferAmount', 'O.MachineOrgId',
                'O.LevelId', 'O.Address', 'O.ContactTel', 'O.Logo', 'O.Type', 'O.Lat', 'O.Lng', 'O.MerchantCode', 'O.License',
                'MU.Name as Salesman', 'SU.LastLoginTime', 'SU.UserId', 'SU.UseStatus',
                'LP.Name as Province', 'LC.Name as City', 'LA.Name as Area',
                'PG.Name as RuleName',
            ])
            ->addFrom(OrganizationRelationship::class, 'R')
            ->leftJoin(Organization::class, 'O.Id=R.MinorId', 'O')
            ->leftJoin(ProfitGroup::class, 'PG.Id=R.RuleId', 'PG')
            ->leftJoin(OrganizationUser::class, 'OU.OrganizationId=O.Id', 'OU')
            ->leftJoin(User::class, 'U.Id=OU.UserId', 'U')
            ->leftJoin(OrganizationUser::class, 'SU.UserId=R.SalesmanId', 'SU')
            ->leftJoin(User::class, 'MU.Id=SU.UserId', 'MU')
            ->join(Location::class, 'LP.Id=O.ProvinceId', 'LP', 'left')
            ->join(Location::class, 'LC.Id=O.CityId', 'LC', 'left')
            ->join(Location::class, 'LA.Id=O.AreaId', 'LA', 'left')
            ->where('R.MainId=:MainId:', ['MainId' => $this->user->OrganizationId])
            ->andWhere('R.MinorId=:MinorId:', ['MinorId' => $this->request->get('Id', 'int')])
            ->andWhere('O.Fake=0')
            ->limit(1)
            ->getQuery()->execute()->toArray();
        $result = $query[0];
        $result['TypeName'] = OrganizationType::value($result['Type']);
        $result['LevelName'] = HospitalLevel::value($result['LevelId']);
        $result['Machine'] = $result['MachineOrgId'] ? '是' : '否';
        $this->response->setJsonContent($result);
    }

    public function listAction()
    {
        $response = new Response();
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns([
                'R.MainId', 'R.MinorId', 'R.MainName', 'R.MinorName', 'R.SalesmanId', 'R.RuleId', 'R.MinorType', 'R.Created as CreateTime',
                'O.MerchantCode', 'O.Contact', 'O.Phone', 'O.ProvinceId', 'O.CityId', 'O.AreaId', 'O.TransferAmount', 'O.MachineOrgId',
                'U.Name as Salesman', 'MU.LastLoginTime', 'MU.UserId', 'MU.UseStatus',
                'LP.Name as Province', 'LC.Name as City', 'LA.Name as Area',
                'PG.Name as RuleName',
            ])
            ->addFrom(OrganizationRelationship::class, 'R')
            ->join(Organization::class, 'O.Id=R.MinorId', 'O', 'left')
            ->join(User::class, 'U.Id=R.SalesmanId', 'U', 'left')
            ->join(OrganizationUser::class, 'OU.OrganizationId=R.MainId and OU.UserId=R.SalesmanId', 'OU', 'left')
            ->join(OrganizationUser::class, 'MU.OrganizationId=O.Id', 'MU', 'left')
            ->join(Location::class, 'LP.Id=O.ProvinceId', 'LP', 'left')
            ->join(Location::class, 'LC.Id=O.CityId', 'LC', 'left')
            ->join(Location::class, 'LA.Id=O.AreaId', 'LA', 'left')
            ->join(ProfitGroup::class, 'PG.Id=R.RuleId', 'PG', 'left')
            ->where('R.MainId=:MainId:', ['MainId' => $this->user->OrganizationId])
            ->andWhere('O.IsMain=:IsMain:', ['IsMain' => Organization::ISMAIN_SLAVE])
            ->andWhere('O.Fake=0');
        //机构编码
        if (!empty($data['MerchantCode']) && isset($data['MerchantCode'])) {
            $query->andWhere("O.MerchantCode = :MerchantCode:", ['MerchantCode' => $data['MerchantCode']]);
        }
        //联系人电话
        if (!empty($data['Phone']) && isset($data['Phone'])) {
            $query->andWhere("O.Phone = :Phone:", ['Phone' => $data['Phone']]);
        }
        //联系人姓名
        $sphinx = new Sphinx($this->sphinx, 'organization');
        if (!empty($data['Contact']) && isset($data['Contact'])) {
            $contact = $sphinx->match($data['Contact'], 'contact')->fetchAll();
            $ids = array_column($contact ? $contact : [], 'id');
            if (count($ids)) {
                $query->andWhere('R.MinorId in ' . sprintf('(%s)', implode(',', $ids)));
            } else {
                $query->andWhere('R.MinorId=-1');
            }
        }
        //下游别名
        if (!empty($data['MinorName']) && isset($data['MinorName'])) {
            $name = $sphinx->match($data['MinorName'], 'alias')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('R.MinorId', $ids);
            } else {
                $query->andWhere('R.MinorId=0');
            }
        }
        //网点类型
        if (!empty($data['MinorType']) && isset($data['MinorType'])) {
            $query->andWhere("R.MinorType = :MinorType:", ['MinorType' => $data['MinorType']]);
        }
        //销售人员
        if (!empty($data['SalesmanId']) && isset($data['SalesmanId']) && is_numeric($data['SalesmanId'])) {
            $query->andWhere("R.SalesmanId=:SalesmanId:", ['SalesmanId' => $data['SalesmanId']]);
        }
        //分润组
        if (!empty($data['RuleId']) && isset($data['RuleId']) && is_numeric($data['RuleId'])) {
            $query->andWhere("R.RuleId=:RuleId:", ['RuleId' => $data['RuleId']]);
        }
        //是否有一体机
        if (isset($data['IsMachine']) && is_numeric($data['IsMachine'])) {
            $query->andWhere($data['IsMachine'] == 0 ? "O.MachineOrgId=:MachineOrgId:" : "O.MachineOrgId>:MachineOrgId:", ['MachineOrgId' => 0]);
        }
        //创建时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("R.Created>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                $response->setStatusCode(Status::BadRequest);
                return $response;
            }
            $query->andWhere("R.Created<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        $query->orderBy('R.Created desc');
        //导出csv
        if (isset($data['Export']) && !empty($data['Export'])) {
            $csv = new FrontCsv($query);
            $csv->slaveList();
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
        foreach ($datas as &$v) {
            $v['MinorTypeName'] = OrganizationType::value($v['MinorType']);
            $v['Count'] = $v['TransferAmount'];
            $v['Machine'] = $v['MachineOrgId'] ? '是' : '否';
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $response->setJsonContent($result);
        return $response;
    }

    public function allAction()
    {
        $response = new Response();
        $data = $this->request->getPost();
        $auth = $this->session->get('auth');
        $organization = Organization::findFirst(sprintf('Id=%d', $auth['OrganizationId']));
        if (!$organization) {
            $response->setStatusCode(Status::BadRequest);
            return $response;
        }
        if ($organization->Id != Organization::PEACH) {
            $response->setStatusCode(Status::Forbidden);
            return $response;
        }
        $query = Organization::query();
        $query->where('Id!=0');
        $bind = [];
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        //所在省
        if (!empty($data['ProvinceId']) && isset($data['ProvinceId'])) {
            $query->andWhere("ProvinceId = :ProvinceId:");
            $bind['ProvinceId'] = $data['ProvinceId'];
        }
        //所在城市
        if (!empty($data['CityId']) && isset($data['CityId'])) {
            $query->andWhere("CityId = :CityId:");
            $bind['CityId'] = $data['CityId'];
        }
        //联系人姓名
        $sphinx = new Sphinx($this->sphinx, 'organization');
        if (!empty($data['Contact']) && isset($data['Contact'])) {
            $contact = $sphinx->match($data['Contact'], 'contact')->fetchAll();
            $ids = array_column($contact ? $contact : [], 'id');
            if (count($ids)) {
                $query->andWhere('Id in ' . sprintf('(%s)', implode(',', $ids)));
            } else {
                $query->andWhere('Id=-1');
            }
        }
        //所在区域
        if (!empty($data['AreaId']) && isset($data['AreaId'])) {
            $query->andWhere("AreaId = :AreaId:");
            $bind['AreaId'] = $data['AreaId'];
        }
        //机构名字
        if (!empty($data['Name']) && isset($data['Name'])) {
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->andWhere('Id in ' . sprintf('(%s)', implode(',', $ids)));
            } else {
                $query->andWhere('Id=-1');
            }
        }
        //开户时间
        if (!empty($data['CreateTime']) && isset($data['CreateTime'])) {
            $query->andWhere("CreateTime>:CreateTime:");
            $bind['CreateTime'] = strtotime($data['CreateTime']);
        }
        //是否为医院
        if (!empty($data['IsMain']) && isset($data['IsMain'])) {
            $query->andWhere("IsMain=:IsMain:");
            $bind['IsMain'] = $data['IsMain'];
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
        $datas = $pages->items->toArray();
        $location = Location::query()
            ->columns('Id,Name')
            ->inWhere('Id', array_unique(array_merge(array_column($datas, 'ProvinceId'), array_column($datas, 'CityId'), array_column($datas, 'AreaId'))))
            ->execute();
        $location_new = [];
        foreach ($location as $v) {
            $location_new[$v['Id']] = $v['Name'];
        }
        foreach ($datas as &$v) {
            $v['Province'] = $location_new[$v['ProvinceId']];
            $v['City'] = $location_new[$v['CityId']];
            $v['Area'] = $location_new[$v['AreaId']];
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $response->setJsonContent($result);
        return $response;
    }

    public function bannerAction()
    {
        $auth = $this->session->get('auth');
        $id = $auth['HospitalId'];
        /**
         * @var  \Phalcon\Mvc\Model\Criteria $criteria
         */
        $criteria = OrganizationBanner::query();
        $criteria->where('Type=?0 and OrganizationId IN (?1, ?2)')->orderBy("OrganizationId={$auth['HospitalId']} desc,Sort asc");
        $criteria->bind([
            $this->request->get('Type', null, OrganizationBanner::PLATFORM_APP),
            Organization::PEACH,
            $id,
        ]);
        $this->response->setJsonContent($criteria->execute());
    }

    public function typeListAction()
    {
        $response = new Response();
        $types = OrganizationType::map();
        $response->setJsonContent($types);
        return $response;
    }

    public function levelListAction()
    {
        $response = new Response();
        $types = HospitalLevel::map();
        $response->setJsonContent($types);
        return $response;
    }

    public function doctorTitleAction()
    {
        $response = new Response();
        $types = DoctorTitle::map();
        $response->setJsonContent($types);
        return $response;
    }

    /**
     * 医院账户信息
     */
    public function accountAction()
    {
        $response = new Response();
        $organizationId = $this->user->OrganizationId;
        $organization = Organization::findFirst(sprintf('Id=%d', $organizationId));
        if ($organization->IsMain === 2) {
            $response->setStatusCode(Status::Forbidden);
            return $response;
        }
        $result['Balance'] = $organization->Balance;
        $result['Money'] = $organization->Money;
        $response->setJsonContent($result);
        return $response;
    }

    /**
     * 展示医院到logo和简介  并修改
     */
    public function introAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $organizationId = $this->user->OrganizationId;
            $organization = Organization::findFirst(sprintf('Id=%d', $organizationId));
            if ($this->request->isPut()) {
                $organization->Logo = $this->request->getPut('Logo');
                $organization->Intro = $this->request->getPut('Intro');
                $organization->Tel = $this->request->getPut('Tel');
                //设置验证场景
                $organization->setScene(Organization::SCENE_ORGANIZATION_INTRO);
                if ($organization->save() === false) {
                    $exception->loadFromModel($organization);
                    throw $exception;
                }
            }
            $result['Logo'] = $organization->Logo;
            $result['Intro'] = $organization->Intro;
            $result['Tel'] = $organization->Tel;
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 医院的banner
     */
    public function selfBannerAction()
    {
        /**
         * @var  \Phalcon\Mvc\Model\Criteria $criteria
         */
        $criteria = OrganizationBanner::query();
        $banner = $criteria->where('OrganizationId=?0', [$this->user->OrganizationId])->orderBy('Type asc')->execute()->toArray();
        $result = $banner;
        $this->response->setJsonContent($result);
    }

    /**
     * 医院的共享申请状态
     */
    public function verifyAction()
    {
        $response = new Response();
        $hospital = Organization::findFirst(sprintf('Id=%d', $this->user->OrganizationId))->toArray();
        $aptitude = HospitalOfAptitude::findFirst(sprintf('OrganizationId=%d', $this->user->OrganizationId));
        $result = $hospital;
        if ($aptitude) {
            $result = array_merge($hospital, $aptitude->toArray());
        }
        unset($result['Balance']);
        unset($result['Money']);
        $response->setJsonContent($result);
        return $response;
    }

    public function admissionAction()
    {
        $auth = $this->session->get('auth');
        $user = User::findFirst([
            'conditions' => 'OrganizationId=?0 AND Label=?1',
            'bind'       => [$auth['HospitalId'], User::LABEL_ADMISSION],
        ]);
        $response = new Response();
        $response->setJsonContent([
            'Id'   => $user ? $user->Id : null,
            'Name' => $user ? $user->Name : null,
        ]);
        return $response;
    }

    /**
     * 医院共享开关
     */
    public function switchShareAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $hospital = Organization::findFirst(sprintf('Id=%d', $this->user->OrganizationId));
            $status = $hospital->Verifyed;
            if ($status === Organization::VERIFYED || $status === Organization::CLOSE) {
                $hospital->Verifyed = ($status === Organization::VERIFYED ? Organization::CLOSE : Organization::VERIFYED);
            } else {
                throw new LogicException('申请未通过，无权修改', Status::Forbidden);
            }
            $hospital->setScene(Organization::SCENE_ORGANIZATION_SWITCH);
            if ($hospital->save() === false) {
                $exception->loadFromModel($hospital);
                throw $exception;
            }
            $result = $hospital->toArray();
            unset($result['Balance']);
            unset($result['Money']);
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 网点禁用开关
     */
    public function slaveSwitchAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $organizationId = $this->request->getPut('OrganizationId', 'int');
            $relation = OrganizationRelationship::findFirst([
                'conditions' => 'MainId=?0 and MinorId=?1',
                'bind'       => [$this->user->OrganizationId, $organizationId],
            ]);
            if (!$relation) {
                throw new LogicException('参数错误', Status::BadRequest);
            }
            $user = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0',
                'bind'       => [$organizationId],
            ]);
            if (!$user) {
                throw new LogicException('网点账户未激活', Status::BadRequest);
            }
            $user->UseStatus = $user->UseStatus === OrganizationUser::USESTATUS_ON ? OrganizationUser::USESTATUS_OFF : OrganizationUser::USESTATUS_ON;
            if ($user->save() === false) {
                $exception->loadFromModel($user);
                throw $exception;
            }
            if ($user->UseStatus === OrganizationUser::USESTATUS_OFF) {
                $this->redis->del($this->redis->get(RedisName::Token . $user->UserId), RedisName::Token . $user->UserId);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }

    }

    /**
     * 删除医院与网点关系
     */
    public function delRelationshipAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $mainId = $this->session->get('auth')['OrganizationId'];
            if (!$mainId) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            $minorId = $this->request->getPut('MinorId', 'int');
            $relation = OrganizationRelationship::findFirst([
                'conditions' => 'MainId=?0 and MinorId=?1',
                'bind'       => [$mainId, $minorId],
            ]);
            if (!$relation) {
                throw $exception;
            }
            $slave = Organization::findFirst(sprintf('Id=%d', $relation->MinorId));
            if (!$slave) {
                throw $exception;
            }
            $count = OrganizationRelationship::count(sprintf("MinorId=%d", $minorId));
            if ($count == 1) {
                if ($slave->Money != 0) {
                    throw new LogicException('该网点账户余额不为0，请联系网点提现', Status::BadRequest);
                }
            }
            $relation->delete();
            //清除网点session
            /** @var OrganizationUser $organizationUser */
            $organizationUser = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0',
                'bind'       => [$slave->Id],
            ]);
            if ($organizationUser) {
                $token = $this->redis->get(RedisName::Token . $organizationUser->UserId);
                $this->redis->delete($token, RedisName::Token . $organizationUser->UserId);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function readBannerAction()
    {
        $this->response->setJsonContent(OrganizationBanner::findFirst(['conditions' => 'Id=?0 and OrganizationId=?1', 'bind' => [$this->request->get('Id'), $this->session->get('auth')['OrganizationId']]]) ?: []);
    }

    public function getHospitalTelAction()
    {
        /** @var Organization $hospital */
        $hospital = Organization::findFirst(sprintf('Id=%d', $this->session->get('auth')['HospitalId']));
        $this->response->setJsonContent(['HospitalTel' => $hospital ? $hospital->Tel ?: null : null]);
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
}
