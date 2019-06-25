<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/1/22
 * Time: 4:12 PM
 */

namespace App\Libs\transfer;


use App\Enums\DoctorTitle;
use App\Models\Evaluate;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\SalesmanBonus;
use App\Models\Transfer;
use App\Models\TransferFlow;
use App\Models\TransferLog;
use App\Models\TransferPicture;
use App\Models\User;
use Phalcon\Di\FactoryDefault;
use App\Libs\appOps\Control as AppOpsControl;

class Read
{
    /**
     * @var Transfer
     */
    protected $transfer;

    /**
     * @var Organization
     */
    protected $hospital;

    /**
     * @var Organization
     */
    protected $sendOrganization;

    /**
     * @var Organization
     */
    protected $sendHospital;

    /**
     * 用户的机构是医院还是网点
     * @var bool
     */
    protected $isHospital;

    /**
     * 是否流转
     * @var bool
     */
    protected $isFlowed = false;

    /**
     * @var array
     */

    public $info = [];

    public function __construct(Transfer $transfer)
    {
        $this->transfer = $transfer;
        $this->hospital = $transfer->Hospital;
        $this->sendOrganization = $transfer->SendOrganization;
        $auth = FactoryDefault::getDefault()->get('session')->get('auth');
        $this->isHospital = $auth['HospitalId'] == $auth['OrganizationId'];
        if ($this->isHospital) {
            $this->sendHospital = $transfer->SendHospital;
        }
    }

    /**
     * 展示
     */
    public function show()
    {
        $this->isHospital ? $this->forHospital() : $this->forSlave();
        return $this->info;
    }

    public function adminShow()
    {
        $this->forHospital();
        return $this->info;
    }

    /**
     * 医院端展示
     */
    public function forHospital()
    {
        $this->baseInfo();
        $this->accountInfo();
        $this->senderInfo();
        $this->doctorInfo();
        $this->patientInfo();
        $this->casePictures();
        $this->flows();
        $this->evaluate();
        $this->logs();
        $this->timeInfo();//在logs之后调用
        $this->interiorTradeUnPass();
        $this->appOps();
    }

    /**
     * 小b端展示
     */
    public function forSlave()
    {
        $this->baseInfo();
        $this->doctorInfo();
        $this->patientInfo();
        $this->casePictures();
        $this->flows();
        $this->doctorsInfo();//在flows之后调用
        $this->evaluate();
        $this->logs();
        $this->timeInfo();//在logs之后调用
    }

    /**
     * 基本信息
     */
    public function baseInfo()
    {
        $this->info = array_merge($this->info, $this->status(), $this->source(),
            [
                'Id'                        => $this->transfer->Id,
                'OrderNumber'               => (string)$this->transfer->OrderNumber,
                'StartTime'                 => $this->transfer->StartTime,
                'Genre'                     => $this->transfer->Genre,
                'GenreName'                 => $this->transfer->Genre == Transfer::GENRE_SELF ? '自有' : '共享',
                'AcceptOrganizationId'      => $this->transfer->AcceptOrganizationId,
                'AcceptOrganizationName'    => $this->hospital->Name,
                'SendHospitalName'          => $this->isHospital ? $this->sendHospital->Name : $this->hospital->Name,
                'AcceptSectionId'           => $this->transfer->AcceptSectionId,
                'AcceptDoctorId'            => $this->transfer->AcceptDoctorId,
                'AcceptSectionName'         => $this->transfer->AcceptSectionName,
                'AcceptDoctorName'          => $this->transfer->AcceptDoctorName,
                'OldDoctorName'             => $this->transfer->OldDoctorName,
                'OldSectionName'            => $this->transfer->OldSectionName,
                'Remark'                    => $this->transfer->Remake,
                'IsEvaluate'                => $this->transfer->IsEvaluate,
                'OutpatientOrInpatient'     => $this->transfer->OutpatientOrInpatient,
                'OutpatientOrInpatientName' => Transfer::OutpatientOrInpatient_Name[(int)$this->transfer->OutpatientOrInpatient],
            ]
        );
    }

    /**
     * 状态
     */
    public function status(): array
    {
        return [
            'Status'     => $this->transfer->Status,
            'StatusName' => Transfer::STATUS_NAME[(int)$this->transfer->Status],
        ];
    }

    public function source(): array
    {
        return [
            'Source' => $this->transfer->Source,
        ];
    }

