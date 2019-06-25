<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/10/13
 * Time: 下午1:39
 */

use Phalcon\Cli\Task;
use App\Models\Organization;
use App\Models\TransferLog;
use App\Models\Transfer;
use App\Models\Bill;
use App\Models\HospitalReport;
use App\Models\SlaveReport;
use App\Models\ZealHospitalReport;
use App\Models\ZealSlaveReport;
use App\Models\OrganizationRelationship;

class ReportTask extends Task
{
    /**
     * 将昨天的数据写入HospitalReport 医院转诊统计报表
     * @param array $params [0] 开始日期 '2017-09-30'
     * @param array $params [1] 结束日期 '2017-10-10'
     */
    public function hospitalAction(array $params)
    {
        $date = (int)date('Ymd', time() - 86400);
        $dates = [$date];
        if ($params) {
            $dates = self::getDateFromRange($params[0], $params[1]);
        }
        foreach ($dates as $date) {
            $date = (int)$date;
            $start = strtotime($date);
            $end = strtotime($date) + 86400;
            $organizations = Transfer::query()
                ->columns(['AcceptOrganizationId as Id', 'min(StartTime) as StartTime'])
                ->groupBy('AcceptOrganizationId')
                ->where('StartTime<=:StartTime:')
                ->bind(['StartTime' => $start])
                ->execute();
            $transferLog = $this->modelsManager->createBuilder()
                ->columns('OrganizationId as Id,count(*) as TransferCount')
                ->addFrom(TransferLog::class, 'L')
                ->leftJoin(Transfer::class, 'T.Id=L.TransferId', 'T')
                ->where('L.Status=' . Transfer::ACCEPT)
                ->betweenWhere('L.LogTime', $start, $end)
                ->andWhere('T.Sign=0')
                ->groupBy('OrganizationId')
                ->getQuery()
                ->execute();
            $transferLog_new = [];
            if (count($transferLog->toArray())) {
                foreach ($transferLog as $item) {
                    $transferLog_new[$item->Id] = $item->TransferCount;
                }
            }
            $bills = Bill::query()
                ->columns('OrganizationId as Id,sum(Fee) as Cost')
                ->leftJoin(Organization::class, 'O.Id=OrganizationId', 'O')
                ->where('ReferenceType=3')
                ->andWhere('O.IsMain!=2')
                ->betweenWhere('Created', $start, $end)
                ->groupBy('OrganizationId')
                ->execute();
            $transfer_new = [];
            if (count($bills->toArray())) {
                foreach ($bills as $item) {
                    $transfer_new[$item->Id] = abs($item->Cost);
                }
            }
            $old = HospitalReport::find(['Date=?0', 'bind' => [(int)date('Ymd', strtotime($date) - 86400)]]);
            $old_new = [];
            $yestoday = 20170810;
            if (count($old->toArray())) {
                foreach ($old as $item) {
                    $old_new[$item->OrganizationId] = [
                        'OrganizationId' => $item->OrganizationId,
                        'Date'           => $item->Date,
                        'TransferDay'    => $item->TransferDay,
                        'TransferMonth'  => $item->TransferMonth,
                        'TransferYear'   => $item->TransferYear,
                        'CostDay'        => $item->CostDay,
                        'CostMonth'      => $item->CostMonth,
                        'CostYear'       => $item->CostYear,
                    ];
                    $yestoday = $item->Date;
                }
            } else {
                $yestoday = date('Ymd', strtotime($params[0]) - 86400);
            }
            $month = mb_substr($date, 0, 6) === mb_substr($yestoday, 0, 6) ? 1 : 0;
            $year = mb_substr($date, 0, 4) === mb_substr($yestoday, 0, 4) ? 1 : 0;
            if (count($organizations->toArray())) {
                foreach ($organizations as $organization) {
                    $transferLog_new[$organization->Id] = isset($transferLog_new[$organization->Id]) ? $transferLog_new[$organization->Id] : 0;
                    $transfer_new[$organization->Id] = isset($transfer_new[$organization->Id]) ? $transfer_new[$organization->Id] : 0;
                    $old_new[$organization->Id] = isset($old_new[$organization->Id]) ? $old_new[$organization->Id] : ['TransferMonth' => 0, 'TransferYear' => 0, 'CostMonth' => 0, 'CostYear' => 0];
                    $report = new HospitalReport();
                    $report->OrganizationId = $organization->Id;
                    $report->Date = $date;
                    $report->TransferDay = $transferLog_new[$organization->Id];
                    $report->TransferMonth = $month ? $old_new[$organization->Id]['TransferMonth'] + $transferLog_new[$organization->Id] : $report->TransferDay;
                    $report->TransferYear = $year ? $old_new[$organization->Id]['TransferYear'] + $transferLog_new[$organization->Id] : $report->TransferMonth;
                    $report->CostDay = $transfer_new[$organization->Id];
                    $report->CostMonth = $month ? $old_new[$organization->Id]['CostMonth'] + $transfer_new[$organization->Id] : $report->CostDay;
                    $report->CostYear = $year ? $old_new[$organization->Id]['CostYear'] + $transfer_new[$organization->Id] : $report->CostMonth;
                    $report->save();
                }
            }
        }
        echo 'ok' . PHP_EOL;
    }

