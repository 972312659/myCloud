<?php

namespace App\Libs\fake;

use App\Enums\Status;
use App\Exceptions\ParamException;
use App\Libs\fake\composer\Color;
use App\Libs\fake\composer\Composer;
use App\Libs\fake\composer\Image;
use App\Libs\fake\composer\Position;
use App\Libs\fake\composer\Text;
use App\Libs\fake\transfer\disease\Disease;
use App\Libs\fake\transfer\disease\Transfer as DiseaseTransfer;
use App\Libs\fake\transfer\Generator;
use App\Libs\fake\models\Transfer;
use App\Libs\fake\models\TransferLog;
use App\Libs\fake\models\TransferPicture;
use Phalcon\Di;
use Qiniu\Auth;
use Qiniu\Storage\UploadManager;

class Faker
{
    protected $generator;

    protected $composer;

    protected $disease;

    public function __construct(Composer $composer, Generator $generator, Disease $disease)
    {
        $this->composer = $composer;
        $this->generator = $generator;
        $this->disease = $disease;

        $this->defaultFont();
    }

    public function createTransfer(\DateTime $end_time)
    {
        $person = $this->generator->createPerson();
        $transfer = $this->generator->createDefaultTransfer();
        //根据person生成症状和治疗周期
        $disease = $this->disease->rand($person->gender, $person->getAge(), $end_time);

        if ($disease === null) {
            return;
        }
        $transfer->PatientName = $person->name;
        $transfer->PatientSex = $person->gender;
        $transfer->PatientAddress = $person->address;
        $transfer->PatientId = $person->id_card;
        $transfer->PatientTel = $person->phone;
        $transfer->AcceptSectionId = $disease->AcceptSectionId;
        $transfer->AcceptDoctorId = $disease->AcceptDoctorId;
        $transfer->AcceptSectionName = $disease->AcceptSectionName;
        $transfer->AcceptDoctorName = $disease->AcceptDoctorName;
        $transfer->Disease = $disease->Disease;
        $transfer->Cost = $disease->Cost;
        $transfer->PatientAge = $disease->PatientAge;
        $transfer->EndTime = $disease->EndTime;
        $transfer->LeaveTime = $disease->LeaveTime;
        $transfer->ClinicTime = $disease->ClinicTime;
        $transfer->StartTime = $disease->StartTime;

        //活得症状信息以后,根据治疗的科室获取大B
        $transfer->AcceptOrganizationId = $disease->AcceptOrganizationId;
        $transfer->SendOrganizationId = $disease->SendOrganizationId;
        $transfer->SendHospitalId = $disease->SendHospitalId;
        $transfer->SendOrganizationName = $disease->SendOrganizationName;

        //生成单号
        $transfer->OrderNumber = $transfer->StartTime << 32 | substr('0000000' . $transfer->SendOrganizationId, -7, 7);

        //生成图片
        $this->createImage($transfer, $disease);

        //生成log
        $this->createLogs($transfer, $disease);

        //分润
        $exception = new ParamException(Status::BadRequest);

        if (!$transfer->save()) {
            $exception->loadFromModel($transfer);
            throw $exception;
        }
    }

    /**
     * 生成log
     *
     * @param Transfer $transfer
     * @param DiseaseTransfer $disease
     */
    public function createLogs(Transfer $transfer, DiseaseTransfer $disease)
    {
        //结算完成
        $end = new TransferLog();
        $end->OrganizationId = $transfer->AcceptOrganizationId;
        $end->OrganizationName = $disease->AcceptOrganizationName;
        $end->Status = 8;
        $end->LogTime = $transfer->EndTime;

        //出院
        $leave = new TransferLog();
        $leave->OrganizationId = $transfer->AcceptOrganizationId;
        $leave->OrganizationName = $disease->AcceptOrganizationName;
        $leave->Status = 6;
        $leave->LogTime = $transfer->LeaveTime;

        //发起
        $start = new TransferLog();
        $start->OrganizationId = $transfer->SendOrganizationId;
        $start->OrganizationName = $transfer->SendOrganizationName;
        $start->Status = 2;
        $start->LogTime = $transfer->StartTime;

        //接收
        $receive = new TransferLog();
        $receive->OrganizationId = $transfer->AcceptOrganizationId;
        $receive->OrganizationName = $disease->AcceptOrganizationName;
        $receive->LogTime = $disease->AcceptTime;
        $receive->Status = 3;

        //治疗中
        $healing = new TransferLog();
        $healing->OrganizationId = $transfer->AcceptOrganizationId;
        $healing->OrganizationName = $disease->AcceptOrganizationName;
        $healing->Status = 5;
        $healing->LogTime = $transfer->ClinicTime;

        $transfer->TransferLogs = [
            $start,
            $receive,
            $healing,
            $leave,
            $end,
        ];
    }

