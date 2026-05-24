<?php
namespace app\controller;

use app\BaseController;
use app\model\User;
use think\facade\View;
use think\facade\Session;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class UserController extends BaseController
{
    public function login()
    {
        if ($this->request->isPost()) {
            $username = $this->request->post('username');
            $password = $this->request->post('password');

            $user = User::where('username', $username)->find();
            if ($user && $user->checkPassword($password)) {
                Session::set('user_id', $user->id);
                return redirect('/proxy');
            } else {
                return View::fetch('user/login', ['error' => '用户名或密码错误']);
            }
        }
        return View::fetch('user/login');
    }

    public function register()
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            $user = new User();
            if ($user->save($data)) {
                return redirect('/user/login');
            } else {
                return View::fetch('user/register', ['error' => '注册失败']);
            }
        }
        return View::fetch('user/register');
    }

    public function forgotPassword()
    {
        if ($this->request->isPost()) {
            $email = $this->request->post('email');
            $user = User::where('email', $email)->find();
            if ($user) {
                // 生成重置令牌
                $token = md5(uniqid());
                $user->reset_token = $token;
                $user->reset_expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $user->save();

                // 发送邮件
                $this->sendResetEmail($email, $token);
                return View::fetch('user/forgot_password', ['message' => '重置链接已发送到您的邮箱']);
            } else {
                return View::fetch('user/forgot_password', ['error' => '邮箱不存在']);
            }
        }
        return View::fetch('user/forgot_password');
    }

    public function resetPassword($token)
    {
        $user = User::where('reset_token', $token)->where('reset_expires', '>', date('Y-m-d H:i:s'))->find();
        if (!$user) {
            return '无效或过期的令牌';
        }

        if ($this->request->isPost()) {
            $password = $this->request->post('password');
            $user->password = $password;
            $user->reset_token = null;
            $user->reset_expires = null;
            $user->save();
            return redirect('/user/login');
        }
        return View::fetch('user/reset_password', ['token' => $token]);
    }

    private function sendResetEmail($email, $token)
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = env('MAIL_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = env('MAIL_USERNAME');
            $mail->Password = env('MAIL_PASSWORD');
            $mail->SMTPSecure = env('MAIL_ENCRYPTION');
            $mail->Port = env('MAIL_PORT');

            $mail->setFrom(env('MAIL_FROM_ADDRESS'), env('MAIL_FROM_NAME'));
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = '密码重置';
            $mail->Body = '点击以下链接重置密码: <a href="' . url('/user/reset/' . $token) . '">重置密码</a>';

            $mail->send();
        } catch (Exception $e) {
            // 处理错误
        }
    }

    public function logout()
    {
        Session::delete('user_id');
        return redirect('/user/login');
    }
}