    /**
     * @param string $startdate 开始日期
     * @param string $enddate   结束日期
     * @return array     包含每天的数组
     */
    public static function getDateFromRange(string $startdate, string $enddate): array
    {
        $stimestamp = strtotime($startdate);
        $etimestamp = strtotime($enddate);

        // 计算日期段内有多少天
        $days = ($etimestamp - $stimestamp) / 86400 + 1;

        // 保存每天日期
        $date = [];

        for ($i = 0; $i < $days; $i++) {
            $date[] = date('Ymd', $stimestamp + (86400 * $i));
        }
        return $date;
    }

    /**
     * 将昨天的数据写入SlaveReport 网点转诊统计报表
     * @param array $params [0] 开始日期 '2017-09-30'
     * @param array $params [1] 结束日期 '2017-10-10'
     */
    public function slaveAction(array $params)
    {
        $date = (int)date('Ymd', time() - 86400);
        $dates = [$date];
        if ($params) {
            $dates = self::getDateFromRange($params[0], $params[1]);
        }
        foreach ($dates as $date) {
            $date = (int)$date;
            $start = strtotime($date);
            $end = strtotime($date) + 86400;
            $organizations = $this->modelsManager->createBuilder()
                ->columns('O.Id as Id,S.MainId as HospitalId')
                ->addFrom(Organization::class, 'O')
                ->join(OrganizationRelationship::class, 'S.MinorId=O.Id', 'S', 'left')
                ->where('O.Id!=:Id:', ['Id' => Organization::PEACH])
                ->andWhere('O.IsMain=:IsMain:', ['IsMain' => 2])
                ->andWhere('O.CreateTime<=:CreateTime:', ['CreateTime' => $start])
                ->andWhere('O.Fake=0')
                ->getQuery()
                ->execute();
            $begin = Transfer::query()
                ->columns(['SendOrganizationId as Id', 'min(StartTime) as StartTime'])
                ->groupBy('SendOrganizationId')
                ->where('StartTime<=:StartTime:')
                ->bind(['StartTime' => $start])
                ->execute()->toArray();

            $transferLog = Transfer::query()
                ->columns('SendHospitalId,SendOrganizationId as Id,count(Id) as TransferCount')
                ->where('Sign=0')
                ->betweenWhere('StartTime', $start, $end)
                ->groupBy('SendOrganizationId,SendHospitalId')
                ->execute();
            $transferLog_new = [];
            if (count($transferLog->toArray())) {
                foreach ($transferLog as $item) {
                    $transferLog_new[$item->Id] = $item->TransferCount;
                }
            }
            $bills = Bill::query()
                ->columns('OrganizationId as Id,sum(Fee) as Share')
                ->leftJoin(Organization::class, 'O.Id=OrganizationId', 'O')
                ->where('ReferenceType=3')
                ->andWhere('O.IsMain=2')
                ->betweenWhere('Created', $start, $end)
                ->groupBy('OrganizationId')
                ->execute();
            $transfer_new = [];
            if (count($bills->toArray())) {
                foreach ($bills as $item) {
                    $transfer_new[$item->Id] = abs($item->Share);
                }
            }
            $old = SlaveReport::find(['Date=?0', 'bind' => [(int)date('Ymd', strtotime($date) - 86400)]]);
            $old_new = [];
            $yestoday = 20170810;
            if (count($old->toArray())) {
                foreach ($old as $item) {
                    $old_new[$item->OrganizationId] = [
                        'OrganizationId' => $item->OrganizationId,
                        'Date'           => $item->Date,
                        'TransferDay'    => $item->TransferDay,
                        'TransferMonth'  => $item->TransferMonth,
                        'TransferYear'   => $item->TransferYear,
                        'ShareDay'       => $item->ShareDay,
                        'ShareMonth'     => $item->ShareMonth,
                        'ShareYear'      => $item->ShareYear,
                    ];
                    $yestoday = $item->Date;
                }
            } else {
                $yestoday = date('Ymd', strtotime($params[0]) - 86400);
            }
            $month = mb_substr($date, 0, 6) === mb_substr($yestoday, 0, 6) ? 1 : 0;
            $year = mb_substr($date, 0, 4) === mb_substr($yestoday, 0, 4) ? 1 : 0;
            if (count($organizations->toArray())) {
                foreach ($organizations as $organization) {
                    if (in_array($organization->Id, array_column($begin, 'Id'))) {
                        $transferLog_new[$organization->Id] = isset($transferLog_new[$organization->Id]) ? $transferLog_new[$organization->Id] : 0;
                        $transfer_new[$organization->Id] = isset($transfer_new[$organization->Id]) ? $transfer_new[$organization->Id] : 0;
                        $old_new[$organization->Id] = isset($old_new[$organization->Id]) ? $old_new[$organization->Id] : ['TransferMonth' => 0, 'TransferYear' => 0, 'ShareMonth' => 0, 'ShareYear' => 0];
                        $report = new SlaveReport();
                        $report->OrganizationId = $organization->Id;
                        $report->HospitalId = $organization->HospitalId;
                        $report->Date = $date;
                        $report->TransferDay = $transferLog_new[$organization->Id];
                        $report->TransferMonth = $month ? $old_new[$organization->Id]['TransferMonth'] + $transferLog_new[$organization->Id] : $report->TransferDay;
                        $report->TransferYear = $year ? $old_new[$organization->Id]['TransferYear'] + $transferLog_new[$organization->Id] : $report->TransferMonth;
                        $report->ShareDay = $transfer_new[$organization->Id];
                        $report->ShareMonth = $month ? $old_new[$organization->Id]['ShareMonth'] + $transfer_new[$organization->Id] : $report->ShareDay;
                        $report->ShareYear = $year ? $old_new[$organization->Id]['ShareYear'] + $transfer_new[$organization->Id] : $report->ShareMonth;
                        $report->save();
                    }
                }
            }
        }
        echo 'ok' . PHP_EOL;
    }

