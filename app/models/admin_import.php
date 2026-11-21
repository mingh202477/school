<?php
/**
 * 批量导入与模板下载处理模块（真实开源库驱动版）
 *
 * 使用的开源依赖（Composer）：
 * 1. shuchkin/simplexlsxgen : 真实生成标准 OpenXML (.xlsx / .xlxs) 表格文件
 * 2. shuchkin/simplexlsx    : 解析标准 OpenXML (.xlsx / .xlxs) 表格文件
 * 3. shuchkin/simplexls     : 解析标准二进制 BIFF8 OLE2 (.xls, Excel 97-2003 / WPS) 表格文件
 * 4. hisamu/php-xbase       : 解析标准 dBASE / FoxPro (.dbf) 数据库文件（含 GBK/UTF-8 编码自适应）
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/auth.php';

use Shuchkin\SimpleXLS;
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;
use XBase\TableReader;

/**
 * 输出导入模板文件下载
 */
function admin_output_import_template(string $ext): void
{
    $ext = strtolower(trim($ext));
    $sampleRows = [
        ['账号', '姓名', '身份组', '证件类型', '证件号码'],
        ['20260101', '王晓明', 'student', '居民身份证', '37050220080512345X'],
        ['T10086',   '李建国', 'teacher', '居民身份证', '370502198210156789'],
    ];

    if ($ext === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="学生信息导入模板_不可修改表头.csv"');
        echo "\xEF\xBB\xBF";
        foreach ($sampleRows as $r) {
            echo implode(',', $r) . "\r\n";
        }
        exit;
    }

    if ($ext === 'txt') {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="学生信息导入模板_不可修改文件头.txt"');
        foreach ($sampleRows as $r) {
            echo implode("\t", $r) . "\r\n";
        }
        exit;
    }

    // .xlxs / .xlsx 使用 SimpleXLSXGen 生成真实的 OpenXML ZIP 格式 Excel 文件
    if ($ext === 'xlxs' || $ext === 'xlsx') {
        $filename = $ext === 'xlxs'
            ? '学生信息导入模板_不可修改表头.xlxs'
            : '学生信息导入模板_不可修改表头.xlsx';
        $xlsx = SimpleXLSXGen::fromArray($sampleRows);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo (string) $xlsx;
        exit;
    }

    // .xls 生成标准 XML Spreadsheet 2003（Excel/WPS 双击直接识别，且另存为二进制 BIFF8 .xls 后由 SimpleXLS 解析）
    if ($ext === 'xls') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="学生信息导入模板_不可修改表头.xls"');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Sheet1"><Table>';
        foreach ($sampleRows as $r) {
            echo '<Row>';
            foreach ($r as $cell) {
                echo '<Cell><Data ss:Type="String">' . htmlspecialchars($cell, ENT_QUOTES, 'UTF-8') . '</Data></Cell>';
            }
            echo '</Row>';
        }
        echo '</Table></Worksheet></Workbook>';
        exit;
    }

    // .dbf 生成真实的 dBASE III 二进制文件（采用国内 FoxPro/学籍系统通用的 GBK 编码写入中文）
    if ($ext === 'dbf') {
        header('Content-Type: application/x-dbf');
        header('Content-Disposition: attachment; filename="学生信息导入模板_不可修改表头.dbf"');
        echo admin_build_sample_dbf($sampleRows);
        exit;
    }
}

/**
 * 构建标准 dBASE III (.dbf) 二进制模板文件（中文采用 GBK 编码以兼容 FoxPro / Excel / WPS）
 */
function admin_build_sample_dbf(array $sampleRows): string
{
    $fields = [
        ['USERNAME', 'C', 24],
        ['REALNAME', 'C', 24],
        ['ROLE',     'C', 16],
        ['IDTYPE',   'C', 24],
        ['IDCARD',   'C', 24],
    ];
    $numFields = count($fields);
    $headerLen = 32 + ($numFields * 32) + 1;
    $recordLen = 1;
    foreach ($fields as $f) {
        $recordLen += $f[2];
    }

    $dataRows = array_slice($sampleRows, 1);
    $numRecords = count($dataRows);

    $header = pack(
        'CCCCVvvx20',
        0x03,
        (int) date('y'),
        (int) date('m'),
        (int) date('d'),
        $numRecords,
        $headerLen,
        $recordLen
    );

    foreach ($fields as $f) {
        $namePad = str_pad($f[0], 11, "\x00");
        $header .= $namePad . $f[1] . pack('V', 0) . chr($f[2]) . chr(0) . str_repeat("\x00", 14);
    }
    $header .= "\x0D";

    $body = '';
    foreach ($dataRows as $r) {
        $body .= "\x20";
        foreach ($fields as $idx => $f) {
            $val = (string) ($r[$idx] ?? '');
            if (function_exists('mb_convert_encoding')) {
                $valGbk = @mb_convert_encoding($val, 'GBK', 'UTF-8');
                if ($valGbk !== false) {
                    $val = $valGbk;
                }
            }
            $body .= str_pad(substr($val, 0, $f[2]), $f[2], ' ');
        }
    }
    $body .= "\x1A";

    return $header . $body;
}

