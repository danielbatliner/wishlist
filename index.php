<?php
declare(strict_types=1);

$dbDir = __DIR__ . '/data';
$uploadsDir = $dbDir . '/uploads';
$dbPath = $dbDir . '/wishlist.sqlite';

if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE IF NOT EXISTS wishes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        link TEXT,
        notes TEXT,
        photo_path TEXT,
        created_at TEXT NOT NULL
    )'
);

function redirectToHome(): never
{
    $basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['PHP_SELF'] ?? '/'))), '/');
    $location = $basePath !== '' ? $basePath . '/' : '/';
    header('Location: ' . $location);
    exit;
}

function sanitizeLink(string $link): ?string
{
    if ($link === '') {
        return null;
    }

    $filtered = filter_var($link, FILTER_VALIDATE_URL);
    if ($filtered === false) {
        return null;
    }

    $scheme = strtolower((string) parse_url($filtered, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    return $filtered;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $link = sanitizeLink(trim((string) ($_POST['link'] ?? '')));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $photoPath = null;

        if ($name !== '') {
            $photoFile = $_FILES['photo'] ?? null;
            $pastedPhoto = trim((string) ($_POST['pasted_photo'] ?? ''));

            if (is_array($photoFile) && ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $tmpPath = (string) $photoFile['tmp_name'];
                $mimeType = mime_content_type($tmpPath) ?: '';
                if (str_starts_with($mimeType, 'image/')) {
                    $extension = image_type_to_extension((int) (exif_imagetype($tmpPath) ?: IMAGETYPE_JPEG), false) ?: 'jpg';
                    $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
                    $destination = $uploadsDir . '/' . $fileName;
                    if (move_uploaded_file($tmpPath, $destination)) {
                        $photoPath = 'data/uploads/' . $fileName;
                    }
                }
            } elseif ($pastedPhoto !== '' && preg_match('#^data:image/(png|jpeg|jpg|webp|gif);base64,#i', $pastedPhoto)) {
                [$header, $content] = explode(',', $pastedPhoto, 2);
                $binary = base64_decode($content, true);
                if ($binary !== false) {
                    preg_match('#^data:image/([a-z0-9+]+);base64$#i', $header, $matches);
                    $extension = strtolower($matches[1] ?? 'png');
                    if ($extension === 'jpeg') {
                        $extension = 'jpg';
                    }
                    if (!in_array($extension, ['png', 'jpg', 'gif', 'webp'], true)) {
                        $extension = 'png';
                    }
                    $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
                    $destination = $uploadsDir . '/' . $fileName;
                    if (getimagesizefromstring($binary) !== false && file_put_contents($destination, $binary) !== false) {
                        $photoPath = 'data/uploads/' . $fileName;
                    }
                }
            }

            $stmt = $db->prepare('INSERT INTO wishes (name, link, notes, photo_path, created_at) VALUES (:name, :link, :notes, :photo_path, :created_at)');
            $stmt->execute([
                ':name' => $name,
                ':link' => $link,
                ':notes' => $notes !== '' ? $notes : null,
                ':photo_path' => $photoPath,
                ':created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
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
                $photoAbsolute = __DIR__ . '/' . ltrim((string) $wish['photo_path'], '/');
                if (is_file($photoAbsolute) && str_starts_with(realpath($photoAbsolute) ?: '', realpath($uploadsDir) ?: '')) {
                    unlink($photoAbsolute);
                }
            }

            $deleteStmt = $db->prepare('DELETE FROM wishes WHERE id = :id');
            $deleteStmt->execute([':id' => $id]);
        }

        redirectToHome();
    }
}

$wishes = $db->query('SELECT id, name, link, notes, photo_path, created_at FROM wishes ORDER BY datetime(created_at) DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meine Wunschliste</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: Arial, sans-serif; margin: 2rem auto; max-width: 900px; padding: 0 1rem; background: #f8fafc; color: #1f2937; }
        h1 { margin-top: 0; }
        details { background: #fff; border: 1px solid #d1d5db; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem; }
        summary { cursor: pointer; font-weight: 700; }
        form { margin-top: 0.75rem; display: grid; gap: 0.6rem; }
        label { display: grid; gap: 0.25rem; font-size: 0.95rem; }
        input[type="text"], input[type="url"], textarea { width: 100%; box-sizing: border-box; padding: 0.45rem; border: 1px solid #9ca3af; border-radius: 6px; }
        textarea { min-height: 80px; resize: vertical; }
        .submit-row { display: flex; gap: 0.5rem; align-items: center; }
        button { border: 1px solid #2563eb; background: #2563eb; color: #fff; border-radius: 6px; padding: 0.5rem 0.9rem; cursor: pointer; }
        .delete-button { background: #dc2626; border-color: #dc2626; }
        ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.75rem; }
        li { background: #fff; border: 1px solid #d1d5db; border-radius: 8px; padding: 0.7rem; display: grid; grid-template-columns: 70px 1fr auto; gap: 0.8rem; align-items: start; }
        .thumb-wrap { width: 60px; height: 60px; display: grid; place-items: center; background: #f3f4f6; border-radius: 6px; overflow: hidden; }
        .thumb { max-width: 100%; max-height: 100%; cursor: zoom-in; }
        .placeholder { font-size: 0.75rem; color: #6b7280; }
        .meta { font-size: 0.75rem; color: #6b7280; margin-top: 0.2rem; }
        .notes { white-space: pre-wrap; margin-top: 0.35rem; }
        .paste-hint { font-size: 0.85rem; color: #4b5563; background: #eef2ff; border: 1px dashed #93c5fd; border-radius: 6px; padding: 0.45rem; }
        dialog { border: none; border-radius: 10px; padding: 0.5rem; max-width: 90vw; max-height: 90vh; }
        dialog::backdrop { background: rgba(0, 0, 0, 0.6); }
        dialog img { max-width: 85vw; max-height: 85vh; display: block; }
    </style>
</head>
<body>
<h1>Meine Wunschliste</h1>

<details open>
    <summary>Neuen Wunsch hinzufügen</summary>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create">
        <input type="hidden" id="pasted_photo" name="pasted_photo" value="">

        <label>Foto (Upload)
            <input type="file" name="photo" id="photo" accept="image/*">
        </label>

        <div class="paste-hint" id="pasteZone" tabindex="0">
            Optional: Hier klicken und Bild aus Zwischenablage mit STRG/CMD+V einfügen.
        </div>

        <label>Name
            <input type="text" name="name" required>
        </label>

        <label>Link
            <input type="url" name="link" placeholder="https://...">
        </label>

        <label>Notizen
            <textarea name="notes" placeholder="Notizen zum Wunsch"></textarea>
        </label>

        <div class="submit-row">
            <button type="submit">Speichern</button>
            <span id="pasteInfo" class="meta"></span>
        </div>
    </form>
</details>

<ul>
    <?php foreach ($wishes as $wish): ?>
        <li>
            <div class="thumb-wrap">
                <?php if (!empty($wish['photo_path']) && is_file(__DIR__ . '/' . $wish['photo_path'])): ?>
                    <img
                        class="thumb"
                        src="<?= e((string) $wish['photo_path']) ?>"
                        alt="Foto von <?= e((string) $wish['name']) ?>"
                        data-full-src="<?= e((string) $wish['photo_path']) ?>"
                    >
                <?php else: ?>
                    <span class="placeholder">kein Foto</span>
                <?php endif; ?>
            </div>

            <div>
                <strong><?= e((string) $wish['name']) ?></strong>
                <?php if (!empty($wish['link'])): ?>
                    <div>
                        <a href="<?= e((string) $wish['link']) ?>" target="_blank" rel="noopener noreferrer">Link öffnen</a>
                    </div>
                <?php endif; ?>
                <?php if (!empty($wish['notes'])): ?>
                    <div class="notes"><?= e((string) $wish['notes']) ?></div>
                <?php endif; ?>
                <div class="meta">angelegt: <?= e((new DateTimeImmutable((string) $wish['created_at']))->format('d.m.Y H:i')) ?></div>
            </div>

            <form method="post" onsubmit="return confirm('Wunsch wirklich löschen?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $wish['id'] ?>">
                <button type="submit" class="delete-button">Löschen</button>
            </form>
        </li>
    <?php endforeach; ?>
</ul>

<dialog id="imageDialog">
    <img id="dialogImage" alt="Originalfoto">
</dialog>

<script>
    const pasteZone = document.getElementById('pasteZone');
    const pastedInput = document.getElementById('pasted_photo');
    const photoInput = document.getElementById('photo');
    const pasteInfo = document.getElementById('pasteInfo');

    pasteZone.addEventListener('paste', function (event) {
        const items = event.clipboardData?.items || [];
        for (const item of items) {
            if (item.type.startsWith('image/')) {
                const file = item.getAsFile();
                if (!file) continue;

                const reader = new FileReader();
                reader.onload = function () {
                    pastedInput.value = String(reader.result || '');
                    photoInput.value = '';
                    pasteInfo.textContent = 'Bild aus Zwischenablage erkannt.';
                };
                reader.readAsDataURL(file);
                event.preventDefault();
                break;
            }
        }
    });

    photoInput.addEventListener('change', function () {
        if (photoInput.files && photoInput.files.length > 0) {
            pastedInput.value = '';
            pasteInfo.textContent = '';
        }
    });

    const imageDialog = document.getElementById('imageDialog');
    const dialogImage = document.getElementById('dialogImage');

    document.querySelectorAll('.thumb').forEach(function (thumb) {
        thumb.addEventListener('click', function () {
            dialogImage.src = thumb.dataset.fullSrc || '';
            imageDialog.showModal();
        });
    });

    imageDialog.addEventListener('click', function () {
        imageDialog.close();
    });
</script>
</body>
</html>
