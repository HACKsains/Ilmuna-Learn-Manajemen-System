<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — BASE TEMPLATE FOOTER (BNB-25 Concave Subtract + Pointer Style)
 * ==============================================================================
 * - Bentuk & animasi mengikuti desain Figma BNB-25 (Subtract 34px + Pointer 13px)
 * - Warna tetap menggunakan tema Ilmuna Learn (#0D7A87 -> #0A6B75)
 * - Tombol Menu (titik tiga) membuka Card Dropdown (hanya efek hover)
 * - Badge Guardian otomatis terangkat rapi di atas bottom nav
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    exit;
}

$activeRole    = $GLOBALS['activeRole'] ?? $activeRole ?? getActiveRole();
$currentScript = basename($_SERVER['PHP_SELF']);
$requestUri    = $_SERVER['REQUEST_URI'] ?? '';

// -------- Load daftar menu mobile dari file konfigurasi terpisah --------
require_once __DIR__ . '/menu.php';

$_mobileNav      = $mobileNavMenus[$activeRole] ?? ['primary' => [], 'sheet' => []];
$roleNavPrimary  = $_mobileNav['primary'];
$roleNavSheet    = $_mobileNav['sheet'];

// Seluruh item navigasi mobile (primary & sheet) 100% mengikuti dari menu.php (tidak hardcode)
$sheetPages      = array_chunk($roleNavSheet, 12);

/**
 * Deteksi index menu utama yang sedang aktif
 */
if (!function_exists('ilmunaResolveActiveNavIndex')) {
    function ilmunaResolveActiveNavIndex(array $items, string $currentScript, string $requestUri): int {
        $scriptLower = strtolower($currentScript);
        $uriPath     = strtolower((string) parse_url($requestUri, PHP_URL_PATH));

        foreach ($items as $idx => $item) {
            if (!empty($item['match'])) {
                if (is_array($item['match'])) {
                    foreach ($item['match'] as $m) {
                        if (strtolower((string) $m) === $scriptLower) {
                            return $idx;
                        }
                    }
                } else {
                    $matchStr = strtolower((string) $item['match']);
                    if ($matchStr === $scriptLower || $matchStr . '.php' === $scriptLower) {
                        return $idx;
                    }
                }
            }

            if (!empty($item['href'])) {
                $hrefPath = strtolower((string) parse_url($item['href'], PHP_URL_PATH));
                $hrefBase = basename($hrefPath);
                if ($hrefBase !== '' && $hrefBase === $scriptLower) {
                    return $idx;
                }
                if ($uriPath !== '' && $hrefPath !== '' && substr($uriPath, -strlen($hrefPath)) === $hrefPath) {
                    return $idx;
                }
            }
        }

        if (in_array($scriptLower, ['dashboard.php', 'index.php', 'home.php'], true) || strpos($uriPath, 'dashboard') !== false) {
            foreach ($items as $idx => $item) {
                $labelLower = strtolower($item['label'] ?? '');
                if (strpos($labelLower, 'dashboard') !== false || strpos($labelLower, 'beranda') !== false || strpos($labelLower, 'home') !== false) {
                    return $idx;
                }
            }
            return 0;
        }

        return 0;
    }
}

