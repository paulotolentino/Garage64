<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$error   = '';
$success = false;

// Load current user data
$user = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
$user->execute([current_user_id()]);
$user = $user->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $display_name = trim($_POST['display_name'] ?? '');
    $bio          = trim($_POST['bio'] ?? '');
    $new_slug     = strtolower(trim($_POST['slug'] ?? $user['slug'] ?? ''));
    $new_email    = strtolower(trim($_POST['email'] ?? ''));
    $current_pass = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    // ─── Slug validation ──────────────────────────────────────────────────────
    if (!preg_match('/^[a-z0-9_-]{2,30}$/', $new_slug) || is_reserved_slug($new_slug)) {
        $error = 'Slug inválido (2–30 chars: letras minúsculas, números, _ e -) ou reservado.';
    } else {
        $chk = db()->prepare('SELECT id FROM admin_users WHERE slug = ? AND id != ?');
        $chk->execute([$new_slug, current_user_id()]);
        if ($chk->fetch()) $error = 'Este slug já está em uso.';
    }

    // ─── Email validation ─────────────────────────────────────────────────────
    if (!$error) {
        if ($new_email === '') {
            $error = 'O e-mail é obrigatório.';
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'E-mail inválido.';
        } else {
            $chk = db()->prepare('SELECT id FROM admin_users WHERE email = ? AND id != ?');
            $chk->execute([$new_email, current_user_id()]);
            if ($chk->fetch()) $error = 'Este e-mail já está em uso.';
        }
    }

    // ─── Avatar upload ───────────────────────────────────────────────────────
    $avatar = $user['avatar']; // keep existing by default

    if (!$error && !empty($_FILES['avatar']['tmp_name'])) {
        $file    = $_FILES['avatar'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($file['tmp_name']);

        if (!in_array($mime, $allowed, true)) {
            $error = 'Formato de imagem inválido. Use JPG, PNG, WebP ou GIF.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $error = 'A imagem deve ter no máximo 5 MB.';
        } else {
            $avatars_dir = UPLOADS_DIR . 'avatars/';
            if (!is_dir($avatars_dir)) {
                mkdir($avatars_dir, 0755, true);
            }

            $filename = current_user_id() . '.webp';
            $dest     = $avatars_dir . $filename;

            $image = match ($mime) {
                'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
                'image/png'  => imagecreatefrompng($file['tmp_name']),
                'image/gif'  => imagecreatefromgif($file['tmp_name']),
                default      => imagecreatefromstring(file_get_contents($file['tmp_name'])),
            };

            if ($image) {
                // Crop to square and resize to 200x200
                $src_w = imagesx($image);
                $src_h = imagesy($image);
                $side  = min($src_w, $src_h);
                $src_x = (int)(($src_w - $side) / 2);
                $src_y = (int)(($src_h - $side) / 2);

                $thumb = imagecreatetruecolor(200, 200);
                imagecopyresampled($thumb, $image, 0, 0, $src_x, $src_y, 200, 200, $side, $side);
                imagewebp($thumb, $dest, 85);
                imagedestroy($image);
                imagedestroy($thumb);
                $avatar = $filename;
            } else {
                $error = 'Não foi possível processar a imagem.';
            }
        }
    }

    // ─── Password change ─────────────────────────────────────────────────────
    $hash = null;
    if (!$error && $new_password !== '') {
        if (!password_verify($current_pass, $user['password_hash'])) {
            $error = 'A senha atual está incorreta.';
        } elseif (strlen($new_password) < 8) {
            $error = 'A nova senha deve ter no mínimo 8 caracteres.';
        } elseif ($new_password !== $confirm_pass) {
            $error = 'As senhas não coincidem.';
        } else {
            $hash = password_hash($new_password, PASSWORD_BCRYPT);
        }
    }

    if (!$error) {
        if ($hash) {
            db()->prepare(
                'UPDATE admin_users SET display_name = ?, bio = ?, avatar = ?, slug = ?, email = ?, password_hash = ? WHERE id = ?'
            )->execute([$display_name, $bio ?: null, $avatar ?: null, $new_slug, $new_email, $hash, current_user_id()]);
        } else {
            db()->prepare(
                'UPDATE admin_users SET display_name = ?, bio = ?, avatar = ?, slug = ?, email = ? WHERE id = ?'
            )->execute([$display_name, $bio ?: null, $avatar ?: null, $new_slug, $new_email, current_user_id()]);
        }
        // Refresh session
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['user_slug']      = $new_slug;
        $success = true;
        // Reload user data
        $stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
        $stmt->execute([current_user_id()]);
        $user = $stmt->fetch();
    }
}

