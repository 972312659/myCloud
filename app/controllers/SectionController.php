<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/10
 * Time: 下午2:39
 */

namespace App\Controllers;

use App\Enums\HospitalLevel;
use App\Enums\OrganizationType;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Sphinx;
use App\Models\ApplyOfShare;
use App\Models\EquipmentAndSection;
use App\Models\Organization;
use App\Models\OrganizationAndEquipment;
use App\Models\OrganizationAndSection;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationSection;
use App\Models\OrganizationUser;
use App\Models\ProfitRule;
use App\Models\RuleOfShare;
use App\Models\Section;
use App\Models\User;
use Phalcon\Http\Response;
use Phalcon\Paginator\Adapter\Model;
use Phalcon\Paginator\Adapter\NativeArray;
use Phalcon\Paginator\Adapter\QueryBuilder;

class SectionController extends Controller
{
    //科室默认LOGO
    private $image = '';

    /**
     * 查看一条内置科室
     */
    public function showAction($id)
    {
        $response = new Response();
        $section = Section::findFirst(sprintf('Id=%d', $id));
        if (!$section) {
            $response->setStatusCode(Status::BadRequest);
            return $response;
        }
        $response->setJsonContent($section);
        return $response;
    }

    /**
     * 查看全部内置科室
     */
    public function allAction()
    {
        $response = new Response();
        $organizationSections = OrganizationAndSection::find(['conditions' => 'OrganizationId=?0', 'bind' => [$this->session->get('auth')['HospitalId']]])->toArray();
        $sectionIds = [];
        if (count($organizationSections)) {
            $sectionIds = array_column($organizationSections, 'SectionId');
        }
        $sections = Section::query()->inWhere('Id', $sectionIds)->orWhere('IsBuilt=1')->execute();
        $response->setJsonContent($sections);
        return $response;
    }

