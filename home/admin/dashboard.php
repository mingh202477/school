<?php
require_once __DIR__ . '/../../app/models/admin_import.php';
require_once __DIR__ . '/../../app/models/mailer.php';
require_once __DIR__ . '/../../app/models/traffic.php';
require_once __DIR__ . '/../../app/models/ai_translate.php';
require_once __DIR__ . '/../../app/models/plugin_manager.php';
ai_ensure_schema();

auth_require_admin();

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * 通用图片上传或链接二合一处理函数：
 * 若用户上传了本地文件则保存并返回新相对路径；若未上传文件则使用填写的链接/路径。
 */
function admin_resolve_image_input(string $fileKey, string $urlInput, string $subDir = 'public/images/', string $prefix = 'img_'): string
{
    $urlInput = trim($urlInput);
    $allowedExts = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'svg', 'bmp'];

    if (!empty($_FILES[$fileKey]['tmp_name']) && is_uploaded_file($_FILES[$fileKey]['tmp_name'])) {
        $ext = strtolower(pathinfo((string) $_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExts, true)) {
            $targetDir = dirname(__DIR__) . '/' . $subDir;
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }
            $relPath = $subDir . $prefix . date('YmdHis') . '_' . random_int(100, 999) . '.' . $ext;
            $absPath = dirname(__DIR__) . '/' . $relPath;
            if (@move_uploaded_file($_FILES[$fileKey]['tmp_name'], $absPath)) {
                return $relPath;
            }
        }
    }
    return $urlInput;
}

