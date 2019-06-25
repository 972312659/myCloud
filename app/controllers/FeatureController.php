<?php

namespace App\Controllers;

use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\DefaultFeature;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\OrganizationFeature;
use App\Models\Role;
use App\Models\RoleFeature;
use App\Plugins\DispatcherListener;
use App\Libs\module\ManagerOrganization as ModuleManagerOrganization;

class FeatureController extends Controller
{
    public $RoleCacheName = '_PHCRCache:RoleFeature:';

    /**
     * 获取所有功能
     */
    public function allAction()
    {
        $tree = Feature::tree(1);
        $this->response->setJsonContent($tree);
    }

    /**
     * 获取当前用户所在机构拥有的功能 (用于分配权限时)
     */
    public function organizationAction()
    {
        // 获取当前用户的机构对应的features
        $of = OrganizationFeature::find([
            'conditions' => 'OrganizationId=?0',
            'bind'       => [$this->user->OrganizationId],
            'cache'      => [
                'key' => 'Cache:OrganizationFeature:' . $this->user->OrganizationId,
            ],
            'hydration'  => true,
        ])->toArray();
        $oflist = array_column($of, 'FeatureId');
        // 当前用户机构
        $org = Organization::findFirst([
            'conditions' => 'Id=?0',
            'bind'       => [$this->user->OrganizationId],
            'cache'      => [
                'key' => 'Cache:Organization:' . $this->user->OrganizationId,
            ],
        ]);
        $features = \apcu_entry(DispatcherListener::DEFAULT_FEATURE_KEY, function () {
            return DefaultFeature::find();
        });
        $defaultFeatures = $features->filter(function ($item) use ($org) {
            if ($item->Type === $org->IsMain) {
                return $item;
            }
        });
        $oflist = array_merge($oflist, array_column($defaultFeatures, 'FeatureId'));
        $this->response->setJsonContent($oflist);
    }

    /**
     * 获取当前角色的功能 (导航)
     */
    public function currentRoleAction()
    {
        $org = Organization::findFirst([
            'conditions' => 'Id=?0',
            'bind'       => [$this->user->OrganizationId],
        ]);
        // 判断是否为超级管理员
        if ($this->user->Phone === $org->Phone) {
            $this->dispatcher->forward(['action' => 'organization']);
        }
        // 获取当前用户的角色对应的features
        $rf = RoleFeature::find([
            'conditions' => 'RoleId=?0',
            'bind'       => [$this->user->Role],
            'cache'      => [
                'key' => 'Cache:RoleFeature:' . $this->user->Role,
            ],
            'hydration'  => true,
        ])->toArray();
        $rflist = array_column($rf, 'FeatureId');
        $this->response->setJsonContent($rflist);
    }

