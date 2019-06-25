<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/10
 * Time: 15:22
 */

namespace App\Controllers;


use App\Enums\RedisName;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Probability;
use App\Libs\Sphinx;
use App\Models\Action;
use App\Models\Combo;
use App\Models\Equipment;
use App\Models\Evaluate;
use App\Models\Organization;
use App\Models\OrganizationAndSection;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\Product;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\RuleOfShare;
use App\Models\Section;
use App\Models\Sickness;
use App\Models\SicknessAndOrganization;
use App\Models\Transfer;
use App\Models\User;
use App\Plugins\DispatcherListener;

class SystemController extends Controller
{
    /**
     * 批量写入sphinx的organization
     */
    public function sphinxOrganizationAction()
    {
        $organizations = Organization::find()->toArray();
        if (count($organizations)) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $organizationRelations = OrganizationRelationship::find()->toArray();
            $relations = [];
            $alis = [];
            if (count($organizationRelations)) {
                foreach ($organizationRelations as $relation) {
                    $relations[$relation['MinorId']]['pids'][] = $relation['MainId'];
                    $alis[$relation['MinorId']] = $relation['MinorName'];
                }
            }
            foreach ($organizations as $v) {
                if (is_numeric($v['Id']) && $v['Id']) {
                    $sphinx_data = array_change_key_case($v, CASE_LOWER);
                    $sphinx_data['sharesectionids'] = [];
                    $sphinx_data['sharecomboids'] = [];
                    $sphinx_data['pids'] = count($relations[$v['Id']]['pids']) ? $relations[$v['Id']]['pids'] : [];
                    if ($v['IsMain'] !== 2 && $v['Verifyed'] === 2) {
                        $sections = OrganizationAndSection::find([
                            'conditions' => 'OrganizationId=?0 and Display=?1 and Share=?2',
                            'bind'       => [$v['Id'], 1, 2],
                        ])->toArray();
                        $combos = Combo::find([
                            'conditions' => 'Status=?0 and Audit=?1 and Share=?2 and (PassTime>?3 or PassTime=?4) and OrganizationId=?5',
                            'bind'       => [1, 1, 2, time(), 0, $v['Id']],
                        ])->toArray();
                        $sphinx_data['sharesectionids'] = array_column($sections, 'SectionId');
                        $sphinx_data['sharecomboids'] = array_column($combos, 'Id');
                    }
                    $sphinx_data['alias'] = $alis[$v['Id']] ?: $v['Name'];
                    $sphinx_data['type'] = $v['Type'];
                    $sphinx->save($sphinx_data);
                }
            }
            $this->response->setJsonContent(['message' => 'ok']);
        }
    }

    /**
     * 批量写入sphinx的section user combo equipment
     */
    public function sphinxAction($param)
    {
        $new = [];
        switch ($param) {
            case 'user':
                $results = User::find();
                break;
            case 'combo':
                $results = Combo::find();
                break;
            case 'equipment':
                $results = Equipment::find();
                break;
            case 'section':
                $results = Section::find();
                break;
            case 'sickness':
                $results = Sickness::find();
                $organizations = SicknessAndOrganization::query()->inWhere('SicknessId', array_column($results->toArray(), 'Id'))->execute();
                if (count($organizations->toArray())) {
                    foreach ($organizations as $organization) {
                        $new[$organization->SicknessId][] = $organization->OrganizationId;
                    }
                }
                break;
            default:
                $results = [];
                break;
        }
        if ($results) {
            $sphinx = new Sphinx($this->sphinx, $param);
            foreach ($results as $v) {
                if ($v->Id) {
                    $sphinx_data = array_change_key_case($v->toArray(), CASE_LOWER);
                    $sphinx_data['organizations'] = [];
                    if (count($new)) {
                        $sphinx_data['organizations'] = !empty($new[$v->Id]) ? $new[$v->Id] : [];
                    }
                    $sphinx->save($sphinx_data);
                }
            }
            $this->response->setJsonContent(['message' => 'ok']);
        }
    }

    /**
     * 清除指定用户权限缓存
     */
    public function clearPermissionAction($hospitalId, $id)
    {
        $this->redis->delete(RedisName::Permission . $hospitalId . '_' . $id);
        $this->response->setJsonContent(['message' => 'ok']);
    }

    /**
     * 清除全部用户权限缓存
     */
    public function clearAllPermissionAction()
    {
        $users = OrganizationUser::find();
        if ($users) {
            foreach ($users as $user) {
                $this->redis->delete(RedisName::Permission . $user->OrganizationId . '_' . $user->UserId);
            }
        }
        $this->response->setJsonContent(['message' => 'ok']);
    }

    /**
     * 更新医生的转诊单数、评论数
     */
    public function renewalUserAction()
    {
        $transfers = Transfer::query()
            ->columns('AcceptDoctorId as Id,count(*) as Total')
            ->where('Status=8')
            ->groupBy('AcceptDoctorId')
            ->execute();
        $transfers_new = [];
        foreach ($transfers as $transfer) {
            $transfers_new[$transfer->Id] = $transfer->Total;
        }
        $evaluates = Evaluate::query()
            ->columns('AcceptDoctorId as Id,count(*) as Total')
            ->leftJoin(Transfer::class, 't.Id=TransferId', 't')
            ->groupBy('AcceptDoctorId')
            ->execute();
        $evaluates_new = [];
        foreach ($evaluates as $evaluate) {
            $evaluates_new[$evaluate->Id] = $evaluate->Total;
        }
        //分析所有数据
        $users = User::find();
        $i = 0;
        foreach ($users as $user) {
            if (isset($transfers_new[$user->Id]) || isset($evaluates_new[$user->Id])) {
                $user->TransferAmount = isset($transfers_new[$user->Id]) ? $transfers_new[$user->Id] : 0;
                $user->EvaluateAmount = isset($evaluates_new[$user->Id]) ? $evaluates_new[$user->Id] : 0;
                $user->save();
                $i++;
            }
        }
        return $this->response->setJsonContent(['message' => 'ok', 'total' => $i]);
    }

    /**
     * 更新医院、诊所转诊单数
     */
    public function renewalOrganizationAction()
    {
        $hospitals = Transfer::query()
            ->columns('AcceptOrganizationId as Id,count(*) as Total')
            ->where('Status=8')
            ->groupBy('AcceptOrganizationId')
            ->execute();
        $hospitals_new = [];
        foreach ($hospitals as $hospital) {
            $hospitals_new[$hospital->Id] = $hospital->Total;
        }
        $slaves = Transfer::query()
            ->columns('SendOrganizationId as Id,count(*) as Total')
            ->where('Status=8')
            ->groupBy('SendOrganizationId')
            ->execute();
        $slaves_new = [];
        foreach ($slaves as $slave) {
            $slaves_new[$slave->Id] = $slave->Total;
        }
        $organizations = Organization::find();
        foreach ($organizations as $organization) {
            $organization->TransferAmount = $organization->IsMain === Organization::ISMAIN_SLAVE ? (isset($slaves_new[$organization->Id]) ? $slaves_new[$organization->Id] : 0) : (isset($hospitals_new[$organization->Id]) ? $hospitals_new[$organization->Id] : 0);
            $organization->save();
        }
        return $this->response->setJsonContent(['message' => 'ok']);
    }

    /**
     * 读取该机构的sphinx
     */
    public function getOneOrganizationSphinxAction()
    {
        $sphinx = new Sphinx($this->sphinx, 'organization');
        $columns = array_column($this->sphinx->query("desc `organization`")->fetchAll(), 'Field');
        $data = $sphinx->where('=', (int)$this->request->get('Id'), 'id')->fetch() ?: null;
        $result = [];
        if ($data) {
            foreach ($columns as $column) {
                $result[$column] = $data[$column];
            }
        }
        return $this->response->setJsonContent($result);
    }

    /**
     * 更新角色权限
     * 祛掉员工有但超级管理员没有的权限
     */
    public function renewalRolePermissionAction()
    {
        /**
         * 医院
         */
        //医院超级管理员权限资源id
        $hospital = RolePermission::find([
            'conditions' => 'RoleId=?0',
            'bind'       => [Role::DEFAULT_B],
        ])->toArray();
        $hospital_permissionIds = array_column($hospital, 'PermissionId');
        //医院员工角色id
        $hospital_users = OrganizationUser::query()
            ->columns(['OrganizationId', 'UserId', 'Role'])
            ->leftJoin(Organization::class, 'O.Id=OrganizationId', 'O')
            ->notInWhere('Role', [Role::DEFAULT_B, Role::DEFAULT_SUPPLIER, Role::DEFAULT_SUPPLIER])
            ->andWhere(sprintf('O.IsMain=%d', Organization::ISMAIN_HOSPITAL))
            ->andWhere(sprintf('O.Id!=%d', Organization::PEACH))
            ->execute()->toArray();
        $hospital_roleIds = array_unique(array_column($hospital_users, 'Role'));
        //不在医院超级管理员资源内的资源
        $hospital_clean = RolePermission::query()
            ->inWhere('RoleId', $hospital_roleIds)
            ->notInWhere('PermissionId', $hospital_permissionIds)
            ->execute();
        $roleIds = [];
        if (count($hospital_clean->toArray())) {
            $roleIds = array_column($hospital_clean->toArray(), 'RoleId');
            // $hospital_clean->delete();
        }
        /**
         * 供应商
         */
        //供应商超级管理员权限资源id
        $supplier = RolePermission::find([
            'conditions' => 'RoleId=?0',
            'bind'       => [Role::DEFAULT_SUPPLIER],
        ])->toArray();
        $supplier_permissionIds = array_column($supplier, 'PermissionId');
        //供应商员工角色id
        $supplier_users = OrganizationUser::query()
            ->columns(['OrganizationId', 'UserId', 'Role'])
            ->leftJoin(Organization::class, 'O.Id=OrganizationId', 'O')
            ->notInWhere('Role', [Role::DEFAULT_B, Role::DEFAULT_SUPPLIER, Role::DEFAULT_SUPPLIER])
            ->andWhere(sprintf('O.IsMain=%d', Organization::ISMAIN_SUPPLIER))
            ->andWhere(sprintf('O.Id!=%d', Organization::PEACH))
            ->execute()->toArray();
        $supplier_rolesIds = array_unique(array_column($supplier_users, 'Role'));
        //不再供应商超级管理员资源内的资源
        $supplier_clean = RolePermission::query()
            ->inWhere('RoleId', $supplier_rolesIds)
            ->notInWhere('PermissionId', $supplier_permissionIds)
            ->execute();
        if (count($supplier_clean->toArray())) {
            $roleIds = array_merge($roleIds, array_column($supplier_clean->toArray(), 'RoleId'));
            // $supplier_clean->delete();
        }
        //清缓存
        if (count($roleIds)) {
            $organizationUsers = OrganizationUser::query()
                ->columns(['OrganizationId', 'UserId'])
                ->inWhere('Role', $roleIds)
                ->execute();
            foreach ($organizationUsers as $user) {
                $this->redis->delete(RedisName::Permission . $user->OrganizationId . '_' . $user->UserId);
            }
        }
        return $this->response->setJsonContent(['message' => 'ok']);
    }

    public function brushAction()
    {
        $names = file_get_contents('../../names');
        $names = explode('、', $names);
        $phones = [133, 153, 180, 181, 189, 177, 173, 149, 130, 131, 132, 155, 156, 145, 185, 186, 176, 175, 134, 135, 136, 137, 138, 139, 150, 151, 152, 157, 158, 159, 182, 183, 184, 187, 188, 147, 178];
        //时间概率key=7,代表7点到8点
        $probability = [7 => 3, 8 => 14, 9 => 13, 10 => 12, 11 => 13, 12 => 18, 13 => 5, 14 => 4, 15 => 4, 16 => 4, 17 => 1, 18 => 2, 19 => 3, 20 => 4, 21 => 2, 22 => 1];
        //每天有的概率
        $have = [0 => 94.5, 1 => 4.5, 2 => 1];
        $total = 0;
        $organizations = $this->modelsManager->createBuilder()
            ->columns('O.Id,O.Name,O.CreateTime,O.RuleId,S.Fixed,S.Ratio,S.Type')
            ->addFrom(Organization::class, 'O')
            ->leftJoin(\App\Models\RuleOfShare::class, 'S.Id=O.RuleId', 'S')
            ->where('O.IsMain=1')
            ->andWhere('O.Id!=0')
            ->orderBy('O.CreateTime asc')
            ->getQuery()
            ->execute();
        foreach ($organizations as $organization) {
            //所有网点
            $slaves = $this->modelsManager->createBuilder()
                ->columns('O.Id,O.Name,O.CreateTime,O.Contact,R.MinorName as AliasName,R.RuleId,S.Fixed,S.Ratio,S.Type')
                ->addFrom(Organization::class, 'O')
                ->leftJoin(\App\Models\OrganizationRelationship::class, 'R.MinorId=O.Id', 'R')
                ->leftJoin(\App\Models\RuleOfShare::class, 'S.Id=R.RuleId', 'S')
                ->where(sprintf('R.MainId=%d', $organization->Id))
                ->orderBy('O.CreateTime asc')
                ->getQuery()
                ->execute();
            if (count($slaves->toArray())) {
                $organization_users = OrganizationUser::query()
                    ->columns('OrganizationId,U.Name,U.Id')
                    ->leftJoin(User::class, 'U.Id=UserId', 'U')
                    ->inWhere('OrganizationId', array_column($slaves->toArray(), 'Id'))
                    ->execute();
                $users = [];
                foreach ($organization_users as $user) {
                    $users[$user->OrganizationId] = ['Id' => $user->Id, 'Name' => $user->Name];
                }
                foreach ($slaves as $slave) {
                    $auth = ['OrganizationId' => $slave->Id, 'OrganizationName' => $slave->Name, 'Id' => $users[$slave->Id]['Id'], 'Name' => $users[$slave->Id]['Name']];
                    if (empty($auth['Id']) || empty($auth['Name'])) {
                        continue;
                    }
                    $this->session->set('auth', $auth);
                    $stimestamp = $slave->CreateTime;
                    $etimestamp = time() - 86400;
                    // 计算日期段内有多少天
                    $days = ($etimestamp - $stimestamp) / 86400;
                    // 保存每天日期
                    $dates = [];
                    for ($i = 1; $i < $days; $i++) {
                        $dates[] = date('Ymd', $stimestamp + (86400 * $i));
                    }
                    if (count($dates)) {
                        foreach ($dates as $date) {
                            $n = Probability::get($have);
                            if ($n) {
                                for ($i = 1; $i <= $n; $i++) {
                                    $this->db->begin();
                                    $transfer = new Transfer();
                                    $transfer->PatientName = $names[array_rand($names)];
                                    $transfer->PatientTel = $phones[array_rand($phones)] . substr(uniqid('', true), 20) . substr(microtime(), 2, 5);
                                    $transfer->SendHospitalId = $organization->Id;
                                    $transfer->SendOrganizationId = $slave->Id;
                                    $transfer->SendOrganizationName = $slave->Name;
                                    $transfer->TranStyle = 1;
                                    $transfer->AcceptOrganizationId = $organization->Id;
                                    $transfer->StartTime = strtotime($date) + Probability::get($probability) * 3600 + mt_rand(0001, 3600);
                                    $transfer->Status = 2;
                                    $transfer->OrderNumber = $transfer->StartTime << 32 | substr('0000000' . $slave->Id, -7, 7);
                                    $transfer->ShareOne = $slave->Type == RuleOfShare::RULE_FIXED ? $slave->Fixed : $slave->Ratio;
                                    $transfer->ShareCloud = $organization->Type == RuleOfShare::RULE_FIXED ? $organization->Fixed : $organization->Ratio;
                                    $transfer->Genre = 1;
                                    $transfer->GenreOne = $slave->Type;
                                    $transfer->CloudGenre = $organization->Type;
                                    $transfer->Sign = 1;
                                    if (!$transfer->save()) {
                                        $exception = new ParamException(Status::BadRequest);
                                        $this->db->rollback();
                                        $exception->loadFromModel($transfer);
                                        throw $exception;
                                    }
                                    $this->db->commit();
                                    $total++;
                                }

                            }
                        }
                    }
                    $this->session->destroy('auth');
                }
            }


        }
        echo $total . PHP_EOL;
    }
}