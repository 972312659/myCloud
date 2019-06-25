<?php

namespace App\Libs\fake\transfer;

class Seed
{
    protected $phone_prefix = [
        133, 153, 180, 181, 189, 177, 173, 149, 130, 131, 132, 155, 156, 145, 185, 186, 176, 175, 134, 135, 136, 137,
        138, 139, 150, 151, 152, 157, 158, 159, 182, 183, 184, 187, 188, 147, 178
    ];

    protected $name_file = __DIR__.'/names';

    protected $name_file_handler;

//    protected $id_card = [
//        ['510105', '青羊区'],
//        ['510104', '锦江区'],
//        ['510106', '金牛区'],
//        ['510107', '武侯区'],
//        ['510108', '成华区'],
//        ['510112', '龙泉驿区'],
//        ['510113', '青白江区'],
//        ['510114', '新都区'],
//        ['510181', '都江堰市'],
//        ['510182', '彭州市'],
//        ['510183', '邛崃市'],
//        ['510184', '崇州市'],
//        ['510121', '金堂县'],
//        ['510124', '郫县'],
//        ['510132', '新津县'],
//        ['510122', '双流区'],
//        ['510131', '蒲江县'],
//        ['510129', '大邑县'],
//        ['510115', '温江区'],
//        ['512081', '简阳市']
//    ];

    /**
     * Seed constructor.
     */
    public function __construct()
    {
        $this->name_file_handler = new \SplFileObject($this->name_file);
        $this->name_file_handler->seek($this->name_file_handler->getSize());
        $this->name_file_handler->setMaxLineLen($this->name_file_handler->key()-1);
        $this->name_file_handler->rewind();
    }

    public function randPerson()
    {
        $this->name_file_handler->rewind();
        $this->name_file_handler->seek(rand(0, $this->name_file_handler->getMaxLineLen()));

        $line = $this->name_file_handler->fgets();

        $split = explode(',', trim($line, PHP_EOL));

        $person = new Person();
        $person->id_card = $split[0];
        $person->name = $split[1];
        $person->gender = $split[2];
        $person->address = $split[3];
        $person->birthday = $split[4];
        $person->phone = $split[5];

        return $person;
    }

    public function randPhone()
    {
        return $this->phone_prefix[array_rand($this->phone_prefix)]
            . substr(uniqid('', true), 20) . substr(microtime(), 2, 5);
    }
}
