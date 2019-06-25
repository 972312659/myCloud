<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/13
 * Time: 11:36 AM
 */

namespace App\Libs\illness\hou;


use App\Models\Symptom;
use App\Models\Syndrome;

class Compute
{
    /** @var Symptom[] */
    public $symptoms;
    public $result = [];

    public function __construct($symptoms)
    {
        $this->symptoms = $symptoms;

    }

    /**
     * 西医症状得到西医症候分数的规则:(注意其中存在必选症状)
     *      只一级症状直接以一级症状分数作为得分，有二级症状的（以选择的最高分作为一级症状的得分）
     *      该症候一级分数相加*10=该症候分数
     */
    public function western()
    {
        //得到有二级的一级id
        $pIds = [];
        foreach ($this->symptoms as $symptom) {
            if ($symptom->Level == 2) {
                $pIds[] = $symptom->Pid;
            }
        }

        $tmp = [];
        foreach ($this->symptoms as $symptom) {
            if ($symptom->Level == 1 && !in_array($symptom->Id, $pIds)) {
                if (!isset($tmp[$symptom->SyndromeId][$symptom->Id])) {
                    $tmp[$symptom->SyndromeId][$symptom->Id] = $symptom->Score;
                }
            } else {
                if (isset($tmp[$symptom->SyndromeId][$symptom->Pid])) {
                    $tmp[$symptom->SyndromeId][$symptom->Pid] = $tmp[$symptom->SyndromeId][$symptom->Pid] >= $symptom->Score ? $tmp[$symptom->SyndromeId][$symptom->Pid] : $symptom->Score;
                } else {
                    $tmp[$symptom->SyndromeId][$symptom->Pid] = $symptom->Score;
                }
            }
        }

        $syndromes = Syndrome::query()
            ->columns(['Id', 'Name', 'MakeSureScore'])
            ->inWhere('Id', array_column($this->symptoms->toArray(), 'SyndromeId'))
            ->execute();

        if (count($syndromes->toArray())) {
            /** @var Syndrome $syndrome */
            foreach ($syndromes as $syndrome) {
                $score = 10 * array_sum($tmp[$syndrome->Id]);
                if ($score >= $syndrome->MakeSureScore) {
                    $this->result[] = ['Id' => $syndrome->Id, 'Name' => $syndrome->Name, 'Image' => $syndrome->Image, 'Score' => $score];
                }
            }
        }
    }

    /**
     * 中医症状得到中医症候分数的规则:
     *        该症候分数相加*10=该症候分数
     */
    public function chinese()
    {
        $tmp = [];
        foreach ($this->symptoms as $symptom) {
            $tmp[$symptom->SyndromeId][] = $symptom->Score;
        }

        $syndromes = Syndrome::query()
            ->columns(['Id', 'Name', 'MakeSureScore'])
            ->inWhere('Id', array_column($this->symptoms->toArray(), 'SyndromeId'))
            ->execute();

        if (count($syndromes->toArray())) {
            /** @var Syndrome $syndrome */
            foreach ($syndromes as $syndrome) {
                $score = 10 * array_sum($tmp[$syndrome->Id]);
                if ($score >= $syndrome->MakeSureScore) {
                    $this->result[] = ['Id' => $syndrome->Id, 'Name' => $syndrome->Name, 'Image' => $syndrome->Image, 'Score' => $score];
                }
            }
        }
    }
}