    /**
     * 当前的结算信息
     */
    public function accountInfo()
    {
        $this->info = array_merge($this->info,
            [
                'ShareOne'   => $this->transfer->ShareOne,
                'ShareTwo'   => $this->transfer->ShareTwo,
                'ShareCloud' => $this->transfer->ShareCloud,
                'GenreOne'   => $this->transfer->GenreOne,
                'GenreTwo'   => $this->transfer->GenreTwo,
                'CloudGenre' => $this->transfer->CloudGenre,
                'Cost'       => $this->transfer->Cost,
            ]
        );
    }

    /**
     * 创建者信息
     * 如果是医院还会显示业务经理信息
     */
    public function senderInfo()
    {
        /** @var TransferLog $log */
        $log = TransferLog::findFirst([
            'conditions' => 'TransferId=?0 and OrganizationId=?1 and Status=?2',
            'bind'       => [$this->transfer->Id, $this->sendOrganization->Id, Transfer::CREATE],
        ]);
        if ($log) {
            /** @var User $user */
            $user = User::findFirst(sprintf('Id=%d', $log->UserId));
        } else {
            /** @var OrganizationUser $organizationUser */
            $organizationUser = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0',
                'bind'       => [$this->transfer->SendOrganizationId],
            ]);
            /** @var User $user */
            $user = User::findFirst(sprintf('Id=%d', $organizationUser->UserId));
        }

        $sendOrganizationName = $this->transfer->SendOrganizationName;
        $salesmanInfo = [];
        $salesmanBonus = [];
        if ($this->transfer->Genre === Transfer::GENRE_SELF && $this->isHospital) {
            /** @var OrganizationRelationship $organizationRelation */
            $organizationRelation = OrganizationRelationship::findFirst([
                'conditions' => 'MainId=?0 and MinorId=?1',
                'bind'       => [$this->hospital->Id, $this->sendOrganization->Id],
            ]);
            if ($organizationRelation) {
                $sendOrganizationName = $organizationRelation->MinorName;
            }
            $salesman = $this->salesman($organizationRelation);
            $salesmanInfo = [
                'SalesmanName'  => $salesman ? $salesman->Name : '',
                'SalesmanPhone' => $salesman ? $salesman->Phone : '',
            ];

            //业务经理奖励
            $salesmanBonus = $this->salesmanBonus($organizationRelation);
        }

        $this->info = array_merge($this->info, $salesmanInfo, $salesmanBonus,
            [
                'SendOrganizationId'   => $this->transfer->SendOrganizationId,
                'SendOrganizationName' => $sendOrganizationName,
                'SenderName'           => $log->UserName ?: $user->Name,
                'SenderPhone'          => $user->Phone,
                'SenderAddress'        => $this->sendOrganization->Address,
            ]
        );
    }

    /**
     * @param $organizationRelation
     * @return User
     */
    public function salesman($organizationRelation)
    {
        /** @var OrganizationRelationship $organizationRelation */
        $organizationRelation = $organizationRelation ?: OrganizationRelationship::findFirst([
            'conditions' => 'MinorId=?0',
            'bind'       => [$this->sendOrganization->Id],
        ]);
        /** @var User $user */
        $user = User::findFirst(sprintf('Id=%d', $organizationRelation->SalesmanId));;
        return $user;
    }

    /**
     * 医生信息
     */
    public function doctorInfo()
    {
        /** @var OrganizationUser $doctor */
        $doctor = OrganizationUser::findFirst([
            'conditions' => 'UserId=?0 and OrganizationId=?1',
            'bind'       => [$this->transfer->AcceptDoctorId, $this->transfer->AcceptOrganizationId],
        ]);
        $this->info = array_merge($this->info,
            [
                'AcceptDoctorImage'     => $doctor ? $doctor->Image : OrganizationUser::DEFAULT_IMAGE,
                'AcceptDoctorTitleName' => $doctor ? DoctorTitle::value($doctor->Title) : '',
            ]
        );
    }

    /**
     * 全部医生信息
     * 必须在$this->flows() 之后调用
     */
    public function doctorsInfo()
    {
        $result['Doctors'] = [];
        if ($this->isFlowed) {
            if (isset($this->info['Flows'])) {
                foreach ($this->info['Flows'] as $flow) {
                    $result['Doctors'][] = [
                        'HospitalId'      => $this->hospital->Id,
                        'HospitalName'    => $this->hospital->Name,
                        'DoctorId'        => $flow['DoctorId'],
                        'DoctorName'      => $flow['DoctorName'],
                        'DoctorImage'     => $flow['DoctorImage'],
                        'DoctorTitleName' => $flow['DoctorTitleName'],
                        'SectionId'       => $flow['SectionId'],
                        'SectionName'     => $flow['SectionName'],
                    ];
                }
            }
            if ($this->transfer->Status < Transfer::LEAVE && $this->info['AcceptDoctorId']) {
                $result['Doctors'][] = [
                    'HospitalId'      => $this->hospital->Id,
                    'HospitalName'    => $this->hospital->Name,
                    'DoctorId'        => $this->info['AcceptDoctorId'],
                    'DoctorName'      => $this->info['AcceptDoctorName'],
                    'DoctorImage'     => $this->info['AcceptDoctorImage'],
                    'DoctorTitleName' => $this->info['AcceptDoctorTitleName'],
                    'SectionId'       => $this->info['AcceptSectionId'],
                    'SectionName'     => $this->info['AcceptSectionName'],
                ];
            }
        } else {
            if ($this->info['AcceptDoctorId']) {
                $result['Doctors'][] = [
                    'HospitalId'      => $this->hospital->Id,
                    'HospitalName'    => $this->hospital->Name,
                    'DoctorId'        => $this->info['AcceptDoctorId'],
                    'DoctorName'      => $this->info['AcceptDoctorName'],
                    'DoctorImage'     => $this->info['AcceptDoctorImage'],
                    'DoctorTitleName' => $this->info['AcceptDoctorTitleName'],
                    'SectionId'       => $this->info['AcceptSectionId'],
                    'SectionName'     => $this->info['AcceptSectionName'],
                ];
            }
        }
        $this->info = array_merge($this->info, $result);
    }

    /**
     * 患者信息
     */
    public function patientInfo()
    {
        $this->info = array_merge($this->info,
            [
                'PatientName'    => $this->transfer->PatientName,
                'PatientAge'     => $this->transfer->PatientAge ?: null,
                'PatientSex'     => $this->transfer->PatientSex,
                'PatientAddress' => $this->transfer->PatientAddress,
                'PatientId'      => $this->transfer->PatientId,
                'PatientTel'     => $this->transfer->PatientTel,
                'Disease'        => $this->transfer->Disease,
            ]
        );
    }

    /**
     * 病例图片
     */
    public function casePictures()
    {
        $cases = TransferPicture::find([
            'columns'    => 'Image',
            'conditions' => 'TransferId=?0 and Type=?1',
            'bind'       => [$this->transfer->Id, TransferPicture::TYPE_CASE],
        ])->toArray();
        $this->info = array_merge($this->info, [
            'CasePicture' => array_column($cases, 'Image'),
        ]);
    }

    /**
     * 流转信息
     */
    public function flows()
    {
        $flows = TransferFlow::find([
            'conditions' => 'TransferId=?0',
            'bind'       => [$this->transfer->Id],
        ]);
        $result['Flows'] = [];
        $count = count($flows->toArray());
        if ($count) {
            $this->isFlowed = true;
            $transferComputing = new TransferComputing();
            //医生
            $doctors = OrganizationUser::query()
                ->columns(['UserId', 'Image', 'Title'])
                ->inWhere('UserId', array_column($flows->toArray(), 'DoctorId'))
                ->andWhere(sprintf('OrganizationId=%d', $this->transfer->AcceptOrganizationId))
                ->execute();
            $doctor_tmp = [];
            if (count($doctors->toArray())) {
                foreach ($doctors as $doctor) {
                    $doctor_tmp[$doctor->UserId] = [
                        'Image'     => $doctor->Image ?: OrganizationUser::DEFAULT_IMAGE,
                        'TitleName' => $doctor->Title ? DoctorTitle::value($doctor->Title) : '',
                    ];
                }
            }
            foreach ($flows as $k => $flow) {
                $genreTwo = $this->transfer->Genre == Transfer::GENRE_SELF ? Transfer::FIXED : $this->transfer->GenreTwo;
                $shareTwo = $this->transfer->Genre == Transfer::GENRE_SELF ? 0 : $this->transfer->ShareTwo;
                $shareTwoNum = $transferComputing->amount($flow->Cost, $shareTwo, $genreTwo == Transfer::FIXED);
                $shareOneNum = $transferComputing->amount($flow->Cost, $flow->ShareOne, $flow->GenreOne == Transfer::FIXED);
                $shareCloudNum = $transferComputing->amount($flow->Cost, $flow->ShareCloud, $flow->CloudGenre == Transfer::FIXED);
                $result['Flows'][] = [
                    'Id'                        => $flow->Id,
                    'HospitalId'                => $this->hospital->Id,
                    'SectionId'                 => $flow->SectionId,
                    'SectionName'               => $flow->SectionName,
                    'DoctorId'                  => $flow->DoctorId,
                    'DoctorName'                => $flow->DoctorName,
                    'DoctorImage'               => isset($doctor_tmp[$flow->DoctorId]) ? $doctor_tmp[$flow->DoctorId]['Image'] : OrganizationUser::DEFAULT_IMAGE,
                    'DoctorTitleName'           => isset($doctor_tmp[$flow->DoctorId]) ? $doctor_tmp[$flow->DoctorId]['TitleName'] : '',
                    'Created'                   => $flow->Created,
                    'Cost'                      => $flow->Cost,
                    'SendOrganizationName'      => $this->sendOrganization->Name,
                    'SendHospitalName'          => $this->sendHospital ? $this->sendHospital->Name : '',
                    'CloudGenre'                => $flow->CloudGenre,
                    'ShareCloud'                => $flow->ShareCloud,
                    'GenreOne'                  => $flow->GenreOne,
                    'ShareOne'                  => $flow->ShareOne,
                    'GenreTwo'                  => $genreTwo,
                    'ShareTwo'                  => $shareTwo,
                    'ShareCloudNum'             => $shareCloudNum,
                    'ShareOneNum'               => $shareOneNum,
                    'ShareTwoNum'               => $shareTwoNum,
                    'OutpatientOrInpatient'     => $flow->OutpatientOrInpatient,
                    'OutpatientOrInpatientName' => Transfer::OutpatientOrInpatient_Name[$flow->OutpatientOrInpatient],
                    'ClinicRemark'              => $flow->ClinicRemark,
                    'FinishRemark'              => $flow->FinishRemark,
                    'DiagnosisExplain'          => $flow->DiagnosisExplain,
                    'DiagnosisExplainImages'    => $flow->DiagnosisExplainImages ?: '',
                    'FeeExplain'                => $flow->FeeExplain,
                    'FeeExplainImages'          => $flow->FeeExplainImages ?: '',
                    'ReportExplain'             => $flow->ReportExplain,
                    'ReportExplainImages'       => $flow->ReportExplainImages ?: '',
                    'TherapiesExplain'          => $flow->TherapiesExplain,
                    'TherapiesExplainImages'    => $flow->TherapiesExplainImages ?: '',
                    'CanModify'                 => $flow->CanModify,
                    'IsLeave'                   => $k + 1 == $count && $this->transfer->Status >= Transfer::LEAVE ? true : false,
                ];
                if (!$this->isHospital) $result['ShareOneNum'] += $shareOneNum;
            }
            if (!$this->isHospital) $result['Cost'] = $this->transfer->Cost;
        }
        $this->info = array_merge($this->info, $result);
    }

    /**
     * 流转图片
     */
    public function flowPictures(TransferFlow $flow)
    {
        $columns = [
            TransferPicture::TYPE_FEE       => 'FeeExplain',
            TransferPicture::TYPE_THERAPIES => 'TherapiesExplain',
            TransferPicture::TYPE_REPORT    => 'ReportExplain',
            TransferPicture::TYPE_DIAGNOSIS => 'DiagnosisExplain',
        ];
        $result = [];
        foreach ($columns as $column) {
            $result[$column] = [
                $column           => $flow->{$column},
                $column . 'Image' => json_decode($flow->{$column . 'Images'}, true) ?: [],
            ];
        }
        return $result;
    }

    /**
     * 评论
     */
    public function evaluate()
    {
        $evaluate = Evaluate::find([
            'conditions' => 'TransferId=?0 and IsDeleted=?1',
            'bind'       => [$this->transfer->Id, Evaluate::IsDeleted_No],
        ])->toArray();
        if (count($evaluate)) {
            foreach ($evaluate as &$item) {
                $item['CreateTime'] = $item['CreateTime'] ? date('Y-m-d H:i:s', $item['CreateTime']) : null;
                $item['AnswerTime'] = $item['AnswerTime'] ? date('Y-m-d H:i:s', $item['AnswerTime']) : null;
            }
        }
        $this->info = array_merge(
            $this->info,
            ['Evaluate' => $evaluate,]
        );
    }

    /**
     * 日志
     */
    public function logs()
    {
        $logs = TransferLog::find([
            'conditions' => 'TransferId=?0',
            'bind'       => [$this->transfer->Id],
            'order'      => 'LogTime desc',
        ])->toArray();
        if ($logs) {
            $isFlow = false;
            foreach ($logs as &$log) {
                $log['Info'] = Transfer::STATUS_NAME[$log['Status']];
                $log['LogTime'] = date('Y-m-d H:i:s', $log['LogTime']);
                $log['Sort'] = $log['LogTime'];
                if ($log['Status'] == Transfer::TREATMENT) {
                    if ($isFlow) {
                        $log['Info'] = '病人院内流转';
                    }
                    $isFlow = true;
                }
            }
        }
        $this->info = array_merge(
            $this->info,
            ['Logs' => $logs,]
        );
    }

    /**
     * 时间
     * 在$this->logs之后调用
     */
    public function timeInfo()
    {
        $acceptTime = 0;
        $refuseTime = 0;
        if (isset($this->info['Logs'])) {
            foreach ($this->info['Logs'] as $log) {
                if ($log['Status'] == Transfer::ACCEPT) {
                    $acceptTime = $log['LogTime'];
                } elseif ($log['Status'] == Transfer::REFUSE) {
                    $refuseTime = $log['LogTime'];
                }
            }
        }
        $this->info = array_merge($this->info,
            [
                'StartTime'  => $this->transfer->StartTime,
                'AcceptTime' => $acceptTime,
                'RefuseTime' => $refuseTime,
                'ClinicTime' => $this->transfer->ClinicTime,
                'LeaveTime'  => $this->transfer->LeaveTime,
                'EndTime'    => $this->transfer->EndTime,
            ]
        );
    }

    /**
     * 财务审核未通过情况
     */
    public function interiorTradeUnPass()
    {

        $refusal = FactoryDefault::getDefault()->get('modelsManager')->createBuilder()
            ->columns(['IL.UserName', 'IL.LogTime', 'I.Explain'])
            ->addFrom(\App\Models\InteriorTradeAndTransfer::class, 'T')
            ->join(\App\Models\InteriorTrade::class, 'I.Id=T.InteriorTradeId', 'I', 'left')
            ->join(\App\Models\InteriorTradeLog::class, 'IL.InteriorTradeId=I.Id', 'IL', 'left')
            ->where('T.TransferId=:TransferId:', ['TransferId' => $this->transfer->Id])
            ->andWhere('I.Status=' . \App\Models\InteriorTrade::STATUS_UNPASS)
            ->andWhere('IL.Status=' . \App\Models\InteriorTrade::STATUS_UNPASS)
            ->getQuery()->execute();
        if (count($refusal)) {
            $logs = $this->info['Logs'];
            foreach ($refusal as $item) {
                $logs[] = [
                    'UserName' => $item->UserName,
                    'Info'     => '审核不通过，原因：' . $item->Explain,
                    'LogTime'  => date('Y-m-d H:i:s', $item->LogTime),
                    'Sort'     => $item->LogTime,
                ];
            }
            $sort = array_column($logs, 'Sort');
            array_multisort($sort, SORT_DESC, $logs);
            $this->info['Logs'] = $logs;
        }

    }

    /**
     * 业务经理奖励
     */
    public function salesmanBonus(OrganizationRelationship $organizationRelationship)
    {
        $result = ['SalesmanIsFixed' => 0, 'SalesmanValue' => 0, 'SalesmanBonus' => 0];
        if ($this->transfer->Status >= Transfer::LEAVE && $this->transfer->Genre === Transfer::GENRE_SELF) {
            /** @var SalesmanBonus $salesmanBonus */
            $salesmanBonus = SalesmanBonus::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1 and ReferenceType=?2 and ReferenceId=?3',
                'bind'       => [$this->transfer->AcceptOrganizationId, $organizationRelationship->SalesmanId, SalesmanBonus::ReferenceType_Transfer, $this->transfer->Id],
            ]);
            if ($salesmanBonus) {
                $result = [
                    'SalesmanIsFixed' => $salesmanBonus->IsFixed,
                    'SalesmanValue'   => $salesmanBonus->IsFixed == SalesmanBonus::IsFixed_Yes ? $salesmanBonus->Value : $salesmanBonus->Value / 100,
                    'SalesmanBonus'   => $salesmanBonus->Bonus,
                ];
            }
        }
        return $result;
    }

    /**
     * 数据权限
     */
    public function appOps()
    {
        $access = AppOpsControl::transferRead();
        $this->info = array_merge($this->info,
            [
                'Access' => $access['Access'] && $access['MoneyShow'],
            ]
        );
    }
}