$activeNavIndex = ilmunaResolveActiveNavIndex($roleNavPrimary, $currentScript, $requestUri);
?>
    </main><!-- Close .ilmuna-content-area -->

    <!-- ======================================================================
     * FOOTER STYLES — Figma BNB-25 Shape & Animation + Ilmuna Learn Colors
     * ====================================================================== -->
    <style id="ilmuna-footer-react-styles">
    /* ---------- BOTTOM NAV CONTAINER (BNB-25 75px Subtract Bar) ---------- */
    html body .ilmuna-mhs .ilmuna-bottomnav,
    html body .ilmuna-bottomnav {
        --notch-x: 48px;
        position: fixed !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        height: calc(75px + env(safe-area-inset-bottom, 0px)) !important;
        padding: 0 16px env(safe-area-inset-bottom, 0px) 16px !important;
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        overflow: visible !important;
        filter: drop-shadow(0 -8px 22px rgba(10, 107, 117, 0.35)) !important;
        z-index: 1200 !important;
    }

    /* Layer Subtract: Bar Teal Ilmuna dengan Cekungan Bulat 34px (Ellipse 207) di Atas */
    html body .ilmuna-mhs .ilmuna-bottomnav::before,
    html body .ilmuna-bottomnav::before {
        content: "" !important;
        position: absolute !important;
        inset: 0 !important;
        background: linear-gradient(180deg, #0D7A87 0%, #0A6B75 100%) !important;
        border-radius: 12px 12px 0 0 !important;
        /* Cutout lingkaran 34px (jari-jari 17px) tepat di bibir atas bar seperti Figma Subtract */
        -webkit-mask-image: radial-gradient(circle 17.5px at var(--notch-x, 48px) -1px, transparent 16.8px, #000000 17.8px) !important;
        mask-image: radial-gradient(circle 17.5px at var(--notch-x, 48px) -1px, transparent 16.8px, #000000 17.8px) !important;
        z-index: 0 !important;
        pointer-events: none !important;
    }

    /* ---------- POINTER & INNER-POINTER (Frame 1998 & Frame 1999 di Figma) ---------- */
    .ilmuna-bnb-pointer {
        position: absolute !important;
        width: 34px !important;
        height: 34px !important;
        top: -17px !important;
        left: var(--notch-x, 48px) !important;
        transform: translateX(-50%) !important;
        border-radius: 34px !important;
        pointer-events: none !important;
        z-index: 2 !important;
    }

    .ilmuna-bnb-inner-pointer {
        position: absolute !important;
        width: 13px !important;
        height: 13px !important;
        left: 10.5px !important;
        top: 10.5px !important;
        background: #0D7A87 !important;
        border: 2px solid #FFFFFF !important;
        border-radius: 34px !important;
        box-shadow: 0 2px 8px rgba(13, 122, 135, 0.55) !important;
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
    }

    .ilmuna-bnb-pointer.is-moving .ilmuna-bnb-inner-pointer {
        transform: scale(0.78) !important;
    }

    /* ---------- MENU AUTO-LAYOUT (Figma BNB-25: height 75px, align-items flex-end) ---------- */
    html body .ilmuna-mhs .ilmuna-dock,
    html body .ilmuna-dock {
        position: relative !important;
        z-index: 1 !important;
        display: flex !important;
        flex-direction: row !important;
        justify-content: space-between !important;
        align-items: flex-end !important;
        width: 100% !important;
        max-width: 100% !important;
        height: 75px !important;
        padding: 0 !important;
        gap: 8px !important;
    }

    /* ---------- ITEM MENU (Figma BNB-25: height 68px, padding 12.5px 15px, gap 5px) ---------- */
    html body .ilmuna-mhs .ilmuna-nav-item,
    html body .ilmuna-nav-item {
        flex: 1 1 0% !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        height: 68px !important;
        padding: 12.5px 6px !important;
        gap: 5px !important;
        margin: 0 auto !important;
        color: rgba(255, 255, 255, 0.62) !important;
        background: transparent !important;
        box-shadow: none !important;
        border: none !important;
        border-radius: 0 !important;
        cursor: pointer !important;
        text-decoration: none !important;
        font-family: inherit !important;
        transition: color 0.25s ease, transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
    }

    html body .ilmuna-mhs .ilmuna-nav-item:hover,
    html body .ilmuna-nav-item:hover {
        color: rgba(255, 255, 255, 0.9) !important;
        background: transparent !important;
    }

    html body .ilmuna-mhs .ilmuna-nav-item.active,
    html body .ilmuna-nav-item.active {
        color: #FFFFFF !important;
        background: transparent !important;
    }

    /* Icon 24x24 sesuai Figma BNB-25 */
    html body .ilmuna-mhs .ilmuna-nav-icon-wrap,
    html body .ilmuna-nav-icon-wrap {
        width: 24px !important;
        height: 24px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        background: transparent !important;
        box-shadow: none !important;
        border: none !important;
        border-radius: 0 !important;
        transition: transform 0.28s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
    }

    html body .ilmuna-mhs .ilmuna-nav-item:not(#ilmunaMenuBtn).active .ilmuna-nav-icon-wrap,
    html body .ilmuna-nav-item:not(#ilmunaMenuBtn).active .ilmuna-nav-icon-wrap {
        transform: translateY(-2px) scale(1.08) !important;
        filter: drop-shadow(0 2px 6px rgba(255, 255, 255, 0.25)) !important;
    }

    /* Label 12px line-height 14px sesuai Figma BNB-25 */
    html body .ilmuna-mhs .ilmuna-nav-item > span,
    html body .ilmuna-nav-item > span {
        font-size: 11.5px !important;
        line-height: 14px !important;
        font-weight: 400 !important;
        letter-spacing: 0.01em !important;
        text-align: center !important;
        white-space: nowrap !important;
        color: inherit !important;
        transition: font-weight 0.2s ease, color 0.2s ease !important;
    }

    html body .ilmuna-mhs .ilmuna-nav-item.active > span,
    html body .ilmuna-nav-item.active > span {
        font-weight: 600 !important;
        color: #FFFFFF !important;
    }

    /* ---------- TOMBOL MENU (Titik Tiga Bulat) ---------- */
    html body .ilmuna-mhs .ilmuna-nav-menu-dots,
    html body .ilmuna-nav-menu-dots {
        display: flex !important;
        flex-direction: column !important;
        gap: 3.5px !important;
        align-items: center !important;
        justify-content: center !important;
        transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1) !important;
        color: currentColor !important;
    }

    html body .ilmuna-mhs .ilmuna-nav-menu-dots.active,
    html body .ilmuna-nav-menu-dots.active {
        transform: rotate(90deg) !important;
    }

    html body .ilmuna-mhs .ilmuna-nav-menu-dots > div,
    html body .ilmuna-nav-menu-dots > div {
        width: 4.5px !important;
        height: 4.5px !important;
        border-radius: 50% !important;
        background: currentColor !important;
        transition: transform 0.2s ease, background 0.2s ease !important;
    }

    html body .ilmuna-mhs #ilmunaMenuBtn:hover .ilmuna-nav-menu-dots > div,
    html body #ilmunaMenuBtn:hover .ilmuna-nav-menu-dots > div {
        transform: scale(1.18) !important;
        background: #FFFFFF !important;
    }

    /* ---------- CARD DROPDOWN / BOTTOM SHEET (Hanya Efek Hover Saja) ---------- */
    html body .ilmuna-mhs .ilmuna-sheet,
    html body .ilmuna-sheet {
        position: fixed !important;
        bottom: calc(92px + env(safe-area-inset-bottom, 0px)) !important;
        left: 14px !important;
        right: 14px !important;
        background: rgba(255, 255, 255, 0.97) !important;
        backdrop-filter: blur(24px) saturate(1.6) !important;
        -webkit-backdrop-filter: blur(24px) saturate(1.6) !important;
        border: 1px solid rgba(255, 255, 255, 0.7) !important;
        border-radius: 28px !important;
        padding: 14px 12px 18px !important;
        max-height: 60vh !important;
        overflow: hidden !important;
        box-shadow: 0 25px 50px -12px rgba(15, 29, 47, 0.3), 0 0 0 1px rgba(255,255,255,0.5) inset !important;
        transform: translateY(calc(100% + 120px)) !important;
        transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1) !important;
        z-index: 1250 !important;
    }

    html body .ilmuna-mhs .ilmuna-sheet.active,
    html body .ilmuna-sheet.active {
        transform: translateY(0) !important;
    }

    .ilmuna-sheet-handle {
        width: 48px;
        height: 6px;
        background: #E2E8F0;
        border-radius: 999px;
        margin: 0 auto 14px;
    }

    .ilmuna-sheet-grid-wrap {
        display: flex;
        gap: 14px;
        overflow-x: auto;
        overflow-y: hidden;
        scroll-snap-type: x mandatory;
        scrollbar-width: none;
        padding-bottom: 4px;
    }
    .ilmuna-sheet-grid-wrap::-webkit-scrollbar { display: none; }

    .ilmuna-sheet-page {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        grid-template-rows: repeat(3, auto);
        gap: 14px 6px;
        min-width: 100%;
        scroll-snap-align: center;
    }

    html body .ilmuna-mhs .ilmuna-sheet-item,
    html body .ilmuna-sheet-item {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        gap: 6px !important;
        background: transparent !important;
        box-shadow: none !important;
        border: none !important;
        text-decoration: none !important;
        font-size: 9.5px !important;
        font-weight: 600 !important;
        color: #475569 !important;
        text-align: center !important;
        padding: 4px 2px !important;
        cursor: pointer !important;
        font-family: inherit !important;
        transition: transform 0.18s ease, color 0.18s ease !important;
    }

    html body .ilmuna-mhs .ilmuna-sheet-icon,
    html body .ilmuna-sheet-icon {
        width: 44px !important;
        height: 44px !important;
        border-radius: 14px !important;
        background: #F8FAFC !important;
        border: 1px solid #F1F5F9 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        color: #475569 !important;
        box-shadow: 0 1px 2px rgba(29, 59, 94, 0.05) !important;
        transition: all 0.2s ease !important;
    }

    html body .ilmuna-mhs .ilmuna-sheet-item:hover,
    html body .ilmuna-sheet-item:hover {
        color: #0D7A87 !important;
        transform: translateY(-2px) !important;
    }

    html body .ilmuna-mhs .ilmuna-sheet-item:hover .ilmuna-sheet-icon,
    html body .ilmuna-sheet-item:hover .ilmuna-sheet-icon {
        background: rgba(13, 122, 135, 0.10) !important;
        color: #0D7A87 !important;
        border-color: rgba(13, 122, 135, 0.24) !important;
        box-shadow: 0 6px 14px -4px rgba(13, 122, 135, 0.18) !important;
    }

    html body .ilmuna-mhs .ilmuna-sheet-item:active,
    html body .ilmuna-sheet-item:active {
        transform: scale(0.94) !important;
    }

    html body .ilmuna-mhs .ilmuna-sheet-item.danger,
    html body .ilmuna-sheet-item.danger {
        color: #DC2626 !important;
    }

    html body .ilmuna-mhs .ilmuna-sheet-item.danger .ilmuna-sheet-icon,
    html body .ilmuna-sheet-item.danger .ilmuna-sheet-icon {
        background: rgba(239, 68, 68, 0.08) !important;
        color: #DC2626 !important;
        border-color: rgba(239, 68, 68, 0.18) !important;
    }

    html body .ilmuna-mhs .ilmuna-sheet-item.danger:hover .ilmuna-sheet-icon,
    html body .ilmuna-sheet-item.danger:hover .ilmuna-sheet-icon {
        background: rgba(239, 68, 68, 0.15) !important;
        color: #DC2626 !important;
        border-color: rgba(239, 68, 68, 0.30) !important;
    }

    .ilmuna-sheet-item > span {
        line-height: 1.15;
        padding: 0 2px;
        color: inherit;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }

    .ilmuna-sheet-dots {
        display: flex;
        justify-content: center;
        gap: 6px;
        margin-top: 14px;
    }

    .ilmuna-sheet-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #CBD5E1;
        transition: background 0.25s ease, transform 0.25s ease;
    }

    .ilmuna-sheet-dot.active {
        background: #0D7A87;
        transform: scale(1.25);
    }

    /* ---------- OVERLAY & MODAL Z-INDEX ---------- */
    .ilmuna-overlay {
        z-index: 1240 !important;
    }
    .ilmuna-modal {
        z-index: 13000 !important;
    }

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 360px) {
        html body .ilmuna-mhs .ilmuna-bottomnav,
        html body .ilmuna-bottomnav {
            padding-left: 10px !important;
            padding-right: 10px !important;
        }
        html body .ilmuna-mhs .ilmuna-nav-item > span,
        html body .ilmuna-nav-item > span {
            font-size: 10.5px !important;
        }
    }
    @media (min-width: 769px) {
        html body .ilmuna-mhs .ilmuna-bottomnav,
        html body .ilmuna-bottomnav {
            display: none !important;
        }
    }
    </style>

    <!-- ======================================================================
     * MOBILE BOTTOM NAVIGATION DOCK (BNB-25 Structure)
     * ====================================================================== -->
    <div class="ilmuna-bottomnav" id="ilmunaBottomNav">
        <!-- Pointer & Inner-Pointer (Bulatan di dalam cekungan atas sesuai Figma BNB-25) -->
        <div class="ilmuna-bnb-pointer" id="ilmunaBnbPointer">
            <div class="ilmuna-bnb-inner-pointer"></div>
        </div>

        <nav class="ilmuna-dock" id="ilmunaDock">
            <?php foreach ($roleNavPrimary as $idx => $item):
                $isActive = ($idx === $activeNavIndex);
            ?>
                <a class="ilmuna-nav-item <?= $isActive ? 'active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>">
                    <div class="ilmuna-nav-icon-wrap">
                        <iconify-icon icon="<?= htmlspecialchars($item['icon']) ?>" width="24"></iconify-icon>
                    </div>
                    <span><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endforeach; ?>

            <button class="ilmuna-nav-item" id="ilmunaMenuBtn" onclick="ilmunaToggleSheet()" type="button">
                <div class="ilmuna-nav-icon-wrap">
                    <div class="ilmuna-nav-menu-dots" id="ilmunaMenuDots">
                        <div></div>
                        <div></div>
                        <div></div>
                    </div>
                </div>
                <span>Menu</span>
            </button>
        </nav>
    </div>

    <!-- ======================================================================
     * MOBILE SLIDE-UP BOTTOM SHEET (CARD DROPDOWN) & OVERLAY
     * ====================================================================== -->
    <div class="ilmuna-overlay" id="ilmunaOverlay" onclick="ilmunaCloseSheet()"></div>

    <div class="ilmuna-sheet" id="ilmunaSheet">
        <div class="ilmuna-sheet-handle"></div>

        <div class="ilmuna-sheet-grid-wrap" id="ilmunaSheetScroller">
            <?php foreach ($sheetPages as $pageItems): ?>
                <div class="ilmuna-sheet-page">
                    <?php foreach ($pageItems as $sItem):
                        $isDanger  = !empty($sItem['danger']);
                        $class     = 'ilmuna-sheet-item' . ($isDanger ? ' danger' : '');
                        $iconHtml  = '<div class="ilmuna-sheet-icon"><iconify-icon icon="' . htmlspecialchars($sItem['icon']) . '" width="20"></iconify-icon></div>';
                        $labelHtml = '<span>' . htmlspecialchars($sItem['label']) . '</span>';
                    ?>
                        <?php if (!empty($sItem['onclick'])): ?>
                            <button type="button" class="<?= $class ?>" onclick="<?= htmlspecialchars($sItem['onclick']) ?>">
                                <?= $iconHtml ?><?= $labelHtml ?>
                            </button>
                        <?php else: ?>
                            <a class="<?= $class ?>" href="<?= htmlspecialchars($sItem['href']) ?>">
                                <?= $iconHtml ?><?= $labelHtml ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (count($sheetPages) > 1): ?>
            <div class="ilmuna-sheet-dots" id="ilmunaSheetDots">
                <?php for ($i = 0; $i < count($sheetPages); $i++): ?>
                    <div class="ilmuna-sheet-dot <?= $i === 0 ? 'active' : '' ?>"></div>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ======================================================================
     * LOGOUT CONFIRMATION POPUP MODAL
     * ====================================================================== -->
    <div class="ilmuna-modal" id="ilmunaModalLogout">
        <div class="ilmuna-popup">
            <div class="ilmuna-popup-icon">
                <iconify-icon icon="solar:logout-2-bold" width="28"></iconify-icon>
            </div>
            <h3>Konfirmasi Logout</h3>
            <p>Apakah Anda yakin ingin keluar dari sesi akun Ilmuna Learn ini?</p>
            <div class="ilmuna-popup-btns">
                <button type="button" class="ilmuna-btn-cancel" onclick="ilmunaCloseLogout()">Batal</button>
                <a href="<?= BASE_URL ?>/logout.php" class="ilmuna-btn-confirm">Keluar (Logout)</a>
            </div>
        </div>
    </div>

    <!-- ======================================================================
     * UNIVERSAL ACTION CONFIRMATION MODAL
     * ====================================================================== -->
    <div class="ilmuna-modal" id="ilmunaModalConfirm">
        <div class="ilmuna-popup">
            <div class="ilmuna-popup-icon" id="ilmunaConfirmIcon">
                <iconify-icon icon="solar:trash-bin-trash-bold" width="28"></iconify-icon>
            </div>
            <h3 id="ilmunaConfirmTitle">Konfirmasi Tindakan</h3>
            <p id="ilmunaConfirmMessage">Apakah Anda yakin ingin melanjutkan tindakan ini?</p>
            <div class="ilmuna-popup-btns">
                <button type="button" class="ilmuna-btn-cancel" id="ilmunaConfirmCancelBtn" onclick="ilmunaCloseConfirm()">Batal</button>
                <button type="button" class="ilmuna-btn-confirm" id="ilmunaConfirmOkBtn">Ya, Lanjutkan</button>
            </div>
        </div>
    </div>

