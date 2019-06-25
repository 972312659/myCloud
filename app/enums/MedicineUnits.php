<?php

namespace App\Enums;

/**
 * Class DrugUnits 药品单位
 * @package App\Enums
 *
 */
class MedicineUnits
{
// 中盒、对、ug、10g、0.2g、IU、ku、mg、ml、u、包、滴、小时、万u、50ml、10ml、u、枚、cm、小盒、小袋、部、组、加仑、床、批、双、顶、T、箱、人份、副、盘、圈、捆、串、页、座、平方分米、间、万IU、把、板、小包、本、袋、付、个、根、合、盒、斤、克、粒、片、瓶、升、台、套、听、桶、筒、万、张、支、只、kg、条、小袋、小盒、贴、毫升、件、卷、米、扎、碗、份、条、小袋、小盒、贴、毫升、件、卷、米、扎、碗、份、提、g、揿、丸、块、颗、具、台、厘米
    private static $map = [
        ['Id' => 1, 'Name' => '中盒'],
        ['Id' => 2, 'Name' => '对'],
        ['Id' => 3, 'Name' => 'ug'],
        ['Id' => 4, 'Name' => '10g'],
        ['Id' => 5, 'Name' => '0.2g'],
        ['Id' => 6, 'Name' => 'IU'],
        ['Id' => 7, 'Name' => 'ku'],
        ['Id' => 8, 'Name' => 'mg'],
        ['Id' => 9, 'Name' => 'ml'],
        ['Id' => 10, 'Name' => 'u'],
        ['Id' => 11, 'Name' => '包'],
        ['Id' => 12, 'Name' => '滴'],
        ['Id' => 13, 'Name' => '小时'],
        ['Id' => 14, 'Name' => '万u'],
        ['Id' => 15, 'Name' => '50ml'],
        ['Id' => 16, 'Name' => '10ml'],
        ['Id' => 17, 'Name' => 'u'],
        ['Id' => 18, 'Name' => '枚'],
        ['Id' => 19, 'Name' => 'cm'],
        ['Id' => 20, 'Name' => '小盒'],
        ['Id' => 21, 'Name' => '小袋'],
        ['Id' => 22, 'Name' => '部'],
        ['Id' => 23, 'Name' => '组'],
        ['Id' => 24, 'Name' => '加仑'],
        ['Id' => 25, 'Name' => '床'],
        ['Id' => 26, 'Name' => '批'],
        ['Id' => 27, 'Name' => '双'],
        ['Id' => 28, 'Name' => '顶'],
        ['Id' => 29, 'Name' => 'T'],
        ['Id' => 30, 'Name' => '箱'],
        ['Id' => 31, 'Name' => '人份'],
        ['Id' => 32, 'Name' => '副'],
        ['Id' => 33, 'Name' => '盘'],
        ['Id' => 34, 'Name' => '圈'],
        ['Id' => 35, 'Name' => '捆'],
        ['Id' => 36, 'Name' => '串'],
        ['Id' => 37, 'Name' => '页'],
        ['Id' => 38, 'Name' => '座'],
        ['Id' => 39, 'Name' => '平方分米'],
        ['Id' => 40, 'Name' => '间'],
        ['Id' => 41, 'Name' => '万IU'],
        ['Id' => 42, 'Name' => '把'],
        ['Id' => 43, 'Name' => '板'],
        ['Id' => 44, 'Name' => '小包'],
        ['Id' => 45, 'Name' => '本'],
        ['Id' => 46, 'Name' => '袋'],
        ['Id' => 47, 'Name' => '付'],
        ['Id' => 48, 'Name' => '个'],
        ['Id' => 49, 'Name' => '根'],
        ['Id' => 50, 'Name' => '合'],
        ['Id' => 51, 'Name' => '盒'],
        ['Id' => 52, 'Name' => '斤'],
        ['Id' => 53, 'Name' => '克'],
        ['Id' => 54, 'Name' => '粒'],
        ['Id' => 55, 'Name' => '片'],
        ['Id' => 56, 'Name' => '瓶'],
        ['Id' => 57, 'Name' => '升'],
        ['Id' => 58, 'Name' => '台'],
        ['Id' => 59, 'Name' => '套'],
        ['Id' => 60, 'Name' => '听'],
        ['Id' => 61, 'Name' => '桶'],
        ['Id' => 62, 'Name' => '筒'],
        ['Id' => 63, 'Name' => '万'],
        ['Id' => 64, 'Name' => '张'],
        ['Id' => 65, 'Name' => '支'],
        ['Id' => 66, 'Name' => '只'],
        ['Id' => 67, 'Name' => 'kg'],
        ['Id' => 68, 'Name' => '条'],
        ['Id' => 69, 'Name' => '小袋'],
        ['Id' => 70, 'Name' => '小盒'],
        ['Id' => 71, 'Name' => '贴'],
        ['Id' => 72, 'Name' => '毫升'],
        ['Id' => 73, 'Name' => '件'],
        ['Id' => 74, 'Name' => '卷'],
        ['Id' => 75, 'Name' => '米'],
        ['Id' => 76, 'Name' => '扎'],
        ['Id' => 77, 'Name' => '碗'],
        ['Id' => 78, 'Name' => '份'],
        ['Id' => 79, 'Name' => '条'],
        ['Id' => 80, 'Name' => '小袋'],
        ['Id' => 81, 'Name' => '小盒'],
        ['Id' => 82, 'Name' => '贴'],
        ['Id' => 83, 'Name' => '毫升'],
        ['Id' => 84, 'Name' => '件'],
        ['Id' => 85, 'Name' => '卷'],
        ['Id' => 86, 'Name' => '米'],
        ['Id' => 87, 'Name' => '扎'],
        ['Id' => 88, 'Name' => '碗'],
        ['Id' => 89, 'Name' => '份'],
        ['Id' => 90, 'Name' => '提'],
        ['Id' => 91, 'Name' => 'g'],
        ['Id' => 92, 'Name' => '揿'],
        ['Id' => 93, 'Name' => '丸'],
        ['Id' => 94, 'Name' => '块'],
        ['Id' => 95, 'Name' => '颗'],
        ['Id' => 96, 'Name' => '具'],
        ['Id' => 97, 'Name' => '台'],
        ['Id' => 98, 'Name' => '厘米'],
    ];

    public static function options()
    {
        return array_column(self::$map, 'Id');
    }

    public static function value($Id)
    {
        foreach (self::$map as $item) {
            if ($item['Id'] == $Id) {
                return $item['Name'];
            }
        }
        return null;
    }

    public static function map()
    {
        return self::$map;
    }

}