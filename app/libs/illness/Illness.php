<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/13
 * Time: 11:45 AM
 */

namespace App\Libs\illness;

use App\Enums\Status;
use App\Enums\SymptomDegree;
use App\Exceptions\LogicException;
use App\Models\Symptom;
use App\Models\Syndrome;
use App\Models\SyndromeRelation;

class Illness
{
    /**
     * 通过症候群->得到症状
     * @param array $ids
     * @param bool $getChineseMedicine
     * @return array
     * @throws LogicException
     */
    public static function getSymptom(array $ids, bool $getChineseMedicine = false)
    {
        if (!is_array($ids) || empty($ids)) {
            throw new LogicException('请选择所需要的症候', Status::BadRequest);
        }

        //如果传的西医症候，并且想得到中医症状
        if ($getChineseMedicine == true) {
            /** @var Syndrome $syndrome */
            $syndrome = Syndrome::findFirst(sprintf('Id=%d', reset($ids)));
            if ($syndrome) {
                if ($syndrome->IsChineseMedicine == Syndrome::IsChineseMedicine_No) {
                    $ids = self::getSyndromeIds($ids);
                }
            }
        }
        $symptoms = Symptom::query()
            ->inWhere('SyndromeId', $ids)
            ->execute()->toArray();

        $result = [];
        $tmp = [];
        foreach ($symptoms as $symptom) {
            if ($symptom['Level'] == 1) {
                //第一层
                $result[] = $symptom;
            } else {
                //第二层
                $tmp[$symptom['Pid']][] = $symptom;
            }
        }

        foreach ($result as &$item) {
            $item['Kids'] = isset($tmp[$item['Id']]) ? $tmp[$item['Id']] : [];
            $item['SingleDeck'] = isset($tmp[$item['Id']]) ? false : true;
            $item['Degree'] = SymptomDegree::$map['Rheumatism'];
        }
        return $result;
    }

    /**
     * 西医症候群ids->得到中医症候群ids、得到中医症候群ids->西医症候群ids
     * @param array $syndromeIds
     * @param bool $IsWesternSyndromeIdsToChineseSyndromeIds
     * @return array
     */
    public static function getSyndromeIds(array $syndromeIds, $IsWesternSyndromeIdsToChineseSyndromeIds = true)
    {
        $query = SyndromeRelation::query();
        if ($IsWesternSyndromeIdsToChineseSyndromeIds) {
            $query->inWhere('WesternSyndromeId', $syndromeIds);
        } else {
            $query->inWhere('ChineseSyndromeId', $syndromeIds);
        }
        $syndromes = $query->execute()->toArray();
        return array_column($syndromes, $IsWesternSyndromeIdsToChineseSyndromeIds ? 'ChineseSyndromeId' : 'WesternSyndromeId');
    }

    /**
     * 症状验证
     * @param array $syndromeIds 症候群ids
     * @param array $symptomIds  症状ids
     * @throws LogicException
     * @return int
     */
    public static function symptomValidate(array $syndromeIds, array $symptomIds)
    {
        if (!is_array($syndromeIds) || empty($syndromeIds) || !is_array($symptomIds) || empty($symptomIds)) {
            throw new LogicException('请选择症状', Status::BadRequest);
        }
        $symptomIds_new = [];
        foreach ($symptomIds as $id) {
            $symptomIds_new[] = intval($id);
        }
        $syndromeIds_new = [];
        foreach ($syndromeIds as $id) {
            $syndromeIds_new[] = intval($id);
        }

        /** @var Symptom $symptom */
        $symptom = Symptom::findFirst(sprintf('Id=%d', reset($symptomIds_new)));
        if (!$symptom) {
            throw new LogicException('症状的参数错误', Status::BadRequest);
        }
        /** @var Syndrome $syndrome */
        $syndrome = Syndrome::findFirst(sprintf('Id=%d', $symptom->SyndromeId));
        if (!$syndrome) {
            throw new LogicException('症状的参数错误', Status::BadRequest);
        }

        $isChineseMedicine = $syndrome->IsChineseMedicine;

        if (!in_array($symptom->SyndromeId, $syndromeIds_new)) {
            $syndromeIds_new = self::getSyndromeIds($syndromeIds_new, $syndrome->IsChineseMedicine === Syndrome::IsChineseMedicine_Yes ? true : false);
        }

        $symptoms = Symptom::query()
            ->columns(['Id', 'Describe'])
            ->inWhere('SyndromeId', $syndromeIds_new)
            ->andWhere(sprintf('IsRequired=%d', Symptom::IsRequired_Yes))
            ->execute();

        if (count($symptoms->toArray())) {
            $pIds = array_column(Symptom::query()->columns(['Pid'])->inWhere('Id', $symptomIds_new)->execute()->toArray(), 'Pid');
            $symptomIds_new = array_merge($symptomIds_new, $pIds);

            foreach ($symptoms as $symptom) {
                /** @var Symptom $symptom */
                if (!in_array($symptom->Id, $symptomIds_new)) {
                    throw new LogicException($symptom->Describe . '必须选择', Status::BadRequest);
                }
            }
        }

        return $isChineseMedicine;
    }
}