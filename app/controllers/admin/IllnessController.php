<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/17
 * Time: 4:04 PM
 */

namespace App\Admin\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\illness\hou\Treatment;
use App\Models\Illness;
use App\Models\Symptom;
use App\Models\Syndrome;
use App\Models\SyndromeProject;
use App\Models\SyndromeProjectValue;
use App\Models\SyndromeRelation;
use Phalcon\Paginator\Adapter\QueryBuilder;

class IllnessController extends Controller
{
    /**
     * 疾病列表
     */
    public function illnessListAction()
    {
        $this->response->setJsonContent(Illness::find());
    }

    /**
     * 该疾病下的中西医症候
     */
    public function syndromeListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = Syndrome::query()
            ->columns(['Id', 'Name', 'Image', 'IllnessId', 'MakeSureScore']);
        $bind = [];
        if (isset($data['IllnessId']) && is_numeric($data['IllnessId'])) {
            $query->andWhere('IllnessId=:IllnessId:');
            $bind['IllnessId'] = $data['IllnessId'];
        }
        if (isset($data['IsChineseMedicine']) && is_numeric($data['IsChineseMedicine'])) {
            $query->andWhere('IsChineseMedicine=:IsChineseMedicine:');
            $bind['IsChineseMedicine'] = $data['IsChineseMedicine'];
        }
        $query->bind($bind);
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
     * 该症候下面的所有症状
     */
    public function symptomListAction()
    {
        $data = $this->request->get();
        $query = Symptom::query()->where('Pid=0');
        $bind = [];
        if (isset($data['SyndromeId']) && is_numeric($data['SyndromeId'])) {
            $query->andWhere('SyndromeId=:SyndromeId:');
            $bind['SyndromeId'] = $data['SyndromeId'];
        }
        $symptoms = $query->bind($bind)->execute()->toArray();

        if (count($symptoms)) {
            $children = Symptom::query()
                ->inWhere('Pid', array_column($symptoms, 'Id'))
                ->execute()->toArray();
            $children_tmp = [];
            if (count($children)) {
                foreach ($children as $item) {
                    $children_tmp[$item['Pid']][] = $item;
                }
            }
            foreach ($symptoms as &$symptom) {
                $symptom['children'] = isset($children_tmp[$symptom['Id']]) ? $children_tmp[$symptom['Id']] : [];
            }
        }

        $this->response->setJsonContent($symptoms);
    }

    /**
     * 该症候的治疗方案
     */
    public function readSyndromeProjectAction()
    {
        /** @var Syndrome $syndrome */
        $syndrome = Syndrome::findFirst(sprintf('Id=%d', $this->request->get('SyndromeId')));
        if (!$syndrome) {
            throw new LogicException('未选择正确的症候群', Status::BadRequest);
        }
        $treatment = new Treatment($syndrome);
        $treatment->getTreatmentBySyndrome();

        $this->response->setJsonContent($treatment->treatment_old);
    }

    /**
     * 治疗方案字段列表
     */
    public function syndromeProjectListAction()
    {
        $data = $this->request->get();
        $query = SyndromeProject::query();
        $bind = [];
        if (isset($data['IllnessId']) && is_numeric($data['IllnessId'])) {
            $query->andWhere('IllnessId=:IllnessId:');
            $bind['IllnessId'] = $data['IllnessId'];
        }
        $syndromeProject = $query->bind($bind)->execute();
        $this->response->setJsonContent($syndromeProject);
    }