    /**
     * 医院统计
     */
    public function zealHospitalAction(array $params)
    {
        $date = (int)date('Ymd', time() - 86400);
        $dates = [$date];
        if ($params) {
            $dates = self::getDateFromRange($params[0], $params[1]);
        }
        foreach ($dates as $date) {
            $date = (int)$date;
            $start = strtotime($date);
            $end = strtotime($date) + 86400;
            $organizations = Transfer::query()
                ->columns(['AcceptOrganizationId as Id', 'min(StartTime) as StartTime'])
                ->groupBy('AcceptOrganizationId')
                ->where('StartTime<=:StartTime:')
                ->bind(['StartTime' => $start])
                ->execute();
            $transferLog = $this->modelsManager->createBuilder()
                ->columns('OrganizationId as Id,count(*) as TransferCount')
                ->addFrom(TransferLog::class, 'L')
                ->leftJoin(Transfer::class, 'T.Id=L.TransferId', 'T')
                ->where('L.Status=' . Transfer::ACCEPT)
                ->betweenWhere('L.LogTime', $start, $end)
                ->groupBy('OrganizationId')
                ->getQuery()
                ->execute();
            $transferLog_new = [];
            if (count($transferLog->toArray())) {
                foreach ($transferLog as $item) {
                    $transferLog_new[$item->Id] = $item->TransferCount;
                }
            }
            $bills = Bill::query()
                ->columns('OrganizationId as Id,sum(Fee) as Cost')
                ->leftJoin(Organization::class, 'O.Id=OrganizationId', 'O')
                ->where('ReferenceType=3')
                ->andWhere('O.IsMain!=2')
                ->betweenWhere('Created', $start, $end)
                ->groupBy('OrganizationId')
                ->execute();
            $transfer_new = [];
            if (count($bills->toArray())) {
                foreach ($bills as $item) {
                    $transfer_new[$item->Id] = abs($item->Cost);
                }
            }
            $old = HospitalReport::find(['Date=?0', 'bind' => [(int)date('Ymd', strtotime($date) - 86400)]]);
            $old_new = [];
            $yestoday = 20170810;
            if (count($old->toArray())) {
                foreach ($old as $item) {
                    $old_new[$item->OrganizationId] = [
                        'OrganizationId' => $item->OrganizationId,
                        'Date'           => $item->Date,
                        'TransferDay'    => $item->TransferDay,
                        'TransferMonth'  => $item->TransferMonth,
                        'TransferYear'   => $item->TransferYear,
                        'CostDay'        => $item->CostDay,
                        'CostMonth'      => $item->CostMonth,
                        'CostYear'       => $item->CostYear,
                    ];
                    $yestoday = $item->Date;
                }
            } else {
                $yestoday = date('Ymd', strtotime($params[0]) - 86400);
            }
            $month = mb_substr($date, 0, 6) === mb_substr($yestoday, 0, 6) ? 1 : 0;
            $year = mb_substr($date, 0, 4) === mb_substr($yestoday, 0, 4) ? 1 : 0;
            if (count($organizations->toArray())) {
                foreach ($organizations as $organization) {
                    $transferLog_new[$organization->Id] = isset($transferLog_new[$organization->Id]) ? $transferLog_new[$organization->Id] : 0;
                    $transfer_new[$organization->Id] = isset($transfer_new[$organization->Id]) ? $transfer_new[$organization->Id] : 0;
                    $old_new[$organization->Id] = isset($old_new[$organization->Id]) ? $old_new[$organization->Id] : ['TransferMonth' => 0, 'TransferYear' => 0, 'CostMonth' => 0, 'CostYear' => 0];
                    $report = new ZealHospitalReport();
                    $report->OrganizationId = $organization->Id;
                    $report->Date = $date;
                    $report->TransferDay = $transferLog_new[$organization->Id];
                    $report->TransferMonth = $month ? $old_new[$organization->Id]['TransferMonth'] + $transferLog_new[$organization->Id] : $report->TransferDay;
                    $report->TransferYear = $year ? $old_new[$organization->Id]['TransferYear'] + $transferLog_new[$organization->Id] : $report->TransferMonth;
                    $report->CostDay = $transfer_new[$organization->Id];
                    $report->CostMonth = $month ? $old_new[$organization->Id]['CostMonth'] + $transfer_new[$organization->Id] : $report->CostDay;
                    $report->CostYear = $year ? $old_new[$organization->Id]['CostYear'] + $transfer_new[$organization->Id] : $report->CostMonth;
                    $report->save();
                }
            }
        }
        echo 'ok' . PHP_EOL;
    }

