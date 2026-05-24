<?php
//index.php - 首页
//Copyright (c) 2025-2026 Shaanxi Jima Cloud Education Innovation Studio
//website:extreme-code.cn
//数据库配置修改位于:根目录/config/database.php

//加载信息
//没设计容错，别tm乱删文件
//A1变量部分 
require __DIR__ . '/../sql/title.php';
require __DIR__ . '/../sql/keywords.php';
require __DIR__ . '/../sql/logo_1.php';
require __DIR__ . '/../sql/logo_2.php';
require __DIR__ . '/../sql/ico.php';
require __DIR__ . '/../sql/icp.php';
require __DIR__ . '/../sql/address.php';
require __DIR__ . '/../sql/postal_code.php';

//b1语言包加载
require_once __DIR__ . '/public/lib/language/zh-CN.php';//zh-CN语言包
function e($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

//c1页面数据加载
/*顶部链接*/
require_once __DIR__ . '/../sql/top_links.php';
/*导航*/
require_once __DIR__ . '/../sql/nav_links.php';
/*Banner*/
require_once __DIR__ . '/../sql/banner_imgs.php';
/*校园要闻*/
require_once __DIR__ . '/../sql/yaowen_list_q4.php';
/*综合新闻*/
require_once __DIR__ . '/../sql/index_news_q6.php';
/*学术交流*/
require_once __DIR__ . '/../sql/xueshu_list_q4.php';
/*媒体关注*/
require_once __DIR__ . '/../sql/meiti_list_q4.php';
/*门户*/
require_once __DIR__ . '/../sql/portal_cards.php';
?>

<!DOCTYPE html>
<html lang="zh-CN">

<head>

<meta charset="UTF-8">

<title><?php echo e($title); ?> - 首页</title>

<meta name="keywords" content="<?php echo e($keywords); ?>">

<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">

<link rel="icon" href="<?php echo e($ico); ?>" type="image/x-icon">

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

                校园要闻

                <a href="#" class="more">更多&gt;</a>

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

                综合新闻

                <a href="#" class="more">更多&gt;</a>

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

                学术交流

                <a href="#" class="more">更多&gt;</a>

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

                媒体关注

                <a href="#" class="more">更多&gt;</a>

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

<script>

(function(){

    const slides = document.querySelectorAll('.slide');
    const dots = document.querySelectorAll('.dot');

    const prevBtn = document.querySelector('.banner-arrow--left');
    const nextBtn = document.querySelector('.banner-arrow--right');

    let current = 0;

    const total = slides.length;

    function showSlide(index){

        slides[current].classList.remove('active');
        dots[current].classList.remove('active');

        current = (index + total) % total;

        slides[current].classList.add('active');
        dots[current].classList.add('active');

    }

    prevBtn.addEventListener('click', function(){

        showSlide(current - 1);

    });

    nextBtn.addEventListener('click', function(){

        showSlide(current + 1);

    });

    dots.forEach(function(dot){

        dot.addEventListener('click', function(){

            showSlide(parseInt(this.dataset.index));

        });

    });

    setInterval(function(){

        showSlide(current + 1);

    }, 4000);

})();

</script>

</body>
</html>