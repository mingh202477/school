<?php
/**
 * 统一身份认证核心模型（MySQL + Argon2id + 身份证 HMAC-SHA256 盲索引）
 *
 * 安全设计：
 * 1. 密码存储采用 PASSWORD_ARGON2ID（初始密码为身份证后6位，X大写）。
 * 2. 身份证号零明文落库：使用环境变量 SCHOOL_AUTH_HMAC_KEY 计算 HMAC-SHA256 盲索引（id_card_hash），
 *    仅保存不可逆脱敏串（id_card_mask，如 3705************1X）。
 * 3. 登录审计日志自动对输入的身份证号脱敏，防止日志表泄露个人敏感信息。
 * 4. 防暴力破解：结合数据库 auth_login_logs 按 IP 与账号统计 15 分钟内失败次数，防止清空 Cookie 绕过验证码。
 */

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/pdo.php';

const AUTH_CAPTCHA_LENGTH = 5;
const AUTH_LOCKOUT_THRESHOLD = 5;      // 15分钟内连续失败5次临时锁定
const AUTH_LOCKOUT_WINDOW_SEC = 900;   // 统计窗口：15分钟（900秒）

/**
 * 获取环境变量中的 HMAC 密钥（绝不在代码中硬编码密钥）
 */
function auth_get_hmac_key(): string
{
    $key = app_env('SCHOOL_AUTH_HMAC_KEY');
    if ($key === null || strlen($key) < 32) {
        throw new RuntimeException('安全配置缺失：未检测到有效的环境变量 SCHOOL_AUTH_HMAC_KEY');
    }
    return $key;
}

/**
 * 判断输入字符串是否为 15/18 位身份证号格式
 */
function auth_is_id_card_format(string $input): bool
{
    $normalized = trim($input);
    return (bool) preg_match('/^(?:\d{15}|\d{17}[\dX])$/', $normalized);
}

/**
 * 计算身份证号的 HMAC-SHA256 盲索引（64位十六进制）
 */
function auth_hash_id_card(string $idCard): string
{
    $normalized = trim($idCard);
    return hash_hmac('sha256', $normalized, auth_get_hmac_key());
}

/**
 * 生成身份证号不可逆脱敏展示串（仅保留前4位和后2位，中间12位为 *）
 */
function auth_mask_id_card(string $idCard): string
{
    $normalized = trim($idCard);
    if (strlen($normalized) < 8) {
        return '******';
    }
    return substr($normalized, 0, 4) . str_repeat('*', max(4, strlen($normalized) - 6)) . substr($normalized, -2);
}

/**
 * 根据身份证号截取后6位（X大写）并生成 Argon2id 初始密码哈希
 */
function auth_initial_password_hash_from_id_card(string $idCard): string
{
    $normalized = trim($idCard);
    $last6 = substr($normalized, -6);
    return password_hash($last6, PASSWORD_ARGON2ID);
}

/**
 * 对写入日志的登录账号输入进行脱敏（防止用户在账号框输入身份证号或长串敏感数字被明文记入日志）
 */
function auth_sanitize_account_for_log(string $input): string
{
    $trimmed = trim($input);
    if (auth_is_id_card_format($trimmed)) {
        return auth_mask_id_card($trimmed);
    }
    // 若误输入超过 12 位连续数字（如把身份证输错一位），同样强制脱敏
    if (preg_match('/^\d{12,}$/', $trimmed)) {
        return substr($trimmed, 0, 4) . '********' . substr($trimmed, -2);
    }
    return mb_substr($trimmed, 0, 120, 'UTF-8');
}

/**
 * 获取客户端 IP
 */
function auth_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * 判断当前会话是否已登录
 */
function auth_is_logged_in(): bool
{
    return !empty($_SESSION['auth_user_id']) && !empty($_SESSION['auth_user']);
}

/**
 * 要求必须登录，否则重定向至统一身份认证页
 */
function auth_is_admin(): bool
{
    return auth_is_logged_in() && (($_SESSION['auth_role'] ?? '') === 'admin');
}

