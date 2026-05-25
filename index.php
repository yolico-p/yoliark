<?php
require_once __DIR__ . '/bootstrap/app.php';

use App\Core\Security;
use App\Core\Config;
use App\Core\Database;
use App\Services\AuthService;
use App\Services\ShareService;

Security::init();

$config = Config::getInstance();

if (!$config->isInstalled()) {
    Security::redirect('install.php');
}

// 回收站清理：每小时最多执行一次，不阻塞页面
$lastCleanupFile = DATA_PATH . DIRECTORY_SEPARATOR . '.trash_cleanup';
$lastCleanup = file_exists($lastCleanupFile) ? (int)file_get_contents($lastCleanupFile) : 0;
if (time() - $lastCleanup > 3600) {
    $trashService = new \App\Services\TrashService();
    $trashService->cleanExpired();
    @file_put_contents($lastCleanupFile, (string)time());
}

if (isset($_GET['action'])) {
    require __DIR__ . '/api.php';
    exit;
}

// 延迟生成登录页背景图案（安装时未生成时的兜底，写入 PHP 有权限的目录）
$_bgPath = DATA_PATH . DIRECTORY_SEPARATOR . 'bg-pattern.png';
if (function_exists('imagecreatetruecolor') && !file_exists($_bgPath)) {
    try {
        $_test = @imagecreatetruecolor(2, 2);
        if (!$_test) throw new \RuntimeException('gd_fail');
        imagedestroy($_test);
        $_seed = crc32(__FILE__ . '|' . date('Y-m-d'));
        $_w = 400; $_h = 300;
        $_im = imagecreatetruecolor($_w, $_h);
        for ($_y = 0; $_y < $_h; $_y++) {
            $_t = $_y / $_h;
            $_c = imagecolorallocate($_im,
                (int)((1-$_t)*240+$_t*248),
                (int)((1-$_t)*242+$_t*250),
                (int)((1-$_t)*248+$_t*252));
            imageline($_im, 0, $_y, $_w, $_y, $_c);
        }
        foreach ([[37,99,235,80,120,120,180,180],
                  [124,58,237,50,320,240,150,150],
                  [8,145,178,30,200,60,120,120]] as $_a) {
            $_ac = imagecolorallocatealpha($_im, $_a[0],$_a[1],$_a[2],$_a[3]);
            imagefilledellipse($_im, $_a[4],$_a[5],$_a[6],$_a[7],$_ac);
        }
        $_basis = [];
        for ($_u = 0; $_u < 8; $_u++) for ($_v = 0; $_v < 8; $_v++) {
            $_cu = $_u===0 ? 1.0/sqrt(2) : 1.0;
            $_cv = $_v===0 ? 1.0/sqrt(2) : 1.0;
            for ($_x = 0; $_x < 8; $_x++) for ($_y2 = 0; $_y2 < 8; $_y2++)
                $_basis[$_u*8+$_v][$_x*8+$_y2] = $_cu * $_cv
                    * cos((2*$_x+1)*$_u*M_PI/16)
                    * cos((2*$_y2+1)*$_v*M_PI/16);
        }
        for ($_by = 0; $_by + 8 <= $_h; $_by += 8)
        for ($_bx = 0; $_bx + 8 <= $_w; $_bx += 8) {
            $_bl = [];
            for ($_iy = 0; $_iy < 8; $_iy++)
            for ($_ix = 0; $_ix < 8; $_ix++) {
                $_p = imagecolorat($_im, $_bx+$_ix, $_by+$_iy);
                $_bl[$_iy*8+$_ix] = 0.299*(($_p>>16)&0xFF)
                    + 0.587*(($_p>>8)&0xFF)
                    + 0.114*($_p&0xFF);
            }
            $_o31 = 0.0; $_o13 = 0.0;
            for ($_k = 0; $_k < 64; $_k++) {
                $_o31 += $_bl[$_k] * $_basis[3*8+1][$_k];
                $_o13 += $_bl[$_k] * $_basis[1*8+3][$_k];
            }
            $_o31 *= 2 / 8; $_o13 *= 2 / 8;
            $_seed = (1103515245*$_seed+12345)&0x7fffffff;
            $_bit = ($_seed>>16)&1;
            $_avg = ($_o31 + $_o13) / 2;
            if ($_bit) { $_n31 = $_avg + 2.25; $_n13 = $_avg - 2.25; }
            else { $_n31 = $_avg - 2.25; $_n13 = $_avg + 2.25; }
            $_d31 = $_n31 - $_o31; $_d13 = $_n13 - $_o13;
            for ($_x = 0; $_x < 8; $_x++) for ($_y2 = 0; $_y2 < 8; $_y2++) {
                $_v31 = $_basis[3*8+1][$_x*8+$_y2];
                $_v13 = $_basis[1*8+3][$_x*8+$_y2];
                $_nv = ($_d31*$_v31 + $_d13*$_v13) * 2 / 8;
                $_nv = (int)round($_bl[$_x*8+$_y2] + $_nv);
                $_nv = max(1, min(254, $_nv));
                $_orig = imagecolorat($_im, $_bx+$_x, $_by+$_y2);
                $_ro = ($_orig>>16)&0xFF;
                $_go = ($_orig>>8)&0xFF;
                $_bo = $_orig&0xFF;
                $_yo = 0.299*$_ro+0.587*$_go+0.114*$_bo;
                $_d = $_nv - $_yo;
                $_nc = imagecolorallocate($_im,
                    max(0,min(255,(int)round($_ro+$_d))),
                    max(0,min(255,(int)round($_go+$_d))),
                    max(0,min(255,(int)round($_bo+$_d))));
                imagesetpixel($_im, $_bx+$_x, $_by+$_y2, $_nc);
            }
        }
        imagepng($_im, $_bgPath);
        imagedestroy($_im);
    } catch (\Throwable $e) {}
}

