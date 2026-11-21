<?php
/**
 * 网站流量统计模块（内置防穿帮逻辑校验与平滑曲线算法）
 *
 * 防穿帮铁律：
 * 1. 任何时刻必须满足：总访问量 > 今日访问(PV) >= 今日独立访客(UV) >= 当前在线人数 >= 1
 * 2. 跨入新的一天（如 00:01）时，自动包含过去半小时余留在线及当日已过分钟数的平滑增量，绝不出现“在线40人但今日访问只有1次”的穿帮。
 * 3. 在线人数按每2分钟时间片在当前小时区间内平滑浮动，防止连续按 F5 刷新时在线人数忽大忽小剧烈乱跳穿帮。
 */

require_once __DIR__ . '/pdo.php';

/**
 * 默认 24 小时（0点~23点）每小时自动增加访问量区间
 */
function traffic_default_hourly_pv(): array
{
    return [
        0 => '8-15',  1 => '4-9',   2 => '2-6',   3 => '1-4',   4 => '2-5',   5 => '5-12',
        6 => '15-28', 7 => '30-55', 8 => '55-85', 9 => '80-120', 10 => '90-135', 11 => '70-105',
        12 => '45-70', 13 => '50-80', 14 => '80-120', 15 => '85-130', 16 => '75-110', 17 => '55-85',
        18 => '35-60', 19 => '40-68', 20 => '45-75', 21 => '35-60', 22 => '18-35', 23 => '10-22',
    ];
}

/**
 * 默认 24 小时（0点~23点）每小时在线浏览人数随机区间
 */
function traffic_default_hourly_online(): array
{
    return [
        0 => '2-5',   1 => '1-4',   2 => '1-3',   3 => '1-2',   4 => '1-3',   5 => '2-5',
        6 => '5-10',  7 => '12-22', 8 => '25-42', 9 => '38-62', 10 => '45-72', 11 => '35-55',
        12 => '22-38', 13 => '25-42', 14 => '40-65', 15 => '45-70', 16 => '38-60', 17 => '28-48',
        18 => '18-32', 19 => '20-35', 20 => '22-38', 21 => '15-28', 22 => '8-15',  23 => '4-8',
    ];
}

/**
 * 解析形如 "1-6" 或 "5" 的区间字符串并生成整数
 * 若传入固定种子 $seedKey，则在同一时间片内返回稳定的伪随机值（防止狂按 F5 时数字剧烈乱跳穿帮）
 */
function traffic_eval_range_value(string $expr, ?string $seedKey = null): int
{
    $expr = trim($expr);
    if (preg_match('/^(\d+)\s*[\-~～至]\s*(\d+)$/u', $expr, $m)) {
        $min = (int) $m[1];
        $max = (int) $m[2];
        if ($min > $max) {
            $tmp = $min;
            $min = $max;
            $max = $tmp;
        }
        if ($min === $max) {
            return $min;
        }
        if ($seedKey !== null) {
            $hash = abs(crc32($seedKey));
            return $min + ($hash % ($max - $min + 1));
        }
        return random_int($min, $max);
    }
    return max(0, (int) $expr);
}

/**
 * 确保流量统计表及配置项存在
 */
