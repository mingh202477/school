<?php
namespace app\model;

use think\Model;

class User extends Model
{
    protected $table = 'users';
    protected $autoWriteTimestamp = true;

    // 密码加密
    public function setPasswordAttr($value)
    {
        return password_hash($value, PASSWORD_ARGON2ID);
    }

    // 验证密码
    public function checkPassword($password)
    {
        return password_verify($password, $this->password);
    }
}