// ─── Identidade e estatísticas do colecionador ────────────────────────────────
$uid          = current_user_id();
$prof_slug    = $user['slug'] ?? $user['username'];
$prof_name    = $user['display_name'] ?: $user['username'];
$prof_initial = mb_strtoupper(mb_substr($prof_name, 0, 1));
$prof_since   = !empty($user['created_at']) ? date('Y', strtotime($user['created_at'])) : '';
$prof_pub_url = rtrim(APP_URL, '/') . '/u/' . rawurlencode($prof_slug);

$stat_minis  = count_miniatures(['user_id' => $uid]);
$stat_brands = count(get_distinct_manufacturers($uid));
$stat_scales = count(get_distinct_scales($uid));
$stat_wish   = count(get_wishlist('', $uid));

$page_title = 'Meu Perfil';
require_once __DIR__ . '/../includes/header_admin.php';
?>

<!-- Hero ──────────────────────────────────────────────────────────────── -->
<section class="dash-hero profile-hero">
    <div class="dash-hero-id">
        <div class="dash-hero-avatar profile-hero-avatar">
            <?php if (!empty($user['avatar'])): ?>
                <img src="<?= e(avatar_url($user['avatar'])) ?>" alt="<?= e($prof_name) ?>">
            <?php else: ?>
                <span class="profile-hero-initial"><?= e($prof_initial) ?></span>
            <?php endif; ?>
        </div>
        <div class="dash-hero-text">
            <div class="lp-eyebrow">Meu perfil</div>
            <h1 class="dash-hero-name"><?= e($prof_name) ?></h1>
            <div class="dash-hero-handle">
                @<?= e($prof_slug) ?><?= $prof_since ? ' · colecionador desde ' . e($prof_since) : '' ?>
            </div>
        </div>
    </div>
    <div class="dash-hero-actions">
        <a href="<?= e($prof_pub_url) ?>" target="_blank" class="md-btn md-btn-primary"><i class="fa fa-warehouse"></i>Ver garagem pública</a>
        <button type="button" class="md-btn" id="btnCopyLink" data-url="<?= e($prof_pub_url) ?>"><i class="fa fa-link"></i><span>Copiar link</span></button>
    </div>
</section>

<!-- Stats ─────────────────────────────────────────────────────────────── -->
<div class="cp-stats profile-stats">
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($stat_minis) ?></span>
        <span class="cp-stat-lbl">miniatura<?= $stat_minis !== 1 ? 's' : '' ?></span>
    </div>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($stat_brands) ?></span>
        <span class="cp-stat-lbl">fabricante<?= $stat_brands !== 1 ? 's' : '' ?></span>
    </div>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($stat_scales) ?></span>
        <span class="cp-stat-lbl">escala<?= $stat_scales !== 1 ? 's' : '' ?></span>
    </div>
    <div class="cp-stat">
        <span class="cp-stat-num"><?= number_format($stat_wish) ?></span>
        <span class="cp-stat-lbl">na wishlist</span>
    </div>
</div>

<?php if ($success): ?>
    <div class="profile-alert profile-alert-ok"><i class="fa fa-check"></i><span>Perfil atualizado com sucesso.</span></div>
