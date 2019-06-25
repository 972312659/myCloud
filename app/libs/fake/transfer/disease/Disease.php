<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/8/28
 * Time: 上午10:05
 */

namespace App\Libs\fake\transfer\disease;

use App\Models\Organization;
use App\Models\OrganizationAndSection;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Section;
use App\Models\User;
use Phalcon\Di;
use Phalcon\Mvc\Model\Manager;

class Disease
{
    public $sickness;

    private $permissionId = 39;

    private $bedFee = '25-55';//床位费25-55/天

    private $treatFee = '40-80';//治疗费40-80/天

    private $nurseFee = [5.6, 8];//初级护理费5.6/天、高级护理费8/天

    private $inspectionFee = '200-400';//检验费200-400

    private $checkFee = '200-400';//检查费200-400

    private $materialsFee = '20-60';//材料费20-60/天

    private $oxygenFee = '20-40';//氧气费20-40/天

    private $careFee = 124.8;//监护费124.8/天

    private $SBMG = 7.2;//血糖监测7.2/次

    private $registerFee = 10;//挂号费

    private $file = APP_PATH . '/libs/fake/transfer/disease/sickness.csv';

    private $useful_file = APP_PATH . '/libs/fake/transfer/disease/sickness.php';

    //病例
    public $sickReport;

    //医院
    private $hospitals;

    //网点
    private $slaves;

    //医院ids
    private $hospitalIds = [];

    //科室ids
    private $sectionIds = [];

    //科室对应的医院ids
    private $sectionHasHospitals = [];

    //各医院不确定费用
    public $fee = [
        'bedFee'        => 0,
        'treatFee'      => 0,
        'nurseFee'      => 0,
        'inspectionFee' => 0,
        'checkFee'      => 0,
        'materialsFee'  => 0,
        'oxygenFee'     => 0,
    ];

    /**
     * @var Manager
     */
    protected $modelsManager;

    public function __construct()
    {
        $this->modelsManager = Di::getDefault()->get('modelsManager');

        $this->sicknesses = include_once $this->useful_file;
        $this->getSectionIds();
        $this->getHospitals();
    }

