<?php
/**
 * 学校门户插件扩展引擎（支持 .zip 上传安装、导出、一键启停、可视化参数配置与全站 Hook 挂载）
 */

require_once __DIR__ . '/pdo.php';

/**
 * 获取插件根目录绝对路径 (D:\school\plugins)
 */
function plugin_root_dir(): string
{
    $dir = dirname(__DIR__, 2) . '/plugins';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * 确保插件状态数据库表存在并初始化默认内置插件
 */
function plugin_ensure_table(): void
{
    global $pdo;
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `system_plugins` (
          `plugin_id` varchar(64) NOT NULL COMMENT '插件唯一标识',
          `is_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否启用：1启用 0停用',
          `settings_json` text DEFAULT NULL COMMENT '插件自定义参数JSON',
          `installed_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '安装时间',
          PRIMARY KEY (`plugin_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统扩展插件表';
    ");
}

/**
 * 扫描并获取所有已安装插件列表及其状态、配置项定义与当前配置值
 */
function plugin_get_all(): array
{
    global $pdo;
    plugin_ensure_table();

    $dbRows = $pdo->query("SELECT `plugin_id`, `is_enabled`, `settings_json`, `installed_at` FROM `system_plugins`")->fetchAll();
    $dbMap = [];
    foreach ($dbRows as $r) {
        $dbMap[$r['plugin_id']] = $r;
    }

    $root = plugin_root_dir();
    $dirs = @scandir($root);
    if (!is_array($dirs)) {
        return [];
    }

    $plugins = [];
    foreach ($dirs as $d) {
        if ($d === '.' || $d === '..') continue;
        $pDir = $root . '/' . $d;
        $manifestPath = $pDir . '/plugin.json';
        $mainPath     = $pDir . '/main.php';
        if (!is_dir($pDir) || !is_file($manifestPath) || !is_file($mainPath)) {
            continue;
        }
        $meta = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($meta) || empty($meta['id'])) {
            continue;
        }
        $pid = (string) $meta['id'];

        // 默认配置值
        $defaultSettings = [];
        $schema = is_array($meta['schema'] ?? null) ? $meta['schema'] : [];
        foreach ($schema as $fieldKey => $fieldDef) {
            $defaultSettings[$fieldKey] = $fieldDef['default'] ?? '';
        }

        if (!isset($dbMap[$pid])) {
            $ins = $pdo->prepare("INSERT IGNORE INTO `system_plugins` (`plugin_id`, `is_enabled`, `settings_json`) VALUES (:id, 0, :s)");
            $ins->execute([':id' => $pid, ':s' => json_encode($defaultSettings, JSON_UNESCAPED_UNICODE)]);
            $isEnabled = 0;
            $savedSettings = $defaultSettings;
            $installedAt = date('Y-m-d H:i:s');
        } else {
            $isEnabled = (int) $dbMap[$pid]['is_enabled'];
            $decoded = json_decode((string) $dbMap[$pid]['settings_json'], true);
            $savedSettings = is_array($decoded) ? array_merge($defaultSettings, $decoded) : $defaultSettings;
            $installedAt = (string) $dbMap[$pid]['installed_at'];
        }

        $plugins[$pid] = [
            'id'           => $pid,
            'name'         => (string) ($meta['name'] ?? $pid),
            'version'      => (string) ($meta['version'] ?? '1.0.0'),
            'author'       => (string) ($meta['author'] ?? 'Shaanxi Jimayun Education Innovation Studio'),
            'description'  => (string) ($meta['description'] ?? ''),
            'is_enabled'   => $isEnabled === 1,
            'schema'       => $schema,
            'settings'     => $savedSettings,
            'installed_at' => $installedAt,
            'dir'          => $pDir,
            'main_file'    => $mainPath,
        ];
    }

    return $plugins;
}

/**
 * 切换插件启用/停用状态
 */
function plugin_set_enabled(string $pluginId, bool $enabled): void
{
    global $pdo;
    plugin_ensure_table();
    $stmt = $pdo->prepare("UPDATE `system_plugins` SET `is_enabled` = :e WHERE `plugin_id` = :id");
    $stmt->execute([':e' => $enabled ? 1 : 0, ':id' => $pluginId]);
}

/**
 * 保存插件自定义配置参数
 */
function plugin_save_settings(string $pluginId, array $inputSettings): void
{
    global $pdo;
    $all = plugin_get_all();
    if (!isset($all[$pluginId])) return;

    $schema = $all[$pluginId]['schema'];
    $clean  = $all[$pluginId]['settings'];
    foreach ($schema as $k => $def) {
        if (isset($inputSettings[$k])) {
            $clean[$k] = trim((string) $inputSettings[$k]);
        }
    }

    $stmt = $pdo->prepare("UPDATE `system_plugins` SET `settings_json` = :s WHERE `plugin_id` = :id");
    $stmt->execute([':s' => json_encode($clean, JSON_UNESCAPED_UNICODE), ':id' => $pluginId]);
}

/**
 * 卸载插件（删除插件目录及数据库记录）
 */
function plugin_uninstall(string $pluginId): bool
{
    global $pdo;
    $pluginId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $pluginId);
    if ($pluginId === '') return false;

    $pDir = plugin_root_dir() . '/' . $pluginId;
    if (is_dir($pDir)) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
        @rmdir($pDir);
    }

    $pdo->prepare("DELETE FROM `system_plugins` WHERE `plugin_id` = :id")->execute([':id' => $pluginId]);
    return true;
}

/**
 * 从上传的 .zip 压缩包安装插件
 *
 * @return array{ok:bool, message:string}
 */
function plugin_install_from_zip(string $tmpZipFile): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => '当前 PHP 环境未开启 ZipArchive 扩展，无法解压 .zip 插件包。'];
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZipFile) !== true) {
        return ['ok' => false, 'message' => '无法打开上传的 .zip 压缩包，请确认文件未损坏。'];
    }

    $manifestJson = null;
    $manifestPrefix = '';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = str_replace('\\', '/', $zip->getNameIndex($i));
        if (basename($entryName) === 'plugin.json' && substr_count($entryName, '/') <= 1) {
            $manifestJson = $zip->getFromIndex($i);
            $dirName = dirname($entryName);
            $manifestPrefix = ($dirName === '.' || $dirName === '') ? '' : ($dirName . '/');
            break;
        }
    }

    if ($manifestJson === null || $manifestJson === false) {
        $zip->close();
        return ['ok' => false, 'message' => '安装失败：压缩包内未找到有效的 plugin.json 插件描述文件。'];
    }

    $meta = json_decode((string) $manifestJson, true);
    if (!is_array($meta) || empty($meta['id']) || empty($meta['name'])) {
        $zip->close();
        return ['ok' => false, 'message' => '安装失败：plugin.json 格式不合法（缺少 id 或 name 字段）。'];
    }

    $pluginId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $meta['id']);
    if ($pluginId === '') {
        $zip->close();
        return ['ok' => false, 'message' => '安装失败：插件 ID 只能包含字母、数字、下划线或连字符。'];
    }

    $targetDir = plugin_root_dir() . '/' . $pluginId;
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = str_replace('\\', '/', $zip->getNameIndex($i));
        if ($manifestPrefix !== '' && strpos($entryName, $manifestPrefix) === 0) {
            $relName = substr($entryName, strlen($manifestPrefix));
        } else {
            $relName = $entryName;
        }
        $relName = ltrim($relName, '/');
        if ($relName === '' || strpos($relName, '..') !== false) {
            continue;
        }
        $destPath = $targetDir . '/' . $relName;
        if (substr($entryName, -1) === '/') {
            if (!is_dir($destPath)) @mkdir($destPath, 0755, true);
        } else {
            if (!is_dir(dirname($destPath))) @mkdir(dirname($destPath), 0755, true);
            $stream = $zip->getFromIndex($i);
            if ($stream !== false) {
                file_put_contents($destPath, $stream);
            }
        }
    }
    $zip->close();

    if (!is_file($targetDir . '/main.php')) {
        return ['ok' => false, 'message' => '安装失败：插件包内缺少核心执行文件 main.php。'];
    }

    // 刷新注册表
    plugin_get_all();
    return ['ok' => true, 'message' => "插件《{$meta['name']}》(v{$meta['version']}) 安装成功！可在下方列表中点击「启用」。"];
}

/**
 * 打包导出已安装的插件为 .zip（或下载标准插件开发模板 .zip）
 */
function plugin_download_zip(string $pluginId): void
{
    if (!class_exists('ZipArchive')) {
        exit('ZipArchive extension is required.');
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'plg_') . '.zip';
    $zip = new ZipArchive();
    $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($pluginId === '__template__') {
        $tplJson = json_encode([
            'id'          => 'demo_hello_banner',
            'name'        => '示例：欢迎标语横幅插件',
            'version'     => '1.0.0',
            'author'      => 'Shaanxi Jimayun Education Innovation Studio',
            'description' => '这是一个标准的示例插件包，启用后会在网站顶部显示一条自定义欢迎标语。您可以修改 plugin.json 和 main.php 后重新打包上传安装。',
            'schema'      => [
                'welcome_text' => ['label' => '横幅欢迎文字', 'type' => 'text', 'default' => '热烈欢迎各位师生访问东营区行知实验学校门户网站！'],
                'bg_color'     => ['label' => '横幅背景颜色', 'type' => 'color', 'default' => '#1e40af'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $tplMain = <<<'PHP'
<?php
// 插件入口文件：$hook 为当前触发的钩子名（'head_top' | 'footer_end'），$settings 为当前插件后台配置参数数组
if ($hook === 'head_top') {
    $txt = htmlspecialchars((string) ($settings['welcome_text'] ?? ''), ENT_QUOTES, 'UTF-8');
    $bg  = htmlspecialchars((string) ($settings['bg_color'] ?? '#1e40af'), ENT_QUOTES, 'UTF-8');
    echo '<div style="background:' . $bg . ';color:#fff;text-align:center;padding:8px 12px;font-size:14px;font-weight:bold;">' . $txt . '</div>';
}
PHP;
        $zip->addFromString('demo_hello_banner/plugin.json', $tplJson);
        $zip->addFromString('demo_hello_banner/main.php', $tplMain);
        $downloadName = '标准插件开发模板_demo_hello_banner.zip';
    } else {
        $all = plugin_get_all();
        if (!isset($all[$pluginId])) {
            $zip->close();
            @unlink($tmpZip);
            exit('Plugin not found.');
        }
        $pDir = $all[$pluginId]['dir'];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relPath  = $pluginId . '/' . substr(str_replace('\\', '/', $filePath), strlen(str_replace('\\', '/', $pDir)) + 1);
                $zip->addFile($filePath, $relPath);
            }
        }
        $downloadName = '插件包_' . $pluginId . '.zip';
    }

    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($tmpZip));
    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

/**
 * 在前台页面触发指定钩子，执行所有已启用插件的挂载代码
 *
 * @param string $hook 'head_top' | 'footer_end'
 */
function plugin_run_hook(string $hook): void
{
    try {
        $plugins = plugin_get_all();
        foreach ($plugins as $plg) {
            if (!$plg['is_enabled']) continue;
            $settings = $plg['settings'];
            $mainFile = $plg['main_file'];
            if (is_file($mainFile)) {
                include $mainFile;
            }
        }
    } catch (Throwable $e) {
    }
}
