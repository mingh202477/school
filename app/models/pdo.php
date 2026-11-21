<?php
date_default_timezone_set('Asia/Shanghai');
require_once __DIR__ . '/../../config/database.php';
$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, 
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       
    PDO::ATTR_EMULATE_PREPARES   => false,                  
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    die("数据库连接失败: " );
}

/**
 * 确保页脚权威认证图标表存在并初始化四大默认图标
 */
function footer_badges_ensure_table(): void
{
    global $pdo;
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `footer_badges` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(100) NOT NULL COMMENT '图标名称',
                `img_url` varchar(500) NOT NULL COMMENT '图片路径或URL',
                `link_url` varchar(500) NOT NULL DEFAULT '#' COMMENT '点击跳转链接',
                `sort_order` int(11) NOT NULL DEFAULT 10 COMMENT '排序号(越小越靠左)',
                `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1显示 0隐藏',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='页脚认证图标表';
        ");

        $cnt = (int) $pdo->query("SELECT COUNT(*) FROM `footer_badges`")->fetchColumn();
        if ($cnt === 0) {
            $ins = $pdo->prepare("INSERT INTO `footer_badges` (`id`, `title`, `img_url`, `link_url`, `sort_order`, `is_active`) VALUES (?, ?, ?, ?, ?, 1)");
            $ins->execute([1, '事业单位',         'public/images/badge_shiye.png',     'https://bszs.conac.cn/', 1]);
            $ins->execute([2, '可信网站身份验证', 'public/images/badge_kexin.png',     'https://ss.knet.cn/',    2]);
            $ins->execute([3, 'IPv6 Enabled',     'public/images/badge_ipv6.png',      'https://www.edu.cn/',    3]);
            $ins->execute([4, '适老化无障碍服务', 'public/images/badge_wuzhangai.png', '#',                      4]);
        }
    } catch (Throwable $e) {
    }
}

/**
 * 获取页脚认证图标列表
 */
function footer_badges_get_list(bool $onlyActive = true): array
{
    global $pdo;
    footer_badges_ensure_table();
    try {
        $sql = "SELECT * FROM `footer_badges`";
        if ($onlyActive) {
            $sql .= " WHERE `is_active` = 1";
        }
        $sql .= " ORDER BY `sort_order` ASC, `id` ASC";
        return $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * 渲染并排页脚认证图标 HTML（强制 inline-block 横向并排）
 */
function footer_badges_render_html(string $pathPrefix = ''): string
{
    $badges = footer_badges_get_list(true);
    if (empty($badges)) {
        return '';
    }
    $html = '<div class="footer-badges-row" style="margin-top:8px;line-height:1;">';
    foreach ($badges as $b) {
        $rawImg = trim((string) $b['img_url']);
        if ($rawImg === '') continue;
        if (preg_match('#^(https?://|/)#i', $rawImg)) {
            $imgSrc = $rawImg;
        } else {
            $imgSrc = $pathPrefix . ltrim($rawImg, '/');
        }
        $link = trim((string) ($b['link_url'] ?? '#'));
        $title = htmlspecialchars((string) ($b['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $imgEsc = htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8');
        $imgTag = '<img src="' . $imgEsc . '" alt="' . $title . '" title="' . $title . '" style="display:inline-block !important;height:38px;width:auto;vertical-align:middle;border:0;margin:0;">';

        if ($link !== '' && $link !== '#') {
            $linkEsc = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
            $html .= '<a href="' . $linkEsc . '" target="_blank" rel="noopener" style="display:inline-block !important;vertical-align:middle;margin:2px 6px;text-decoration:none;">' . $imgTag . '</a>';
        } else {
            $html .= '<span style="display:inline-block !important;vertical-align:middle;margin:2px 6px;">' . $imgTag . '</span>';
        }
    }
    $html .= '</div>';
    return $html;
}