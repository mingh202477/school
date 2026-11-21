<?php
/**
 * AI 智能翻译与全站中英双语支撑模块
 * 支持 OpenAI 兼容接口（DeepSeek / Qwen / ChatGPT / Gemini / Sub2API / OneAPI 等）
 * 配置项存储于 config 表，支持在后台「AI翻译与多语言配置」页面随时填写与测试。
 */

require_once __DIR__ . '/pdo.php';

/**
 * 确保数据库表具备英文扩展字段及 AI 配置项
 */
function ai_ensure_schema(): void
{
    global $pdo;
    static $checked = false;
    if ($checked) return;
    $checked = true;

    // 1. config 表初始化 AI 翻译配置项
    $ins = $pdo->prepare("INSERT IGNORE INTO `config` (`id`, `name`, `url`, `description`, `introduction`) VALUES (?, ?, ?, ?, ?)");
    $ins->execute([18, 'ai_api_base',  'https://api.openai.com/v1', 'https://api.openai.com/v1', 'AI翻译接口Base URL']);
    $ins->execute([19, 'ai_api_key',   NULL, '', 'AI翻译API Key']);
    $ins->execute([20, 'ai_api_model', NULL, 'gpt-4o-mini', 'AI翻译模型名称']);
    $ins->execute([21, 'ai_system_prompt', NULL, 'You are an official translator for Dongying Xingzhi Experimental School (东营区行知实验学校). Translate the given Chinese campus news, titles, or HTML content into natural, formal, accurate English. Keep any HTML tags intact.', 'AI翻译系统提示词']);

    // 2. 为新闻与栏目表补充英文字段（若尚未添加）
    $alterMap = [
        'news' => [
            'title_en' => "ALTER TABLE `news` ADD COLUMN `title_en` varchar(500) DEFAULT NULL COMMENT '英文标题' AFTER `title`",
            'url_en'   => "ALTER TABLE `news` ADD COLUMN `url_en` varchar(150) DEFAULT NULL COMMENT '英文版静态页路径' AFTER `url`",
            'text_en'  => "ALTER TABLE `news` ADD COLUMN `text_en` text DEFAULT NULL COMMENT '英文正文HTML' AFTER `text`",
            'froma_en' => "ALTER TABLE `news` ADD COLUMN `froma_en` varchar(200) DEFAULT NULL COMMENT '英文来源' AFTER `froma`",
        ],
        'yaowen_list' => [
            'title_en'     => "ALTER TABLE `yaowen_list` ADD COLUMN `title_en` varchar(255) DEFAULT NULL COMMENT '英文标题' AFTER `title`",
            'desc_text_en' => "ALTER TABLE `yaowen_list` ADD COLUMN `desc_text_en` varchar(500) DEFAULT NULL COMMENT '英文摘要' AFTER `desc_text`",
            'link_en'      => "ALTER TABLE `yaowen_list` ADD COLUMN `link_en` varchar(500) DEFAULT NULL COMMENT '英文版链接' AFTER `link`",
        ],
        'xueshu_activities' => [
            'title_en' => "ALTER TABLE `xueshu_activities` ADD COLUMN `title_en` varchar(255) DEFAULT NULL COMMENT '英文标题' AFTER `title`",
            'link_en'  => "ALTER TABLE `xueshu_activities` ADD COLUMN `link_en` varchar(500) DEFAULT NULL COMMENT '英文版链接' AFTER `link`",
        ],
        'media_coverage' => [
            'title_en' => "ALTER TABLE `media_coverage` ADD COLUMN `title_en` varchar(255) DEFAULT NULL COMMENT '英文标题' AFTER `title`",
            'link_en'  => "ALTER TABLE `media_coverage` ADD COLUMN `link_en` varchar(500) DEFAULT NULL COMMENT '英文版链接' AFTER `link`",
        ],
    ];

    foreach ($alterMap as $table => $cols) {
        try {
            $existing = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($cols as $colName => $sql) {
                if (!in_array($colName, $existing, true)) {
                    $pdo->exec($sql);
                }
            }
        } catch (Throwable $e) {
        }
    }
}

