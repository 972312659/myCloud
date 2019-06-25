<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/10/12
 * Time: 上午10:31
 */

namespace App\Admin\Controllers;


use App\Libs\ReportDate;
use App\Models\HospitalReport;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\SlaveReport;
use App\Models\Transfer;

class ReportController extends Controller
{
    /**
     * 用户统计
     */
    public function userAction()
    {
        $allOrganization = Organization::find(["Id!=?0", 'bind' => [Organization::PEACH]]);
        $result['HospitalTotal'] = 0;
        $result['SlaveTotal'] = 0;
        $result['Hospital'] = [];
        $result['Slave'] = [];
        $result['Doctor'] = [];
        if ($allOrganization) {
            foreach ($allOrganization as $organization) {
                $time = date('Ymd', $organization->CreateTime);
                $result[$organization->IsMain === 1 ? 'Hospital' : 'Slave'][$time] += 1;
                $organization->IsMain === 1 ? $result['HospitalTotal'] += 1 : $result['SlaveTotal'] += 1;
            }
        }
        $users = OrganizationUser::find(["IsDoctor=?0", 'bind' => [1]]);
        if ($users) {
            foreach ($users as $user) {
                $time = date('Ymd', $user->CreateTime);
                $result['Doctor'][$time] += 1;
            }
        }
        $result['DoctorTotal'] = count($users->toArray());
        //找到所有日期
        $hospital = count($result['Hospital']) ? array_keys($result['Hospital']) : ['20171001'];
        $slave = count($result['Slave']) ? array_keys($result['Slave']) : ['20171001'];
        $doctor = count($result['Doctor']) ? array_keys($result['Doctor']) : ['20171001'];
        $array = [reset($hospital), end($hospital), reset($slave), end($slave), reset($doctor), end($doctor)];
        $startdate = mb_substr($array[array_search(min($array), $array)], 0, 4) . mb_substr($array[array_search(min($array), $array)], 4, 2) . mb_substr($array[array_search(min($array), $array)], 6, 2);
        $enddate = mb_substr($array[array_search(max($array), $array)], 0, 4) . mb_substr($array[array_search(max($array), $array)], 4, 2) . mb_substr($array[array_search(max($array), $array)], 6, 2);
        $dates = ReportDate::getDateFromRange((int)$startdate, (int)$enddate);
        //循环补足
        foreach ($dates as $date) {
            $result['Hospital'][$date] = $result['Hospital'][$date] ? $result['Hospital'][$date] : 0;
            $result['Slave'][$date] = $result['Slave'][$date] ? $result['Slave'][$date] : 0;
            $result['Doctor'][$date] = $result['Doctor'][$date] ? $result['Doctor'][$date] : 0;
        }
        $this->response->setJsonContent($result);
    }

    /**
     * 转诊单统计
     */
    public function transferAction()
    {
        $transfer = Transfer::find();
        $result['TransferTotal'] = count($transfer->toArray());
        $result['Transfer'] = [];
        if ($transfer) {
            foreach ($transfer as $item) {
                $time = date('Ymd', $item->StartTime);
                $result['Transfer'][$time] += 1;
            }
        }
        $array = $result['Transfer'] ? array_keys($result['Transfer']) : ['20171001'];
        $startdate = mb_substr($array[array_search(min($array), $array)], 0, 4) . mb_substr($array[array_search(min($array), $array)], 4, 2) . mb_substr($array[array_search(min($array), $array)], 6, 2);
        $enddate = mb_substr($array[array_search(max($array), $array)], 0, 4) . mb_substr($array[array_search(max($array), $array)], 4, 2) . mb_substr($array[array_search(max($array), $array)], 6, 2);
        $dates = ReportDate::getDateFromRange((int)$startdate, (int)$enddate);
        foreach ($dates as $date) {
            $result['Transfer'][$date] = $result['Transfer'][$date] ? $result['Transfer'][$date] : 0;
        }
        $this->response->setJsonContent($result);
    }


    /**
     * 医院统计列表
     */
    public function hospitalListAction()
    {
        $data = $this->request->getPost();
        $modelsManager = $this->modelsManager->createBuilder()
            ->columns('H.OrganizationId as HospitalId,O.Name as HospitalName,H.TransferMonth,H.CostMonth')
            ->addFrom(HospitalReport::class, 'H')
            ->join(Organization::class, 'O.Id=H.OrganizationId', 'O', 'left');
        //以时间来进行搜索 ex: Month='20170801'
        if (!empty($data['Month']) && isset($data['Month'])) {
            //当月第一天
            $firstDay = date('Ym01', strtotime(date("Y-m-d")));
            if ($firstDay < $data['Month']) {
                return [];
            }
            if ($firstDay == $data['Month']) {
                $date = date("Ymd", strtotime("-1 day"));
            } else {
                //日期格式 example:20170801 当月最后一天
                $date = date('Ymd', strtotime("{$data['Month']} +1 month -1 day"));
            }
        } else {
            //未传Month则默认为当前月
            $date = date('Ymd', strtotime("-1 day"));
        }
        $modelsManager->andWhere('H.Date=:Date:', ['Date' => $date]);
        if (!empty($data['Type']) && isset($data['Type'])) {
            switch ($data['Type']) {
                case 'Transfer'://当月转诊排序
                    $modelsManager->orderBy('TransferMonth desc');
                    break;
                case 'Cost'://当月消费排序
                    $modelsManager->orderBy('CostMonth desc');
                    break;
            }
        }
        $hospital = $modelsManager->getQuery()->execute();
        $this->response->setJsonContent($hospital);
    }