<?php elseif ($error): ?>
    <div class="profile-alert profile-alert-error"><i class="fa fa-circle-exclamation"></i><span><?= e($error) ?></span></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="profile-form">
    <?= csrf_field() ?>

    <!-- Seção 1 — Perfil público ──────────────────────────────────────── -->
    <section class="profile-section">
        <div class="profile-section-head">
            <span class="profile-section-ico"><i class="fa fa-id-badge"></i></span>
            <div>
                <div class="lp-eyebrow">Identidade</div>
                <h2 class="profile-section-title">Perfil público</h2>
            </div>
        </div>
        <div class="profile-section-body">
            <div class="profile-avatar-row">
                <div class="profile-avatar-preview">
                    <?php if (!empty($user['avatar'])): ?>
                        <img id="avatarPreview" src="<?= e(avatar_url($user['avatar'])) ?>" alt="Foto do perfil">
                    <?php else: ?>
                        <img id="avatarPreview" src="<?= e(avatar_url(null)) ?>" alt="Foto do perfil">
                    <?php endif; ?>
                </div>
                <div class="profile-avatar-info">
                    <label for="avatarInput" class="md-btn profile-avatar-btn"><i class="fa fa-camera"></i>Foto do perfil</label>
                    <input type="file" id="avatarInput" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" class="profile-file">
                    <small class="profile-help">JPG, PNG, WebP ou GIF. Máx 5 MB. Recortada em quadrado 200×200 px.</small>
                </div>
            </div>

            <div class="profile-grid">
                <div class="profile-field">
                    <label for="display_name">Nome de exibição</label>
                    <input type="text" id="display_name" name="display_name" class="amf-input"
                           value="<?= e($user['display_name'] ?? '') ?>" placeholder="Como aparece publicamente">
                </div>
                <div class="profile-field">
                    <label for="slug">URL pública (slug)</label>
                    <div class="profile-input-prefix">
                        <span class="profile-prefix">/u/</span>
                        <input type="text" id="slug" name="slug" class="amf-input"
                               value="<?= e($prof_slug) ?>" pattern="[a-z0-9_\-]{2,30}" required>
                    </div>
                    <small class="profile-help">2–30 caracteres: letras minúsculas, números, _ e -.</small>
                </div>
                <div class="profile-field profile-field-wide">
                    <label for="bio">Bio</label>
                    <textarea id="bio" name="bio" rows="3" class="amf-textarea"
                              placeholder="Fale um pouco sobre sua coleção..."><?= e($user['bio'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </section>

    <!-- Seção 2 — Conta ───────────────────────────────────────────────── -->
    <section class="profile-section">
        <div class="profile-section-head">
            <span class="profile-section-ico"><i class="fa fa-user-gear"></i></span>
            <div>
                <div class="lp-eyebrow">Privado</div>
                <h2 class="profile-section-title">Conta</h2>
            </div>
        </div>
        <div class="profile-section-body">
            <div class="profile-grid">
                <div class="profile-field">
                    <label for="username_ro">Usuário</label>
                    <input type="text" id="username_ro" class="amf-input profile-input-ro" value="<?= e($user['username']) ?>" disabled>
                    <small class="profile-help">O nome de usuário não pode ser alterado.</small>
                </div>
                <div class="profile-field">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" class="amf-input"
                           value="<?= e($user['email'] ?? '') ?>" autocomplete="email" required>
                </div>
            </div>
        </div>
    </section>

    <!-- Seção 3 — Segurança ───────────────────────────────────────────── -->
    <section class="profile-section">
        <div class="profile-section-head">
            <span class="profile-section-ico"><i class="fa fa-lock"></i></span>
            <div>
                <div class="lp-eyebrow">Segurança</div>
                <h2 class="profile-section-title">Senha</h2>
            </div>
        </div>
        <div class="profile-section-body">
            <p class="profile-hint"><i class="fa fa-circle-info"></i>Deixe os campos em branco para manter a senha atual.</p>
            <div class="profile-grid">
                <div class="profile-field profile-field-wide">
                    <label for="current_password">Senha atual</label>
                    <input type="password" id="current_password" name="current_password" class="amf-input" autocomplete="current-password">
                    <small class="profile-help">Necessária apenas para definir uma nova senha.</small>
                </div>
                <div class="profile-field">
                    <label for="new_password">Nova senha</label>
                    <input type="password" id="new_password" name="new_password" class="amf-input" minlength="8" autocomplete="new-password">
                    <small class="profile-help">Mínimo 8 caracteres.</small>
                </div>
                <div class="profile-field">
                    <label for="confirm_password">Confirmar nova senha</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="amf-input" minlength="8" autocomplete="new-password">
                </div>
            </div>
        </div>
    </section>

    <div class="profile-form-foot">
        <button type="submit" class="md-btn md-btn-primary"><i class="fa fa-save"></i>Salvar alterações</button>
    </div>
</form>

<!-- Seção 4 — Minha garagem ───────────────────────────────────────────── -->
<section class="profile-section profile-garage">
    <div class="profile-section-head">
        <span class="profile-section-ico"><i class="fa fa-warehouse"></i></span>
        <div>
            <div class="lp-eyebrow">Atalhos</div>
            <h2 class="profile-section-title">Minha garagem</h2>
        </div>
    </div>
    <div class="profile-section-body">
        <div class="profile-url">
            <i class="fa fa-globe"></i>
            <span class="profile-url-text"><?= e($prof_pub_url) ?></span>
            <button type="button" class="md-btn profile-url-copy" id="btnCopyLink2" data-url="<?= e($prof_pub_url) ?>"><i class="fa fa-copy"></i><span>Copiar</span></button>
        </div>
        <div class="profile-shortcuts">
            <a href="<?= e($prof_pub_url) ?>" target="_blank" class="lp-feature-card profile-shortcut">
                <span class="lp-feature-icon"><i class="fa fa-eye"></i></span>
                <span class="profile-shortcut-title">Ver coleção pública</span>
                <span class="profile-shortcut-sub">Sua vitrine em /u/<?= e($prof_slug) ?></span>
            </a>
            <a href="<?= h(APP_URL) ?>/admin/" class="lp-feature-card profile-shortcut">
                <span class="lp-feature-icon"><i class="fa fa-gauge-high"></i></span>
                <span class="profile-shortcut-title">Dashboard</span>
                <span class="profile-shortcut-sub">Panorama da sua coleção</span>
            </a>
            <a href="<?= h(APP_URL) ?>/admin/miniatures" class="lp-feature-card profile-shortcut">
                <span class="lp-feature-icon"><i class="fa fa-car"></i></span>
                <span class="profile-shortcut-title">Minhas miniaturas</span>
                <span class="profile-shortcut-sub">Gerencie suas peças</span>
            </a>
            <a href="<?= h(APP_URL) ?>/admin/wishlist" class="lp-feature-card profile-shortcut">
                <span class="lp-feature-icon"><i class="fa fa-heart"></i></span>
                <span class="profile-shortcut-title">Wishlist</span>
                <span class="profile-shortcut-sub">Peças que você ainda quer</span>
            </a>
        </div>
    </div>
</section>

<script>
(function () {
    // Preview de avatar
    var input = document.getElementById('avatarInput');
    var preview = document.getElementById('avatarPreview');
    if (input && preview) {
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            var url = URL.createObjectURL(file);
            preview.src = url;
            preview.onload = function () { URL.revokeObjectURL(url); };
        });
    }
    // Copiar link da garagem
    function bindCopy(btn) {
        if (!btn) return;
        btn.addEventListener('click', function () {
            var url = btn.getAttribute('data-url');
            navigator.clipboard.writeText(url).then(function () {
                var label = btn.querySelector('span');
                var prev = label ? label.textContent : '';
                if (label) label.textContent = 'Copiado!';
                btn.classList.add('is-copied');
                setTimeout(function () {
                    if (label) label.textContent = prev;
                    btn.classList.remove('is-copied');
                }, 1800);
            });
        });
    }
    bindCopy(document.getElementById('btnCopyLink'));
    bindCopy(document.getElementById('btnCopyLink2'));
})();
</script>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
