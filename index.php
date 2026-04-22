<?php
declare(strict_types=1);
session_start();

$dbDir      = __DIR__ . '/data';
$uploadsDir = $dbDir . '/uploads';
$dbPath     = $dbDir . '/wishlist.sqlite';

$maxImageBytes  = 5 * 1024 * 1024;
$reencodeMaxW   = 600;
$reencodeMaxH   = 500;
$reencodeQuality = 70;

if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (is_file($dbPath)) {
    chmod($dbPath, 0640);
}
$db->exec(
    'CREATE TABLE IF NOT EXISTS wishes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        notes TEXT,
        photo_path TEXT,
        created_at TEXT NOT NULL
    )'
);
$db->exec(
    "CREATE TABLE IF NOT EXISTS settings (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )"
);

function redirectToHome(): never
{
    $basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $location = $basePath !== '' ? $basePath . '/' : '/';
    header('Location: ' . $location);
    exit;
}

/**
 * Re-encode any supported image type to JPEG and resize to fit within maxW×maxH.
 * Returns true on success, false on failure (e.g. GD not available).
 */
function reencodeImageAsJpeg(string $sourcePath, string $destPath, int $maxW, int $maxH, int $quality): bool
{
    if (!function_exists('imagecreatefromjpeg')) {
        return false;
    }
    $type = exif_imagetype($sourcePath);
    $src  = match ($type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
        IMAGETYPE_PNG  => imagecreatefrompng($sourcePath),
        IMAGETYPE_GIF  => imagecreatefromgif($sourcePath),
        IMAGETYPE_WEBP => imagecreatefromwebp($sourcePath),
        default        => false,
    };
    if ($src === false) {
        return false;
    }

    $origW = imagesx($src);
    $origH = imagesy($src);
    $scale = min(1.0, $maxW / $origW, $maxH / $origH);
    $newW  = max(1, (int) round($origW * $scale));
    $newH  = max(1, (int) round($origH * $scale));

    $dst = imagecreatetruecolor($newW, $newH);
    if ($dst === false) {
        imagedestroy($src);
        return false;
    }
    $white = imagecolorallocate($dst, 255, 255, 255);
    if ($white !== false) {
        imagefill($dst, 0, 0, $white);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

    $ok = imagejpeg($dst, $destPath, $quality);
    imagedestroy($src);
    imagedestroy($dst);
    return $ok;
}

/**
 * Save (and re-encode) an image from a temp path; returns the public path or null.
 */
function processPhoto(string $tmpPath, string $uploadsDir, int $maxW, int $maxH, int $quality): ?string
{
    $fileName    = bin2hex(random_bytes(16)) . '.jpg';
    $destination = $uploadsDir . '/' . $fileName;
    if (reencodeImageAsJpeg($tmpPath, $destination, $maxW, $maxH, $quality)) {
        return 'data/uploads/' . $fileName;
    }
    return null;
}

/**
 * Extract a photo from a file upload or a base64 pasted_photo value.
 * Returns the stored public path or null if nothing was provided / invalid.
 */
function extractPhoto(
    array  $files,
    string $pastedPhoto,
    string $uploadsDir,
    int    $maxImageBytes,
    int    $maxW,
    int    $maxH,
    int    $quality
): ?string {
    $photoFile = $files['photo'] ?? null;
    if (is_array($photoFile) && ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmpPath  = (string) $photoFile['tmp_name'];
        $type     = exif_imagetype($tmpPath);
        $fileSize = (int) ($photoFile['size'] ?? 0);
        $allowed  = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
        if ($type !== false && in_array($type, $allowed, true) && $fileSize > 0 && $fileSize <= $maxImageBytes) {
            return processPhoto($tmpPath, $uploadsDir, $maxW, $maxH, $quality);
        }
        return null;
    }

    if ($pastedPhoto !== '' && preg_match('#^data:image/(?:png|jpe?g|webp|gif);base64,#i', $pastedPhoto)) {
        [, $b64] = explode(',', $pastedPhoto, 2);
        if (strlen($b64) > (int) ceil($maxImageBytes * 1.4)) {
            return null;
        }
        $binary = base64_decode($b64, true);
        if ($binary === false || strlen($binary) > $maxImageBytes) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'wish_');
        if ($tmp === false) {
            return null;
        }
        file_put_contents($tmp, $binary);
        $result = processPhoto($tmp, $uploadsDir, $maxW, $maxH, $quality);
        @unlink($tmp);
        return $result;
    }

    return null;
}

