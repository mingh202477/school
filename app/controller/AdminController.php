<?php
namespace app\controller;

use app\BaseController;
use app\model\Admin;
use app\model\User;
use think\facade\View;
use think\facade\Session;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class AdminController extends BaseController
{
    public function login()
    {
        if ($this->request->isPost()) {
            $username = $this->request->post('username');
            $password = $this->request->post('password');

            $admin = Admin::where('username', $username)->find();
            if ($admin && $admin->checkPassword($password)) {
                Session::set('admin_id', $admin->id);
                return redirect('/admin/dashboard');
            } else {
                return View::fetch('admin/login', ['error' => '用户名或密码错误']);
            }
        }
        return View::fetch('admin/login');
    }

    public function dashboard()
    {
        if (!Session::has('admin_id')) {
            return redirect('/admin/login');
        }
        $users = User::select();
        return View::fetch('admin/dashboard', ['users' => $users]);
    }

    public function forgotPassword()
    {
        if ($this->request->isPost()) {
            $email = $this->request->post('email');
            $admin = Admin::where('email', $email)->find();
            if ($admin) {
                $token = md5(uniqid());
                $admin->reset_token = $token;
                $admin->reset_expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $admin->save();

                $this->sendResetEmail($email, $token);
                return View::fetch('admin/forgot_password', ['message' => '重置链接已发送']);
            } else {
                return View::fetch('admin/forgot_password', ['error' => '邮箱不存在']);
            }
        }
        return View::fetch('admin/forgot_password');
    }

    public function resetPassword($token)
    {
        $admin = Admin::where('reset_token', $token)->where('reset_expires', '>', date('Y-m-d H:i:s'))->find();
        if (!$admin) {
            return '无效令牌';
        }

        if ($this->request->isPost()) {
            $password = $this->request->post('password');
            $admin->password = $password;
            $admin->reset_token = null;
            $admin->reset_expires = null;
            $admin->save();
            return redirect('/admin/login');
        }
        return View::fetch('admin/reset_password', ['token' => $token]);
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
            $mail->Subject = '管理员密码重置';
            $mail->Body = '点击链接重置密码: <a href="' . url('/admin/reset/' . $token) . '">重置密码</a>';

            $mail->send();
        } catch (Exception $e) {
            // 处理错误
        }
    }

    public function logout()
    {
        Session::delete('admin_id');
        return redirect('/admin/login');
    }
}