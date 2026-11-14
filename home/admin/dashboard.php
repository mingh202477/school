<?php
require_once __DIR__ . '/../../app/models/auth.php';
auth_require_login();

function dashboard_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>管理平台 - 学校门户</title>
    <link rel="stylesheet" href="public/css/login.css">
</head>
<body>
<main class="dashboard-page">
    <section class="dashboard-card">
        <p class="eyebrow">SCHOOL PORTAL</p>
        <h1>登录成功</h1>
        <p>你好，<?php echo dashboard_e((string) $_SESSION['auth_user']); ?>。管理后台的功能将在下一步继续完善。</p>
        <a class="dashboard-link" href="../index.php">返回网站首页</a>
    </section>
</main>
</body>
</html>
