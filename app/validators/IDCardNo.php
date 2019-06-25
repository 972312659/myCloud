<?php

namespace App\Validators;

use App\Libs\user\ID;
use Phalcon\Validation;
use Phalcon\Validation\Message;
use Phalcon\Validation\Validator;

class IDCardNo extends Validator
{
    public function validate(Validation $validator, $attribute)
    {
        $value = $validator->getValue($attribute);
        $ID = new ID($value);
        if (!$ID->validate()) {
            $message = $this->getOption('message');
            if (!$message) {
                $message = "18位身份证号码错误";
            }
            $validator->appendMessage(
                new Message($message, $attribute, "IDnumber")
            );
            return false;
        }
        return true;
    }
}