    public function rand(int $sex, int $age, \DateTime $end_time)
    {
        // $file = file_exists($this->useful_file);
        // if (!$file) {
        //     $this->handle();
        // }
        $transfer = new Transfer();
        $sickness = $this->randSickness($age, $sex);
        if (!$sickness['needDay']) {
            return null;
        }
        $transfer->Day = $sickness['needDay'];
        //end time
        $end_time->setTime(rand(16, 18), rand(0, 59), rand(0, 59));
        $transfer->EndTime = $end_time->getTimestamp();

        //leave time
        $leave_time = $end_time->sub(new \DateInterval('P' . $this->randTime()));
        $transfer->LeaveTime = $leave_time->getTimestamp();

        //clinic time
        $clinic_time = $end_time->sub(new \DateInterval('P' . $transfer->Day . 'D' . $this->randTime()));
        $transfer->ClinicTime = $clinic_time->getTimestamp();

        //start time
        $day = rand(1, 2);
        $start_time = $clinic_time->sub(new \DateInterval('P' . $day . 'D' . $this->randTime()));
        $transfer->StartTime = $start_time->getTimestamp();

        //accept time
        $transfer->AcceptTime = $transfer->StartTime + rand(1, 3) * rand(2500, 3600);

        $one = $this->getOne($sickness['sectionName'], $transfer->StartTime);
        if (empty($one['HospitalId']) || empty($one['Slave'])) {
            return null;
        }
        $this->setFee($one['HospitalId']);
        $hospital = $this->hospitals[$one['HospitalId']];
        $slave = $one['Slave'];
        $sectionId = $this->sectionIds[$sickness['sectionName']];
        $doctors = is_array($this->hospitals[$one['HospitalId']]['Doctor']) && isset($this->hospitals[$one['HospitalId']]['Doctor'][$sectionId]) ? $this->hospitals[$one['HospitalId']]['Doctor'][$sectionId] : [];
        if (empty($doctors)) {
            $doctor = ['Id' => $hospital['Staff']['Id'], 'Name' => $hospital['Staff']['Name']];
        } else {
            $doctor = $doctors[array_rand($doctors)];
        }

        $result = [];
        $result['total'] = 0;
        foreach ($sickness['fee'] as $k => $v) {
            if (!$v['exist']) {
                continue;
            }
            $key = $v['alias'];
            if ($k == '检查费') {
                $result['fee'][$k] = (isset($this->fee[$key]) && $this->fee[$key] ? $this->fee[$key] : ($v['fixed'] != 0 ? $v['fixed'][array_rand($v['fixed'])] : mt_rand($v['min'], $v['max']))) * 100;
                $result['total'] += $result['fee'][$k];
                continue;
            }
            if ($k == '治疗费') {
                $result['fee'][$k] = (isset($this->fee[$key]) && $this->fee[$key] ? $this->fee[$key] : $v['fixed'][array_rand($v['fixed'])]) * $transfer->Day * 100;
                $result['total'] += $result['fee'][$k];
                continue;
            }
            if ($k == '药品费') {
                $result['fee'][$k] = ($v['fixed'] * $transfer->Day + mt_rand($v['min'], $v['max'])) * 100 + mt_rand(0, 99);
                $result['total'] += $result['fee'][$k];
                continue;
            }
            $result['fee'][$k] = isset($this->fee[$key]) && $this->fee[$key] ? $this->fee[$key] : ($v['fixed'] ?: mt_rand($v['min'], $v['max']));
            $result['fee'][$k] = ($v['everyDay'] ? $result['fee'][$k] * $transfer->Day : $result['fee'][$k]) * 100;
            $result['total'] += $result['fee'][$k];
        }

        $transfer->PatientAge = $age;
        $transfer->PatientSex = $sex;
        $transfer->SendHospitalId = $hospital['Id'];
        $transfer->SendOrganizationId = $slave['SlaveId'];
        $transfer->SendOrganizationName = $slave['SlaveName'];
        $transfer->OldSectionName = $sickness['sectionName'];
        $transfer->OldDoctorName = $doctor['Name'];
        $transfer->AcceptOrganizationId = $hospital['Id'];
        $transfer->AcceptOrganizationName = $hospital['Name'];
        $transfer->AcceptSectionId = $sectionId;
        $transfer->AcceptDoctorId = $doctor['Id'];
        $transfer->AcceptSectionName = $sickness['sectionName'];
        $transfer->AcceptDoctorName = $doctor['Name'];
        $transfer->Disease = $sickness['sicknessDescribe'];
        $transfer->InHospital = $sickness['inHospital'];
        $transfer->Cost = $result['total'];
        $transfer->StaffId = $hospital['Staff']['Id'];
        $transfer->StaffName = $hospital['Staff']['Name'];
        $transfer->CashierId = $hospital['Cashier']['Id'];
        $transfer->CashierName = $hospital['Cashier']['Name'];
        $transfer->Fee = $result['fee'];

        return $transfer;
    }

