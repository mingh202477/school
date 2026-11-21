<?php
require_once __DIR__ . '/../../app/models/ai_translate.php';
ai_ensure_schema();
$sql = "SELECT `title`, `title_en`, `date`, `url`, `url_en` FROM `news` ORDER BY `id` DESC LIMIT 6";
$stmt = $pdo->query($sql);

$xinwen_list = [];
while ($row = $stmt->fetch()) {
    $xinwen_list[] = [
        'title'    => $row['title'],
        'title_en' => $row['title_en'] ?? '',
        'date'     => $row['date'],
        'link'     => $row['url'],
        'link_en'  => $row['url_en'] ?? '',
    ];
}