/**
 * 将提取出的二维数组统一标准化为用户结构数组
 */
function admin_normalize_extracted_table(array $rawTable): array
{
    $rows = [];
    foreach ($rawTable as $cells) {
        if (!is_array($cells)) continue;
        $vals = array_values(array_map(function ($v) { return trim((string) $v); }, $cells));
        $u = (string) ($vals[0] ?? '');
        if ($u === '' || $u === '账号' || strtolower($u) === 'username') {
            continue;
        }
        $rows[] = [
            'username'     => $u,
            'real_name'    => (string) ($vals[1] ?? ''),
            'role'         => (string) (($vals[2] ?? '') ?: 'student'),
            'id_card_type' => (string) (($vals[3] ?? '') ?: '居民身份证'),
            'id_card'      => (string) ($vals[4] ?? ''),
        ];
    }
    return $rows;
}

/**
 * 解析上传文件或在线文本中的用户行数据
 *
 * @return array<int, array{username:string, real_name:string, role:string, id_card_type:string, id_card:string}>
 */
function admin_parse_import_rows(string $content, string $ext): array
{
    $ext = strtolower(trim($ext));

    // 1. JSON 格式
    if ($ext === 'json') {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }
        $rows = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) continue;
            $u = trim((string) ($item['username'] ?? $item['账号'] ?? $item[0] ?? ''));
            $n = trim((string) ($item['real_name'] ?? $item['姓名'] ?? $item[1] ?? ''));
            $r = trim((string) ($item['role'] ?? $item['身份组'] ?? $item[2] ?? 'student'));
            $t = trim((string) ($item['id_card_type'] ?? $item['证件类型'] ?? $item[3] ?? '居民身份证'));
            $c = trim((string) ($item['id_card'] ?? $item['证件号码'] ?? $item[4] ?? ''));
            if ($u !== '' && $u !== '账号') {
                $rows[] = ['username' => $u, 'real_name' => $n, 'role' => $r, 'id_card_type' => $t, 'id_card' => $c];
            }
        }
        return $rows;
    }

    // 2. DBF 格式（优先使用开源库 hisamu/php-xbase 的 TableReader 解析，自动转换 GBK -> UTF-8）
    if ($ext === 'dbf') {
        return admin_parse_dbf_content($content);
    }

    // 3. OpenXML (.xlsx / .xlxs) 或以 PK 开头的 ZIP 表格（使用开源库 Shuchkin\SimpleXLSX）
    if ($ext === 'xlsx' || $ext === 'xlxs' || substr($content, 0, 2) === 'PK') {
        if ($xlsx = SimpleXLSX::parseData($content)) {
            return admin_normalize_extracted_table($xlsx->rows());
        }
    }

    // 4. 二进制 BIFF8 OLE2 (.xls, 文件头 \xD0\xCF\x11\xE0，使用开源库 Shuchkin\SimpleXLS)
    if (substr($content, 0, 4) === "\xD0\xCF\x11\xE0") {
        if ($xls = SimpleXLS::parseData($content)) {
            return admin_normalize_extracted_table($xls->rows());
        }
    }

    // 5. XML Spreadsheet 2003 (.xls 模板直接修改保存)
    if (stripos($content, '<Workbook') !== false && stripos($content, '<Row') !== false) {
        $rawTable = [];
        if (preg_match_all('/<Row[^>]*>(.*?)<\/Row>/is', $content, $rMatches)) {
            foreach ($rMatches[1] as $rXml) {
                if (preg_match_all('/<Data[^>]*>(.*?)<\/Data>/is', $rXml, $dMatches)) {
                    $rawTable[] = array_map(function ($v) {
                        return html_entity_decode(strip_tags($v), ENT_QUOTES, 'UTF-8');
                    }, $dMatches[1]);
                }
            }
        }
        return admin_normalize_extracted_table($rawTable);
    }

    // 6. HTML Table 伪 .xls
    if (stripos($content, '<table') !== false && stripos($content, '<tr') !== false) {
        $rawTable = [];
        if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $content, $trMatches)) {
            foreach ($trMatches[1] as $trHtml) {
                if (preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $trHtml, $tdMatches)) {
                    $rawTable[] = array_map(function ($v) {
                        return html_entity_decode(strip_tags($v), ENT_QUOTES, 'UTF-8');
                    }, $tdMatches[1]);
                }
            }
        }
        return admin_normalize_extracted_table($rawTable);
    }

    // 7. CSV / TXT 文本（自动检测 UTF-8 或 Excel 导出的 GBK 编码）
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    if (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
        $content = @mb_convert_encoding($content, 'UTF-8', 'GBK');
    }

    $lines = preg_split('/\r\n|\r|\n/', trim((string) $content));
    $rawTable = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $rawTable[] = strpos($line, "\t") !== false
            ? explode("\t", $line)
            : str_getcsv($line);
    }
    return admin_normalize_extracted_table($rawTable);
}

