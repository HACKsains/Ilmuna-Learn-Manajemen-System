<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MANAJEMEN FORMULIR PUBLIK TER-HASH (ADMIN)
 * ==============================================================================
 * Mengelola tautan publik bergaya Google Form (tanpa login, dilindungi hash):
 *   1. Formulir Pendataan Siswa + Orang Tua / Wali (1 form gabungan)
 *   2. Formulir Pendataan Guru & Tenaga Pendidik
 * Fitur: Salin Link, Buka Form, Acak Ulang Token Hash, Buka/Tutup Akses,
 *        Edit Judul & Deskripsi, Pantau Jumlah Pengisi (100% AJAX).
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../includes/public-form-engine.php';

requireRole('admin');

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
ilmuna_ensure_public_form_links($pdo);

// ==============================================================================
// AJAX API (?api=1)
// ==============================================================================
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_REQUEST['action'] ?? '';

    try {
        if ($act === 'list') {
            $rows = $pdo->query("SELECT * FROM public_form_links ORDER BY FIELD(form_type, 'siswa_ortu', 'guru')")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $slug = ($r['form_type'] === 'guru') ? 'form-guru' : 'form-siswa';
                $r['public_url'] = BASE_URL . '/' . $slug . '?h=' . $r['token_hash'];
                $r['is_active'] = (int)$r['is_active'];
                $r['submission_count'] = (int)$r['submission_count'];
            }
            unset($r);
            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        if ($act === 'toggle_active') {
            $type = trim((string)($_POST['form_type'] ?? ''));
            if (!in_array($type, ['siswa_ortu', 'guru'], true)) {
                echo json_encode(['success' => false, 'message' => 'Tipe formulir tidak valid.']);
                exit;
            }
            $st = $pdo->prepare("SELECT is_active FROM public_form_links WHERE form_type = ? LIMIT 1");
            $st->execute([$type]);
            $cur = $st->fetchColumn();
            if ($cur === false) {
                echo json_encode(['success' => false, 'message' => 'Data formulir tidak ditemukan.']);
                exit;
            }
            $next = ((int)$cur === 1) ? 0 : 1;
            $pdo->prepare("UPDATE public_form_links SET is_active = ? WHERE form_type = ?")->execute([$next, $type]);
            echo json_encode([
                'success'   => true,
                'is_active' => $next,
                'message'   => $next ? 'Formulir publik dibuka kembali.' : 'Formulir publik berhasil ditutup sementara.',
            ]);
            exit;
        }

        if ($act === 'regenerate_hash') {
            $type = trim((string)($_POST['form_type'] ?? ''));
            if (!in_array($type, ['siswa_ortu', 'guru'], true)) {
                echo json_encode(['success' => false, 'message' => 'Tipe formulir tidak valid.']);
                exit;
            }
            $newHash = substr(hash('sha256', $type . '|' . bin2hex(random_bytes(24)) . '|' . microtime(true)), 0, 32);
            $pdo->prepare("UPDATE public_form_links SET token_hash = ? WHERE form_type = ?")->execute([$newHash, $type]);
            $slug = ($type === 'guru') ? 'form-guru' : 'form-siswa';
            echo json_encode([
                'success'    => true,
                'token_hash' => $newHash,
                'public_url' => BASE_URL . '/' . $slug . '?h=' . $newHash,
                'message'    => 'Token hash berhasil diacak ulang. Tautan lama otomatis tidak berlaku lagi.',
            ]);
            exit;
        }

        if ($act === 'update_meta') {
            $type  = trim((string)($_POST['form_type'] ?? ''));
            $title = trim((string)($_POST['title'] ?? ''));
            $desc  = trim((string)($_POST['description'] ?? ''));
            if (!in_array($type, ['siswa_ortu', 'guru'], true)) {
                echo json_encode(['success' => false, 'message' => 'Tipe formulir tidak valid.']);
                exit;
            }
            if ($title === '') {
                echo json_encode(['success' => false, 'message' => 'Judul formulir wajib diisi.']);
                exit;
            }
            $pdo->prepare("UPDATE public_form_links SET title = ?, description = ? WHERE form_type = ?")
                ->execute([$title, $desc !== '' ? $desc : null, $type]);
            echo json_encode(['success' => true, 'message' => 'Judul & deskripsi formulir berhasil diperbarui.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Kesalahan server: ' . $e->getMessage()]);
    }
    exit;
}