    /**
     * 为医院添加科室 非内置科室
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $auth = $this->session->get('auth');
                $data = $this->request->getPost();
                $this->db->begin();
                //不使用内置科室，就需要新建科室，且不共享
                if (isset($data['NewSection']) && !empty($data['NewSection'])) {
                    $oldSection = Section::findFirst([
                        "Name=:Name:",
                        'bind' => ['Name' => $data['NewSection']],
                    ]);
                    if (!$oldSection) {
                        $sectionData = [];
                        $sectionData['Pid'] = isset($data['Pid']) ? $data['Pid'] : 0;
                        $sectionData['Name'] = $data['NewSection'];
                        $sectionData['IsBuilt'] = Section::BUILT_OUTSIDE;
                        $sectionData['Image'] = $this->image;
                        $section = new Section();
                        if ($section->save($sectionData) === false) {
                            $exception->loadFromModel($section);
                            throw $exception;
                        }
                        $data['SectionId'] = $section->Id;
                        $data['Share'] = 1;
                    } else {
                        $data['SectionId'] = $oldSection->Id;
                        $data['Share'] = 1;
                    }
                }
                if (OrganizationAndSection::findFirst(["OrganizationId=:OrganizationId: and SectionId=:SectionId:", "bind" => ["OrganizationId" => $auth['OrganizationId'], "SectionId" => $data['SectionId']]])) {
                    $exception->add('SectionId', '科室已存在，请不要重复添加');
                    throw $exception;
                }
                $organizationAndSection = new OrganizationAndSection();
                $data['OrganizationId'] = $auth['OrganizationId'];
                $data['UpdateTime'] = time();
                if (!isset($data['Rank']) || !is_numeric($data['Rank'])) {
                    $data['Rank'] = 0;
                }
                if ($organizationAndSection->save($data) === false) {
                    $exception->loadFromModel($organizationAndSection);
                    throw $exception;
                }
                $this->db->commit();
                $this->response->setStatusCode(Status::Created);
                $this->response->setJsonContent($organizationAndSection);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 修改医院科室的属性
     */
    public function updateAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $this->db->begin();
                $data = $this->request->getPut();
                //验证采购科室不能修改
                $buy = OrganizationSection::findFirst([
                    "OrganizationId=?0 and SectionId=?1",
                    'bind' => [$this->user->OrganizationId, $data['OldSectionId']],
                ]);
                if ($buy) {
                    if ($buy->HospitalId !== $this->user->OrganizationId) {
                        throw $exception;
                    }
                }
                $section = OrganizationAndSection::findFirst([
                    "OrganizationId=?0 and SectionId=?1",
                    'bind' => [$this->user->OrganizationId, $data['OldSectionId']],
                ]);
                if (!$section) {
                    throw $exception;
                }
                if (isset($data['NewSection'])) {
                    $data['NewSection'] = trim($data['NewSection']);
                }
                if ((isset($data['SectionId']) && $data['OldSectionId'] != $data['SectionId']) || (isset($data['NewSection']) && !empty($data['NewSection'])) || $section->IsSpecial != $data['IsSpecial']) {
                    if ($section->Share == OrganizationAndSection::SHARE_WAIT) {
                        throw new LogicException('正在共享审核，禁止编辑科室', Status::BadRequest);
                    }
                    if ($section->Share == OrganizationAndSection::SHARE_SHARE) {
                        throw new LogicException('取消共享后才能编辑科室', Status::BadRequest);
                    }
                }
                if (isset($data['NewSection']) && !empty($data['NewSection'])) {
                    $buildSection_old = Section::findFirst(['conditions' => 'Name=?0', 'bind' => [$data['NewSection']]]);
                    if (!$buildSection_old) {
                        $build = new Section();
                        $build->Name = $data['NewSection'];
                        $build->IsBuilt = Section::BUILT_OUTSIDE;
                        if ($build->save() === false) {
                            $exception->loadFromModel($build);
                            throw new $exception;
                        }
                        $data['SectionId'] = $build->Id;
                    } else {
                        $data['SectionId'] = $buildSection_old->Id;
                        $exist = OrganizationAndSection::findFirst([
                            "OrganizationId=?0 and SectionId=?1",
                            'bind' => [$this->user->OrganizationId, $data['SectionId']],
                        ]);
                        if ($exist) {
                            $exception->add('SectionId', '修改出错，科室已存在');
                            throw $exception;
                        }
                    }
                }
                //科室下面有医生或者设备都不能进行修改
                $oldSectionId = (int)$data['OldSectionId'];
                $change = false;
                if (isset($data['SectionId']) && is_numeric($data['SectionId'])) {
                    $data['SectionId'] = (int)$data['SectionId'];
                    if ($oldSectionId != $data['SectionId']) {
                        //重新关联医生
                        $users = OrganizationUser::find([
                            'conditions' => "OrganizationId=?0 and SectionId=?1",
                            "bind"       => [$this->user->OrganizationId, $oldSectionId],
                        ]);
                        if (count($users->toArray())) {
                            foreach ($users as $user) {
                                $user->SectionId = $data['SectionId'];
                                if ($user->save() === false) {
                                    $exception->loadFromModel($user);
                                    throw $exception;
                                }
                            }
                        }
                        //重新关联设备
                        $equipments = EquipmentAndSection::find([
                            'conditions' => "OrganizationId=?0 and SectionId=?1",
                            "bind"       => [$this->user->OrganizationId, $oldSectionId],
                        ]);
                        if (count($equipments->toArray())) {
                            foreach ($equipments as $equipment) {
                                $newEquipment = new EquipmentAndSection();
                                $newEquipment->OrganizationId = $equipment->OrganizationId;
                                $newEquipment->SectionId = $data['SectionId'];
                                $newEquipment->EquipmentId = $equipment->EquipmentId;
                                if ($newEquipment->save() === false) {
                                    $exception->loadFromModel($newEquipment);
                                    throw $exception;
                                }
                            }
                        }
                        $equipments->delete();
                        //重新关联分润规则
                        $profitRules = ProfitRule::find([
                            'conditions' => 'OrganizationId=?0 and SectionId=?1',
                            'bind'       => [$this->user->OrganizationId, $oldSectionId],
                        ]);
                        if (count($profitRules->toArray())) {
                            foreach ($profitRules as $profitRule) {
                                $profitRule->SectionId = $data['SectionId'];
                                if ($profitRule->save() === false) {
                                    $exception->loadFromModel($profitRule);
                                    throw $exception;
                                }
                            }
                        }
                        $change = true;
                    }
                }
                $data['UpdateTime'] = time();
                $whiteList = ['SectionId', 'UpdateTime', 'IsSpecial', 'Rank', 'Display', 'Intro'];
                if ($section->save($data, $whiteList) === false) {
                    $exception->loadFromModel($section);
                    throw $exception;
                }
                //如果更换科室
                if ($change) {
                    $data = $section->toArray();
                    $data['UpdateTime'] = time();
                    $oldSection = OrganizationAndSection::findFirst([
                        "OrganizationId=?0 and SectionId=?1",
                        'bind' => [$this->user->OrganizationId, $oldSectionId],
                    ]);
                    if ($oldSection->delete() === false) {
                        $exception->add('SectionId', '操作未成功');
                        throw $exception;
                    }
                    $section = new OrganizationAndSection();
                    if ($section->save($data) === false) {
                        $exception->loadFromModel($section);
                        throw $exception;
                    }
                }
                $this->db->commit();
                $this->response->setStatusCode(Status::Created);
                $this->response->setJsonContent($section);
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