    public function createImage(Transfer $transfer, DiseaseTransfer $disease)
    {
        $image_id = (int)substr($disease->SendOrganizationId, -1, 1) % 2;
        $image_src = __DIR__ . '/resource/' . $image_id . '.png';
        $texts = [];

        $font = 'simsun';

        //姓名
        $name = Text::create();
        $name->setText($transfer->PatientName);
        $name->setSize(12);
        $name->setFamily($font);
        array_push($texts, $name);

        //住院号
        $inpatient_number = Text::create();
        $inpatient_number->setText((float)str_replace('.', '', microtime(true)) + rand(100, 999));
        $inpatient_number->setSize(12);
        $inpatient_number->setFamily($font);
        array_push($texts, $inpatient_number);

        //科室
        $section = Text::create();
        $section->setText($disease->AcceptSectionName);
        $section->setFamily($font);
        $section->setSize(12);
        array_push($texts, $section);

        //性别
        $gender = Text::create();
        $gender->setText($transfer->PatientSex == 1 ? '男' : '女');
        $gender->setFamily($font);
        $gender->setSize(12);
        array_push($texts, $gender);

        //费用总额
        $cost = Text::create();
        $cost->setText($this->numberFormat($disease->Cost));
        $cost->setFamily($font);
        $cost->setSize(12);
        array_push($texts, $cost);

        $black = new Color(0, 0, 0);

        //MD 这块好麻烦  先写出来能用再优化
        switch ($image_id) {
            case 0:
                $color = new Color(26, 78, 106);
                //病人id
                //剪掉住院号前2位然后翻转
                $no = substr($inpatient_number->getText(), 2);
                $no = strrev($no);
                $patient_id = Text::create();
                $patient_id->setText($no);
                $patient_id->setSize(12);
                $patient_id->setFamily($font);
                $patient_id->setColor($color);
                $patient_id->setPosition(new Position(103, 48));
                array_push($texts, $patient_id);

                //住院号
                $inpatient_number->setColor($color);
                $inpatient_number->setPosition(new Position(310, 48));

                //姓名
                $name->setColor($color);
                $name->setPosition(new Position(105, 79));

                //性别
                $gender->setColor($color);
                $gender->setPosition(new Position(517, 79));

                //科室
                $section->setColor($color);
                $section->setPosition(new Position(312, 109));

                //费用总额
                $cost->setColor(new Color(255, 255, 255));
                $cost->setPosition(new Position(755, 205));

                //住院天数
                $day = Text::create();
                $day->setText($disease->Day);
                $day->setFamily($font);
                $day->setColor($color);
                $day->setSize(12);
                $day->setPosition(new Position(315, 137));
                array_push($texts, $day);

                //出院时间
                $leave = Text::create();
                $leave->setText(date('Y-m-d'));
                $leave->setFamily($font);
                $leave->setSize(12);
                $leave->setColor($color);
                $leave->setPosition(new Position(515, 108));
                array_push($texts, $leave);

                //入院时间
                $in = Text::create();
                $in->setText(date('Y-m-d', strtotime('-' . $disease->Day . ' day')));
                $in->setFamily($font);
                $in->setSize(12);
                $in->setColor($color);
                $in->setPosition(new Position(105, 108));
                array_push($texts, $in);

                //费用清单
                $col = 1;
                $row = 1;
                $x_base = 18;
                $y_base = 239;
                $col_padding = 68; //费用项间距

                foreach ($disease->Fee as $name => $fee) {
                    $disease_name = Text::create();
                    $disease_name->setText($name);
                    $disease_name->setFamily($font);
                    $disease_name->setColor($black);
                    $disease_name->setSize(10.5);
                    $disease_name->setPosition(new Position($x_base, $row * $y_base));
                    array_push($texts, $disease_name);

                    $disease_fee = Text::create();
                    $disease_fee->setText($this->numberFormat($fee));
                    $disease_fee->setFamily($font);
                    $disease_fee->setColor($black);
                    $disease_fee->setSize(10.5);
                    $disease_fee->setPosition(new Position($x_base + $col_padding, $row * $y_base));
                    array_push($texts, $disease_fee);

                    //实收金额
                    $true_fee = Text::create();
                    $true_fee->setText($disease_fee->getText());
                    $true_fee->setFamily($font);
                    $true_fee->setColor($black);
                    $true_fee->setSize(10.5);
                    $true_fee->setPosition(new Position($x_base + $col_padding * 2, $row * $y_base));
                    array_push($texts, $true_fee);

                    $col++;

                    //超过3列以后重置
                    if ($col > 3) {
                        $x_base = 18;
                        $y_base += 20;
                    } else {
                        $x_base += 213;
                    }
                }

                break;
            case 1:
                $color = new Color(0, 0, 0);

                //姓名
                $name->setColor($color);
                $name->setPosition(new Position(200, 218));
                $name->setSize(10);

                //科室
                $section->setColor($color);
                $section->setSize(10);
                $section->setPosition(new Position(586, 218));

                //住院天数
                $day = Text::create();
                $day->setText($disease->Day);
                $day->setFamily($font);
                $day->setColor($color);
                $day->setSize(10);
                $day->setPosition(new Position(1360, 218));
                array_push($texts, $day);

                //出院时间
                $leave = Text::create();
                $leave->setText(date('Y-m-d'));
                $leave->setFamily($font);
                $leave->setSize(10);
                $leave->setColor($color);
                $leave->setPosition(new Position(1360, 195));
                array_push($texts, $leave);

                //入院时间
                $in = Text::create();
                $in->setText(date('Y-m-d', strtotime('-' . $disease->Day . ' day')));
                $in->setFamily($font);
                $in->setSize(10);
                $in->setColor($color);
                $in->setPosition(new Position(1360, 173));
                array_push($texts, $in);

                //住院号
                $inpatient_number->setSize(10);
                $inpatient_number->setColor($color);
                $inpatient_number->setPosition(new Position(200, 195));

                //性别
                $gender->setColor($color);
                $gender->setSize(10);
                $gender->setPosition(new Position(586, 107));

                //年龄
                $age = Text::create();
                $age->setText($disease->PatientAge);
                $age->setSize(10);
                $age->setColor($color);
                $age->setFamily($font);
                $age->setPosition(new Position(586, 130));
                array_push($texts, $age);

                //费用
                $cost->setSize(10);
                $cost->setPosition(new Position(1360, 130));

                $x = 70;
                $y = 300;
                $row = 1;
                //费用明细
                foreach ($disease->Fee as $name => $fee) {
                    $disease_name = Text::create();
                    $disease_name->setText($name);
                    $disease_name->setFamily($font);
                    $disease_name->setColor($color);
                    $disease_name->setSize(10);
                    $disease_name->setPosition(new Position($x, $row * $y));
                    array_push($texts, $disease_name);

                    $disease_fee = Text::create();
                    $disease_fee->setText(number_format($fee / 100, 2, '.', ''));
                    $disease_fee->setFamily($font);
                    $disease_fee->setColor($color);
                    $disease_fee->setSize(10);
                    $disease_fee->setPosition(new Position($x + 230, $row * $y));
                    array_push($texts, $disease_fee);

                    $y += 20;
                }

                break;
        }
        $image = new Image($image_src);

        $resource = $this->composer->compose($image, $texts);

        ob_start();
        imagepng($resource);
        $content = ob_get_contents();
        ob_end_clean();

        /** @var Auth $qiniu */
        $qiniu = Di::getDefault()->getShared('qiniu');
        $token = $qiniu->uploadToken('referral');

        $upload_manager = new UploadManager();

        $ret = $upload_manager->put($token, null, $content, null, 'image/png');

        if (!empty($ret[1])) {
            throw new \Exception($ret[1]->message());
        }

        $pic = new TransferPicture();
        $pic->Type = 2;
        $pic->Image = 'https://referral-store.100cbc.com/' . $ret[0]['key'];
        $transfer->Pictures = [$pic];
    }

    private function defaultFont()
    {
        $this->composer->getManager()->addFont('simsun', __DIR__ . '/resource/SIMSUN.TTC');
    }

    private function numberFormat($number)
    {
        return number_format($number / 100, 2, '.', '');
    }
}
