<?php
require_once __DIR__ . '/../../app/models/mailer.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// 异步发送重置密码邮箱验证码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'send_reset_code') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!csrf_verify()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => '请求已失效，请刷新页面重试。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $email = trim((string) ($_POST['email'] ?? ''));
    $stmt = $pdo->prepare("SELECT `id`, `real_name`, `role` FROM `users` WHERE `email` = :e LIMIT 1");
    $stmt->execute([':e' => $email]);
    $u = $stmt->fetch();
    if (!$u) {
        echo json_encode(['ok' => false, 'message' => '该邮箱未绑定任何校内账号。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $res = auth_send_email_verification_code($email, 'reset_pwd', (string) ($u['real_name'] ?? ''), (string) ($u['role'] ?? 'student'));
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

// 异步查询账号绑定的密保问题
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'query_question') {
    header('Content-Type: application/json; charset=UTF-8');
    $acc = trim((string) ($_POST['account'] ?? ''));
    $u = auth_find_user_by_identifier($acc);
    if (!$u || empty($u['security_question'])) {
        echo json_encode(['ok' => false, 'question' => '未查询到该账号设置的密保问题（请核对账号或改用邮箱找回）'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => true, 'question' => (string) $u['security_question']], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$mode = (string) ($_GET['mode'] ?? 'email'); // 'email' | 'security' | 'change'
if (!in_array($mode, ['email', 'security', 'change'], true)) {
    $mode = 'email';
}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postMode = (string) ($_POST['mode'] ?? $mode);
    $mode = $postMode;
    $captcha = trim((string) ($_POST['captcha'] ?? ''));

    if (!csrf_verify()) {
        $err = '请求已失效，请刷新页面重试。';
    } elseif ($captcha === '' || !auth_verify_captcha($captcha)) {
        $err = '图形验证码不正确或已过期，请重新输入。';
    } else {
        $newPwd  = (string) ($_POST['new_password'] ?? '');
        $newPwd2 = (string) ($_POST['confirm_password'] ?? '');
        if ($newPwd !== $newPwd2) {
            $err = '两次输入的新密码不一致。';
        } elseif ($postMode === 'email') {
            $email = trim((string) ($_POST['email'] ?? ''));
            $code  = trim((string) ($_POST['email_code'] ?? ''));
            $res   = auth_reset_password_by_email($email, $code, $newPwd);
            if ($res['ok']) {
                $msg = '密码重置成功！请使用新密码登录。';
            } else {
                $err = $res['error'];
            }
        } elseif ($postMode === 'security') {
            $acc = trim((string) ($_POST['account'] ?? ''));
            $ans = trim((string) ($_POST['security_answer'] ?? ''));
            $res = auth_reset_password_by_security($acc, $ans, $newPwd);
            if ($res['ok']) {
                $msg = '密码重置成功！请使用新密码登录。';
            } else {
                $err = $res['error'];
            }
        } elseif ($postMode === 'change') {
            $acc    = trim((string) ($_POST['account'] ?? ''));
            $oldPwd = (string) ($_POST['old_password'] ?? '');
            $res    = auth_change_password_with_old($acc, $oldPwd, $newPwd);
            if ($res['ok']) {
                $msg = '密码修改成功！请使用新密码重新登录。';
            } else {
                $err = $res['error'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>找回/修改密码凭证 - 东营行知实验学校</title>
    <link rel="icon" href="../dyxz.ico">
    <link rel="stylesheet" href="public/css/login.css">
    <link rel="stylesheet" href="public/css/activate.css">
</head>
<?php $footerCfg = auth_get_site_footer_config(); ?>
<body class="auth-body">
    <div class="auth-header">
        <div class="auth-container auth-header-inner clearfix">
            <a href="../index.php" class="brand-logo" title="返回学校首页">
                <img src="../<?php echo e($footerCfg['logo_2']); ?>" alt="东营行知实验学校">
            </a>
            <span class="brand-divider"></span>
            <img class="brand-auth-title" src="public/images/auth-title.png" alt="统一身份认证">
        </div>
    </div>

    <div class="activate-main">
        <div class="auth-container" style="width:680px;margin:0 auto;">
            <table border="1" cellspacing="0" cellpadding="6" width="100%">
                <tr bgcolor="#eeeeee">
                    <td align="center" width="33%">
                        <a href="forgot.php?mode=email"><?php echo $mode === 'email' ? '<b>[1. 绑定邮箱验证码找回]</b>' : '1. 绑定邮箱验证码找回'; ?></a>
                    </td>
                    <td align="center" width="33%">
                        <a href="forgot.php?mode=security"><?php echo $mode === 'security' ? '<b>[2. 密保问题找回]</b>' : '2. 密保问题找回'; ?></a>
                    </td>
                    <td align="center" width="34%">
                        <a href="forgot.php?mode=change"><?php echo $mode === 'change' ? '<b>[3. 根据原密码修改密码]</b>' : '3. 根据原密码修改密码'; ?></a>
                    </td>
                </tr>
            </table>

            <br>

            <?php if ($msg !== ''): ?>
                <div style="border:1px solid #008800;background:#e6ffe6;color:#006600;padding:8px;margin-bottom:12px;">
                    <?php echo e($msg); ?> &nbsp; <a href="login.php"><b>[点击此处返回登录页]</b></a>
                </div>
            <?php endif; ?>

            <?php if ($err !== ''): ?>
                <div class="activate-alert" style="width:100%;"><?php echo e($err); ?></div>
            <?php endif; ?>

            <form method="post" action="forgot.php?mode=<?php echo e($mode); ?>" id="forgotForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="mode" value="<?php echo e($mode); ?>">

                <table border="1" cellspacing="0" cellpadding="6" width="100%">
                    <?php if ($mode === 'email'): ?>
                        <tr>
                            <th width="150" align="right">*绑定电子邮箱：</th>
                            <td>
                                <input type="text" name="email" id="resetEmailInput" size="30" placeholder="请输入激活时绑定的邮箱">
                                <button type="button" id="sendResetCodeBtn" onclick="sendResetEmailCode()">发送验证码</button>
                                <span id="resetEmailFeedback" style="color:#c00;"></span>
                            </td>
                        </tr>
                        <tr>
                            <th align="right">*6位邮箱验证码：</th>
                            <td><input type="text" name="email_code" size="14" maxlength="6" placeholder="请输入6位数字验证码"></td>
                        </tr>
                    <?php elseif ($mode === 'security'): ?>
                        <tr>
                            <th width="150" align="right">*账号/学号/工号：</th>
                            <td>
                                <input type="text" name="account" id="secAccountInput" size="28" placeholder="请输入账号或身份证号">
                                <button type="button" onclick="loadSecurityQuestion()">查询密保问题</button>
                            </td>
                        </tr>
                        <tr>
                            <th align="right">设置的密保问题：</th>
                            <td><b id="secQuestionDisplay" style="color:#3e57c2;">（请先输入账号并点击“查询密保问题”）</b></td>
                        </tr>
                        <tr>
                            <th align="right">*密保问题答案：</th>
                            <td><input type="text" name="security_answer" size="30" placeholder="请输入激活时填写的密保答案"></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th width="150" align="right">*账号/邮箱/身份证：</th>
                            <td>
                                <input type="text" name="account" size="30" value="<?php echo e((string) ($_SESSION['auth_user'] ?? '')); ?>" placeholder="请输入账号、邮箱或身份证号">
                            </td>
                        </tr>
                        <tr>
                            <th align="right">*当前原密码：</th>
                            <td><input type="password" name="old_password" size="30" placeholder="未修改过则为身份证后6位(X大写)"></td>
                        </tr>
                    <?php endif; ?>

                    <tr>
                        <th align="right">*设置新密码：</th>
                        <td><input type="password" name="new_password" size="30" placeholder="请输入不少于6位的新密码"></td>
                    </tr>
                    <tr>
                        <th align="right">*确认新密码：</th>
                        <td><input type="password" name="confirm_password" size="30" placeholder="请再次输入新密码"></td>
                    </tr>
                    <tr>
                        <th align="right">*图形验证码：</th>
                        <td>
                            <input type="text" name="captcha" size="12" maxlength="5" placeholder="图形验证码" autocomplete="off">
                            <img src="captcha.php?v=<?php echo time(); ?>" alt="点击刷新" title="点击刷新" style="vertical-align:middle;border:1px solid #999;cursor:pointer;height:32px;" onclick="this.src='captcha.php?v='+(new Date().getTime())">
                        </td>
                    </tr>
                    <tr>
                        <th></th>
                        <td>
                            <button type="submit" class="step-btn" style="width:140px;height:36px;line-height:36px;font-size:15px;">提交确认</button>
                            &nbsp;&nbsp;
                            <a href="login.php">[返回登录页面]</a>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
    </div>

    
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
        function getCsrfToken() {
            var inputs = document.getElementById('forgotForm').getElementsByTagName('input');
            for (var i = 0; i < inputs.length; i++) {
                if (inputs[i].name === 'csrf_token') return inputs[i].value;
            }
            return '';
        }

        function sendResetEmailCode() {
            var emailEl = document.getElementById('resetEmailInput');
            var btn = document.getElementById('sendResetCodeBtn');
            var fb = document.getElementById('resetEmailFeedback');
            var email = (emailEl.value || '').replace(/^\s+|\s+$/g, '');
            if (!email) {
                fb.innerHTML = '请先输入绑定邮箱';
                return;
            }
            btn.disabled = true;
            btn.innerHTML = '发送中...';
            var xhr = window.XMLHttpRequest ? new XMLHttpRequest() : new ActiveXObject('Microsoft.XMLHTTP');
            xhr.open('POST', 'forgot.php?action=send_reset_code', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                try {
                    var res = window.JSON ? JSON.parse(xhr.responseText) : eval('(' + xhr.responseText + ')');
                    fb.innerHTML = res.message || '';
                    fb.style.color = res.ok ? '#008800' : '#cc0000';
                    if (res.ok) {
                        var sec = 60;
                        var t = setInterval(function () {
                            sec--;
                            if (sec <= 0) {
                                clearInterval(t);
                                btn.disabled = false;
                                btn.innerHTML = '发送验证码';
                            } else {
                                btn.innerHTML = sec + 's后重发';
                            }
                        }, 1000);
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = '发送验证码';
                    }
                } catch (e) {
                    fb.innerHTML = '请求失败';
                    btn.disabled = false;
                    btn.innerHTML = '发送验证码';
                }
            };
            xhr.send('email=' + encodeURIComponent(email) + '&csrf_token=' + encodeURIComponent(getCsrfToken()));
        }

        function loadSecurityQuestion() {
            var acc = document.getElementById('secAccountInput').value;
            var disp = document.getElementById('secQuestionDisplay');
            var xhr = window.XMLHttpRequest ? new XMLHttpRequest() : new ActiveXObject('Microsoft.XMLHTTP');
            xhr.open('POST', 'forgot.php?action=query_question', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                try {
                    var res = window.JSON ? JSON.parse(xhr.responseText) : eval('(' + xhr.responseText + ')');
                    disp.innerHTML = res.question || '未找到密保问题';
                    disp.style.color = res.ok ? '#3e57c2' : '#cc0000';
                } catch (e) {
                    disp.innerHTML = '查询失败';
                }
            };
            xhr.send('account=' + encodeURIComponent(acc));
        }
    </script>
</body>
</html>