    public function handle()
    {
        $file = fopen($this->file, 'r');
        $excel_arrs = [];
        while ($data = fgetcsv($file)) {
            $excel_arrs[] = $data;
        }
        // var_dump($excel_arrs);
        // die;
        fclose($file);
        $arr = [];
        $label_name = [];
        $this->bedFee = explode('-', $this->bedFee);
        $this->treatFee = explode('-', $this->treatFee);
        $this->inspectionFee = explode('-', $this->inspectionFee);
        $this->checkFee = explode('-', $this->checkFee);
        $this->materialsFee = explode('-', $this->materialsFee);
        $this->oxygenFee = explode('-', $this->oxygenFee);
        foreach ($excel_arrs as $k => $excel_arr) {
            if ($k == 0) {
                $label_name = $excel_arr;
            } else {
                //屏蔽门诊
                if ($excel_arr[4] == 0) {
                    continue;
                }
                $tempAge = explode('-', $excel_arr[2]);
                $jianChaFee = explode('-', $excel_arr[8]);
                $jianyanFee = explode('-', $excel_arr[9]);
                $yaopinFee = explode('-', $excel_arr[17]);
                $arr[] = [
                    'sectionName'      => $excel_arr[0],
                    'sicknessDescribe' => $excel_arr[3],
                    'sex'              => $excel_arr[1],
                    'ageMin'           => (int)$tempAge[0],
                    'ageMax'           => (int)$tempAge[1],
                    'day'              => $excel_arr[18],
                    'inHospital'       => $excel_arr[4],
                    'fee'              => [
                        //挂号费
                        $label_name[7]  => ['exist' => $excel_arr[7] == 1, 'everyDay' => false, 'fixed' => $this->registerFee, 'min' => 0, 'max' => 0, 'alias' => 'register'],
                        //检查费
                        $label_name[8]  => ['exist' => $excel_arr[8] > 0, 'everyDay' => false, 'fixed' => count($jianChaFee) > 1 ? 0 : explode('/', $jianChaFee[0]), 'min' => (int)$jianChaFee[0], 'max' => (int)$jianChaFee[1], 'alias' => 'checkFee'],
                        //检验费
                        $label_name[9]  => ['exist' => count($jianyanFee) && $jianyanFee[0], 'everyDay' => false, 'fixed' => count($jianyanFee) > 1 ? 0 : (int)$jianyanFee[0], 'min' => (int)$jianyanFee[0], 'max' => (int)$jianyanFee[1], 'alias' => 'inspectionFee'],
                        //材料费
                        $label_name[10] => ['exist' => $excel_arr[10] == 1, 'everyDay' => true, 'fixed' => 0, 'min' => (int)$this->materialsFee[0], 'max' => (int)$this->materialsFee[1], 'alias' => 'cailiaoFee'],
                        //床位费
                        $label_name[11] => ['exist' => $excel_arr[11] == 1, 'everyDay' => true, 'fixed' => 0, 'min' => (int)$this->bedFee[0], 'max' => (int)$this->bedFee[1], 'alias' => 'bedFee'],
                        //治疗费
                        $label_name[12] => ['exist' => $excel_arr[12] > 0, 'everyDay' => true, 'fixed' => explode('/', $excel_arr[12]), 'min' => 0, 'max' => 0, 'alias' => 'treatFee'],
                        //护理费
                        $label_name[13] => ['exist' => $excel_arr[13] == 1, 'everyDay' => true, 'fixed' => 0, 'min' => (int)$this->nurseFee[0], 'max' => (int)$this->nurseFee[1], 'alias' => 'nurseFee'],
                        //氧气费
                        $label_name[14] => ['exist' => $excel_arr[14] == 1, 'everyDay' => true, 'fixed' => 0, 'min' => (int)$this->oxygenFee[0], 'max' => (int)$this->oxygenFee[1], 'alias' => 'oxygenFee'],
                        //监护费
                        $label_name[15] => ['exist' => $excel_arr[15] == 1, 'everyDay' => true, 'fixed' => $this->careFee, 'min' => 0, 'max' => 0, 'alias' => 'careFee'],
                        //血糖监测
                        // $label_name[16] => ['exist' => $excel_arr[16] == 1, 'everyDay' => true, 'fixed' => $this->SBMG, 'min' => 0, 'max' => 0, 'alias' => 'SBMG'],
                        //药品费
                        $label_name[17] => ['exist' => $excel_arr[17] != 0, 'everyDay' => false, 'fixed' => (int)$yaopinFee[0], 'min' => 0, 'max' => (int)$yaopinFee[1], 'alias' => 'drugFee'],
                    ],

                ];
            }
        }
        $str = "<?php\r\nreturn " . var_export($arr, true) . "\r\n?>";
        file_put_contents($this->useful_file, $str);
    }

