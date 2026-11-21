<?php $isEnFooter = (isset($lang) && $lang === 'en') || (isset($_COOKIE['lang']) && $_COOKIE['lang'] === 'en'); ?>
<footer>

    <div class="wrap footer-content">

        <img src="<?php echo e($logo_2); ?>"
             alt=""
             class="footer-logo">

        <div class="footer-text">

            <?php if ($postal_code !== 'f'): ?>
                <p><?php echo $isEnFooter ? 'Postal Code: ' : '邮编：'; ?><?php echo e($postal_code); ?></p>
            <?php endif; ?>

            <?php if ($address !== 'f'): ?>
                <p><?php echo $isEnFooter ? 'Address: ' : '地址：'; ?><?php echo e($address); ?></p>
            <?php endif; ?>

            <p>
                <?php echo $isEnFooter ? 'Copyright © ' : '版权所有 © '; ?><?php echo e($title); ?>
            </p>

            <?php if ($icp !== 'f'): ?>
                <p><?php echo $isEnFooter ? 'ICP Filing: ' : 'ICP备案号：'; ?><?php echo e($icp); ?></p>
            <?php endif; ?>

            <?php if (!empty($traffic_stats) && is_array($traffic_stats)): ?>
                <?php if ($isEnFooter): ?>
                    <p style="margin-top:6px;font-size:12px;opacity:0.9;">
                        Traffic Stats: Today PV <?php echo (int) $traffic_stats['today_pv']; ?> ｜
                        Today UV <?php echo (int) $traffic_stats['today_uv']; ?> ｜
                        Online Now <?php echo (int) $traffic_stats['online_count']; ?> ｜
                        Total Visits <?php echo (int) $traffic_stats['total_pv']; ?>
                    </p>
                <?php else: ?>
                    <p style="margin-top:6px;font-size:12px;opacity:0.9;">
                        流量统计：今日访问 <?php echo (int) $traffic_stats['today_pv']; ?> 次 ｜
                        今日独立访客(UV) <?php echo (int) $traffic_stats['today_uv']; ?> 人 ｜
                        当前在线 <?php echo (int) $traffic_stats['online_count']; ?> 人 ｜
                        总访问量 <?php echo (int) $traffic_stats['total_pv']; ?> 次
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (function_exists('footer_badges_render_html')) echo footer_badges_render_html(''); ?>

        </div>

    </div>

</footer>
<?php require_once __DIR__ . '/../../../app/models/plugin_manager.php'; plugin_run_hook('footer_end'); ?>