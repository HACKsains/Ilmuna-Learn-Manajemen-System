<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — BASE TEMPLATE SIDEBAR (ROLE-AWARE)
 * ==============================================================================
 * Tampilan mengikuti gaya Menu (React) — kategori minimalis, icon box,
 * gradient aktif, hover smooth. Warna tetap Lumina.
 * Header logo & tulisan panel DIHAPUS — menu langsung dari atas.
 * + Scroll position persistence antar navigasi.
 * + Page transition loader — tampil SEBELUM page terlihat, hilang saat siap.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    exit;
}

$currentUser   = getCurrentUser();
$activeRole    = $GLOBALS['activeRole'] ?? $activeRole ?? getActiveRole();
$userRoles     = getUserRoles();
$currentScript = basename($_SERVER['PHP_SELF']);
$currentRoute  = preg_replace('/\.php$/i', '', $currentScript);

$user_name = $currentUser['full_name'] ?: $currentUser['username'];
$user_foto = $currentUser['avatar'] ?? '';

// Generate Initials
$parts = preg_split('/\s+/', trim($user_name));
$inisial = '';
if (!empty($parts)) {
    $inisial .= mb_substr($parts[0], 0, 1);
    if (isset($parts[1])) {
        $inisial .= mb_substr($parts[1], 0, 1);
    }
}
$inisial = mb_strtoupper($inisial ?: mb_substr($user_name, 0, 2));
?>
<style id="ilmuna-sidebar-styles">
/* ==========================================================================
 * SIDEBAR — MATCH FILE 2 LOOK (React Menu), NO HEADER
 * ========================================================================== */
