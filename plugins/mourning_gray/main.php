<?php
if ($hook === 'head_top') {
    $pct = max(1, min(100, (int) ($settings['gray_percent'] ?? 100)));
    echo "<style type=\"text/css\">html, body { -webkit-filter: grayscale({$pct}%); -moz-filter: grayscale({$pct}%); -ms-filter: grayscale({$pct}%); -o-filter: grayscale({$pct}%); filter: grayscale({$pct}%); filter: progid:DXImageTransform.Microsoft.BasicImage(grayscale=1); }</style>";
}