/**
 * 使用开源库 hisamu/php-xbase (XBase\TableReader) 解析 .dbf 文件
 */
function admin_parse_dbf_content(string $bin): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'dbf_');
    file_put_contents($tmp, $bin);
    $rawTable = [];

    try {
        if (class_exists(TableReader::class)) {
            $table = new TableReader($tmp);
            $colNames = array_keys($table->getColumns());
            while ($record = $table->nextRecord()) {
                if ($record->isDeleted()) continue;
                $vals = [];
                foreach ($colNames as $col) {
                    $v = (string) $record->get($col);
                    if (function_exists('mb_check_encoding') && !mb_check_encoding($v, 'UTF-8')) {
                        $v = (string) @mb_convert_encoding($v, 'UTF-8', 'GBK');
                    }
                    $vals[] = trim($v);
                }
                $rawTable[] = $vals;
            }
            $table->close();
        }
    } catch (Throwable $e) {
    }
    @unlink($tmp);

    return admin_normalize_extracted_table($rawTable);
}

/**
 * 批量写入解析出的用户记录（零明文身份证，自动计算 HMAC 盲索引与 Argon2id 初始密码哈希）
 *
 * @return array{inserted:int, skipped:int}
 */
function admin_import_users_batch(array $rows): array
{
    global $pdo;
    $inserted = 0;
    $skipped  = 0;

    $roleMap = [
        '学生'   => 'student',
        '教师'   => 'teacher',
        '老师'   => 'teacher',
        '领导'   => 'leader',
        '管理员' => 'admin',
        '校友'   => 'alumni',
    ];
    $validRoles = ['admin', 'leader', 'teacher', 'student', 'alumni'];

    $stmt = $pdo->prepare("
        INSERT INTO `users` (`username`, `real_name`, `id_card_type`, `id_card_hash`, `id_card_mask`, `password_hash`, `initial_password_hash`, `role`, `status`, `is_initial_pwd`)
        VALUES (:u, :n, :t, :ih, :im, :ph, :iph, :r, 0, 1)
        ON DUPLICATE KEY UPDATE
            `real_name`             = VALUES(`real_name`),
            `id_card_type`          = VALUES(`id_card_type`),
            `id_card_hash`          = VALUES(`id_card_hash`),
            `id_card_mask`          = VALUES(`id_card_mask`),
            `initial_password_hash` = VALUES(`initial_password_hash`),
            `role`                  = VALUES(`role`)
    ");

    foreach ($rows as $r) {
        $u  = trim((string) ($r['username'] ?? ''));
        $n  = trim((string) ($r['real_name'] ?? ''));
        $ro = trim((string) ($r['role'] ?? 'student'));
        $t  = trim((string) (($r['id_card_type'] ?? '') ?: '居民身份证'));
        $id = trim((string) ($r['id_card'] ?? ''));

        if ($u === '' || $n === '' || strlen($id) < 6) {
            $skipped++;
            continue;
        }

        if (isset($roleMap[$ro])) {
            $ro = $roleMap[$ro];
        }
        if (!in_array($ro, $validRoles, true)) {
            $ro = 'student';
        }

        $idHash  = auth_hash_id_card($id);
        $idMask  = auth_mask_id_card($id);
        $pwdHash = auth_initial_password_hash_from_id_card($id);

        try {
            $stmt->execute([
                ':u'   => $u,
                ':n'   => $n,
                ':t'   => $t,
                ':ih'  => $idHash,
                ':im'  => $idMask,
                ':ph'  => $pwdHash,
                ':iph' => $pwdHash,
                ':r'   => $ro,
            ]);
            $inserted++;
        } catch (Throwable $e) {
            $skipped++;
        }
    }

    return ['inserted' => $inserted, 'skipped' => $skipped];
}