.ilmuna-sidebar {
    position: fixed;
    top: 0; left: 0; bottom: 0;
    width: 265px;
    background: var(--lumina-bg-sidebar, rgba(255, 255, 255, 0.96));
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-right: 1px solid var(--lumina-border, #E2E8F0);
    display: flex;
    flex-direction: column;
    z-index: 1000;
    box-shadow: 4px 0 24px rgba(29, 59, 94, 0.04);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Scrollable menu */
.ilmuna-sidebar-menu {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 24px 14px 24px;
    scrollbar-width: thin;
    scrollbar-color: rgba(13, 122, 135, 0.2) transparent;
}
.ilmuna-sidebar-menu::-webkit-scrollbar { width: 4px; }
.ilmuna-sidebar-menu::-webkit-scrollbar-track { background: transparent; }
.ilmuna-sidebar-menu::-webkit-scrollbar-thumb {
    background: rgba(13, 122, 135, 0.2);
    border-radius: 10px;
}
.ilmuna-sidebar-menu::-webkit-scrollbar-thumb:hover {
    background: rgba(13, 122, 135, 0.35);
}

/* Category label — React style */
.ilmuna-menu-category {
    font-size: 10px;
    font-weight: 900;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #94A3B8;
    padding-left: 16px;
    margin: 22px 0 10px;
    display: block;
}
.ilmuna-menu-category:first-child { margin-top: 4px; }

/* Menu item — React style */
.ilmuna-menu-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    border-radius: 12px;
    color: #475569;
    text-decoration: none !important;
    font-size: 13.5px;
    font-weight: 500;
    letter-spacing: 0.01em;
    margin-bottom: 2px;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

/* Hover */
.ilmuna-menu-item:hover {
    background: rgba(13, 122, 135, 0.08);
    color: var(--lumina-teal, #0D7A87);
}
.ilmuna-menu-item:hover .ilmuna-menu-icon-box {
    background: rgba(13, 122, 135, 0.12);
    color: var(--lumina-teal, #0D7A87);
}
.ilmuna-menu-item:hover .ilmuna-menu-label {
    transform: translateX(4px);
}

/* Active */
.ilmuna-menu-item.active {
    background: linear-gradient(90deg, #0D7A87 0%, #0A6B75 100%);
    color: #FFFFFF !important;
    box-shadow: 0 10px 20px -6px rgba(13, 122, 135, 0.3);
}
.ilmuna-menu-item.active .ilmuna-menu-icon-box {
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
}
.ilmuna-menu-item.active .ilmuna-menu-label {
    font-weight: 600;
    transform: translateX(0);
}
.ilmuna-menu-item.active .ilmuna-menu-badge {
    background: rgba(255, 255, 255, 0.25) !important;
    color: #FFFFFF !important;
    border-color: rgba(255, 255, 255, 0.35) !important;
    box-shadow: none;
}

/* Icon box */
.ilmuna-menu-icon-box {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #F1F5F9;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #64748B;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

/* Label */
.ilmuna-menu-label {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Badge */
.ilmuna-menu-badge {
    font-size: 9px;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 999px;
    background: linear-gradient(135deg, #F59E0B, #D97706);
    color: #FFFFFF;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    box-shadow: 0 2px 6px rgba(245, 158, 11, 0.3);
    flex-shrink: 0;
}

/* Logout item styling */
.ilmuna-menu-item.logout-btn {
    color: #DC2626;
}
.ilmuna-menu-item.logout-btn .ilmuna-menu-icon-box {
    background: rgba(239, 68, 68, 0.1);
    color: #DC2626;
}
.ilmuna-menu-item.logout-btn:hover {
    background: rgba(239, 68, 68, 0.08);
    color: #DC2626;
}
.ilmuna-menu-item.logout-btn:hover .ilmuna-menu-icon-box {
    background: rgba(239, 68, 68, 0.16);
    color: #DC2626;
}

/* Responsive */
@media (max-width: 768px) {
    .ilmuna-sidebar { display: none !important; }
}

/* (loader styles dipindah ke includes/loader.php */
</style>

<?php require_once __DIR__ . '/loader.php'; ?>

<aside class="ilmuna-sidebar">
    <!-- Grouped Sidebar Menu Items -->
    <div class="ilmuna-sidebar-menu" id="ilmunaSidebarMenu">

        <?php
        // ── Load daftar menu dari file konfigurasi terpisah ──────────────────
        require_once __DIR__ . '/menu.php';

        $menuItems = $sidebarMenus[$activeRole] ?? [];
        foreach ($menuItems as $item):
            if ($item['type'] === 'category'):
        ?>
            <div class="ilmuna-menu-category"><?= htmlspecialchars($item['label']) ?></div>
        <?php
            elseif ($item['type'] === 'item'):
                $isActive  = !empty($item['route']) && $currentRoute === $item['route'];
                $extraCls  = isset($item['class'])   ? ' ' . $item['class'] : '';
                $onclickAt = isset($item['onclick'])  ? ' onclick="' . htmlspecialchars($item['onclick']) . '"' : '';
                $href      = $item['href'] ?? '#';
        ?>
            <a class="ilmuna-menu-item<?= $extraCls ?><?= $isActive ? ' active' : '' ?>"
               href="<?= htmlspecialchars($href) ?>"<?= $onclickAt ?>>
                <span class="ilmuna-menu-icon-box">
                    <iconify-icon icon="<?= htmlspecialchars($item['icon']) ?>" width="16"></iconify-icon>
                </span>
                <span class="ilmuna-menu-label"><?= htmlspecialchars($item['label']) ?></span>
                <?php if (!empty($item['badge'])): ?>
                    <span class="ilmuna-menu-badge"
                        <?= !empty($item['badge']['style']) ? 'style="' . htmlspecialchars($item['badge']['style']) . '"' : '' ?>>
                        <?= htmlspecialchars($item['badge']['text']) ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php
            endif;
        endforeach;
        ?>

    </div>
</aside>

<!-- ======================================================================
 * SIDEBAR SCROLL PERSISTENCE
 * Simpan & restore posisi scroll sidebar antar navigasi.
 * ====================================================================== -->
<script>
(function () {
    var menu = document.getElementById('ilmunaSidebarMenu');
    if (!menu) return;

    var STORAGE_KEY = 'ilmuna_sidebar_scroll_v2';

    function saveScroll() {
        try {
            sessionStorage.setItem(STORAGE_KEY, String(menu.scrollTop));
        } catch (e) {}
    }

    function restoreScroll() {
        var saved = null;
        try { saved = sessionStorage.getItem(STORAGE_KEY); } catch (e) {}

        if (saved !== null && saved !== '') {
            menu.scrollTop = parseInt(saved, 10) || 0;
        }

        var active = menu.querySelector('.ilmuna-menu-item.active');
        if (active) {
            var menuRect   = menu.getBoundingClientRect();
            var activeRect = active.getBoundingClientRect();
            var margin     = 24;
            var isVisible  = activeRect.top  >= menuRect.top  - margin
                          && activeRect.bottom <= menuRect.bottom + margin;

            if (!isVisible) {
                var offset = active.offsetTop - (menu.clientHeight / 2) + (active.offsetHeight / 2);
                offset = Math.max(0, Math.min(offset, menu.scrollHeight - menu.clientHeight));
                menu.scrollTop = offset;
            }
        }
    }

    restoreScroll();
    requestAnimationFrame(restoreScroll);
    setTimeout(restoreScroll, 120);
    window.addEventListener('load', restoreScroll);

    menu.addEventListener('click', function (e) {
        var link = e.target.closest('a');
        if (link && link.getAttribute('href') && link.getAttribute('href') !== '#') {
            saveScroll();
        }
    });

    window.addEventListener('beforeunload', saveScroll);
    window.addEventListener('pagehide', saveScroll);
    window.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') saveScroll();
    });
})();
</script>

<?php /* (loader controller dipindah ke includes/loader.php) */?>