<?php
if ($hook === 'head_top') {
    $ev  = htmlspecialchars((string) ($settings['event_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $dt  = trim((string) ($settings['target_date'] ?? '2027-06-15'));
    $sl  = htmlspecialchars((string) ($settings['slogan'] ?? ''), ENT_QUOTES, 'UTF-8');
    $bg  = htmlspecialchars((string) ($settings['bg_color'] ?? '#b91c1c'), ENT_QUOTES, 'UTF-8');
    $targetTs = strtotime($dt . ' 00:00:00');
    $days = $targetTs ? max(0, (int) ceil(($targetTs - time()) / 86400)) : 0;
    echo "<div style=\"background:{$bg};color:#fff;text-align:center;padding:9px 14px;font-size:14px;letter-spacing:0.5px;border-bottom:2px solid #fbbf24;\">"
        . "★ 距离 <b>{$ev}</b>（{$dt}）还有 <span style=\"display:inline-block;padding:1px 8px;margin:0 4px;background:#fef08a;color:#991b1b;font-size:17px;font-weight:bold;border-radius:2px;\">{$days}</span> 天 ｜ {$sl}"
        . "</div>";
}