    public function readAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $data = OrganizationAndSection::findFirst([
                "OrganizationId=:OrganizationId: and SectionId=:SectionId:",
                'bind' => ["OrganizationId" => (int)$this->request->get('OrganizationId'), "SectionId" => (int)$this->request->get('SectionId')],
            ]);
            if (!$data) {
                throw $exception;
            }
            $organization = Organization::findFirst(sprintf('Id=%d', (int)$this->request->get('OrganizationId')));
            $new = $data->toArray();
            $new['SectionName'] = $data->Section->Name;
            $new['Image'] = $data->Section->Image ?: Section::DEFAULT_IMAGE;
            $new['HospitalName'] = $organization->Name;
            $new['HospitalLogo'] = $organization->Logo;
            $new['TransferAmount'] = (int)User::query()->columns('sum(TransferAmount) as TransferAmount')->join(OrganizationUser::class, 'OU.UserId=Id', 'OU')->where(sprintf('OrganizationId=%d', $data->OrganizationId))->andWhere(sprintf('SectionId=%d', $data->SectionId))->execute()[0]->TransferAmount;
            $this->response->setJsonContent($new);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    public function listAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns('OS.OrganizationId,OS.SectionId,OS.IsSpecial,OS.Rank,OS.Display,OS.Share,OS.UpdateTime,OS.Intro,S.Name as SectionName,S.Image as Image')
            ->addFrom(OrganizationAndSection::class, 'OS')
            ->where(sprintf('OS.OrganizationId=%d', $auth['OrganizationId']));
        //科室名字
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'section');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('S.Id', $ids);
            } else {
                $query->inWhere('S.Id', [-1]);
            }
        }
        //展示
        if (!empty($data['Display']) && isset($data['Display'])) {
            $query->andWhere('Display=:Display:', ['Display' => $data['Display']]);
        }
        //共享状态
        if (!empty($data['Share']) && isset($data['Share'])) {
            $query->andWhere("Share=:Share:", ['Share' => $data['Share']]);
        }

        $query->join(Section::class, 'S.Id=OS.SectionId', 'S', 'left')
            ->orderBy('OS.Rank desc');

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
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $response->setJsonContent($result);
        return $response;
    }

    /**
     * 自有科室
     */
    public function selfSectionAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        $data = $this->request->getPost();

        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        if (!empty($data['Show']) && isset($data['Show'])) {
            $pageSize = $data['Show'];
        }
        $query = $this->modelsManager->createBuilder()
            ->columns('O.SectionId,O.Intro,O.IsSpecial,S.Name as SectionName,S.Image as Image')
            ->addFrom(OrganizationAndSection::class, 'O')
            ->where('O.OrganizationId =' . $auth['HospitalId'])
            ->andWhere('O.Display=1')
            ->join(Section::class, 'S.Id=O.SectionId', 'S', 'left')
            ->orderBy('O.Rank desc');
        //科室名字
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'section');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ?: [], 'id');
            if (count($ids)) {
                $query->inWhere('S.Id', $ids);
            } else {
                $query->inWhere('S.Id', [-1]);
            }
        }
        if (!empty($data['Share']) && isset($data['Share'])) {
            $query->andWhere('O.Share=2');
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
        $sections = $pages->items->toArray();
        foreach ($sections as &$section) {
            $section['Image'] = $section['Image'] ?: Section::DEFAULT_IMAGE;
        }
        if (!empty($data['Show']) && isset($data['Show'])) {
            $response->setJsonContent($sections);
        } else {
            //分页
            $result = [];
            $result['Data'] = $sections;
            $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
            $response->setJsonContent($result);
        }
        return $response;

    }

    /**
     * 共享科室列表,随机（暂定）
     */
    public function shareAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        $data = $this->request->getPost();

        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        if (!empty($data['Show']) && isset($data['Show'])) {
            $pageSize = $data['Show'];
        }
        $sphinx = new Sphinx($this->sphinx, 'organization');
        $sphinx->columns('id,length(sharesectionids) as s,sharesectionids');
        $sphinx->where('!=', $auth['HospitalId'], 'id');
        $sphinx->andWhere('=', 1, 'ismain');
        $sphinx->andWhere('>', 0, 's');
        $sort = $sphinx->fetchAll();
        $ids = [];
        if (is_array($sort)) {
            $ids = array_column($sort ?: [], 'sharesectionids');
            $ids = implode(',', $ids);
            $ids = array_filter(array_unique(explode(',', $ids)));
            asort($ids);
        }
        //科室名字
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'section');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $sections = array_column($name ?: [], 'id');
            $ids = array_intersect($ids, $sections);
        }
        $query = Section::query()
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
        $sections = $pages->items->toArray();
        foreach ($sections as &$section) {
            $section['Image'] = $section['Image'] ?: Section::DEFAULT_IMAGE;
        }
        if (!empty($data['Show']) && isset($data['Show'])) {
            $response->setJsonContent($sections);
        } else {
            //分页
            $result = [];
            $result['Data'] = $sections;
            $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
            $response->setJsonContent($result);
        }
        return $response;
    }

    /**
     * 删除一条医院创建科室
     */
    public function deleteAction($id)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if ($this->request->isDelete()) {
                $organizationId = $this->user->OrganizationId;
                $organizationAndSection = OrganizationAndSection::findFirst([
                    "conditions" => "OrganizationId=:OrganizationId: and SectionId=:SectionId:",
                    "bind"       => ["OrganizationId" => $organizationId, "SectionId" => $id],
                ]);
                if (!$organizationAndSection) {
                    throw $exception;
                }
                //验证是否有医生
                $doctor = OrganizationUser::findFirst([
                    'conditions' => 'OrganizationId=?0 and SectionId=?1',
                    'bind'       => [$organizationId, $organizationAndSection->SectionId],
                ]);
                if ($doctor) {
                    throw new LogicException('该科室下存在医生，不能删除', Status::BadRequest);
                }
                //验证是否有分润规则
                $profitRule = ProfitRule::findFirst([
                    'conditions' => 'OrganizationId=?0 and SectionId=?1',
                    'bind'       => [$organizationId, $organizationAndSection->SectionId],
                ]);
                if ($profitRule) {
                    throw new LogicException('佣金规则配置了该科室，不能删除', Status::BadRequest);
                }
                $apply = ApplyOfShare::findFirst([
                    'conditions' => 'OrganizationId=?0 and SectionId=?1',
                    'bind'       => [$organizationId, $organizationAndSection->SectionId],
                ]);
                if ($apply) {
                    $apply->delete();
                }
                $organizationAndSection->delete();
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * from shareSection to Hospital list
     * @return Response
     */
    public function shareHospitalAction()
    {
        $response = new Response();
        if ($this->request->isPost()) {
            $auth = $this->session->get('auth');
            $data = $this->request->getPost();
            $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
            $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
            $query = $this->modelsManager->createBuilder()
                ->columns('O.Id as OrganizationId,O.Name,O.LevelId,O.ProvinceId,O.CityId,O.AreaId,O.RuleId,O.Logo as Image,O.Score,O.TransferAmount,R.Fixed,R.Ratio,R.DistributionOut,R.Type,S.IsSpecial')
                ->addFrom(Organization::class, 'O')
                ->join(RuleOfShare::class, 'R.Id=O.RuleId', 'R', 'left')
                ->join(OrganizationAndSection::class, 'S.OrganizationId=O.Id', 'S', 'left')
                ->where(sprintf('S.SectionId=%d', $data['SectionId']));
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $sphinx->where('!=', $auth['HospitalId'], 'id');
            $sphinx->andWhere('=', (int)$data['SectionId'], 'sharesectionids');
            //选择城市
            if (!empty($data['AreaId']) && isset($data['AreaId'])) {
                $sphinx->andWhere('=', (int)$data['AreaId'], 'areaid');
            }
            //筛选方式
            if (!empty($data['Type']) && isset($data['Type'])) {
                $query->andWhere(sprintf('O.Type=%d', $data['Type']));
            }
            //综合排序
            if (!empty($data['Sort']) && isset($data['Sort']) && !empty($data['Lng']) && isset($data['Lng']) && !empty($data['Lat']) && isset($data['Lat'])) {
                $data['Lat'] = (float)$data['Lat'];
                $data['Lng'] = (float)$data['Lng'];
                switch ($data['Sort']) {
                    //好评优先
                    case 'Evaluate':
                        $sphinx->distance($data['Lat'], $data['Lng']);
                        $sphinx->orderBy('score desc');
                        break;
                    //接诊最多
                    case 'TransferAmount':
                        $sphinx->distance($data['Lat'], $data['Lng']);
                        $sphinx->orderBy('transferamount desc');
                        break;
                    //综合排序
                    case 'Comprehensive':
                        $sphinx->comprehensive($data['Lat'], $data['Lng']);
                        $sphinx->orderBy('weight desc');
                        break;
                }
            }
            $sphinx->limit($page, $pageSize);
            $sort = $sphinx->fetchAll();
            $ids = [];
            if (is_array($sort)) {
                $ids = array_column($sort ?: [], 'id');
            }
            if (count($ids)) {
                $query->inWhere('O.Id', $ids);
            } else {
                $query->inWhere('O.Id', [-1]);
            }
            $paginator = new QueryBuilder(
                [
                    "builder" => $query,
                    "limit"   => $pageSize,
                    "page"    => 1,
                ]
            );
            $pages = $paginator->getPaginate();
            $totalPage = $pages->total_pages;
            $count = $pages->total_items;
            $hospitals = $pages->items->toArray();
            foreach ($hospitals as &$hospital) {
                $hospital['Score'] = sprintf('%.1f', $hospital['Score']);
                $hospital['LevelName'] = HospitalLevel::value($hospital['LevelId']);
            }
            $result = [];
            $result['Data'] = $hospitals;
            $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
            $response->setJsonContent($result);
        } else {
            $response->setStatusCode(Status::MethodNotAllowed);

        }
        return $response;
    }

    /**
     * 全部科室
     */
    public function allSectionAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $hospitalId = $this->session->get('auth')['HospitalId'];
        $selfSection = OrganizationAndSection::find(['OrganizationId=?0', 'bind' => [$hospitalId]])->toArray();
        $section = $selfSection ? array_column($selfSection, 'SectionId') : null;
        $query = $this->modelsManager->createBuilder()
            ->columns(['OS.SectionId', 'S.Name as SectionName', 'S.Image', 'S.Poster', 'count(U.UserId) as DoctorAmount'])
            ->addFrom(OrganizationAndSection::class, 'OS')
            ->join(Section::class, 'S.Id=OS.SectionId', 'S', 'left')
            ->join(OrganizationUser::class, 'U.SectionId=OS.SectionId and U.OrganizationId=OS.OrganizationId', 'U', 'left')
            ->where('OS.Display=1')
            ->andWhere("if(OS.OrganizationId={$hospitalId},1=1,OS.Share=2)")
            ->andWhere('U.Display=1')
            ->andWhere("if(U.OrganizationId={$hospitalId},1=1,U.Share=2)")
            ->groupBy('OS.SectionId');
        if ($section) {
            $order = [];
            foreach ($section as $item) {
                $order[] = sprintf('OS.SectionId<>%d', $item);
            }
            $query->orderBy(implode(',', $order));
        }
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'section');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ?: [], 'id');
            if (count($ids)) {
                $query->inWhere('S.Id', $ids);
            } else {
                $query->inWhere('S.Id', [-1]);
            }
        }
        $paginator = new QueryBuilder(
            [
                'builder' => $query,
                'limit'   => $pageSize,
                'page'    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        foreach ($datas as &$data) {
            $data['Image'] = $data['Image'] ?: Section::DEFAULT_IMAGE;
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 网点PC端科室下面医院列表
     */
    public function hospitalPcAction()
    {
        $hospitalId = $this->session->get('auth')['HospitalId'];
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns('O.Id,O.Name,O.LevelId,O.ProvinceId,O.CityId,O.AreaId,O.Type,O.Logo,O.Score,count(U.UserId) as DoctorAmount')
            ->addFrom(Organization::class, 'O')
            ->join(OrganizationAndSection::class, 'OS.OrganizationId=O.Id', 'OS', 'left')
            ->join(OrganizationUser::class, 'U.OrganizationId=O.Id', 'U', 'left')
            ->where(sprintf('OS.SectionId=%d', $data['SectionId']))
            ->andWhere('OS.Display=1')
            ->andWhere("if(OS.OrganizationId={$hospitalId},1=1,OS.Share=2)")
            ->andWhere('U.IsDoctor=1')
            ->andWhere('U.Display=1')
            ->andWhere(sprintf('U.SectionId=%d', $data['SectionId']))
            ->andWhere("if(U.OrganizationId={$hospitalId},1=1,U.Share=2)")
            ->groupBy('O.Id')
            ->orderBy("O.Id<>{$hospitalId}");
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ?: [], 'id');
            if (count($ids)) {
                $query->inWhere('O.Id', $ids);
            } else {
                $query->inWhere('O.Id', [-1]);
            }
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
        $hospitals = $pages->items->toArray();
        foreach ($hospitals as &$hospital) {
            $hospital['Type'] = OrganizationType::value($hospital['Type']);
        }
        $result = [];
        $result['Data'] = $hospitals;
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 编辑科室排序
     */
    public function editSortAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            $sections = OrganizationSection::find([
                'conditions' => 'OrganizationId=?0 and SectionId=?1',
                'bind'       => [$this->user->OrganizationId, $data['SectionId']],
            ]);
            if (!count($sections->toArray())) {
                throw $exception;
            }
            foreach ($sections as $section) {
                $section->Sort = $data['Sort'];
                if ($section->save() === false) {
                    $exception->loadFromModel($section);
                    throw $exception;
                }
                if ($section->HospitalId === $this->user->OrganizationId) {
                    $self = OrganizationAndSection::findFirst([
                        'conditions' => 'OrganizationId=?0 and SectionId=?1',
                        'bind'       => [$this->user->OrganizationId, $data['SectionId']],
                    ]);
                    if ($self) {
                        $self->Rank = $section->Sort;
                        if ($self->save() === false) {
                            $exception->loadFromModel($self);
                            throw $exception;
                        }
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
     * 2.0版本网点端科室列表
     */
    public function slaveAction()
    {
        $data = $this->request->getPost();
        $auth = $this->session->get('auth');
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $phql = "select b.Name,b.Id,b.Image,count(a.SectionId) count,min(a.Sort) Sort,min(a.HospitalId) HospitalId,c.SectionId as Sign 
                from (select HospitalId,SectionId,Sort from `OrganizationSection` where OrganizationId={$auth['HospitalId']}";
        //科室名字
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'section');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $ids = '(' . implode(',', $ids) . ')';
                $phql .= " and SectionId in $ids";
            } else {
                $phql .= " and SectionId=-1";
            }
        }
        $phql .= " ORDER BY Sort desc,HospitalId={$auth['HospitalId']} desc) a 
                   left join Section b on b.Id=a.SectionId 
                   left join OrganizationAndSection c on c.SectionId=a.SectionId and c.OrganizationId={$auth['HospitalId']}
                   GROUP BY a.SectionId order by Sort desc,Id asc";
        $paginator = new NativeArray([
            'data'  => $this->db->query($phql)->fetchAll(),
            'limit' => $pageSize,
            'page'  => $page,
        ]);
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items;
        foreach ($datas as &$data) {
            $data['Image'] = $data['Image'] ?: Section::DEFAULT_IMAGE;
            $data['Sign'] = $data['Sign'] ? '自有' : '采购';
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 2.0版本 首页科室入口的医院列表
     */
    public function buyerListAction()
    {
        $data = $this->request->get();
        $auth = $this->session->get('auth');
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $organizations = OrganizationSection::find([
            'conditions' => 'OrganizationId=?0 and SectionId=?1',
            'bind'       => [$auth['HospitalId'], $data['SectionId']],
        ])->toArray();
        $ids = [];
        $supplierIds = [];
        if (count($organizations)) {
            foreach ($organizations as $organization) {
                $ids[] = $organization['HospitalId'];
                if ($organization['Type'] == OrganizationSection::TYPE_SUPPLIER) {
                    $supplierIds[] = $organization['HospitalId'];
                }
            }
        }
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
        $result = $ids ? $sphinx->fetchAll() : [];
        $ids_new = array_column($result ?: [], 'id');
        $field = 'O.Id=' . $auth['HospitalId'] . ' desc';
        if (count($ids_new)) {
            $field = 'field(O.Id,' . implode(',', $ids_new) . '),O.Id=' . $auth['HospitalId'] . ' desc';
        }
        $dist_new = [];
        if (count($result)) {
            foreach ($result as $item) {
                $dist_new[$item['id']] = $item['dist'];
            }
        }
        $query = $this->modelsManager->createBuilder()
            ->columns([
                'O.Id as OrganizationId', 'OS.SectionId', 'O.Name', 'O.Type as HospitalType', 'O.LevelId', 'O.ProvinceId', 'O.CityId', 'O.AreaId', 'O.RuleId', 'O.Logo as Image', 'O.Score', 'O.TransferAmount',
                'OS.Type as SupplierType', 'OS.SectionId', 'R.Fixed', 'R.Ratio', 'R.DistributionOut', 'R.Type as ShareType',
            ])
            ->addFrom(Organization::class, 'O')
            ->leftJoin(OrganizationSection::class, "OS.HospitalId=O.Id and OS.OrganizationId={$auth['HospitalId']}", 'OS')
            ->leftJoin(RuleOfShare::class, 'R.Id=O.RuleId', 'R')
            ->inWhere('OS.HospitalId', $ids_new)
            ->andWhere('OS.SectionId=:SectionId:', ['SectionId' => $data['SectionId']])
            ->orderBy($field);
        if (isset($data['ShowAll']) && is_numeric($data['ShowAll']) && $data['ShowAll']) {
            $datas = $query->getQuery()->execute()->toArray();
            foreach ($datas as &$data) {
                $data['Dist'] = $dist_new[$data['OrganizationId']];
                $data['LevelName'] = HospitalLevel::value($data['LevelId']);
                $data['TypeName'] = OrganizationSection::TYPE_NAME[$data['SupplierType']];
                $data['HospitalType'] = OrganizationType::value($data['HospitalType']);
                $data['ShareType'] = RuleOfShare::RULE_RATIO;
            }
            return $this->response->setJsonContent($datas);
        }
        $paginator = new QueryBuilder(
            [
                'builder' => $query,
                'limit'   => $pageSize,
                'page'    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        foreach ($datas as &$data) {
            $data['Dist'] = $dist_new[$data['OrganizationId']];
            $data['LevelName'] = HospitalLevel::value($data['LevelId']);
            $data['TypeName'] = OrganizationSection::TYPE_NAME[$data['SupplierType']];
            $data['HospitalType'] = OrganizationType::value($data['HospitalType']);
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        return $this->response->setJsonContent($result);
    }
}