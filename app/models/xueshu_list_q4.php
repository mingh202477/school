<?php
require_once __DIR__ . '/../../app/models/ai_translate.php';
ai_ensure_schema();
$sql = "SELECT title, title_en, DATE_FORMAT(activity_date, '%m-%d') AS date, link, link_en
        FROM xueshu_activities
        ORDER BY activity_date DESC, sort_order ASC
        LIMIT 4";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$xueshu_list = $stmt->fetchAll();