<?php
/**
 * inc/admin_nav.php – Shared top navigation for all admin & member areas.
 * Requires: session_start() already called before include.
 */
$_n_super      = !empty($_SESSION['kgv_admin']);
$_n_member     = $_SESSION['kgv_member'] ?? null;
$_n_roles      = $_n_member['roles'] ?? [];
$_n_isLoggedIn = $_n_super || !empty($_n_member);
$_n_canAdmin   = $_n_super || !empty(array_intersect($_n_roles, ['vorstand','buchung','schriftfuehrer','web']));
$_n_canContent = $_n_super || !empty(array_intersect($_n_roles, ['vorstand','web','schriftfuehrer']));
$_n_hasMember  = !empty($_n_member);
$_n_page       = basename($_SERVER['PHP_SELF']);
$_n_who        = $_n_super ? 'SuperAdmin' : htmlspecialchars($_n_member['name'] ?? '');
$_n_csrf       = htmlspecialchars($_SESSION['csrf'] ?? '');
$_n_onAdmin    = in_array($_n_page, ['index.php', 'content.php'], true);
$_n_isKoppel   = in_array('koppel', $_n_roles, true);

// ── Gemeinschaftsarbeit-Chip ──────────────────────────────────────────────────
$_n_arbeitChip = '';
if ($_n_hasMember) {
    $_n_arbFile = dirname(__DIR__) . '/data/arbeitsstunden.json';
    $_n_ccFile  = dirname(__DIR__) . '/data/content.json';
    $_n_arbAll  = file_exists($_n_arbFile) ? (json_decode((string)file_get_contents($_n_arbFile), true) ?: []) : [];
    $_n_cc      = file_exists($_n_ccFile)  ? (json_decode((string)file_get_contents($_n_ccFile),  true) ?: []) : [];
    $_n_soll    = max(1, (int)(($_n_cc['settings']['arbeit_soll_stunden'] ?? 4)));
    $_n_ayear   = (int)date('Y');
    $_n_memId   = $_n_member['id'] ?? '';
    $_n_ist     = array_sum(array_column(
        array_filter($_n_arbAll[$_n_memId] ?? [], fn($e) => (int)($e['year'] ?? 0) === $_n_ayear),
        'hours'
    ));
    $_n_done    = $_n_ist >= $_n_soll;
    $_n_chipBg  = $_n_done ? 'rgba(46,125,50,.4)' : ($_n_ist > 0 ? 'rgba(230,81,0,.4)' : 'rgba(255,255,255,.1)');
    $_n_arbeitChip = sprintf(
        '<span class="kgv-nav-arbeit" style="display:inline-flex;align-items:center;gap:4px;background:%s;padding:4px 10px;border-radius:20px;font-size:0.75rem;color:#fff;white-space:nowrap" title="Gemeinschaftsarbeit %d: %s von %d Stunden geleistet">🔨 %s/%dh</span>',
        $_n_chipBg, $_n_ayear,
        number_format($_n_ist, 1, ',', '.'), $_n_soll,
        number_format($_n_ist, 1, ',', '.'), $_n_soll
    );
}
?>
<style>
/* ── Desktop Nav ── */
.kgv-nav{background:#3d6b41;display:flex;align-items:center;justify-content:space-between;padding:0 20px;height:54px;position:sticky;top:0;z-index:200;box-shadow:0 2px 10px rgba(0,0,0,0.18);gap:8px}
.kgv-nav-left,.kgv-nav-right{display:flex;align-items:center;gap:2px}
.kgv-nav-left{flex:1;min-width:0}
.kgv-nav-logo{display:flex;align-items:center;gap:9px;text-decoration:none;margin-right:10px;flex-shrink:0}
.kgv-nav-logo img{height:30px;filter:brightness(0) invert(1)}
.kgv-nav-sep{width:1px;height:20px;background:rgba(255,255,255,0.22);margin:0 8px;flex-shrink:0}
.kgv-nav-btn{display:inline-flex;align-items:center;gap:5px;color:rgba(255,255,255,0.75);text-decoration:none;font-size:0.83rem;font-weight:500;padding:7px 13px;border-radius:7px;border:none;background:transparent;cursor:pointer;font-family:inherit;white-space:nowrap;transition:background .15s,color .15s;line-height:1.2}
.kgv-nav-btn:hover{background:rgba(255,255,255,0.12);color:#fff}
.kgv-nav-btn.active{background:rgba(255,255,255,0.2);color:#fff;font-weight:700}
.kgv-nav-user{color:rgba(255,255,255,0.6);font-size:0.78rem;padding:0 8px;white-space:nowrap}

/* ── Hamburger (nur Mobile) ── */
.kgv-hamburger{display:none}

/* ── Mobile Dropdown ── */
.kgv-mobile-menu{
    display:none;
    position:fixed;top:50px;left:0;right:0;
    background:#2d5231;
    z-index:199;
    box-shadow:0 4px 16px rgba(0,0,0,0.25);
    padding:8px 0 12px;
    flex-direction:column;
}
.kgv-mobile-menu.open{display:flex}
.kgv-mobile-menu a,.kgv-mobile-menu button{
    display:flex;align-items:center;gap:10px;
    color:#fff;text-decoration:none;
    padding:13px 20px;font-size:0.95rem;font-weight:500;
    background:transparent;border:none;cursor:pointer;
    font-family:inherit;width:100%;text-align:left;
    border-left:3px solid transparent;
    transition:background .12s,border-color .12s;
}
.kgv-mobile-menu a:hover,.kgv-mobile-menu button:hover{background:rgba(255,255,255,0.08)}
.kgv-mobile-menu a.active{border-left-color:#8fcb93;background:rgba(255,255,255,0.1);color:#fff;font-weight:700}
.kgv-mobile-menu hr{border:none;border-top:1px solid rgba(255,255,255,0.12);margin:6px 16px}
.kgv-mobile-menu .menu-user{padding:10px 20px 4px;font-size:0.78rem;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:.05em}

@media(max-width:680px){
    .kgv-nav-left .kgv-nav-sep,
    .kgv-nav-left .kgv-nav-btn,
    .kgv-nav-right .kgv-nav-arbeit,
    .kgv-nav-right .kgv-nav-user,
    .kgv-nav-right .kgv-nav-sep,
    .kgv-nav-right .kgv-nav-btn:not(.kgv-hamburger){display:none}
    .kgv-nav{padding:0 14px;height:50px}
    .kgv-nav-logo img{height:26px}
    .kgv-hamburger{
        display:inline-flex;align-items:center;justify-content:center;
        width:40px;height:40px;border-radius:8px;
        color:#fff;font-size:1.3rem;
        background:transparent;border:none;cursor:pointer;
        transition:background .15s;
        -webkit-tap-highlight-color:transparent;
    }
    .kgv-hamburger:hover,.kgv-hamburger.open{background:rgba(255,255,255,0.15)}
    .kgv-mobile-menu{top:50px}
}
/* Overlay zum Schließen */
.kgv-menu-overlay{display:none;position:fixed;inset:0;z-index:198}
.kgv-menu-overlay.open{display:block}
</style>

<nav class="kgv-nav" aria-label="Hauptnavigation">
  <div class="kgv-nav-left">
    <a href="<?= $_n_hasMember ? '/mitglieder.php' : '/intern/' ?>" class="kgv-nav-logo">
      <img src="/images/logo.png" alt="unser Verein">
    </a>
    <?php if ($_n_isLoggedIn): ?>
    <div class="kgv-nav-sep"></div>
    <?php if ($_n_hasMember): ?>
    <a href="/mitglieder.php" class="kgv-nav-btn <?= $_n_page === 'mitglieder.php' && ($_GET['tab'] ?? '') !== 'koppel' ? 'active' : '' ?>">👤 Mein Bereich</a>
    <?php endif; ?>
    <?php if ($_n_isKoppel && !$_n_canAdmin): ?>
    <a href="/mitglieder.php?tab=koppel" class="kgv-nav-btn <?= $_n_page === 'mitglieder.php' && ($_GET['tab'] ?? '') === 'koppel' ? 'active' : '' ?>">🔨 Gemeinschaftsarbeit</a>
    <?php endif; ?>
    <?php if ($_n_canAdmin): ?>
    <a href="/intern/" class="kgv-nav-btn <?= $_n_page === 'index.php' && ($_GET['tab'] ?? 'dashboard') !== 'arbeit' ? 'active' : '' ?>">📊 Backoffice</a>
    <?php endif; ?>
    <?php if ($_n_super || in_array('vorstand', $_n_roles, true)): ?>
    <a href="/intern/?tab=arbeit" class="kgv-nav-btn <?= ($_GET['tab'] ?? '') === 'arbeit' ? 'active' : '' ?>">🔨 Gemeinschaftsarbeit</a>
    <?php endif; ?>
    <?php if ($_n_canContent): ?>
    <a href="/intern/content.php" class="kgv-nav-btn <?= $_n_page === 'content.php' ? 'active' : '' ?>">✏️ Website</a>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="kgv-nav-right">
    <?php if ($_n_arbeitChip !== ''): ?>
    <?= $_n_arbeitChip ?>
    <?php endif; ?>
    <?php if ($_n_isLoggedIn && $_n_who !== ''): ?>
    <span class="kgv-nav-user"><?= $_n_who ?></span>
    <div class="kgv-nav-sep"></div>
    <?php endif; ?>
    <a href="/" target="_blank" class="kgv-nav-btn" title="Website ansehen">🌐</a>
    <?php if ($_n_isLoggedIn): ?>
    <?php if ($_n_onAdmin): ?>
    <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" style="margin:0">
      <input type="hidden" name="csrf" value="<?= $_n_csrf ?>">
      <button name="logout" value="1" class="kgv-nav-btn">Abmelden</button>
    </form>
    <?php else: ?>
    <a href="/member-api/logout.php" class="kgv-nav-btn">Abmelden</a>
    <?php endif; ?>
    <?php endif; ?>
    <!-- Hamburger Button (nur Mobile) -->
    <button class="kgv-hamburger kgv-nav-btn" id="kgv-hamburger" aria-label="Menü öffnen" aria-expanded="false">☰</button>
  </div>
</nav>

<!-- Mobile Dropdown-Menü -->
<div class="kgv-menu-overlay" id="kgv-menu-overlay"></div>
<div class="kgv-mobile-menu" id="kgv-mobile-menu" role="menu">
  <?php if ($_n_isLoggedIn && $_n_who !== ''): ?>
  <div class="menu-user"><?= $_n_who ?></div>
  <?php endif; ?>
  <?php if ($_n_hasMember): ?>
  <a href="/mitglieder.php" class="<?= $_n_page === 'mitglieder.php' && ($_GET['tab'] ?? '') !== 'koppel' ? 'active' : '' ?>">👤 Mein Bereich</a>
  <?php endif; ?>
  <?php if ($_n_isKoppel && !$_n_canAdmin): ?>
  <a href="/mitglieder.php?tab=koppel" class="<?= $_n_page === 'mitglieder.php' && ($_GET['tab'] ?? '') === 'koppel' ? 'active' : '' ?>">🔨 Gemeinschaftsarbeit</a>
  <?php endif; ?>
  <?php if ($_n_canAdmin): ?>
  <a href="/intern/" class="<?= $_n_page === 'index.php' && ($_GET['tab'] ?? 'dashboard') !== 'arbeit' ? 'active' : '' ?>">📊 Backoffice</a>
  <?php endif; ?>
  <?php if ($_n_super || in_array('vorstand', $_n_roles, true)): ?>
  <a href="/intern/?tab=arbeit" class="<?= ($_GET['tab'] ?? '') === 'arbeit' ? 'active' : '' ?>">🔨 Gemeinschaftsarbeit</a>
  <?php endif; ?>
  <?php if ($_n_canContent): ?>
  <a href="/intern/content.php" class="<?= $_n_page === 'content.php' ? 'active' : '' ?>">✏️ Website bearbeiten</a>
  <?php endif; ?>
  <?php if ($_n_arbeitChip !== ''): ?>
  <hr>
  <div style="padding:8px 20px"><?= $_n_arbeitChip ?></div>
  <?php endif; ?>
  <hr>
  <a href="/" target="_blank">🌐 Website ansehen</a>
  <?php if ($_n_isLoggedIn): ?>
  <?php if ($_n_onAdmin): ?>
  <hr>
  <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" style="margin:0">
    <input type="hidden" name="csrf" value="<?= $_n_csrf ?>">
    <button name="logout" value="1">🚪 Abmelden</button>
  </form>
  <?php else: ?>
  <hr>
  <a href="/member-api/logout.php">🚪 Abmelden</a>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
(function(){
    const btn     = document.getElementById('kgv-hamburger');
    const menu    = document.getElementById('kgv-mobile-menu');
    const overlay = document.getElementById('kgv-menu-overlay');
    if (!btn) return;
    function toggle(open) {
        btn.classList.toggle('open', open);
        btn.setAttribute('aria-expanded', open);
        btn.textContent = open ? '✕' : '☰';
        menu.classList.toggle('open', open);
        overlay.classList.toggle('open', open);
    }
    btn.addEventListener('click', () => toggle(!menu.classList.contains('open')));
    overlay.addEventListener('click', () => toggle(false));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') toggle(false); });
})();
</script>
