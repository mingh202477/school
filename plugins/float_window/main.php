<?php
if ($hook === 'footer_end') {
    $t  = htmlspecialchars((string) ($settings['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $s  = htmlspecialchars((string) ($settings['summary'] ?? ''), ENT_QUOTES, 'UTF-8');
    $u  = htmlspecialchars((string) ($settings['link_url'] ?? '#'), ENT_QUOTES, 'UTF-8');
    $bg = htmlspecialchars((string) ($settings['bg_color'] ?? '#16569a'), ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<div id="schoolFloatBay" style="position:fixed;left:40px;top:120px;z-index:99999;width:270px;background:{$bg};color:#fff;border:2px solid #fff;box-shadow:0 4px 14px rgba(0,0,0,0.3);font-family:'Microsoft YaHei',SimSun,sans-serif;">
    <div style="background:rgba(0,0,0,0.18);padding:5px 10px;font-size:12px;text-align:right;">
        <span style="float:left;font-weight:bold;">校园公告飘窗</span>
        <a href="javascript:void(0)" onclick="document.getElementById('schoolFloatBay').style.display='none';" style="color:#fff;text-decoration:none;font-weight:bold;">[×关闭]</a>
    </div>
    <a href="{$u}" style="display:block;padding:12px 14px;color:#fff;text-decoration:none;">
        <div style="font-size:14px;font-weight:bold;line-height:1.45;margin-bottom:6px;color:#ffeb3b;">{$t}</div>
        <div style="font-size:12px;line-height:1.6;opacity:0.95;">{$s}</div>
    </a>
</div>
<script type="text/javascript">
(function(){
    var el = document.getElementById('schoolFloatBay');
    if (!el) return;
    var x = 40, y = 120, vx = 1.6, vy = 1.3, paused = false;
    el.onmouseover = function(){ paused = true; };
    el.onmouseout  = function(){ paused = false; };
    setInterval(function(){
        if (paused || el.style.display === 'none') return;
        var maxW = (document.documentElement.clientWidth || document.body.clientWidth || 1024) - 280;
        var maxH = (document.documentElement.clientHeight || document.body.clientHeight || 768) - 130;
        x += vx; y += vy;
        if (x <= 0 || x >= maxW) vx = -vx;
        if (y <= 0 || y >= maxH) vy = -vy;
        el.style.left = Math.max(0, Math.round(x)) + 'px';
        el.style.top  = Math.max(0, Math.round(y)) + 'px';
    }, 25);
})();
</script>
HTML;
}