    public function getSectionIds()
    {
        $sectionNames = ['内科', '外科', '骨科', '妇科', '康复科'];
        foreach ($sectionNames as $sectionName) {
            $section = Section::findFirst(['conditions' => "Name like '%{$sectionName}%'"]);
            $this->sectionIds["$section->Name"] = $section->Id;
        }
    }

    public function getHospitals()
    {
        $organizationSections = OrganizationAndSection::query()
            ->columns(['OrganizationId', 'SectionId'])
            ->inWhere('SectionId', $this->sectionIds)
            ->execute()
            ->toArray();
        $organizations = $this->modelsManager->createBuilder()
            ->columns(['R.MainId', 'R.MinorId', 'O.Name as SlaveName', 'U.Id as UserId', 'U.Name as UserName', 'O.CreateTime'])
            ->addFrom(OrganizationRelationship::class, 'R')
            ->leftJoin(Organization::class, 'O.Id=R.MinorId', 'O')
            ->leftJoin(OrganizationUser::class, 'OU.OrganizationId=O.Id', 'OU')
            ->leftJoin(User::class, 'U.Id=OU.UserId', 'U')
            ->inWhere('R.MainId', array_column($organizationSections, 'OrganizationId'))
            ->andWhere('O.Fake=1')
            ->getQuery()
            ->execute()
            ->toArray();
        foreach ($organizations as $organization) {
            $this->slaves[$organization['MainId']][] = ['SlaveId' => $organization['MinorId'], 'SlaveName' => $organization['SlaveName'], 'UserId' => $organization['UserId'], 'UserName' => $organization['UserName'], 'CreateTime' => $organization['CreateTime']];
        }
        $this->hospitalIds = array_unique(array_column($organizations, 'MainId'));
        foreach ($organizationSections as $section) {
            if (is_array($this->hospitalIds) && in_array($section['OrganizationId'], $this->hospitalIds) && !in_array($section['OrganizationId'], is_array(isset($this->sectionHasHospitals[$section['SectionId']])) ? $this->sectionHasHospitals[$section['SectionId']] : [])) {
                $this->sectionHasHospitals[$section['SectionId']][] = $section['OrganizationId'];
            }
        }

        $hospitals = $this->modelsManager->createBuilder()
            ->columns(['O.Id as HospitalId', 'O.Name as HospitalName', 'O.CreateTime', 'OU.UserId', 'OU.IsDoctor', 'OU.Role', 'OU.SectionId', 'U.Name as UserName'])
            ->addFrom(Organization::class, 'O')
            ->leftJoin(OrganizationUser::class, 'OU.OrganizationId=O.Id', 'OU')
            ->leftJoin(User::class, 'U.Id=OU.UserId', 'U')
            ->inWhere('O.Id', $this->hospitalIds)
            ->getQuery()
            ->execute()
            ->toArray();
        $roles = Role::query()
            ->columns('Id')
            ->leftJoin(RolePermission::class, 'P.RoleId=Id', 'P')
            ->where("P.PermissionId=$this->permissionId")
            ->execute()
            ->toArray();
        $roleIds = array_column($roles, 'Id');
        foreach ($hospitals as $hospital) {
            $this->hospitals[$hospital['HospitalId']]['Id'] = $hospital['HospitalId'];
            $this->hospitals[$hospital['HospitalId']]['Name'] = $hospital['HospitalName'];
            $this->hospitals[$hospital['HospitalId']]['CreateTime'] = $hospital['CreateTime'];
            if ($hospital['IsDoctor'] == OrganizationUser::IS_DOCTOR_NO) {
                if (in_array($hospital['Role'], $roleIds)) {
                    $this->hospitals[$hospital['HospitalId']]['Cashier'] = ['Id' => $hospital['UserId'], 'Name' => $hospital['UserName']];
                }
                $this->hospitals[$hospital['HospitalId']]['Staff'] = ['Id' => $hospital['UserId'], 'Name' => $hospital['UserName']];
            } else {
                if (in_array($hospital['SectionId'], $this->sectionIds)) {
                    $this->hospitals[$hospital['HospitalId']]['Doctor'][$hospital['SectionId']][] = ['Id' => $hospital['UserId'], 'Name' => $hospital['UserName']];
                }
            }
        }
    }

