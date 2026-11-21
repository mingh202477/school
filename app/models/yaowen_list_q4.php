<?php
require_once __DIR__ . '/../../app/models/ai_translate.php';
ai_ensure_schema();
$sql = "SELECT img, title, title_en, desc_text AS `desc`, desc_text_en AS `desc_en`, link, link_en
        FROM yaowen_list
        ORDER BY publish_date DESC, sort_order ASC
        LIMIT 4";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$yaowen_list = $stmt->fetchAll();