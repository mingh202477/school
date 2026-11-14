<?php
/**
 * index.php - 学校官网首页
 *
 * 页面主入口，包含以下模块：
 *   - Banner 轮播图
 *   - 校园要闻（图文卡片）
 *   - 综合新闻（列表）
 *   - 学术交流（列表）
 *   - 媒体关注（列表）
 *   - 门户卡片
 *
 * 请求流程：
 *   1. 加载全局配置变量（title / keywords / logo / ico / icp / address 等）
 *   2. 加载 CSRF 防护
 *   3. 加载页面展示数据（顶部链接 / 导航 / Banner / 各新闻列表 / 门户卡片）
 *   4. 处理语言切换（zh-CN 或 en，通过 Cookie 持久化 30 天）
 *   5. 渲染 HTML 模板
 *
 * Copyright (c) 2025-2026 Shaanxi Jima Cloud Education Innovation Studio
 * website: extreme-code.cn
 *
 * 数据库配置修改位置：根目录/config/database.php
 *
 * =====提醒=====
 * 这个代码我自己都看不懂！！！！不要瞎改！！！！！
 * 加载信息
 * 没设计容错，别乱删文件
 * 天知道那些文件啥地方引用了！！！！！！！！！
 */

// ============================================================
// A1 变量加载区 —— 从数据库/配置文件加载网站全局变量
// 注意：以下文件均不使用 require_once，若重复加载会导致"已定义"致命错误
// ============================================================

// 页面标题变量 $title（网站名称，如"某某学校官网"）
require __DIR__ . '/../app/models/title.php';

// SEO 关键词变量 $keywords
require __DIR__ . '/../app/models/keywords.php';

// Logo 图片 URL 变量 $logo_1、$logo_2（通常分别用于桌面端/移动端）
require __DIR__ . '/../app/models/logo_1.php';
require __DIR__ . '/../app/models/logo_2.php';

// 网站图标（favicon）URL 变量 $ico
require __DIR__ . '/../app/models/ico.php';

// ICP 备案号变量 $icp
require __DIR__ . '/../app/models/icp.php';

// 学校地址变量 $address
require __DIR__ . '/../app/models/address.php';

// 邮政编码变量 $postal_code
require __DIR__ . '/../app/models/postal_code.php';

// ============================================================
// B1 防护加载区
// ============================================================

// 加载 CSRF 防护，提供 csrf_token() 和 csrf_verify() 函数
require __DIR__ . '/../app/models/csrf.php';//CSRF防护

// ============================================================
// B1（旧版）语言包加载 —— 已废弃，改为下方按需动态按 Cookie 加载
// ============================================================
// require_once __DIR__ . '/public/lib/language/zh-CN.php';//zh-CN语言包
// require_once __DIR__ . '/public/lib/language/en.php';//en语言包

/**
 * HTML 输出转义函数
 *
 * 使用 htmlspecialchars() 防止 XSS 攻击。
 * 所有输出到 HTML 的用户数据/数据库数据都必须通过此函数处理。
 *
 * @param  mixed $str 待转义的字符串（非字符串会自动转为字符串）
 * @return string     转义后的安全字符串
 */