    public function randSickness(int $age, int $sex)
    {
        $tempArr = [];
        foreach ($this->sicknesses as $sickness) {
            if ($age >= $sickness['ageMin'] && $age <= $sickness['ageMax'] && ($sex == $sickness['sex'] || $sickness['sex'] == 3)) {
                $tempArr[] = $sickness;
            }
        }
        //随机
        $sickness = $tempArr[array_rand($tempArr)];
        $dayArr = explode(',', $sickness['day']);
        $sickness['needDay'] = count($dayArr) == 1 ? $dayArr[0] : mt_rand($dayArr[0], $dayArr[1]);
        return $sickness;
    }

    public function getOne(string $sectionName, $startTime)
    {
        $sectionId = $this->sectionIds[$sectionName];
        $hospitalIds = $this->sectionHasHospitals[$sectionId];
        $tempHospitalIds = [];
        foreach ($this->hospitals as $hospital) {
            if ($hospital['CreateTime'] <= $startTime && in_array($hospital['Id'], $hospitalIds)) {
                $tempHospitalIds[] = $hospital['Id'];
            }
        }
        $hospitalId = $tempHospitalIds[array_rand($tempHospitalIds)];
        $slaves = $this->slaves[$hospitalId];
        $tempSlaves = [];
        foreach ($slaves as $slave) {
            if ($slave['CreateTime'] <= $startTime) {
                $tempSlaves[] = $slave;
            }
        }
        $slave = [];
        if (count($tempSlaves)) {
            $slave = $tempSlaves[array_rand($tempSlaves)];
        }
        return ['HospitalId' => $hospitalId, 'Slave' => $slave];
    }

    public function setFee(int $hospitalId)
    {
        $num = str_split($hospitalId);
        $number = $num[count($num) - 1];
        if (in_array($number, [0, 3, 7])) {
            $this->fee = [
                'bedFee'        => 25,
                'treatFee'      => 40,
                'nurseFee'      => 5.6,
                'inspectionFee' => 200,
                'checkFee'      => 200,
                'materialsFee'  => 20,
                'oxygenFee'     => 20,
            ];
        } elseif (in_array($number, [1, 2, 5])) {
            $this->fee = [
                'bedFee'        => 30,
                'treatFee'      => 50,
                'nurseFee'      => 5.6,
                'inspectionFee' => 200,
                'checkFee'      => 248,
                'materialsFee'  => 35,
                'oxygenFee'     => 30,
            ];
        } elseif (in_array($number, [4, 6])) {
            $this->fee = [
                'bedFee'        => 45,
                'treatFee'      => 75,
                'nurseFee'      => 0,
                'inspectionFee' => 300,
                'checkFee'      => 350,
                'materialsFee'  => 50,
                'oxygenFee'     => 30,
            ];
        } elseif (in_array($number, [8, 9])) {
            $this->fee = [
                'bedFee'        => 50,
                'treatFee'      => 80,
                'nurseFee'      => 0,
                'inspectionFee' => 380,
                'checkFee'      => 400,
                'materialsFee'  => 60,
                'oxygenFee'     => 40,
            ];
        }
    }

    private function randTime()
    {
        return 'T' . rand(1, 3) . 'H' . rand(0, 59) . 'M' . rand(0, 59) . 'S';
    }
}