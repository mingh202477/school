<?php
require_once __DIR__ . '/../../app/models/auth.php';

if (auth_is_logged_in()) {
    header('Location: ../admin/dashboard.php');
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
            header('Location: ../admin/dashboard.php');
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
    <title>统一身份认证 - 东营行知实验学校</title>
    <link rel="icon" href="../dyxz.ico">
    <link rel="stylesheet" href="public/css/login.css">
</head>
<body class="auth-body">
    <!-- 顶部品牌栏 -->
    <header class="auth-header">
        <div class="auth-container auth-header-inner">
            <a href="../index.php" class="brand-logo" title="返回学校首页">
                <img src="../public/images/logo2.png" alt="东营行知实验学校">
            </a>
            <span class="brand-divider" aria-hidden="true"></span>
            <img class="brand-auth-title" src="public/images/auth-title.png" alt="统一身份认证">
        </div>
    </header>

    <!-- 主体内容区 -->
    <main class="auth-main">
        <div class="auth-container auth-main-inner">
            <!-- 左侧新闻图片展示 -->
            <div class="auth-showcase">
                <div class="showcase-image-wrap">
                    <img src="public/images/login-banner.jpg" alt="东营区行知实验学校校长刘培光与学生亲切交流">
                    <span class="showcase-badge">行知学校文宣部</span>
                </div>
                <p class="showcase-caption">
                    *2024-03-20黄河口晚报 东营区行知实验学校校长刘培光：潜心育桃李 当好引路人（图中间为校长刘培光同志）
                </p>
            </div>

            <!-- 右侧登录卡片 -->
            <section class="auth-card" aria-label="登录面板">
                <div class="auth-tabs" role="tablist">
                    <button type="button" class="auth-tab is-active" role="tab" aria-selected="true" data-tab="password">
                        密码登录
                    </button>
                    <button type="button" class="auth-tab" role="tab" aria-selected="false" data-tab="email">
                        邮箱验证码登录
                    </button>
                </div>

                <div class="auth-card-body">
                    <?php if ($error !== ''): ?>
                        <p class="login-alert" role="alert"><?php echo e($error); ?></p>
                    <?php endif; ?>

                    <!-- 密码登录表单 -->
                    <form id="form-password" class="auth-form" method="post" action="login.php" novalidate>
                        <?php echo csrf_field(); ?>

                        <div class="input-group">
                            <span class="input-icon" aria-hidden="true">
                                <!-- 用户图标 -->
                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="8" r="4"></circle>
                                    <path d="M5 20c0-3.8 3.1-6 7-6s7 2.2 7 6"></path>
                                    <line x1="5" y1="20" x2="19" y2="20"></line>
                                </svg>
                            </span>
                            <input
                                id="username"
                                name="username"
                                type="text"
                                value="<?php echo e($username); ?>"
                                placeholder="请输入用户名/邮箱/身份证号码"
                                autocomplete="username"
                                aria-label="用户名、邮箱或身份证号码"
                                required
                                autofocus
                            >
                        </div>

                        <div class="input-group">
                            <span class="input-icon" aria-hidden="true">
                                <!-- 钥匙图标 -->
                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="8.5" cy="8.5" r="4"></circle>
                                    <path d="M11.5 11.5L20 20"></path>
                                    <path d="M16.5 16.5l2.2-2.2"></path>
                                    <path d="M18.5 18.5l2-2"></path>
                                </svg>
                            </span>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                placeholder="请输入密码"
                                autocomplete="current-password"
                                aria-label="密码"
                                required
                            >
                            <button type="button" class="toggle-password" id="togglePasswordBtn" aria-label="显示或隐藏密码" title="显示/隐藏密码">
                                <svg id="eyeIcon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path>
                                    <circle cx="12" cy="12" r="3.5" fill="currentColor"></circle>
                                </svg>
                            </button>
                        </div>

                        <?php if ($showCaptcha): ?>
                            <div class="captcha-block">
                                <div class="captcha-row">
                                    <input
                                        id="captcha"
                                        name="captcha"
                                        type="text"
                                        maxlength="5"
                                        placeholder="请输入图片验证码"
                                        aria-label="图片验证码"
                                        autocomplete="off"
                                        required
                                    >
                                    <img
                                        class="captcha-image"
                                        src="captcha.php?v=<?php echo time(); ?>"
                                        alt="图片验证码，点击可刷新"
                                        title="点击刷新验证码"
                                        onclick="this.src='captcha.php?v=' + Date.now()"
                                    >
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="forgot-row">
                            <a href="javascript:void(0)" class="forgot-link">忘记密码凭证？</a>
                        </div>

                        <button type="submit" class="submit-btn">
                            <span class="submit-btn-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="28" height="28" fill="none">
                                    <path d="M3 10.2h8.5V6.5l6.2 5.5-6.2 5.5v-3.7H3v-3.6z" fill="currentColor"></path>
                                    <path d="M13.5 4h5a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"></path>
                                </svg>
                            </span>
                            <span class="submit-btn-text">登录</span>
                        </button>
                    </form>

                    <!-- 邮箱验证码登录表单（切换标签页展示） -->
                    <form id="form-email" class="auth-form is-hidden" method="post" action="login.php" novalidate>
                        <?php echo csrf_field(); ?>
                        <div class="input-group">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                    <path d="M3 7l9 6 9-6"></path>
                                </svg>
                            </span>
                            <input
                                name="username"
                                type="email"
                                placeholder="请输入绑定的校园邮箱"
                                aria-label="校园邮箱"
                                required
                            >
                        </div>

                        <div class="input-group">
                            <span class="input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 2L4 5v6.09c0 5.05 3.41 9.76 8 10.91 4.59-1.15 8-5.86 8-10.91V5l-8-3z"></path>
                                </svg>
                            </span>
                            <input
                                name="password"
                                type="text"
                                placeholder="请输入邮箱验证码"
                                aria-label="邮箱验证码"
                                required
                            >
                            <button type="button" class="send-code-btn" onclick="alert('演示环境暂未开启邮件网关，请使用密码登录。')">获取验证码</button>
                        </div>

                        <div class="forgot-row">
                            <a href="javascript:void(0)" class="forgot-link">忘记密码凭证？</a>
                        </div>

                        <button type="submit" class="submit-btn">
                            <span class="submit-btn-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="28" height="28" fill="none">
                                    <path d="M3 10.2h8.5V6.5l6.2 5.5-6.2 5.5v-3.7H3v-3.6z" fill="currentColor"></path>
                                    <path d="M13.5 4h5a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"></path>
                                </svg>
                            </span>
                            <span class="submit-btn-text">登录</span>
                        </button>
                    </form>

                    <p class="default-pwd-tip">默认初始密码为身份证号后6位（含X需大写）</p>

                    <div class="third-party-section">
                        <p class="third-party-title">通过以下方式继续：</p>
                        <div class="third-party-icons">
                            <a href="javascript:void(0)" class="oauth-link" title="通过 GitHub 登录" aria-label="通过 GitHub 登录">
                                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                                    <path d="M12 .5C5.65.5.5 5.65.5 12c0 5.09 3.29 9.4 7.86 10.92.58.1.79-.25.79-.56 0-.28-.01-1.02-.02-2-3.2.7-3.88-1.54-3.88-1.54-.52-1.33-1.28-1.68-1.28-1.68-1.05-.71.08-.7.08-.7 1.16.08 1.77 1.19 1.77 1.19 1.03 1.76 2.7 1.25 3.36.96.1-.75.4-1.25.73-1.54-2.55-.29-5.23-1.28-5.23-5.68 0-1.26.45-2.28 1.18-3.09-.12-.29-.51-1.46.11-3.05 0 0 .97-.31 3.17 1.18a11.05 11.05 0 0 1 5.78 0c2.2-1.49 3.16-1.18 3.16-1.18.63 1.59.24 2.76.12 3.05.74.81 1.18 1.83 1.18 3.09 0 4.41-2.69 5.38-5.25 5.67.41.36.78 1.06.78 2.14 0 1.55-.01 2.79-.01 3.17 0 .31.21.67.8.55A11.51 11.51 0 0 0 23.5 12C23.5 5.65 18.35.5 12 .5z"/>
                                </svg>
                            </a>
                            <a href="javascript:void(0)" class="oauth-link" title="通过 Google 登录" aria-label="通过 Google 登录">
                                <svg viewBox="0 0 24 24" width="23" height="23" fill="currentColor">
                                    <path d="M12.24 10.285V14.4h6.806c-.275 1.765-2.056 5.174-6.806 5.174-4.095 0-7.439-3.389-7.439-7.574s3.345-7.574 7.439-7.574c2.33 0 3.891.989 4.785 1.849l3.254-3.138C18.189 1.186 15.479 0 12.24 0c-6.635 0-12 5.365-12 12s5.365 12 12 12c6.926 0 11.52-4.869 11.52-11.726 0-.788-.085-1.39-.189-1.989H12.24z"/>
                                </svg>
                            </a>
                            <a href="javascript:void(0)" class="oauth-link" title="通过 Apple 登录" aria-label="通过 Apple 登录">
                                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                                    <path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.53 4.08zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/>
                                </svg>
                            </a>
                        </div>
                    </div>

                    <div class="activate-row">
                        <a href="javascript:void(0)" class="activate-link">新生账号激活</a>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <!-- 底部信息栏 -->
    <footer class="auth-footer">
        <div class="auth-container">
            <div class="footer-top">
                <div class="footer-contact">
                    <span>信息化处邮箱：----@---.cn</span>
                    <span>电话：--------（可能的费用由运营商收取）</span>
                </div>
                <div class="footer-security">
                    <p class="security-warning">非涉密平台，严禁处理涉密信息！</p>
                    <p class="browser-tip">建议使用浏览器：edge、FireFox、Chrome</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>Copyright © 2026 东营行知实验学校 版权所有 | 建议使用 1024*768 分辨率浏览</p>
            </div>
        </div>
    </footer>

    <script>
        (function () {
            // 密码显隐切换
            var pwdInput = document.getElementById('password');
            var toggleBtn = document.getElementById('togglePasswordBtn');
            if (pwdInput && toggleBtn) {
                toggleBtn.addEventListener('click', function () {
                    var isPwd = pwdInput.getAttribute('type') === 'password';
                    pwdInput.setAttribute('type', isPwd ? 'text' : 'password');
                    toggleBtn.classList.toggle('is-showing', isPwd);
                });
            }

            // 登录方式标签切换
            var tabs = document.querySelectorAll('.auth-tab');
            var formPassword = document.getElementById('form-password');
            var formEmail = document.getElementById('form-email');
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    var target = tab.getAttribute('data-tab');
                    tabs.forEach(function (t) {
                        var active = t === tab;
                        t.classList.toggle('is-active', active);
                        t.setAttribute('aria-selected', active ? 'true' : 'false');
                    });
                    if (target === 'email') {
                        formPassword.classList.add('is-hidden');
                        formEmail.classList.remove('is-hidden');
                    } else {
                        formEmail.classList.add('is-hidden');
                        formPassword.classList.remove('is-hidden');
                    }
                });
            });
        })();
    </script>
</body>
</html>
