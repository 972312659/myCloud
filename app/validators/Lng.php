<?php

namespace App\Validators;

use Phalcon\Validation;
use Phalcon\Validation\Message;
use Phalcon\Validation\Validator;

class Lng extends Validator
{
    public function validate(Validation $validator, $attribute)
    {
        $value = $validator->getValue($attribute);
        if (!preg_match('/^[\-\+]?[0,1]?[0-8]?[0-9]\.\d{1,8}$/', $value) || abs($value) > 180) {
            $message = $this->getOption('message');
            if (!$message) {
                $message = "Invalid Lng";
            }
            $validator->appendMessage(
                new Message($message, $attribute, "Lng")
            );
            return false;
        }
        return true;
    }
}
