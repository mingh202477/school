<?php
require_once __DIR__ . '/../app/models/title.php';
require_once __DIR__ . '/../app/models/keywords.php';
require_once __DIR__ . '/../app/models/logo_1.php';
require_once __DIR__ . '/../app/models/logo_2.php';
require_once __DIR__ . '/../app/models/ico.php';
require_once __DIR__ . '/../app/models/icp.php';
require_once __DIR__ . '/../app/models/address.php';
require_once __DIR__ . '/../app/models/postal_code.php';
require_once __DIR__ . '/../app/models/auth.php';
require_once __DIR__ . '/../app/models/traffic.php';
require_once __DIR__ . '/../app/models/top_links.php';
require_once __DIR__ . '/../app/models/nav_links.php';

function e($str): string
{
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

// 确保书记/校长信箱表存在并初始化示例数据
$pdo->exec("
CREATE TABLE IF NOT EXISTS `leader_mailbox` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '信件ID',
  `query_code` varchar(24) NOT NULL COMMENT '信件查询码',
  `box_type` enum('shuji','xiaozhang') NOT NULL DEFAULT 'xiaozhang' COMMENT '信箱类型：shuji书记信箱 xiaozhang校长信箱',
  `sender_name` varchar(64) NOT NULL COMMENT '写信人姓名',
  `sender_role` varchar(32) NOT NULL DEFAULT '学生' COMMENT '写信人身份',
  `contact_email` varchar(128) DEFAULT NULL COMMENT '联系邮箱',
  `contact_phone` varchar(32) DEFAULT NULL COMMENT '联系电话',
  `subject` varchar(200) NOT NULL COMMENT '信件标题',
  `content` text NOT NULL COMMENT '信件内容',
  `is_public` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否允许公开选登：1允许 0不公开',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '处理状态：0待办 1已办结回复',
  `reply_content` text DEFAULT NULL COMMENT '批复/回复内容',
  `reply_dept` varchar(64) DEFAULT NULL COMMENT '答复部门',
  `replied_at` datetime DEFAULT NULL COMMENT '回复时间',
  `ip_address` varchar(45) DEFAULT NULL COMMENT '提交IP',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '来信时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_query_code` (`query_code`),
  KEY `idx_box_status` (`box_type`, `status`, `is_public`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='书记信箱与校长信箱表';
");

$cnt = (int) $pdo->query("SELECT COUNT(*) FROM `leader_mailbox`")->fetchColumn();
if ($cnt === 0) {
    $pdo->exec("
        INSERT INTO `leader_mailbox` (`query_code`, `box_type`, `sender_name`, `sender_role`, `contact_email`, `subject`, `content`, `is_public`, `status`, `reply_content`, `reply_dept`, `replied_at`)
        VALUES
        ('XZ2026050101', 'xiaozhang', '李*同学', '学生', 'student@dyxz.edu.cn', '关于延长学校图书馆周末自习室开放时间的建议', '尊敬的校长您好，临近考试周，希望学校能将周末图书馆二楼自习室的闭馆时间延长1小时，方便同学们复习备考。', 1, 1, '同学你好！感谢你对学校教学服务工作的关注与建议。经校长办公会商议并协调总务处、图书馆，自本周起图书馆二楼自习区周末开放时间延长至晚 21:30。', '校长办公室 / 图书馆', '2026-05-18 16:20:00'),
        ('SJ2026050102', 'shuji', '王*老师', '教职工', 'teacher@dyxz.edu.cn', '关于增设教职工电动车充电桩遮雨棚的建议', '尊敬的书记您好，西侧车棚充电桩在雨天容易淋雨存在隐患，建议加装防雨棚。', 1, 1, '老师您好！学校党委高度重视校园安全工作，已安排后勤保障处于本周内完成西侧非机动车充电区防雨棚加装及线路巡检。', '校党委办公室 / 后勤处', '2026-05-19 10:15:00');
    ");
}

$traffic_stats = traffic_record_and_get_stats('/mailbox.php');

$boxType = (string) ($_GET['type'] ?? 'xiaozhang');
if (!in_array($boxType, ['shuji', 'xiaozhang'], true)) {
    $boxType = 'xiaozhang';
}
$boxTitle = $boxType === 'shuji' ? '书记信箱' : '校长信箱';

// 三个独立子页面视图：'list' (已办结公开信件选登) | 'write' (我要写信) | 'query' (信件办理进度查询)
$view = (string) ($_GET['view'] ?? 'list');
if (isset($_GET['qcode']) && trim((string) $_GET['qcode']) !== '') {
    $view = 'query';
}
if (!in_array($view, ['list', 'write', 'query'], true)) {
    $view = 'list';
}

$msg = '';
$err = '';
$generatedCode = '';
$queryResult = null;

// 1. 处理信件办理进度查询（仅在 view=query 页面下执行）
if ($view === 'query' && isset($_GET['qcode']) && trim((string) $_GET['qcode']) !== '') {
    $qc = strtoupper(trim((string) $_GET['qcode']));
    $qStmt = $pdo->prepare("SELECT * FROM `leader_mailbox` WHERE `query_code` = :qc LIMIT 1");
    $qStmt->execute([':qc' => $qc]);
    $queryResult = $qStmt->fetch();
    if (!$queryResult) {
        $err = "未查询到编号为「{$qc}」的信件，请核对查询码是否正确。";
    }
}

// 2. 处理写信提交（仅在 view=write 页面下执行）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'submit_letter') {
    $view = 'write';
    $boxType = (string) ($_POST['box_type'] ?? $boxType);
    if (!in_array($boxType, ['shuji', 'xiaozhang'], true)) {
        $boxType = 'xiaozhang';
    }
    $boxTitle = $boxType === 'shuji' ? '书记信箱' : '校长信箱';

    $senderName  = trim((string) ($_POST['sender_name'] ?? ''));
    $senderRole  = trim((string) ($_POST['sender_role'] ?? '学生'));
    $email       = trim((string) ($_POST['contact_email'] ?? ''));
    $phone       = trim((string) ($_POST['contact_phone'] ?? ''));
    $subject     = trim((string) ($_POST['subject'] ?? ''));
    $content     = trim((string) ($_POST['content'] ?? ''));
    $isPublic    = (int) ($_POST['is_public'] ?? 1) === 1 ? 1 : 0;
    $captcha     = trim((string) ($_POST['captcha'] ?? ''));

    if (!csrf_verify()) {
        $err = '请求已失效，请刷新页面后重试。';
    } elseif ($captcha === '' || !auth_verify_captcha($captcha)) {
        $err = '图形验证码不正确或已过期，请重新填写。';
    } elseif ($senderName === '' || $subject === '' || $content === '') {
        $err = '请完整填写您的姓名、信件主题和信件内容。';
    } elseif ($email === '' && $phone === '') {
        $err = '请至少填写一项联系方式（电子邮箱或联系电话），以便向您反馈处理结果。';
    } else {
        $prefix = $boxType === 'shuji' ? 'SJ' : 'XZ';
        $generatedCode = $prefix . date('Ymd') . random_int(1000, 9999);
        $ip = auth_client_ip();

        $ins = $pdo->prepare("
            INSERT INTO `leader_mailbox` (`query_code`, `box_type`, `sender_name`, `sender_role`, `contact_email`, `contact_phone`, `subject`, `content`, `is_public`, `status`, `ip_address`)
            VALUES (:qc, :bt, :sn, :sr, :em, :ph, :sub, :cnt, :pub, 0, :ip)
        ");
        $ins->execute([
            ':qc'  => $generatedCode,
            ':bt'  => $boxType,
            ':sn'  => $senderName,
            ':sr'  => $senderRole,
            ':em'  => $email,
            ':ph'  => $phone,
            ':sub' => $subject,
            ':cnt' => $content,
            ':pub' => $isPublic,
            ':ip'  => $ip,
        ]);
        $msg = "您的来信已成功投递至【{$boxTitle}】！请牢记您的信件查询码：{$generatedCode}";
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo e($boxTitle); ?> - <?php echo e($title); ?></title>
    <link rel="icon" href="<?php echo e($ico); ?>" type="image/x-icon">
    <link rel="stylesheet" href="public/css/index.css">
    <style type="text/css">
        .mailbox-wrap {
            width: 1100px;
            max-width: 96%;
            margin: 24px auto 40px;
            background: #fff;
            border: 1px solid #b8c6d6;
            padding: 18px 22px 28px;
        }
        .mailbox-header-bar {
            border-bottom: 2px solid #16569a;
            padding-bottom: 10px;
            margin-bottom: 16px;
        }
        .mailbox-type-btn {
            display: inline-block;
            padding: 7px 20px;
            margin-right: 6px;
            font-size: 16px;
            font-weight: bold;
            border: 1px solid #16569a;
            background: #f0f5fb;
            color: #16569a;
        }
        .mailbox-type-btn.is-active {
            background: #16569a;
            color: #fff;
        }
        .mailbox-subnav {
            margin-bottom: 18px;
            background: #f4f7fb;
            border: 1px solid #d5e0ec;
            padding: 8px 12px;
        }
        .mailbox-subnav a {
            display: inline-block;
            padding: 6px 16px;
            margin-right: 10px;
            font-size: 14px;
            color: #16569a;
            border: 1px solid #b8c6d6;
            background: #fff;
        }
        .mailbox-subnav a.is-current {
            background: #16569a;
            color: #fff;
            border-color: #16569a;
            font-weight: bold;
        }
        .mailbox-notice {
            background: #f7f9fc;
            border: 1px solid #d5e0ec;
            padding: 12px 16px;
            margin-bottom: 16px;
            font-size: 13px;
            line-height: 1.8;
            color: #333;
        }
        .mailbox-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .mailbox-table th {
            background: #eef3f9;
            color: #16569a;
            text-align: right;
            padding: 8px 10px;
            border: 1px solid #b8c6d6;
            width: 140px;
        }
        .mailbox-table td {
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
        }
        .mailbox-table input[type="text"],
        .mailbox-table select,
        .mailbox-table textarea {
            font-family: "Microsoft YaHei", SimSun, sans-serif;
            font-size: 13px;
            border: 1px solid #9ab0c7;
            padding: 4px 6px;
        }
        .pub-list-table th {
            text-align: left;
            width: auto;
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/public/lib/head.php'; ?>

<div class="mailbox-wrap">
    <!-- 顶部：书记信箱 / 校长信箱切换 -->
    <div class="mailbox-header-bar">
        <a href="mailbox.php?type=shuji&view=<?php echo e($view); ?>" class="mailbox-type-btn <?php echo $boxType === 'shuji' ? 'is-active' : ''; ?>">书记信箱</a>
        <a href="mailbox.php?type=xiaozhang&view=<?php echo e($view); ?>" class="mailbox-type-btn <?php echo $boxType === 'xiaozhang' ? 'is-active' : ''; ?>">校长信箱</a>
        <span style="float:right;line-height:34px;font-size:13px;">
            <a href="index.php">&lt;&lt; 返回学校首页</a>
        </span>
    </div>

    <!-- 二级功能分页导航（三个功能彻底拆分为独立页面视图） -->
    <div class="mailbox-subnav">
        <a href="mailbox.php?type=<?php echo e($boxType); ?>&view=list" class="<?php echo $view === 'list' ? 'is-current' : ''; ?>">
            <?php echo e($boxTitle); ?> · 已办结公开信件选登
        </a>
        <a href="mailbox.php?type=<?php echo e($boxType); ?>&view=write" class="<?php echo $view === 'write' ? 'is-current' : ''; ?>">
            我要写信
        </a>
        <a href="mailbox.php?type=<?php echo e($boxType); ?>&view=query" class="<?php echo $view === 'query' ? 'is-current' : ''; ?>">
            信件办理进度查询
        </a>
    </div>

    <?php if ($msg !== ''): ?>
        <div style="border:2px solid #008800;background:#e6ffe6;color:#006600;padding:12px;margin-bottom:16px;font-size:14px;font-weight:bold;">
            <?php echo e($msg); ?>
            <?php if ($generatedCode !== ''): ?>
                &nbsp;&nbsp;<a href="mailbox.php?type=<?php echo e($boxType); ?>&view=query&qcode=<?php echo e($generatedCode); ?>" style="color:#16569a;text-decoration:underline;">[点击直接查看办理进度]</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($err !== ''): ?>
        <div style="border:1px solid #cc0000;background:#ffe6e6;color:#cc0000;padding:10px;margin-bottom:16px;font-size:13px;font-weight:bold;">
            <?php echo e($err); ?>
        </div>
    <?php endif; ?>

    <?php if ($view === 'list'): ?>
        <!-- ==================== 独立页面 1：已办结公开信件选登 ==================== -->
        <?php
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 8;
        $cStmt = $pdo->prepare("SELECT COUNT(*) FROM `leader_mailbox` WHERE `box_type` = :bt AND `status` = 1 AND `is_public` = 1");
        $cStmt->execute([':bt' => $boxType]);
        $totalPub = (int) $cStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalPub / $pageSize));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $pageSize;

        $pubStmt = $pdo->prepare("
            SELECT * FROM `leader_mailbox`
            WHERE `box_type` = :bt AND `status` = 1 AND `is_public` = 1
            ORDER BY `id` DESC LIMIT {$offset}, {$pageSize}
        ");
        $pubStmt->execute([':bt' => $boxType]);
        $publicLetters = $pubStmt->fetchAll();
        ?>
        <div class="mailbox-notice">
            <b>【<?php echo e($boxTitle); ?>来信须知】</b><br>
            1. 欢迎广大师生员工、学生家长及社会各界人士就学校改革发展、教育教学、管理服务等方面提出宝贵意见和建议。<br>
            2. 如需向<?php echo e($boxTitle); ?>反映问题，请点击上方 <b><a href="mailbox.php?type=<?php echo e($boxType); ?>&view=write" style="color:#16569a;text-decoration:underline;">[我要写信]</a></b>；已投递信件请点击 <b><a href="mailbox.php?type=<?php echo e($boxType); ?>&view=query" style="color:#16569a;text-decoration:underline;">[信件办理进度查询]</a></b>。
        </div>

        <table border="1" cellspacing="0" cellpadding="6" class="mailbox-table pub-list-table">
            <tr>
                <th width="115">查询码</th>
                <th>信件主题 / 批复摘要</th>
                <th width="95">来信人</th>
                <th width="140">答复部门</th>
                <th width="145">答复时间</th>
            </tr>
            <?php if (empty($publicLetters)): ?>
                <tr>
                    <td colspan="5" align="center">暂无公开选登信件</td>
                </tr>
            <?php else: ?>
                <?php foreach ($publicLetters as $pl): ?>
                    <tr>
                        <td>
                            <a href="mailbox.php?type=<?php echo e($boxType); ?>&view=query&qcode=<?php echo e((string) $pl['query_code']); ?>" style="color:#16569a;font-weight:bold;text-decoration:underline;">
                                <?php echo e((string) $pl['query_code']); ?>
                            </a>
                        </td>
                        <td>
                            <div><b>【来信】<?php echo e((string) $pl['subject']); ?></b></div>
                            <div style="margin-top:4px;color:#444;font-size:12px;">
                                <b>【批复】</b><?php echo e((string) $pl['reply_content']); ?>
                            </div>
                        </td>
                        <td><?php echo e((string) $pl['sender_name']); ?><br><font color="#666">(<?php echo e((string) $pl['sender_role']); ?>)</font></td>
                        <td><?php echo e((string) ($pl['reply_dept'] ?: '校办')); ?></td>
                        <td><?php echo e((string) $pl['replied_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>

        <div style="margin-top:12px;font-size:13px;">
            <?php
            $basePager = 'mailbox.php?type=' . urlencode($boxType) . '&view=list';
            $prevP = max(1, $page - 1);
            $nextP = min($totalPages, $page + 1);
            ?>
            <a href="<?php echo e($basePager . '&page=' . $prevP); ?>">[上一页]</a>
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php if ($p === $page): ?>
                    <b><?php echo $p; ?></b>
                <?php else: ?>
                    <a href="<?php echo e($basePager . '&page=' . $p); ?>"><?php echo $p; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <a href="<?php echo e($basePager . '&page=' . $nextP); ?>">[下一页]</a>
            &nbsp;（共 <?php echo $totalPub; ?> 封公开信件）
        </div>

    <?php elseif ($view === 'write'): ?>
        <!-- ==================== 独立页面 2：我要写信 ==================== -->
        <div class="mailbox-notice">
            <b>【写信投递说明】</b>请填写真实姓名与有效联系方式，以便学校相关部门核实情况并及时向您反馈处理结果。本平台为非涉密平台，严禁填报任何涉密信息。
        </div>

        <form method="post" action="mailbox.php?type=<?php echo e($boxType); ?>&view=write">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="form_action" value="submit_letter">

            <table border="1" cellspacing="0" cellpadding="6" class="mailbox-table">
                <tr>
                    <th><font color="red">*</font>投递信箱：</th>
                    <td>
                        <label><input type="radio" name="box_type" value="shuji" <?php echo $boxType === 'shuji' ? 'checked' : ''; ?>> 书记信箱</label>
                        &nbsp;&nbsp;&nbsp;
                        <label><input type="radio" name="box_type" value="xiaozhang" <?php echo $boxType === 'xiaozhang' ? 'checked' : ''; ?>> 校长信箱</label>
                    </td>
                    <th><font color="red">*</font>您的身份：</th>
                    <td>
                        <select name="sender_role">
                            <option value="学生">在校学生</option>
                            <option value="教职工">教职工</option>
                            <option value="学生家长">学生家长</option>
                            <option value="校友">校友</option>
                            <option value="社会人士">社会关心人士</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><font color="red">*</font>您的姓名：</th>
                    <td>
                        <input type="text" name="sender_name" value="<?php echo e((string) ($_SESSION['auth_real_name'] ?? '')); ?>" size="22" placeholder="请填写真实姓名">
                    </td>
                    <th><font color="red">*</font>联系电话：</th>
                    <td>
                        <input type="text" name="contact_phone" size="22" placeholder="请填写手机或固话">
                    </td>
                </tr>
                <tr>
                    <th>电子邮箱：</th>
                    <td>
                        <input type="text" name="contact_email" size="26" placeholder="用于接收回复通知">
                    </td>
                    <th>是否公开选登：</th>
                    <td>
                        <select name="is_public">
                            <option value="1">允许公开（姓名自动脱敏）</option>
                            <option value="0">不公开（仅凭查询码查看）</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><font color="red">*</font>信件主题：</th>
                    <td colspan="3">
                        <input type="text" name="subject" style="width:92%;" placeholder="请简要概括您反映的问题或建议主题">
                    </td>
                </tr>
                <tr>
                    <th><font color="red">*</font>信件内容：</th>
                    <td colspan="3">
                        <textarea name="content" rows="8" style="width:96%;" placeholder="请详细描述具体情况、诉求或建议（严禁填写涉密信息）..."></textarea>
                    </td>
                </tr>
                <tr>
                    <th><font color="red">*</font>图形验证码：</th>
                    <td colspan="3">
                        <input type="text" name="captcha" size="12" maxlength="5" placeholder="请输入验证码" autocomplete="off">
                        <img src="auth/captcha.php?v=<?php echo time(); ?>" alt="点击刷新验证码" title="点击刷新验证码" style="vertical-align:middle;height:32px;border:1px solid #999;cursor:pointer;" onclick="this.src='auth/captcha.php?v='+(new Date().getTime())">
                    </td>
                </tr>
                <tr>
                    <th></th>
                    <td colspan="3">
                        <button type="submit" style="padding:7px 28px;background:#16569a;color:#fff;border:0;font-size:14px;font-weight:bold;cursor:pointer;">
                            提交信件到<?php echo e($boxTitle); ?>
                        </button>
                    </td>
                </tr>
            </table>
        </form>

    <?php elseif ($view === 'query'): ?>
        <!-- ==================== 独立页面 3：信件办理进度查询 ==================== -->
        <div class="mailbox-notice">
            <b>【信件办理进度查询说明】</b>请输入您提交信件时系统生成的 12 位信件查询码（例如：<code>XZ2026050101</code> 或 <code>SJ2026050102</code>），即可查看当前办理状态与领导批复内容。
        </div>

        <form method="get" action="mailbox.php" style="margin-bottom:18px;padding:14px;background:#f2f6fb;border:1px solid #b8c6d6;">
            <input type="hidden" name="type" value="<?php echo e($boxType); ?>">
            <input type="hidden" name="view" value="query">
            <b>请输入信件查询码：</b>
            <input type="text" name="qcode" value="<?php echo e((string) ($_GET['qcode'] ?? '')); ?>" placeholder="例如：XZ2026050101" size="26" style="height:30px;padding:0 8px;border:1px solid #9ab0c7;">
            <input type="submit" value="查询批复结果" style="height:32px;padding:0 18px;background:#16569a;color:#fff;border:0;cursor:pointer;font-weight:bold;">
        </form>

        <?php if ($queryResult): ?>
            <table border="1" cellspacing="0" cellpadding="6" class="mailbox-table" style="border:2px solid #16569a;">
                <tr>
                    <th colspan="4" style="text-align:left;background:#16569a;color:#fff;">
                        信件查询结果 —— 查询码：<?php echo e((string) $queryResult['query_code']); ?>
                    </th>
                </tr>
                <tr>
                    <th>信件主题：</th>
                    <td><b><?php echo e((string) $queryResult['subject']); ?></b></td>
                    <th>投递信箱：</th>
                    <td><?php echo $queryResult['box_type'] === 'shuji' ? '书记信箱' : '校长信箱'; ?></td>
                </tr>
                <tr>
                    <th>来信时间：</th>
                    <td><?php echo e((string) $queryResult['created_at']); ?></td>
                    <th>办理状态：</th>
                    <td>
                        <?php echo ((int) $queryResult['status'] === 1) ? '<font color="#008800"><b>已办结回复</b></font>' : '<font color="#cc6600"><b>正在阅办中</b></font>'; ?>
                    </td>
                </tr>
                <tr>
                    <th>来信内容：</th>
                    <td colspan="3"><?php echo nl2br(e((string) $queryResult['content'])); ?></td>
                </tr>
                <tr>
                    <th>领导批复/部门答复：</th>
                    <td colspan="3" style="background:#f9fff9;">
                        <?php if ((int) $queryResult['status'] === 1): ?>
                            <div><?php echo nl2br(e((string) $queryResult['reply_content'])); ?></div>
                            <div style="margin-top:6px;color:#666;font-size:12px;">
                                答复单位：<?php echo e((string) ($queryResult['reply_dept'] ?: '学校办公室')); ?> ｜
                                答复时间：<?php echo e((string) $queryResult['replied_at']); ?>
                            </div>
                        <?php else: ?>
                            <font color="#666666">您的信件已转交相关部门核实办理，请稍后再来查询。</font>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/public/lib/footer.php'; ?>

</body>
</html>