/**
 * Safely delete a stored photo file.
 */
function removePhotoFile(string $photoPath, string $uploadsDir): void
{
    if ($photoPath === '') {
        return;
    }
    $abs      = __DIR__ . '/' . ltrim($photoPath, '/');
    $real     = realpath($abs);
    $uploadsR = realpath($uploadsDir);
    if ($real !== false && $uploadsR !== false && is_file($real) && str_starts_with($real, $uploadsR . '/')) {
        unlink($real);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
        http_response_code(400);
        exit('Invalid request.');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $name        = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 40);
        $notes       = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 1000);
        $pastedPhoto = trim((string) ($_POST['pasted_photo'] ?? ''));

        if ($name !== '') {
            $photoPath = extractPhoto($_FILES, $pastedPhoto, $uploadsDir, $maxImageBytes, $reencodeMaxW, $reencodeMaxH, $reencodeQuality);
            $stmt = $db->prepare('INSERT INTO wishes (name, notes, photo_path, created_at) VALUES (:name, :notes, :photo_path, :created_at)');
            $stmt->execute([
                ':name'       => $name,
                ':notes'      => $notes !== '' ? $notes : null,
                ':photo_path' => $photoPath,
                ':created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ]);
        }
        redirectToHome();
    }

    if ($action === 'update') {
        $id          = (int) ($_POST['id'] ?? 0);
        $name        = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 40);
        $notes       = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 1000);
        $pastedPhoto = trim((string) ($_POST['pasted_photo'] ?? ''));
        $clearPhoto  = !empty($_POST['clear_photo']);

        if ($id > 0 && $name !== '') {
            $stmt = $db->prepare('SELECT photo_path FROM wishes WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $cur          = $stmt->fetch(PDO::FETCH_ASSOC);
            $curPhotoPath = $cur ? (string) ($cur['photo_path'] ?? '') : '';

            $newPhoto = extractPhoto($_FILES, $pastedPhoto, $uploadsDir, $maxImageBytes, $reencodeMaxW, $reencodeMaxH, $reencodeQuality);
            if ($newPhoto !== null) {
                removePhotoFile($curPhotoPath, $uploadsDir);
                $finalPhoto = $newPhoto;
            } elseif ($clearPhoto) {
                removePhotoFile($curPhotoPath, $uploadsDir);
                $finalPhoto = null;
            } else {
                $finalPhoto = $curPhotoPath !== '' ? $curPhotoPath : null;
            }

            $stmt = $db->prepare('UPDATE wishes SET name = :name, notes = :notes, photo_path = :photo_path WHERE id = :id');
            $stmt->execute([
                ':name'       => $name,
                ':notes'      => $notes !== '' ? $notes : null,
                ':photo_path' => $finalPhoto,
                ':id'         => $id,
            ]);
        }
        redirectToHome();
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare('SELECT photo_path FROM wishes WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $wish = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($wish && !empty($wish['photo_path'])) {
                removePhotoFile((string) $wish['photo_path'], $uploadsDir);
            }
            $db->prepare('DELETE FROM wishes WHERE id = :id')->execute([':id' => $id]);
        }
        redirectToHome();
    }

    if ($action === 'set_title') {
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 80);
        if ($title !== '') {
            $db->prepare("INSERT INTO settings (key, value) VALUES ('title', :v) ON CONFLICT(key) DO UPDATE SET value = :v")
               ->execute([':v' => $title]);
        }
        redirectToHome();
    }
}

