<?php
/**
 * 东营区行知实验学校 - 校领导（党委书记 / 校长）办公后台
 * 纯传统 HTML4 简陋表格风（零现代设计感，简陋=靠谱）
 */

require_once __DIR__ . '/../../app/models/auth.php';
require_once __DIR__ . '/../../app/models/ai_translate.php';
require_once __DIR__ . '/../../app/models/traffic.php';

if (!function_exists('e')) {
    function e(?string $str): string
    {
        return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
    }
}

ai_ensure_schema();

// 退出登录
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = []; if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
    header('Location: ../auth/login.php');
    exit;
}

// 仅允许校领导 (leader) 或系统管理员 (admin) 访问
auth_require_leader_or_admin();

// ==================== AJAX 接口：AI 公文助手（润色文章 + 隐蔽夸领导） ====================
if (isset($_GET['action']) && $_GET['action'] === 'ai_counselor') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? null)) {
        echo json_encode(['ok' => false, 'error' => '安全令牌校验失败，请刷新页面重试']);
        exit;
    }
    $title = trim((string) ($_POST['title'] ?? ''));
    $draft = trim((string) ($_POST['draft'] ?? ''));
    $mode  = trim((string) ($_POST['mode'] ?? 'polish'));
    $res   = ai_leader_counselor_assist($title, $draft, $mode);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

$tab = $_GET['tab'] ?? 'publish';
$msg = '';
$err = '';

$myUsername = (string) ($_SESSION['auth_user'] ?? 'leader');
$myRealName = (string) ($_SESSION['auth_real_name'] ?? '校领导');
$leaderTitleLabel = '校领导';
if (strpos($myUsername, 'shuji') !== false || strpos($myRealName, '书记') !== false) {
    $leaderTitleLabel = '校党委书记';
} elseif (strpos($myUsername, 'xiaozhang') !== false || strpos($myRealName, '校长') !== false) {
    $leaderTitleLabel = '校长';
}