    /**
     * 网点统计
     */
    public function zealSlaveAction(array $params)
    {
        $date = (int)date('Ymd', time() - 86400);
        $dates = [$date];
        if ($params) {
            $dates = self::getDateFromRange($params[0], $params[1]);
        }
        foreach ($dates as $date) {
            $date = (int)$date;
            $start = strtotime($date);
            $end = strtotime($date) + 86400;
            $organizations = $this->modelsManager->createBuilder()
                ->columns('O.Id as Id,S.MainId as HospitalId')
                ->addFrom(Organization::class, 'O')
                ->join(OrganizationRelationship::class, 'S.MinorId=O.Id', 'S', 'left')
                ->where('O.Id!=:Id:', ['Id' => Organization::PEACH])
                ->andWhere('O.IsMain=:IsMain:', ['IsMain' => 2])
                ->andWhere('O.CreateTime<=:CreateTime:', ['CreateTime' => $start])
                ->getQuery()
                ->execute();
            $begin = Transfer::query()
                ->columns(['SendOrganizationId as Id', 'min(StartTime) as StartTime'])
                ->groupBy('SendOrganizationId')
                ->where('StartTime<=:StartTime:')
                ->bind(['StartTime' => $start])
                ->execute()->toArray();
            $transferLog = Transfer::query()
                ->columns(['SendOrganizationId as Id', 'SendHospitalId', 'count(*) as TransferCount'])
                ->betweenWhere('StartTime', $start, $end)
                ->groupBy('SendOrganizationId,SendHospitalId')
                ->execute();
            $transferLog_new = [];
            if (count($transferLog->toArray())) {
                foreach ($transferLog as $item) {
                    $transferLog_new[$item->Id] = $item->TransferCount;
                }
            }
            $bills = Bill::query()
                ->columns('OrganizationId as Id,sum(Fee) as Share')
                ->leftJoin(Organization::class, 'O.Id=OrganizationId', 'O')
                ->where('ReferenceType=3')
                ->andWhere('O.IsMain=2')
                ->betweenWhere('Created', $start, $end)
                ->groupBy('OrganizationId')
                ->execute();
            $transfer_new = [];
            if (count($bills->toArray())) {
                foreach ($bills as $item) {
                    $transfer_new[$item->Id] = abs($item->Share);
                }
            }
            $old = SlaveReport::find(['Date=?0', 'bind' => [(int)date('Ymd', strtotime($date) - 86400)]]);
            $old_new = [];
            $yestoday = 20170810;
            if (count($old->toArray())) {
                foreach ($old as $item) {
                    $old_new[$item->OrganizationId] = [
                        'OrganizationId' => $item->OrganizationId,
                        'Date'           => $item->Date,
                        'TransferDay'    => $item->TransferDay,
                        'TransferMonth'  => $item->TransferMonth,
                        'TransferYear'   => $item->TransferYear,
                        'ShareDay'       => $item->ShareDay,
                        'ShareMonth'     => $item->ShareMonth,
                        'ShareYear'      => $item->ShareYear,
                    ];
                    $yestoday = $item->Date;
                }
            } else {
                $yestoday = date('Ymd', strtotime($params[0]) - 86400);
            }
            $month = mb_substr($date, 0, 6) === mb_substr($yestoday, 0, 6) ? 1 : 0;
            $year = mb_substr($date, 0, 4) === mb_substr($yestoday, 0, 4) ? 1 : 0;
            if (count($organizations->toArray())) {
                foreach ($organizations as $organization) {
                    if (in_array($organization->Id, array_column($begin, 'Id'))) {
                        $transferLog_new[$organization->Id] = isset($transferLog_new[$organization->Id]) ? $transferLog_new[$organization->Id] : 0;
                        $transfer_new[$organization->Id] = isset($transfer_new[$organization->Id]) ? $transfer_new[$organization->Id] : 0;
                        $old_new[$organization->Id] = isset($old_new[$organization->Id]) ? $old_new[$organization->Id] : ['TransferMonth' => 0, 'TransferYear' => 0, 'ShareMonth' => 0, 'ShareYear' => 0];
                        $report = new ZealSlaveReport();
                        $report->OrganizationId = $organization->Id;
                        $report->HospitalId = $organization->HospitalId;
                        $report->Date = $date;
                        $report->TransferDay = $transferLog_new[$organization->Id];
                        $report->TransferMonth = $month ? $old_new[$organization->Id]['TransferMonth'] + $transferLog_new[$organization->Id] : $report->TransferDay;
                        $report->TransferYear = $year ? $old_new[$organization->Id]['TransferYear'] + $transferLog_new[$organization->Id] : $report->TransferMonth;
                        $report->ShareDay = $transfer_new[$organization->Id];
                        $report->ShareMonth = $month ? $old_new[$organization->Id]['ShareMonth'] + $transfer_new[$organization->Id] : $report->ShareDay;
                        $report->ShareYear = $year ? $old_new[$organization->Id]['ShareYear'] + $transfer_new[$organization->Id] : $report->ShareMonth;
                        $report->save();
                    }
                }
            }
        }
        echo 'ok' . PHP_EOL;
    }