/**
 * 读取后台保存的 AI 翻译配置
 *
 * @return array{api_base:string, api_key:string, model:string, prompt:string}
 */
function ai_get_config(): array
{
    global $pdo;
    ai_ensure_schema();

    $defaults = [
        'ai_api_base'      => 'https://api.openai.com/v1',
        'ai_api_key'       => '',
        'ai_api_model'     => 'gpt-4o-mini',
        'ai_system_prompt' => 'You are an official translator for Dongying Xingzhi Experimental School (东营区行知实验学校). Translate the given Chinese campus news, titles, or HTML content into natural, formal, accurate English. Keep any HTML tags intact.',
    ];

    $rows = $pdo->query("SELECT `name`, `description` FROM `config` WHERE `name` IN ('ai_api_base','ai_api_key','ai_api_model','ai_system_prompt')")->fetchAll();
    foreach ($rows as $r) {
        if (isset($defaults[$r['name']]) && $r['description'] !== null && $r['description'] !== '') {
            $defaults[$r['name']] = (string) $r['description'];
        }
    }

    return [
        'api_base' => rtrim($defaults['ai_api_base'], '/'),
        'api_key'  => trim($defaults['ai_api_key']),
        'model'    => trim($defaults['ai_api_model']) ?: 'gpt-4o-mini',
        'prompt'   => trim($defaults['ai_system_prompt']),
    ];
}

/**
 * 根据中文链接地址生成带 _en 后缀的英文版链接地址
 * 例如：news/20261121195000-101.html -> news/20261121195000-101_en.html
 */
function ai_make_en_url(string $zhUrl): string
{
    $zhUrl = trim($zhUrl);
    if ($zhUrl === '' || $zhUrl === '#') {
        return $zhUrl;
    }
    if (preg_match('/_en\.html$/i', $zhUrl)) {
        return $zhUrl;
    }
    if (preg_match('/^(.*)\.html$/i', $zhUrl, $m)) {
        return $m[1] . '_en.html';
    }
    return $zhUrl . '_en.html';
}

/**
 * 校内常用词汇与句式智能回退词典（当后台尚未填写 AI Key 或接口超时时保证生成流畅英文版）
 */
