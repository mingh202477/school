<footer>

    <div class="wrap footer-content">

        <img src="<?php echo e($logo_2); ?>"
             alt=""
             class="footer-logo">

        <div class="footer-text">

            <?php if ($postal_code !== 'f'): ?>

                <p>

                    邮编：<?php echo e($postal_code); ?>

                </p>

            <?php endif; ?>

            <?php if ($address !== 'f'): ?>

                <p>

                    地址：<?php echo e($address); ?>

                </p>

            <?php endif; ?>

            <p>

                版权所有 © <?php echo e($title); ?>
                <!-- 开发者：陕西极码云教育创新工作室 开源项目 免费商用 -->
            </p>

            <?php if ($icp !== 'f'): ?>

                <p>

                    ICP备案号：<?php echo e($icp); ?>

                </p>

            <?php endif; ?>

        </div>

    </div>

</footer>