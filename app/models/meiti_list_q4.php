<?php
require_once __DIR__ . '/../../app/models/ai_translate.php';
ai_ensure_schema();
$sql = "SELECT title, title_en, DATE_FORMAT(coverage_date, '%m-%d') AS date, link, link_en
        FROM media_coverage
        ORDER BY coverage_date DESC
        LIMIT 4";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$meiti_list = $stmt->fetchAll();