function auth_is_leader_or_admin(): bool
{
    return auth_is_logged_in() && in_array(($_SESSION['auth_role'] ?? ''), ['leader', 'admin'], true);
}

function auth_redirect_target_by_role(?string $role = null): string
{
    $r = $role ?? (string) ($_SESSION['auth_role'] ?? '');
    if ($r === 'admin') {
        return '../admin/dashboard.php';
    }
    if ($r === 'leader') {
        return '../leader/dashboard.php';
    }
    return '../index.php';
}

function auth_require_leader_or_admin(): void
{
    if (!auth_is_logged_in()) {
        header('Location: ../auth/login.php');
        exit;
    }
    if (!auth_is_leader_or_admin()) {
        header('Location: ../index.php');
        exit;
    }
}

function auth_require_login(): void
{
    if (!auth_is_logged_in()) {
        header('Location: ../auth/login.php');
        exit;
    }
}

function auth_require_admin(): void
{
    if (!auth_is_logged_in()) {
        header('Location: ../auth/login.php');
        exit;
    }
    if (!auth_is_admin()) {
        header('Location: ../index.php');
        exit;
    }
}

/**
 * 统计当前 IP 或输入账号在过去 15 分钟内的连续登录失败次数
 */
function auth_recent_failure_count(string $accountInput = ''): int
{
    global $pdo;
    $sessionFailures = (int) ($_SESSION['login_failures'] ?? 0);

    try {
        $ip = auth_client_ip();
        $windowStart = date('Y-m-d H:i:s', time() - AUTH_LOCKOUT_WINDOW_SEC);

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM `auth_login_logs`
            WHERE `ip_address` = :ip
              AND `is_success` = 0
              AND `created_at` >= :win
        ");
        $stmt->execute([':ip' => $ip, ':win' => $windowStart]);
        $ipFailures = (int) $stmt->fetchColumn();

        $accFailures = 0;
        if ($accountInput !== '') {
            $safeAcc = auth_sanitize_account_for_log($accountInput);
            $stmtAcc = $pdo->prepare("
                SELECT COUNT(*) FROM `auth_login_logs`
                WHERE `account_input` = :acc
                  AND `is_success` = 0
                  AND `created_at` >= :win
            ");
            $stmtAcc->execute([':acc' => $safeAcc, ':win' => $windowStart]);
            $accFailures = (int) $stmtAcc->fetchColumn();
        }

        return max($sessionFailures, $ipFailures, $accFailures);
    } catch (Throwable $e) {
        return $sessionFailures;
    }
}

/**
 * 是否需要展示并校验图片验证码（失败 >= 1 次即强制要求）
 */
function auth_requires_captcha(string $accountInput = ''): bool
{
    return auth_recent_failure_count($accountInput) >= 1;
}

/**
 * 是否因高频失败触发临时防爆破锁定（15分钟内失败 >= 5 次）
 */
function auth_is_locked_out(string $accountInput = ''): bool
{
    return auth_recent_failure_count($accountInput) >= AUTH_LOCKOUT_THRESHOLD;
}

/**
 * 记录登录审计日志（自动对身份证号脱敏）
 */
function auth_write_login_log(?int $userId, string $rawAccountInput, string $loginType, bool $isSuccess, ?string $failReason = null): void
{
    global $pdo;
    try {
        $safeAccount = auth_sanitize_account_for_log($rawAccountInput);
        $ip = auth_client_ip();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 250, 'UTF-8') : null;

        $stmt = $pdo->prepare("
            INSERT INTO `auth_login_logs` (`user_id`, `account_input`, `login_type`, `ip_address`, `user_agent`, `is_success`, `fail_reason`)
            VALUES (:uid, :acc, :type, :ip, :ua, :succ, :reason)
        ");
        $stmt->execute([
            ':uid'    => $userId,
            ':acc'    => $safeAccount,
            ':type'   => $loginType,
            ':ip'     => $ip,
            ':ua'     => $ua,
            ':succ'   => $isSuccess ? 1 : 0,
            ':reason' => $failReason,
        ]);
    } catch (Throwable $e) {
        // 日志写入异常不中断主流程
    }
}

/**
 * 记录一次会话失败并失效当前验证码
 */
function auth_record_failure(string $accountInput = '', ?int $userId = null, string $reason = 'invalid_credentials'): void
{
    $_SESSION['login_failures'] = (int) ($_SESSION['login_failures'] ?? 0) + 1;
    unset($_SESSION['captcha_code'], $_SESSION['captcha_created_at']);
    if ($accountInput !== '') {
        auth_write_login_log($userId, $accountInput, 'password', false, $reason);
    }
}

/**
 * 登录成功后重置失败计数并更新最后登录信息
 */
function auth_reset_failures(?int $userId = null): void
{
    global $pdo;
    unset($_SESSION['login_failures'], $_SESSION['captcha_code'], $_SESSION['captcha_created_at']);

    if ($userId !== null) {
        try {
            $ip = auth_client_ip();
            $stmt = $pdo->prepare("
                UPDATE `users`
                SET `last_login_at` = NOW(), `last_login_ip` = :ip
                WHERE `id` = :id
            ");
            $stmt->execute([':ip' => $ip, ':id' => $userId]);
        } catch (Throwable $e) {
        }
    }
}

/**
 * 通过用户名 / 邮箱 / 身份证号（HMAC-SHA256 盲索引）查找用户
 */
function auth_find_user_by_identifier(string $identifier): ?array
{
    global $pdo;
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    // 若输入符合身份证格式，计算 HMAC-SHA256 盲索引按 id_card_hash 精确检索
    if (auth_is_id_card_format($identifier)) {
        $idHash = auth_hash_id_card($identifier);
        $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `id_card_hash` = :hash LIMIT 1");
        $stmt->execute([':hash' => $idHash]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }

    // 否则按用户名（学号/工号）或绑定邮箱检索
    $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `username` = :u OR `email` = :e LIMIT 1");
    $stmt->execute([':u' => $identifier, ':e' => $identifier]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * 执行账号密码认证
 *
 * @return array{ok: bool, error: string, user: ?array}
 */
function auth_attempt_password_login(string $identifier, string $password): array
{
    if ($identifier === '' || $password === '') {
        return ['ok' => false, 'error' => '请输入账号和密码。', 'user' => null];
    }

    if (auth_is_locked_out($identifier)) {
        return ['ok' => false, 'error' => '登录失败次数过多，账号或当前网络已临时保护锁定 15 分钟，请稍后再试。', 'user' => null];
    }

    $user = auth_find_user_by_identifier($identifier);
    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        auth_record_failure($identifier, $user ? (int) $user['id'] : null, 'wrong_password');
        return ['ok' => false, 'error' => '账号或密码不正确。请完成验证码后再次登录。', 'user' => null];
    }

    $status = (int) $user['status'];
    if ($status === 0) {
        auth_record_failure($identifier, (int) $user['id'], 'not_activated');
        return ['ok' => false, 'error' => '该账号尚未激活，请点击下方「新生账号激活」完成首次激活。', 'user' => null];
    }
    if ($status === 2) {
        auth_record_failure($identifier, (int) $user['id'], 'disabled');
        return ['ok' => false, 'error' => '该账号已被停用，请联系学校信息化处处理。', 'user' => null];
    }

    // 登录成功
    auth_write_login_log((int) $user['id'], $identifier, 'password', true, null);
    auth_reset_failures((int) $user['id']);

    return ['ok' => true, 'error' => '', 'user' => $user];
}

function auth_generate_captcha(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';

    for ($i = 0; $i < AUTH_CAPTCHA_LENGTH; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    $_SESSION['captcha_code'] = $code;
    $_SESSION['captcha_created_at'] = time();

    return $code;
}

function auth_verify_captcha(string $input): bool
{
    $code = $_SESSION['captcha_code'] ?? null;
    $createdAt = (int) ($_SESSION['captcha_created_at'] ?? 0);

    // 验证码单次有效，且最多保留 5 分钟。
    unset($_SESSION['captcha_code'], $_SESSION['captcha_created_at']);

    if (!is_string($code) || $createdAt < time() - 300) {
        return false;
    }

    return hash_equals($code, strtoupper(trim($input)));
}

/**
 * 校验账号激活第 2 步：信息校验（账号、姓名、证件类型、证件号码，严格区分大小写 X）
 *
 * @return array{ok: bool, error: string, user: ?array}
 */
function auth_verify_activation_step2(string $username, string $realName, string $idCardType, string $idCardNumber): array
{
    global $pdo;

    $username     = trim($username);
    $realName     = trim($realName);
    $idCardType   = trim($idCardType);
    $idCardNumber = trim($idCardNumber);

    if ($username === '' || $realName === '' || $idCardType === '' || $idCardNumber === '') {
        return ['ok' => false, 'error' => '请完整填写所有必填项（账号、姓名、证件类型、证件号码）。', 'user' => null];
    }

    $validTypes = ['居民身份证', '港澳台居民居住证', '护照', '军官证/士兵证', '其他有效证件'];
    if (!in_array($idCardType, $validTypes, true)) {
        return ['ok' => false, 'error' => '请选择有效的证件类型。', 'user' => null];
    }

    // 居民身份证校验：末尾是 X 的必须大写（严格区分大小写）
    if ($idCardType === '居民身份证') {
        if (substr($idCardNumber, -1) === 'x') {
            return ['ok' => false, 'error' => '身份证号码末尾是X的需要大写（区分大小写），请使用大写“X”。', 'user' => null];
        }
        if (!preg_match('/^(?:\d{15}|\d{17}[\dX])$/', $idCardNumber)) {
            return ['ok' => false, 'error' => '请输入正确的 18 位（或 15 位）居民身份证号码。', 'user' => null];
        }
    }

    // 严格按原大小写计算 HMAC-SHA256 盲索引（不将小写 x 自动转大写）
    $idHashStrict = hash_hmac('sha256', $idCardNumber, auth_get_hmac_key());

    $stmt = $pdo->prepare("
        SELECT * FROM `users`
        WHERE `username` = :u
        LIMIT 1
    ");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (
        !$user
        || (string) ($user['real_name'] ?? '') !== $realName
        || (string) ($user['id_card_type'] ?? '居民身份证') !== $idCardType
        || !hash_equals((string) ($user['id_card_hash'] ?? ''), $idHashStrict)
    ) {
        auth_record_failure($username, $user ? (int) $user['id'] : null, 'activate_verify_failed');
        return ['ok' => false, 'error' => '身份信息校验未通过，请核对账号、姓名、证件类型及证件号码是否准确。', 'user' => null];
    }

    if ((int) $user['status'] === 1) {
        return ['ok' => false, 'error' => '该账号已完成激活，请直接前往登录页面登录。', 'user' => null];
    }
    if ((int) $user['status'] === 2) {
        return ['ok' => false, 'error' => '该账号已被禁用，请联系系统管理员。', 'user' => null];
    }

    return ['ok' => true, 'error' => '', 'user' => $user];
}

/**
 * 完成账号激活（更新绑定邮箱、密保问题与答案哈希、重置默认密码为证件号后6位并置 status = 1）
 *
 * @return array{ok: bool, error: string}
 */
function auth_complete_activation(int $userId, string $email, string $securityQuestion, string $securityAnswer, string $rawIdCardNumber): array
{
    global $pdo;

    $email            = trim($email);
    $securityQuestion = trim($securityQuestion);
    $securityAnswer   = trim($securityAnswer);
    $rawIdCardNumber  = trim($rawIdCardNumber);

    if ($userId <= 0 || $email === '' || $securityQuestion === '' || $securityAnswer === '') {
        return ['ok' => false, 'error' => '激活会话数据不完整，请重新按步骤操作。'];
    }

    // 检查邮箱是否已被其他账号绑定
    $chk = $pdo->prepare("SELECT `id` FROM `users` WHERE `email` = :e AND `id` != :id LIMIT 1");
    $chk->execute([':e' => $email, ':id' => $userId]);
    if ($chk->fetch()) {
        return ['ok' => false, 'error' => '该电子邮箱已被其他账号绑定，请更换邮箱。'];
    }

    $answerHash = password_hash($securityAnswer, PASSWORD_ARGON2ID);
    $last6      = substr($rawIdCardNumber, -6); // 严格保留原大小写（大写 X）
    $pwdHash    = password_hash($last6, PASSWORD_ARGON2ID);

    $upd = $pdo->prepare("
        UPDATE `users`
        SET `email` = :email,
            `password_hash` = :pwd,
            `security_question` = :sq,
            `security_answer_hash` = :sa,
            `status` = 1,
            `is_initial_pwd` = 1
        WHERE `id` = :id AND `status` = 0
    ");
    $upd->execute([
        ':email' => $email,
        ':pwd'   => $pwdHash,
        ':sq'    => $securityQuestion,
        ':sa'    => $answerHash,
        ':id'    => $userId,
    ]);

    return ['ok' => true, 'error' => ''];
}

/**
 * 读取站点配置中的认证页底部信息与安全警告语配置
 */
function auth_get_site_footer_config(): array
{
    global $pdo;
    $defaults = [
        'logo_1'           => 'public/images/logo1.png',
        'logo_2'           => 'public/images/logo2.png',
        'ico'              => 'dyxz.ico',
        'footer_email'     => '----@---.cn',
        'footer_phone'     => '--------（可能的费用由运营商收取）',
        'security_warning' => '非涉密平台，严禁处理涉密信息！',
        'security_color'   => '#cc0000',
        'footer_copyright' => 'Copyright © 2026 东营行知实验学校 版权所有 | 建议使用 1024*768 分辨率浏览',
    ];
    try {
        $rows = $pdo->query("SELECT `name`, `url`, `description` FROM `config`")->fetchAll();
        foreach ($rows as $r) {
            $name = (string) $r['name'];
            if (in_array($name, ['logo_1', 'logo_2', 'ico'], true) && !empty($r['url'])) {
                $defaults[$name] = (string) $r['url'];
            } elseif (isset($defaults[$name]) && $r['description'] !== '') {
                $defaults[$name] = (string) $r['description'];
            }
        }
    } catch (Throwable $e) {
    }
    return $defaults;
}

/**
 * 邮箱验证码登录认证
 *
 * @return array{ok: bool, error: string, user: ?array}
 */
function auth_attempt_email_code_login(string $email, string $code): array
{
    global $pdo;

    $email = trim($email);
    $code  = trim($code);

    if ($email === '') {
        return ['ok' => false, 'error' => '请输入绑定的邮箱地址或账号。', 'user' => null];
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        return ['ok' => false, 'error' => '请输入 6 位数字邮箱验证码。', 'user' => null];
    }
    if (auth_is_locked_out($email)) {
        return ['ok' => false, 'error' => '尝试次数过多，已临时锁定 15 分钟，请稍后再试。', 'user' => null];
    }

    $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `email` = :e OR `username` = :u LIMIT 1");
    $stmt->execute([':e' => $email, ':u' => $email]);
    $user = $stmt->fetch();
    if ($user && !empty($user['email'])) {
        $email = (string) $user['email'];
    }

    if (!$user) {
        auth_write_login_log(null, $email, 'email_code', false, 'email_not_found');
        return ['ok' => false, 'error' => '该邮箱未绑定任何账号，请先完成账号激活。', 'user' => null];
    }

    if ((int) $user['status'] === 0) {
        return ['ok' => false, 'error' => '该账号尚未激活，请先进行新生账号激活。', 'user' => null];
    }
    if ((int) $user['status'] === 2) {
        return ['ok' => false, 'error' => '该账号已被停用，请联系系统管理员。', 'user' => null];
    }

    if (!auth_verify_email_code($email, $code, 'login', true)) {
        $_SESSION['login_failures'] = (int) ($_SESSION['login_failures'] ?? 0) + 1;
        auth_write_login_log((int) $user['id'], $email, 'email_code', false, 'invalid_email_code');
        return ['ok' => false, 'error' => '邮箱验证码不正确或已过期，请重新获取。', 'user' => null];
    }

    auth_write_login_log((int) $user['id'], $email, 'email_code', true, null);
    auth_reset_failures((int) $user['id']);

    return ['ok' => true, 'error' => '', 'user' => $user];
}

/**
 * 通过邮箱验证码重置密码
 */
function auth_reset_password_by_email(string $email, string $emailCode, string $newPassword): array
{
    global $pdo;

    $email = trim($email);
    $emailCode = trim($emailCode);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => '请输入有效的绑定邮箱地址。'];
    }
    if (strlen($newPassword) < 6) {
        return ['ok' => false, 'error' => '新密码长度不能少于 6 位。'];
    }

    $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `email` = :e LIMIT 1");
    $stmt->execute([':e' => $email]);
    $user = $stmt->fetch();
    if (!$user) {
        return ['ok' => false, 'error' => '未找到绑定该邮箱的账号。'];
    }

    if (!auth_verify_email_code($email, $emailCode, 'reset_pwd', true)) {
        return ['ok' => false, 'error' => '邮箱验证码不正确或已过期。'];
    }

    $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
    $pdo->prepare("UPDATE `users` SET `password_hash` = :ph, `is_initial_pwd` = 0 WHERE `id` = :id")
        ->execute([':ph' => $newHash, ':id' => (int) $user['id']]);

    auth_reset_failures((int) $user['id']);
    return ['ok' => true, 'error' => ''];
}

/**
 * 通过密保问题答案重置密码
 */
function auth_reset_password_by_security(string $identifier, string $answer, string $newPassword): array
{
    global $pdo;

    $identifier = trim($identifier);
    $answer     = trim($answer);

    if ($identifier === '' || $answer === '') {
        return ['ok' => false, 'error' => '请填写账号和密保答案。'];
    }
    if (strlen($newPassword) < 6) {
        return ['ok' => false, 'error' => '新密码长度不能少于 6 位。'];
    }

    $user = auth_find_user_by_identifier($identifier);
    if (!$user || empty($user['security_answer_hash'])) {
        return ['ok' => false, 'error' => '该账号未设置密保问题或账号不存在，请使用邮箱验证码找回或联系管理员重置。'];
    }

    if (!password_verify($answer, (string) $user['security_answer_hash'])) {
        auth_record_failure($identifier, (int) $user['id'], 'wrong_security_answer');
        return ['ok' => false, 'error' => '密保问题答案不正确。'];
    }

    $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
    $pdo->prepare("UPDATE `users` SET `password_hash` = :ph, `is_initial_pwd` = 0 WHERE `id` = :id")
        ->execute([':ph' => $newHash, ':id' => (int) $user['id']]);

    auth_reset_failures((int) $user['id']);
    return ['ok' => true, 'error' => ''];
}

/**
 * 通过原密码修改新密码（首次登录改密或日常改密）
 */
function auth_change_password_with_old(string $identifier, string $oldPassword, string $newPassword): array
{
    global $pdo;

    $identifier = trim($identifier);
    if ($identifier === '' || $oldPassword === '' || $newPassword === '') {
        return ['ok' => false, 'error' => '请完整填写账号、原密码和新密码。'];
    }
    if (strlen($newPassword) < 6) {
        return ['ok' => false, 'error' => '新密码长度不能少于 6 位。'];
    }
    if ($oldPassword === $newPassword) {
        return ['ok' => false, 'error' => '新密码不能与原密码相同。'];
    }

    $user = auth_find_user_by_identifier($identifier);
    if (!$user || !password_verify($oldPassword, (string) $user['password_hash'])) {
        auth_record_failure($identifier, $user ? (int) $user['id'] : null, 'wrong_old_password');
        return ['ok' => false, 'error' => '账号或原密码不正确。'];
    }

    $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
    $pdo->prepare("UPDATE `users` SET `password_hash` = :ph, `is_initial_pwd` = 0 WHERE `id` = :id")
        ->execute([':ph' => $newHash, ':id' => (int) $user['id']]);

    return ['ok' => true, 'error' => ''];
}