    /**
     * 每月需要生成财务对账用的报表
     * 流水号 商户名 提现人 联系方式 对应医院 金额 账户 时间
     * cron: 0 3 1 * *
     */
    public function financeAction()
    {
        // 一个月前的第一天
        $start = strtotime('midnight first day of previous month');
        // 这个月的第一天
        $end = strtotime('midnight first day of this month');
        $sql = 'SELECT
	t.SerialNumber,
	o.`Name` AS Organization,
	t.`Name`,
	t.Bank,
	t.Account,
	o1.`Name` AS Hospital,
	t.Amount / 100 AS Cash,
	o.Phone,
	FROM_UNIXTIME( t.Created ) AS Time 
FROM
	Trade t
	INNER JOIN Organization o ON t.OrganizationId = o.Id
	INNER JOIN OrganizationRelationship os ON os.MinorId = o.Id
	INNER JOIN Organization o1 ON o1.Id = os.MainId 
WHERE
	t.Fake = 0 
	AND o.Fake = 0 
	AND t.`Status` = 2 
	AND t.Type =2
    AND t.Created>=?
    AND t.Created<?';
        $subject = '云转诊提现季度统计';
        $result = $this->db->query($sql, [$start, $end])->fetchAll();
        $columns = ['SerialNumber', 'Organization', 'Name', 'Bank', 'Account', 'Hospital', 'Cash', 'Phone', 'Time'];
        $content = '<html><head><title>' . $subject . '</title></head><body><table><thead><tr>';
        $content .= implode('', array_map(function ($column) {
            return '<th>' . $column . '</th>';
        }, $columns));
        $content .= '</tr></thead><tbody>';
        foreach ($result as $item) {
            $content .= '<tr>';
            foreach ($columns as $column) {
                $content .= '<td>' . $item[$column] . '</td>';
            }
            $content .= '</tr>';
        }
        $content .= '</tbody></table></body></html>';
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=utf-8',

        ];
        // 发给宋晓婷
        mail('1109225177@qq.com', $subject, $content, implode("\r\n", $headers));
    }
}