function e($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// C1 页面数据加载区 —— 从单表数据库加载页面展示所需数据
// 说明：每个文件查询数据库并输出对应的数组变量，变量名通常与文件名对应
// ============================================================

// $top_links — 顶部链接（如：校内服务、English 等快捷入口）
require_once __DIR__ . '/../app/models/top_links.php';

// $nav_links — 主导航菜单链接
/*导航*/
require_once __DIR__ . '/../app/models/nav_links.php';

// $banner_imgs — Banner 轮播图片数组（每项含 img / title / link）
/*Banner*/
require_once __DIR__ . '/../app/models/banner_imgs.php';

// $yaowen_list — 校园要闻列表，取前 4 条（q4=quantity 4）
/*校园要闻*/
require_once __DIR__ . '/../app/models/yaowen_list_q4.php';//q4

// $xinwen_list — 综合新闻列表，取前 6 条（q6=quantity 6）
/*综合新闻*/
require_once __DIR__ . '/../app/models/index_news_q6.php';//q6

// $xueshu_list — 学术交流列表，取前 4 条
/*学术交流*/
require_once __DIR__ . '/../app/models/xueshu_list_q4.php';//q4

// $meiti_list — 媒体关注列表，取前 4 条
/*媒体关注*/
require_once __DIR__ . '/../app/models/meiti_list_q4.php';//q4

// $portal_cards — 门户卡片数组（每项含 title / desc / class）
/*门户*/
require_once __DIR__ . '/../app/models/portal_cards.php';

// ============================================================
// 语言切换逻辑
// 流程：
//   1. 检测 URL 参数 ?lang=xx
//   2. 通过 CSRF 验证后设置 Cookie（30天有效期）
//   3. 重定向去掉 URL 参数（防止刷新重复提交）
//   4. 读取 Cookie 确定当前语言，加载对应语言包
// ============================================================

// 允许的语言列表
$allowed_languages = ['zh-CN', 'en'];
$cookie_options = [
    'expires'  => time() + 30 * 86400,          // 30 天
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']),     // 仅 HTTPS 下发送（生产环境建议强制开启）
    'httponly' => true,                         // 禁止 JS 读取，防 XSS
    'samesite' => 'Lax'                         // 防 CSRF
];

if (isset($_GET['lang']) && in_array($_GET['lang'], $allowed_languages, true)) {
    // CSRF 验证
    if (!csrf_verify()) {
        die('CSRF token 验证失败，请刷新页面后重试。');
    }

    setcookie('lang', $_GET['lang'], $cookie_options);

    $redirect_url = strtok($_SERVER['REQUEST_URI'], '?');
    if ($redirect_url === false || $redirect_url === '') {
        $redirect_url = '/';
    }
    header('Location: ' . $redirect_url);
    exit;
}

// --- 4. 读取当前语言 ---
if (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], $allowed_languages, true)) {
    $lang = $_COOKIE['lang'];
} else {
    $lang = 'zh-CN';
    setcookie('lang', $lang, $cookie_options);
}




if (isset($_COOKIE['lang'])) {
    $lang = $_COOKIE['lang'];
    
    if ($lang === 'en') {
        require_once __DIR__ . '/public/lib/language/en.php';
    } elseif ($lang === 'zh-CN') {
        require_once __DIR__ . '/public/lib/language/zh-CN.php';
    }
}
?>
<!-- ===== PHP 逻辑结束，以下为 HTML 模板 ===== -->

<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>">

<head>

<meta charset="UTF-8">

<!-- 页面标题：格式为"当前页标题 - 网站名称"（如"首页 - 某某学校"） -->
<title><?php echo e($title); ?> - <?php echo e($index_title); ?></title>

<!-- SEO 关键词 -->
<meta name="keywords" content="<?php echo e($keywords); ?>">

<!-- 兼容 IE 浏览器，使用最新渲染模式 -->
<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">

<!-- 网站图标（favicon），从数据库加载动态图标 URL -->
<link rel="icon" href="<?php echo e($ico); ?>" type="image/x-icon">

<!-- 首页主样式表 -->
<link rel="stylesheet" href="public/css/index.css">

</head>

<body>

<!-- 顶部栏 -->

<?php require_once __DIR__ . '/public/lib/head.php'; ?>

<!-- 轮番图Banner -->

<div class="banner">

    <div class="slides-container">

        <?php foreach ($banner_imgs as $key => $b): ?>

            <a href="<?php echo e($b['link']); ?>"
               class="slide <?php echo $key === 0 ? 'active' : ''; ?>">

                <img src="<?php echo e($b['img']); ?>" alt="">

                <div class="banner-text">

                    <?php echo e($b['title']); ?>

                </div>

            </a>

        <?php endforeach; ?>

    </div>

    <button class="banner-arrow banner-arrow--left">&#10094;</button>

    <button class="banner-arrow banner-arrow--right">&#10095;</button>

    <div class="banner-dots">

        <?php foreach ($banner_imgs as $key => $b): ?>

            <span class="dot <?php echo $key === 0 ? 'active' : ''; ?>"
                  data-index="<?php echo $key; ?>"></span>

        <?php endforeach; ?>

    </div>

</div>

<!-- 主体 -->