function traffic_ensure_tables(): void
{
    global $pdo;
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `site_traffic_daily` (
          `stat_date` date NOT NULL COMMENT '统计日期',
          `pv` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日页面浏览量(PV)',
          `uv` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日独立IP访客数(UV)',
          PRIMARY KEY (`stat_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='每日流量统计表';
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `site_visit_logs` (
          `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增ID',
          `visit_date` date NOT NULL COMMENT '访问日期',
          `ip_address` varchar(45) NOT NULL COMMENT '访客IP',
          `page_uri` varchar(255) NOT NULL DEFAULT '/' COMMENT '受访页面路径',
          `user_agent` varchar(255) DEFAULT NULL COMMENT '浏览器标识',
          `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '访问时间',
          PRIMARY KEY (`id`),
          KEY `idx_visit_date_ip` (`visit_date`, `ip_address`),
          KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='网站访问明细表';
    ");

    $ins = $pdo->prepare("INSERT IGNORE INTO `config` (`id`, `name`, `url`, `description`, `introduction`) VALUES (?, ?, NULL, ?, ?)");
    $ins->execute([14, 'traffic_base_pv', '128560', '网站总访问量初始基数']);
    $ins->execute([15, 'traffic_hourly_pv', json_encode(traffic_default_hourly_pv()), '24小时每小时自动增加访问量区间JSON']);
    $ins->execute([16, 'traffic_hourly_online', json_encode(traffic_default_hourly_online()), '24小时每小时在线浏览人数区间JSON']);
    $ins->execute([17, 'traffic_last_settle_hour', date('Y-m-d H'), '上次自动结算整点流量的时间(Y-m-d H)']);
}

/**
 * 读取 24 小时每小时自动增长 PV 区间数组 (0..23)
 */
function traffic_get_hourly_pv_config(): array
{
    global $pdo;
    traffic_ensure_tables();
    $defaults = traffic_default_hourly_pv();
    $json = (string) $pdo->query("SELECT `description` FROM `config` WHERE `name` = 'traffic_hourly_pv' LIMIT 1")->fetchColumn();
    $arr = json_decode($json, true);
    if (!is_array($arr)) {
        return $defaults;
    }
    $out = [];
    for ($h = 0; $h < 24; $h++) {
        $val = isset($arr[$h]) && !is_array($arr[$h]) ? trim((string) $arr[$h]) : '';
        $out[$h] = ($val !== '' && (strpos($val, '-') !== false || is_numeric($val))) ? $val : (string) $defaults[$h];
    }
    return $out;
}

/**
 * 读取 24 小时每小时在线浏览人数区间数组 (0..23)
 */
function traffic_get_hourly_online_config(): array
{
    global $pdo;
    traffic_ensure_tables();
    $defaults = traffic_default_hourly_online();
    $json = (string) $pdo->query("SELECT `description` FROM `config` WHERE `name` = 'traffic_hourly_online' LIMIT 1")->fetchColumn();
    $arr = json_decode($json, true);
    if (!is_array($arr) || isset($arr['min'])) {
        return $defaults;
    }
    $out = [];
    for ($h = 0; $h < 24; $h++) {
        $val = isset($arr[$h]) && !is_array($arr[$h]) ? trim((string) $arr[$h]) : '';
        $out[$h] = ($val !== '' && (strpos($val, '-') !== false || is_numeric($val))) ? $val : (string) $defaults[$h];
    }
    return $out;
}

/**
 * 结算自上次记录整点以来经过的每个小时的自动增长流量
 */
function traffic_settle_elapsed_hours(): void
{
    global $pdo;
    traffic_ensure_tables();

    $lastHourStr = (string) $pdo->query("SELECT `description` FROM `config` WHERE `name` = 'traffic_last_settle_hour' LIMIT 1")->fetchColumn();
    $currentHourTs = strtotime(date('Y-m-d H:00:00'));
    $lastHourTs    = $lastHourStr !== '' ? strtotime($lastHourStr . ':00:00') : $currentHourTs;

    if ($lastHourTs === false || $lastHourTs >= $currentHourTs) {
        return;
    }

    if ($currentHourTs - $lastHourTs > 168 * 3600) {
        $lastHourTs = $currentHourTs - 24 * 3600;
    }

    $hourlyPv = traffic_get_hourly_pv_config();
    $upsert = $pdo->prepare("
        INSERT INTO `site_traffic_daily` (`stat_date`, `pv`, `uv`)
        VALUES (:d, :pv, :uv)
        ON DUPLICATE KEY UPDATE `pv` = `pv` + VALUES(`pv`), `uv` = `uv` + VALUES(`uv`)
    ");

    for ($ts = $lastHourTs + 3600; $ts <= $currentHourTs; $ts += 3600) {
        $d = date('Y-m-d', $ts - 3600);
        $h = (int) date('G', $ts - 3600);
        $addPv = traffic_eval_range_value((string) ($hourlyPv[$h] ?? '5-15'), "settle_{$d}_{$h}");
        if ($addPv > 0) {
            $addUv = max(1, (int) round($addPv * 0.58));
            $upsert->execute([':d' => $d, ':pv' => $addPv, ':uv' => $addUv]);
        }
    }

    $pdo->prepare("UPDATE `config` SET `description` = :v WHERE `name` = 'traffic_last_settle_hour'")
        ->execute([':v' => date('Y-m-d H', $currentHourTs)]);
}

/**
 * 记录一次页面访问并返回最新统计数据
 */
function traffic_record_and_get_stats(string $pageUri = '/index.php'): array
{
    global $pdo;

    try {
        traffic_ensure_tables();
        traffic_settle_elapsed_hours();

        $today = date('Y-m-d');
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '127.0.0.1';
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 250, 'UTF-8') : '';

        $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM `site_visit_logs` WHERE `visit_date` = :d AND `ip_address` = :ip");
        $chkStmt->execute([':d' => $today, ':ip' => $ip]);
        $isNewUvToday = ((int) $chkStmt->fetchColumn() === 0) ? 1 : 0;

        $insLog = $pdo->prepare("INSERT INTO `site_visit_logs` (`visit_date`, `ip_address`, `page_uri`, `user_agent`) VALUES (:d, :ip, :uri, :ua)");
        $insLog->execute([':d' => $today, ':ip' => $ip, ':uri' => mb_substr($pageUri, 0, 250, 'UTF-8'), ':ua' => $ua]);

        $upsert = $pdo->prepare("
            INSERT INTO `site_traffic_daily` (`stat_date`, `pv`, `uv`)
            VALUES (:d, 1, 1)
            ON DUPLICATE KEY UPDATE `pv` = `pv` + 1, `uv` = `uv` + :new_uv
        ");
        $upsert->execute([':d' => $today, ':new_uv' => $isNewUvToday]);

        return traffic_get_summary();
    } catch (Throwable $e) {
        return [
            'today_pv'     => 18,
            'today_uv'     => 9,
            'yesterday_pv' => 860,
            'total_pv'     => 128578,
            'online_count' => 3,
        ];
    }
}

/**
 * 读取统计摘要（带严密防穿帮约束：今日PV >= 今日UV >= 当前在线 >= 1）
 */
function traffic_get_summary(): array
{
    global $pdo;
    traffic_ensure_tables();
    traffic_settle_elapsed_hours();

    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $currHour  = (int) date('G');
    $currMin   = (int) date('i');

    $hourlyPv     = traffic_get_hourly_pv_config();
    $hourlyOnline = traffic_get_hourly_online_config();

    // 1. 计算当前小时对应的在线人数（按每2分钟一个种子，保证刷新页面不会忽高忽低穿帮）
    $currOnlineRangeExpr = (string) ($hourlyOnline[$currHour] ?? '2-5');
    $twoMinSlot = (int) floor($currMin / 2);
    $onlineCount = max(1, traffic_eval_range_value($currOnlineRangeExpr, "online_{$today}_{$currHour}_{$twoMinSlot}"));

    // 2. 计算当前小时内已过分钟数的平滑虚拟增量
    $currHourFullPv = traffic_eval_range_value((string) ($hourlyPv[$currHour] ?? '8-15'), "pv_{$today}_{$currHour}");
    $partialPv      = (int) floor($currHourFullPv * ($currMin / 60));
    $partialUv      = max(1, (int) round($partialPv * 0.58));

    $stmt = $pdo->prepare("SELECT `pv`, `uv` FROM `site_traffic_daily` WHERE `stat_date` = :d");
    $stmt->execute([':d' => $today]);
    $tRow = $stmt->fetch();
    $dbTodayPv = (int) ($tRow['pv'] ?? 0);
    $dbTodayUv = (int) ($tRow['uv'] ?? 0);

    $todayPv = $dbTodayPv + $partialPv;
    $todayUv = $dbTodayUv + $partialUv;

    // 3. 防穿帮硬约束：
    // (a) 今日独立访客(UV) 必须 >= 当前在线人数（否则今天才来1个人却有40人在线就穿帮了！）
    if ($todayUv < $onlineCount) {
        $todayUv = $onlineCount + max(1, (int) floor($currMin / 15));
    }
    // (b) 今日访问量(PV) 必须 >= 今日独立访客(UV) * 1.4（每个人至少浏览1~2页）
    $minLogicalPv = $todayUv + max(2, (int) round($todayUv * 0.45));
    if ($todayPv < $minLogicalPv) {
        $todayPv = $minLogicalPv;
    }

    $stmt->execute([':d' => $yesterday]);
    $yRow = $stmt->fetch();
    $yesterdayPv = (int) ($yRow['pv'] ?? 0);

    $sumOtherDaysPv = (int) $pdo->query("SELECT COALESCE(SUM(`pv`), 0) FROM `site_traffic_daily` WHERE `stat_date` < '{$today}'")->fetchColumn();
    $realSumPv      = $sumOtherDaysPv + $todayPv;
    $basePv         = (int) $pdo->query("SELECT COALESCE(`description`, '128560') FROM `config` WHERE `name` = 'traffic_base_pv' LIMIT 1")->fetchColumn();

    return [
        'today_pv'          => $todayPv,
        'today_uv'          => $todayUv,
        'yesterday_pv'      => $yesterdayPv,
        'total_pv'          => $basePv + $realSumPv,
        'base_pv'           => $basePv,
        'real_sum_pv'       => $realSumPv,
        'online_count'      => $onlineCount,
        'curr_online_range' => $currOnlineRangeExpr,
    ];
}