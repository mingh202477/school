<?php require_once __DIR__ . '/../../../app/models/plugin_manager.php'; plugin_run_hook('head_top'); ?>
<?php $isEnMode = (isset($lang) && $lang === 'en') || (isset($_COOKIE['lang']) && $_COOKIE['lang'] === 'en'); ?>
<div class="top-bar">

    <div class="wrap">

        <div class="top-links">

            <?php foreach ($top_links as $link): ?>
                <?php $origTxt = $link['orig_text'] ?? $link['text']; ?>
                <a href="<?php echo ($link['url'] === '#' && in_array($origTxt, ['学生', '教工', '校友'], true)) ? 'auth/login.php' : e($link['url']); ?>">
                    <?php echo e($link['text']); ?>
                </a>

            <?php endforeach; ?>

        </div>

    <div class="top-extra">
        <?php if (!empty($_SESSION['auth_user_id']) && !empty($_SESSION['auth_user'])): ?>
            <?php
            $roleMapHead = $isEnMode
                ? ['admin' => ' (Admin)', 'leader' => ' (Leader)', 'teacher' => ' (Teacher)', 'student' => ' (Student)', 'alumni' => ' (Alumni)']
                : ['admin' => '管理员', 'leader' => '领导', 'teacher' => '老师', 'student' => '同学', 'alumni' => '校友'];
            $rLabel = $roleMapHead[$_SESSION['auth_role'] ?? 'student'] ?? '';
            ?>
            <span><?php echo $isEnMode ? 'Welcome, ' : '欢迎您，'; ?><?php echo e((string) ($_SESSION['auth_real_name'] ?: $_SESSION['auth_user'])); ?><?php echo e($rLabel); ?></span> ｜
            <?php if (($_SESSION['auth_role'] ?? '') === 'admin'): ?>
                <a href="admin/dashboard.php"><b><?php echo $isEnMode ? 'Admin Panel' : '后台管理'; ?></b></a> ｜
                <a href="leader/dashboard.php"><b><?php echo $isEnMode ? 'Leader Desk' : '领导工作台'; ?></b></a> ｜
            <?php elseif (($_SESSION['auth_role'] ?? '') === 'leader'): ?>
                <a href="leader/dashboard.php"><b><?php echo $isEnMode ? 'Leader Desk' : '书记/校长工作台'; ?></b></a> ｜
            <?php endif; ?>
            <a href="auth/forgot.php?mode=change"><?php echo $isEnMode ? 'Change Password' : '修改密码'; ?></a> ｜
            <a href="index.php?action=logout"><?php echo $isEnMode ? 'Logout' : '退出'; ?></a> ｜
        <?php else: ?>
            <a href="auth/login.php"><b><?php echo $isEnMode ? 'Unified Auth' : '统一身份认证'; ?></b></a> ｜
        <?php endif; ?>
        <a href="mailbox.php?type=shuji"><?php echo $isEnMode ? 'Party Secretary Mailbox' : '书记信箱'; ?></a> ｜
        <a href="mailbox.php?type=xiaozhang"><?php echo $isEnMode ? 'Principal Mailbox' : '校长信箱'; ?></a> ｜ 
            <?php if ($isEnMode): ?>
                <a href="?lang=zh-CN&csrf_token=<?php echo csrf_token(); ?>">简体中文</a>
            <?php else: ?>
                <a href="?lang=en&csrf_token=<?php echo csrf_token(); ?>">English</a>
            <?php endif; ?>
    </div>

    </div>

</div>

<!-- Header -->

<header class="header">

    <div class="wrap">

        <a href="/" class="logo">

            <img src="<?php echo e($logo_2); ?>" alt="<?php echo e($title); ?>">

        </a>

        <ul class="nav">

            <?php foreach ($nav_links as $nav): ?>

                <li>

                    <a href="<?php echo e($nav['url']); ?>">
                        <?php echo e($nav['text']); ?>
                    </a>

                    <?php if (!empty($nav['children'])): ?>

                        <ul class="subnav">

                            <?php foreach ($nav['children'] as $child): ?>

                                <li>

                                    <a href="<?php echo e($child['url']); ?>">
                                        <?php echo e($child['text']); ?>
                                    </a>

                                </li>

                            <?php endforeach; ?>

                        </ul>

                    <?php endif; ?>

                </li>

            <?php endforeach; ?>

        </ul>

    </div>

</header>