// ==================== 处理 POST 表单 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $err = '安全令牌校验失败，请刷新页面后重试。';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        // 1. 领导亲自签发公文 / 讲话 / 新闻
        if ($formAction === 'leader_publish_doc') {
            $tab       = 'publish';
            $category  = trim((string) ($_POST['category'] ?? 'yaowen'));
            $title     = trim((string) ($_POST['title'] ?? ''));
            $customUrl = trim((string) ($_POST['custom_url'] ?? ''));
            $froma     = trim((string) ($_POST['froma'] ?? '校党委办公室'));
            $author    = trim((string) ($_POST['author'] ?? $myRealName));
            $pubDate   = trim((string) ($_POST['pub_date'] ?? date('Y-m-d')));
            $coverUrl  = trim((string) ($_POST['cover_url'] ?? 'public/images/yw1.jpg'));
            $content   = trim((string) ($_POST['content_html'] ?? ''));
            $syncHome  = !empty($_POST['sync_homepage']);

            if (!empty($_FILES['cover_upload']) && $_FILES['cover_upload']['error'] === UPLOAD_ERR_OK) {
                $tmpPath  = $_FILES['cover_upload']['tmp_name'];
                $origName = (string) $_FILES['cover_upload']['name'];
                $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
                    $imgDir = __DIR__ . '/../public/images';
                    if (!is_dir($imgDir)) {
                        @mkdir($imgDir, 0755, true);
                    }
                    $newFileName = 'leader_' . date('YmdHis') . '_' . random_int(100, 999) . '.' . $ext;
                    if (@move_uploaded_file($tmpPath, $imgDir . '/' . $newFileName)) {
                        $coverUrl = 'public/images/' . $newFileName;
                    }
                }
            }

            if ($title === '' || $content === '') {
                $err = '文件标题和正文内容不能为空。';
            } else {
                $allowedCats = ['news', 'yaowen', 'xueshu', 'meiti'];
                if (!in_array($category, $allowedCats, true)) {
                    $category = 'yaowen';
                }
                if ($customUrl === '') {
                    $customUrl = $category . '/' . date('YmdHis') . '-' . random_int(100, 999) . '.html';
                }
                $customUrl = str_replace(['..', '\\'], ['', '/'], $customUrl);
                $customUrl = ltrim($customUrl, '/');
                if (strpos($customUrl, '/') === false) {
                    $customUrl = $category . '/' . $customUrl;
                }
                if (substr($customUrl, -5) !== '.html' && substr($customUrl, -4) !== '.htm') {
                    $customUrl .= '.html';
                }

                $urlEn       = ai_make_en_url($customUrl);
                $titleEnRes  = ai_translate_to_english($title, false);
                $titleEn     = $titleEnRes['text'];
                $textEnRes   = ai_translate_to_english($content, true);
                $contentEn   = $textEnRes['text'];
                $fromaEnRes  = ai_translate_to_english($froma, false);
                $fromaEn     = $fromaEnRes['text'];

                $ins = $pdo->prepare("INSERT INTO `news` (`title`, `title_en`, `username`, `date`, `url`, `url_en`, `text`, `text_en`, `froma`, `froma_en`) VALUES (:t, :ten, :u, :d, :url, :urlen, :txt, :txten, :f, :fen)");
                $ins->execute([
                    ':t'     => $title,
                    ':ten'   => $titleEn,
                    ':u'     => $author ?: $myRealName,
                    ':d'     => $pubDate ?: date('Y-m-d'),
                    ':url'   => $customUrl,
                    ':urlen' => $urlEn,
                    ':txt'   => $content,
                    ':txten' => $contentEn,
                    ':f'     => $froma ?: '校长办公室',
                    ':fen'   => $fromaEn ?: 'Principal Office',
                ]);

                if ($syncHome) {
                    $plainSummary = mb_substr(trim(strip_tags($content)), 0, 75, 'UTF-8');
                    $plainSummaryEn = mb_substr(trim(strip_tags($contentEn)), 0, 120, 'UTF-8');
                    if ($category === 'yaowen' || $category === 'news') {
                        $pdo->prepare("INSERT INTO `yaowen_list` (`img`, `title`, `title_en`, `desc_text`, `desc_text_en`, `link`, `link_en`, `is_featured`, `sort_order`) VALUES (:img, :t, :ten, :d, :den, :l, :len, 0, 5)")
                            ->execute([
                                ':img' => $coverUrl ?: 'public/images/yw1.jpg',
                                ':t'   => $title,
                                ':ten' => $titleEn,
                                ':d'   => $plainSummary,
                                ':den' => $plainSummaryEn,
                                ':l'   => '/' . $customUrl,
                                ':len' => '/' . $urlEn,
                            ]);
                    } elseif ($category === 'xueshu') {
                        $ts = strtotime($pubDate ?: date('Y-m-d'));
                        $pdo->prepare("INSERT INTO `xueshu_activities` (`month`, `day`, `title`, `title_en`, `link`, `link_en`, `sort_order`) VALUES (:m, :d, :t, :ten, :l, :len, 5)")
                            ->execute([
                                ':m'   => (int) date('n', $ts),
                                ':d'   => (int) date('j', $ts),
                                ':t'   => $title,
                                ':ten' => $titleEn,
                                ':l'   => '/' . $customUrl,
                                ':len' => '/' . $urlEn,
                            ]);
                    } elseif ($category === 'meiti') {
                        $ts = strtotime($pubDate ?: date('Y-m-d'));
                        $pdo->prepare("INSERT INTO `media_coverage` (`source`, `month`, `day`, `title`, `title_en`, `link`, `link_en`, `sort_order`) VALUES (:s, :m, :d, :t, :ten, :l, :len, 5)")
                            ->execute([
                                ':s'   => $froma ?: '校办发布',
                                ':m'   => (int) date('n', $ts),
                                ':d'   => (int) date('j', $ts),
                                ':t'   => $title,
                                ':ten' => $titleEn,
                                ':l'   => '/' . $customUrl,
                                ':len' => '/' . $urlEn,
                            ]);
                    }
                }

                $catNameZhMap = ['yaowen' => '学校要闻', 'news' => '综合新闻', 'xueshu' => '学术交流', 'meiti' => '媒体关注'];
                $catNameEnMap = ['yaowen' => 'Campus Headlines', 'news' => 'General News', 'xueshu' => 'Academic Exchange', 'meiti' => 'Media Coverage'];
                if (preg_match('/^[a-zA-Z0-9_\-\/]+\.html$/', $customUrl) && strpos($customUrl, '..') === false) {
                    $zhPath = dirname(__DIR__) . '/' . $customUrl;
                    $enPath = dirname(__DIR__) . '/' . $urlEn;
                    $targetDir = dirname($zhPath);
                    if (!is_dir($targetDir)) {
                        @mkdir($targetDir, 0755, true);
                    }
                    $zhHtml = ai_render_pretty_article_html([
                        'lang'           => 'zh-CN',
                        'title'          => $title,
                        'category_label' => $catNameZhMap[$category] ?? '学校要闻',
                        'pub_date'       => $pubDate ?: date('Y-m-d'),
                        'froma'          => $froma ?: '校长办公室',
                        'author'         => $author ?: $myRealName,
                        'body_html'      => $content,
                        'zh_url'         => $customUrl,
                        'en_url'         => $urlEn,
                    ]);
                    $enHtml = ai_render_pretty_article_html([
                        'lang'           => 'en',
                        'title'          => $titleEn,
                        'category_label' => $catNameEnMap[$category] ?? 'Campus Headlines',
                        'pub_date'       => $pubDate ?: date('Y-m-d'),
                        'froma'          => $fromaEn ?: 'Principal Office',
                        'author'         => $author ?: $myRealName,
                        'body_html'      => $contentEn,
                        'zh_url'         => $customUrl,
                        'en_url'         => $urlEn,
                    ]);
                    @file_put_contents($zhPath, $zhHtml);
                    @file_put_contents($enPath, $enHtml);
                }

                $msg = "文件已签发并发布！已生成前台页面 ({$customUrl})。";
            }
        }
        // 2. 书记/校长信箱阅批
        elseif ($formAction === 'leader_reply_mailbox') {
            $tab    = 'mailbox';
            $mid    = (int) ($_POST['mail_id'] ?? 0);
            $reply  = trim((string) ($_POST['reply_content'] ?? ''));
            $dept   = trim((string) ($_POST['reply_dept'] ?? '校长办公室'));
            $isPub  = (int) ($_POST['is_public'] ?? 1) ? 1 : 0;
            if ($mid > 0 && $reply !== '') {
                $pdo->prepare("UPDATE `leader_mailbox` SET `reply_content` = :r, `reply_dept` = :d, `is_public` = :p, `replied_at` = NOW(), `status` = 1 WHERE `id` = :id")
                    ->execute([':r' => $reply, ':d' => $dept, ':p' => $isPub, ':id' => $mid]);
                $msg = "来信（编号 #{$mid}）已批复保存。";
            } else {
                $err = '请填写批复内容。';
            }
        }
        // 3. 领导修改个人资料与密码
        elseif ($formAction === 'save_leader_profile') {
            $tab     = 'profile';
            $myId    = (int) ($_SESSION['auth_user_id'] ?? 0);
            $newName = trim((string) ($_POST['real_name'] ?? ''));
            $newMail = trim((string) ($_POST['email'] ?? ''));
            $newPwd  = (string) ($_POST['new_password'] ?? '');
            $newPwd2 = (string) ($_POST['confirm_password'] ?? '');

            if ($myId <= 0 || $newName === '') {
                $err = '姓名不能为空。';
            } elseif ($newPwd !== '' && $newPwd !== $newPwd2) {
                $err = '两次输入的新密码不一致。';
            } elseif ($newPwd !== '' && strlen($newPwd) < 6) {
                $err = '新密码长度不能少于 6 位。';
            } else {
                $pdo->prepare("UPDATE `users` SET `real_name` = :n, `email` = :e WHERE `id` = :id")
                    ->execute([':n' => $newName, ':e' => ($newMail !== '' ? $newMail : null), ':id' => $myId]);
                if ($newPwd !== '') {
                    $pdo->prepare("UPDATE `users` SET `password_hash` = :ph, `is_initial_pwd` = 0 WHERE `id` = :id")
                        ->execute([':ph' => password_hash($newPwd, PASSWORD_ARGON2ID), ':id' => $myId]);
                }
                $_SESSION['auth_real_name'] = $newName;
                $myRealName = $newName;
                $msg = '个人资料与密码已保存。';
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
    <title>校领导办公后台 - 东营区行知实验学校</title>
    <link rel="icon" href="../dyxz.ico">
    <link rel="stylesheet" href="../admin/public/css/admin.css">
    <style type="text/css">
        .ico-svg {
            width: 12px;
            height: 12px;
            vertical-align: -1px;
            margin-right: 3px;
            fill: currentColor;
        }
    </style>
</head>
<body>

<!-- 顶部系统信息条 -->
<table border="0" cellspacing="0" cellpadding="0" width="100%" class="admin-topbar">
    <tr>
        <td>
            <svg class="ico-svg" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zm0 7.5l-6-3 6-3 6 3-6 3zm0 2.5l-10-5v2l10 5 10-5v-2l-10 5zm0 4l-10-5v2l10 5 10-5v-2l-10 5z"/></svg>
            <b>东营区行知实验学校 - 书记/校长办公后台系统 v1.0 (内部系统)</b>
        </td>
        <td align="right">
            <svg class="ico-svg" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
            当前登录：<b><?php echo e($myRealName); ?></b> [<?php echo e($leaderTitleLabel); ?>]
            <?php if (($_SESSION['auth_role'] ?? '') === 'admin'): ?>
                | <a href="../admin/dashboard.php">[返回管理员后台]</a>
            <?php endif; ?>
            | <a href="../index.php" target="_blank">[查看前台首页]</a>
            | <a href="dashboard.php?tab=profile">[修改密码]</a>
            | <a href="dashboard.php?action=logout" onclick="return confirm('确定要退出后台吗？');">[退出]</a>
        </td>
    </tr>
</table>

<!-- 主体左右分栏表格 -->
<table border="1" cellspacing="0" cellpadding="0" width="98%" class="admin-layout">
    <tr valign="top">
        <!-- 左侧功能导航菜单 -->
        <td width="175" class="admin-sidebar">
            <span class="admin-sidebar-title">
                <svg class="ico-svg" viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
                领导办公菜单
            </span>

            <p>
                <a href="dashboard.php?tab=publish">
                    <svg class="ico-svg" viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a.996.996 0 0 0 0-1.41l-2.34-2.34a.996.996 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                    <?php echo $tab === 'publish' ? '<b>&gt;&gt; 领导发文签发</b>' : '领导发文签发'; ?>
                </a>
            </p>
            <p>
                <a href="dashboard.php?tab=mailbox">
                    <svg class="ico-svg" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                    <?php echo $tab === 'mailbox' ? '<b>&gt;&gt; 书记/校长信箱</b>' : '书记/校长信箱'; ?>
                </a>
            </p>
            <p>
                <a href="dashboard.php?tab=articles">
                    <svg class="ico-svg" viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                    <?php echo $tab === 'articles' ? '<b>&gt;&gt; 已发文件列表</b>' : '已发文件列表'; ?>
                </a>
            </p>
            <p>
                <a href="dashboard.php?tab=overview">
                    <svg class="ico-svg" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>
                    <?php echo $tab === 'overview' ? '<b>&gt;&gt; 门户运行概况</b>' : '门户运行概况'; ?>
                </a>
            </p>
            <p>
                <a href="dashboard.php?tab=profile">
                    <svg class="ico-svg" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>
                    <?php echo $tab === 'profile' ? '<b>&gt;&gt; 个人资料与密码</b>' : '个人资料与密码'; ?>
                </a>
            </p>

            <hr size="1">
            <font color="#666666">服务器时间：<br><?php echo date('Y-m-d H:i:s'); ?></font>

        </td>

        <!-- 右侧主工作区 -->
        <td class="admin-content">
            <?php if ($msg !== ''): ?>
                <div class="msg-ok">[提示] <?php echo e($msg); ?></div>
            <?php endif; ?>
            <?php if ($err !== ''): ?>
                <div class="msg-err">[错误] <?php echo e($err); ?></div>
            <?php endif; ?>

            <?php if ($tab === 'publish'): ?>
                <!-- ==================== 1. 领导发文签发（左侧发文表 + 右侧AI公文助手表） ==================== -->
                <?php
                $defCat = 'yaowen';
                $defUrl = $defCat . '/' . date('YmdHis') . '-' . random_int(100, 999) . '.html';
                $defOffice = (strpos($leaderTitleLabel, '书记') !== false) ? '校党委办公室' : '校长办公室';
                ?>
                <b>
                    <svg class="ico-svg" viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25z"/></svg>
                    当前位置：领导发文签发 &gt;&gt; 起草文件 / 讲话稿签发
                </b>
                <hr size="1">

                <table border="0" cellspacing="0" cellpadding="0" width="100%" style="border:0;">
                    <tr valign="top">
                        <!-- 左侧：领导发文主表格 -->
                        <td width="63%" style="border:0;padding:0 6px 0 0;">
                            <form method="post" action="dashboard.php?tab=publish" enctype="multipart/form-data" id="leaderPubForm" onsubmit="syncLeaderRte()">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="form_action" value="leader_publish_doc">

                                <table border="1" cellspacing="0" cellpadding="4" width="100%">
                                    <tr>
                                        <th colspan="2">
                                            <svg class="ico-svg" viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6z"/></svg>
                                            文件签发单
                                        </th>
                                    </tr>
                                    <tr>
                                        <th width="105">发布栏目：</th>
                                        <td>
                                            <select name="category" id="leaderCatSelect" onchange="regenLeaderUrl()">
                                                <option value="yaowen">学校要闻</option>
                                                <option value="news">综合新闻</option>
                                                <option value="xueshu">学术与教研活动</option>
                                                <option value="meiti">媒体关注</option>
                                            </select>
                                            &nbsp;
                                            <label><input type="checkbox" name="sync_homepage" value="1" checked> 同步推送至首页</label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>文件标题：</th>
                                        <td>
                                            <input type="text" name="title" id="leaderTitleInput" size="50" required> *
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>签发人 / 部门：</th>
                                        <td>
                                            签发人：<input type="text" name="author" value="<?php echo e($myRealName); ?>" size="10">
                                            &nbsp;签发部门：<input type="text" name="froma" value="<?php echo e($defOffice); ?>" size="14">
                                            &nbsp;日期：<input type="text" name="pub_date" value="<?php echo date('Y-m-d'); ?>" size="10">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>文件路径：</th>
                                        <td>
                                            <input type="text" name="custom_url" id="leaderUrlInput" value="<?php echo e($defUrl); ?>" size="36">
                                            <input type="button" value="重生成路径" onclick="regenLeaderUrl()">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>配图(可选)：</th>
                                        <td>
                                            <input type="text" name="cover_url" id="leaderCoverInput" value="public/images/yw1.jpg" size="22">
                                            <select onchange="if(this.value) document.getElementById('leaderCoverInput').value=this.value;">
                                                <option value="">-选择已有图-</option>
                                                <option value="public/images/yw1.jpg">yw1.jpg</option>
                                                <option value="public/images/banner1.png">banner1.png</option>
                                            </select>
                                            上传：<input type="file" name="cover_upload" accept=".png,.jpg,.jpeg,.gif,.webp">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th valign="top">正文内容：</th>
                                        <td>
                                            <div class="rte-toolbar">
                                                <button type="button" onclick="lRteCmd('bold')"><b>加粗</b></button>
                                                <button type="button" onclick="lRteCmd('underline')"><u>下划线</u></button>
                                                <button type="button" onclick="lRteCmd('justifyLeft')">左对齐</button>
                                                <button type="button" onclick="lRteCmd('justifyCenter')">居中</button>
                                                <button type="button" onclick="lRteCmd('foreColor', '#cc0000')">标红</button>
                                                <button type="button" onclick="lRteCmd('foreColor', '#000000')">黑字</button>
                                            </div>
                                            <div id="leaderRteEditor" class="rte-area" contenteditable="true"><p>各处室、各年级组：</p><p>为进一步抓好近期学校重点工作落实，现就有关要求明确如下：</p></div>
                                            <textarea name="content_html" id="leaderRteHidden" style="display:none;"></textarea>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>操作：</th>
                                        <td>
                                            <input type="submit" value=" 签发并发布文件 ">
                                        </td>
                                    </tr>
                                </table>
                            </form>
                        </td>

                        <!-- 右侧：AI公文助手（最朴素的表格+文本框，无任何花哨卡片） -->
                        <td width="37%" style="border:0;padding:0;">
                            <table border="1" cellspacing="0" cellpadding="4" width="100%">
                                <tr>
                                    <th colspan="2">
                                        <svg class="ico-svg" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                                        AI公文助手
                                    </th>
                                </tr>
                                <tr>
                                    <th width="72">功能操作：</th>
                                    <td>
                                        <input type="button" value="润色正文" onclick="askAiCounselor('polish')" id="btnAiPolish">
                                        <input type="button" value="按标题写初稿" onclick="askAiCounselor('expand')" id="btnAiExpand">
                                    </td>
                                </tr>
                                <tr>
                                    <th valign="top">审读意见：</th>
                                    <td>
                                        <textarea id="aiPraiseBox" rows="6" style="width:97%;background:#f5f5f5;" readonly></textarea>
                                    </td>
                                </tr>
                                <tr>
                                    <th valign="top">润色文稿：</th>
                                    <td>
                                        <textarea id="aiPolishedBox" rows="13" style="width:97%;"></textarea>
                                        <div style="margin-top:4px;">
                                            <input type="button" value="&lt;&lt; 将润色文稿填入左侧正文" onclick="applyPolishedToEditor()">
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <script type="text/javascript">
                    function lRteCmd(cmd, val) {
                        var ed = document.getElementById('leaderRteEditor');
                        ed.focus();
                        document.execCommand(cmd, false, val || null);
                    }
                    function syncLeaderRte() {
                        document.getElementById('leaderRteHidden').value = document.getElementById('leaderRteEditor').innerHTML;
                    }
                    function pad2(n) { return n < 10 ? '0' + n : '' + n; }
                    function regenLeaderUrl() {
                        var cat = document.getElementById('leaderCatSelect').value;
                        var d = new Date();
                        var ts = '' + d.getFullYear() + pad2(d.getMonth() + 1) + pad2(d.getDate())
                            + pad2(d.getHours()) + pad2(d.getMinutes()) + pad2(d.getSeconds());
                        var rnd = Math.floor(100 + Math.random() * 900);
                        document.getElementById('leaderUrlInput').value = cat + '/' + ts + '-' + rnd + '.html';
                    }
                    function htmlToPlainParagraphs(html) {
                        var tmp = document.createElement('div');
                        tmp.innerHTML = html.replace(/<\/p>/gi, "\n\n").replace(/<br\s*\/?>/gi, "\n");
                        var txt = tmp.innerText || tmp.textContent || '';
                        return txt.replace(/\n{3,}/g, "\n\n").replace(/^\s+|\s+$/g, '');
                    }
                    function plainToHtmlParagraphs(text) {
                        var lines = text.split(/\r?\n/);
                        var out = '';
                        for (var i = 0; i < lines.length; i++) {
                            var ln = lines[i].replace(/^\s+|\s+$/g, '');
                            if (ln !== '') {
                                out += '<p>' + ln + '</p>';
                            }
                        }
                        return out || '<p>' + text + '</p>';
                    }
                    function askAiCounselor(mode) {
                        var title = document.getElementById('leaderTitleInput').value;
                        var draft = document.getElementById('leaderRteEditor').innerHTML;
                        var praiseBox   = document.getElementById('aiPraiseBox');
                        var polishedBox = document.getElementById('aiPolishedBox');

                        var inputs = document.getElementById('leaderPubForm').getElementsByTagName('input');
                        var csrf = '';
                        for (var i = 0; i < inputs.length; i++) {
                            if (inputs[i].name === 'csrf_token') { csrf = inputs[i].value; break; }
                        }

                        praiseBox.value   = '正在处理，请稍候...';
                        polishedBox.value = '正在生成润色文稿...';

                        var xhr = window.XMLHttpRequest ? new XMLHttpRequest() : new ActiveXObject('Microsoft.XMLHTTP');
                        xhr.open('POST', 'dashboard.php?action=ai_counselor', true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onreadystatechange = function () {
                            if (xhr.readyState === 4) {
                                if (xhr.status === 200) {
                                    try {
                                        var res = eval('(' + xhr.responseText + ')');
                                        if (res && res.ok) {
                                            praiseBox.value   = res.praise || '';
                                            polishedBox.value = htmlToPlainParagraphs(res.polished_html || '');
                                        } else {
                                            praiseBox.value = '调用失败：' + (res.error || '未知错误');
                                        }
                                    } catch (e) {
                                        praiseBox.value = '解析返回结果失败。';
                                    }
                                } else {
                                    praiseBox.value = '网络请求异常。';
                                }
                            }
                        };
                        xhr.send('title=' + encodeURIComponent(title)
                            + '&draft=' + encodeURIComponent(draft)
                            + '&mode=' + encodeURIComponent(mode)
                            + '&csrf_token=' + encodeURIComponent(csrf));
                    }
                    function applyPolishedToEditor() {
                        var txt = document.getElementById('aiPolishedBox').value;
                        if (!txt) return;
                        document.getElementById('leaderRteEditor').innerHTML = plainToHtmlParagraphs(txt);
                    }
                </script>

            <?php elseif ($tab === 'mailbox'): ?>
                <!-- ==================== 2. 书记/校长信箱阅批 ==================== -->
                <?php
                $letters = $pdo->query("SELECT * FROM `leader_mailbox` ORDER BY `status` ASC, `id` DESC")->fetchAll();
                ?>
                <b>
                    <svg class="ico-svg" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2z"/></svg>
                    当前位置：书记/校长信箱 &gt;&gt; 师生来信阅批与回复
                </b>
                <hr size="1">

                <table border="1" cellspacing="0" cellpadding="4" width="100%">
                    <tr>
                        <th width="65">来信编号</th>
                        <th width="75">投递信箱</th>
                        <th width="130">来信人信息</th>
                        <th>信件主题与内容</th>
                        <th width="65">状态</th>
                        <th width="310">领导批复意见</th>
                    </tr>
                    <?php if (empty($letters)): ?>
                        <tr><td colspan="6" align="center">暂无师生来信记录</td></tr>
                    <?php else: ?>
                        <?php foreach ($letters as $lt): ?>
                            <?php $boxType = (string) ($lt['box_type'] ?? ($lt['leader_type'] ?? 'xiaozhang')); ?>
                            <tr>
                                <td>
                                    #<?php echo (int) $lt['id']; ?><br>
                                    <font color="#666666"><?php echo e((string) ($lt['query_code'] ?? '')); ?></font>
                                </td>
                                <td>
                                    <b><?php echo $boxType === 'shuji' ? '<font color="#c00">书记信箱</font>' : '<font color="#16569a">校长信箱</font>'; ?></b>
                                </td>
                                <td>
                                    姓名：<b><?php echo e((string) $lt['sender_name']); ?></b> (<?php echo e((string) $lt['sender_role']); ?>)<br>
                                    电话：<?php echo e((string) ($lt['contact_phone'] ?: '-')); ?><br>
                                    邮箱：<?php echo e((string) ($lt['contact_email'] ?? '-')); ?>
                                </td>
                                <td valign="top">
                                    <b><?php echo e((string) $lt['subject']); ?></b>
                                    <font color="#666666">（<?php echo e((string) $lt['created_at']); ?>）</font><br>
                                    <div style="margin-top:4px;white-space:pre-wrap;"><?php echo e((string) $lt['content']); ?></div>
                                </td>
                                <td>
                                    <?php echo (int) $lt['status'] === 1 ? '<font color="green">[已办结]</font>' : '<font color="#cc6600">[待批复]</font>'; ?>
                                </td>
                                <td valign="top">
                                    <form method="post" action="dashboard.php?tab=mailbox">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="leader_reply_mailbox">
                                        <input type="hidden" name="mail_id" value="<?php echo (int) $lt['id']; ?>">
                                        <textarea name="reply_content" rows="3" style="width:96%;"><?php echo e((string) ($lt['reply_content'] ?? '')); ?></textarea>
                                        <div style="margin-top:4px;">
                                            部门：<input type="text" name="reply_dept" value="<?php echo e((string) ($lt['reply_dept'] ?? ($boxType === 'shuji' ? '校党委办公室' : '校长办公室'))); ?>" size="12">
                                            <select name="is_public">
                                                <option value="1" <?php echo ((int) ($lt['is_public'] ?? 1) === 1) ? 'selected' : ''; ?>>公开选登</option>
                                                <option value="0" <?php echo ((int) ($lt['is_public'] ?? 1) === 0) ? 'selected' : ''; ?>>不公开</option>
                                            </select>
                                            <input type="submit" value="保存批复">
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>

            <?php elseif ($tab === 'articles'): ?>
                <!-- ==================== 3. 已发文件列表 ==================== -->
                <?php
                $recentNews = $pdo->query("SELECT `id`, `title`, `username`, `date`, `url`, `url_en`, `froma` FROM `news` ORDER BY `id` DESC LIMIT 25")->fetchAll();
                ?>
                <b>
                    <svg class="ico-svg" viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6z"/></svg>
                    当前位置：已发文件列表 &gt;&gt; 全站已签发公文与新闻列表
                </b>
                <hr size="1">

                <table border="1" cellspacing="0" cellpadding="4" width="100%">
                    <tr>
                        <th width="45">ID</th>
                        <th>文件标题</th>
                        <th width="90">签发人</th>
                        <th width="110">签发部门</th>
                        <th width="90">发布日期</th>
                        <th width="150">查看页面</th>
                    </tr>
                    <?php foreach ($recentNews as $rn): ?>
                        <tr>
                            <td><?php echo (int) $rn['id']; ?></td>
                            <td><?php echo e((string) $rn['title']); ?></td>
                            <td><?php echo e((string) $rn['username']); ?></td>
                            <td><?php echo e((string) $rn['froma']); ?></td>
                            <td><?php echo e((string) $rn['date']); ?></td>
                            <td>
                                <a href="../<?php echo e(ltrim((string) $rn['url'], '/')); ?>" target="_blank">[中文版]</a>
                                <?php if (!empty($rn['url_en'])): ?>
                                    <a href="../<?php echo e(ltrim((string) $rn['url_en'], '/')); ?>" target="_blank">[英文版]</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

            <?php elseif ($tab === 'overview'): ?>
                <!-- ==================== 4. 门户运行概况 ==================== -->
                <?php
                $totalUsers   = (int) $pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
                $totalNews    = (int) $pdo->query("SELECT COUNT(*) FROM `news`")->fetchColumn();
                $totalLetters = (int) $pdo->query("SELECT COUNT(*) FROM `leader_mailbox`")->fetchColumn();
                $unreplied    = (int) $pdo->query("SELECT COUNT(*) FROM `leader_mailbox` WHERE `status` = 0")->fetchColumn();
                $trafficSnap  = traffic_get_summary();
                ?>
                <b>
                    <svg class="ico-svg" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                    当前位置：门户运行概况 &gt;&gt; 网站运行数据汇总
                </b>
                <hr size="1">

                <table border="1" cellspacing="0" cellpadding="4" width="100%">
                    <tr>
                        <th width="180">统计项</th>
                        <th>当前数值</th>
                        <th width="180">统计项</th>
                        <th>当前数值</th>
                    </tr>
                    <tr>
                        <th>全网累计访问量 (PV)：</th>
                        <td><b><?php echo number_format($trafficSnap['total_pv']); ?></b> 次</td>
                        <th>今日累计访问量 (PV)：</th>
                        <td><b><?php echo number_format($trafficSnap['today_pv']); ?></b> 次</td>
                    </tr>
                    <tr>
                        <th>今日独立访客 (UV)：</th>
                        <td><b><?php echo number_format($trafficSnap['today_uv']); ?></b> 人</td>
                        <th>当前实时在线人数：</th>
                        <td><b><?php echo number_format($trafficSnap['online_count']); ?></b> 人</td>
                    </tr>
                    <tr>
                        <th>已录入师生账号总数：</th>
                        <td><b><?php echo number_format($totalUsers); ?></b> 人</td>
                        <th>已发公文与新闻总数：</th>
                        <td><b><?php echo number_format($totalNews); ?></b> 篇</td>
                    </tr>
                    <tr>
                        <th>书记/校长信箱累计来信：</th>
                        <td><b><?php echo number_format($totalLetters); ?></b> 件</td>
                        <th>待批复师生来信：</th>
                        <td><b><?php echo $unreplied; ?></b> 件</td>
                    </tr>
                </table>

            <?php elseif ($tab === 'profile'): ?>
                <!-- ==================== 5. 个人资料与密码修改 ==================== -->
                <?php
                $myId = (int) ($_SESSION['auth_user_id'] ?? 0);
                $stmtMe = $pdo->prepare("SELECT * FROM `users` WHERE `id` = :id LIMIT 1");
                $stmtMe->execute([':id' => $myId]);
                $meRow = $stmtMe->fetch() ?: [
                    'username'     => $myUsername,
                    'real_name'    => $myRealName,
                    'email'        => '',
                    'id_card_mask' => '3705************1X',
                ];
                ?>
                <b>
                    <svg class="ico-svg" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
                    当前位置：个人资料与密码 &gt;&gt; 修改密码
                </b>
                <hr size="1">

                <form method="post" action="dashboard.php?tab=profile">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save_leader_profile">
                    <table border="1" cellspacing="0" cellpadding="4" width="100%">
                        <tr>
                            <th width="140">登录账号：</th>
                            <td><b><?php echo e((string) $meRow['username']); ?></b></td>
                        </tr>
                        <tr>
                            <th>姓名：</th>
                            <td><input type="text" name="real_name" value="<?php echo e((string) $meRow['real_name']); ?>" size="24" required></td>
                        </tr>
                        <tr>
                            <th>联系邮箱：</th>
                            <td><input type="text" name="email" value="<?php echo e((string) ($meRow['email'] ?? '')); ?>" size="32"></td>
                        </tr>
                        <tr>
                            <th>新登录密码：</th>
                            <td><input type="password" name="new_password" size="24"> （不修改请留空）</td>
                        </tr>
                        <tr>
                            <th>确认新密码：</th>
                            <td><input type="password" name="confirm_password" size="24"></td>
                        </tr>
                        <tr>
                            <th></th>
                            <td><input type="submit" value="保存修改"></td>
                        </tr>
                    </table>
                </form>
            <?php endif; ?>
        </td>
    </tr>
</table>

<!-- 正常网站页脚（读取站点配置中的联系方式、安全警告语、版权备案、灰色英文技术支持及并排四大认证图标） -->
<?php
$ftCfg = [
    'title'            => '东营区行知实验学校',
    'address'          => '',
    'postal_code'      => '',
    'icp'              => 'f',
    'footer_email'     => '----@---.cn',
    'footer_phone'     => '--------',
    'security_warning' => '非涉密平台，严禁处理涉密信息！',
    'security_color'   => '#cc0000',
    'footer_copyright' => 'Copyright © 2026 东营区行知实验学校 版权所有 | 建议使用 1024*768 分辨率浏览',
];
try {
    $qFt = $pdo->query("SELECT `name`, `description` FROM `config` WHERE `name` IN ('title','address','postal_code','icp','footer_email','footer_phone','security_warning','security_color','footer_copyright')");
    foreach ($qFt->fetchAll() as $fr) {
        if ($fr['description'] !== null && $fr['description'] !== '') {
            $ftCfg[$fr['name']] = (string) $fr['description'];
        }
    }
} catch (Throwable $e) {}
?>
<div style="width:98%;margin:16px auto 24px;padding:12px 0 8px;border-top:1px solid #b8c6d6;text-align:center;font-size:12px;color:#333;line-height:1.9;">
    <div>
        <span>信息化处邮箱：<?php echo e($ftCfg['footer_email']); ?></span>
        &nbsp;｜&nbsp;
        <span>电话：<?php echo e($ftCfg['footer_phone']); ?></span>
        <?php if (!empty($ftCfg['address']) && $ftCfg['address'] !== 'f'): ?>
            &nbsp;｜&nbsp;<span>地址：<?php echo e($ftCfg['address']); ?></span>
        <?php endif; ?>
        <?php if (!empty($ftCfg['postal_code']) && $ftCfg['postal_code'] !== 'f'): ?>
            &nbsp;｜&nbsp;<span>邮编：<?php echo e($ftCfg['postal_code']); ?></span>
        <?php endif; ?>
        <?php if (!empty($ftCfg['icp']) && $ftCfg['icp'] !== 'f'): ?>
            &nbsp;｜&nbsp;<span>ICP备案号：<?php echo e($ftCfg['icp']); ?></span>
        <?php endif; ?>
    </div>
    <div>
        <?php if (!empty($ftCfg['security_warning'])): ?>
            <span style="color:<?php echo e($ftCfg['security_color']); ?>;font-weight:bold;"><?php echo e($ftCfg['security_warning']); ?></span>
            &nbsp;｜&nbsp;
        <?php endif; ?>
        <span><?php echo e($ftCfg['footer_copyright']); ?></span>
    </div>
    <div style="color:#8892a0;font-family:Arial,Helvetica,sans-serif;font-size:11px;">
        Copyright &copy; <?php echo date('Y'); ?> Dongying Xingzhi Experimental School. All Rights Reserved. Technical Support: Shaanxi Jimayun Education Innovation Studio.
    </div>
    <?php echo footer_badges_render_html('../'); ?>
</div>
</body>
</html>