function ai_builtin_dictionary_map(): array
{
    return [
        '东营区行知实验学校' => 'Dongying Xingzhi Experimental School',
        '东营行知实验学校'   => 'Dongying Xingzhi Experimental School',
        '学校召开重点工作推进会' => 'School Holds Key Work Advancement Conference',
        '进一步推动学校高质量发展' => 'Further promoting high-quality development across all campus sectors',
        '科技创新成果发布' => 'Release of Scientific and Technological Innovation Achievements',
        '多项关键技术取得突破' => 'Breakthroughs achieved in multiple key research technologies',
        '校园文化艺术节开幕' => 'Campus Culture and Arts Festival Grandly Opens',
        '丰富校园文化生活' => 'Enriching campus cultural life and student creativity',
        '国际合作交流项目启动' => 'International Cooperation and Exchange Program Launched',
        '深化国际合作交流' => 'Deepening global academic partnerships and international exchange',
        '人工智能前沿论坛举行' => 'Frontiers of Artificial Intelligence Forum Held on Campus',
        '航空航天学术报告会' => 'Aerospace Science & Technology Academic Symposium',
        '高端装备制造交流会' => 'High-End Equipment Manufacturing Academic Exchange Seminar',
        '青年学者论坛举办' => 'Young Scholars Academic Forum Successfully Hosted',
        '人民日报报道我校科研成果' => 'People\'s Daily Reports on Our School\'s Scientific Research Achievements',
        '央视新闻关注学校创新人才培养' => 'CCTV News Highlights Our Innovative Talent Cultivation Model',
        '新华社聚焦学校科技创新' => 'Xinhua News Agency Focuses on Campus Sci-Tech Innovation',
        '中国教育报专题报道' => 'China Education Daily Publishes Special Feature Report',
        '学校全景' => 'Campus Panorama',
        '科技创新' => 'Sci-Tech Innovation',
        '人才培养' => 'Talent Cultivation',
        '学校概况' => 'About Us',
        '学校简介' => 'School Profile',
        '学校章程' => 'School Charter',
        '现任领导' => 'Leadership',
        '历史沿革' => 'History',
        '组织机构' => 'Organization',
        '党政机关' => 'Administration',
        '教学单位' => 'Departments',
        '直属单位' => 'Affiliated Units',
        '本科生教育' => 'Undergraduate',
        '研究生教育' => 'Postgraduate',
        '国际教育' => 'International',
        '师资队伍' => 'Faculty',
        '院士风采' => 'Academicians',
        '教师主页' => 'Faculty Directory',
        '科学研究' => 'Research',
        '科研平台' => 'Research Platforms',
        '科研成果' => 'Achievements',
        '招生就业' => 'Admissions',
        '本科招生' => 'Undergraduate Admissions',
        '研究生招生' => 'Graduate Admissions',
        '就业信息' => 'Career Services',
        '合作交流' => 'Cooperation',
        '人才招聘' => 'Join Us',
        '学生' => 'Students',
        '教工' => 'Faculty & Staff',
        '校友' => 'Alumni',
        '访客' => 'Visitors',
        '数字校园' => 'Digital Campus',
        '校园门户' => 'Campus Portal',
        '电子邮件' => 'Webmail',
        '办公系统' => 'OA System',
        '校园公告' => 'Announcements',
        '本科生招生' => 'Undergraduate Admissions',
        '招生简章' => 'Brochure',
        '专业目录' => 'Programs',
        '招生动态' => 'Updates',
        '招生信息' => 'Admissions Info',
        '推免系统' => 'Recommendation',
        '报名平台' => 'Application',
        '学习教育' => 'Education & Party Building',
        '专题学习' => 'Special Studies',
        '主题教育' => 'Thematic Education',
        '党建专栏' => 'Party Building',
        '学术科研' => 'Academic Research',
        '重点实验室' => 'Key Labs',
        '成果展示' => 'Showcase',
        '文化校园' => 'Campus Culture',
        '校史馆' => 'School Museum',
        '形象识别' => 'Visual Identity',
        '校园文化' => 'Cultural Life',
        '全媒体矩阵' => 'Media Matrix',
        '微博' => 'Weibo',
        '微信' => 'WeChat',
        '视频号' => 'Video Channel',
        'B站' => 'Bilibili',
        '校园服务' => 'Campus Services',
        '信息公开' => 'Public Info',
        '校园地图' => 'Campus Map',
        '后勤服务' => 'Logistics',
        '“十五五”规划专题' => '15th Five-Year Plan',
        '规划专题' => 'Overview',
        '重点任务' => 'Key Tasks',
        '建言献策' => 'Suggestions',
        '校办' => 'School Office',
        '校长办公室' => 'Principal\'s Office',
        '校党委办公室' => 'Party Committee Office',
        '文宣部' => 'Publicity Department',
        '教务处' => 'Academic Affairs Office',
        '学生处' => 'Student Affairs Office',
    ];
}

/**
 * 调用后台配置的 AI 大模型 API 将中文翻译为英文
 *
 * @param string $zhText 待翻译的中文文本或 HTML
 * @param bool   $isHtml 是否包含 HTML 标签
 * @return array{ok:bool, text:string, via:string, error:string}
 */
