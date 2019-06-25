<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 5:40 PM
 * 疾病（侯丽萍风湿诊疗、）
 */

namespace App\Controllers;

use App\Enums\Status;
use App\Enums\SymptomDegree;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\illness\hou\Compute;
use App\Libs\illness\hou\Treatment;
use App\Models\CaseBook;
use App\Models\Illness;
use App\Models\Symptom;
use App\Models\Syndrome;
use App\Models\SyndromeProject;
use App\Models\SyndromeProjectValue;
use App\Models\SyndromeRelation;

class IllnessController extends Controller
{
    public function initialize()
    {
        $auth = $this->session->get('auth');
        if (!$auth['Identification']['Hou']) {
            throw new LogicException('请学习并取得认证', Status::BadRequest);
        }

    }

    /**
     * 症候群列表
     */
    public function syndromeListAction()
    {
        $data = $this->request->get();
        $syndrome = Syndrome::query()
            ->where('IllnessId=:IllnessId:')
            ->andWhere('IsChineseMedicine=:IsChineseMedicine:')
            ->bind(['IllnessId' => isset($data['IllnessId']) ? $data['IllnessId'] : Illness::Rheumatism, 'IsChineseMedicine' => isset($data['IsChineseMedicine']) ? $data['IsChineseMedicine'] : Syndrome::IsChineseMedicine_No])
            ->execute();
        $this->response->setJsonContent($syndrome);
    }

    /**
     * 读取一条症候
     */
    public function readSyndromeAction()
    {
        /** @var Syndrome $syndrome */
        $syndrome = Syndrome::findFirst(sprintf('Id=%d', $this->request->get('Id')));
        if (!$syndrome) {
            throw new LogicException('', Status::BadRequest);
        }
        $this->response->setJsonContent($syndrome);
    }

    /**
     * 症状列表
     */
    public function symptomListAction()
    {
        $ids = $this->request->get('SyndromeIds');
        if (!is_array($ids) || empty($ids)) {
            throw new LogicException('请选择所需要的症候', Status::BadRequest);
        }

        $getChineseMedicine = false;
        //如果传的西医症候，并且想得到中医症状
        if ($this->request->get('GetChineseMedicine') == 1) {
            $getChineseMedicine = true;
        };
        $result = \App\Libs\illness\Illness::getSymptom($ids, $getChineseMedicine);

        $this->response->setJsonContent($result);
    }

    /**
     * 计算
     */
    public function computeAction()
    {
        $syndromeIds = $this->request->get('SyndromeIds');
        $symptomIds = $this->request->get('SymptomIds');

        //验证并得到计算的对象是中医还是西医
        $iIsChineseMedicine = \App\Libs\illness\Illness::symptomValidate($syndromeIds, $symptomIds);

        $symptoms = Symptom::query()->inWhere('Id', $symptomIds)->execute();
        $compute = new Compute($symptoms);
        if ($iIsChineseMedicine === Syndrome::IsChineseMedicine_Yes) {
            $compute->chinese();
        } else {
            $compute->western();
        }

        $this->response->setJsonContent($compute->result);
    }

    /**
     * 得到治疗方案
     */
    public function getProjectAction()
    {
        /** @var Syndrome $syndrome */
        $syndrome = Syndrome::findFirst(sprintf('Id=%d', $this->request->get('Id')));
        if (!$syndrome) {
            throw new LogicException('未选择正确的症候群', Status::BadRequest);
        }
        $treatment = new Treatment($syndrome);
        $treatment->getTreatmentBySyndrome();

        $this->response->setJsonContent($treatment->treatment_old);
    }

    /**
     * 创建病例
     */
    public function createCasebookAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getJsonRawBody();
            //对比是否有变动
            /** @var Syndrome $syndrome */
            $syndrome = Syndrome::findFirst(sprintf('Id=%d', $data->Id));
            if (!$syndrome) {
                throw new LogicException('未选择正确的症候群', Status::BadRequest);
            }
            $treatment = new Treatment($syndrome);
            $treatment->getTreatmentBySyndrome();
            $treatment->getTreatmentByData((array)$data->TreatmentProject);
            $treatment->comparing();
            $treatment->createCasebook((array)$data->Symptom, (array)$data->SymptomAdd);

            $auth = $this->session->get('auth');
            /** @var CaseBook $casebook */
            $casebook = new CaseBook();
            $casebook->IDnumber = $data->IDnumber;
            $casebook->IllnessId = $syndrome->IllnessId;
            $casebook->SyndromeId = $syndrome->Id;
            $casebook->OrganizationId = $auth['OrganizationId'];
            $casebook->OrganizationName = $auth['OrganizationName'];
            $casebook->DoctorId = $auth['Id'];
            $casebook->DoctorName = $auth['Name'];
            $casebook->Content = serialize($treatment->casebook);
            $casebook->Changed = $treatment->changed ? CaseBook::Changed_Yes : CaseBook::Changed_No;
            if (!$casebook->save()) {
                $exception->loadFromModel($casebook);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }
}