// 富文本编辑器异步上传本地图片接口
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'upload_rte_image') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!csrf_verify()) {
        echo json_encode(['ok' => false, 'msg' => 'CSRF校验失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $path = admin_resolve_image_input('rte_img_file', '', 'public/images/', 'rte_');
    if ($path === '') {
        echo json_encode(['ok' => false, 'msg' => '上传失败，请选择有效的图片文件（png/jpg/gif/webp/svg）'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => true, 'url' => '../' . $path, 'rel' => $path], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 退出登录
if (($_GET['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

// 导出插件 .zip 或下载标准插件开发模板 .zip
if (($_GET['action'] ?? '') === 'export_plugin_zip') {
    plugin_download_zip((string) ($_GET['pid'] ?? '__template__'));
}

// 下载导入模板 (.xls / .xlxs / .txt / .csv / .dbf)
if (($_GET['action'] ?? '') === 'download_tpl') {
    admin_output_import_template((string) ($_GET['ext'] ?? 'csv'));
}

// 异步保存线上填写缓存（Ctrl+S 或定时自动保存）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save_online_draft') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!csrf_verify()) {
        echo json_encode(['ok' => false, 'msg' => 'CSRF校验失败'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['admin_import_online_draft'] = (string) ($_POST['online_text'] ?? '');
    $_SESSION['admin_import_autosave_sec'] = max(5, (int) ($_POST['autosave_sec'] ?? 30));
    echo json_encode(['ok' => true, 'time' => date('H:i:s')], JSON_UNESCAPED_UNICODE);
    exit;
}

$tab = (string) ($_GET['tab'] ?? 'users');
$msg = '';
$err = '';

// ==================== 处理各模块 POST 提交 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $err = '请求已失效（CSRF token 不匹配），请刷新页面重试。';
    } else {
        $formAction = (string) ($_POST['form_action'] ?? '');

// 修改当前登录管理员自己的个人资料与密码
        if ($formAction === 'save_admin_profile') {
            $tab = 'profile';
            $myId    = (int) ($_SESSION['auth_user_id'] ?? 0);
            $newU    = trim((string) ($_POST['username'] ?? ''));
            $newName = trim((string) ($_POST['real_name'] ?? ''));
            $newMail = trim((string) ($_POST['email'] ?? ''));
            $newType = trim((string) ($_POST['id_card_type'] ?? '居民身份证'));
            $newIdNo = trim((string) ($_POST['id_card_number'] ?? ''));
            $newPwd  = (string) ($_POST['new_password'] ?? '');
            $newPwd2 = (string) ($_POST['confirm_password'] ?? '');
            $secQ    = trim((string) ($_POST['security_question'] ?? ''));
            $secA    = trim((string) ($_POST['security_answer'] ?? ''));

            if ($myId <= 0 || $newU === '' || $newName === '') {
                $err = '账号和真实姓名不能为空。';
            } elseif ($newPwd !== '' && $newPwd !== $newPwd2) {
                $err = '两次输入的新密码不一致。';
            } elseif ($newPwd !== '' && strlen($newPwd) < 6) {
                $err = '新密码长度不能少于 6 位。';
            } else {
                // 检查账号或邮箱是否与其他人冲突
                $chk = $pdo->prepare("SELECT `id` FROM `users` WHERE (`username` = :u OR (:e != '' AND `email` = :e2)) AND `id` != :id LIMIT 1");
                $chk->execute([':u' => $newU, ':e' => $newMail, ':e2' => $newMail, ':id' => $myId]);
                if ($chk->fetch()) {
                    $err = '该账号或绑定邮箱已被其他用户占用。';
                } else {
                    $sqlParts = [
                        '`username` = :u',
                        '`real_name` = :n',
                        '`email` = :e',
                        '`id_card_type` = :t',
                    ];
                    $params = [
                        ':u'  => $newU,
                        ':n'  => $newName,
                        ':e'  => $newMail !== '' ? $newMail : null,
                        ':t'  => $newType,
                        ':id' => $myId,
                    ];
                    if ($newIdNo !== '') {
                        if ($newType === '居民身份证' && substr($newIdNo, -1) === 'x') {
                            $err = '身份证末尾是X的需要大写。';
                        } else {
                            $sqlParts[] = '`id_card_hash` = :ih';
                            $sqlParts[] = '`id_card_mask` = :im';
                            $sqlParts[] = '`initial_password_hash` = :iph';
                            $params[':ih']  = auth_hash_id_card($newIdNo);
                            $params[':im']  = auth_mask_id_card($newIdNo);
                            $params[':iph'] = auth_initial_password_hash_from_id_card($newIdNo);
                        }
                    }
                    if ($err === '' && $newPwd !== '') {
                        $sqlParts[] = '`password_hash` = :ph';
                        $sqlParts[] = '`is_initial_pwd` = 0';
                        $params[':ph'] = password_hash($newPwd, PASSWORD_ARGON2ID);
                    }
                    if ($err === '' && $secQ !== '') {
                        $sqlParts[] = '`security_question` = :sq';
                        $params[':sq'] = $secQ;
                        if ($secA !== '') {
                            $sqlParts[] = '`security_answer_hash` = :sa';
                            $params[':sa'] = password_hash($secA, PASSWORD_ARGON2ID);
                        }
                    }
                    if ($err === '') {
                        $updSql = "UPDATE `users` SET " . implode(', ', $sqlParts) . " WHERE `id` = :id";
                        $pdo->prepare($updSql)->execute($params);
                        $_SESSION['auth_user']      = $newU;
                        $_SESSION['auth_real_name'] = $newName;
                        $msg = '您的管理员个人资料已更新成功！';
                    }
                }
            }
        }
        // 在用户管理中编辑任意用户的完整信息
        elseif ($formAction === 'edit_single_user') {
            $tab     = 'users';
            $uid     = (int) ($_POST['edit_user_id'] ?? 0);
            $newU    = trim((string) ($_POST['username'] ?? ''));
            $newName = trim((string) ($_POST['real_name'] ?? ''));
            $newMail = trim((string) ($_POST['email'] ?? ''));
            $newRole = trim((string) ($_POST['role'] ?? 'student'));
            $newStat = (int) ($_POST['status'] ?? 1);
            $newType = trim((string) ($_POST['id_card_type'] ?? '居民身份证'));
            $newIdNo = trim((string) ($_POST['id_card_number'] ?? ''));
            $newPwd  = (string) ($_POST['new_password'] ?? '');

            if ($uid <= 0 || $newU === '' || $newName === '') {
                $err = '账号和姓名不能为空。';
            } else {
                $sqlParts = [
                    '`username` = :u',
                    '`real_name` = :n',
                    '`email` = :e',
                    '`role` = :r',
                    '`status` = :s',
                    '`id_card_type` = :t',
                ];
                $params = [
                    ':u'  => $newU,
                    ':n'  => $newName,
                    ':e'  => $newMail !== '' ? $newMail : null,
                    ':r'  => in_array($newRole, ['admin','leader','teacher','student','alumni'], true) ? $newRole : 'student',
                    ':s'  => in_array($newStat, [0,1,2], true) ? $newStat : 1,
                    ':t'  => $newType,
                    ':id' => $uid,
                ];
                if ($newIdNo !== '') {
                    $sqlParts[] = '`id_card_hash` = :ih';
                    $sqlParts[] = '`id_card_mask` = :im';
                    $sqlParts[] = '`initial_password_hash` = :iph';
                    $params[':ih']  = auth_hash_id_card($newIdNo);
                    $params[':im']  = auth_mask_id_card($newIdNo);
                    $params[':iph'] = auth_initial_password_hash_from_id_card($newIdNo);
                }
                if ($newPwd !== '') {
                    $sqlParts[] = '`password_hash` = :ph';
                    $sqlParts[] = '`is_initial_pwd` = 0';
                    $params[':ph'] = password_hash($newPwd, PASSWORD_ARGON2ID);
                }
                try {
                    $pdo->prepare("UPDATE `users` SET " . implode(', ', $sqlParts) . " WHERE `id` = :id")->execute($params);
                    if ($uid === (int) ($_SESSION['auth_user_id'] ?? 0)) {
                        $_SESSION['auth_user']      = $newU;
                        $_SESSION['auth_real_name'] = $newName;
                        $_SESSION['auth_role']      = $params[':r'];
                    }
                    $msg = "已成功保存用户 {$newU}（{$newName}）的资料。";
                } catch (Throwable $e) {
                    $err = '保存失败：账号、邮箱或证件号可能与其他用户重复。';
                }
            }
        }
        // 1. 单个重置密码（重置为身份证后6位）
        elseif ($formAction === 'reset_single_pwd') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            if ($uid > 0) {
                $stmt = $pdo->prepare("
                    UPDATE `users`
                    SET `password_hash` = COALESCE(`initial_password_hash`, `password_hash`),
                        `is_initial_pwd` = 1
                    WHERE `id` = :id
                ");
                $stmt->execute([':id' => $uid]);
                $msg = "已将该用户（ID: {$uid}）的密码重置为身份证后6位。";
            }
        }
        // 2. 批量操作（批量重置密码 / 批量修改身份组 / 批量修改状态 / 批量删除）
        elseif ($formAction === 'batch_users') {
            $ids = $_POST['user_ids'] ?? [];
            $subOp = (string) ($_POST['batch_op'] ?? '');
            if (!is_array($ids) || empty($ids)) {
                $err = '请先勾选需要操作的用户。';
            } else {
                $cleanIds = array_values(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }));
                if (!empty($cleanIds)) {
                    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
                    if ($subOp === 'reset_pwd') {
                        $stmt = $pdo->prepare("
                            UPDATE `users`
                            SET `password_hash` = COALESCE(`initial_password_hash`, `password_hash`),
                                `is_initial_pwd` = 1
                            WHERE `id` IN ($placeholders)
                        ");
                        $stmt->execute($cleanIds);
                        $msg = '已批量将选中用户的密码重置为身份证后6位（共 ' . count($cleanIds) . ' 人）。';
                    } elseif ($subOp === 'set_role') {
                        $targetRole = (string) ($_POST['target_role'] ?? 'student');
                        if (in_array($targetRole, ['admin', 'leader', 'teacher', 'student', 'alumni'], true)) {
                            $params = array_merge([$targetRole], $cleanIds);
                            $stmt = $pdo->prepare("UPDATE `users` SET `role` = ? WHERE `id` IN ($placeholders)");
                            $stmt->execute($params);
                            $msg = '已批量修改选中用户的身份组为：' . $targetRole;
                        }
                    } elseif ($subOp === 'set_status') {
                        $targetStatus = (int) ($_POST['target_status'] ?? 1);
                        if (in_array($targetStatus, [0, 1, 2], true)) {
                            $params = array_merge([$targetStatus], $cleanIds);
                            $stmt = $pdo->prepare("UPDATE `users` SET `status` = ? WHERE `id` IN ($placeholders)");
                            $stmt->execute($params);
                            $msg = '已批量更新选中用户的状态。';
                        }
                    }
                }
            }
        }
        // 3. 单个修改用户身份组
        elseif ($formAction === 'change_single_role') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $newRole = (string) ($_POST['role'] ?? 'student');
            if ($uid > 0 && in_array($newRole, ['admin', 'leader', 'teacher', 'student', 'alumni'], true)) {
                $pdo->prepare("UPDATE `users` SET `role` = :r WHERE `id` = :id")->execute([':r' => $newRole, ':id' => $uid]);
                $msg = "已更新用户（ID: {$uid}）的身份组为 {$newRole}。";
            }
        }
        // 4. 手工新增单个用户
        elseif ($formAction === 'add_single_user') {
            $u  = trim((string) ($_POST['username'] ?? ''));
            $n  = trim((string) ($_POST['real_name'] ?? ''));
            $ro = trim((string) ($_POST['role'] ?? 'student'));
            $t  = trim((string) ($_POST['id_card_type'] ?? '居民身份证'));
            $id = trim((string) ($_POST['id_card'] ?? ''));
            if ($u === '' || $n === '' || strlen($id) < 6) {
                $err = '新增失败：账号、姓名必填，证件号码至少6位。';
            } else {
                $res = admin_import_users_batch([[
                    'username'     => $u,
                    'real_name'    => $n,
                    'role'         => $ro,
                    'id_card_type' => $t,
                    'id_card'      => $id,
                ]]);
                if ($res['inserted'] > 0) {
                    $msg = "用户 {$u}（{$n}）保存成功，初始密码已设为证件号后6位。";
                } else {
                    $err = '保存失败，请检查账号或证件号是否冲突。';
                }
            }
        }
        // 5. 批量导入（上传文件或线上填写）
        elseif ($formAction === 'do_import') {
            $tab = 'import';
            $onlineText = (string) ($_POST['online_text'] ?? '');
            $_SESSION['admin_import_online_draft'] = $onlineText;
            $_SESSION['admin_import_autosave_sec'] = max(5, (int) ($_POST['autosave_sec'] ?? 30));

            $allRows = [];

            // 检查是否有上传文件
            if (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
                $origName = (string) ($_FILES['import_file']['name'] ?? '');
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $allowedExts = ['xls', 'xlxs', 'xlsx', 'json', 'csv', 'txt', 'dbf'];
                if (!in_array($ext, $allowedExts, true)) {
                    $err = '不支持的文件格式：.' . $ext . '（仅支持 .xls / .xlxs / .xlsx / .json / .csv / .txt / .dbf）';
                } else {
                    $fileContent = (string) file_get_contents($_FILES['import_file']['tmp_name']);
                    $allRows = array_merge($allRows, admin_parse_import_rows($fileContent, $ext));
                }
            }

            // 检查线上填写区域是否有内容
            if ($err === '' && trim($onlineText) !== '') {
                $trimmedOnline = trim($onlineText);
                $onlineExt = ($trimmedOnline[0] === '[' || $trimmedOnline[0] === '{') ? 'json' : 'txt';
                $allRows = array_merge($allRows, admin_parse_import_rows($trimmedOnline, $onlineExt));
            }

            if ($err === '') {
                if (empty($allRows)) {
                    $msg = '线上缓存已保存（未检测到待导入的数据行）。';
                } else {
                    $stat = admin_import_users_batch($allRows);
                    $msg = "批量导入处理完成：成功导入/更新 {$stat['inserted']} 条，跳过无效数据 {$stat['skipped']} 条。";
                }
            }
        }
        // 6. 内容发布（自动调用 AI 翻译同步生成 _en.html 英文版及与首页同级 UI 的详情页）
        elseif ($formAction === 'publish_content') {
            $tab = 'publish';
            $cat     = trim((string) ($_POST['category'] ?? 'news'));
            $title   = trim((string) ($_POST['title'] ?? ''));
            $titleEn = trim((string) ($_POST['title_en'] ?? ''));
            $url     = trim((string) ($_POST['custom_url'] ?? ''));
            $author  = trim((string) ($_POST['author'] ?? ($_SESSION['auth_real_name'] ?? 'admin')));
            $froma   = trim((string) ($_POST['froma'] ?? '校办'));
            $body    = (string) ($_POST['content_html'] ?? '');
            $date    = trim((string) ($_POST['pub_date'] ?? date('Y-m-d')));

            if ($title === '') {
                $err = '文章标题不能为空！';
            } else {
                if ($url === '') {
                    $url = $cat . '/' . date('YmdHis') . '-' . random_int(100, 999) . '.html';
                }
                $urlEn = ai_make_en_url($url);

                // 调用 AI 翻译标题、正文和来源
                if ($titleEn === '') {
                    $tRes = ai_translate_to_english($title, false);
                    $titleEn = $tRes['text'] ?: $title;
                }
                $bRes    = ai_translate_to_english($body, true);
                $bodyEn  = $bRes['text'] ?: $body;
                $fRes    = ai_translate_to_english($froma, false);
                $fromaEn = $fRes['text'] ?: 'School Office';

                $catNameZhMap = ['news' => '综合新闻', 'yaowen' => '校园要闻', 'xueshu' => '学术交流', 'meiti' => '媒体关注'];
                $catNameEnMap = ['news' => 'General News', 'yaowen' => 'Campus Highlights', 'xueshu' => 'Academic Exchange', 'meiti' => 'Media Coverage'];

                if ($cat === 'yaowen') {
                    $plainDesc   = mb_substr(trim(strip_tags($body)), 0, 120, 'UTF-8') ?: $title;
                    $plainDescEn = mb_substr(trim(strip_tags($bodyEn)), 0, 180, 'UTF-8') ?: $titleEn;
                    $coverImg = admin_resolve_image_input('cover_upload', (string) ($_POST['cover_url'] ?? 'public/images/yw1.jpg'), 'public/images/', 'cover_');
                    if ($coverImg === '') { $coverImg = 'public/images/yw1.jpg'; }
                    $stmt = $pdo->prepare("INSERT INTO `yaowen_list` (`img`, `title`, `title_en`, `desc_text`, `desc_text_en`, `link`, `link_en`, `publish_date`, `sort_order`) VALUES (:img, :t, :te, :d, :de, :l, :le, :dt, 1)");
                    $stmt->execute([':img' => $coverImg, ':t' => $title, ':te' => $titleEn, ':d' => $plainDesc, ':de' => $plainDescEn, ':l' => $url, ':le' => $urlEn, ':dt' => $date]);
                } elseif ($cat === 'xueshu') {
                    $stmt = $pdo->prepare("INSERT INTO `xueshu_activities` (`title`, `title_en`, `activity_date`, `link`, `link_en`, `sort_order`) VALUES (:t, :te, :dt, :l, :le, 1)");
                    $stmt->execute([':t' => $title, ':te' => $titleEn, ':dt' => $date, ':l' => $url, ':le' => $urlEn]);
                } elseif ($cat === 'meiti') {
                    $stmt = $pdo->prepare("INSERT INTO `media_coverage` (`title`, `title_en`, `coverage_date`, `link`, `link_en`) VALUES (:t, :te, :dt, :l, :le)");
                    $stmt->execute([':t' => $title, ':te' => $titleEn, ':dt' => $date, ':l' => $url, ':le' => $urlEn]);
                } else {
                    $maxId = (int) $pdo->query("SELECT COALESCE(MAX(`id`), 0) FROM `news`")->fetchColumn();
                    $stmt = $pdo->prepare("INSERT INTO `news` (`id`, `title`, `title_en`, `username`, `date`, `url`, `url_en`, `text`, `text_en`, `people`, `froma`, `froma_en`) VALUES (:id, :t, :te, :u, :dt, :l, :le, :txt, :txte, 0, :f, :fe)");
                    $stmt->execute([
                        ':id'   => $maxId + 1,
                        ':t'    => $title,
                        ':te'   => $titleEn,
                        ':u'    => $author,
                        ':dt'   => $date,
                        ':l'    => $url,
                        ':le'   => $urlEn,
                        ':txt'  => $body,
                        ':txte' => $bodyEn,
                        ':f'    => $froma,
                        ':fe'   => $fromaEn,
                    ]);
                }

                // 若链接是相对路径且以 .html 结尾，同时生成中文版 (.html) 和英文版 (_en.html) 高颜值详情页
                if (preg_match('/^[a-zA-Z0-9_\-\/]+\.html$/', $url) && strpos($url, '..') === false) {
                    $zhPath = dirname(__DIR__) . '/' . $url;
                    $enPath = dirname(__DIR__) . '/' . $urlEn;
                    $targetDir = dirname($zhPath);
                    if (!is_dir($targetDir)) {
                        @mkdir($targetDir, 0755, true);
                    }
                    $zhHtml = ai_render_pretty_article_html([
                        'lang'           => 'zh-CN',
                        'title'          => $title,
                        'category_label' => $catNameZhMap[$cat] ?? '校园新闻',
                        'pub_date'       => $date,
                        'froma'          => $froma,
                        'author'         => $author,
                        'body_html'      => $body,
                        'zh_url'         => $url,
                        'en_url'         => $urlEn,
                    ]);
                    $enHtml = ai_render_pretty_article_html([
                        'lang'           => 'en',
                        'title'          => $titleEn,
                        'category_label' => $catNameEnMap[$cat] ?? 'Campus News',
                        'pub_date'       => $date,
                        'froma'          => $fromaEn,
                        'author'         => $author,
                        'body_html'      => $bodyEn,
                        'zh_url'         => $url,
                        'en_url'         => $urlEn,
                    ]);
                    @file_put_contents($zhPath, $zhHtml);
                    @file_put_contents($enPath, $enHtml);
                }

                $msg = "内容发布成功！已同步生成中文版 ({$url}) 与 AI 英文版 ({$urlEn})。";
            }
        }
        // AI翻译配置保存、测试与全量重绘
        elseif ($formAction === 'save_ai_config' || $formAction === 'test_ai_translate' || $formAction === 'rebuild_all_articles') {
            $tab = 'ai';
            $curAi = ai_get_config();
            $apiBase = trim((string) ($_POST['ai_api_base'] ?? $curAi['api_base']));
            $apiKey  = trim((string) ($_POST['ai_api_key'] ?? ''));
            if ($apiKey === '' || $apiKey === '********') {
                $apiKey = $curAi['api_key'];
            }
            $apiModel     = trim((string) ($_POST['ai_api_model'] ?? $curAi['model']));
            $prompt       = trim((string) ($_POST['ai_system_prompt'] ?? $curAi['prompt']));
            $curLeaderPmt = ai_get_leader_prompt();
            $leaderPrompt = trim((string) ($_POST['ai_leader_prompt'] ?? $curLeaderPmt));
            if ($leaderPrompt === '') {
                $leaderPrompt = ai_default_leader_counselor_prompt();
            }

            $upd = $pdo->prepare("UPDATE `config` SET `description` = :v WHERE `name` = :n");
            $upd->execute([':v' => $apiBase,      ':n' => 'ai_api_base']);
            $upd->execute([':v' => $apiKey,       ':n' => 'ai_api_key']);
            $upd->execute([':v' => $apiModel,     ':n' => 'ai_api_model']);
            $upd->execute([':v' => $prompt,       ':n' => 'ai_system_prompt']);
            $upd->execute([':v' => $leaderPrompt, ':n' => 'ai_leader_prompt']);

            if ($formAction === 'test_ai_translate') {
                $sampleZh = trim((string) ($_POST['test_zh_text'] ?? '东营区行知实验学校召开科技创新推进会'));
                $res = ai_translate_to_english($sampleZh, false);
                if ($res['ok']) {
                    $msg = "翻译成功 [引擎: {$res['via']}]：{$res['text']}" . ($res['error'] ? " （{$res['error']}）" : '');
                } else {
                    $err = "AI 接口返回警告：{$res['error']} | 回退译文：{$res['text']}";
                }
            } elseif ($formAction === 'rebuild_all_articles') {
                $allNews = $pdo->query("SELECT * FROM `news`")->fetchAll();
                $rebuilt = 0;
                foreach ($allNews as $nRow) {
                    $zhUrl = (string) $nRow['url'];
                    if ($zhUrl === '' || $zhUrl === '#') continue;
                    $enUrl = !empty($nRow['url_en']) ? (string) $nRow['url_en'] : ai_make_en_url($zhUrl);
                    $tEn   = !empty($nRow['title_en']) ? (string) $nRow['title_en'] : (ai_translate_to_english((string) $nRow['title'], false)['text'] ?: $nRow['title']);
                    $bEn   = !empty($nRow['text_en'])  ? (string) $nRow['text_en']  : (ai_translate_to_english((string) $nRow['text'], true)['text'] ?: $nRow['text']);
                    $fEn   = !empty($nRow['froma_en']) ? (string) $nRow['froma_en'] : (ai_translate_to_english((string) $nRow['froma'], false)['text'] ?: 'School Office');

                    $pdo->prepare("UPDATE `news` SET `title_en`=:te, `url_en`=:ue, `text_en`=:txe, `froma_en`=:fe WHERE `id`=:id")
                        ->execute([':te' => $tEn, ':ue' => $enUrl, ':txe' => $bEn, ':fe' => $fEn, ':id' => (int) $nRow['id']]);

                    if (preg_match('/^[a-zA-Z0-9_\-\/]+\.html$/', $zhUrl) && strpos($zhUrl, '..') === false) {
                        $zhPath = dirname(__DIR__) . '/' . $zhUrl;
                        $enPath = dirname(__DIR__) . '/' . $enUrl;
                        if (!is_dir(dirname($zhPath))) @mkdir(dirname($zhPath), 0755, true);
                        @file_put_contents($zhPath, ai_render_pretty_article_html([
                            'lang' => 'zh-CN', 'title' => (string) $nRow['title'], 'category_label' => '综合新闻',
                            'pub_date' => (string) $nRow['date'], 'froma' => (string) $nRow['froma'], 'author' => (string) $nRow['username'],
                            'body_html' => (string) $nRow['text'], 'zh_url' => $zhUrl, 'en_url' => $enUrl,
                        ]));
                        @file_put_contents($enPath, ai_render_pretty_article_html([
                            'lang' => 'en', 'title' => $tEn, 'category_label' => 'General News',
                            'pub_date' => (string) $nRow['date'], 'froma' => $fEn, 'author' => (string) $nRow['username'],
                            'body_html' => $bEn, 'zh_url' => $zhUrl, 'en_url' => $enUrl,
                        ]));
                        $rebuilt++;
                    }
                }
                $msg = "已成功重绘并生成 {$rebuilt} 篇文章的新版中文详情页 (.html) 与 AI 英文详情页 (_en.html)！";
            } else {
                $msg = 'AI 翻译配置（Base URL / API Key / 模型）已保存成功！';
            }
        }
        // 7. 邮件配置保存与测试发信
        elseif ($formAction === 'save_mail_config' || $formAction === 'test_mail_send') {
            $tab = 'mail';
            require __DIR__ . '/../../config/mail.php';

            $newHost   = trim((string) ($_POST['smtp_host'] ?? $smtp_host));
            $newPort   = (int) ($_POST['smtp_port'] ?? $smtp_port);
            $newSecure = trim((string) ($_POST['smtp_secure'] ?? $smtp_secure));
            $newUser   = trim((string) ($_POST['smtp_username'] ?? $smtp_username));
            $newPass   = (string) ($_POST['smtp_password'] ?? '');
            if ($newPass === '' || $newPass === '********') {
                $newPass = $smtp_password; // 未改动密码框则保留原授权码
            }
            $newFromEmail = trim((string) ($_POST['smtp_from_email'] ?? $newUser));
            $newFromName  = trim((string) ($_POST['smtp_from_name'] ?? $smtp_from_name));

            $mailPhpContent = "<?php\n"
                . "require_once __DIR__ . '/../app/models/env.php';\n\n"
                . '$smtp_host       = app_env(\'SMTP_HOST\', ' . var_export($newHost, true) . ");\n"
                . '$smtp_port       = (int) app_env(\'SMTP_PORT\', ' . var_export((string) $newPort, true) . ");\n"
                . '$smtp_secure     = app_env(\'SMTP_SECURE\', ' . var_export($newSecure, true) . ");\n"
                . '$smtp_username   = app_env(\'SMTP_USER\', ' . var_export($newUser, true) . ");\n"
                . '$smtp_password   = app_env(\'SMTP_PASS\', ' . var_export($newPass, true) . ");\n"
                . '$smtp_from_email = app_env(\'SMTP_FROM_EMAIL\', ' . var_export($newFromEmail, true) . ");\n"
                . '$smtp_from_name  = app_env(\'SMTP_FROM_NAME\', ' . var_export($newFromName, true) . ");\n";

            file_put_contents(__DIR__ . '/../../config/mail.php', $mailPhpContent);

            if ($formAction === 'test_mail_send') {
                $testTo = trim((string) ($_POST['test_to_email'] ?? ''));
                if ($testTo === '') {
                    $err = '请填写测试收件邮箱地址。';
                } else {
                    $res = auth_send_email_verification_code($testTo, 'login', '系统管理员', 'admin');
                    if ($res['ok']) {
                        $msg = '测试邮件发送成功！已发送至：' . $testTo;
                    } else {
                        $err = '测试发送失败：' . $res['message'];
                    }
                }
            } else {
                $msg = '邮件服务器（SMTP）配置已保存。';
            }
        }
        // 8. 站点配置保存（含学校商标 logo_1、商标+名称长图 logo_2、网站图标 ico 上传）
        elseif ($formAction === 'save_site_config') {
            $tab = 'site';
            $fields = [
                'title'            => trim((string) ($_POST['cfg_title'] ?? '')),
                'keywords'         => trim((string) ($_POST['cfg_keywords'] ?? '')),
                'address'          => trim((string) ($_POST['cfg_address'] ?? '')),
                'postal_code'      => trim((string) ($_POST['cfg_postal_code'] ?? '')),
                'icp'              => trim((string) ($_POST['cfg_icp'] ?? '')),
                'footer_email'     => trim((string) ($_POST['cfg_footer_email'] ?? '')),
                'footer_phone'     => trim((string) ($_POST['cfg_footer_phone'] ?? '')),
                'security_warning' => trim((string) ($_POST['cfg_security_warning'] ?? '')),
                'security_color'   => trim((string) ($_POST['cfg_security_color'] ?? '#cc0000')),
                'footer_copyright' => trim((string) ($_POST['cfg_footer_copyright'] ?? '')),
            ];
            $upd = $pdo->prepare("UPDATE `config` SET `description` = :val WHERE `name` = :name");
            foreach ($fields as $k => $v) {
                $upd->execute([':val' => $v, ':name' => $k]);
            }

            // 处理路径手工修改及图片文件上传（logo_1 正方形校徽 / logo_2 商标+名称长图 / ico 网站图标）
            $urlFields = [
                'logo_1' => trim((string) ($_POST['cfg_logo_1_url'] ?? 'public/images/logo1.png')),
                'logo_2' => trim((string) ($_POST['cfg_logo_2_url'] ?? 'public/images/logo2.png')),
                'ico'    => trim((string) ($_POST['cfg_ico_url'] ?? 'dyxz.ico')),
            ];
            $uploadMap = [
                'logo_1' => ['file_key' => 'upload_logo_1', 'dir' => 'public/images/', 'prefix' => 'logo1_'],
                'logo_2' => ['file_key' => 'upload_logo_2', 'dir' => 'public/images/', 'prefix' => 'logo2_'],
                'ico'    => ['file_key' => 'upload_ico',    'dir' => '',               'prefix' => 'favicon_'],
            ];
            $allowedImgExts = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'svg'];

            foreach ($uploadMap as $cfgName => $uMeta) {
                $fk = $uMeta['file_key'];
                if (!empty($_FILES[$fk]['tmp_name']) && is_uploaded_file($_FILES[$fk]['tmp_name'])) {
                    $ext = strtolower(pathinfo((string) $_FILES[$fk]['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, $allowedImgExts, true)) {
                        $relPath = $uMeta['dir'] . $uMeta['prefix'] . date('YmdHis') . '.' . $ext;
                        $absPath = dirname(__DIR__) . '/' . $relPath;
                        if (@move_uploaded_file($_FILES[$fk]['tmp_name'], $absPath)) {
                            $urlFields[$cfgName] = $relPath;
                        }
                    } else {
                        $err = "上传失败：{$cfgName} 文件格式不支持（仅允许 png/jpg/gif/ico/svg）。";
                    }
                }
            }

            $updUrl = $pdo->prepare("UPDATE `config` SET `url` = :u WHERE `name` = :name");
            foreach ($urlFields as $k => $uVal) {
                if ($uVal !== '') {
                    $updUrl->execute([':u' => $uVal, ':name' => $k]);
                }
            }

            if ($err === '') {
                $msg = '站点配置信息及商标图片已保存成功！';
            }
        }
        // 8b. 页脚认证图标批量保存（支持修改图片地址、直接上传新图片、修改跳转链接、排序、显示/隐藏）
        elseif ($formAction === 'save_footer_badges') {
            $tab = 'badges';
            footer_badges_ensure_table();
            $titles  = $_POST['badge_title']  ?? [];
            $imgs    = $_POST['badge_img']    ?? [];
            $links   = $_POST['badge_link']   ?? [];
            $sorts   = $_POST['badge_sort']   ?? [];
            $actives = $_POST['badge_active'] ?? [];
            $updB = $pdo->prepare("UPDATE `footer_badges` SET `title` = :t, `img_url` = :img, `link_url` = :lnk, `sort_order` = :s, `is_active` = :a WHERE `id` = :id");
            foreach ($titles as $bid => $bTitle) {
                $bid = (int) $bid;
                if ($bid <= 0) continue;
                $bImg  = trim((string) ($imgs[$bid] ?? ''));
                $bImg  = admin_resolve_image_input('badge_file_' . $bid, $bImg, 'public/images/', 'badge_');
                $bLink = trim((string) ($links[$bid] ?? '#'));
                if ($bLink === '') $bLink = '#';
                $bSort = (int) ($sorts[$bid] ?? 10);
                $bAct  = (int) ($actives[$bid] ?? 1) ? 1 : 0;
                $updB->execute([
                    ':t'   => trim((string) $bTitle),
                    ':img' => $bImg,
                    ':lnk' => $bLink,
                    ':s'   => $bSort,
                    ':a'   => $bAct,
                    ':id'  => $bid,
                ]);
            }
            $msg = '页脚认证图标配置已保存更新！';
        }
        // 8c. 新增页脚认证图标
        elseif ($formAction === 'add_footer_badge') {
            $tab = 'badges';
            footer_badges_ensure_table();
            $newTitle = trim((string) ($_POST['new_badge_title'] ?? ''));
            $newImg   = trim((string) ($_POST['new_badge_img'] ?? ''));
            $newImg   = admin_resolve_image_input('new_badge_file', $newImg, 'public/images/', 'badge_');
            $newLink  = trim((string) ($_POST['new_badge_link'] ?? '#'));
            if ($newLink === '') $newLink = '#';
            $newSort  = (int) ($_POST['new_badge_sort'] ?? 10);
            if ($newTitle === '' || $newImg === '') {
                $err = '新增页脚图标失败：请填写图标名称，并上传图片或填写图片路径。';
            } else {
                $pdo->prepare("INSERT INTO `footer_badges` (`title`, `img_url`, `link_url`, `sort_order`, `is_active`) VALUES (:t, :img, :lnk, :s, 1)")
                    ->execute([':t' => $newTitle, ':img' => $newImg, ':lnk' => $newLink, ':s' => $newSort]);
                $msg = "已新增页脚认证图标「{$newTitle}」！";
            }
        }
        // 8d. 删除页脚认证图标
        elseif ($formAction === 'delete_footer_badge') {
            $tab = 'badges';
            footer_badges_ensure_table();
            $delId = (int) ($_POST['badge_id'] ?? 0);
            if ($delId > 0) {
                $pdo->prepare("DELETE FROM `footer_badges` WHERE `id` = :id")->execute([':id' => $delId]);
                $msg = '该页脚认证图标已删除。';
            }
        }
        // 9. 删除已发布内容
        elseif ($formAction === 'delete_content') {
            $tab = 'publish';
            $tbl = (string) ($_POST['content_table'] ?? 'news');
            $cid = (int) ($_POST['content_id'] ?? 0);
            $tableMap = [
                'news'   => 'news',
                'yaowen' => 'yaowen_list',
                'xueshu' => 'xueshu_activities',
                'meiti'  => 'media_coverage',
            ];
            if ($cid > 0 && isset($tableMap[$tbl])) {
                $realTbl = $tableMap[$tbl];
                $pdo->prepare("DELETE FROM `{$realTbl}` WHERE `id` = :id")->execute([':id' => $cid]);
                $msg = "已成功删除该条内容（编号：{$cid}）。";
            }
        }
        // 10. 导航与轮播管理
        elseif ($formAction === 'add_nav_link') {
            $tab = 'portal';
            $txt = trim((string) ($_POST['nav_text'] ?? ''));
            $url = trim((string) ($_POST['nav_url'] ?? '#'));
            $pid = ($_POST['parent_id'] ?? '') !== '' ? (int) $_POST['parent_id'] : null;
            $ord = (int) ($_POST['sort_order'] ?? 1);
            if ($txt !== '') {
                $pdo->prepare("INSERT INTO `nav_links` (`parent_id`, `text`, `url`, `sort_order`) VALUES (:p, :t, :u, :o)")
                    ->execute([':p' => $pid, ':t' => $txt, ':u' => $url, ':o' => $ord]);
                $msg = "已新增导航菜单：{$txt}";
            }
        }
        elseif ($formAction === 'del_nav_link') {
            $tab = 'portal';
            $nid = (int) ($_POST['nav_id'] ?? 0);
            if ($nid > 0) {
                $pdo->prepare("DELETE FROM `nav_links` WHERE `id` = :id")->execute([':id' => $nid]);
                $msg = "已删除导航菜单（ID: {$nid}）。";
            }
        }
        elseif ($formAction === 'add_banner') {
            $tab = 'portal';
            $title = trim((string) ($_POST['banner_title'] ?? ''));
            $img   = admin_resolve_image_input('banner_upload', (string) ($_POST['banner_img'] ?? ''), 'public/images/', 'banner_');
            $link  = trim((string) ($_POST['banner_link'] ?? '#'));
            $ord   = (int) ($_POST['sort_order'] ?? 1);
            if ($title === '' || $img === '') {
                $err = '请填写轮播图标题，并填写图片链接或上传本地图片文件。';
            } else {
                $pdo->prepare("INSERT INTO `banner_images` (`img`, `title`, `link`, `sort_order`) VALUES (:i, :t, :l, :o)")
                    ->execute([':i' => $img, ':t' => $title, ':l' => $link, ':o' => $ord]);
                $msg = "已新增首页轮播图：{$title}（图片：{$img}）";
            }
        }
// 插件中心操作：启用/停用、保存配置、卸载、上传 .zip 安装
        elseif ($formAction === 'plugin_toggle') {
            $tab = 'plugins';
            $pid = trim((string) ($_POST['plugin_id'] ?? ''));
            $en  = (int) ($_POST['enable_val'] ?? 0) === 1;
            if ($pid !== '') {
                plugin_set_enabled($pid, $en);
                $msg = "插件 [{$pid}] 已" . ($en ? '启用（前台已即时生效）' : '停用') . '！';
            }
        }
        elseif ($formAction === 'plugin_save_cfg') {
            $tab = 'plugins';
            $pid = trim((string) ($_POST['plugin_id'] ?? ''));
            $cfg = $_POST['plg_cfg'] ?? [];
            if ($pid !== '' && is_array($cfg)) {
                plugin_save_settings($pid, $cfg);
                $msg = "插件 [{$pid}] 的参数设置已保存生效！";
            }
        }
        elseif ($formAction === 'plugin_uninstall') {
            $tab = 'plugins';
            $pid = trim((string) ($_POST['plugin_id'] ?? ''));
            if ($pid !== '') {
                plugin_uninstall($pid);
                $msg = "插件 [{$pid}] 已彻底卸载删除。";
            }
        }
        elseif ($formAction === 'plugin_upload_zip') {
            $tab = 'plugins';
            if (empty($_FILES['plugin_zip']['tmp_name']) || !is_uploaded_file($_FILES['plugin_zip']['tmp_name'])) {
                $err = '请先选择要上传安装的 .zip 插件压缩包。';
            } else {
                $res = plugin_install_from_zip($_FILES['plugin_zip']['tmp_name']);
                if ($res['ok']) {
                    $msg = $res['message'];
                } else {
                    $err = $res['message'];
                }
            }
        }
        elseif ($formAction === 'save_traffic_base') {
            $tab = 'traffic';
            traffic_ensure_tables();
            $baseVal = max(0, (int) ($_POST['traffic_base_pv'] ?? 0));
            $pdo->prepare("UPDATE `config` SET `description` = :v WHERE `name` = 'traffic_base_pv'")->execute([':v' => (string) $baseVal]);

            $hourlyPvArr     = [];
            $hourlyOnlineArr = [];
            for ($h = 0; $h < 24; $h++) {
                $rawPv = trim((string) ($_POST['hourly_pv'][$h] ?? '1-5'));
                $rawOn = trim((string) ($_POST['hourly_online'][$h] ?? '1-5'));
                $hourlyPvArr[$h]     = $rawPv !== '' ? $rawPv : '1-5';
                $hourlyOnlineArr[$h] = $rawOn !== '' ? $rawOn : '1-5';
            }
            $pdo->prepare("UPDATE `config` SET `description` = :v WHERE `name` = 'traffic_hourly_pv'")
                ->execute([':v' => json_encode($hourlyPvArr)]);
            $pdo->prepare("UPDATE `config` SET `name` = 'traffic_hourly_online', `description` = :v WHERE `id` = 16")
                ->execute([':v' => json_encode($hourlyOnlineArr)]);

            $msg = '24小时分时段每小时流量区间与24小时分时段在线浏览人数区间均已保存生效！';
        }
        elseif ($formAction === 'del_banner') {
            $tab = 'portal';
            $bid = (int) ($_POST['banner_id'] ?? 0);
            if ($bid > 0) {
                $pdo->prepare("DELETE FROM `banner_images` WHERE `id` = :id")->execute([':id' => $bid]);
                $msg = "已删除轮播图（ID: {$bid}）。";
            }
        }
    }
}

$roleNames = [
    'student' => '学生',
    'teacher' => '教师',
    'leader'  => '领导',
    'admin'   => '管理员',
    'alumni'  => '校友',
];
$statusNames = [
    0 => '未激活',
    1 => '正常',
    2 => '已禁用',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>综合后台管理系统 - 东营行知实验学校</title>
    <link rel="stylesheet" href="public/css/admin.css">
</head>
<body>

<!-- 顶部系统信息条 -->
<table border="0" cellspacing="0" cellpadding="0" width="100%" class="admin-topbar">
    <tr>
        <td>
            <b>东营区行知实验学校 - 门户与统一身份认证后台管理系统 v1.0 (内部系统)</b>
        </td>
        <td align="right">
            当前登录：<b><?php echo e((string) ($_SESSION['auth_real_name'] ?? $_SESSION['auth_user'])); ?></b>
            [身份：管理员]
            | <a href="dashboard.php?tab=profile"><b>[修改个人资料/密码]</b></a> | <a href="../leader/dashboard.php" target="_blank"><b>[书记/校长工作台]</b></a> | <a href="../index.php" target="_blank">[查看前台首页]</a>
            | <a href="../auth/login.php" target="_blank">[统一认证页]</a>
            | <a href="dashboard.php?action=logout" onclick="return confirm('确定要退出系统后台吗？');">[安全退出]</a>
        </td>
    </tr>
</table>

<br>

<!-- 主体左右分栏表格 -->
<table border="1" cellspacing="0" cellpadding="0" width="98%" class="admin-layout">
    <tr valign="top">
        <!-- 左侧功能导航菜单 -->
        <td width="185" class="admin-sidebar">
            <span class="admin-sidebar-title">系统功能菜单</span>
            <p><a href="dashboard.php?tab=profile"><?php echo $tab === 'profile' ? '<b>&gt;&gt; 个人资料修改</b>' : '个人资料修改'; ?></a></p>
            <p><a href="dashboard.php?tab=users"><?php echo $tab === 'users' ? '<b>&gt;&gt; 用户管理</b>' : '用户管理'; ?></a></p>
            <p><a href="dashboard.php?tab=import"><?php echo $tab === 'import' ? '<b>&gt;&gt; 批量导入</b>' : '批量导入'; ?></a></p>
            <p><a href="dashboard.php?tab=publish"><?php echo $tab === 'publish' ? '<b>&gt;&gt; 内容发布</b>' : '内容发布'; ?></a></p>
            <p><a href="dashboard.php?tab=portal"><?php echo $tab === 'portal' ? '<b>&gt;&gt; 导航与轮播管理</b>' : '导航与轮播管理'; ?></a></p>
            <p><a href="dashboard.php?tab=mailbox"><?php echo $tab === 'mailbox' ? '<b>&gt;&gt; 书记/校长信箱</b>' : '书记/校长信箱'; ?></a></p>
            <p><a href="dashboard.php?tab=ai"><?php echo $tab === 'ai' ? '<b>&gt;&gt; AI翻译配置</b>' : 'AI翻译配置'; ?></a></p>
            <p><a href="dashboard.php?tab=mail"><?php echo $tab === 'mail' ? '<b>&gt;&gt; 邮件配置</b>' : '邮件配置'; ?></a></p>
            <p><a href="dashboard.php?tab=site"><?php echo $tab === 'site' ? '<b>&gt;&gt; 站点配置信息</b>' : '站点配置信息'; ?></a></p>
            <p><a href="dashboard.php?tab=badges"><?php echo $tab === 'badges' ? '<b>&gt;&gt; 页脚认证图标</b>' : '页脚认证图标'; ?></a></p>
            <p><a href="dashboard.php?tab=plugins"><?php echo $tab === 'plugins' ? '<b>&gt;&gt; 插件扩展中心</b>' : '插件扩展中心'; ?></a></p>
            <p><a href="dashboard.php?tab=traffic"><?php echo $tab === 'traffic' ? '<b>&gt;&gt; 网站流量统计</b>' : '网站流量统计'; ?></a></p>
            <p><a href="dashboard.php?tab=logs"><?php echo $tab === 'logs' ? '<b>&gt;&gt; 登录安全日志</b>' : '登录安全日志'; ?></a></p>
            <hr size="1">
            <font color="#666666">服务器时间：<br><?php echo date('Y-m-d H:i:s'); ?></font>
        </td>

        <!-- 右侧主操作区域 -->
        <td class="admin-content">
            <?php if ($msg !== ''): ?>
                <div class="msg-ok">系统提示：<?php echo e($msg); ?></div>
            <?php endif; ?>
            <?php if ($err !== ''): ?>
                <div class="msg-err">错误提示：<?php echo e($err); ?></div>
            <?php endif; ?>

<?php if ($tab === 'profile'): ?>
                <!-- ==================== 0. 管理员个人资料与密码修改页面 ==================== -->
                <?php
                $myId = (int) ($_SESSION['auth_user_id'] ?? 0);
                $myStmt = $pdo->prepare("SELECT * FROM `users` WHERE `id` = :id LIMIT 1");
                $myStmt->execute([':id' => $myId]);
                $me = $myStmt->fetch() ?: [];
                $myType = (string) ($me['id_card_type'] ?? '居民身份证');
                ?>
                <b>当前位置：个人资料修改 &gt;&gt; 修改当前登录管理员的个人信息、证件号与登录密码</b>
                <hr size="1">

                <form method="post" action="dashboard.php?tab=profile">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save_admin_profile">

                    <table border="1" cellspacing="0" cellpadding="6" width="100%">
                        <tr>
                            <th width="170">管理员用户 ID：</th>
                            <td><b><?php echo (int) ($me['id'] ?? 0); ?></b>（当前身份：管理员）</td>
                        </tr>
                        <tr>
                            <th>*登录账号：</th>
                            <td>
                                <input type="text" name="username" value="<?php echo e((string) ($me['username'] ?? 'admin')); ?>" size="28" required>
                                <span>（可用于统一身份认证登录）</span>
                            </td>
                        </tr>
                        <tr>
                            <th>*真实姓名：</th>
                            <td>
                                <input type="text" name="real_name" value="<?php echo e((string) ($me['real_name'] ?? '系统管理员')); ?>" size="28" required>
                            </td>
                        </tr>
                        <tr>
                            <th>绑定电子邮箱：</th>
                            <td>
                                <input type="text" name="email" value="<?php echo e((string) ($me['email'] ?? '')); ?>" size="32" placeholder="用于邮箱登录及接收验证码">
                            </td>
                        </tr>
                        <tr>
                            <th>证件类型：</th>
                            <td>
                                <select name="id_card_type">
                                    <option value="居民身份证" <?php echo $myType === '居民身份证' ? 'selected' : ''; ?>>居民身份证</option>
                                    <option value="港澳台居民居住证" <?php echo $myType === '港澳台居民居住证' ? 'selected' : ''; ?>>港澳台居民居住证</option>
                                    <option value="护照" <?php echo $myType === '护照' ? 'selected' : ''; ?>>护照</option>
                                    <option value="军官证/士兵证" <?php echo $myType === '军官证/士兵证' ? 'selected' : ''; ?>>军官证/士兵证</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>证件号码（支持身份证登录）：</th>
                            <td>
                                <input type="text" name="id_card_number" size="32" placeholder="当前状态：<?php echo e((string) ($me['id_card_mask'] ?: '未录入')); ?>（留空则保持不变）" autocomplete="off">
                                <span>（录入后将以环境变量密钥的 HMAC-SHA256 盲索引保存，可直接用身份证号登录后台，末尾 X 需大写）</span>
                            </td>
                        </tr>
                        <tr>
                            <th>修改登录密码：</th>
                            <td>
                                <input type="password" name="new_password" size="28" placeholder="留空则不修改当前密码" autocomplete="new-password">
                                <span>（如填写，须不少于 6 位，将以 Argon2id 算法加密）</span>
                            </td>
                        </tr>
                        <tr>
                            <th>确认新密码：</th>
                            <td>
                                <input type="password" name="confirm_password" size="28" placeholder="再次输入新密码" autocomplete="new-password">
                            </td>
                        </tr>
                        <tr>
                            <th>密保问题：</th>
                            <td>
                                <input type="text" name="security_question" value="<?php echo e((string) ($me['security_question'] ?? '您母亲的姓名是？')); ?>" size="38">
                            </td>
                        </tr>
                        <tr>
                            <th>修改密保答案：</th>
                            <td>
                                <input type="text" name="security_answer" size="28" placeholder="留空则保持原密保答案不变" autocomplete="off">
                            </td>
                        </tr>
                        <tr>
                            <th>最后登录信息：</th>
                            <td>
                                时间：<?php echo e((string) ($me['last_login_at'] ?? '-')); ?> &nbsp;|&nbsp;
                                IP：<?php echo e((string) ($me['last_login_ip'] ?? '-')); ?>
                            </td>
                        </tr>
                        <tr>
                            <th></th>
                            <td>
                                <input type="submit" value="保存管理员个人资料" style="padding:6px 22px;font-weight:bold;">
                            </td>
                        </tr>
                    </table>
                </form>

            <?php elseif ($tab === 'users'): ?>
                <!-- ==================== 1. 用户管理页面 ==================== -->
                <?php
                $filterRole = trim((string) ($_GET['role'] ?? ''));
                $keyword    = trim((string) ($_GET['kw'] ?? ''));
                $page       = max(1, (int) ($_GET['page'] ?? 1));
                $pageSize   = 10;

                $where = [];
                $params = [];
                if ($filterRole !== '' && isset($roleNames[$filterRole])) {
                    $where[] = '`role` = :role';
                    $params[':role'] = $filterRole;
                }
                if ($keyword !== '') {
                    $where[] = '(`username` LIKE :kw1 OR `real_name` LIKE :kw2 OR `email` LIKE :kw3)';
                    $params[':kw1'] = "%{$keyword}%";
                    $params[':kw2'] = "%{$keyword}%";
                    $params[':kw3'] = "%{$keyword}%";
                }
                $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

                $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM `users` {$whereSql}");
                $cntStmt->execute($params);
                $totalUsers = (int) $cntStmt->fetchColumn();
                $totalPages = max(1, (int) ceil($totalUsers / $pageSize));
                if ($page > $totalPages) {
                    $page = $totalPages;
                }
                $offset = ($page - 1) * $pageSize;

                $listStmt = $pdo->prepare("SELECT * FROM `users` {$whereSql} ORDER BY `id` ASC LIMIT {$offset}, {$pageSize}");
                $listStmt->execute($params);
                $userRows = $listStmt->fetchAll();
                ?>

<b>当前位置：用户管理 &gt;&gt; 用户列表与身份组设置</b>
                <hr size="1">

                <?php
                $editUid = (int) ($_GET['edit_id'] ?? 0);
                $editUserRow = null;
                if ($editUid > 0) {
                    $eStmt = $pdo->prepare("SELECT * FROM `users` WHERE `id` = :id LIMIT 1");
                    $eStmt->execute([':id' => $editUid]);
                    $editUserRow = $eStmt->fetch();
                }
                ?>
                <?php if ($editUserRow): ?>
                    <div id="userEditModal" style="position:fixed;left:0;top:0;width:100%;height:100%;z-index:9999;background:rgba(0,0,0,0.45);">
                        <div style="width:680px;max-width:94%;margin:90px auto 0;background:#fff;border:2px solid #404040;">
                            <div style="background:#d4d0c8;color:#000;padding:5px 8px;font-size:12px;font-weight:bold;border-bottom:1px solid #808080;">
                                <span>编辑用户资料（ID: <?php echo (int) $editUserRow['id']; ?>）</span>
                                <a href="dashboard.php?tab=users&page=<?php echo $page; ?>" style="float:right;color:#fff;text-decoration:none;font-size:16px;line-height:1;">×</a>
                            </div>
                            <form method="post" action="dashboard.php?tab=users&page=<?php echo $page; ?>" style="padding:16px 18px;margin:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="form_action" value="edit_single_user">
                                <input type="hidden" name="edit_user_id" value="<?php echo (int) $editUserRow['id']; ?>">
                                <table border="1" cellspacing="0" cellpadding="6" width="100%">
                                    <tr>
                                        <th width="130">账号(学号/工号)：</th>
                                        <td><input type="text" name="username" value="<?php echo e((string) $editUserRow['username']); ?>" size="22" required></td>
                                        <th width="120">真实姓名：</th>
                                        <td><input type="text" name="real_name" value="<?php echo e((string) ($editUserRow['real_name'] ?? '')); ?>" size="20" required></td>
                                    </tr>
                                    <tr>
                                        <th>绑定邮箱：</th>
                                        <td><input type="text" name="email" value="<?php echo e((string) ($editUserRow['email'] ?? '')); ?>" size="24"></td>
                                        <th>身份组：</th>
                                        <td>
                                            <select name="role">
                                                <?php foreach ($roleNames as $rk => $rv): ?>
                                                    <option value="<?php echo e($rk); ?>" <?php echo $editUserRow['role'] === $rk ? 'selected' : ''; ?>><?php echo e($rv); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>账号状态：</th>
                                        <td>
                                            <select name="status">
                                                <option value="1" <?php echo (int) $editUserRow['status'] === 1 ? 'selected' : ''; ?>>正常</option>
                                                <option value="0" <?php echo (int) $editUserRow['status'] === 0 ? 'selected' : ''; ?>>未激活</option>
                                                <option value="2" <?php echo (int) $editUserRow['status'] === 2 ? 'selected' : ''; ?>>已禁用</option>
                                            </select>
                                        </td>
                                        <th>直接设新密码：</th>
                                        <td><input type="password" name="new_password" size="20" placeholder="留空则不修改密码"></td>
                                    </tr>
                                    <tr>
                                        <th>证件类型：</th>
                                        <td>
                                            <?php $eType = (string) ($editUserRow['id_card_type'] ?? '居民身份证'); ?>
                                            <select name="id_card_type">
                                                <option value="居民身份证" <?php echo $eType === '居民身份证' ? 'selected' : ''; ?>>居民身份证</option>
                                                <option value="港澳台居民居住证" <?php echo $eType === '港澳台居民居住证' ? 'selected' : ''; ?>>港澳台居民居住证</option>
                                                <option value="护照" <?php echo $eType === '护照' ? 'selected' : ''; ?>>护照</option>
                                                <option value="军官证/士兵证" <?php echo $eType === '军官证/士兵证' ? 'selected' : ''; ?>>军官证/士兵证</option>
                                            </select>
                                        </td>
                                        <th>重设证件号码：</th>
                                        <td>
                                            <input type="text" name="id_card_number" size="22" placeholder="当前：<?php echo e((string) ($editUserRow['id_card_mask'] ?: '未录入')); ?>">
                                        </td>
                                    </tr>
                                </table>
                                <div style="margin-top:14px;text-align:right;">
                                    <a href="dashboard.php?tab=users&page=<?php echo $page; ?>" style="display:inline-block;padding:4px 12px;background:#e2e8f0;color:#222;border:1px solid #cbd5e1;margin-right:8px;text-decoration:none;">取消关闭</a>
                                    <input type="submit" value="保存该用户修改" style="font-weight:bold;padding:5px 18px;">
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 筛选与快速新增 -->
                <form method="get" action="dashboard.php" style="margin-bottom:8px;">
                    <input type="hidden" name="tab" value="users">
                    按身份组筛选：
                    <select name="role">
                        <option value="">-- 全部身份组 --</option>
                        <?php foreach ($roleNames as $rk => $rv): ?>
                            <option value="<?php echo e($rk); ?>" <?php echo $filterRole === $rk ? 'selected' : ''; ?>><?php echo e($rv); ?></option>
                        <?php endforeach; ?>
                    </select>
                    账号/姓名/邮箱关键字：<input type="text" name="kw" value="<?php echo e($keyword); ?>" size="18">
                    <input type="submit" value="查询">
                    <a href="dashboard.php?tab=users">[重置条件]</a>
                </form>

                <!-- 手工添加单个用户 -->
                <form method="post" action="dashboard.php?tab=users" style="margin-bottom:10px;background:#f9f9f9;padding:6px;border:1px solid #ccc;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="add_single_user">
                    <b>快速添加用户：</b>
                    账号(学号/工号): <input type="text" name="username" size="10" required>
                    姓名: <input type="text" name="real_name" size="8" required>
                    身份组:
                    <select name="role">
                        <?php foreach ($roleNames as $rk => $rv): ?>
                            <option value="<?php echo e($rk); ?>"><?php echo e($rv); ?></option>
                        <?php endforeach; ?>
                    </select>
                    证件类型:
                    <select name="id_card_type">
                        <option value="居民身份证">居民身份证</option>
                        <option value="港澳台居民居住证">港澳台居民居住证</option>
                        <option value="护照">护照</option>
                        <option value="军官证/士兵证">军官证/士兵证</option>
                    </select>
                    证件号码(后6位作初始密码): <input type="text" name="id_card" size="18" required>
                    <input type="submit" value="添加用户">
                </form>

                <!-- 用户数据表格（严格使用 <table border="1" cellspacing="0" cellpadding="5">） -->
                <form method="post" action="dashboard.php?tab=users&page=<?php echo $page; ?>" id="batchUsersForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="batch_users">
                    <input type="hidden" name="batch_op" id="batchOpInput" value="">

                    <table border="1" cellspacing="0" cellpadding="5" width="100%">
                        <tr>
                            <th width="36"><input type="checkbox" onclick="toggleAllCheckboxes(this)">全选</th>
                            <th>ID</th>
                            <th>账号(学号/工号)</th>
                            <th>真实姓名</th>
                            <th>身份组</th>
                            <th>证件类型</th>
                            <th>证件号(脱敏)</th>
                            <th>绑定邮箱</th>
                            <th>账号状态</th>
                            <th>最后登录时间</th>
                            <th>操作</th>
                        </tr>
                        <?php if (empty($userRows)): ?>
                            <tr>
                                <td colspan="11" align="center">暂无符合条件的用户数据</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($userRows as $u): ?>
                                <tr>
                                    <td align="center">
                                        <input type="checkbox" name="user_ids[]" value="<?php echo (int) $u['id']; ?>" class="user-chk">
                                    </td>
                                    <td><?php echo (int) $u['id']; ?></td>
                                    <td><b><?php echo e((string) $u['username']); ?></b></td>
                                    <td><?php echo e((string) ($u['real_name'] ?? '')); ?></td>
                                    <td>
                                        <?php echo e($roleNames[$u['role']] ?? $u['role']); ?>
                                    </td>
                                    <td><?php echo e((string) ($u['id_card_type'] ?? '居民身份证')); ?></td>
                                    <td><?php echo e((string) ($u['id_card_mask'] ?? '未录入')); ?></td>
                                    <td><?php echo e((string) ($u['email'] ?? '未绑定')); ?></td>
                                    <td>
                                        <?php
                                        $st = (int) $u['status'];
                                        if ($st === 0) echo '<font color="#cc6600">未激活</font>';
                                        elseif ($st === 1) echo '<font color="#008800">正常</font>';
                                        else echo '<font color="#cc0000">已禁用</font>';
                                        ?>
                                    </td>
                                    <td><?php echo e((string) ($u['last_login_at'] ?? '从未登录')); ?></td>
                                    <td>
                                        <a href="dashboard.php?tab=users&page=<?php echo $page; ?>&edit_id=<?php echo (int) $u['id']; ?>">[编辑资料]</a>
                                        <button type="button" onclick="resetSingleUserPwd(<?php echo (int) $u['id']; ?>)">一键重置密码</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </table>

                    <!-- 批量操作栏 -->
                    <div style="margin-top:8px;">
                        批量操作：
                        <button type="button" onclick="submitBatchResetPwd()">[批量一键重置密码(身份证后6位)]</button>
                        &nbsp;|&nbsp;
                        批量设为身份组：
                        <select name="target_role">
                            <?php foreach ($roleNames as $rk => $rv): ?>
                                <option value="<?php echo e($rk); ?>"><?php echo e($rv); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="submitBatchOp('set_role')">[应用身份组]</button>
                        &nbsp;|&nbsp;
                        批量设为状态：
                        <select name="target_status">
                            <option value="1">正常</option>
                            <option value="0">未激活</option>
                            <option value="2">已禁用</option>
                        </select>
                        <button type="button" onclick="submitBatchOp('set_status')">[应用状态]</button>
                    </div>
                </form>

                <!-- 隐藏的单个重置密码提交表单 -->
                <form method="post" action="dashboard.php?tab=users&page=<?php echo $page; ?>" id="singleResetForm" style="display:none;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="reset_single_pwd">
                    <input type="hidden" name="user_id" id="singleResetUserId" value="">
                </form>

                <!-- 纯文本链接分页：[上一页] 1 2 3 4 [下一页] -->
                <div style="margin-top:12px;">
                    <?php
                    $baseUrl = 'dashboard.php?tab=users&role=' . urlencode($filterRole) . '&kw=' . urlencode($keyword);
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);
                    ?>
                    <a href="<?php echo e($baseUrl . '&page=' . $prevPage); ?>">[上一页]</a>
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php if ($p === $page): ?>
                            <b><?php echo $p; ?></b>
                        <?php else: ?>
                            <a href="<?php echo e($baseUrl . '&page=' . $p); ?>"><?php echo $p; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <a href="<?php echo e($baseUrl . '&page=' . $nextPage); ?>">[下一页]</a>
                    &nbsp;（共 <?php echo $totalUsers; ?> 条记录，第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页）
                </div>

                <script type="text/javascript">
                    function toggleAllCheckboxes(master) {
                        var boxes = document.getElementsByTagName('input');
                        for (var i = 0; i < boxes.length; i++) {
                            if (boxes[i].type === 'checkbox' && boxes[i].className === 'user-chk') {
                                boxes[i].checked = master.checked;
                            }
                        }
                    }
                    function resetSingleUserPwd(uid) {
                        if (confirm('确定要重置该用户的密码吗？')) {
                            document.getElementById('singleResetUserId').value = uid;
                            document.getElementById('singleResetForm').submit();
                        }
                    }
                    function submitBatchResetPwd() {
                        if (confirm('确定要重置该用户的密码吗？')) {
                            document.getElementById('batchOpInput').value = 'reset_pwd';
                            document.getElementById('batchUsersForm').submit();
                        }
                    }
                    function submitBatchOp(op) {
                        document.getElementById('batchOpInput').value = op;
                        document.getElementById('batchUsersForm').submit();
                    }
                </script>

            <?php elseif ($tab === 'import'): ?>
                <!-- ==================== 2. 批量导入页面 ==================== -->
                <?php
                $draftText   = (string) ($_SESSION['admin_import_online_draft'] ?? '');
                $autosaveSec = (int) ($_SESSION['admin_import_autosave_sec'] ?? 30);
                ?>
                <p style="font-size:14px;margin:4px 0 14px;">
                    请先点击下方链接下载导入模板，修改后再上传。
                    <a href="dashboard.php?action=download_tpl&ext=xls" style="color:blue;font-weight:bold;">[点击下载《学生信息导入模板_不可修改表头》.xls]</a>，
                    <a href="dashboard.php?action=download_tpl&ext=xlxs" style="color:blue;font-weight:bold;">[点击下载《学生信息导入模板_不可修改表头》.xlxs]</a>，
                    <a href="dashboard.php?action=download_tpl&ext=txt" style="color:blue;font-weight:bold;">[点击下载《学生信息导入模板_不可修改文件头》.txt]</a>，
                    <a href="dashboard.php?action=download_tpl&ext=csv" style="color:blue;font-weight:bold;">[点击下载《学生信息导入模板_不可修改表头》.csv]</a>，
                    <a href="dashboard.php?action=download_tpl&ext=dbf" style="color:blue;font-weight:bold;">[点击下载《学生信息导入模板_不可修改表头》.dbf]</a>
                </p>

                <form method="post" action="dashboard.php?tab=import" enctype="multipart/form-data" id="importForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="do_import">

                    <table border="1" cellspacing="0" cellpadding="5" width="100%">
                        <tr>
                            <th width="160">方式一：上传数据文件</th>
                            <td>
                                <input type="file" name="import_file" accept=".xls,.xlxs,.xlsx,.json,.csv,.txt,.dbf">
                                <span>（支持格式：.xls、.xlxs、.xlsx、.json、.csv、.txt、.dbf）</span>
                            </td>
                        </tr>
                        <tr>
                            <th>自动保存时间设定</th>
                            <td>
                                每隔 <input type="text" name="autosave_sec" id="autosaveSecInput" value="<?php echo $autosaveSec; ?>" size="5"> 秒自动线上缓存一次
                                <button type="button" onclick="applyAutosaveTimer()">[应用间隔]</button>
                                &nbsp;&nbsp;
                                <span id="cacheStatusText" style="color:#008800;font-weight:bold;">（按 Ctrl+S 可随时触发线上缓存）</span>
                            </td>
                        </tr>
                        <tr>
                            <th>
                                方式二：线上直接填写<br>
                                <font color="#666666" style="font-weight:normal;">
                                    每行一条，格式：<br>
                                    账号,姓名,身份组,证件类型,证件号码<br>
                                    或直接粘贴 JSON 数组
                                </font>
                            </th>
                            <td>
                                <textarea name="online_text" id="onlineTextArea" rows="12" style="width:98%;"><?php echo e($draftText); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" align="center" style="padding:14px;">
                                <button type="submit" style="padding:4px 18px;font-weight:bold;">
                                    保存并执行导入 (Ctrl+S)
                                </button>
                            </td>
                        </tr>
                    </table>
                </form>

                <script type="text/javascript">
                    var autosaveTimer = null;
                    function triggerOnlineCacheSave() {
                        var txt = document.getElementById('onlineTextArea').value;
                        var sec = document.getElementById('autosaveSecInput').value;
                        var statusEl = document.getElementById('cacheStatusText');
                        try {
                            if (window.localStorage) {
                                localStorage.setItem('school_import_draft', txt);
                            }
                        } catch (e) {}

                        var form = document.getElementById('importForm');
                        var inputs = form.getElementsByTagName('input');
                        var csrf = '';
                        for (var i = 0; i < inputs.length; i++) {
                            if (inputs[i].name === 'csrf_token') {
                                csrf = inputs[i].value;
                                break;
                            }
                        }

                        var xhr = window.XMLHttpRequest ? new XMLHttpRequest() : new ActiveXObject('Microsoft.XMLHTTP');
                        xhr.open('POST', 'dashboard.php?action=save_online_draft', true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onreadystatechange = function () {
                            if (xhr.readyState === 4 && statusEl) {
                                statusEl.innerHTML = '已线上缓存（保存时间：' + (new Date().toTimeString().slice(0, 8)) + '）';
                            }
                        };
                        xhr.send('online_text=' + encodeURIComponent(txt) + '&autosave_sec=' + encodeURIComponent(sec) + '&csrf_token=' + encodeURIComponent(csrf));
                    }

                    function applyAutosaveTimer() {
                        if (autosaveTimer) clearInterval(autosaveTimer);
                        var sec = parseInt(document.getElementById('autosaveSecInput').value, 10);
                        if (isNaN(sec) || sec < 5) sec = 30;
                        autosaveTimer = setInterval(triggerOnlineCacheSave, sec * 1000);
                        document.getElementById('cacheStatusText').innerHTML = '已开启每 ' + sec + ' 秒自动线上缓存';
                    }

                    // 拦截 Ctrl+S 进行线上缓存
                    document.onkeydown = function (ev) {
                        var e = ev || window.event;
                        if ((e.ctrlKey || e.metaKey) && (e.keyCode === 83 || e.which === 83)) {
                            if (e.preventDefault) e.preventDefault();
                            e.returnValue = false;
                            triggerOnlineCacheSave();
                            return false;
                        }
                    };

                    applyAutosaveTimer();
                </script>

            <?php elseif ($tab === 'publish'): ?>
                <!-- ==================== 3. 内容发布页面 ==================== -->
                <?php
                $defaultCat = 'news';
                $defaultUrl = $defaultCat . '/' . date('YmdHis') . '-' . random_int(100, 999) . '.html';
                $recentNews = $pdo->query("SELECT `id`, `title`, `title_en`, `username`, `date`, `url`, `url_en`, `froma` FROM `news` ORDER BY `id` DESC LIMIT 10")->fetchAll();
                ?>
                <b>当前位置：内容发布 &gt;&gt; 发布文章与自定义链接</b>
                <hr size="1">

                <form method="post" action="dashboard.php?tab=publish" enctype="multipart/form-data" id="publishForm" onsubmit="syncRteContent()">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="publish_content">

                    <table border="1" cellspacing="0" cellpadding="5" width="100%">
                        <tr>
                            <th width="130">所属分类：</th>
                            <td>
                                <select name="category" id="pubCategorySelect" onchange="updateDefaultUrlByCat()">
                                    <option value="news">综合新闻</option>
                                    <option value="yaowen">校园要闻</option>
                                    <option value="xueshu">学术交流</option>
                                    <option value="meiti">媒体关注</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>文章中文标题：</th>
                            <td><input type="text" name="title" size="60" required> *必填</td>
                        </tr>
                        <tr>
                            <th>英文版标题(可选)：</th>
                            <td>
                                <input type="text" name="title_en" size="60" placeholder="留空则发布时由 AI 自动翻译中文标题与正文并生成 _en.html">
                            </td>
                        </tr>
                        <tr>
                            <th>自定义链接地址：</th>
                            <td>
                                <input type="text" name="custom_url" id="customUrlInput" value="<?php echo e($defaultUrl); ?>" size="50">
                                <button type="button" onclick="regenDefaultUrl()">[重新生成默认文件名]</button>
                                <span>（默认规则：分类/时间命名-数字.html，可手动修改）</span>
                            </td>
                        </tr>
                        <tr>
                            <th>封面配图(可选)：<br><font color="#666666" style="font-weight:normal;">支持填链接或直接上传</font></th>
                            <td>
                                方式1-填写或选择图片链接：
                                <input type="text" name="cover_url" id="coverUrlInput" value="public/images/yw1.jpg" size="32">
                                <select onchange="if(this.value) document.getElementById('coverUrlInput').value=this.value;">
                                    <option value="">-- 选择站内已有图片 --</option>
                                    <option value="public/images/yw1.jpg">public/images/yw1.jpg</option>
                                    <option value="public/images/banner1.png">public/images/banner1.png</option>
                                    <option value="public/images/logo1.png">public/images/logo1.png</option>
                                    <option value="public/images/logo2.png">public/images/logo2.png</option>
                                </select>
                                <br>
                                <div style="margin-top:5px;">
                                    方式2-直接上传本地图片：<input type="file" name="cover_upload" accept=".png,.jpg,.jpeg,.gif,.webp,.svg">
                                    <span>（若选择了本地上传文件，将优先使用上传的图片）</span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th>发布日期 / 来源：</th>
                            <td>
                                日期：<input type="text" name="pub_date" value="<?php echo date('Y-m-d'); ?>" size="12">
                                &nbsp;来源：<input type="text" name="froma" value="校办" size="14">
                                &nbsp;发布人：<input type="text" name="author" value="<?php echo e((string) ($_SESSION['auth_real_name'] ?? 'admin')); ?>" size="12">
                            </td>
                        </tr>
                        <tr>
                            <th>正文内容<br>(富文本编辑器)：</th>
                            <td>
                                <!-- 传统富文本工具栏 -->
                                <div class="rte-toolbar" style="background:#eee;border:1px solid #000;border-bottom:0;padding:4px;">
                                    <button type="button" onclick="rteCmd('bold')"><b>B 加粗</b></button>
                                    <button type="button" onclick="rteCmd('italic')"><i>I 斜体</i></button>
                                    <button type="button" onclick="rteCmd('underline')"><u>U 下划线</u></button>
                                    <button type="button" onclick="rteCmd('justifyLeft')">左对齐</button>
                                    <button type="button" onclick="rteCmd('justifyCenter')">居中</button>
                                    <button type="button" onclick="rteCmd('justifyRight')">右对齐</button>
                                    <button type="button" onclick="rteCmd('insertOrderedList')">编号列表</button>
                                    <button type="button" onclick="rteCmd('insertUnorderedList')">项目符号</button>
                                    字号:
                                    <select onchange="if(this.value) rteCmd('fontSize', this.value);">
                                        <option value="">默认</option>
                                        <option value="2">小号</option>
                                        <option value="3">标准</option>
                                        <option value="4">中号</option>
                                        <option value="5">大号</option>
                                        <option value="6">特大</option>
                                    </select>
                                    颜色:
                                    <select onchange="if(this.value) rteCmd('foreColor', this.value);">
                                        <option value="#000000">黑色</option>
                                        <option value="#cc0000">红色</option>
                                        <option value="#0000cc">蓝色</option>
                                        <option value="#008800">绿色</option>
                                    </select>
                                    <button type="button" onclick="rteInsertLink()">插入超链接</button>
                                    <button type="button" onclick="rteInsertImage()">插入图片链接</button>
                                    <button type="button" onclick="document.getElementById('rteLocalImgInput').click()">上传本地图片插入</button>
                                    <input type="file" id="rteLocalImgInput" accept=".png,.jpg,.jpeg,.gif,.webp,.svg" style="display:none;" onchange="rteUploadLocalImage(this)">
                                    <button type="button" onclick="toggleRteSource()">[切换HTML源码]</button>
                                </div>
                                <div id="rteEditor" class="rte-area" contenteditable="true"><p>在此输入正文内容...</p></div>
                                <textarea name="content_html" id="rteSourceArea" rows="14" style="width:100%;display:none;"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th></th>
                            <td>
                                <input type="submit" value="立即发布内容" style="padding:6px 24px;font-weight:bold;">
                            </td>
                        </tr>
                    </table>
                </form>

                <br>
                <b>最近发布的综合新闻：</b>
                <table border="1" cellspacing="0" cellpadding="5" width="100%">
                    <tr>
                        <th>ID</th>
                        <th>标题</th>
                        <th>链接地址</th>
                        <th>来源</th>
                        <th>发布人</th>
                        <th>发布日期</th>
                        <th>操作</th>
                    </tr>
                    <?php if (empty($recentNews)): ?>
                        <tr><td colspan="7" align="center">暂无已发布新闻</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentNews as $nRow): ?>
                            <tr>
                                <td><?php echo (int) $nRow['id']; ?></td>
                                <td><?php echo e((string) $nRow['title']); ?></td>
                                <td>
                                    中文：<a href="../<?php echo e((string) $nRow['url']); ?>" target="_blank"><?php echo e((string) $nRow['url']); ?></a><br>
                                    <?php $enLinkShow = !empty($nRow['url_en']) ? $nRow['url_en'] : ai_make_en_url((string) $nRow['url']); ?>
                                    英文：<a href="../<?php echo e((string) $enLinkShow); ?>" target="_blank"><?php echo e((string) $enLinkShow); ?></a>
                                </td>
                                <td><?php echo e((string) $nRow['froma']); ?></td>
                                <td><?php echo e((string) $nRow['username']); ?></td>
                                <td><?php echo e((string) $nRow['date']); ?></td>
                                <td>
                                    <form method="post" action="dashboard.php?tab=publish" style="display:inline;" onsubmit="return confirm('确定要删除该条新闻吗？');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="delete_content">
                                        <input type="hidden" name="content_table" value="news">
                                        <input type="hidden" name="content_id" value="<?php echo (int) $nRow['id']; ?>">
                                        <input type="submit" value="删除">
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>

                <script type="text/javascript">
                    var isSourceMode = false;
                    function rteCmd(cmd, val) {
                        if (isSourceMode) return;
                        var ed = document.getElementById('rteEditor');
                        ed.focus();
                        document.execCommand(cmd, false, val || null);
                    }
                    function rteInsertLink() {
                        var url = prompt('请输入超链接地址：', 'http://');
                        if (url) rteCmd('createLink', url);
                    }
                    function rteInsertImage() {
                        var url = prompt('请输入图片 URL 链接地址：', '../public/images/banner1.png');
                        if (url) rteCmd('insertImage', url);
                    }
                    function rteUploadLocalImage(fileInput) {
                        if (!fileInput.files || !fileInput.files[0]) return;
                        var form = document.getElementById('publishForm');
                        var inputs = form.getElementsByTagName('input');
                        var csrf = '';
                        for (var i = 0; i < inputs.length; i++) {
                            if (inputs[i].name === 'csrf_token') { csrf = inputs[i].value; break; }
                        }
                        var fd = new FormData();
                        fd.append('rte_img_file', fileInput.files[0]);
                        fd.append('csrf_token', csrf);
                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', 'dashboard.php?action=upload_rte_image', true);
                        xhr.onreadystatechange = function () {
                            if (xhr.readyState !== 4) return;
                            try {
                                var res = JSON.parse(xhr.responseText);
                                if (res.ok && res.url) {
                                    rteCmd('insertImage', res.url);
                                } else {
                                    alert(res.msg || '图片上传失败');
                                }
                            } catch (e) {
                                alert('图片上传失败');
                            }
                            fileInput.value = '';
                        };
                        xhr.send(fd);
                    }
                    function toggleRteSource() {
                        var ed = document.getElementById('rteEditor');
                        var src = document.getElementById('rteSourceArea');
                        if (!isSourceMode) {
                            src.value = ed.innerHTML;
                            ed.style.display = 'none';
                            src.style.display = 'block';
                            isSourceMode = true;
                        } else {
                            ed.innerHTML = src.value;
                            src.style.display = 'none';
                            ed.style.display = 'block';
                            isSourceMode = false;
                        }
                    }
                    function syncRteContent() {
                        var ed = document.getElementById('rteEditor');
                        var src = document.getElementById('rteSourceArea');
                        if (!isSourceMode) {
                            src.value = ed.innerHTML;
                        }
                    }
                    function pad2(n) { return n < 10 ? '0' + n : '' + n; }
                    function regenDefaultUrl() {
                        var cat = document.getElementById('pubCategorySelect').value;
                        var d = new Date();
                        var ts = '' + d.getFullYear() + pad2(d.getMonth() + 1) + pad2(d.getDate())
                            + pad2(d.getHours()) + pad2(d.getMinutes()) + pad2(d.getSeconds());
                        var rnd = Math.floor(100 + Math.random() * 900);
                        document.getElementById('customUrlInput').value = cat + '/' + ts + '-' + rnd + '.html';
                    }
                    function updateDefaultUrlByCat() {
                        regenDefaultUrl();
                    }
                </script>

<?php elseif ($tab === 'ai'): ?>
                <!-- ==================== AI 翻译与双语文章生成配置 ==================== -->
                <?php $aiCfg = ai_get_config(); ?>
                <b>当前位置：AI翻译配置 &gt;&gt; 中译英大模型 API 密钥与全站英文版生成</b>
                <hr size="1">

                <form method="post" action="dashboard.php?tab=ai" id="aiConfigForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" id="aiFormActionInput" value="save_ai_config">

                    <table border="1" cellspacing="0" cellpadding="6" width="100%">
                        <tr>
                            <th width="180">AI 接口地址 (Base URL)：</th>
                            <td>
                                <input type="text" name="ai_api_base" value="<?php echo e($aiCfg['api_base']); ?>" size="52">
                                <span>（兼容 OpenAI 格式，如 <code>https://api.openai.com/v1</code>、<code>https://api.deepseek.com/v1</code> 或中转站地址）</span>
                            </td>
                        </tr>
                        <tr>
                            <th>AI API Key（密钥）：</th>
                            <td>
                                <input type="password" name="ai_api_key" value="<?php echo $aiCfg['api_key'] !== '' ? '********' : ''; ?>" size="42" placeholder="请输入 sk-... 密钥">
                                <span>（已保存状态：<?php echo $aiCfg['api_key'] !== '' ? '<font color="green"><b>已配置密钥</b></font>' : '<font color="#cc6600">未配置（当前使用内置词典回退）</font>'; ?>）</span>
                            </td>
                        </tr>
                        <tr>
                            <th>AI 模型名称 (Model)：</th>
                            <td>
                                <input type="text" name="ai_api_model" id="aiModelInput" value="<?php echo e($aiCfg['model']); ?>" size="28">
                                快速选择：
                                <select onchange="if(this.value) document.getElementById('aiModelInput').value=this.value;">
                                    <option value="">-- 常用模型 --</option>
                                    <option value="gpt-4o-mini">gpt-4o-mini</option>
                                    <option value="gpt-4o">gpt-4o</option>
                                    <option value="deepseek-chat">deepseek-chat</option>
                                    <option value="gemini-2.5-flash">gemini-2.5-flash</option>
                                    <option value="qwen-plus">qwen-plus</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>中译英翻译提示词：</th>
                            <td>
                                <textarea name="ai_system_prompt" rows="3" style="width:94%;"><?php echo e($aiCfg['prompt']); ?></textarea>
                            </td>
                        </tr>
                        <tr bgcolor="#fffdf5">
                            <th>领导发文 AI 参谋提示词：<br><font color="#888888" style="font-weight:normal;">(专职润色公文+隐蔽夸领导)</font></th>
                            <td>
                                <?php $leaderPromptVal = ai_get_leader_prompt(); ?>
                                <textarea name="ai_leader_prompt" id="aiLeaderPromptArea" rows="5" style="width:94%;"><?php echo e($leaderPromptVal); ?></textarea><br>
                                <font color="#666666">说明：该提示词控制<b>「书记/校长工作台 &gt;&gt; 领导发文与讲话签发」</b>右侧的 AI 公文参谋。只干两件事：① 将领导草稿润色为标准体制内公文；② 在【参谋审读体会】里<b>不动声色、不露痕迹地夸赞领导</b>（严禁出现低级马屁词，必须从“切入点极准、战略眼光深远、原稿骨架已非常成熟仅微调过渡字句”角度夸）。</font>
                            </td>
                        </tr>
                        <tr>
                            <th>英文版文章命名规则：</th>
                            <td>
                                <b>自动追加 <code>_en.html</code> 后缀</b>
                                （例如中文版为 <code>news/20261121-101.html</code>，发布文章时自动同步生成 <code>news/20261121-101_en.html</code>，且页面右上角自带中英互切按钮）
                            </td>
                        </tr>
                        <tr>
                            <th></th>
                            <td>
                                <input type="submit" value="保存 AI 翻译配置" onclick="document.getElementById('aiFormActionInput').value='save_ai_config';" style="padding:5px 18px;font-weight:bold;">
                                &nbsp;&nbsp;
                                <input type="submit" value="一键升级并重绘全站已有文章的中/英文版 (.html + _en.html)" onclick="document.getElementById('aiFormActionInput').value='rebuild_all_articles';" >
                            </td>
                        </tr>
                        <tr bgcolor="#f8fafd">
                            <th>在线翻译测试：</th>
                            <td>
                                测试中文句子：<input type="text" name="test_zh_text" value="东营区行知实验学校召开重点工作推进会" size="42">
                                <input type="submit" value="测试 AI 中译英" onclick="document.getElementById('aiFormActionInput').value='test_ai_translate';">
                            </td>
                        </tr>
                    </table>
                </form>

            <?php elseif ($tab === 'mail'): ?>
                <!-- ==================== 4. 邮件配置页面 ==================== -->
                <?php require __DIR__ . '/../../config/mail.php'; ?>
                <b>当前位置：邮件配置 &gt;&gt; SMTP 邮件网关与授权码参数配置</b>
                <hr size="1">

                <form method="post" action="dashboard.php?tab=mail" id="mailConfigForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" id="mailFormAction" value="save_mail_config">

                    <table border="1" cellspacing="0" cellpadding="4" width="100%">
                        <tr>
                            <th width="160">SMTP服务器：</th>
                            <td><input type="text" name="smtp_host" value="<?php echo e((string) $smtp_host); ?>" size="32"></td>
                            <th width="160">端口：</th>
                            <td><input type="text" name="smtp_port" value="<?php echo (int) $smtp_port; ?>" size="10"></td>
                        </tr>
                        <tr>
                            <th>发件邮箱：</th>
                            <td><input type="text" name="smtp_username" value="<?php echo e((string) $smtp_username); ?>" size="32"></td>
                            <th>授权码：</th>
                            <td>
                                <input type="password" name="smtp_password" value="********" size="22">
                                <font color="#cc0000">* 如遇发送失败，请联系信息中心获取最新授权码。</font>
                            </td>
                        </tr>
                        <tr>
                            <th>加密传输协议：</th>
                            <td>
                                <input type="text" name="smtp_secure" value="<?php echo e((string) $smtp_secure); ?>" size="12"> (ssl / tls)
                            </td>
                            <th>发件人显示邮箱：</th>
                            <td><input type="text" name="smtp_from_email" value="<?php echo e((string) $smtp_from_email); ?>" size="28"></td>
                        </tr>
                        <tr>
                            <th>发件人显示名称：</th>
                            <td><input type="text" name="smtp_from_name" value="<?php echo e((string) $smtp_from_name); ?>" size="32"></td>
                            <th>回复邮箱地址(Reply-To)：</th>
                            <td><input type="text" name="smtp_reply_to" value="<?php echo e((string) $smtp_from_email); ?>" size="28"></td>
                        </tr>
                        <tr>
                            <th>连接超时时间(秒)：</th>
                            <td><input type="text" name="smtp_timeout" value="30" size="8"></td>
                            <th>失败自动重试次数：</th>
                            <td><input type="text" name="smtp_retry" value="3" size="8"></td>
                        </tr>
                        <tr>
                            <th>邮件字符集编码：</th>
                            <td><input type="text" name="smtp_charset" value="UTF-8" size="12"></td>
                            <th>验证码有效期(秒)：</th>
                            <td><input type="text" name="code_ttl" value="300" size="10"></td>
                        </tr>
                        <tr>
                            <th>单IP发送冷却(秒)：</th>
                            <td><input type="text" name="code_cooldown" value="60" size="10"></td>
                            <th>单日单邮箱上限(封)：</th>
                            <td><input type="text" name="daily_limit" value="20" size="10"></td>
                        </tr>
                        <tr>
                            <th>备用SMTP服务器：</th>
                            <td><input type="text" name="smtp_backup_host" value="smtp.ym.163.com" size="32"></td>
                            <th>备用端口：</th>
                            <td><input type="text" name="smtp_backup_port" value="994" size="10"></td>
                        </tr>
                        <tr>
                            <td colspan="4" align="left">
                                <input type="submit" value="保存邮件配置" onclick="document.getElementById('mailFormAction').value='save_mail_config';" style="padding:4px 18px;font-weight:bold;">
                            </td>
                        </tr>
                        <tr bgcolor="#f9f9f9">
                            <th>发送测试：</th>
                            <td colspan="3">
                                测试收件人邮箱：<input type="text" name="test_to_email" placeholder="请输入接收测试邮件的邮箱" size="30">
                                <input type="submit" value="发送测试" onclick="document.getElementById('mailFormAction').value='test_mail_send';">
                            </td>
                        </tr>
                    </table>
                </form>

            <?php elseif ($tab === 'site'): ?>
                <!-- ==================== 5. 站点配置信息页面 ==================== -->
                <?php
                $cfgRows = $pdo->query("SELECT `name`, `url`, `description` FROM `config`")->fetchAll();
                $cfgMap = [];
                $cfgUrlMap = [];
                foreach ($cfgRows as $r) {
                    $cfgMap[$r['name']] = $r['description'];
                    $cfgUrlMap[$r['name']] = $r['url'];
                }
                $logo1Url = (string) ($cfgUrlMap['logo_1'] ?: 'public/images/logo1.png');
                $logo2Url = (string) ($cfgUrlMap['logo_2'] ?: 'public/images/logo2.png');
                $icoUrl   = (string) ($cfgUrlMap['ico'] ?: 'dyxz.ico');
                ?>
                <b>当前位置：站点配置信息 &gt;&gt; 学校商标、底部版权与安全警告语设置</b>
                <hr size="1">

                <form method="post" action="dashboard.php?tab=site" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save_site_config">

                    <table border="1" cellspacing="0" cellpadding="5" width="100%">
                        <tr>
                            <th width="170">学校网站标题：</th>
                            <td><input type="text" name="cfg_title" value="<?php echo e((string) ($cfgMap['title'] ?? '')); ?>" size="50"></td>
                        </tr>
                        <tr>
                            <th>学校商标（正方形校徽）：</th>
                            <td>
                                <div style="margin-bottom:6px;">
                                    <img src="../<?php echo e($logo1Url); ?>" alt="学校商标" style="height:60px;vertical-align:middle;border:1px solid #ccc;padding:2px;background:#fff;">
                                    &nbsp;当前路径：<input type="text" name="cfg_logo_1_url" value="<?php echo e($logo1Url); ?>" size="32">
                                </div>
                                上传新校徽图片：<input type="file" name="upload_logo_1" accept=".png,.jpg,.jpeg,.gif,.svg,.webp">
                                <span>（建议上传透明背景正方形 PNG 校徽）</span>
                            </td>
                        </tr>
                        <tr>
                            <th>商标+名称长图：<br><font color="#666666" style="font-weight:normal;">(用于首页顶栏与统一认证顶栏)</font></th>
                            <td>
                                <div style="margin-bottom:6px;">
                                    <img src="../<?php echo e($logo2Url); ?>" alt="商标+名称长图" style="height:56px;vertical-align:middle;border:1px solid #ccc;padding:2px;background:#fff;">
                                    &nbsp;当前路径：<input type="text" name="cfg_logo_2_url" value="<?php echo e($logo2Url); ?>" size="32">
                                </div>
                                上传新商标+名称长图：<input type="file" name="upload_logo_2" accept=".png,.jpg,.jpeg,.gif,.svg,.webp">
                                <span>（建议上传横向长方形 PNG，含校徽+校名中英文）</span>
                            </td>
                        </tr>
                        <tr>
                            <th>浏览器标签图标 (.ico)：</th>
                            <td>
                                <img src="../<?php echo e($icoUrl); ?>" alt="ico" style="height:24px;vertical-align:middle;border:1px solid #ccc;">
                                &nbsp;当前路径：<input type="text" name="cfg_ico_url" value="<?php echo e($icoUrl); ?>" size="24">
                                &nbsp;上传新图标：<input type="file" name="upload_ico" accept=".ico,.png">
                            </td>
                        </tr>
                        <tr>
                            <th>SEO 关键词：</th>
                            <td><input type="text" name="cfg_keywords" value="<?php echo e((string) ($cfgMap['keywords'] ?? '')); ?>" size="50"></td>
                        </tr>
                        <tr>
                            <th>学校地址：</th>
                            <td><input type="text" name="cfg_address" value="<?php echo e((string) ($cfgMap['address'] ?? '')); ?>" size="50"></td>
                        </tr>
                        <tr>
                            <th>邮政编码：</th>
                            <td><input type="text" name="cfg_postal_code" value="<?php echo e((string) ($cfgMap['postal_code'] ?? '')); ?>" size="20"></td>
                        </tr>
                        <tr>
                            <th>ICP 备案号：</th>
                            <td><input type="text" name="cfg_icp" value="<?php echo e((string) ($cfgMap['icp'] ?? 'f')); ?>" size="30">（无备案号填小写 f）</td>
                        </tr>
                        <tr>
                            <th>信息化处邮箱：</th>
                            <td><input type="text" name="cfg_footer_email" value="<?php echo e((string) ($cfgMap['footer_email'] ?? '----@---.cn')); ?>" size="35"></td>
                        </tr>
                        <tr>
                            <th>信息化处电话：</th>
                            <td><input type="text" name="cfg_footer_phone" value="<?php echo e((string) ($cfgMap['footer_phone'] ?? '--------（可能的费用由运营商收取）')); ?>" size="50"></td>
                        </tr>
                        <tr>
                            <th>安全警告语：</th>
                            <td>
                                <input type="text" name="cfg_security_warning" value="<?php echo e((string) ($cfgMap['security_warning'] ?? '非涉密平台，严禁处理涉密信息！')); ?>" size="45">
                                &nbsp;警告语颜色选择器：
                                <input type="color" name="cfg_security_color" value="<?php echo e((string) ($cfgMap['security_color'] ?? '#cc0000')); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th>底部版权信息：</th>
                            <td>
                                <input type="text" name="cfg_footer_copyright" value="<?php echo e((string) ($cfgMap['footer_copyright'] ?? 'Copyright © 2026 东营行知实验学校 版权所有 | 建议使用 1024*768 分辨率浏览')); ?>" size="75">
                            </td>
                        </tr>
                        <tr>
                            <th></th>
                            <td><input type="submit" value="保存站点配置信息" style="padding:4px 18px;font-weight:bold;"></td>
                        </tr>
                    </table>
                </form>

            <?php elseif ($tab === 'badges'): ?>
                <!-- ==================== 页脚四大权威认证图标管理（并排显示，可上传图片、改链接） ==================== -->
                <?php $allFooterBadges = footer_badges_get_list(false); ?>
                <b>当前位置：页脚认证图标 &gt;&gt; 事业单位 / 可信网站 / IPv6 / 适老化无障碍等图标管理</b>
                <hr size="1">

                <form method="post" action="dashboard.php?tab=badges" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save_footer_badges">
                    <table border="1" cellspacing="0" cellpadding="4" width="100%">
                        <tr>
                            <th width="45">序号</th>
                            <th width="110">当前图标预览</th>
                            <th width="130">图标名称</th>
                            <th>图片路径/URL 或 本地上传新图片</th>
                            <th width="210">点击跳转链接</th>
                            <th width="55">排序</th>
                            <th width="70">状态</th>
                            <th width="60">操作</th>
                        </tr>
                        <?php foreach ($allFooterBadges as $fb): ?>
                            <?php
                            $bid = (int) $fb['id'];
                            $rawBImg = trim((string) $fb['img_url']);
                            $previewSrc = preg_match('#^(https?://|/)#i', $rawBImg) ? $rawBImg : ('../' . ltrim($rawBImg, '/'));
                            ?>
                            <tr>
                                <td>#<?php echo $bid; ?></td>
                                <td align="center" style="background:#1e3a5f;">
                                    <img src="<?php echo e($previewSrc); ?>" alt="<?php echo e((string) $fb['title']); ?>" style="display:inline-block !important;height:32px;width:auto;vertical-align:middle;">
                                </td>
                                <td>
                                    <input type="text" name="badge_title[<?php echo $bid; ?>]" value="<?php echo e((string) $fb['title']); ?>" size="15">
                                </td>
                                <td>
                                    路径/URL：<input type="text" name="badge_img[<?php echo $bid; ?>]" value="<?php echo e($rawBImg); ?>" size="28"><br>
                                    上传替换：<input type="file" name="badge_file_<?php echo $bid; ?>" accept=".png,.jpg,.jpeg,.gif,.svg,.webp">
                                </td>
                                <td>
                                    <input type="text" name="badge_link[<?php echo $bid; ?>]" value="<?php echo e((string) $fb['link_url']); ?>" size="26" placeholder="填跳转网址，不跳转填 #">
                                </td>
                                <td>
                                    <input type="text" name="badge_sort[<?php echo $bid; ?>]" value="<?php echo (int) $fb['sort_order']; ?>" size="3">
                                </td>
                                <td>
                                    <select name="badge_active[<?php echo $bid; ?>]">
                                        <option value="1" <?php echo (int) $fb['is_active'] === 1 ? 'selected' : ''; ?>>显示</option>
                                        <option value="0" <?php echo (int) $fb['is_active'] === 0 ? 'selected' : ''; ?>>隐藏</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="button" value="删除" onclick="if(confirm('确定删除该页脚图标吗？')){document.getElementById('delBadgeIdInput').value='<?php echo $bid; ?>';document.getElementById('delBadgeForm').submit();}">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td colspan="8">
                                <input type="submit" value="保存全部页脚图标设置" style="font-weight:bold;padding:3px 16px;">
                                &nbsp;<font color="#666666">（说明：所有设为“显示”的图标将在门户前台页脚、书记/校长后台页脚、管理员后台页脚横向并排展示）</font>
                            </td>
                        </tr>
                    </table>
                </form>

                <form method="post" action="dashboard.php?tab=badges" id="delBadgeForm" style="display:none;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="delete_footer_badge">
                    <input type="hidden" name="badge_id" id="delBadgeIdInput" value="">
                </form>

                <form method="post" action="dashboard.php?tab=badges" enctype="multipart/form-data" style="margin-top:6px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="add_footer_badge">
                    <table border="1" cellspacing="0" cellpadding="4" width="100%">
                        <tr>
                            <th width="140">新增页脚认证图标：</th>
                            <td>
                                名称：<input type="text" name="new_badge_title" size="14" placeholder="如：网安备案" required>
                                &nbsp;图片路径/URL：<input type="text" name="new_badge_img" size="22" placeholder="可选，或直接右侧上传">
                                &nbsp;或上传图片：<input type="file" name="new_badge_file" accept=".png,.jpg,.jpeg,.gif,.svg,.webp">
                                &nbsp;跳转链接：<input type="text" name="new_badge_link" value="#" size="18">
                                &nbsp;排序：<input type="text" name="new_badge_sort" value="5" size="3">
                                &nbsp;<input type="submit" value="新增图标">
                            </td>
                        </tr>
                    </table>
                </form>

<?php elseif ($tab === 'portal'): ?>
                <!-- ==================== 导航菜单与首页轮播图管理 ==================== -->
                <?php
                $banners  = $pdo->query("SELECT * FROM `banner_images` ORDER BY `sort_order` ASC, `id` ASC")->fetchAll();
                $navLinks = $pdo->query("SELECT * FROM `nav_links` ORDER BY COALESCE(`parent_id`, `id`), `parent_id` IS NOT NULL, `sort_order` ASC")->fetchAll();
                $topNavs  = array_filter($navLinks, function ($n) { return $n['parent_id'] === null; });
                ?>
                <b>当前位置：导航与轮播管理 &gt;&gt; 首页 Banner 轮播图与主导航菜单</b>
                <hr size="1">

                <b>一、首页轮播大图</b>
                <form method="post" action="dashboard.php?tab=portal" enctype="multipart/form-data" style="margin:6px 0;background:#f9f9f9;padding:8px;border:1px solid #ccc;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="add_banner">
                    <div>
                        <b>新增轮播图</b> ——
                        标题：<input type="text" name="banner_title" size="16" required>
                        &nbsp;跳转链接：<input type="text" name="banner_link" value="#" size="16">
                        &nbsp;排序：<input type="text" name="sort_order" value="1" size="4">
                    </div>
                    <div style="margin-top:6px;">
                        图片方式1（填链接/选站内图）：
                        <input type="text" name="banner_img" id="bannerImgInput" value="public/images/banner1.png" size="30">
                        <select onchange="if(this.value) document.getElementById('bannerImgInput').value=this.value;">
                            <option value="">-- 选择已有图片 --</option>
                            <option value="public/images/banner1.png">public/images/banner1.png</option>
                            <option value="public/images/logo2.png">public/images/logo2.png</option>
                        </select>
                        &nbsp;|&nbsp;
                        图片方式2（直接上传本地图）：
                        <input type="file" name="banner_upload" accept=".png,.jpg,.jpeg,.gif,.webp">
                        &nbsp;<input type="submit" value="添加轮播图">
                    </div>
                </form>

                <table border="1" cellspacing="0" cellpadding="5" width="100%">
                    <tr>
                        <th>ID</th>
                        <th>标题</th>
                        <th>图片路径</th>
                        <th>跳转链接</th>
                        <th>排序</th>
                        <th>操作</th>
                    </tr>
                    <?php foreach ($banners as $b): ?>
                        <tr>
                            <td><?php echo (int) $b['id']; ?></td>
                            <td><?php echo e((string) $b['title']); ?></td>
                            <td><?php echo e((string) $b['img']); ?></td>
                            <td><?php echo e((string) $b['link']); ?></td>
                            <td><?php echo (int) $b['sort_order']; ?></td>
                            <td>
                                <form method="post" action="dashboard.php?tab=portal" style="display:inline;" onsubmit="return confirm('确定删除该轮播图吗？');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="form_action" value="del_banner">
                                    <input type="hidden" name="banner_id" value="<?php echo (int) $b['id']; ?>">
                                    <input type="submit" value="删除">
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <br>
                <b>二、网站主导航菜单</b>
                <form method="post" action="dashboard.php?tab=portal" style="margin:6px 0;background:#f9f9f9;padding:6px;border:1px solid #ccc;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="add_nav_link">
                    新增菜单 —— 父级菜单：
                    <select name="parent_id">
                        <option value="">-- 顶级主菜单 --</option>
                        <?php foreach ($topNavs as $tn): ?>
                            <option value="<?php echo (int) $tn['id']; ?>"><?php echo e((string) $tn['text']); ?> (ID:<?php echo (int) $tn['id']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    菜单名称：<input type="text" name="nav_text" size="14" required>
                    链接地址：<input type="text" name="nav_url" value="#" size="20">
                    排序：<input type="text" name="sort_order" value="1" size="4">
                    <input type="submit" value="添加菜单项">
                </form>

                <table border="1" cellspacing="0" cellpadding="5" width="100%">
                    <tr>
                        <th>ID</th>
                        <th>层级 / 菜单名称</th>
                        <th>父级ID</th>
                        <th>链接地址</th>
                        <th>排序</th>
                        <th>操作</th>
                    </tr>
                    <?php foreach ($navLinks as $nl): ?>
                        <tr>
                            <td><?php echo (int) $nl['id']; ?></td>
                            <td><?php echo $nl['parent_id'] === null ? ('<b>' . e((string) $nl['text']) . '</b>') : ('&nbsp;&nbsp;├─ ' . e((string) $nl['text'])); ?></td>
                            <td><?php echo $nl['parent_id'] === null ? '顶级' : (int) $nl['parent_id']; ?></td>
                            <td><?php echo e((string) $nl['url']); ?></td>
                            <td><?php echo (int) $nl['sort_order']; ?></td>
                            <td>
                                <form method="post" action="dashboard.php?tab=portal" style="display:inline;" onsubmit="return confirm('确定删除该菜单项吗？');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="form_action" value="del_nav_link">
                                    <input type="hidden" name="nav_id" value="<?php echo (int) $nl['id']; ?>">
                                    <input type="submit" value="删除">
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

<?php elseif ($tab === 'mailbox'): ?>
                <!-- ==================== 书记信箱与校长信箱管理 ==================== -->
                <?php
                $pdo->exec("
                CREATE TABLE IF NOT EXISTS `leader_mailbox` (
                  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                  `query_code` varchar(24) NOT NULL,
                  `box_type` enum('shuji','xiaozhang') NOT NULL DEFAULT 'xiaozhang',
                  `sender_name` varchar(64) NOT NULL,
                  `sender_role` varchar(32) NOT NULL DEFAULT '学生',
                  `contact_email` varchar(128) DEFAULT NULL,
                  `contact_phone` varchar(32) DEFAULT NULL,
                  `subject` varchar(200) NOT NULL,
                  `content` text NOT NULL,
                  `is_public` tinyint(1) NOT NULL DEFAULT 1,
                  `status` tinyint(1) NOT NULL DEFAULT 0,
                  `reply_content` text DEFAULT NULL,
                  `reply_dept` varchar(64) DEFAULT NULL,
                  `replied_at` datetime DEFAULT NULL,
                  `ip_address` varchar(45) DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uk_query_code` (`query_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
                ");
                $filterBox = (string) ($_GET['box'] ?? '');
                $whereBox = '';
                $boxParams = [];
                if (in_array($filterBox, ['shuji', 'xiaozhang'], true)) {
                    $whereBox = 'WHERE `box_type` = :bt';
                    $boxParams[':bt'] = $filterBox;
                }
                $mStmt = $pdo->prepare("SELECT * FROM `leader_mailbox` {$whereBox} ORDER BY `status` ASC, `id` DESC");
                $mStmt->execute($boxParams);
                $letters = $mStmt->fetchAll();
                ?>
                <b>当前位置：书记/校长信箱 &gt;&gt; 师生来信批复与公开选登管理</b>
                <hr size="1">

                <div class="toolbar-box">
                    按信箱筛选：
                    <a href="dashboard.php?tab=mailbox">[全部来信]</a> ｜
                    <a href="dashboard.php?tab=mailbox&box=shuji">[仅看书记信箱]</a> ｜
                    <a href="dashboard.php?tab=mailbox&box=xiaozhang">[仅看校长信箱]</a> ｜
                    <a href="../mailbox.php" target="_blank">[打开前台写信页面]</a>
                </div>

                <table border="1" cellspacing="0" cellpadding="5" width="100%">
                    <tr>
                        <th width="40">ID</th>
                        <th width="85">信箱 / 查询码</th>
                        <th width="130">写信人 / 联系方式</th>
                        <th>来信主题与内容</th>
                        <th width="340">批复回复操作</th>
                    </tr>
                    <?php if (empty($letters)): ?>
                        <tr><td colspan="5" align="center">暂无来信记录</td></tr>
                    <?php else: ?>
                        <?php foreach ($letters as $lt): ?>
                            <tr>
                                <td><?php echo (int) $lt['id']; ?></td>
                                <td>
                                    <b><?php echo $lt['box_type'] === 'shuji' ? '<font color="#c00">书记信箱</font>' : '<font color="#16569a">校长信箱</font>'; ?></b><br>
                                    <?php echo e((string) $lt['query_code']); ?><br>
                                    <?php echo ((int) $lt['status'] === 1) ? '<font color="green">[已办结]</font>' : '<font color="#cc6600">[待批复]</font>'; ?>
                                </td>
                                <td>
                                    <b><?php echo e((string) $lt['sender_name']); ?></b> (<?php echo e((string) $lt['sender_role']); ?>)<br>
                                    电话：<?php echo e((string) ($lt['contact_phone'] ?: '-')); ?><br>
                                    邮箱：<?php echo e((string) ($lt['contact_email'] ?: '-')); ?><br>
                                    时间：<?php echo e((string) $lt['created_at']); ?>
                                </td>
                                <td valign="top">
                                    <div><b>主题：<?php echo e((string) $lt['subject']); ?></b></div>
                                    <div style="margin-top:6px;white-space:pre-wrap;color:#333;"><?php echo e((string) $lt['content']); ?></div>
                                </td>
                                <td valign="top">
                                    <form method="post" action="dashboard.php?tab=mailbox" style="margin-bottom:4px;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="reply_mailbox">
                                        <input type="hidden" name="letter_id" value="<?php echo (int) $lt['id']; ?>">
                                        <textarea name="reply_content" rows="3" style="width:96%;" placeholder="输入批复/答复内容..."><?php echo e((string) ($lt['reply_content'] ?? '')); ?></textarea>
                                        <div style="margin-top:4px;">
                                            答复部门：<input type="text" name="reply_dept" value="<?php echo e((string) ($lt['reply_dept'] ?: ($lt['box_type'] === 'shuji' ? '校党委办公室' : '校长办公室'))); ?>" size="14">
                                            <select name="is_public">
                                                <option value="1" <?php echo ((int) $lt['is_public'] === 1) ? 'selected' : ''; ?>>公开选登</option>
                                                <option value="0" <?php echo ((int) $lt['is_public'] === 0) ? 'selected' : ''; ?>>不公开</option>
                                            </select>
                                        </div>
                                        <div style="margin-top:4px;">
                                            <input type="submit" value="保存批复">
                                        </div>
                                    </form>
                                    <form method="post" action="dashboard.php?tab=mailbox" onsubmit="return confirm('确定删除该封来信吗？');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="del_mailbox">
                                        <input type="hidden" name="letter_id" value="<?php echo (int) $lt['id']; ?>">
                                        <input type="submit" value="删除信件" >
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>

            <?php elseif ($tab === 'plugins'): ?>
                <!-- ==================== 插件扩展中心（上传 .zip 安装 / 启停 / 弹窗参数设置 / 导出） ==================== -->
                <?php $allPlugins = plugin_get_all(); ?>
                <b>当前位置：插件扩展中心 &gt;&gt; 外部插件包 (.zip) 安装与功能扩展模块</b>
                <hr size="1">

                <!-- 1. 上传安装 .zip 插件包 -->
                <form method="post" action="dashboard.php?tab=plugins" enctype="multipart/form-data" class="toolbar-box">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="plugin_upload_zip">
                    <b>【安装新插件】</b>
                    选择本地插件包 (.zip)：<input type="file" name="plugin_zip" accept=".zip" required>
                    <input type="submit" value="上传并安装插件">
                    &nbsp;&nbsp;|&nbsp;&nbsp;
                    <a href="dashboard.php?action=export_plugin_zip&pid=__template__" style="color:#16569a;font-weight:bold;">
                        [点击下载《标准插件开发模板》.zip]
                    </a>
                    <span>（下载模板或导出下方任意插件为 .zip 后，均可在此直接上传安装）</span>
                </form>

                <!-- 2. 已安装插件列表 -->
                <table border="1" cellspacing="0" cellpadding="6" width="100%">
                    <tr>
                        <th width="220">插件名称 / 标识</th>
                        <th width="70">版本</th>
                        <th width="160">开发者</th>
                        <th>功能简介与当前参数预览</th>
                        <th width="85">运行状态</th>
                        <th width="270">管理操作</th>
                    </tr>
                    <?php if (empty($allPlugins)): ?>
                        <tr><td colspan="6" align="center">暂未安装任何插件，请通过上方上传 .zip 插件包安装</td></tr>
                    <?php else: ?>
                        <?php foreach ($allPlugins as $plg): ?>
                            <tr>
                                <td>
                                    <b><?php echo e($plg['name']); ?></b><br>
                                    <font color="#666666">ID: <?php echo e($plg['id']); ?></font>
                                </td>
                                <td>v<?php echo e($plg['version']); ?></td>
                                <td><?php echo e($plg['author']); ?></td>
                                <td>
                                    <div><?php echo e($plg['description']); ?></div>
                                    <?php if (!empty($plg['settings'])): ?>
                                        <div style="margin-top:4px;color:#555;font-size:12px;">
                                            当前参数：
                                            <?php
                                            $previewItems = [];
                                            foreach ($plg['schema'] as $k => $def) {
                                                $previewItems[] = ($def['label'] ?? $k) . '=' . ($plg['settings'][$k] ?? '');
                                            }
                                            echo e(implode(' ｜ ', $previewItems));
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td align="center">
                                    <?php if ($plg['is_enabled']): ?>
                                        <font color="#008800"><b>● 已启用</b></font>
                                    <?php else: ?>
                                        <font color="#888888">○ 已停用</font>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!-- 启用 / 停用 -->
                                    <form method="post" action="dashboard.php?tab=plugins" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="plugin_toggle">
                                        <input type="hidden" name="plugin_id" value="<?php echo e($plg['id']); ?>">
                                        <input type="hidden" name="enable_val" value="<?php echo $plg['is_enabled'] ? '0' : '1'; ?>">
                                        <?php if ($plg['is_enabled']): ?>
                                            <input type="submit" value="停用" >
                                        <?php else: ?>
                                            <input type="submit" value="启用" >
                                        <?php endif; ?>
                                    </form>

                                    <?php if (!empty($plg['schema'])): ?>
                                        &nbsp;<button type="button" onclick="openPluginModal('<?php echo e($plg['id']); ?>')">参数设置</button>
                                    <?php endif; ?>

                                    &nbsp;<a href="dashboard.php?action=export_plugin_zip&pid=<?php echo e($plg['id']); ?>">[导出.zip]</a>

                                    &nbsp;
                                    <form method="post" action="dashboard.php?tab=plugins" style="display:inline;" onsubmit="return confirm('确定要卸载并删除插件《<?php echo e($plg['name']); ?>》吗？（卸载前可先点击[导出.zip]备份）');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="form_action" value="plugin_uninstall">
                                        <input type="hidden" name="plugin_id" value="<?php echo e($plg['id']); ?>">
                                        <input type="submit" value="卸载" >
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>

                <!-- 3. 各插件的弹窗模态框（点击“参数设置”弹出） -->
                <?php foreach ($allPlugins as $plg): ?>
                    <?php if (empty($plg['schema'])) continue; ?>
                    <div id="plgModal_<?php echo e($plg['id']); ?>" style="display:none;position:fixed;left:0;top:0;width:100%;height:100%;z-index:9999;background:rgba(0,0,0,0.45);">
                        <div style="width:620px;max-width:94%;margin:110px auto 0;background:#fff;border:2px solid #404040;">
                            <!-- 弹窗标题栏 -->
                            <div style="background:#d4d0c8;color:#000;padding:5px 8px;font-size:12px;font-weight:bold;border-bottom:1px solid #808080;">
                                <span>插件参数设置 —— 《<?php echo e($plg['name']); ?>》</span>
                                <a href="javascript:void(0)" onclick="closePluginModal('<?php echo e($plg['id']); ?>')" style="float:right;color:#fff;text-decoration:none;font-size:16px;line-height:1;">×</a>
                            </div>
                            <!-- 弹窗表单主体 -->
                            <form method="post" action="dashboard.php?tab=plugins" style="padding:16px 18px;margin:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="form_action" value="plugin_save_cfg">
                                <input type="hidden" name="plugin_id" value="<?php echo e($plg['id']); ?>">

                                <table border="1" cellspacing="0" cellpadding="6" width="100%">
                                    <?php foreach ($plg['schema'] as $sKey => $sDef): ?>
                                        <?php
                                        $sLabel = (string) ($sDef['label'] ?? $sKey);
                                        $sType  = (string) ($sDef['type'] ?? 'text');
                                        $sVal   = (string) ($plg['settings'][$sKey] ?? ($sDef['default'] ?? ''));
                                        ?>
                                        <tr>
                                            <th width="180"><?php echo e($sLabel); ?>：</th>
                                            <td>
                                                <?php if ($sType === 'color'): ?>
                                                    <input type="color" name="plg_cfg[<?php echo e($sKey); ?>]" value="<?php echo e($sVal); ?>">
                                                <?php else: ?>
                                                    <input type="text" name="plg_cfg[<?php echo e($sKey); ?>]" value="<?php echo e($sVal); ?>" style="width:95%;">
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>

                                <div style="margin-top:14px;text-align:right;">
                                    <button type="button" onclick="closePluginModal('<?php echo e($plg['id']); ?>')" >取消关闭</button>
                                    <input type="submit" value="保存参数配置" style="font-weight:bold;padding:5px 18px;">
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>

                <script type="text/javascript">
                    function openPluginModal(pid) {
                        var el = document.getElementById('plgModal_' + pid);
                        if (el) el.style.display = 'block';
                    }
                    function closePluginModal(pid) {
                        var el = document.getElementById('plgModal_' + pid);
                        if (el) el.style.display = 'none';
                    }
                </script>

            <?php elseif ($tab === 'traffic'): ?>
                <!-- ==================== 网站流量统计页面 ==================== -->
                <?php
                $tStat = traffic_get_summary();
                $dailyRows = $pdo->query("SELECT * FROM `site_traffic_daily` ORDER BY `stat_date` DESC LIMIT 14")->fetchAll();
                $visitLogs = $pdo->query("SELECT * FROM `site_visit_logs` ORDER BY `id` DESC LIMIT 20")->fetchAll();
                ?>
                <b>当前位置：网站流量统计 &gt;&gt; 首页与全站访问量监控 (PV / UV)</b>
                <hr size="1">

                <table border="1" cellspacing="0" cellpadding="6" width="100%">
                    <tr>
                        <th width="20%">今日浏览量 (PV)</th>
                        <th width="20%">今日独立访客 (UV)</th>
                        <th width="20%">昨日浏览量 (PV)</th>
                        <th width="20%">当前在线人数(15分钟内)</th>
                        <th width="20%">累计总访问量</th>
                    </tr>
                    <tr>
                        <td><b style="font-size:18px;color:#16569a;"><?php echo (int) $tStat['today_pv']; ?></b> 次</td>
                        <td><b style="font-size:18px;color:#008800;"><?php echo (int) $tStat['today_uv']; ?></b> 人</td>
                        <td><b><?php echo (int) $tStat['yesterday_pv']; ?></b> 次</td>
                        <td><b style="color:#cc6600;"><?php echo (int) $tStat['online_count']; ?></b> 人</td>
                        <td><b style="font-size:18px;color:#cc0000;"><?php echo (int) $tStat['total_pv']; ?></b> 次</td>
                    </tr>
                </table>

                <?php
                $hourlyPvCfg     = traffic_get_hourly_pv_config();
                $hourlyOnlineCfg = traffic_get_hourly_online_config();
                $currHourNow     = (int) date('G');
                ?>
                <form method="post" action="dashboard.php?tab=traffic" style="margin:12px 0;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="form_action" value="save_traffic_base">

                    <table border="1" cellspacing="0" cellpadding="5" width="100%" style="margin-bottom:12px;">
                        <tr>
                            <th width="220">一、网站总访问量初始基数：</th>
                            <td>
                                <input type="text" name="traffic_base_pv" value="<?php echo (int) $tStat['base_pv']; ?>" size="16">
                                <span>（前台显示总访问量 = 初始基数 <?php echo (int) $tStat['base_pv']; ?> + 累计增量 <?php echo (int) $tStat['real_sum_pv']; ?> = <b><?php echo (int) $tStat['total_pv']; ?></b>）</span>
                            </td>
                        </tr>
                    </table>

                    <b>二、24小时分时段【每小时自动增加访问量(PV)】随机区间设置（例如 1点：<code>1-6</code>，2点：<code>1-5</code>）：</b>
                    <table border="1" cellspacing="0" cellpadding="4" width="100%" style="margin:6px 0 12px;">
                        <tr>
                            <?php for ($h = 0; $h < 12; $h++): ?>
                                <th style="<?php echo $h === $currHourNow ? 'background:#16569a;color:#fff;' : ''; ?>">
                                    <?php echo $h; ?>点<?php echo $h === $currHourNow ? '(当前)' : ''; ?>
                                </th>
                            <?php endfor; ?>
                        </tr>
                        <tr>
                            <?php for ($h = 0; $h < 12; $h++): ?>
                                <td><input type="text" name="hourly_pv[<?php echo $h; ?>]" value="<?php echo e((string) $hourlyPvCfg[$h]); ?>" size="5"></td>
                            <?php endfor; ?>
                        </tr>
                        <tr>
                            <?php for ($h = 12; $h < 24; $h++): ?>
                                <th style="<?php echo $h === $currHourNow ? 'background:#16569a;color:#fff;' : ''; ?>">
                                    <?php echo $h; ?>点<?php echo $h === $currHourNow ? '(当前)' : ''; ?>
                                </th>
                            <?php endfor; ?>
                        </tr>
                        <tr>
                            <?php for ($h = 12; $h < 24; $h++): ?>
                                <td><input type="text" name="hourly_pv[<?php echo $h; ?>]" value="<?php echo e((string) $hourlyPvCfg[$h]); ?>" size="5"></td>
                            <?php endfor; ?>
                        </tr>
                    </table>

                    <b>三、24小时分时段【前台显示在线浏览人数】随机区间设置（例如 1点：<code>1-6</code>，2点：<code>1-5</code>，系统按当前小时的区间随机显示）：</b>
                    <table border="1" cellspacing="0" cellpadding="4" width="100%" style="margin:6px 0 12px;">
                        <tr>
                            <?php for ($h = 0; $h < 12; $h++): ?>
                                <th style="<?php echo $h === $currHourNow ? 'background:#16569a;color:#fff;' : ''; ?>">
                                    <?php echo $h; ?>点<?php echo $h === $currHourNow ? '(当前)' : ''; ?>
                                </th>
                            <?php endfor; ?>
                        </tr>
                        <tr>
                            <?php for ($h = 0; $h < 12; $h++): ?>
                                <td><input type="text" name="hourly_online[<?php echo $h; ?>]" value="<?php echo e((string) $hourlyOnlineCfg[$h]); ?>" size="5"></td>
                            <?php endfor; ?>
                        </tr>
                        <tr>
                            <?php for ($h = 12; $h < 24; $h++): ?>
                                <th style="<?php echo $h === $currHourNow ? 'background:#16569a;color:#fff;' : ''; ?>">
                                    <?php echo $h; ?>点<?php echo $h === $currHourNow ? '(当前)' : ''; ?>
                                </th>
                            <?php endfor; ?>
                        </tr>
                        <tr>
                            <?php for ($h = 12; $h < 24; $h++): ?>
                                <td><input type="text" name="hourly_online[<?php echo $h; ?>]" value="<?php echo e((string) $hourlyOnlineCfg[$h]); ?>" size="5"></td>
                            <?php endfor; ?>
                        </tr>
                    </table>

                    <div style="margin-bottom:14px;">
                        <input type="submit" value="保存24小时流量区间与在线人数区间配置" style="padding:6px 22px;font-weight:bold;">
                    </div>
                </form>

                <b>近 14 天每日访问统计：</b>
                <table border="1" cellspacing="0" cellpadding="5" width="100%" style="margin-bottom:14px;">
                    <tr>
                        <th>日期</th>
                        <th>当日浏览量 (PV)</th>
                        <th>当日独立IP访客 (UV)</th>
                    </tr>
                    <?php foreach ($dailyRows as $dr): ?>
                        <tr>
                            <td><?php echo e((string) $dr['stat_date']); ?></td>
                            <td><?php echo (int) $dr['pv']; ?> 次</td>
                            <td><?php echo (int) $dr['uv']; ?> 人</td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <b>最近 20 条实时访问明细：</b>
                <table border="1" cellspacing="0" cellpadding="5" width="100%">
                    <tr>
                        <th>ID</th>
                        <th>访客 IP</th>
                        <th>受访页面路径</th>
                        <th>客户端浏览器标识 (User-Agent)</th>
                        <th>访问时间</th>
                    </tr>
                    <?php foreach ($visitLogs as $vl): ?>
                        <tr>
                            <td><?php echo (int) $vl['id']; ?></td>
                            <td><?php echo e((string) $vl['ip_address']); ?></td>
                            <td><?php echo e((string) $vl['page_uri']); ?></td>
                            <td><?php echo e(mb_substr((string) ($vl['user_agent'] ?? ''), 0, 80, 'UTF-8')); ?></td>
                            <td><?php echo e((string) $vl['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>

            <?php elseif ($tab === 'logs'): ?>
                <!-- ==================== 6. 登录安全审计日志 ==================== -->
                <?php
                $logs = $pdo->query("SELECT * FROM `auth_login_logs` ORDER BY `id` DESC LIMIT 30")->fetchAll();
                ?>
                <b>当前位置：登录安全日志 &gt;&gt; 最近 30 条登录审计记录（身份证号已自动脱敏）</b>
                <hr size="1">
                <table border="1" cellspacing="0" cellpadding="5" width="100%">
                    <tr>
                        <th>日志ID</th>
                        <th>输入账号(脱敏)</th>
                        <th>用户ID</th>
                        <th>登录方式</th>
                        <th>客户端IP</th>
                        <th>结果</th>
                        <th>失败原因</th>
                        <th>时间</th>
                    </tr>
                    <?php foreach ($logs as $lg): ?>
                        <tr>
                            <td><?php echo (int) $lg['id']; ?></td>
                            <td><?php echo e((string) $lg['account_input']); ?></td>
                            <td><?php echo $lg['user_id'] ? (int) $lg['user_id'] : '-'; ?></td>
                            <td><?php echo e((string) $lg['login_type']); ?></td>
                            <td><?php echo e((string) $lg['ip_address']); ?></td>
                            <td><?php echo ((int) $lg['is_success'] === 1) ? '<font color="green">成功</font>' : '<font color="red">失败</font>'; ?></td>
                            <td><?php echo e((string) ($lg['fail_reason'] ?? '-')); ?></td>
                            <td><?php echo e((string) $lg['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </td>
    </tr>
</table>

<!-- 正常网站页脚（读取站点配置中的联系方式、安全警告语、版权备案、灰色英文技术支持及并排四大认证图标） -->
<?php
$ftCfg = [
    'title'            => '东营区行知实验学校',
    'address'          => '',
    'postal_code'      => '',
    'icp'              => 'f',
    'footer_email'     => '----@---.cn',
    'footer_phone'     => '--------',
    'security_warning' => '非涉密平台，严禁处理涉密信息！',
    'security_color'   => '#cc0000',
    'footer_copyright' => 'Copyright © 2026 东营区行知实验学校 版权所有 | 建议使用 1024*768 分辨率浏览',
];
try {
    $qFt = $pdo->query("SELECT `name`, `description` FROM `config` WHERE `name` IN ('title','address','postal_code','icp','footer_email','footer_phone','security_warning','security_color','footer_copyright')");
    foreach ($qFt->fetchAll() as $fr) {
        if ($fr['description'] !== null && $fr['description'] !== '') {
            $ftCfg[$fr['name']] = (string) $fr['description'];
        }
    }
} catch (Throwable $e) {}
?>
<div style="width:98%;margin:16px auto 24px;padding:12px 0 8px;border-top:1px solid #b8c6d6;text-align:center;font-size:12px;color:#333;line-height:1.9;">
    <div>
        <span>信息化处邮箱：<?php echo e($ftCfg['footer_email']); ?></span>
        &nbsp;｜&nbsp;
        <span>电话：<?php echo e($ftCfg['footer_phone']); ?></span>
        <?php if (!empty($ftCfg['address']) && $ftCfg['address'] !== 'f'): ?>
            &nbsp;｜&nbsp;<span>地址：<?php echo e($ftCfg['address']); ?></span>
        <?php endif; ?>
        <?php if (!empty($ftCfg['postal_code']) && $ftCfg['postal_code'] !== 'f'): ?>
            &nbsp;｜&nbsp;<span>邮编：<?php echo e($ftCfg['postal_code']); ?></span>
        <?php endif; ?>
        <?php if (!empty($ftCfg['icp']) && $ftCfg['icp'] !== 'f'): ?>
            &nbsp;｜&nbsp;<span>ICP备案号：<?php echo e($ftCfg['icp']); ?></span>
        <?php endif; ?>
    </div>
    <div>
        <?php if (!empty($ftCfg['security_warning'])): ?>
            <span style="color:<?php echo e($ftCfg['security_color']); ?>;font-weight:bold;"><?php echo e($ftCfg['security_warning']); ?></span>
            &nbsp;｜&nbsp;
        <?php endif; ?>
        <span><?php echo e($ftCfg['footer_copyright']); ?></span>
    </div>
    <div style="color:#8892a0;font-family:Arial,Helvetica,sans-serif;font-size:11px;">
        Copyright &copy; <?php echo date('Y'); ?> Dongying Xingzhi Experimental School. All Rights Reserved. Technical Support: Shaanxi Jimayun Education Innovation Studio.
    </div>
    <?php echo footer_badges_render_html('../'); ?>
</div>
</body>
</html>