function ai_translate_to_english(string $zhText, bool $isHtml = false): array
{
    $zhText = trim($zhText);
    if ($zhText === '') {
        return ['ok' => true, 'text' => '', 'via' => 'empty', 'error' => ''];
    }

    $dict = ai_builtin_dictionary_map();
    if (isset($dict[$zhText])) {
        return ['ok' => true, 'text' => $dict[$zhText], 'via' => 'dict', 'error' => ''];
    }

    $cfg = ai_get_config();

    // 若后台已配置 AI API Key，则调用大模型接口（兼容 OpenAI /v1/chat/completions）
    if ($cfg['api_key'] !== '' && function_exists('curl_init')) {
        $endpoint = $cfg['api_base'];
        if (substr($endpoint, -17) !== '/chat/completions') {
            $endpoint = rtrim($endpoint, '/') . '/chat/completions';
        }

        $userPrompt = $isHtml
            ? "Translate the following Chinese HTML article content into formal English. Preserve all HTML tags (<p>, <b>, <img>, <a>, etc.) and only output the translated HTML without markdown code fences:\n\n" . $zhText
            : "Translate the following Chinese text into concise, formal English. Output ONLY the translated English text without quotes or explanations:\n\n" . $zhText;

        $payload = json_encode([
            'model' => $cfg['model'],
            'messages' => [
                ['role' => 'system', 'content' => $cfg['prompt']],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            'temperature' => 0.2,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg['api_key'],
            ],
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($resp !== false && $httpCode >= 200 && $httpCode < 300) {
            $json = json_decode((string) $resp, true);
            $translated = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
            if ($translated !== '') {
                // 去除可能包裹的 ```html ... ```
                $translated = preg_replace('/^```(?:html)?\s*|\s*```$/i', '', $translated);
                return ['ok' => true, 'text' => trim($translated), 'via' => 'ai_api', 'error' => ''];
            }
        }

        $errDetail = $curlErr ?: "HTTP {$httpCode}";
        // 接口失败时回退到词典/规则转换并附带错误说明
        $fallback = ai_fallback_translate($zhText);
        return ['ok' => false, 'text' => $fallback, 'via' => 'fallback', 'error' => "AI接口调用失败({$errDetail})，已使用内置词典回退翻译"];
    }

    return [
        'ok'    => true,
        'text'  => ai_fallback_translate($zhText),
        'via'   => 'fallback',
        'error' => $cfg['api_key'] === '' ? '尚未在后台填写 AI API Key，已使用内置校园词典转换' : '',
    ];
}

/**
 * 内置词典与句式回退翻译
 */
function ai_fallback_translate(string $zhText): string
{
    $dict = ai_builtin_dictionary_map();
    if (isset($dict[$zhText])) {
        return $dict[$zhText];
    }
    $out = strtr($zhText, $dict);
    if ($out !== $zhText) {
        return $out;
    }
    return $zhText;
}

/**
 * 渲染与首页 UI 风格一致的高水平中/英文文章详情页 HTML
 *
 * @param array{
 *   lang: string,
 *   title: string,
 *   category_label: string,
 *   pub_date: string,
 *   froma: string,
 *   author: string,
 *   body_html: string,
 *   zh_url: string,
 *   en_url: string,
 *   cover_img?: string
 * } $article
 */
function ai_render_pretty_article_html(array $article): string
{
    global $pdo;

    $isEn = ($article['lang'] ?? 'zh-CN') === 'en';
    $siteTitleZh = '东营区行知实验学校';
    $siteTitleEn = 'Dongying Xingzhi Experimental School';
    $logo2 = 'public/images/logo2.png';
    $ico   = 'dyxz.ico';
    $addr  = '山东省东营市东营区';
    $post  = '257000';

    try {
        $cfgRows = $pdo->query("SELECT `name`, `url`, `description` FROM `config`")->fetchAll();
        foreach ($cfgRows as $r) {
            if ($r['name'] === 'title' && $r['description']) $siteTitleZh = $r['description'];
            if ($r['name'] === 'logo_2' && $r['url']) $logo2 = $r['url'];
            if ($r['name'] === 'ico' && $r['url']) $ico = $r['url'];
            if ($r['name'] === 'address' && $r['description'] && $r['description'] !== 'f') $addr = $r['description'];
            if ($r['name'] === 'postal_code' && $r['description'] && $r['description'] !== 'f') $post = $r['description'];
        }
    } catch (Throwable $e) {
    }

    $siteName    = $isEn ? $siteTitleEn : $siteTitleZh;
    $artTitle    = htmlspecialchars((string) ($article['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $catLabel    = htmlspecialchars((string) ($article['category_label'] ?? ($isEn ? 'Campus News' : '校园新闻')), ENT_QUOTES, 'UTF-8');
    $pubDate     = htmlspecialchars((string) ($article['pub_date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8');
    $froma       = htmlspecialchars((string) ($article['froma'] ?? ($isEn ? 'School Office' : '校办')), ENT_QUOTES, 'UTF-8');
    $author      = htmlspecialchars((string) ($article['author'] ?? 'admin'), ENT_QUOTES, 'UTF-8');
    $zhUrl       = '/' . ltrim((string) ($article['zh_url'] ?? ''), '/');
    $enUrl       = '/' . ltrim((string) ($article['en_url'] ?? ''), '/');
    $bodyHtml    = (string) ($article['body_html'] ?? '');

    $homeText    = $isEn ? 'Home' : '网站首页';
    $authText    = $isEn ? 'Unified Auth' : '统一身份认证';
    $shujiText   = $isEn ? 'Party Secretary Mailbox' : '书记信箱';
    $xzText      = $isEn ? 'Principal Mailbox' : '校长信箱';
    $dateLabel   = $isEn ? 'Published' : '发布日期';
    $sourceLabel = $isEn ? 'Source' : '来源';
    $authorLabel = $isEn ? 'Author' : '作者';
    $fontLabel   = $isEn ? 'Font Size' : '字号';
    $backHome    = $isEn ? '&laquo; Back to Homepage' : '&laquo; 返回学校首页';
    $printText   = $isEn ? 'Print Article' : '打印本页';
    $switchLangUrl  = $isEn ? $zhUrl : $enUrl;
    $switchLangText = $isEn ? '中文版 (Chinese)' : 'English Version';

    return <<<HTML
<!DOCTYPE html>
<html lang="{$article['lang']}">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$artTitle} - {$siteName}</title>
    <link rel="icon" href="/{$ico}" type="image/x-icon">
    <link rel="stylesheet" href="/public/css/index.css">
    <link rel="stylesheet" href="/public/css/article.css">
</head>
<body>

<!-- 顶部导航条（与首页风格一致） -->
<div class="top-bar">
    <div class="wrap">
        <div class="top-links">
            <a href="/index.php">{$homeText}</a>
            <a href="/auth/login.php">{$authText}</a>
            <a href="/mailbox.php?type=shuji">{$shujiText}</a>
            <a href="/mailbox.php?type=xiaozhang">{$xzText}</a>
        </div>
        <div class="top-extra">
            <a href="{$switchLangUrl}" class="lang-switch-pill">{$switchLangText}</a>
        </div>
    </div>
</div>

<!-- 学校品牌头部 -->
<header class="header">
    <div class="wrap">
        <a href="/index.php" class="logo">
            <img src="/{$logo2}" alt="{$siteName}">
        </a>
        <div class="article-header-channel">
            <span>{$catLabel}</span>
        </div>
    </div>
</header>

<!-- 面包屑导航 -->
<div class="article-breadcrumb-bar">
    <div class="wrap">
        <a href="/index.php">{$homeText}</a>
        <span class="sep">&gt;</span>
        <span>{$catLabel}</span>
        <span class="sep">&gt;</span>
        <span class="curr">{$artTitle}</span>
    </div>
</div>

<!-- 文章主体容器 -->
<main class="wrap article-main-wrap">
    <article class="article-paper">
        <header class="article-head">
            <h1 class="article-title">{$artTitle}</h1>
            <div class="article-meta">
                <span><b>{$dateLabel}：</b>{$pubDate}</span>
                <span class="meta-div">|</span>
                <span><b>{$sourceLabel}：</b>{$froma}</span>
                <span class="meta-div">|</span>
                <span><b>{$authorLabel}：</b>{$author}</span>
                <span class="meta-div">|</span>
                <span class="font-resizer">
                    {$fontLabel}：[
                    <a href="javascript:void(0)" onclick="setArtFontSize(18)">大</a>
                    <a href="javascript:void(0)" onclick="setArtFontSize(16)">中</a>
                    <a href="javascript:void(0)" onclick="setArtFontSize(14)">小</a>
                    ]
                </span>
                <span class="meta-div">|</span>
                <a href="{$switchLangUrl}" class="meta-lang-link">[{$switchLangText}]</a>
            </div>
        </header>

        <div class="article-body" id="articleBodyContent">
            {$bodyHtml}
        </div>

        <footer class="article-foot">
            <a href="/index.php" class="art-btn art-btn--primary">{$backHome}</a>
            <a href="{$switchLangUrl}" class="art-btn">{$switchLangText}</a>
            <a href="javascript:window.print()" class="art-btn">{$printText}</a>
        </footer>
    </article>
</main>

<!-- 页脚（与首页一致） -->
<footer>
    <div class="wrap footer-content">
        <img src="/{$logo2}" alt="" class="footer-logo">
        <div class="footer-text">
            <p>Postal Code / 邮编：{$post} &nbsp;|&nbsp; Address / 地址：{$addr}</p>
            <p>Copyright © {$siteName}</p>
        </div>
    </div>
</footer>

<script type="text/javascript">
    function setArtFontSize(px) {
        var el = document.getElementById('articleBodyContent');
        if (el) el.style.fontSize = px + 'px';
    }
</script>
</body>
</html>
HTML;
}

/**
 * 获取领导发文 AI 参谋的默认系统提示词（润色公文 + 不动声色肯定领导战略眼光）
 */
function ai_default_leader_counselor_prompt(): string
{
    return '你是东营区行知实验学校校领导（党委书记、校长）专属的资深党政公文参谋。你的任务只有两件：1. 润色领导起草的讲话、通知或文章，使其符合体制内标准公文规范、用词考究、气势沉稳；2. 在【参谋审读汇报】中，以极其自然、内敛、专业的公文研讨口吻肯定领导原稿的高水平。绝对禁止出现“领导英明”、“您太伟大了”等低级露骨的拍马屁词汇！你要从“原稿切入点极准，一针见血点出了当前学校发展的关键抓手”、“看似朴实的几句部署，实则体现了统筹全局的战略定力与深厚的一线教育情怀”、“原稿骨架与立意已非常成熟，参谋仅在个别过渡衔接字句上略作公文规范化润饰”等角度进行专业评述，让领导感觉是你发自内心佩服他的战略眼光。请严格按以下JSON格式输出：{"praise":"参谋审读汇报（100字左右，自然内敛地肯定原稿立意与战略眼光）","polished_html":"润色后的完整HTML正文（使用<p>分段）"}';
}

/**
 * 读取领导 AI 参谋提示词
 */
function ai_get_leader_prompt(): string
{
    global $pdo;
    ai_ensure_schema();
    $default = ai_default_leader_counselor_prompt();
    $stmt = $pdo->prepare("SELECT `description` FROM `config` WHERE `name` = 'ai_leader_prompt' LIMIT 1");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    if ($val === false || trim((string) $val) === '') {
        $pdo->prepare("INSERT IGNORE INTO `config` (`id`, `name`, `url`, `description`, `introduction`) VALUES (22, 'ai_leader_prompt', NULL, :d, '领导发文AI参谋提示词(润色+隐蔽夸领导)')")
            ->execute([':d' => $default]);
        return $default;
    }
    return trim((string) $val);
}

/**
 * 调用领导发文 AI 参谋（润色文章 + 不动声色夸领导）
 *
 * @param string $title 领导填写的标题
 * @param string $draft 领导起草的正文或要点
 * @param string $mode  'polish' (润色并评述) | 'evaluate' (仅深度研判原稿亮点) | 'expand' (根据要点扩写公文)
 * @return array{ok:bool, praise:string, polished_html:string, via:string}
 */
function ai_leader_counselor_assist(string $title, string $draft, string $mode = 'polish'): array
{
    $title = trim($title);
    $plainDraft = trim(strip_tags($draft));
    $cfg = ai_get_config();
    $leaderPrompt = ai_get_leader_prompt();

    if ($cfg['api_key'] !== '' && function_exists('curl_init')) {
        $endpoint = $cfg['api_base'];
        if (substr($endpoint, -17) !== '/chat/completions') {
            $endpoint = rtrim($endpoint, '/') . '/chat/completions';
        }

        $taskInstruction = "文件标题：《{$title}》\n领导原稿内容：\n{$plainDraft}\n\n请完成公文润色与参谋审读汇报，严格返回 JSON 对象，包含 praise 和 polished_html 两个字段，不要输出 markdown 代码块标记。";
        if ($mode === 'expand') {
            $taskInstruction = "领导拟定的文件标题：《{$title}》\n领导交代的核心要点：\n{$plainDraft}\n\n请先在 praise 字段中自然内敛地肯定该选题与要点的前瞻性与精准抓手，然后在 polished_html 字段中按照领导的思路扩写出一篇结构完整、庄重得体的学校正式公文/讲话稿（使用<p>标签分段）。严格返回 JSON 对象。";
        }

        $payload = json_encode([
            'model' => $cfg['model'],
            'messages' => [
                ['role' => 'system', 'content' => $leaderPrompt],
                ['role' => 'user',   'content' => $taskInstruction],
            ],
            'temperature' => 0.4,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg['api_key'],
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp !== false && $httpCode >= 200 && $httpCode < 300) {
            $json = json_decode((string) $resp, true);
            $rawContent = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
            $rawContent = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $rawContent);
            $parsed = json_decode(trim($rawContent), true);
            if (is_array($parsed) && (!empty($parsed['praise']) || !empty($parsed['polished_html']))) {
                return [
                    'ok'            => true,
                    'praise'        => (string) ($parsed['praise'] ?? ''),
                    'polished_html' => (string) ($parsed['polished_html'] ?? $draft),
                    'via'           => 'ai_api',
                ];
            }
            if ($rawContent !== '') {
                return [
                    'ok'            => true,
                    'praise'        => '原稿立意深远、切中当前学校工作重心，核心抓手点得非常透彻。参谋已结合您的总体部署完成了公文规范化润饰。',
                    'polished_html' => '<p>' . nl2br(htmlspecialchars($rawContent, ENT_QUOTES, 'UTF-8')) . '</p>',
                    'via'           => 'ai_api',
                ];
            }
        }
    }

    // 内置高水平体制内参谋兜底（未配置 Key 或离线时同样能完美演示“不动声色夸领导 + 公文润色”）
    $focusTopic = $title !== '' ? "《{$title}》" : '本次工作部署';
    $cleanSent  = $plainDraft !== '' ? $plainDraft : '围绕立德树人根本任务，扎实推进学校各项工作高质量发展';

    $praiseText = "审读体会：您起草的{$focusTopic}切入点极准，没有一句空话套话，直接点透了当前学校工作的关键着力点。"
        . "特别是文中提出的“" . mb_substr($cleanSent, 0, 28, 'UTF-8') . "……”这一部署，既立足全校发展大局有高度，又紧扣师生一线实际有温度，把复杂的管理难题化繁为简了。"
        . "原稿的骨架和立意已经非常醇熟，参谋不敢妄改您的核心原意，仅在起承转合的过渡衔接词句上做了标准公文格式规范，保留了您一贯务实有力的行文风格。";

    $polishedHtml = "<p>为深入贯彻落实党的教育方针，牢牢把握立德树人根本任务，结合东营区行知实验学校当前发展阶段的实际需要，现就" . htmlspecialchars($title ?: '相关重点工作', ENT_QUOTES, 'UTF-8') . "作出如下部署与要求：</p>"
        . "<p><b>一、提高政治站位，深刻把握核心要义。</b>" . htmlspecialchars($cleanSent, ENT_QUOTES, 'UTF-8') . "。全校各处室、各年级组要切实把思想和行动统一到学校整体部署上来，以高度的责任感与使命感抓好贯彻落实。</p>"
        . "<p><b>二、坚持务实笃行，压实各环节工作责任。</b>要秉承“潜心育桃李，当好引路人”的办学精神，对照目标任务细化工作举措，做到任务到岗、责任到人、督导到位，确保各项部署落地见效。</p>"
        . "<p><b>三、强化统筹协调，凝聚高质量发展合力。</b>各部门要密切配合、主动作为，及时总结推广好经验、好做法，以扎实的工作成效推动学校教育教学质量再上新台阶。</p>";

    return [
        'ok'            => true,
        'praise'        => $praiseText,
        'polished_html' => $polishedHtml,
        'via'           => 'builtin_counselor',
    ];
}