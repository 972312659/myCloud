<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/1/24
 * Time: 2:34 PM
 */

use Phalcon\Cli\Task;

class TransferTask extends Task
{
    /**
     * 处理转诊图片
     */
    public function flowAction()
    {
        $columns = [
            \App\Models\TransferPicture::TYPE_FEE       => 'FeeExplain',
            \App\Models\TransferPicture::TYPE_THERAPIES => 'TherapiesExplain',
            \App\Models\TransferPicture::TYPE_REPORT    => 'ReportExplain',
            \App\Models\TransferPicture::TYPE_DIAGNOSIS => 'DiagnosisExplain',
        ];

        $transfers = \App\Models\Transfer::find(['Status>=6']);
        foreach ($transfers as $transfer) {
            /** @var \App\Models\Transfer $transfer */


            /** @var \App\Models\TransferFlow $flow */
            $pictures = \App\Models\TransferPicture::find([
                'conditions' => 'TransferId=?0',
                'bind'       => [$transfer->Id],
            ]);
            $temp = [];
            if (count($pictures->toArray())) {
                foreach ($pictures as $picture) {
                    /** @var \App\Models\TransferPicture $picture */
                    $temp[$picture->Type][] = $picture->Image;
                }
            }

            /** @var \App\Models\TransferFlow $flow */
            $flow = new \App\Models\TransferFlow();
            $flow->TransferId = $transfer->Id;
            $flow->GenreOne = $transfer->GenreOne;
            $flow->ShareOne = $transfer->ShareOne;
            $flow->CloudGenre = $transfer->CloudGenre;
            $flow->ShareCloud = $transfer->ShareCloud;
            $flow->SectionId = $transfer->AcceptSectionId;
            $flow->SectionName = $transfer->AcceptSectionName;
            $flow->DoctorId = $transfer->AcceptDoctorId;
            $flow->DoctorName = $transfer->AcceptDoctorName;
            $flow->OutpatientOrInpatient = $transfer->OutpatientOrInpatient;
            $flow->Created = $transfer->LeaveTime;
            $flow->Cost = $transfer->Cost;
            $flow->ClinicRemark = $transfer->Remake;
            $flow->FinishRemark = $transfer->Explain;
            $flow->TherapiesExplain = $transfer->TherapiesExplain;
            $flow->ReportExplain = $transfer->ReportExplain;
            $flow->DiagnosisExplain = $transfer->DiagnosisExplain;
            $flow->FeeExplain = $transfer->FeeExplain;
            if ($transfer->Pid != 0) {
                $flow->CanModify = \App\Models\TransferFlow::CanModify_Yes;
            } else {
                $flow->CanModify = $transfer->Source == \App\Models\Transfer::SOURCE_COMBO ? \App\Models\TransferFlow::CanModify_No : \App\Models\TransferFlow::CanModify_Yes;
            }
            foreach ($columns as $k => $column) {
                $flow->{$column . 'Images'} = json_encode(isset($temp[$k]) ? $temp[$k] : []);
            }
            echo "Id:$transfer->Id\n";
            echo "插入数据CanModify:$flow->CanModify,FeeExplainImages:$flow->FeeExplainImages,TherapiesExplainImages: $flow->TherapiesExplainImages, ReportExplainImages: $flow->ReportExplainImages, DiagnosisExplainImages:$flow->DiagnosisExplainImages\n";

            $flow->save();

        }
        echo 'ok' . PHP_EOL;
    }
}