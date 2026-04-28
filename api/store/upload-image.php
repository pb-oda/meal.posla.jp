<?php
/**
 * 画像アップロード API（店舗用）
 *
 * POST /api/store/upload-image.php
 */

require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/uploads.php';

require_method(['POST']);
$user = require_role('manager');

$storeId = $_POST['store_id'] ?? null;
if ($storeId) require_store_access($storeId);

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_error('NO_FILE', '画像ファイルが送信されていません', 400);
}

$file = $_FILES['image'];
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

if (!in_array($mime, $allowed)) {
    json_error('INVALID_FORMAT', '許可されていないファイル形式です', 400);
}

if ($file['size'] > 5 * 1024 * 1024) {
    json_error('FILE_TOO_LARGE', 'ファイルサイズは5MB以下にしてください', 400);
}

$ext = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp', 'image/gif' => '.gif'];
$filename = generate_uuid() . ($ext[$mime] ?? '.jpg');

$uploadDir = posla_uploads_path('menu');
if (!posla_uploads_ensure_dir($uploadDir)) {
    json_error('SAVE_FAILED', 'アップロード先ディレクトリを作成できません', 500);
}

if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) {
    json_error('SAVE_FAILED', 'ファイルの保存に失敗しました', 500);
}

json_response(['ok' => true, 'url' => posla_uploads_public_url('menu/' . $filename)]);