    /**
     * 医院曲线图
     */
    public function hospitalReadAction()
    {
        $data = $this->request->getPost();
        $modelsManager = HospitalReport::query()
            ->columns('OrganizationId,Date,TransferMonth,CostMonth');
        //当前月 201708
        $year = (int)date('Y');
        $month = (int)date('m');
        if (empty($data['Month']) || !isset($data['Month'])) {
            $data['Month'] = date('Ym01');
        }
        $diff = ($year - (int)mb_substr($data['Month'], 0, 4)) * 12 + $month - (int)mb_substr($data['Month'], 4, 2);
        $date = [];
        if ($diff <= 3) {
            $month = date('Ym01', strtotime('-1 month'));
            for ($i = 11; $i >= 0; $i--) {
                $time = date('Ym01', strtotime("{$month} -{$i} month"));
                $date[] = date('Ymd', strtotime("{$time} +1 month -1 day"));
            }
            $date[] = date('Ymd', strtotime('-1 day'));
        } else {
            $month = $data['Month'] . '01';
            for ($i = 7; $i >= 0; $i--) {
                $time = date('Ym01', strtotime("{$month} -{$i} month"));
                $date[] = date('Ymd', strtotime("{$time} +1 month -1 day"));
            }
            for ($i = 1; $i <= 4; $i++) {
                $time = date('Ym01', strtotime("{$month} +{$i} month"));
                $date[] = date('Ymd', strtotime("{$time} +1 month -1 day"));
            }
        }
        //指定医院
        if (!empty($data['HospitalId']) && isset($data['HospitalId']) && is_numeric($data['HospitalId'])) {
            $modelsManager->andWhere('OrganizationId=:OrganizationId:');
            $modelsManager->bind(['OrganizationId' => $data['HospitalId']]);
        }
        $modelsManager->inWhere('Date', $date);
        $hospital = $modelsManager->execute();
        $this->response->setJsonContent($hospital);
    }

    /**
     * 网点统计列表
     */
    public function slaveListAction()
    {
        $data = $this->request->getPost();
        $modelsManager = $this->modelsManager->createBuilder()
            ->columns('H.OrganizationId as SlaveId,O.Name as SlaveName,H.TransferMonth,H.ShareMonth')
            ->addFrom(SlaveReport::class, 'H')
            ->join(Organization::class, 'O.Id=H.OrganizationId', 'O', 'left');
        //以时间来进行搜索 ex: Month='20170801'
        if (!empty($data['Month']) && isset($data['Month'])) {
            //当月第一天
            $firstDay = date('Ym01', strtotime(date("Y-m-d")));
            if ($firstDay < $data['Month']) {
                return [];
            }
            if ($firstDay == $data['Month']) {
                $date = date("Ymd", strtotime("-1 day"));
            } else {
                //日期格式 example:20170801 当月最后一天
                $date = date('Ymd', strtotime("{$data['Month']} +1 month -1 day"));
            }
        } else {
            //未传Month则默认为当前月
            $date = date('Ymd', strtotime("-1 day"));
        }
        $modelsManager->andWhere('H.Date=:Date:', ['Date' => $date]);
        if (!empty($data['Type']) && isset($data['Type'])) {
            switch ($data['Type']) {
                case 'Transfer'://当月转诊排序
                    $modelsManager->orderBy('TransferMonth desc');
                    break;
                case 'Share'://当月分润排序
                    $modelsManager->orderBy('ShareMonth desc');
                    break;
            }
        }
        $hospital = $modelsManager->getQuery()->execute();
        $this->response->setJsonContent($hospital);
    }

    /**
     * 网点曲线图
     */
    public function slaveReadAction()
    {
        $data = $this->request->getPost();
        $modelsManager = SlaveReport::query()
            ->columns('OrganizationId,Date,TransferMonth,ShareMonth');
        //当前月 201708
        $year = (int)date('Y');
        $month = (int)date('m');
        if (empty($data['Month']) || !isset($data['Month'])) {
            $data['Month'] = date('Ym01');
        }
        $diff = ($year - (int)mb_substr($data['Month'], 0, 4)) * 12 + $month - (int)mb_substr($data['Month'], 4, 2);
        $date = [];
        if ($diff <= 3) {
            $month = date('Ym01', strtotime('-1 month'));
            for ($i = 11; $i >= 0; $i--) {
                $time = date('Ym01', strtotime("{$month} -{$i} month"));
                $date[] = date('Ymd', strtotime("{$time} +1 month -1 day"));
            }
            $date[] = date('Ymd', strtotime('-1 day'));
        } else {
            $month = $data['Month'] . '01';
            for ($i = 7; $i >= 0; $i--) {
                $time = date('Ym01', strtotime("{$month} -{$i} month"));
                $date[] = date('Ymd', strtotime("{$time} +1 month -1 day"));
            }
            for ($i = 1; $i <= 4; $i++) {
                $time = date('Ym01', strtotime("{$month} +{$i} month"));
                $date[] = date('Ymd', strtotime("{$time} +1 month -1 day"));
            }
        }
        //指定医院
        if (!empty($data['SlaveId']) && isset($data['SlaveId']) && is_numeric($data['SlaveId'])) {
            $modelsManager->andWhere('OrganizationId=:OrganizationId:');
            $modelsManager->bind(['OrganizationId' => $data['SlaveId']]);
        }
        $modelsManager->inWhere('Date', $date);
        $hospital = $modelsManager->execute();
        $this->response->setJsonContent($hospital);
    }
}