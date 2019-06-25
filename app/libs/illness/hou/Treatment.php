<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/7
 * Time: 10:08 AM
 */

namespace App\Libs\illness\hou;

use App\Enums\SymptomDegree;
use App\Libs\illness\diff\DiffEngine;
use App\Libs\illness\diff\Diff;
use App\Libs\illness\models\CaseBook;
use App\Libs\illness\models\Symptom;
use App\Libs\illness\models\SymptomAdd;
use App\Libs\illness\models\TreatmentProject;
use App\Models\Syndrome;
use App\Models\SyndromeProject;
use App\Models\SyndromeProjectValue;

class Treatment
{
    /** @var  Syndrome */
    public $syndrome;
    /** @var  \App\Libs\illness\models\Treatment */
    public $treatment_old;
    /** @var  \App\Libs\illness\models\Treatment */
    public $treatment_new;
    /** @var  \App\Libs\illness\models\CaseBook */
    public $casebook;
    /** @var bool */
    public $changed = false;

    public function __construct(Syndrome $syndrome)
    {
        $this->syndrome = $syndrome;
    }

    /**
     * 对比两个治疗方案是否一致
     * @return bool
     */
    public function comparing()
    {
        $engine = new DiffEngine();
        $diff = $engine->compare($this->treatment_old, $this->treatment_new);
        $trace = function ($diff, $tab = '', $tier = 1) use (&$trace) {
            foreach ($diff as $element) {
                /** @var Diff $element */
                $c = $element->isTypeChanged() ? 'T' : ($element->isModified() ? 'M' : ($element->isCreated() ? '+' : ($element->isDeleted() ? '-' : '=')));
                switch ($c) {
                    case 'M':
                        $this->changed = true;
                        break;
                    case '+':
                        $this->changed = true;
                        break;
                }
                if ($diff->isModified()) {
                    $trace($element, $tab . '  ', $tier + 1);
                }
            }
        };
        $trace($diff);
        return $this->changed;
    }

    /**
     * 通过症候群得到治疗方案
     */
    public function getTreatmentBySyndrome()
    {
        $this->treatment_old = new \App\Libs\illness\models\Treatment();
        $this->treatment_old->Id = $this->syndrome->Id;
        $this->treatment_old->Name = $this->syndrome->Name;

        $syndromeProjects = SyndromeProject::find([
            'conditions' => 'IllnessId=?0',
            'bind'       => [$this->syndrome->IllnessId],
        ]);
        if (count($syndromeProjects->toArray())) {
            /** @var SyndromeProject $syndromeProject */
            foreach ($syndromeProjects as $syndromeProject) {
                $treatmentProject = new TreatmentProject();
                $treatmentProject->Name = $syndromeProject->Name;
                /** @var SyndromeProjectValue $syndromeProjectValue */
                $syndromeProjectValue = SyndromeProjectValue::findFirst([
                    'columns'    => 'Value',
                    'conditions' => 'SyndromeId=?0 and SyndromeProjectId=?1',
                    'bind'       => [$this->syndrome->Id, $syndromeProject->Id],
                ]);
                if ($syndromeProjectValue) {
                    $treatmentProject->Value = $syndromeProjectValue->Value;
                }

                $this->treatment_old->TreatmentProject[] = $treatmentProject;
            }
        }
    }

    /**
     * 得到新的治疗方案
     * @param array $treatmentProject
     */
    public function getTreatmentByData(array $treatmentProject)
    {
        $this->treatment_new = new \App\Libs\illness\models\Treatment();
        $this->treatment_new->Id = $this->syndrome->Id;
        $this->treatment_new->Name = $this->syndrome->Name;
        /** @var TreatmentProject $item */
        foreach ($treatmentProject as $item) {
            $treatmentProject = new TreatmentProject();
            $treatmentProject->Name = $item->Name;
            if (isset($item->Value)) {
                $treatmentProject->Value = $item->Value;
            }

            $this->treatment_new->TreatmentProject[] = $treatmentProject;
        }
    }

    /**
     * @param array $symptom    症状
     * @param array $symptomAdd 症状补充
     */
    public function createCasebook(array $symptom, array $symptomAdd)
    {
        $this->casebook = new CaseBook();
        /** @var Symptom $datum */
        foreach ($symptom as $datum) {
            /** @var Symptom $symptom */
            $symptom = new Symptom();
            $symptom->Id = intval($datum->Id);
            $symptom->Name = $datum->Name;
            $symptom->Level = isset($datum->Level) && is_numeric($datum->Level) ? intval($datum->Level) : 1;
            $symptom->LevelName = SymptomDegree::value('Rheumatism', $symptom->Level);
            $this->casebook->Symptom[] = $symptom;
        }
        /** @var SymptomAdd $item */
        foreach ($symptomAdd as $item) {
            /** @var SymptomAdd $symptomAdd */
            $symptomAdd = new SymptomAdd();
            $symptomAdd->Name = $item->Name;
            $symptomAdd->Value = $item->Value;
            $this->casebook->SymptomAdd[] = $symptomAdd;
        }
        $this->casebook->Treatment = $this->treatment_new;
    }
}