    /**
     * 新增、修改治疗方案字段
     */
    public function addSyndromeProjectAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $syndromeProject = new SyndromeProject();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                /** @var SyndromeProject $syndromeProject */
                $syndromeProject = SyndromeProject::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$syndromeProject) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if (!$syndromeProject->save($data)) {
                $exception->loadFromModel($syndromeProject);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 删除字段治疗方案字段
     */
    public function delSyndromeProjectAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            /** @var SyndromeProject $syndromeProject */
            $syndromeProject = SyndromeProject::findFirst(sprintf('Id=%d', $this->request->getPut('Id')));
            if (!$syndromeProject) {
                throw $exception;
            }
            /** @var SyndromeProjectValue $syndrome */
            $syndromeProjectValue = SyndromeProjectValue::findFirst([
                'conditions' => 'SyndromeProjectId=?0',
                'bind'       => [$syndromeProject->Id],
            ]);
            if ($syndromeProjectValue) {
                throw new LogicException('该字段正在被使用，不能删除', Status::MethodNotAllowed);
            }
            $syndromeProject->delete();
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 查询该症候的关联
     */
    public function syndromeRelationListAction()
    {
        /** @var Syndrome $syndrome */
        $syndrome = Syndrome::findFirst(sprintf('Id=%d', $this->request->get('SyndromeId')));
        if (!$syndrome) {
            throw new LogicException('错误', Status::BadRequest);
        }

        $syndromes = Syndrome::find([
            'columns'    => 'Id,Name',
            'conditions' => 'IllnessId=?0 and IsChineseMedicine=?1',
            'bind'       => [$syndrome->IllnessId, $syndrome->IsChineseMedicine ? Syndrome::IsChineseMedicine_No : Syndrome::IsChineseMedicine_Yes],
        ])->toArray();

        $columns = $syndrome->IsChineseMedicine ? 'WesternSyndromeId' : 'ChineseSyndromeId';
        $conditions = $syndrome->IsChineseMedicine ? 'ChineseSyndromeId=?0' : 'WesternSyndromeId=?0';
        $syndromeRelation = SyndromeRelation::find([
            'columns'    => $columns,
            'conditions' => $conditions,
            'bind'       => [$syndrome->Id],
        ])->toArray();
        $relations = array_column($syndromeRelation, $columns);

        foreach ($syndromes as &$item) {
            $item['Selected'] = in_array($item['Id'], $relations);
        }

        $this->response->setJsonContent($syndromes);
    }

    /**
     * 编辑症候之间关联关系
     */
    public function updateSyndromeRelationAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            if (!is_array($data['Ids'])) {
                throw new LogicException('数据错误', Status::BadRequest);
            }
            /** @var Syndrome $syndrome */
            $syndrome = Syndrome::findFirst(sprintf('Id=%d', $data['SyndromeId']));
            if (!$syndrome) {
                throw $exception;
            }
            $conditions = $syndrome->IsChineseMedicine ? 'ChineseSyndromeId=?0' : 'WesternSyndromeId=?0';
            $syndromeRelation = SyndromeRelation::find([
                'conditions' => $conditions,
                'bind'       => [$syndrome->Id],
            ]);
            if (count($syndromeRelation->toArray())) {
                $syndromeRelation->delete();
            }
            if (count($data['Ids'])) {
                foreach ($data['Ids'] as $id) {
                    /** @var SyndromeRelation $relation */
                    $relation = new SyndromeRelation();
                    $relation->ChineseSyndromeId = $syndrome->IsChineseMedicine ? $syndrome->Id : $id;
                    $relation->WesternSyndromeId = $syndrome->IsChineseMedicine ? $id : $syndrome->Id;
                    if (!$relation->save()) {
                        $exception->loadFromModel($relation);
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
     * 编辑治疗方案
     */
    public function updateSyndromeProjectAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            if (!is_array($data['TreatmentProject'])) {
                throw new LogicException('数据错误', Status::BadRequest);
            }
            /** @var Syndrome $syndrome */
            $syndrome = Syndrome::findFirst(sprintf('Id=%d', $data['SyndromeId']));
            if (!$syndrome) {
                throw $exception;
            }
            $syndromeProject = SyndromeProject::find(['conditions' => 'IllnessId=?0', 'bind' => [$syndrome->IllnessId]])->toArray();
            $syndromeProject_tmp = [];
            foreach ($syndromeProject as $item) {
                $syndromeProject_tmp[$item['Name']] = $item['Id'];
            }
            foreach ($data['TreatmentProject'] as $datum) {
                /** @var SyndromeProjectValue $syndromeProjectValue */
                $syndromeProjectValue = SyndromeProjectValue::findFirst([
                    'conditions' => 'SyndromeId=?0 and SyndromeProjectId=?1',
                    'bind'       => [$syndrome->Id, $syndromeProject_tmp[$datum['Name']]],
                ]);
                if (!$syndromeProjectValue) {
                    $syndromeProjectValue = new SyndromeProjectValue();
                    $syndromeProjectValue->SyndromeId = $syndrome->Id;
                    $syndromeProjectValue->SyndromeProjectId = $syndromeProject_tmp[$datum['Name']];
                }
                $syndromeProjectValue->Value = $datum['Value'];
                if (!$syndromeProjectValue->save()) {
                    $exception->loadFromModel($syndromeProjectValue);
                    throw $exception;
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
     * 新增、修改症状
     */
    public function updateSymptomAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                /** @var Syndrome $syndrome */
                $syndrome = Syndrome::findFirst(sprintf('Id=%d', $data['SyndromeId']));
                if (!$syndrome) {
                    throw $exception;
                }
                $symptom = new Symptom();
                $symptom->SyndromeId = $syndrome->Id;
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                /** @var Symptom $symptom */
                $symptom = Symptom::findFirst(sprintf('Id=%d', $data['SymptomId']));
                if (!$symptom) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $symptom->Describe = isset($data['Describe']) ? $data['Describe'] : null;
            $symptom->Image = isset($data['Image']) ? $data['Image'] : null;
            $symptom->Score = isset($data['Score']) ? $data['Score'] : 0;
            $symptom->Level = isset($data['Pid']) && $data['Pid'] == 0 ? 1 : 2;
            $symptom->Pid = is_numeric($data['Pid']) ? $data['Pid'] : 0;
            $symptom->IsRequired = is_numeric($data['IsRequired']) && $data['IsRequired'] == Symptom::IsRequired_Yes ? Symptom::IsRequired_Yes : Symptom::IsRequired_No;
            if (!$symptom->save()) {
                $exception->loadFromModel($symptom);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 新增、修改症候
     */
    public function addSyndromeAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $syndrome = new Syndrome();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                /** @var Syndrome $syndrome */
                $syndrome = Syndrome::findFirst(sprintf('Id=%d', $data['SyndromeId']));
                if (!$syndrome) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if (!$syndrome->save($data)) {
                $exception->loadFromModel($syndrome);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 新增、修改疾病
     */
    public function addIllnessAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $illness = new Illness();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                /** @var Illness $illness */
                $illness = Illness::findFirst(sprintf('Id=%d', $data['IllnessId']));
                if (!$illness) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if (!$illness->save($data)) {
                $exception->loadFromModel($illness);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 删除症状
     */
    public function delSymptomAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            /** @var Symptom $symptom */
            $symptom = Symptom::findFirst(sprintf('Id=%d', $this->request->getPut('SymptomId')));
            if (!$symptom) {
                throw $exception;
            }
            $child = Symptom::findFirst([
                'conditions' => 'Pid=?0',
                'bind'       => [$symptom->Id],
            ]);
            if ($child) {
                throw new LogicException('该症状下面有子节点，不能被删除', Status::BadRequest);
            }
            $symptom->delete();
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 删除症候
     */
    public function delSyndromeAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            /** @var Syndrome $syndrome */
            $syndrome = Syndrome::findFirst(sprintf('Id=%d', $this->request->getPut('SyndromeId')));
            if (!$syndrome) {
                throw $exception;
            }
            /** @var Symptom $symptom */
            $symptom = Symptom::findFirst([
                'conditions' => 'SyndromeId=?0',
                'bind'       => [$syndrome->Id],
            ]);
            if ($symptom) {
                throw new LogicException('该症候下面有症状，不能被删除', Status::BadRequest);
            }
            $conditions = $syndrome->IsChineseMedicine ? 'ChineseSyndromeId=?0' : 'WesternSyndromeId=?0';
            $syndromeRelation = SyndromeRelation::find([
                'conditions' => $conditions,
                'bind'       => [$syndrome->Id],
            ]);
            if (count($syndromeRelation->toArray())) {
                $syndromeRelation->delete();
            }
            $syndromeProjectValue = SyndromeProjectValue::find([
                'conditions' => 'SyndromeId=?0',
                'bind'       => [$syndrome->Id],
            ]);
            if (count($syndromeProjectValue->toArray())) {
                $syndromeProjectValue->delete();
            }
            $syndrome->delete();
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
     * 删除疾病
     */
    public function delIllnessAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            /** @var Illness $illness */
            $illness = Illness::findFirst(sprintf('Id=%d', $this->request->getPut('IllnessId')));
            if (!$illness) {
                throw $exception;
            }
            /** @var Syndrome $syndrome */
            $syndrome = Syndrome::findFirst([
                'conditions' => 'IllnessId=?0',
                'bind'       => [$illness->Id],
            ]);
            if ($syndrome) {
                throw new LogicException('该疾病下面有症候，不能被删除', Status::BadRequest);
            }
            $syndromeProject = SyndromeProject::find([
                'conditions' => 'IllnessId=?0',
                'bind'       => [$illness->Id],
            ]);
            if (count($syndromeProject->toArray())) {
                $syndromeProject->delete();
            }
            $illness->delete();
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }
}