</div><!-- Close .ilmuna-mhs -->

<script src="<?= BASE_URL ?>/assets/js/lumina-lms.js"></script>
<script src="<?= BASE_URL ?>/assets/js/alert.js"></script>
<?php require_once BASE_PATH . '/includes/alert.php'; ilmuna_render_alerts(); ?>

<!-- Core Interactive Client Script (BNB-25 Notch Animator + Guardian Repositioner) -->
<script>
// Top Loader Bar Simulation
(function() {
    var bar = document.getElementById('ilmunaLoaderBar');
    if (!bar) return;
    bar.style.width = '30%';
    setTimeout(function() { bar.style.width = '70%'; }, 150);
    window.addEventListener('load', function() {
        bar.style.width = '100%';
        setTimeout(function() {
            bar.style.opacity = '0';
            setTimeout(function() { bar.style.width = '0%'; }, 300);
        }, 200);
    });
})();

// Luxury Loader Dismiss
window.addEventListener('load', function() {
    var overlay = document.getElementById('ilmunaLoaderOverlay');
    if (overlay) overlay.classList.remove('active');
});

// ---- BNB-25 Sliding Subtract Notch & Inner-Pointer Animator ----
var _ilmunaNotchX   = null;
var _ilmunaNotchRaf = null;

function ilmunaGetTargetCenterX(itemEl) {
    var nav = document.getElementById('ilmunaBottomNav');
    if (!nav || !itemEl) return 48;
    var navRect  = nav.getBoundingClientRect();
    var itemRect = itemEl.getBoundingClientRect();
    return (itemRect.left - navRect.left) + (itemRect.width / 2);
}

