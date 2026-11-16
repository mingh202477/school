<?php
require_once __DIR__ . '/../../app/models/auth.php';

if (!extension_loaded('gd')) {
    http_response_code(500);
    exit('GD extension is required to render the captcha.');
}

$code = auth_generate_captcha();
$width = 148;
$height = 50;
$image = imagecreatetruecolor($width, $height);

$background = imagecolorallocate($image, 242, 246, 251);
$text = imagecolorallocate($image, 22, 86, 154);
imagefilledrectangle($image, 0, 0, $width, $height, $background);

// 干扰线和干扰点，让图片验证码不能被简单的 OCR 规则绕过。
for ($i = 0; $i < 7; $i++) {
    $line = imagecolorallocate($image, random_int(160, 210), random_int(180, 220), random_int(200, 240));
    imageline($image, 0, random_int(0, $height), $width, random_int(0, $height), $line);
}

for ($i = 0; $i < 100; $i++) {
    $dot = imagecolorallocate($image, random_int(130, 190), random_int(150, 205), random_int(180, 230));
    imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $dot);
}

for ($i = 0; $i < strlen($code); $i++) {
    imagestring($image, 5, 13 + $i * 25, random_int(14, 25), $code[$i], $text);
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
imagepng($image);
imagedestroy($image);
