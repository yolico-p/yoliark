<script src="assets/js/utils.js"></script>
<script src="assets/js/core.js"></script>
<script src="assets/js/theme.js"></script>
<script src="assets/js/upload.js"></script>
<script src="assets/js/preview.js"></script>
<script src="assets/js/files.js"></script>
<script src="assets/js/ai.js"></script>
<script src="assets/js/cloud.js"></script>
<script src="assets/js/share.js"></script>
<script src="assets/js/pages.js"></script>
<script>
window.APP_CONFIG = {
    debug: <?php echo $config->get('debug') ? 'true' : 'false'; ?>,
    csrfToken: <?php echo json_encode($csrfToken); ?>,
    chunkSize: <?php echo (int)$config->get('chunk_size'); ?>
};

// PWA：注册 Service Worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js').then(function (reg) {
            reg.onupdatefound = function () {
                var installing = reg.installing;
                if (installing) {
                    installing.onstatechange = function () {
                        if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                            console.log('[PWA] 新版本已缓存，下次加载时生效');
                        }
                    };
                }
            };
        }).catch(function (err) {
            console.warn('[PWA] Service Worker 注册失败:', err);
        });
    });
    
    // 拦截「添加到主屏幕」弹窗提示
    var deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        // 可以在这里延时弹出安装提示，暂不自动触发
    });
    
    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        console.log('[PWA] 已安装到主屏幕');
    });
}
</script>