$pageTitle    = 'Formulir Publik (Hash Link)';
$currentRoute = 'public-forms';
$activeRole   = 'admin';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<style>
    .pf-wrap { display: flex; flex-direction: column; gap: 1rem; }
    .pf-toolbar {
        display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
        gap: 0.75rem; background: #fff; border: 1px solid #E2E8F0; border-radius: 14px;
        padding: 0.85rem 1.1rem; box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
    }
    .pf-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
    .pf-card {
        background: #fff; border: 1px solid #E2E8F0; border-radius: 16px;
        overflow: hidden; box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
        display: flex; flex-direction: column;
    }
    .pf-card-accent { height: 5px; background: linear-gradient(90deg, #E2B03F, #C99726); }
    .pf-card-accent.guru { background: linear-gradient(90deg, #0D7A87, #0F766E); }
    .pf-card-body { padding: 1.15rem 1.25rem; display: flex; flex-direction: column; gap: 0.85rem; flex: 1; }
    .pf-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; }
    .pf-icon-box {
        width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A;
    }
    .pf-icon-box.guru { background: #F0FDFA; color: #0F766E; border-color: #99F6E4; }
    .pf-title { font-size: 0.98rem; font-weight: 800; color: #0F172A; line-height: 1.3; }
    .pf-desc { font-size: 0.8rem; color: #64748B; line-height: 1.5; margin-top: 0.25rem; }
    .pf-status {
        display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.24rem 0.65rem;
        border-radius: 999px; font-size: 0.72rem; font-weight: 700; white-space: nowrap;
    }
    .pf-status.open { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
    .pf-status.closed { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }
    .pf-meta-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.45rem; }
    .pf-chip {
        display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.72rem; font-weight: 700;
        padding: 0.22rem 0.6rem; border-radius: 8px; background: #F8FAFC; color: #475569;
        border: 1px solid #E2E8F0;
    }
    .pf-chip.gold { background: #FFFBEB; color: #B45309; border-color: #FDE68A; }
    .pf-url-box {
        display: flex; align-items: center; gap: 0.45rem; background: #F8FAFC;
        border: 1px solid #CBD5E1; border-radius: 10px; padding: 0.45rem 0.6rem;
    }
    .pf-url-input {
        flex: 1; min-width: 0; border: none; background: transparent; outline: none;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 0.76rem; color: #1E293B; font-weight: 600;
    }
    .pf-actions {
        display: flex; flex-wrap: wrap; gap: 0.45rem; padding-top: 0.75rem;
        border-top: 1px solid #F1F5F9; margin-top: auto;
    }
    .pf-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem;
        padding: 0.48rem 0.85rem; border-radius: 9px; font-size: 0.78rem; font-weight: 700;
        border: 1px solid #E2E8F0; background: #fff; color: #334155; cursor: pointer;
        text-decoration: none; transition: all .15s;
    }
    .pf-btn:hover { background: #F8FAFC; border-color: #CBD5E1; }
    .pf-btn-gold {
        background: linear-gradient(135deg, #E2B03F, #C99726); color: #fff; border-color: #C99726;
    }
    .pf-btn-gold:hover { filter: brightness(1.04); color: #fff; }
    .pf-btn-warn { background: #FFFBEB; color: #B45309; border-color: #FDE68A; }
    .pf-btn-warn:hover { background: #FEF3C7; }
    .pf-btn-danger { background: #FEF2F2; color: #B91C1C; border-color: #FECACA; }
    .pf-btn-danger:hover { background: #FEE2E2; }
    .pf-btn-ok { background: #ECFDF5; color: #047857; border-color: #A7F3D0; }
    .pf-btn-ok:hover { background: #D1FAE5; }

    /* Form input inside Universal Ilmuna Modal */
    .pf-label { display: block; font-size: 0.78rem; font-weight: 700; color: #334155; margin-bottom: 0.3rem; }
    .pf-input, .pf-textarea {
        width: 100%; border: 1.5px solid #CBD5E1; border-radius: 10px; padding: 0.6rem 0.8rem;
        font-size: 0.84rem; color: #0F172A; font-family: inherit; outline: none;
    }
    .pf-input:focus, .pf-textarea:focus { border-color: #0D7A87; box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.14); }

    @media (max-width: 960px) {
        .pf-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="main-content">
    <div class="pf-wrap">
        <div class="pf-toolbar">
            <div style="display:flex;align-items:center;gap:0.65rem;flex-wrap:wrap;">
                <span class="pf-chip gold">
                    <iconify-icon icon="solar:shield-keyhole-bold" width="15"></iconify-icon>
                    Proteksi Token Hash SHA-256 (32 Karakter)
                </span>
                <span class="pf-chip">
                    <iconify-icon icon="solar:database-bold" width="15"></iconify-icon>
                    Auto-Mapping Langsung ke Database
                </span>
            </div>
            <button type="button" class="pf-btn" onclick="loadPublicForms()">
                <iconify-icon icon="solar:refresh-bold" width="15"></iconify-icon>
                Muat Ulang
            </button>
        </div>

        <div class="pf-grid" id="pfGrid">
            <div class="pf-card"><div class="pf-card-body" style="color:#64748B;font-size:0.84rem;">Memuat tautan formulir publik...</div></div>
        </div>
    </div>
</div>

<!-- Universal Popup Modal Template (README.md §2.2) -->
<?php if (function_exists('loadCentralTemplates')) { loadCentralTemplates(['modal']); } ?>
<div class="ilm-modal-overlay" id="pfEditModal" role="dialog" aria-modal="true">
    <div class="ilm-modal-card" style="max-width: 540px;">
        <div class="ilm-modal-gunungan" aria-hidden="true"></div>
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__left">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:pen-new-square-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title">Ubah Judul &amp; Deskripsi Formulir</h3>
                    <p class="ilm-modal-header__subtitle">Sesuaikan teks tajuk dan petunjuk pengisian formulir publik</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeEditModal()" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <div class="ilm-modal-body" style="display:flex;flex-direction:column;gap:0.9rem;">
            <input type="hidden" id="editFormType">
            <div>
                <label class="pf-label" for="editTitle">Judul Formulir</label>
                <input type="text" id="editTitle" class="pf-input" maxlength="160">
            </div>
            <div>
                <label class="pf-label" for="editDesc">Deskripsi / Petunjuk Pengisian</label>
                <textarea id="editDesc" class="pf-textarea" rows="4"></textarea>
            </div>
        </div>
        <div class="ilm-modal-footer">
            <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeEditModal()">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                <span>Batal</span>
            </button>
            <button type="button" class="ilm-modal-btn ilm-modal-btn--primary" id="btnSaveMeta" onclick="saveFormMeta()">
                <iconify-icon icon="solar:disk-bold"></iconify-icon>
                <span>Simpan Perubahan</span>
            </button>
        </div>
    </div>
</div>

<script>
const PF_API = <?= json_encode(BASE_URL . '/dashboard/admin/public-forms?api=1') ?>;
let pfRows = [];

function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

async function pfFetch(url, opts = {}) {
    opts.headers = Object.assign({'X-Requested-With': 'XMLHttpRequest'}, opts.headers || {});
    const res = await fetch(url, opts);
    return res.json();
}

async function loadPublicForms() {
    try {
        const j = await pfFetch(PF_API + '&action=list');
        if (!j.success) {
            window.Ilmuna.toast(j.message || 'Gagal memuat data.', 'error');
            return;
        }
        pfRows = j.data || [];
        renderPublicForms();
    } catch (e) {
        window.Ilmuna.toast('Koneksi gagal saat memuat formulir publik.', 'error');
    }
}

function renderPublicForms() {
    const grid = document.getElementById('pfGrid');
    if (!pfRows.length) {
        grid.innerHTML = '<div class="pf-card"><div class="pf-card-body">Belum ada data formulir.</div></div>';
        return;
    }

    grid.innerHTML = pfRows.map(r => {
        const isGuru = r.form_type === 'guru';
        const icon = isGuru ? 'solar:user-speak-bold' : 'solar:users-group-two-rounded-bold';
        const tables = isGuru
            ? 'guru + guru_mata_pelajaran'
            : 'siswa + orang_tua + orang_tua_siswa';
        const open = r.is_active === 1;

        return `
        <div class="pf-card">
            <div class="pf-card-accent ${isGuru ? 'guru' : ''}"></div>
            <div class="pf-card-body">
                <div class="pf-card-head">
                    <div style="display:flex;gap:0.75rem;align-items:flex-start;">
                        <div class="pf-icon-box ${isGuru ? 'guru' : ''}">
                            <iconify-icon icon="${icon}" width="22"></iconify-icon>
                        </div>
                        <div>
                            <div class="pf-title">${escHtml(r.title)}</div>
                            <div class="pf-desc">${escHtml(r.description || '')}</div>
                        </div>
                    </div>
                    <span class="pf-status ${open ? 'open' : 'closed'}">
                        <iconify-icon icon="${open ? 'solar:check-circle-bold' : 'solar:lock-keyhole-bold'}" width="14"></iconify-icon>
                        ${open ? 'Dibuka' : 'Ditutup'}
                    </span>
                </div>

                <div class="pf-meta-row">
                    <span class="pf-chip gold">
                        <iconify-icon icon="solar:document-add-bold" width="14"></iconify-icon>
                        ${r.submission_count} Pengisi Masuk
                    </span>
                    <span class="pf-chip">
                        <iconify-icon icon="solar:server-minimalistic-bold" width="14"></iconify-icon>
                        Tabel: ${tables}
                    </span>
                </div>

                <div class="pf-url-box">
                    <iconify-icon icon="solar:link-round-bold" width="16" style="color:#B45309;flex-shrink:0;"></iconify-icon>
                    <input type="text" class="pf-url-input" readonly value="${escHtml(r.public_url)}" onclick="this.select()">
                    <button type="button" class="pf-btn pf-btn-gold" style="padding:0.35rem 0.65rem;font-size:0.74rem;" onclick="copyLink('${escHtml(r.public_url)}')">
                        <iconify-icon icon="solar:copy-bold" width="14"></iconify-icon>
                        Salin
                    </button>
                </div>

                <div class="pf-actions">
                    <a href="${escHtml(r.public_url)}" target="_blank" rel="noopener" class="pf-btn">
                        <iconify-icon icon="solar:square-top-down-bold" width="15"></iconify-icon>
                        Buka Form
                    </a>
                    <button type="button" class="pf-btn" onclick="openEditModal('${r.form_type}')">
                        <iconify-icon icon="solar:pen-new-square-bold" width="15"></iconify-icon>
                        Edit Teks
                    </button>
                    <button type="button" class="pf-btn pf-btn-warn" onclick="regenerateHash('${r.form_type}')">
                        <iconify-icon icon="solar:refresh-circle-bold" width="15"></iconify-icon>
                        Acak Ulang Hash
                    </button>
                    <button type="button" class="pf-btn ${open ? 'pf-btn-danger' : 'pf-btn-ok'}" onclick="toggleFormActive('${r.form_type}', ${open ? 1 : 0})">
                        <iconify-icon icon="${open ? 'solar:lock-bold' : 'solar:lock-unlocked-bold'}" width="15"></iconify-icon>
                        ${open ? 'Tutup Form' : 'Buka Form'}
                    </button>
                </div>
            </div>
        </div>`;
    }).join('');
}

async function copyLink(url) {
    try {
        await navigator.clipboard.writeText(url);
        window.Ilmuna.toast('Tautan formulir ter-hash berhasil disalin!', 'success');
    } catch (e) {
        const ta = document.createElement('textarea');
        ta.value = url;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        window.Ilmuna.toast('Tautan formulir berhasil disalin!', 'success');
    }
}

async function toggleFormActive(formType, currentlyOpen) {
    const ok = await window.Ilmuna.confirm({
        title: currentlyOpen ? 'Tutup Formulir Publik?' : 'Buka Kembali Formulir Publik?',
        message: currentlyOpen
            ? 'Pengunjung yang membuka tautan tidak akan bisa mengisi formulir selama ditutup.'
            : 'Formulir akan kembali dapat diisi melalui tautan hash yang aktif.',
        confirmText: currentlyOpen ? 'Ya, Tutup' : 'Ya, Buka',
        variant: currentlyOpen ? 'danger' : 'primary',
    });
    if (!ok) return;

    const fd = new FormData();
    fd.append('action', 'toggle_active');
    fd.append('form_type', formType);
    const j = await pfFetch(PF_API, { method: 'POST', body: fd });
    window.Ilmuna.toast(j.message || (j.success ? 'Berhasil.' : 'Gagal.'), j.success ? 'success' : 'error');
    if (j.success) loadPublicForms();
}

async function regenerateHash(formType) {
    const ok = await window.Ilmuna.confirm({
        title: 'Acak Ulang Token Hash?',
        message: 'Tautan lama akan langsung tidak berlaku. Anda perlu membagikan tautan hash yang baru kepada siswa/orang tua atau guru.',
        confirmText: 'Ya, Acak Ulang',
        variant: 'warning',
    });
    if (!ok) return;

    const fd = new FormData();
    fd.append('action', 'regenerate_hash');
    fd.append('form_type', formType);
    const j = await pfFetch(PF_API, { method: 'POST', body: fd });
    window.Ilmuna.toast(j.message || (j.success ? 'Hash diperbarui.' : 'Gagal.'), j.success ? 'success' : 'error');
    if (j.success) loadPublicForms();
}

function openEditModal(formType) {
    const row = pfRows.find(x => x.form_type === formType);
    if (!row) return;
    document.getElementById('editFormType').value = row.form_type;
    document.getElementById('editTitle').value = row.title || '';
    document.getElementById('editDesc').value = row.description || '';
    if (typeof openIlmunaModal === 'function') openIlmunaModal('pfEditModal');
}

function closeEditModal() {
    if (typeof closeIlmunaModal === 'function') closeIlmunaModal('pfEditModal');
}

async function saveFormMeta() {
    const btn = document.getElementById('btnSaveMeta');
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('action', 'update_meta');
        fd.append('form_type', document.getElementById('editFormType').value);
        fd.append('title', document.getElementById('editTitle').value.trim());
        fd.append('description', document.getElementById('editDesc').value.trim());
        const j = await pfFetch(PF_API, { method: 'POST', body: fd });
        window.Ilmuna.toast(j.message || (j.success ? 'Tersimpan.' : 'Gagal.'), j.success ? 'success' : 'error');
        if (j.success) {
            closeEditModal();
            loadPublicForms();
        }
    } finally {
        btn.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', loadPublicForms);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
