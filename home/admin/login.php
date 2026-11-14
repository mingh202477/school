<?php
require_once __DIR__ . '/../../app/models/auth.php';

if (auth_is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $captcha = (string) ($_POST['captcha'] ?? '');

    if (!csrf_verify()) {
        http_response_code(400);
        $error = '请求已失效，请刷新页面后重试。';
    } else {
        $captchaValid = !auth_requires_captcha() || auth_verify_captcha($captcha);
        $credentialValid = hash_equals(AUTH_USERNAME, $username)
            && password_verify($password, AUTH_PASSWORD_HASH);

        if ($captchaValid && $credentialValid) {
            session_regenerate_id(true);
            $_SESSION['auth_user'] = AUTH_USERNAME;
            $_SESSION['auth_login_at'] = time();
            auth_reset_failures();
            header('Location: dashboard.php');
            exit;
        }

        auth_record_failure();
        $error = auth_requires_captcha() && !$captchaValid
            ? '验证码不正确或已过期，请重新填写。'
            : '账号或密码不正确。请完成验证码后再次登录。';
    }
}

$showCaptcha = auth_requires_captcha();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>统一身份认证 - 学校门户</title>
    <link rel="stylesheet" href="public/css/login.css">
</head>
<body>
<main class="login-page">
    <section class="login-panel" aria-labelledby="login-title">
        <div class="login-brand">
            <img src="../public/images/logo2.png" alt="学校门户">
            <span>统一身份认证</span>
        </div>
        <div class="login-content">
            <p class="eyebrow">SCHOOL PORTAL</p>
            <h1 id="login-title">欢迎登录</h1>
            <p class="login-intro">请输入账号和密码，以访问校园管理平台。</p>

            <?php if ($error !== ''): ?>
                <p class="login-alert" role="alert"><?php echo e($error); ?></p>
            <?php endif; ?>

            <form method="post" action="login.php" novalidate>
                <?php echo csrf_field(); ?>
                <label for="username">账号</label>
                <input id="username" name="username" type="text" value="<?php echo e($username); ?>" autocomplete="username" required autofocus>

                <label for="password">密码</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>

                <?php if ($showCaptcha): ?>
                    <label for="captcha">图片验证码</label>
                    <div class="captcha-row">
                        <input id="captcha" name="captcha" type="text" maxlength="5" autocomplete="off" required>
                        <img class="captcha-image" src="captcha.php?v=<?php echo time(); ?>" alt="图片验证码，点击可刷新" title="点击刷新验证码" onclick="this.src='captcha.php?v=' + Date.now()">
                    </div>
                    <p class="captcha-note">请输入验证码。点击图片刷新。</p>
                <?php endif; ?>

                <button type="submit">登录管理平台</button>
            </form>
        </div>
        <p class="login-footer"><?php echo date('Y-m-d H:i:s'); ?></p>
    </section>
</main>
</body>
</html>