function ilmunaMoveNotchToItem(itemEl, animate) {
    var nav     = document.getElementById('ilmunaBottomNav');
    var pointer = document.getElementById('ilmunaBnbPointer');
    if (!nav || !itemEl) return;

    var targetX = ilmunaGetTargetCenterX(itemEl);

    if (!animate || _ilmunaNotchX === null) {
        _ilmunaNotchX = targetX;
        nav.style.setProperty('--notch-x', targetX + 'px');
        return;
    }

    if (_ilmunaNotchRaf) cancelAnimationFrame(_ilmunaNotchRaf);
    if (pointer) pointer.classList.add('is-moving');

    var startX    = _ilmunaNotchX;
    var diffX     = targetX - startX;
    var duration  = 340;
    var startTime = performance.now();

    function step(now) {
        var elapsed = now - startTime;
        var t       = Math.min(1, elapsed / duration);
        // Ease-out back/cubic untuk efek pegas halus ala Figma BNB-25
        var eased   = 1 - Math.pow(1 - t, 3);
        _ilmunaNotchX = startX + (diffX * eased);
        nav.style.setProperty('--notch-x', _ilmunaNotchX.toFixed(2) + 'px');

        if (t < 1) {
            _ilmunaNotchRaf = requestAnimationFrame(step);
        } else {
            _ilmunaNotchX = targetX;
            nav.style.setProperty('--notch-x', targetX + 'px');
            if (pointer) pointer.classList.remove('is-moving');
        }
    }

    _ilmunaNotchRaf = requestAnimationFrame(step);
}

