<?php
/**
 * PHPMailer 邮件验证码发送模块
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/auth.php';

/**
 * 生成并发送 6 位数字邮箱验证码
 *
 * @param string $email 目标邮箱
 * @param string $scene 场景：'activate' | 'login' | 'reset_pwd'
 * @param string $recipientName 姓名（如无则从 users 表按邮箱查询或显示 -------）
 * @return array{ok: bool, message: string}
 */
function auth_send_email_verification_code(string $email, string $scene = 'activate', string $recipientName = '', string $role = ''): array
{
    global $pdo;

    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '请输入有效的电子邮箱地址。'];
    }

    $ip = auth_client_ip();

    // 检查 60 秒发送冷却
    $stmt = $pdo->prepare("
        SELECT `created_at` FROM `auth_email_codes`
        WHERE (`email` = :email OR `request_ip` = :ip)
          AND `scene` = :scene
          AND `created_at` >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
        ORDER BY `id` DESC LIMIT 1
    ");
    $stmt->execute([':email' => $email, ':ip' => $ip, ':scene' => $scene]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'message' => '验证码发送过于频繁，请等待 60 秒后再试。'];
    }

    if ($recipientName === '' || $role === '') {
        $uStmt = $pdo->prepare("SELECT `real_name`, `username`, `role` FROM `users` WHERE `email` = :email LIMIT 1");
        $uStmt->execute([':email' => $email]);
        $uRow = $uStmt->fetch();
        if ($uRow) {
            if ($recipientName === '') {
                $recipientName = (string) ($uRow['real_name'] ?: $uRow['username']);
            }
            if ($role === '') {
                $role = (string) ($uRow['role'] ?? 'student');
            }
        }
    }
    if ($recipientName === '') {
        $recipientName = '-------';
    }
    $roleTitleMap = [
        'admin'   => '领导',
        'leader'  => '领导',
        'teacher' => '老师',
        'student' => '同学',
        'alumni'  => '同学',
    ];
    $roleTitle = $roleTitleMap[$role] ?? '同学';

    // 生成 6 位纯数字验证码
    $code = (string) random_int(100000, 999999);
    $codeHash = hash_hmac('sha256', $code, auth_get_hmac_key());

    // 作废该邮箱同场景下未使用的旧验证码
    $pdo->prepare("
        UPDATE `auth_email_codes`
        SET `is_used` = 1
        WHERE `email` = :email AND `scene` = :scene AND `is_used` = 0
    ")->execute([':email' => $email, ':scene' => $scene]);

    // 写入新验证码记录（仅存 HMAC 摘要）
    $ins = $pdo->prepare("
        INSERT INTO `auth_email_codes` (`email`, `code_hash`, `scene`, `is_used`, `request_ip`, `expires_at`)
        VALUES (:email, :hash, :scene, 0, :ip, DATE_ADD(NOW(), INTERVAL 5 MINUTE))
    ");
    $ins->execute([
        ':email' => $email,
        ':hash'  => $codeHash,
        ':scene' => $scene,
        ':ip'    => $ip,
    ]);

    // 读取 SMTP 配置并使用 PHPMailer 发送
    require __DIR__ . '/../../config/mail.php';

    if ($smtp_username === '' || $smtp_password === '' || $smtp_host === 'smtp.example.com') {
        return [
            'ok'      => false,
            'message' => '邮件服务器（SMTP）尚未配置账号密钥。',
        ];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->CharSet  = PHPMailer::CHARSET_UTF8;
        $mail->isSMTP();
        $mail->Host     = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->Port     = $smtp_port;

        if (strtolower((string) $smtp_secure) === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (strtolower((string) $smtp_secure) === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($smtp_from_email, $smtp_from_name);
        $mail->addAddress($email);
        $mail->isHTML(false);

        $sceneLabel = $scene === 'activate' ? '账号激活' : ($scene === 'reset_pwd' ? '密码重置' : '身份认证登录');
        $mail->Subject = "东营行知实验学校身份认证系统";
        $mail->Body    = "东营行知实验学校身份认证系统\r\n"
            . "{$recipientName}{$roleTitle}\r\n"
            . "您正在进行{$sceneLabel}操作\r\n"
            . "您的 6 位数字验证码为：\r\n"
            . "{$code}\r\n"
            . "请勿泄露给他人。\r\n"
            . "如非本人操作，您的密码可能泄露，请重置密码。\r\n"
            . "请勿直接回复此邮件。";

        $mail->send();
        return ['ok' => true, 'message' => '验证码已发送至您的邮箱，请查收。'];
    } catch (MailException $e) {
        return ['ok' => false, 'message' => '邮件发送失败，请检查 SMTP 配置或稍后重试。'];
    }
}

/**
 * 校验 6 位数字邮箱验证码
 */
function auth_verify_email_code(string $email, string $code, string $scene = 'activate', bool $markUsed = true): bool
{
    global $pdo;

    $email = trim($email);
    $code  = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT `id`, `code_hash`
        FROM `auth_email_codes`
        WHERE `email` = :email
          AND `scene` = :scene
          AND `is_used` = 0
          AND `expires_at` >= NOW()
        ORDER BY `id` DESC
        LIMIT 1
    ");
    $stmt->execute([':email' => $email, ':scene' => $scene]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    $expectedHash = hash_hmac('sha256', $code, auth_get_hmac_key());
    if (!hash_equals((string) $row['code_hash'], $expectedHash)) {
        return false;
    }

    if ($markUsed) {
        $pdo->prepare("UPDATE `auth_email_codes` SET `is_used` = 1 WHERE `id` = :id")
            ->execute([':id' => (int) $row['id']]);
    }

    return true;
}