    /**
     * 当前机构下所有角色与权限列表
     */
    public function roleListAction()
    {
        $roles = Role::find([
            'conditions' => 'OrganizationId=?0',
            'bind'       => [$this->user->OrganizationId],
        ]);
        $sql = 'SELECT rf.RoleId,rf.FeatureId FROM RoleFeature rf INNER JOIN Role r on r.Id=rf.RoleId
WHERE r.OrganizationId=?';
        $query = $this->db->query($sql, [$this->user->OrganizationId]);
        $result = $query->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_GROUP);
        $filterd = $roles->filter(function ($role) use ($result) {
            $newRole = $role->toArray();
            if (isset($result[$role->Id])) {
                $newRole['Features'] = $result[$role->Id];
            }
            return $newRole;
        });
        $this->response->setJsonContent($filterd);
    }

    /**
     * 新增/编辑角色
     * 参数:
     *      RoleId int
     *      Name string
     *      Users []int
     */
    public function editRoleAction()
    {
        // 新增/修改Role
        $e = new ParamException(400);
        $id = $this->request->get('RoleId', null, null);
        $role = empty($id) ? new Role() : Role::findFirst($id);
        $role->Name = $this->request->get('Name');
        $role->OrganizationId = $this->user->OrganizationId;
        if (!$role->save()) {
            $e->loadFromModel($role);
            throw $e;
        }
        // 绑定User
        $users = $this->request->get('Users');
        if (!\is_array($users)) {
            throw new LogicException('请勾选员工账号', Status::BadRequest);
        }
        try {
            $this->db->begin();
            // 把所有用户指定用户的角色id置为0
            $sql = 'UPDATE OrganizationUser t1 INNER JOIN OrganizationUser t2 ON t1.OrganizationId=t2.OrganizationId AND t1.UserId=t2.UserId 
SET t1.Role=0 WHERE t1.OrganizationId=? AND t2.Role=?';
            $this->db->execute($sql, [$this->user->OrganizationId, $role->Id]);
            // 分别重设角色id
            foreach ($users as $userId) {
                $sql = 'UPDATE OrganizationUser SET Role=? WHERE OrganizationId=? AND UserId=?';
                $this->db->execute($sql, [$role->Id, $this->user->OrganizationId, $userId]);
            }
            $this->db->commit();
            $this->response->setJsonContent([
                'message' => '操作成功',
            ]);
        } catch (\Exception $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    /**
     * 删除角色
     * 参数:
     *      RoleId int
     */
    public function deleteRoleAction()
    {
        $role = Role::findFirst([
            'conditions' => 'Id=?0 and OrganizationId=?1',
            'bind'       => [$this->request->get('RoleId'), $this->user->OrganizationId],
        ]);
        if (!$role) {
            throw new LogicException('没有对应的角色', Status::BadRequest);
        }
        try {
            $this->db->begin();
            $this->db->delete('RoleFeature', 'RoleId=?0', [$role->Id]);
            $role->delete();
            $this->db->commit();
            //删除角色缓存
            $this->redis->delete($this->RoleCacheName . $role->Id);
            $this->response->setJsonContent([
                'message' => '删除成功',
            ]);
        } catch (\Exception $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    /**
     * 设置指定角色权限
     * 参数:
     *      RoleId int
     *      Features []int
     */
    public function editRoleFeatureAction()
    {
        $e = new ParamException(400);
        $role = Role::findFirst([
            'conditions' => 'Id=?0 and OrganizationId=?1',
            'bind'       => [$this->request->get('RoleId'), $this->user->OrganizationId],
        ]);
        if (!$role) {
            throw new LogicException('没有对应的角色', Status::BadRequest);
        }
        $features = $this->request->get('Features');
        if (!\is_array($features)) {
            throw new LogicException('功能参数错误', Status::BadRequest);
        }
        try {
            $this->db->begin();
            $this->db->delete('RoleFeature', 'RoleId=?', [$role->Id]);
            // 循环写入角色与功能的关系
            foreach ($features as $featureId) {
                $roleFeature = new RoleFeature();
                $roleFeature->FeatureId = $featureId;
                $roleFeature->RoleId = $role->Id;
                if (!$roleFeature->save()) {
                    $e->loadFromModel($roleFeature);
                    throw $e;
                }
            }
            $this->db->commit();
            //删除角色缓存
            $this->redis->delete($this->RoleCacheName . $role->Id);
        } catch (\Exception $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    /**
     * 获取当前角色的功能标识 (导航)
     */
    public function currentRoleSignAction()
    {
        $org = Organization::findFirst([
            'conditions' => 'Id=?0',
            'bind'       => [$this->user->OrganizationId],
        ]);
        // 判断是否为超级管理员
        if ($this->user->Phone === $org->Phone) {
            $this->dispatcher->forward(['action' => 'adminRoleSign']);
        }
        // 获取当前用户的角色对应的features
        $rf = RoleFeature::query()
            ->columns('F.Sign')
            ->leftJoin(Feature::class, 'F.Id=FeatureId', 'F')
            ->where('RoleId=:RoleId:')
            ->bind(['RoleId' => $this->user->Role])
            ->execute()->toArray();
        $rflist = array_column($rf, 'Sign');
        $this->response->setJsonContent($rflist);
    }

    /**
     * 超级管理员的功能标识
     */
    public function adminRoleSignAction()
    {
        $moduleManager = new ModuleManagerOrganization();
        $features = Feature::query()
            ->columns(['Sign'])
            ->inWhere('Id', $moduleManager->feature())
            ->execute()
            ->toArray();
        $this->response->setJsonContent(array_column($features, 'Sign'));
    }
}