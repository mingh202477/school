<?php
require_once __DIR__ . '/../../app/models/mailer.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// 处理异步发送 6 位邮箱验证码请求（PHPMailer）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'send_email_code') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!csrf_verify()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => '请求已失效，请刷新页面重试。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($_SESSION['activate_flow']['user_id'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => '请先完成「信息校验」步骤。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    $res   = auth_send_email_verification_code(
        $email,
        'activate',
        (string) ($_SESSION['activate_flow']['real_name'] ?? ''),
        (string) ($_SESSION['activate_flow']['role'] ?? '')
    );
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['activate_flow']) || !is_array($_SESSION['activate_flow'])) {
    $_SESSION['activate_flow'] = [
        'max_step' => 1,
    ];
}

$flow  = &$_SESSION['activate_flow'];
$error = '';
$done  = false;

$requestedStep = isset($_GET['step']) ? (int) $_GET['step'] : (int) ($flow['current_step'] ?? 1);
if ($requestedStep < 1 || $requestedStep > 5) {
    $requestedStep = 1;
}
$step = min($requestedStep, (int) ($flow['max_step'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postStep = (int) ($_POST['step'] ?? 1);

    if (!csrf_verify()) {
        http_response_code(400);
        $error = '请求已失效，请刷新页面后重试。';
    } elseif ($postStep === 1) {
        $flow['max_step']     = max((int) ($flow['max_step'] ?? 1), 2);
        $flow['current_step'] = 2;
        header('Location: activate.php?step=2');
        exit;
    } elseif ($postStep === 2) {
        $account      = trim((string) ($_POST['username'] ?? ''));
        $realName     = trim((string) ($_POST['real_name'] ?? ''));
        $idCardType   = trim((string) ($_POST['id_card_type'] ?? '居民身份证'));
        $idCardNumber = trim((string) ($_POST['id_card_number'] ?? ''));

        $flow['draft_username']     = $account;
        $flow['draft_real_name']    = $realName;
        $flow['draft_id_card_type'] = $idCardType;

        $check = auth_verify_activation_step2($account, $realName, $idCardType, $idCardNumber);
        if (!$check['ok'] || !is_array($check['user'])) {
            $error = $check['error'];
            $step  = 2;
        } else {
            $flow['user_id']        = (int) $check['user']['id'];
            $flow['username']       = (string) $check['user']['username'];
            $flow['real_name']      = (string) $check['user']['real_name'];
            $flow['role']           = (string) ($check['user']['role'] ?? 'student');
            $flow['id_card_type']   = $idCardType;
            $flow['id_card_number'] = $idCardNumber;
            $flow['max_step']       = max((int) ($flow['max_step'] ?? 2), 3);
            $flow['current_step']   = 3;
            header('Location: activate.php?step=3');
            exit;
        }
    } elseif ($postStep === 3 && ((int) ($flow['max_step'] ?? 1)) >= 3) {
        $email     = trim((string) ($_POST['email'] ?? ''));
        $emailCode = trim((string) ($_POST['email_code'] ?? ''));
        $flow['draft_email'] = $email;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '请输入有效的电子邮箱地址。';
            $step  = 3;
        } elseif (!preg_match('/^\d{6}$/', $emailCode)) {
            $error = '请输入 6 位数字邮箱验证码。';
            $step  = 3;
        } elseif (!auth_verify_email_code($email, $emailCode, 'activate', true)) {
            $error = '邮箱验证码不正确或已过期，请重新获取。';
            $step  = 3;
        } else {
            $flow['email']        = $email;
            $flow['max_step']     = max((int) ($flow['max_step'] ?? 3), 4);
            $flow['current_step'] = 4;
            header('Location: activate.php?step=4');
            exit;
        }
    } elseif ($postStep === 4 && ((int) ($flow['max_step'] ?? 1)) >= 4) {
        $question = trim((string) ($_POST['security_question'] ?? ''));
        $answer   = trim((string) ($_POST['security_answer'] ?? ''));
        $flow['draft_question'] = $question;

        if ($question === '' || $answer === '') {
            $error = '请选择保密问题并填写保密答案。';
            $step  = 4;
        } elseif (mb_strlen($answer, 'UTF-8') < 2) {
            $error = '保密答案长度不能少于 2 个字符。';
            $step  = 4;
        } else {
            $flow['security_question'] = $question;
            $flow['security_answer']   = $answer;
            $flow['max_step']          = max((int) ($flow['max_step'] ?? 4), 5);
            $flow['current_step']      = 5;
            header('Location: activate.php?step=5');
            exit;
        }
    } elseif ($postStep === 5 && ((int) ($flow['max_step'] ?? 1)) >= 5) {
        $captcha = trim((string) ($_POST['captcha'] ?? ''));
        if ($captcha === '' || !auth_verify_captcha($captcha)) {
            $error = '图形验证码不正确或已过期，请重新输入。';
            $step  = 5;
        } else {
            $complete = auth_complete_activation(
                (int) ($flow['user_id'] ?? 0),
                (string) ($flow['email'] ?? ''),
                (string) ($flow['security_question'] ?? ''),
                (string) ($flow['security_answer'] ?? ''),
                (string) ($flow['id_card_number'] ?? '')
            );

            if (!$complete['ok']) {
                $error = $complete['error'];
                $step  = 5;
            } else {
                $activatedUsername = (string) ($flow['username'] ?? '');
                $activatedName     = (string) ($flow['real_name'] ?? '');
                $activatedEmail    = (string) ($flow['email'] ?? '');
                unset($_SESSION['activate_flow']);
                $done = true;
                $step = 5;
            }
        }
    }
}

$idCardTypes = [
    '居民身份证',
    '港澳台居民居住证',
    '护照',
    '军官证/士兵证',
    '其他有效证件',
];

$securityQuestions = [
    '您母亲的姓名是？',
    '您父亲的姓名是？',
    '您高中班主任的姓名是？',
    '您出生城市的名称是？',
    '您最难忘的童年好友姓名是？',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>账号激活 - 东营行知实验学校</title>
    <link rel="icon" href="../dyxz.ico">
    <link rel="stylesheet" href="public/css/login.css">
    <link rel="stylesheet" href="public/css/activate.css">
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
            <img class="brand-activate-title" src="public/images/activate-title.png" alt="账号激活">
        </div>
    </div>

    <!-- 账号激活主体区 -->
    <div class="activate-main">
        <div class="auth-container activate-container">
            <!-- 5步进度条 -->
            <div class="stepper">
                <?php
                $stepsMeta = [
                    1 => '确认激活通知',
                    2 => '信息校验',
                    3 => '绑定邮箱',
                    4 => '设置保密',
                    5 => '完成激活',
                ];
                foreach ($stepsMeta as $num => $label):
                    $stateClass = '';
                    if ($done || $num < $step) {
                        $stateClass = 'is-done';
                    } elseif ($num === $step) {
                        $stateClass = 'is-current';
                    }
                ?>
                    <div class="stepper-item <?php echo $stateClass; ?>">
                        <div class="stepper-circle"><?php echo $num; ?></div>
                        <div class="stepper-label"><?php echo e($label); ?></div>
                    </div>
                    <?php if ($num < 5): ?>
                        <div class="stepper-arrow">&rarr;</div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- 步骤内容面板 -->
            <div class="activate-stage">
                <?php if ($error !== ''): ?>
                    <p class="activate-alert"><?php echo e($error); ?></p>
                <?php endif; ?>

                <?php if ($done): ?>
                    <div class="activate-success">
                        <h2>账号激活成功！</h2>
                        <p>
                            账号 <strong><?php echo e($activatedUsername); ?></strong>（<?php echo e($activatedName); ?>）已完成激活，并绑定邮箱
                            <strong><?php echo e($activatedEmail); ?></strong>。
                        </p>
                        <p class="success-pwd-note">
                            您的默认初始密码为<strong>身份证号后6位（含X需大写）</strong>，请立即前往统一身份认证登录并修改密码。
                        </p>
                        <div class="activate-actions is-center">
                            <a href="login.php" class="step-btn">前往登录</a>
                        </div>
                    </div>

                <?php elseif ($step === 1): ?>
                    <form method="post" action="activate.php?step=1" class="step1-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="step" value="1">

                        <div class="notice-block">
                            <h2 class="notice-heading">账号激活须知：</h2>
                            <ol class="notice-list">
                                <li>1.请先激活账号，再进行统一身份认证登录。</li>
                                <li>2.激活成功的默认密码是您的身份证后6位，登录后请立刻修改（如有x请大写）。</li>
                                <li>3.如遇问题请联系系统管理员。</li>
                            </ol>
                        </div>

                        <div class="activate-actions is-right">
                            <button type="submit" class="step-btn">下一步</button>
                        </div>
                    </form>

                <?php elseif ($step === 2): ?>
                    <form method="post" action="activate.php?step=2" class="step-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="step" value="2">

                        <div class="form-row clearfix">
                            <label for="act-username" class="form-label"><span class="req">*</span>账号：</label>
                            <div class="form-field">
                                <input
                                    id="act-username"
                                    name="username"
                                    type="text"
                                    value="<?php echo e((string) ($flow['draft_username'] ?? $flow['username'] ?? '')); ?>"
                                    placeholder="请填写职工号、学号或考生号"
                                >
                            </div>
                        </div>

                        <div class="form-row clearfix">
                            <label for="act-realname" class="form-label"><span class="req">*</span>姓名：</label>
                            <div class="form-field">
                                <input
                                    id="act-realname"
                                    name="real_name"
                                    type="text"
                                    value="<?php echo e((string) ($flow['draft_real_name'] ?? $flow['real_name'] ?? '')); ?>"
                                    placeholder="请填写真实姓名"
                                >
                            </div>
                        </div>

                        <div class="form-row clearfix">
                            <label for="act-idtype" class="form-label"><span class="req">*</span>证件类型：</label>
                            <div class="form-field">
                                <?php $selectedType = (string) ($flow['draft_id_card_type'] ?? $flow['id_card_type'] ?? '居民身份证'); ?>
                                <select id="act-idtype" name="id_card_type">
                                    <?php foreach ($idCardTypes as $t): ?>
                                        <option value="<?php echo e($t); ?>" <?php echo $selectedType === $t ? 'selected' : ''; ?>>
                                            <?php echo e($t); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row clearfix">
                            <label for="act-idnum" class="form-label"><span class="req">*</span>证件号码：</label>
                            <div class="form-field">
                                <input
                                    id="act-idnum"
                                    name="id_card_number"
                                    type="text"
                                    maxlength="32"
                                    placeholder="请填写证件号码（身份证末尾是X的需要大写）"
                                    autocomplete="off"
                                >
                            </div>
                        </div>

                        <div class="activate-actions is-between clearfix">
                            <a href="activate.php?step=1" class="step-btn is-secondary">上一步</a>
                            <button type="submit" class="step-btn">下一步</button>
                        </div>
                    </form>

                <?php elseif ($step === 3): ?>
                    <form method="post" action="activate.php?step=3" class="step-form" id="emailStepForm">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="step" value="3">

                        <div class="form-row clearfix">
                            <label for="act-email" class="form-label"><span class="req">*</span>电子邮箱：</label>
                            <div class="form-field">
                                <div class="inline-input-btn clearfix">
                                    <input
                                        id="act-email"
                                        name="email"
                                        type="text"
                                        value="<?php echo e((string) ($flow['draft_email'] ?? $flow['email'] ?? '')); ?>"
                                        placeholder="请输入您的常用电子邮箱"
                                    >
                                    <button type="button" id="sendEmailCodeBtn" class="code-action-btn">发送验证码</button>
                                </div>
                                <span class="field-hint" id="emailSendFeedback"></span>
                            </div>
                        </div>

                        <div class="form-row clearfix">
                            <label for="act-email-code" class="form-label"><span class="req">*</span>邮箱验证码：</label>
                            <div class="form-field">
                                <input
                                    id="act-email-code"
                                    name="email_code"
                                    type="text"
                                    maxlength="6"
                                    placeholder="请输入收到的 6 位数字验证码"
                                    autocomplete="off"
                                >
                            </div>
                        </div>

                        <div class="activate-actions is-between clearfix">
                            <a href="activate.php?step=2" class="step-btn is-secondary">上一步</a>
                            <button type="submit" class="step-btn">下一步</button>
                        </div>
                    </form>

                <?php elseif ($step === 4): ?>
                    <form method="post" action="activate.php?step=4" class="step-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="step" value="4">

                        <div class="form-row clearfix">
                            <label for="act-question" class="form-label"><span class="req">*</span>保密问题：</label>
                            <div class="form-field">
                                <?php $selectedQ = (string) ($flow['draft_question'] ?? $flow['security_question'] ?? $securityQuestions[0]); ?>
                                <select id="act-question" name="security_question">
                                    <?php foreach ($securityQuestions as $q): ?>
                                        <option value="<?php echo e($q); ?>" <?php echo $selectedQ === $q ? 'selected' : ''; ?>>
                                            <?php echo e($q); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row clearfix">
                            <label for="act-answer" class="form-label"><span class="req">*</span>保密答案：</label>
                            <div class="form-field">
                                <input
                                    id="act-answer"
                                    name="security_answer"
                                    type="text"
                                    placeholder="请填写保密问题答案（至少2个字符）"
                                    autocomplete="off"
                                >
                            </div>
                        </div>

                        <div class="activate-actions is-between clearfix">
                            <a href="activate.php?step=3" class="step-btn is-secondary">上一步</a>
                            <button type="submit" class="step-btn">下一步</button>
                        </div>
                    </form>

                <?php elseif ($step === 5): ?>
                    <form method="post" action="activate.php?step=5" class="step-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="step" value="5">

                        <div class="form-row clearfix">
                            <label for="act-captcha" class="form-label"><span class="req">*</span>图形验证码：</label>
                            <div class="form-field">
                                <div class="captcha-verify-row clearfix">
                                    <input
                                        id="act-captcha"
                                        name="captcha"
                                        type="text"
                                        maxlength="5"
                                        placeholder="请输入右侧图形验证码"
                                        autocomplete="off"
                                    >
                                    <img
                                        class="captcha-image"
                                        src="captcha.php?v=<?php echo time(); ?>"
                                        alt="图形验证码，点击可刷新"
                                        title="点击刷新验证码"
                                        onclick="this.src='captcha.php?v=' + (new Date().getTime())"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="activate-actions is-between clearfix">
                            <a href="activate.php?step=4" class="step-btn is-secondary">上一步</a>
                            <button type="submit" class="step-btn">完成激活</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 底部页脚 -->
    
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
            var sendBtn = document.getElementById('sendEmailCodeBtn');
            var emailInput = document.getElementById('act-email');
            var feedback = document.getElementById('emailSendFeedback');
            var form = document.getElementById('emailStepForm');

            function trimStr(s) {
                return (s || '').replace(/^\s+|\s+$/g, '');
            }

            if (sendBtn && emailInput && form) {
                var timer = null;
                var startCooldown = function (seconds) {
                    var remain = seconds;
                    sendBtn.disabled = true;
                    sendBtn.innerHTML = remain + 's 后重发';
                    timer = setInterval(function () {
                        remain--;
                        if (remain <= 0) {
                            clearInterval(timer);
                            sendBtn.disabled = false;
                            sendBtn.innerHTML = '发送验证码';
                        } else {
                            sendBtn.innerHTML = remain + 's 后重发';
                        }
                    }, 1000);
                };

                sendBtn.onclick = function () {
                    var email = trimStr(emailInput.value);
                    if (!email) {
                        feedback.innerHTML = '请先输入电子邮箱地址。';
                        feedback.className = 'field-hint is-warn';
                        emailInput.focus();
                        return;
                    }

                    var inputs = form.getElementsByTagName('input');
                    var csrfToken = '';
                    for (var i = 0; i < inputs.length; i++) {
                        if (inputs[i].name === 'csrf_token') {
                            csrfToken = inputs[i].value;
                            break;
                        }
                    }

                    sendBtn.disabled = true;
                    sendBtn.innerHTML = '发送中...';

                    // 兼容 IE8 ~ IE11 的 XMLHttpRequest
                    var xhr = window.XMLHttpRequest ? new XMLHttpRequest() : new ActiveXObject('Microsoft.XMLHTTP');
                    xhr.open('POST', 'activate.php?action=send_email_code', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onreadystatechange = function () {
                        if (xhr.readyState !== 4) return;
                        try {
                            var data = window.JSON ? JSON.parse(xhr.responseText) : eval('(' + xhr.responseText + ')');
                            feedback.innerHTML = data.message || '';
                            if (data.ok) {
                                feedback.className = 'field-hint is-ok';
                                startCooldown(60);
                            } else {
                                feedback.className = 'field-hint is-warn';
                                sendBtn.disabled = false;
                                sendBtn.innerHTML = '发送验证码';
                            }
                        } catch (e) {
                            feedback.innerHTML = '请求发送失败，请检查网络连接。';
                            feedback.className = 'field-hint is-warn';
                            sendBtn.disabled = false;
                            sendBtn.innerHTML = '发送验证码';
                        }
                    };
                    var payload = 'email=' + encodeURIComponent(email) + '&csrf_token=' + encodeURIComponent(csrfToken);
                    xhr.send(payload);
                };
            }
        })();
    </script>
</body>
</html>
