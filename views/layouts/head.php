<?php
use App\Core\Security;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="googlebot" content="noindex, nofollow">
    <meta name="bingbot" content="noindex, nofollow">
    <meta name="revisit-after" content="never">
    <meta name="app-build-hash" content="<?php echo $pageBuildHash; ?>">
    <title><?php echo Security::escape($config->get('app_name')); ?> - 个人网盘</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/fluent-share.css">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
    <!-- highlight.js 样式由 preview.js 按需加载 -->
    <style>
        /* 背景装饰图案 — 安装时生成，包含可追溯标识 */
        body.bg-pattern::before {
            content: '';
            position: fixed;
            inset: 0;
            z-index: -1;
            background: url('index.php?action=bg_pattern') center/cover no-repeat;
            opacity: 0.85;
            pointer-events: none;
        }
    </style>
</head>
<body>

<div class="bg-mesh">
    <div class="orb"></div>
    <div class="orb"></div>
</div>

<?php if (defined('DATA_PATH') && file_exists(DATA_PATH . '/bg-pattern.png')): ?>
<script>
document.body.classList.add('bg-pattern');
</script>
<?php endif; ?>
