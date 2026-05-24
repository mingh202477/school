<?php
//index_news_q6.php
//date:26/5/22(0.0.1)
//index.php中被引入，获取最新的6条新闻数据
require_once __DIR__ . '/../sql/pdo.php';
$sql = "SELECT `title`, `date`, `url` FROM `news` ORDER BY `date` DESC LIMIT 6";
$stmt = $pdo->query($sql);

$xinwen_list = [];
while ($row = $stmt->fetch()) {
    $xinwen_list[] = [
        'title' => $row['title'],
        'date'  => $row['date'],   
        'link'  => $row['url'], 
    ];
}

?>