// 页面路由
$allowedPages = ['files', 'login', 'share', 'recent', 'favorites', 'shares', 'trash', 'logs', 'ai', 'settings'];
$page = $_GET['page'] ?? 'files';

if (!in_array($page, $allowedPages)) {
    $page = 'files';
}

$token = $_GET['token'] ?? '';

Security::botChallenge();

$auth = new AuthService();
$isLoggedIn = $auth->isLoggedIn();
$user = $isLoggedIn ? $auth->getUser() : null;

if ($isLoggedIn) {
    $csrfToken = Security::generateCSRFToken();
} else {
    $csrfToken = bin2hex(random_bytes(32));
}

// 登录状态与 CSRF Token 已确定，立即释放 Session 文件锁，避免阻塞并发请求
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// 分享页数据
$shareData = null;
if ($page === 'share' && !empty($token)) {
    $shareService = new ShareService();
    $shareInfo = $shareService->getShareByToken($token);
    if ($shareInfo) {
        $shareInfoFull = $shareService->getShareInfo($token);
        if ($shareInfoFull) {
            $file = $shareInfoFull['file'];
            $shareData = [
                'token' => $token,
                'filename' => $file['filename'],
                'filesize_formatted' => Security::formatSize($file['filesize']),
                'filesize' => $file['filesize'],
                'file_type' => $file['file_type'] ?? '',
                'file_id' => $file['id'] ?? 0,
                'mime_type' => $file['mime_type'] ?? '',
                'has_password' => $shareInfoFull['has_password'],
            ];
        }
    }
}

if (!$isLoggedIn && !in_array($page, ['login', 'share'])) {
    Security::redirect('index.php?page=login');
}

// 页面构建哈希
$pageBuildHash = hash('sha256', __FILE__);

// --- 视图渲染 ---
require __DIR__ . '/views/layouts/head.php';

if ($page === 'login') {
    require __DIR__ . '/views/pages/login.php';
} elseif ($page === 'share') {
    require __DIR__ . '/views/pages/share.php';
} else {
    require __DIR__ . '/views/pages/app.php';
}

// 通用脚本（所有页面都需要加载 JS 文件和 APP_CONFIG）
require __DIR__ . '/views/layouts/scripts.php';

// 页面专属脚本（按需加载，避免无关页面加载多余的 JS）
if ($page === 'login') {
    require __DIR__ . '/views/pages/_login_script.php';
} elseif ($page !== 'share') {
    // share 页面的内联脚本包含在 share.php 自身中
    require __DIR__ . '/views/pages/_app_script.php';
}

require __DIR__ . '/views/layouts/foot.php';