function ilmunaInitBnb25Nav() {
    var nav = document.getElementById('ilmunaBottomNav');
    if (!nav) return;

    var links = nav.querySelectorAll('a.ilmuna-nav-item');
    if (!links.length) return;

    var activeLink  = nav.querySelector('a.ilmuna-nav-item.active');
    var currentPath = window.location.pathname.toLowerCase();

    if (!activeLink) {
        links.forEach(function(link) {
            try {
                var linkPath = new URL(link.href, window.location.origin).pathname.toLowerCase();
                if (linkPath === currentPath || (currentPath.endsWith('/') && linkPath.endsWith('index.php'))) {
                    activeLink = link;
                }
            } catch (e) {}
        });
        if (!activeLink) activeLink = links[0];
        activeLink.classList.add('active');
    }

    ilmunaMoveNotchToItem(activeLink, false);

    // Animasi geser saat item menu utama diklik
    links.forEach(function(link) {
        if (link.hasAttribute('data-bnb-bound')) return;
        link.setAttribute('data-bnb-bound', '1');
        link.addEventListener('click', function() {
            links.forEach(function(l) { l.classList.remove('active'); });
            link.classList.add('active');
            ilmunaMoveNotchToItem(link, true);
        });
    });
}

// ---- Posisikan Kontainer Utama Badge Guardian di Atas Bottom Nav ----
function ilmunaPositionGuardianBadge() {
    var isMobile = window.innerWidth <= 768;
    var sheet    = document.getElementById('ilmunaSheet');
    var modal1   = document.getElementById('ilmunaModalLogout');
    var modal2   = document.getElementById('ilmunaModalConfirm');
    var uiOpen   = (sheet && sheet.classList.contains('active')) ||
                   (modal1 && modal1.classList.contains('active')) ||
                   (modal2 && modal2.classList.contains('active'));

    var excludedIds = {
        ilmunaBottomNav: true,
        ilmunaSheet: true,
        ilmunaOverlay: true,
        ilmunaModalLogout: true,
        ilmunaModalConfirm: true,
        ilmunaLoaderOverlay: true,
        ilmunaLoaderBar: true
    };

    var candidates = document.body.children;
    for (var i = 0; i < candidates.length; i++) {
        var el = candidates[i];
        if (!el || excludedIds[el.id] || el.tagName === 'SCRIPT' || el.tagName === 'STYLE') continue;

        var style = window.getComputedStyle(el);
        if (style.position === 'fixed') {
            var rect = el.getBoundingClientRect();
            var isBottomRightBadge = (window.innerHeight - rect.bottom <= 95) &&
                                     (window.innerWidth - rect.right <= 95) &&
                                     rect.width > 16 && rect.width < 260 &&
                                     rect.height > 16 && rect.height < 120;

            if (isBottomRightBadge || el.hasAttribute('data-ilmuna-badge')) {
                el.setAttribute('data-ilmuna-badge', '1');
                if (isMobile) {
                    el.style.setProperty('bottom', 'calc(92px + env(safe-area-inset-bottom, 0px))', 'important');
                    el.style.setProperty('right', '14px', 'important');
                    el.style.setProperty('z-index', '1190', 'important');
                    el.style.setProperty('transition', 'bottom 0.25s ease, opacity 0.2s ease', 'important');
                    if (uiOpen) {
                        el.style.setProperty('opacity', '0', 'important');
                        el.style.setProperty('pointer-events', 'none', 'important');
                    } else {
                        el.style.setProperty('opacity', '1', 'important');
                        el.style.setProperty('pointer-events', 'auto', 'important');
                    }
                } else {
                    el.style.removeProperty('bottom');
                    el.style.removeProperty('right');
                    el.style.setProperty('opacity', '1', 'important');
                    el.style.setProperty('pointer-events', 'auto', 'important');
                }
            }
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    ilmunaInitBnb25Nav();
    ilmunaPositionGuardianBadge();
});

window.addEventListener('load', function() {
    ilmunaInitBnb25Nav();
    ilmunaPositionGuardianBadge();
    setTimeout(function() {
        ilmunaInitBnb25Nav();
        ilmunaPositionGuardianBadge();
    }, 250);
});

window.addEventListener('resize', function() {
    var activeLink = document.querySelector('#ilmunaBottomNav a.ilmuna-nav-item.active');
    if (activeLink) ilmunaMoveNotchToItem(activeLink, false);
    ilmunaPositionGuardianBadge();
});

if (window.MutationObserver) {
    var _badgeObs = new MutationObserver(function() {
        ilmunaPositionGuardianBadge();
    });
    _badgeObs.observe(document.body, { childList: true, subtree: false });
}

// ---- Bottom Sheet Handlers ----
function ilmunaToggleSheet() {
    var sheet   = document.getElementById('ilmunaSheet');
    var overlay = document.getElementById('ilmunaOverlay');
    var dots    = document.getElementById('ilmunaMenuDots');
    var btn     = document.getElementById('ilmunaMenuBtn');
    if (!sheet || !overlay) return;

    var willOpen = !sheet.classList.contains('active');
    sheet.classList.toggle('active');
    overlay.classList.toggle('active');
    if (dots) dots.classList.toggle('active', willOpen);
    if (btn)  btn.classList.toggle('active', willOpen);
    ilmunaPositionGuardianBadge();
}

function ilmunaCloseSheet() {
    var sheet   = document.getElementById('ilmunaSheet');
    var overlay = document.getElementById('ilmunaOverlay');
    var dots    = document.getElementById('ilmunaMenuDots');
    var btn     = document.getElementById('ilmunaMenuBtn');
    if (sheet) sheet.classList.remove('active');
    if (overlay) overlay.classList.remove('active');
    if (dots) dots.classList.remove('active');
    if (btn)  btn.classList.remove('active');
    ilmunaPositionGuardianBadge();
}

// ---- Sheet pagination dots ----
(function() {
    var scroller = document.getElementById('ilmunaSheetScroller');
    var dotsWrap = document.getElementById('ilmunaSheetDots');
    if (!scroller || !dotsWrap) return;
    var dots = dotsWrap.querySelectorAll('.ilmuna-sheet-dot');
    if (!dots.length) return;
    scroller.addEventListener('scroll', function() {
        var idx = Math.round(scroller.scrollLeft / scroller.clientWidth);
        dots.forEach(function(d, i) {
            d.classList.toggle('active', i === idx);
        });
    });
})();

// ---- Logout Modal Handlers ----
function ilmunaShowLogout() {
    var modal = document.getElementById('ilmunaModalLogout');
    if (modal) {
        ilmunaCloseSheet();
        modal.classList.add('active');
        ilmunaPositionGuardianBadge();
    }
}

function ilmunaCloseLogout() {
    var modal = document.getElementById('ilmunaModalLogout');
    if (modal) modal.classList.remove('active');
    ilmunaPositionGuardianBadge();
}

// ---- Universal Confirmation Modal Close ----
function ilmunaCloseConfirm() {
    var modal = document.getElementById('ilmunaModalConfirm');
    if (modal) modal.classList.remove('active');
    ilmunaPositionGuardianBadge();
    if (window._currentConfirmResolver) {
        window._currentConfirmResolver(false);
        window._currentConfirmResolver = null;
    }
}

// Close modal / sheet on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ilmunaCloseLogout();
        ilmunaCloseSheet();
        ilmunaCloseConfirm();
    }
});

// Backdrop click closes modals
document.addEventListener('click', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('ilmuna-modal')) {
        e.target.classList.remove('active');
        ilmunaPositionGuardianBadge();
        if (e.target.id === 'ilmunaModalConfirm' && window._currentConfirmResolver) {
            window._currentConfirmResolver(false);
            window._currentConfirmResolver = null;
        }
    }
});
</script>
</body>
</html>