<?php
/**
 * 轻量登录模块：仅用于本项目的演示账号。
 * 账号：admin
 * 密码：由 PASSWORD_ARGON2ID 生成的哈希，不在代码中保存明文。
 */

require_once __DIR__ . '/csrf.php';

const AUTH_USERNAME = 'admin';
const AUTH_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$TFJCdzhnTVpBSzBjUDgvTg$tzOmQlWR2JuqeN92rrQS2T4Rpe4ij/YMm63mZrPtD1M';
const AUTH_CAPTCHA_LENGTH = 5;

function auth_is_logged_in(): bool
{
    return isset($_SESSION['auth_user']) && $_SESSION['auth_user'] === AUTH_USERNAME;
}

function auth_require_login(): void
{
    if (!auth_is_logged_in()) {
        header('Location: ../auth/login.php');
        exit;
    }
}

function auth_requires_captcha(): bool
{
    return (int) ($_SESSION['login_failures'] ?? 0) >= 1;
}

function auth_record_failure(): void
{
    $_SESSION['login_failures'] = (int) ($_SESSION['login_failures'] ?? 0) + 1;
    unset($_SESSION['captcha_code'], $_SESSION['captcha_created_at']);
}

function auth_reset_failures(): void
{
    unset($_SESSION['login_failures'], $_SESSION['captcha_code'], $_SESSION['captcha_created_at']);
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
