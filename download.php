<?php
/**
 * download.php – Backend yt-dlp cho Mangrove Music
 * Deploy lên Hostinger (PHP 8.x, shared hosting OK)
 *
 * POST params:
 *   url  = URL YouTube / SoundCloud / link MP3 trực tiếp
 *
 * Response JSON:
 *   { "status": "ok",    "url": "/cache/xxx.mp3", "title": "Tên bài" }
 *   { "status": "error", "message": "Lý do lỗi" }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');           // CORS cho GitHub Pages test
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { json_err('Chỉ hỗ trợ POST'); }

/* ── Cấu hình ─────────────────────────────────────── */
define('CACHE_DIR',  __DIR__ . '/cache/');          // thư mục lưu file mp3
define('CACHE_URL',  '/cache/');                    // URL tương ứng (chỉnh theo domain)
define('YT_DLP',     'yt-dlp');                     // path yt-dlp; Hostinger: đặt binary vào thư mục này
define('MAX_BYTES',  30 * 1024 * 1024);             // giới hạn 30 MB / bài
define('CACHE_TTL',  3600 * 6);                     // giữ file 6 giờ rồi xóa

/* ── Nhận URL ──────────────────────────────────────── */
$raw = trim($_POST['url'] ?? '');
if (!$raw) json_err('Thiếu tham số url');

// Validate URL cơ bản
if (!filter_var($raw, FILTER_VALIDATE_URL)) json_err('URL không hợp lệ');

// Block một số domain nguy hiểm
$blocked = ['localhost', '127.', '10.', '192.168.', '169.254.'];
$host = strtolower(parse_url($raw, PHP_URL_HOST) ?? '');
foreach ($blocked as $b) {
    if (str_contains($host, $b)) json_err('URL không được phép');
}

/* ── Nếu link trực tiếp đến file audio → redirect ── */
$ext = strtolower(pathinfo(parse_url($raw, PHP_URL_PATH), PATHINFO_EXTENSION));
if (in_array($ext, ['mp3','ogg','wav','flac','m4a','aac'])) {
    echo json_encode(['status'=>'ok','url'=>$raw,'title'=>basename($raw)]);
    exit;
}

/* ── Dọn cache cũ ──────────────────────────────────── */
if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
foreach (glob(CACHE_DIR . '*.mp3') as $f) {
    if (time() - filemtime($f) > CACHE_TTL) @unlink($f);
}

/* ── Kiểm tra yt-dlp ──────────────────────────────── */
// Nếu Hostinger không có yt-dlp sẵn, đặt binary vào cùng thư mục
$ytdlp = file_exists(__DIR__ . '/yt-dlp') ? __DIR__ . '/yt-dlp' : YT_DLP;

// Thử lấy title trước
$title_cmd = escapeshellcmd($ytdlp)
    . ' --no-playlist --get-title --socket-timeout 10'
    . ' -- ' . escapeshellarg($raw) . ' 2>/dev/null';
$title = trim(@shell_exec($title_cmd)) ?: 'Bài nhạc';

/* ── Tên file cache (hash URL) ─────────────────────── */
$hash    = md5($raw);
$outfile = CACHE_DIR . $hash . '.mp3';
$outurl  = CACHE_URL . $hash . '.mp3';

/* ── Nếu đã có trong cache → trả luôn ─────────────── */
if (file_exists($outfile) && filesize($outfile) > 1024) {
    echo json_encode(['status'=>'ok','url'=>$outurl,'title'=>$title]);
    exit;
}

/* ── Chạy yt-dlp ───────────────────────────────────── */
$cmd = escapeshellcmd($ytdlp)
    . ' --no-playlist'
    . ' --extract-audio --audio-format mp3 --audio-quality 5'   // ~128kbps
    . ' --max-filesize ' . MAX_BYTES
    . ' --socket-timeout 30'
    . ' -o ' . escapeshellarg($outfile)
    . ' -- ' . escapeshellarg($raw)
    . ' 2>&1';

$output = '';
$ret    = -1;
exec($cmd, $lines, $ret);
$output = implode("\n", $lines);

if ($ret !== 0 || !file_exists($outfile)) {
    // Trả về error message sạch (không expose path server)
    if (str_contains($output, 'Unsupported URL'))     json_err('Link không được hỗ trợ');
    if (str_contains($output, 'File is larger than')) json_err('File quá lớn (>30 MB)');
    if (str_contains($output, 'yt-dlp: not found'))   json_err('yt-dlp chưa được cài trên server');
    json_err('Không tải được nhạc. Hãy thử link MP3 trực tiếp');
}

echo json_encode(['status'=>'ok','url'=>$outurl,'title'=>$title]);


/* ── Helpers ───────────────────────────────────────── */
function json_err(string $msg): never {
    http_response_code(200); // giữ 200 để fetch() không throw
    echo json_encode(['status'=>'error','message'=>$msg]);
    exit;
}
