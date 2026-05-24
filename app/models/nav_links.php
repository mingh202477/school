<?php
require_once __DIR__ . '/../../app/models/pdo.php';
$sql_parent = "SELECT id, text, url FROM nav_links WHERE parent_id IS NULL ORDER BY sort_order ASC";
$stmt_parent = $pdo->prepare($sql_parent);
$stmt_parent->execute();
$parents = $stmt_parent->fetchAll();
$sql_child = "SELECT id, parent_id, text, url FROM nav_links WHERE parent_id IS NOT NULL ORDER BY parent_id ASC, sort_order ASC";
$stmt_child = $pdo->prepare($sql_child);
$stmt_child->execute();
$children = $stmt_child->fetchAll();
$children_grouped = [];
foreach ($children as $child) {
    $children_grouped[$child['parent_id']][] = [
        'text' => $child['text'],
        'url'  => $child['url'],
    ];
}
$nav_links = [];
foreach ($parents as $parent) {
    $item = [
        'text' => $parent['text'],
        'url'  => $parent['url'],
    ];
    if (isset($children_grouped[$parent['id']])) {
        $item['children'] = $children_grouped[$parent['id']];
    }
    $nav_links[] = $item;
}