$wishes = $db->query('SELECT id, name, notes, photo_path, created_at FROM wishes ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);

$titleStmt = $db->prepare("SELECT value FROM settings WHERE key = 'title'");
$titleStmt->execute();
$titleRow  = $titleStmt->fetch(PDO::FETCH_ASSOC);
$listTitle = $titleRow ? (string) $titleRow['value'] : 'My Wishlist';

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Render plain text, turning any http/https/www URLs into inline (Link) anchors.
 * Preserves line breaks (rendered via white-space: pre-wrap in CSS).
 */
function renderNotesWithLinks(string $text): string
{
    $pattern = '#(?:https?://|www\.)\S+#i';
    $html    = '';
    $offset  = 0;
    if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as [$rawUrl, $pos]) {
            $url  = rtrim($rawUrl, '.,;:!?)\'"');
            $tail = substr($rawUrl, strlen($url));
            $html .= htmlspecialchars(substr($text, $offset, $pos - $offset), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $href  = preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
            $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">(Link)</a>';
            $html .= htmlspecialchars($tail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $offset = $pos + strlen($rawUrl);
        }
    }
    $html .= htmlspecialchars(substr($text, $offset), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return $html;
}

/**
 * Render the HTML for a paste zone identified by $id.
 * Matching JS ids: pz-{id}-editable, pz-{id}-hint, pz-{id}-preview, pz-{id}-img, pz-{id}-clear, pz-{id}-data.
 */
function pasteZoneHtml(string $id): string
{
    $eid = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return
        '<div class="paste-zone-wrap">' .
        '<div class="paste-zone-label">or paste:</div>' .
        '<div class="paste-zone" id="pz-' . $eid . '"' .
             ' onclick="this.querySelector(\'.pz-editable\').focus()"' .
             ' title="Click here, then paste an image (Ctrl/Cmd+V)">' .
            '<span class="pz-editable" id="pz-' . $eid . '-editable" contenteditable="true" tabindex="0"></span>' .
            '<div class="pz-hint" id="pz-' . $eid . '-hint"><span>📋</span>Paste<br>image</div>' .
            '<div class="pz-preview" id="pz-' . $eid . '-preview">' .
                '<img id="pz-' . $eid . '-img" src="" alt="Clipboard preview">' .
                '<button type="button" class="pz-clear" id="pz-' . $eid . '-clear" title="Remove">×</button>' .
            '</div>' .
        '</div>' .
        '</div>';
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($listTitle) ?></title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; margin: 2rem auto; max-width: 900px; padding: 0 1rem; background: #f8fafc; color: #1f2937; }

        /* ── Title ── */
        .title-row { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 1.25rem; }
        .title-row h1 { margin: 0; }
        .icon-btn { background: none; border: none; cursor: pointer; font-size: 1rem; color: #6b7280; padding: 0.2rem; line-height: 1; border-radius: 4px; }
        .icon-btn:hover { color: #1f2937; background: #e5e7eb; }
        .title-form { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 1.25rem; }
        .title-input { flex: 1; max-width: 320px; padding: 0.45rem; border: 1px solid #9ca3af; border-radius: 6px; font-size: 1.2rem; font-weight: 700; }

        /* ── Add-new panel ── */
        details { background: #fff; border: 1px solid #d1d5db; border-radius: 8px; padding: 0 1rem; margin-bottom: 1.25rem; }
        details[open] { padding-bottom: 0.9rem; }
        summary { cursor: pointer; font-weight: 700; padding: 0.75rem 0; user-select: none; }
        .add-form { margin-top: 0.5rem; display: grid; gap: 0.6rem; }
        label { display: grid; gap: 0.25rem; font-size: 0.95rem; }
        input[type="text"], textarea { width: 100%; box-sizing: border-box; padding: 0.45rem; border: 1px solid #9ca3af; border-radius: 6px; }
        textarea { min-height: 80px; resize: vertical; }
        .submit-row { display: flex; gap: 0.5rem; align-items: center; }

        /* ── Buttons ── */
        button { border: 1px solid #2563eb; background: #2563eb; color: #fff; border-radius: 6px; padding: 0.5rem 0.9rem; cursor: pointer; font-size: 0.9rem; }
        .btn-sm { font-size: 0.72rem; padding: 0.15rem 0.45rem; line-height: 1.6; }
        .btn-delete { background: #dc2626; border-color: #dc2626; }
        .btn-edit   { background: #d97706; border-color: #d97706; }
        .btn-cancel { background: #6b7280; border-color: #6b7280; }
        .btn-save   { background: #16a34a; border-color: #16a34a; }

        /* ── Photo row (upload + paste zone) ── */
        .photo-row { display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; }
        .photo-row > label { flex: 1; min-width: 160px; }

        /* ── Paste zone ── */
        .paste-zone-wrap { display: flex; flex-direction: column; gap: 0.2rem; }
        .paste-zone-label { font-size: 0.75rem; color: #6b7280; }
        .paste-zone {
            width: 90px; height: 90px;
            border: 2px dashed #93c5fd; border-radius: 8px;
            background: #eef2ff; position: relative; overflow: hidden;
            cursor: pointer; flex-shrink: 0;
        }
        .paste-zone:focus-within { border-color: #2563eb; outline: 2px solid #bfdbfe; }
        .pz-hint {
            position: absolute; inset: 0;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            font-size: 0.68rem; color: #4b5563; text-align: center; padding: 0.2rem;
            pointer-events: none; user-select: none; gap: 0.15rem;
        }
        .pz-hint span { font-size: 1.3rem; line-height: 1; }
        .pz-preview { position: absolute; inset: 0; display: none; }
        .pz-preview img { width: 100%; height: 100%; object-fit: cover; }
        .pz-clear {
            position: absolute; top: 3px; right: 3px;
            background: rgba(0,0,0,0.55); color: #fff;
            border: none; border-radius: 50%;
            width: 18px; height: 18px;
            cursor: pointer; font-size: 11px; line-height: 1;
            padding: 0; display: flex; align-items: center; justify-content: center; z-index: 1;
        }
        /* invisible contenteditable that captures paste events */
        .pz-editable {
            position: absolute; left: -9999px; width: 1px; height: 1px;
            opacity: 0; overflow: hidden; outline: none; pointer-events: none;
        }

        /* ── Wish list ── */
        ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.75rem; }
        li { background: #fff; border: 1px solid #d1d5db; border-radius: 8px; padding: 0.7rem; }
        .wish-view { display: grid; grid-template-columns: 70px 1fr; gap: 0.8rem; align-items: start; }
        .thumb-wrap { width: 60px; height: 60px; display: grid; place-items: center; background: #f3f4f6; border-radius: 6px; overflow: hidden; }
        .thumb { max-width: 100%; max-height: 100%; cursor: zoom-in; }
        .placeholder { font-size: 0.72rem; color: #6b7280; text-align: center; line-height: 1.3; }
        .meta { font-size: 0.75rem; color: #6b7280; }
        .notes-text { white-space: pre-wrap; margin-top: 0.3rem; font-size: 0.88rem; word-break: break-word; }
        .item-footer { display: flex; justify-content: flex-end; align-items: center; gap: 0.4rem; margin-top: 0.4rem; flex-wrap: wrap; }
        .item-footer form { display: inline; margin: 0; }

        /* ── Inline edit panel ── */
        .wish-edit { display: none; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #e5e7eb; }
        .edit-grid { display: grid; gap: 0.6rem; }
        .edit-footer-row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
        .current-photo-row { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }
        .current-thumb { width: 44px; height: 44px; object-fit: cover; border-radius: 5px; }

        /* ── Dialog ── */
        dialog { border: none; border-radius: 10px; padding: 0.5rem; max-width: 90vw; max-height: 90vh; }
        dialog::backdrop { background: rgba(0,0,0,0.6); }
        dialog img { max-width: 85vw; max-height: 85vh; display: block; }
    </style>
</head>
<body>

<!-- ── Title ── -->
<div id="titleDisplay" class="title-row">
    <h1><?= e($listTitle) ?></h1>
    <button type="button" class="icon-btn" onclick="startTitleEdit()" title="Edit title">✏️</button>
</div>
<form id="titleForm" method="post" class="title-form" style="display:none">
    <input type="hidden" name="action" value="set_title">
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
    <input type="text" name="title" id="titleInput" class="title-input" value="<?= e($listTitle) ?>" maxlength="80" required>
    <button type="submit">Save</button>
    <button type="button" class="btn-cancel" onclick="cancelTitleEdit()">Cancel</button>
</form>

<!-- ── Add New ── -->
<details>
    <summary>Add New</summary>
    <form class="add-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="pasted_photo" id="pz-new-data" value="">

        <label>Name
            <input type="text" name="name" required maxlength="40">
        </label>

        <label>Notes <span class="meta">(URLs are linked automatically)</span>
            <textarea name="notes" placeholder="Notes, links, …" maxlength="1000"></textarea>
        </label>

        <div class="photo-row">
            <label>Photo
                <input type="file" name="photo" id="photo-new" accept="image/*">
            </label>
            <?php echo pasteZoneHtml('new'); ?>
        </div>

        <div class="submit-row">
            <button type="submit">Save</button>
        </div>
    </form>
</details>

<!-- ── Wish list ── -->
<ul>
    <?php foreach ($wishes as $wish): ?>
        <?php $wid = (int) $wish['id']; ?>
        <li id="wish-<?= $wid ?>">

            <!-- View mode -->
            <div class="wish-view">
                <div class="thumb-wrap">
                    <?php if (!empty($wish['photo_path']) && is_file(__DIR__ . '/' . $wish['photo_path'])): ?>
                        <img
                            class="thumb"
                            src="<?= e((string) $wish['photo_path']) ?>"
                            alt="Photo of <?= e((string) $wish['name']) ?>"
                            data-full-src="<?= e((string) $wish['photo_path']) ?>"
                        >
                    <?php else: ?>
                        <span class="placeholder">no<br>photo</span>
                    <?php endif; ?>
                </div>
                <div>
                    <strong><?= e((string) $wish['name']) ?></strong>
                    <?php if (!empty($wish['notes'])): ?>
                        <div class="notes-text"><?= renderNotesWithLinks((string) $wish['notes']) ?></div>
                    <?php endif; ?>
                    <div class="item-footer">
                        <span class="meta">created: <?= e((new DateTimeImmutable((string) $wish['created_at']))->format('d.m.Y H:i')) ?></span>
                        <button type="button" class="btn-edit btn-sm" onclick="openEdit(<?= $wid ?>)">Edit</button>
                        <form method="post" onsubmit="return confirm('Delete this wish?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= $wid ?>">
                            <button type="submit" class="btn-delete btn-sm">Delete</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Edit panel (hidden by default) -->
            <div class="wish-edit" id="edit-panel-<?= $wid ?>">
                <form class="edit-grid" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= $wid ?>">
                    <input type="hidden" name="pasted_photo" id="pz-edit-<?= $wid ?>-data" value="">

                    <label>Name
                        <input type="text" name="name" value="<?= e((string) $wish['name']) ?>" required maxlength="40">
                    </label>

                    <label>Notes
                        <textarea name="notes" maxlength="1000"><?= e((string) ($wish['notes'] ?? '')) ?></textarea>
                    </label>

                    <div class="photo-row">
                        <label>New Photo
                            <input type="file" name="photo" id="photo-edit-<?= $wid ?>" accept="image/*">
                        </label>
                        <?php echo pasteZoneHtml('edit-' . $wid); ?>
                    </div>

                    <?php if (!empty($wish['photo_path']) && is_file(__DIR__ . '/' . $wish['photo_path'])): ?>
                        <div class="current-photo-row">
                            <img src="<?= e((string) $wish['photo_path']) ?>" alt="" class="current-thumb">
                            <label style="display:flex; align-items:center; gap:0.35rem; font-size:0.85rem">
                                <input type="checkbox" name="clear_photo" value="1">
                                Remove current photo
                            </label>
                        </div>
                    <?php endif; ?>

                    <div class="edit-footer-row">
                        <button type="submit" class="btn-save">Save</button>
                        <button type="button" class="btn-cancel" onclick="closeEdit(<?= $wid ?>)">Cancel</button>
                    </div>
                </form>
            </div>

        </li>
    <?php endforeach; ?>
</ul>

<dialog id="imageDialog">
    <img id="dialogImage" alt="Full-size photo">
</dialog>

<script>
// ── Title editing ──────────────────────────────────────────────
function startTitleEdit() {
    document.getElementById('titleDisplay').style.display = 'none';
    var f = document.getElementById('titleForm');
    f.style.display = 'flex';
    document.getElementById('titleInput').focus();
}
function cancelTitleEdit() {
    document.getElementById('titleDisplay').style.display = '';
    document.getElementById('titleForm').style.display = 'none';
}

// ── Wish inline edit ───────────────────────────────────────────
function openEdit(id) {
    var panel = document.getElementById('edit-panel-' + id);
    panel.style.display = 'block';
    initPasteZone('edit-' + id, 'photo-edit-' + id);
}
function closeEdit(id) {
    document.getElementById('edit-panel-' + id).style.display = 'none';
}

// ── Paste zone ─────────────────────────────────────────────────
var _pzInited = {};

function initPasteZone(pzId, fileInputId) {
    if (_pzInited[pzId]) { return; }
    _pzInited[pzId] = true;

    var editable = document.getElementById('pz-' + pzId + '-editable');
    var hint     = document.getElementById('pz-' + pzId + '-hint');
    var preview  = document.getElementById('pz-' + pzId + '-preview');
    var previewImg = document.getElementById('pz-' + pzId + '-img');
    var dataInput  = document.getElementById('pz-' + pzId + '-data');
    var clearBtn   = document.getElementById('pz-' + pzId + '-clear');
    var fileInput  = fileInputId ? document.getElementById(fileInputId) : null;

    if (!editable || !hint || !preview || !previewImg || !dataInput) { return; }

    function setImage(src) {
        dataInput.value = src;
        if (fileInput) { fileInput.value = ''; }
        hint.style.display    = 'none';
        preview.style.display = 'block';
        previewImg.src        = src;
    }
    function clearImage() {
        dataInput.value       = '';
        hint.style.display    = '';
        preview.style.display = 'none';
        previewImg.src        = '';
        editable.innerHTML    = '';
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation(); clearImage();
        });
    }
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files.length > 0) { clearImage(); }
        });
    }

    // Block typing; allow only paste shortcut and Tab
    editable.addEventListener('keydown', function (e) {
        var isPaste = (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'v';
        if (!isPaste && e.key !== 'Tab') { e.preventDefault(); }
    });

    editable.addEventListener('paste', function (event) {
        var items = Array.from(event.clipboardData ? event.clipboardData.items : []);
        var imageItem = items.find(function (i) { return i.type.startsWith('image/'); });

        if (imageItem) {
            event.preventDefault();
            var file = imageItem.getAsFile();
            if (file) {
                var reader = new FileReader();
                reader.onload = function () { setImage(String(reader.result || '')); };
                reader.readAsDataURL(file);
            }
            return;
        }

        // iOS fallback: let browser insert image then extract it
        var hasText = items.some(function (i) { return i.kind === 'string'; });
        if (hasText) { event.preventDefault(); return; }

        setTimeout(function () {
            var img = editable.querySelector('img');
            if (!img) { return; }
            var src = img.src;
            editable.innerHTML = '';
            if (src.startsWith('data:image/')) {
                setImage(src);
            } else if (src.startsWith('blob:')) {
                fetch(src).then(function (r) { return r.blob(); }).then(function (blob) {
                    var reader = new FileReader();
                    reader.onload = function () { setImage(String(reader.result || '')); };
                    reader.readAsDataURL(blob);
                }).catch(function () {});
            }
        }, 200);
    });
}

// Init paste zone for the Add-New form immediately
initPasteZone('new', 'photo-new');

// ── Image lightbox ─────────────────────────────────────────────
var imageDialog = document.getElementById('imageDialog');
var dialogImage = document.getElementById('dialogImage');

document.querySelectorAll('.thumb').forEach(function (thumb) {
    thumb.addEventListener('click', function () {
        dialogImage.src = thumb.dataset.fullSrc || '';
        imageDialog.showModal();
    });
});
imageDialog.addEventListener('click', function () { imageDialog.close(); });
</script>
</body>
</html>
