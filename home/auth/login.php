<?php
require_once __DIR__ . '/../../app/models/mailer.php';

if (auth_is_logged_in()) {
    header('Location: ' . auth_redirect_target_by_role());
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// 异步发送邮箱登录 6 位数字验证码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'send_login_code') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!csrf_verify()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => '请求已失效，请刷新页面重试。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = trim((string) ($_POST['email'] ?? ''));
    $uStmt = $pdo->prepare("SELECT `id`, `username`, `real_name`, `email`, `role`, `status` FROM `users` WHERE `email` = :e OR `username` = :u LIMIT 1");
    $uStmt->execute([':e' => $input, ':u' => $input]);
    $uRow = $uStmt->fetch();
    if (!$uRow || empty($uRow['email'])) {
        echo json_encode(['ok' => false, 'message' => '该邮箱或账号未绑定有效邮箱，请先完成新生账号激活。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int) $uRow['status'] !== 1) {
        echo json_encode(['ok' => false, 'message' => '该账号尚未激活或已停用。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $targetEmail = (string) $uRow['email'];
    $res = auth_send_email_verification_code($targetEmail, 'login', (string) ($uRow['real_name'] ?? ''), (string) ($uRow['role'] ?? 'student'));
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

$error = '';
$username = '';
$activeTab = 'password';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginMode = (string) ($_POST['login_mode'] ?? 'password');
    $activeTab = $loginMode === 'email' ? 'email' : 'password';
    $username  = trim((string) ($_POST['username'] ?? ''));
    $password  = (string) ($_POST['password'] ?? '');
    $captcha   = (string) ($_POST['captcha'] ?? '');

    if (!csrf_verify()) {
        http_response_code(400);
        $error = '请求已失效，请刷新页面后重试。';
    } elseif ($loginMode === 'email') {
        $result = auth_attempt_email_code_login($username, $password);
        if ($result['ok'] && is_array($result['user'])) {
            $user = $result['user'];
            session_regenerate_id(true);
            $_SESSION['auth_user_id'] = (int) $user['id'];
            $_SESSION['auth_user'] = (string) $user['username'];
            $_SESSION['auth_real_name'] = (string) ($user['real_name'] ?? $user['username']);
            $_SESSION['auth_role'] = (string) ($user['role'] ?? 'student');
            $_SESSION['auth_login_at'] = time();
            header('Location: ' . auth_redirect_target_by_role((string) ($user['role'] ?? 'student')));
            exit;
        }
        $error = $result['error'];
    } elseif (auth_requires_captcha($username) && !auth_verify_captcha($captcha)) {
        auth_record_failure($username, null, 'invalid_captcha');
        $error = '验证码不正确或已过期，请重新填写。';
    } else {
        $result = auth_attempt_password_login($username, $password);
        if ($result['ok'] && is_array($result['user'])) {
            $user = $result['user'];
            session_regenerate_id(true);
            $_SESSION['auth_user_id'] = (int) $user['id'];
            $_SESSION['auth_user'] = (string) $user['username'];
            $_SESSION['auth_real_name'] = (string) ($user['real_name'] ?? $user['username']);
            $_SESSION['auth_role'] = (string) ($user['role'] ?? 'student');
            $_SESSION['auth_login_at'] = time();
            header('Location: ' . auth_redirect_target_by_role((string) ($user['role'] ?? 'student')));
            exit;
        }
        $error = $result['error'];
    }
}

$showCaptcha = auth_requires_captcha($username);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex, nofollow">
    <title>统一身份认证 - 东营行知实验学校</title>
    <link rel="icon" href="../dyxz.ico">
    <link rel="stylesheet" href="public/css/login.css">
</head>
<?php $footerCfg = auth_get_site_footer_config(); ?>
<body class="auth-body">
<?php require_once __DIR__ . '/../../app/models/plugin_manager.php'; plugin_run_hook('head_top'); ?>
    <!-- 顶部品牌栏 -->
    <div class="auth-header">
        <div class="auth-container auth-header-inner clearfix">
            <a href="../index.php" class="brand-logo" title="返回学校首页">
                <img src="../<?php echo e($footerCfg['logo_2']); ?>" alt="东营行知实验学校">
            </a>
            <span class="brand-divider"></span>
            <img class="brand-auth-title" src="public/images/auth-title.png" alt="统一身份认证">
        </div>
    </div>

    <!-- 主体内容区 -->
    <div class="auth-main">
        <div class="auth-container auth-main-inner clearfix">
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
            <div class="auth-card">
                <div class="auth-tabs clearfix">
                    <button type="button" class="auth-tab <?php echo $activeTab === 'password' ? 'is-active' : ''; ?>" data-tab="password">
                        密码登录
                    </button>
                    <button type="button" class="auth-tab <?php echo $activeTab === 'email' ? 'is-active' : ''; ?>" data-tab="email">
                        邮箱验证码登录
                    </button>
                </div>

                <div class="auth-card-body">
                    <?php if ($error !== ''): ?>
                        <p class="login-alert"><?php echo e($error); ?></p>
                    <?php endif; ?>

                    <!-- 密码登录表单 -->
                    <form id="form-password" class="auth-form <?php echo $activeTab === 'email' ? 'is-hidden' : ''; ?>" method="post" action="login.php">
                        <?php echo csrf_field(); ?>

                        <div class="input-group clearfix">
                            <span class="input-icon">
                                <img src="public/images/icon-user.png?v=2" alt="">
                            </span>
                            <input
                                id="username"
                                name="username"
                                type="text"
                                value="<?php echo e($username); ?>"
                                placeholder="请输入用户名/邮箱/身份证号码"
                            >
                        </div>

                        <div class="input-group has-right-btn clearfix">
                            <span class="input-icon">
                                <img src="public/images/icon-key.png?v=2" alt="">
                            </span>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                placeholder="请输入密码"
                            >
                            <button type="button" class="toggle-password" id="togglePasswordBtn" title="显示/隐藏密码">
                                <img src="public/images/icon-eye.png?v=2" alt="显隐">
                            </button>
                        </div>

                        <?php if ($showCaptcha): ?>
                            <div class="captcha-block">
                                <div class="captcha-row clearfix">
                                    <input
                                        id="captcha"
                                        name="captcha"
                                        type="text"
                                        maxlength="5"
                                        placeholder="请输入图片验证码"
                                        autocomplete="off"
                                    >
                                    <img
                                        class="captcha-image"
                                        src="captcha.php?v=<?php echo time(); ?>"
                                        alt="图片验证码，点击可刷新"
                                        title="点击刷新验证码"
                                        onclick="this.src='captcha.php?v=' + (new Date().getTime())"
                                    >
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="forgot-row">
                            <a href="forgot.php" class="forgot-link">忘记密码凭证？</a>
                        </div>

                        <button type="submit" class="submit-btn">
                            <span class="submit-btn-icon">
                                <img src="public/images/icon-login.png?v=2" alt="">
                            </span>
                            <span class="submit-btn-text">登录</span>
                        </button>
                    </form>

                    <!-- 邮箱验证码登录表单 -->
                    <form id="form-email" class="auth-form <?php echo $activeTab === 'email' ? '' : 'is-hidden'; ?>" method="post" action="login.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="login_mode" value="email">
                        <div class="input-group clearfix">
                            <span class="input-icon">
                                <img src="public/images/icon-user.png?v=2" alt="">
                            </span>
                            <input
                                id="loginEmailInput"
                                name="username"
                                type="text"
                                value="<?php echo $activeTab === 'email' ? e($username) : ''; ?>"
                                placeholder="请输入绑定的校园邮箱"
                            >
                        </div>

                        <div class="input-group has-code-btn clearfix">
                            <span class="input-icon">
                                <img src="public/images/icon-key.png?v=2" alt="">
                            </span>
                            <input
                                name="password"
                                type="text"
                                maxlength="6"
                                placeholder="请输入邮箱验证码"
                            >
                            <button type="button" class="send-code-btn" id="sendLoginCodeBtn" onclick="sendLoginEmailCode()">获取验证码</button>
                        </div>

                        <div class="forgot-row">
                            <a href="forgot.php" class="forgot-link">忘记密码凭证？</a>
                        </div>

                        <button type="submit" class="submit-btn">
                            <span class="submit-btn-icon">
                                <img src="public/images/icon-login.png?v=2" alt="">
                            </span>
                            <span class="submit-btn-text">登录</span>
                        </button>
                    </form>

                    <p class="default-pwd-tip">默认初始密码为身份证号后6位（含X需大写）</p>

                    <div class="third-party-section">
                        <p class="third-party-title">通过以下方式继续：</p>
                        <div class="third-party-icons">
                            <a href="javascript:void(0)" class="oauth-link" title="通过 GitHub 登录">
                                <svg viewBox="0 0 24 24" width="21" height="21" fill="currentColor">
                                    <path d="M10.226 17.284c-2.965-.36-5.054-2.493-5.054-5.256 0-1.123.404-2.336 1.078-3.144-.292-.741-.247-2.314.09-2.965.898-.112 2.111.36 2.83 1.01.853-.269 1.752-.404 2.853-.404 1.1 0 1.999.135 2.807.382.696-.629 1.932-1.1 2.83-.988.315.606.36 2.179.067 2.942.72.854 1.101 2 1.101 3.167 0 2.763-2.089 4.852-5.098 5.234.763.494 1.28 1.572 1.28 2.807v2.336c0 .674.561 1.056 1.235.786 4.066-1.55 7.255-5.615 7.255-10.646C23.5 6.188 18.334 1 11.978 1 5.62 1 .5 6.188.5 12.545c0 4.986 3.167 9.12 7.435 10.669.606.225 1.19-.18 1.19-.786V20.63a2.9 2.9 0 0 1-1.078.224c-1.483 0-2.359-.808-2.987-2.313-.247-.607-.517-.966-1.034-1.033-.27-.023-.359-.135-.359-.27 0-.27.45-.471.898-.471.652 0 1.213.404 1.797 1.235.45.651.921.943 1.483.943.561 0 .92-.202 1.437-.719.382-.381.674-.718.944-.943"></path>
                                </svg>
                            </a>
                            <a href="javascript:void(0)" class="oauth-link" title="通过 Google 登录">
                                <svg viewBox="0 0 24 24" width="20" height="20">
                                    <defs>
                                        <clipPath id="google-g-clip">
                                            <path d="M12.24 10.285V14.4h6.806c-.275 1.765-2.056 5.174-6.806 5.174-4.095 0-7.439-3.389-7.439-7.574s3.345-7.574 7.439-7.574c2.33 0 3.891.989 4.785 1.849l3.254-3.138C18.189 1.186 15.479 0 12.24 0c-6.635 0-12 5.365-12 12s5.365 12 12 12c6.926 0 11.52-4.869 11.52-11.726 0-.788-.085-1.39-.189-1.989H12.24z"></path>
                                        </clipPath>
                                    </defs>
                                    <foreignObject width="24" height="24" clip-path="url(#google-g-clip)">
                                        <div xmlns="http://www.w3.org/1999/xhtml" style="width:100%;height:100%;background:conic-gradient(#FF4641,#FD5061 40deg,#FD5061 60deg,#3186FF 85deg,#3186FF 117deg,#00A5B7 142deg,#0EBC5F 167deg,#0EBC5F 200deg,#6CC500 226deg,#FFCC00 253deg,#FFD314 268deg,#FFCC00 292deg,#FF4641 327deg)"></div>
                                    </foreignObject>
                                </svg>
                            </a>
                            <a href="javascript:void(0)" class="oauth-link" title="通过 Apple 登录">
                                <svg viewBox="0.5 12.2 14 16.2" width="21" height="21" fill="currentColor" style="position:relative;top:-1px;">
                                    <path d="m13.0729 17.6825a3.61 3.61 0 0 0 -1.7248 3.0365 3.5132 3.5132 0 0 0 2.1379 3.2223 8.394 8.394 0 0 1 -1.0948 2.2618c-.6816.9812-1.3943 1.9623-2.4787 1.9623s-1.3633-.63-2.613-.63c-1.2187 0-1.6525.6507-2.644.6507s-1.6834-.9089-2.4787-2.0243a9.7842 9.7842 0 0 1 -1.6628-5.2776c0-3.0984 2.014-4.7405 3.9969-4.7405 1.0535 0 1.9314.6919 2.5924.6919.63 0 1.6112-.7333 2.8092-.7333a3.7579 3.7579 0 0 1 3.1604 1.5802zm-3.7284-2.8918a3.5615 3.5615 0 0 0 .8469-2.22 1.5353 1.5353 0 0 0 -.031-.32 3.5686 3.5686 0 0 0 -2.3445 1.2084 3.4629 3.4629 0 0 0 -.8779 2.1585 1.419 1.419 0 0 0 .031.2892 1.19 1.19 0 0 0 .2169.0207 3.0935 3.0935 0 0 0 2.1586-1.1368z"></path>
                                </svg>
                            </a>
                        </div>
                    </div>

                    <div class="activate-row">
                        <a href="activate.php" class="activate-link">新生账号激活</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 底部信息栏 -->
    
    <div class="auth-footer">
        <div class="auth-container">
            <div class="footer-top clearfix">
                <div class="footer-contact">
                    <span>信息化处邮箱：<?php echo e($footerCfg['footer_email']); ?></span>
                    <span>电话：<?php echo e($footerCfg['footer_phone']); ?></span>
                </div>
                <div class="footer-security">
                    <p class="security-warning" style="color:<?php echo e($footerCfg['security_color']); ?>;"><?php echo e($footerCfg['security_warning']); ?></p>
                    <p class="browser-tip">建议使用浏览器：edge、FireFox、Chrome、浏览器</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p><?php echo e($footerCfg['footer_copyright']); ?></p>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        (function () {
            // 兼容 IE8 的密码显隐切换
            var toggleBtn = document.getElementById('togglePasswordBtn');
            if (toggleBtn) {
                toggleBtn.onclick = function () {
                    var pwdInput = document.getElementById('password');
                    if (!pwdInput) return;
                    var targetType = pwdInput.type === 'password' ? 'text' : 'password';
                    try {
                        pwdInput.type = targetType;
                    } catch (e) {
                        var clone = document.createElement('input');
                        clone.id = 'password';
                        clone.name = 'password';
                        clone.type = targetType;
                        clone.value = pwdInput.value;
                        clone.setAttribute('placeholder', '请输入密码');
                        pwdInput.parentNode.replaceChild(clone, pwdInput);
                    }
                };
            }

            // 兼容 IE8 的标签切换
            var tabs = document.getElementsByTagName('button');
            var tabList = [];
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].className && tabs[i].className.indexOf('auth-tab') !== -1) {
                    tabList.push(tabs[i]);
                }
            }
            var formPassword = document.getElementById('form-password');
            var formEmail = document.getElementById('form-email');

            for (var j = 0; j < tabList.length; j++) {
                tabList[j].onclick = (function (clickedTab) {
                    return function () {
                        var target = clickedTab.getAttribute('data-tab');
                        for (var k = 0; k < tabList.length; k++) {
                            tabList[k].className = (tabList[k] === clickedTab) ? 'auth-tab is-active' : 'auth-tab';
                        }
                        if (target === 'email') {
                            formPassword.className = 'auth-form is-hidden';
                            formEmail.className = 'auth-form';
                        } else {
                            formEmail.className = 'auth-form is-hidden';
                            formPassword.className = 'auth-form';
                        }
                    };
                })(tabList[j]);
            }
        })();
            function sendLoginEmailCode() {
            var emailEl = document.getElementById('loginEmailInput');
            var btn = document.getElementById('sendLoginCodeBtn');
            var form = document.getElementById('form-email');
            if (!emailEl || !btn || !form) return;
            var email = (emailEl.value || '').replace(/^\s+|\s+$/g, '');
            if (!email) {
                alert('请先输入绑定的校园邮箱地址。');
                emailEl.focus();
                return;
            }
            var inputs = form.getElementsByTagName('input');
            var csrf = '';
            for (var i = 0; i < inputs.length; i++) {
                if (inputs[i].name === 'csrf_token') {
                    csrf = inputs[i].value;
                    break;
                }
            }
            btn.disabled = true;
            btn.innerHTML = '发送中';
            var xhr = window.XMLHttpRequest ? new XMLHttpRequest() : new ActiveXObject('Microsoft.XMLHTTP');
            xhr.open('POST', 'login.php?action=send_login_code', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                try {
                    var res = window.JSON ? JSON.parse(xhr.responseText) : eval('(' + xhr.responseText + ')');
                    alert(res.message || '');
                    if (res.ok) {
                        var sec = 60;
                        var t = setInterval(function () {
                            sec--;
                            if (sec <= 0) {
                                clearInterval(t);
                                btn.disabled = false;
                                btn.innerHTML = '获取验证码';
                            } else {
                                btn.innerHTML = sec + 's';
                            }
                        }, 1000);
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = '获取验证码';
                    }
                } catch (e) {
                    alert('发送请求失败');
                    btn.disabled = false;
                    btn.innerHTML = '获取验证码';
                }
            };
            xhr.send('email=' + encodeURIComponent(email) + '&csrf_token=' + encodeURIComponent(csrf));
        }
    </script>
</body>
</html>