<div class="main wrap">

    <!-- 校园要闻 -->

    <div class="news-section">

        <div class="news-left">

            <h2 class="section-title">

                <?php echo e($school_new); ?>

                <a href="#" class="more"><?php echo e($more); ?>&gt;</a>

            </h2>

            <div class="yaowen-grid">

                <?php foreach ($yaowen_list as $yw): ?>

                    <a href="<?php echo e($yw['link']); ?>" class="yw-card">

                        <div class="yw-image">

                            <img src="<?php echo e($yw['img']); ?>" alt="">

                        </div>

                        <div class="yw-content">

                            <h3>
                                <?php echo e($yw['title']); ?>
                            </h3>

                            <p>
                                <?php echo e($yw['desc']); ?>
                            </p>

                        </div>

                    </a>

                <?php endforeach; ?>

            </div>

        </div>

        <!-- 综合新闻 -->

        <div class="news-right">

            <h2 class="section-title">

                <?php echo e($zh_new); ?>

                <a href="#" class="more"><?php echo e($more); ?>&gt;</a>

            </h2>

            <ul class="news-list">

                <?php foreach ($xinwen_list as $xw): ?>

                    <li>

                        <a href="<?php echo e($xw['link']); ?>">

                            <?php echo e($xw['title']); ?>

                        </a>

                        <span>

                            <?php echo e($xw['date']); ?>

                        </span>

                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    </div>

    <!-- 学术交流 / 媒体关注 -->

    <div class="info-cols">

        <div class="info-col">

            <h2 class="section-title">

                <?php echo e($academic_exchange); ?>

                <a href="#" class="more"><?php echo e($more); ?>&gt;</a>

            </h2>

            <ul class="info-list">

                <?php foreach ($xueshu_list as $xs): ?>

                    <li>

                        <a href="<?php echo e($xs['link']); ?>">

                            <?php echo e($xs['title']); ?>

                        </a>

                        <span>

                            <?php echo e($xs['date']); ?>

                        </span>

                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

        <div class="info-col">

            <h2 class="section-title">
                    <!-- 媒体关注 -->
                <?php echo e($media_attention); ?>

                <a href="#" class="more"><?php echo e($more); ?>&gt;</a>

            </h2>

            <ul class="info-list">

                <?php foreach ($meiti_list as $mt): ?>

                    <li>

                        <a href="<?php echo e($mt['link']); ?>">

                            <?php echo e($mt['title']); ?>

                        </a>

                        <span>

                            <?php echo e($mt['date']); ?>

                        </span>

                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    </div>

    <!-- 门户 -->

    <div class="portal-grid">

        <?php foreach ($portal_cards as $card): ?>

            <a href="#"
               class="portal-card <?php echo e($card['class']); ?>">

                <div class="portal-inner">

                    <h3>

                        <?php echo e($card['title']); ?>

                    </h3>

                    <div class="portal-desc">

                        <?php foreach ($card['desc'] as $desc): ?>

                            <span>

                                <?php echo e($desc); ?>

                            </span>

                        <?php endforeach; ?>

                    </div>

                </div>

            </a>

        <?php endforeach; ?>

    </div>

</div>

<!-- Footer -->
<?php require_once __DIR__ . '/public/lib/footer.php'; ?>

<!--
  Banner 轮播图 JavaScript
  功能：自动轮播 + 左右箭头切换 + 底部圆点指示器
  自动播放间隔：4000ms（4秒）
  使用 IIFE 避免全局变量污染
-->
<script>

(function(){
    // 获取所有幻灯片和指示圆点
    const slides = document.querySelectorAll('.slide');
    const dots = document.querySelectorAll('.dot');

    // 左右箭头按钮
    const prevBtn = document.querySelector('.banner-arrow--left');
    const nextBtn = document.querySelector('.banner-arrow--right');

    // 当前显示的幻灯片索引（0 起始）
    let current = 0;

    // 幻灯片总数
    const total = slides.length;

    /**
     * 切换到指定索引的幻灯片
     * 使用取模运算实现首尾循环
     *
     * @param {number} index 目标索引
     */
    function showSlide(index){
        // 移除当前激活状态
        slides[current].classList.remove('active');
        dots[current].classList.remove('active');

        // 计算新的索引（确保在 0 ~ total-1 范围内循环）
        current = (index + total) % total;

        // 激活新幻灯片和对应圆点
        slides[current].classList.add('active');
        dots[current].classList.add('active');
    }

    // 左箭头点击 → 上一张
    prevBtn.addEventListener('click', function(){
        showSlide(current - 1);
    });

    // 右箭头点击 → 下一张
    nextBtn.addEventListener('click', function(){
        showSlide(current + 1);
    });

    // 底部圆点点击 → 跳转到对应幻灯片
    dots.forEach(function(dot){
        dot.addEventListener('click', function(){
            showSlide(parseInt(this.dataset.index));
        });
    });

    // 自动轮播：每 4 秒切换到下一张
    setInterval(function(){
        showSlide(current + 1);
    }, 4000);

})();

</script>

</body>
</html>