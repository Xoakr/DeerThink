<?php
declare(strict_types=1);

// 强制关闭输出缓冲，确保SSE实时推送
while (@ob_end_clean());


const OPENAI_API_KEY = 'xx-xxxx';
const OPENAI_BASE_URL = 'https://api.siliconflow.cn/v1';//硅基流动地址，按需替换
const MODEL_CONFIG = [
    'chat_default' => 'Qwen/Qwen2.5-7B-Instruct',
    'title_model' => 'Qwen/Qwen2.5-7B-Instruct',
    'supported' => ['Qwen/Qwen2.5-7B-Instruct','XingChenAGI/Xing4.0-29B']
];

// 智能体角色配置：name 为下拉框显示名称，content 为注入 system 消息的角色提示词
const USER_ROLE = [
    'default' => [
        'name' => '默认助手',
        'content' => ''
    ],
    'legal' => [
        'name' => '法律咨询师',
        'content' => '你是一名严谨、专业的法律咨询师，熟悉中国现行法律法规与司法实践。回答问题时应当：1. 准确援引相关法律条文并注明法律名称；2. 客观分析法律关系与潜在风险；3. 区分“法律规定”与“实务建议”，不编造法条或判例；4. 对超出法律范畴或需要委托执业律师的事项作出明确提示。语言通俗易懂，条理清晰。'
    ],
    'contract' => [
        'name' => '合同审查专家',
        'content' => '你是一名合同审查专家，擅长民商事合同条款的风险识别与修订。审查时请逐条指出：1. 条款存在的法律风险与表述歧义；2. 对委托方不利之处；3. 具体的修改建议与参考措辞。重点关注主体资格、权利义务、违约责任、争议解决、生效条件等条款，意见要专业、具体、可落地。'
    ],
    'official' => [
        'name' => '公文写作助手',
        'content' => '你是一名资深公文写作助手，熟悉通知、报告、请示、函、纪要等法定公文及工作总结、方案等常用材料的写作规范。请根据用户要求撰写结构完整、表述准确、语言庄重简练的内容，符合公文格式与行文逻辑。'
    ],
    'translator' => [
        'name' => '翻译专家',
        'content' => '你是一名精通中英互译的翻译专家。翻译时应忠实原文、语句通顺、用词地道，符合目标语言的表达习惯；专业术语保持一致并在必要时加以说明。用户未指定方向时，根据原文语言自动判断翻译方向。'
    ],
    'coder' => [
        'name' => '编程助手',
        'content' => '你是一名资深软件工程师，擅长多种编程语言与框架。回答时请：1. 先理解需求，给出清晰思路；2. 提供可运行、规范、带必要注释的代码；3. 指出潜在边界情况与性能、安全注意事项；4. 代码出错时帮助定位原因并给出修复方案。'
    ],
    'tutor' => [
        'name' => '学习辅导老师',
        'content' => '你是一名耐心细致的学习辅导老师。讲解知识点时应循序渐进、由浅入深，多用例子说明，适时提问检验理解，并给出巩固练习。不仅给出答案，更要讲清“为什么”，帮助学生真正掌握。'
    ]
];
const UPLOAD_DIR = __DIR__ . '/uploads';
const STREAM_DIR = __DIR__ . '/streams';
const STOP_DIR = __DIR__ . '/stops';
const MAX_CONTEXT_MESSAGES = 0;
const TITLE_MIN_MESSAGES = 2;
const MAX_ATTACHMENTS_PER_TURN = 6;
const MAX_IMAGE_BYTES = 5000000;
const MAX_UPLOAD_BYTES = 15 * 1024 * 1024;
const STREAM_TOKEN_TTL = 600;
const STOP_TOKEN_TTL = 3600;

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0777, true);
if (!is_dir(STREAM_DIR)) mkdir(STREAM_DIR, 0777, true);
if (!is_dir(STOP_DIR)) mkdir(STOP_DIR, 0777, true);

function model_supported(string $m): bool { return in_array($m, MODEL_CONFIG['supported'], true); }
function resolve_role_key(?string $key): string { return ($key && isset(USER_ROLE[$key])) ? $key : 'default'; }
function role_system_content(?string $key): string { return (string)(USER_ROLE[resolve_role_key($key)]['content'] ?? ''); }
function stream_file(string $token): string { return STREAM_DIR . '/' . $token . '.json'; }
function new_stream_token(): string { return 'st_' . bin2hex(random_bytes(16)); }
function new_stop_token(): string { return 'stop_' . bin2hex(random_bytes(16)); }
function stop_file(string $token): string { return STOP_DIR . '/' . $token . '.json'; }

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function sse_out(array $data): void {
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}
function post_json(string $url, array $body, int $timeout = 120): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => $resp !== false && $code < 400, 'code' => $code, 'err' => $err, 'body' => $resp ?: ''];
}
function clamp_title(string $t): string {
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    $t = preg_replace('/[""\'`]/u', '', $t ?? '');
    if ($t === '') $t = '新会话';
    if (mb_strlen($t) > 30) $t = mb_substr($t, 0, 30);
    return $t;
}
function sanitize_filename(string $name): string {
    $name = preg_replace('/[^\w.\-\x{4e00}-\x{9fa5}]+/u', '_', $name);
    $name = trim($name, '._ ');
    return $name !== '' ? $name : 'file.bin';
}
function generate_title_by_ai(array $messages): string {
    if (count($messages) < TITLE_MIN_MESSAGES) return '新会话';
    $sample = [];
    foreach ($messages as $m) {
        $sample[] = ['role' => $m['role'] ?? 'user', 'content' => mb_substr((string)($m['content'] ?? ''), 0, 300)];
        if (count($sample) >= 6) break;
    }
    $res = post_json(OPENAI_BASE_URL . '/chat/completions', [
        'model' => MODEL_CONFIG['title_model'],
        'messages' => [
            ['role' => 'system', 'content' => '你是标题生成器。'],
            ['role' => 'user', 'content' => "根据对话生成一个简洁的标题（8-18字），仅输出标题。\n\n" . json_encode($sample, JSON_UNESCAPED_UNICODE)]
        ],
        'temperature' => 0.2
    ], 30);
    if (!$res['ok']) return '新会话';
    $j = json_decode($res['body'], true);
    return clamp_title((string)($j['choices'][0]['message']['content'] ?? '新会话'));
}
function normalize_messages(array $messages): array {
    $norm = [];
    foreach ($messages as $m) {
        if (!is_array($m)) continue;
        $r = (string)($m['role'] ?? '');
        if (!in_array($r, ['user', 'assistant', 'system'], true)) continue;
        $x = ['role' => $r, 'content' => (string)($m['content'] ?? '')];
        if ($r === 'assistant' && isset($m['reasoning_content'])) $x['reasoning_content'] = (string)$m['reasoning_content'];
        if ($r === 'user' && isset($m['attachments']) && is_array($m['attachments'])) {
            $atts = [];
            foreach ($m['attachments'] as $a) {
                if (!is_array($a)) continue;
                $atts[] = [
                    'name' => (string)($a['name'] ?? 'file'),
                    'mime' => (string)($a['mime'] ?? 'application/octet-stream'),
                    'size' => (int)($a['size'] ?? 0),
                    'stored_name' => (string)($a['stored_name'] ?? ''),
                    'url' => (string)($a['url'] ?? ''),
                    'is_image' => (bool)($a['is_image'] ?? false),
                ];
            }
            $x['attachments'] = $atts;
        }
        $norm[] = $x;
    }
    return $norm;
}
function get_mime_type($filepath) {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $filepath);
            finfo_close($finfo);
            if ($mime) return $mime;
        }
    }
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($filepath);
        if ($mime) return $mime;
    }
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    $map = [
        'txt' => 'text/plain', 'log' => 'text/plain', 'md' => 'text/markdown',
        'json' => 'application/json', 'xml' => 'application/xml', 'yaml' => 'application/x-yaml', 'yml' => 'application/x-yaml',
        'csv' => 'text/csv', 'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed',
        'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}
function cleanup_stream_tokens(): void {
    $files = glob(STREAM_DIR . '/*.json') ?: [];
    $now = time();
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $j = json_decode($raw, true);
        $created = (int)($j['created_at'] ?? 0);
        $used = (bool)($j['used'] ?? false);
        if (($created > 0 && $now - $created > STREAM_TOKEN_TTL) || $used) {
            @unlink($f);
        }
    }
}
function cleanup_stop_tokens(): void {
    $files = glob(STOP_DIR . '/*.json') ?: [];
    $now = time();
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $j = json_decode($raw, true);
        $created = (int)($j['created_at'] ?? 0);
        if ($created <= 0 || $now - $created > STOP_TOKEN_TTL) {
            @unlink($f);
        }
    }
}
function create_stop_token(): string {
    cleanup_stop_tokens();
    $token = new_stop_token();
    $data = [
        'created_at' => time(),
        'stopped' => false
    ];
    file_put_contents(stop_file($token), json_encode($data, JSON_UNESCAPED_UNICODE));
    return $token;
}
function mark_stop_token(string $token): bool {
    if (!preg_match('/^stop_[a-zA-Z0-9]{32}$/', $token)) return false;
    $f = stop_file($token);
    if (!is_file($f)) return false;
    $raw = file_get_contents($f);
    if ($raw === false) return false;
    $j = json_decode($raw, true);
    if (!is_array($j)) return false;
    $j['stopped'] = true;
    $j['stopped_at'] = time();
    return file_put_contents($f, json_encode($j, JSON_UNESCAPED_UNICODE)) !== false;
}
function is_stop_requested(string $token): bool {
    if (!preg_match('/^stop_[a-zA-Z0-9]{32}$/', $token)) return false;
    $f = stop_file($token);
    if (!is_file($f)) return false;
    $raw = @file_get_contents($f);
    if ($raw === false) return false;
    $j = json_decode($raw, true);
    return is_array($j) && !empty($j['stopped']);
}
function delete_stop_token(string $token): void {
    if (!preg_match('/^stop_[a-zA-Z0-9]{32}$/', $token)) return;
    $f = stop_file($token);
    if (is_file($f)) @unlink($f);
}
function create_stream_payload(array $payload): string {
    cleanup_stream_tokens();
    $token = new_stream_token();
    $data = [
        'created_at' => time(),
        'used' => false,
        'payload' => $payload
    ];
    file_put_contents(stream_file($token), json_encode($data, JSON_UNESCAPED_UNICODE));
    return $token;
}
function consume_stream_payload(string $token): ?array {
    if (!preg_match('/^st_[a-zA-Z0-9]{32}$/', $token)) return null;
    $f = stream_file($token);
    if (!is_file($f)) return null;
    $raw = file_get_contents($f);
    if ($raw === false) return null;
    $j = json_decode($raw, true);
    if (!is_array($j)) { @unlink($f); return null; }
    $created = (int)($j['created_at'] ?? 0);
    if ($created <= 0 || time() - $created > STREAM_TOKEN_TTL) { @unlink($f); return null; }
    $payload = $j['payload'] ?? null;
    @unlink($f);
    return is_array($payload) ? $payload : null;
}

$action = $_GET['action'] ?? '';

if ($action === 'models') json_out(['ok' => true, 'models' => MODEL_CONFIG['supported'], 'default' => MODEL_CONFIG['chat_default']]);

if ($action === 'stop_chat') {
    $b = json_decode(file_get_contents('php://input') ?: '', true);
    $stopToken = (string)($b['stop_token'] ?? '');
    if ($stopToken === '') json_out(['ok' => false, 'error' => 'Missing stop token'], 400);
    $ok = mark_stop_token($stopToken);
    json_out(['ok' => $ok]);
}

if ($action === 'upload') {
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) json_out(['ok' => false, 'error' => 'No file'], 400);
    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_out(['ok' => false, 'error' => 'Upload failed'], 400);
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0 || $size > MAX_UPLOAD_BYTES) json_out(['ok' => false, 'error' => '文件太大，最大限制15MB'], 400);

    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) json_out(['ok' => false, 'error' => 'Invalid upload'], 400);

    $rawName = (string)($f['name'] ?? 'file.bin');
    $safeName = sanitize_filename($rawName);
    $ext = pathinfo($safeName, PATHINFO_EXTENSION);
    $token = bin2hex(random_bytes(10));
    $stored = $token . ($ext !== '' ? ('.' . $ext) : '');
    $dest = UPLOAD_DIR . '/' . $stored;

    if (!move_uploaded_file($tmp, $dest)) json_out(['ok' => false, 'error' => '保存失败'], 500);

    $mime = (string)($f['type'] ?? 'application/octet-stream');
    $isImage = strpos($mime, 'image/') === 0;
    $url = '?action=file&name=' . rawurlencode($stored);

    json_out([
        'ok' => true,
        'file' => [
            'name' => $safeName,
            'stored_name' => $stored,
            'mime' => $mime,
            'size' => filesize($dest) ?: $size,
            'is_image' => $isImage,
            'url' => $url
        ]
    ]);
}

if ($action === 'delete_file') {
    $b = json_decode(file_get_contents('php://input') ?: '', true);
    $name = basename((string)($b['name'] ?? ''));
    if ($name === '' || !preg_match('/^[a-zA-Z0-9._-]{6,120}$/', $name)) {
        json_out(['ok' => false, 'error' => 'Invalid name'], 400);
    }
    $path = UPLOAD_DIR . '/' . $name;
    if (is_file($path)) {
        @unlink($path);
    }
    json_out(['ok' => true]);
}

if ($action === 'file') {
    $name = basename((string)($_GET['name'] ?? ''));
    if ($name === '' || !preg_match('/^[a-zA-Z0-9._-]{6,120}$/', $name)) {
        http_response_code(404); exit;
    }
    $path = UPLOAD_DIR . '/' . $name;
    if (!is_file($path)) { http_response_code(404); exit; }

    $mime = get_mime_type($path);
    header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . $name . '"');
    readfile($path);
    exit;
}

// 批量删除上传文件（浏览器端删除会话时用于清理附件）
if ($action === 'delete_files') {
    $b = json_decode(file_get_contents('php://input') ?: '', true);
    $names = $b['names'] ?? [];
    if (!is_array($names)) json_out(['ok' => false, 'error' => 'Invalid names'], 400);
    foreach ($names as $n) {
        $name = basename((string)$n);
        if ($name === '' || !preg_match('/^[a-zA-Z0-9._-]{6,120}$/', $name)) continue;
        $path = UPLOAD_DIR . '/' . $name;
        if (is_file($path)) @unlink($path);
    }
    json_out(['ok' => true]);
}

// 迁移用：导出服务器端旧历史（历史记录现已保存在浏览器 localStorage）
if ($action === 'export_all') {
    $dir = __DIR__ . '/history';
    $files = is_dir($dir) ? (glob($dir . '/*.json') ?: []) : [];
    $chats = [];
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $c = json_decode($raw, true);
        if (!is_array($c) || empty($c['id'])) continue;
        $chats[] = $c;
    }
    json_out(['ok' => true, 'chats' => $chats]);
}

if ($action === 'title_ai') {
    $b = json_decode(file_get_contents('php://input') ?: '', true);
    $messages = $b['messages'] ?? null;
    if (!is_array($messages)) json_out(['ok' => false, 'error' => 'Invalid payload'], 400);
    $title = generate_title_by_ai(normalize_messages($messages));
    json_out(['ok' => true, 'title' => $title]);
}

if ($action === 'chat_prepare') {
    $b = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($b)) json_out(['ok' => false, 'error' => 'Invalid payload'], 400);

    $model = (string)($b['model'] ?? MODEL_CONFIG['chat_default']);
    $msg = trim((string)($b['message'] ?? ''));
    $attachments = $b['attachments'] ?? [];
    $messages = $b['messages'] ?? [];
    $roleKey = resolve_role_key((string)($b['user_role'] ?? 'default'));
    $customPrompt = trim((string)($b['custom_prompt'] ?? ''));
    if (mb_strlen($customPrompt) > 4000) $customPrompt = mb_substr($customPrompt, 0, 4000);
    $tRaw = $b['temperature'] ?? 0.7;
    $temperature = is_numeric($tRaw) ? (float)$tRaw : 0.7;
    if ($temperature < 0) $temperature = 0.0;
    if ($temperature > 2) $temperature = 2.0;
    $titleLocked = (bool)($b['title_locked'] ?? false);
    $currentTitle = clamp_title((string)($b['current_title'] ?? '新会话'));

    if ($msg === '') json_out(['ok' => false, 'error' => 'Empty message'], 400);
    if (!model_supported($model)) json_out(['ok' => false, 'error' => 'Model not allowed'], 400);
    if (!is_array($attachments)) $attachments = [];
    $attachments = array_slice($attachments, 0, MAX_ATTACHMENTS_PER_TURN);
    if (!is_array($messages)) $messages = [];

    $stopToken = create_stop_token();
    $token = create_stream_payload([
        'model' => $model,
        'message' => $msg,
        'attachments' => $attachments,
        'messages' => $messages,
        'user_role' => $roleKey,
        'custom_prompt' => $customPrompt,
        'temperature' => $temperature,
        'title_locked' => $titleLocked,
        'current_title' => $currentTitle,
        'stop_token' => $stopToken
    ]);
    json_out(['ok' => true, 'token' => $token, 'stop_token' => $stopToken]);
}

if ($action === 'chat') {
    ignore_user_abort(false);
    set_time_limit(0);

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    $token = (string)($_GET['token'] ?? '');
    $payload = consume_stream_payload($token);
    if (!is_array($payload)) { sse_out(['type' => 'error', 'message' => 'Invalid or expired stream token']); exit; }

    $model = (string)($payload['model'] ?? MODEL_CONFIG['chat_default']);
    $msg = trim((string)($payload['message'] ?? ''));
    $attachments = $payload['attachments'] ?? [];
    $roleKey = resolve_role_key((string)($payload['user_role'] ?? 'default'));
    $customPrompt = trim((string)($payload['custom_prompt'] ?? ''));
    $tRaw = $payload['temperature'] ?? 0.7;
    $temperature = is_numeric($tRaw) ? (float)$tRaw : 0.7;
    if ($temperature < 0) $temperature = 0.0;
    if ($temperature > 2) $temperature = 2.0;
    $stopToken = (string)($payload['stop_token'] ?? '');
    $titleLocked = (bool)($payload['title_locked'] ?? false);
    $currentTitle = clamp_title((string)($payload['current_title'] ?? '新会话'));

    if ($msg === '') { sse_out(['type' => 'error', 'message' => 'Empty message']); exit; }
    if (!model_supported($model)) { sse_out(['type' => 'error', 'message' => 'Model not allowed']); exit; }

    if (!is_array($attachments)) $attachments = [];
    $attachments = array_slice($attachments, 0, MAX_ATTACHMENTS_PER_TURN);

    $messages = normalize_messages(is_array($payload['messages'] ?? null) ? $payload['messages'] : []);

    $normAttachments = [];
    foreach ($attachments as $a) {
        if (!is_array($a)) continue;
        $normAttachments[] = [
            'name' => (string)($a['name'] ?? 'file'),
            'mime' => (string)($a['mime'] ?? 'application/octet-stream'),
            'size' => (int)($a['size'] ?? 0),
            'stored_name' => (string)($a['stored_name'] ?? ''),
            'url' => (string)($a['url'] ?? ''),
            'is_image' => (bool)($a['is_image'] ?? false),
        ];
    }

    $messages[] = ['role' => 'user', 'content' => $msg, 'attachments' => $normAttachments];

    sse_out(['type' => 'meta', 'stop_token' => $stopToken]);

    $ctx = $messages;
    if (MAX_CONTEXT_MESSAGES > 0 && count($ctx) > MAX_CONTEXT_MESSAGES) $ctx = array_slice($ctx, -MAX_CONTEXT_MESSAGES);

$apiMessages = [];
$sysContent = $customPrompt !== '' ? $customPrompt : role_system_content($roleKey);
if ($sysContent !== '') {
    $apiMessages[] = ['role' => 'system', 'content' => $sysContent];
}

foreach ($ctx as $m) {
        $role = (string)($m['role'] ?? 'user');
        $text = (string)($m['content'] ?? '');

        // ===== 新增的 3 行：正文为空就直接跳过这条消息 =====
        if (trim($text) === '') {
            continue;
        }
        // ===== 新增结束，下面全部是你原来的代码 =====

        if ($role === 'user') {
            $contentParts = [['type' => 'text', 'text' => $text]];
            $atts = $m['attachments'] ?? [];
            $textAttachmentLines = [];
            if (is_array($atts)) {
                foreach ($atts as $a) {
                    $mime = (string)($a['mime'] ?? '');
                    $name = (string)($a['name'] ?? 'file');
                    $storedName = (string)($a['stored_name'] ?? '');
                    $path = $storedName !== '' ? (UPLOAD_DIR . '/' . basename($storedName)) : '';
                    if ($storedName !== '' && is_file($path) && strpos($mime, 'image/') === 0) {
                        $bin = file_get_contents($path);
                        if ($bin !== false) {
                            $approxBytes = strlen($bin);
                            if ($approxBytes <= MAX_IMAGE_BYTES) {
                                $b64 = base64_encode($bin);
                                $contentParts[] = [
                                    'type' => 'image_url',
                                    'image_url' => ['url' => 'data:' . $mime . ';base64,' . $b64]
                                ];
                                continue;
                            }
                        }
                    }
                    if ($storedName !== '' && is_file($path)) {
                        $snippet = '';
                        if (preg_match('/^(text\/|application\/json|application\/xml|application\/x-yaml|application\/javascript)/i', $mime) || preg_match('/\.(txt|md|csv|json|xml|yaml|yml|log|ini|conf|js|ts|py|java|php|go|rs|sql)$/i', $name)) {
                            $raw = file_get_contents($path);
                            if ($raw !== false) $snippet = mb_substr((string)$raw, 0, 2000);
                        }
                        $line = '- 文件: ' . $name . ' (' . $mime . ', ' . ((int)filesize($path)) . " bytes)";
                        if ($snippet !== '') $line .= "\n  内容摘要: " . preg_replace('/\s+/u', ' ', $snippet);
                        $textAttachmentLines[] = $line;
                    } else {
                        $textAttachmentLines[] = '- 文件: ' . $name . ' (' . $mime . ')';
                    }
                }
            }
            if ($textAttachmentLines) {
                $contentParts[] = [
                    'type' => 'text',
                    'text' => "以下是用户上传文件信息，请结合它们回答：\n" . implode("\n", $textAttachmentLines)
                ];
            }
            $apiMessages[] = ['role' => 'user', 'content' => $contentParts];
        } else {
            $apiMessages[] = ['role' => $role, 'content' => $text];
        }
    }

    $assistant = '';
    $reasoning = '';
    $buffer = '';
    $upstreamSnippet = '';
    $isReasoningPhase = false;
    $stopRequested = false;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENAI_BASE_URL . '/chat/completions',
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => $apiMessages,
            'stream' => true,
            'temperature' => $temperature
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT => 0
    ]);

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$assistant, &$reasoning, &$buffer, &$upstreamSnippet, &$isReasoningPhase, &$stopRequested, $stopToken) {
        if (connection_aborted()) {
            $stopRequested = true;
            return 0;
        }

        if ($stopToken !== '' && is_stop_requested($stopToken)) {
            $stopRequested = true;
            return 0;
        }

        if (strlen($upstreamSnippet) < 600) $upstreamSnippet .= $chunk;
        $buffer .= $chunk;

        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos + 1);
            if ($line === '' || strpos($line, 'data:') !== 0) continue;
            $data = trim(substr($line, 5));
            if ($data === '[DONE]') continue;
            $j = json_decode($data, true);
            if (!is_array($j)) continue;

            $delta = $j['choices'][0]['delta'] ?? [];

            $reasoningDelta = (string)($delta['reasoning_content'] ?? '');
            if ($reasoningDelta !== '') {
                if (!$isReasoningPhase) {
                    $isReasoningPhase = true;
                    sse_out(['type' => 'reasoning_start']);
                }
                $reasoning .= $reasoningDelta;
                sse_out(['type' => 'reasoning_delta', 'text' => $reasoningDelta]);
            }

            $contentDelta = (string)($delta['content'] ?? '');
            if ($contentDelta !== '') {
                if ($isReasoningPhase) {
                    $isReasoningPhase = false;
                    sse_out(['type' => 'reasoning_end']);
                }
                $assistant .= $contentDelta;
                sse_out(['type' => 'delta', 'text' => $contentDelta]);
            }

            if ($stopToken !== '' && is_stop_requested($stopToken)) {
                $stopRequested = true;
                return 0;
            }
        }

        return strlen($chunk);
    });

    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $shouldSavePartial = ($assistant !== '' || $reasoning !== '');

    if ($stopRequested) {
        sse_out(['type' => 'stopped', 'model' => $model]);
        delete_stop_token($stopToken);
        exit;
    }

    if ($ok === false || $code >= 400) {
        $detail = $err ?: ('HTTP ' . $code);
        $snippet = trim(mb_substr($upstreamSnippet, 0, 240));
        if ($snippet !== '') $detail .= ' | ' . $snippet;

        sse_out(['type' => 'error', 'message' => 'Upstream error: ' . $detail, 'partial_saved' => $shouldSavePartial]);
        delete_stop_token($stopToken);
        exit;
    }

    $assistantMsg = ['role' => 'assistant', 'content' => $assistant, 'model' => $model];
    if ($reasoning !== '') $assistantMsg['reasoning_content'] = $reasoning;
    $messages[] = $assistantMsg;

    $finalTitle = $currentTitle;
    if (!$titleLocked) {
        $tempTitle = generate_title_by_ai($messages);
        if ($tempTitle !== '新会话') $finalTitle = $tempTitle;
        sse_out(['type' => 'title_updated', 'title' => $finalTitle]);
    }

    sse_out(['type' => 'done', 'title' => $finalTitle, 'model' => $model]);
    delete_stop_token($stopToken);
    exit;
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8" />
<meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0,viewport-fit=cover" name="viewport" />
<title>思鹿 deerthink webui</title>
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script>
window.MathJax = {
  tex:{inlineMath:[['$','$']],displayMath:[['$$','$$']]},
  svg:{fontCache:'global'}
};
</script>
<script src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js"></script>
<script src="https://cdn.jsdelivr.net/npm/mermaid@10.9.1/dist/mermaid.min.js"></script>
<script>
mermaid.initialize({ startOnLoad:false, securityLevel:'loose', theme:'default' });
</script>
<style>
:root{
  --accent:#0066b7;
  --accent-hover:#005a9e;
  --accent-soft:#eaf3fb;
  --accent-ring:rgba(0,102,183,.14);
  --danger:#dc2626;
  --danger-hover:#b91c1c;
  --bg:#f8f9fb;
  --surface:#fff;
  --border:#e5e7eb;
  --border-strong:#d3d8de;
  --text:#1f2329;
  --text-2:#6b7280;
  --r-sm:8px;
  --r-md:12px;
  --r-lg:16px;
  --sh-sm:0 1px 2px rgba(16,24,40,.06);
  --sh-md:0 6px 16px rgba(16,24,40,.08);
  --sh-lg:0 20px 48px rgba(16,24,40,.18);
}
*{box-sizing:border-box}
::selection{background:rgba(0,102,183,.18)}
body{
  margin:0;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif;
  background:#fff;
  color:var(--text)
}
button,input,select,textarea{font-family:inherit}
.app{display:flex;height:100vh;background:#fff}
.side{
  width:300px;
  background:var(--bg);
  border-right:1px solid var(--border);
  display:flex;
  flex-direction:column;
  transition:transform .2s ease, width .2s ease
}
.side-top{
  padding:18px 16px 14px;
  border-bottom:1px solid var(--border);
  font-size:20px;
  font-weight:700;
  letter-spacing:2px;
  text-align:center;
  color:var(--accent);
  background-image:linear-gradient(135deg,#004e8c,#0066b7 45%,#3d9be0);
  -webkit-background-clip:text;
  background-clip:text;
  -webkit-text-fill-color:transparent
}
.side-actions{padding:10px;border-bottom:1px solid var(--border);display:flex;gap:6px;flex-wrap:wrap;align-items:center}
#newBtn{width:100%;padding:9px 12px;font-size:13px;font-weight:600;border-radius:10px}
.history{padding:10px;overflow:auto;flex:1}
.item{
  border:1px solid var(--border);
  border-radius:var(--r-md);
  padding:10px 12px;
  margin-bottom:8px;
  background:var(--surface);
  cursor:pointer;
  position:relative;
  transition:border-color .15s ease,box-shadow .15s ease,background .15s ease
}
.item:hover{border-color:#c3ccd6;box-shadow:var(--sh-sm)}
.item.active{background:var(--accent-soft);border-color:rgba(0,102,183,.45);box-shadow:inset 2px 0 0 var(--accent)}
.item.dragging{opacity:.5}
.item.drag-over{border-color:var(--accent);background:var(--accent-soft)}
.item-top{display:flex;align-items:center;gap:6px}
.item-title{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;user-select:none}
.sel{accent-color:var(--accent);width:15px;height:15px;cursor:pointer;flex-shrink:0}
.item-hit{
  font-size:12px;
  color:#374151;
  background:#f3f4f6;
  border:1px solid var(--border);
  border-radius:var(--r-sm);
  padding:6px 8px;
  margin-top:6px;
  line-height:1.45;
  cursor:pointer;
  transition:background .15s ease
}
.item-hit:hover{background:#e9ecf0}
.item-hit mark{background:var(--accent);color:#fff;border-radius:3px;padding:0 2px}
.item-meta{font-size:12px;color:var(--text-2);margin-top:6px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.item-ops{display:flex;gap:4px}
.expand-hits{font-size:12px;color:var(--accent);cursor:pointer;margin-top:4px}
.expand-hits:hover{text-decoration:underline}
.hidden-hits{display:none}
.hidden-hits.show{display:block}
mark.hl{background:var(--accent);color:#fff;border-radius:3px;padding:0 2px}
.overview-item-content mark,
.overview-item-content mark.hl{
  background:var(--accent);
  color:#fff;
  border-radius:3px;
  padding:0 2px;
}
.lock-btn{
  padding:2px 5px;
  font-size:10px;
  background:transparent;
  border:1px solid transparent;
  cursor:pointer;
  opacity:0.45;
  color:var(--text-2);
  display:flex;
  align-items:center;
  border-radius:6px;
  flex-shrink:0;
  transition:opacity .15s ease,background .15s ease
}
.lock-btn:hover{opacity:1;background:#eef0f3}
.lock-btn.locked{opacity:1;color:var(--accent);border-color:var(--accent);background:var(--accent-soft)}
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding:10px 16px;
  background:#fff;
  border-bottom:1px solid var(--border);
  box-shadow:0 1px 2px rgba(16,24,40,.04)
}
.top-inner{
  width:100%;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px
}
.top-left{
  display:flex;
  align-items:center;
  gap:8px;
  min-width:0
}
.top-title-wrap{min-width:0}
select{
  appearance:none;
  -webkit-appearance:none;
  border:1px solid var(--border-strong);
  border-radius:var(--r-sm);
  padding:6px 28px 6px 10px;
  font-size:12.5px;
  color:#374151;
  background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat right 9px center;
  cursor:pointer;
  transition:border-color .15s ease,box-shadow .15s ease
}
select:hover{border-color:#b9c0c8}
select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-ring)}
#modelSelect{
  max-width:min(52vw,360px);
  min-width:0
}
.agent-btn{
  display:inline-flex;
  align-items:center;
  gap:6px;
  max-width:min(44vw,240px);
  min-width:0
}
.agent-btn svg{width:14px;height:14px;flex-shrink:0}
.agent-btn-name{
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
  font-weight:600
}
.title{margin:0;font-size:17px;font-weight:600;cursor:pointer;transition:color .15s ease}
.title:hover{color:var(--accent)}
.hint{font-size:12px;color:var(--text-2)}
.msgs{flex:1;overflow:auto;padding:16px 12px}
.msg{max-width:900px;margin:0 auto 12px auto;padding:12px 14px;overflow:hidden;border-radius:14px}
.msg.user{
  background:#eef5fc;
  border:1px solid #dbe9f6;
  box-shadow:var(--sh-sm)
}
.msg.assistant{background:transparent;border:none;padding-left:10px;padding-right:10px}
.msg-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;gap:8px}
.msg .msg-header:empty{display:none;margin:0}
.role{font-size:12px;color:var(--text-2);display:block;font-weight:600}
.reasoning-btn{
  font-size:12px;
  color:#374151;
  background:#f3f4f6;
  border:1px solid var(--border);
  border-radius:999px;
  padding:4px 12px;
  cursor:pointer;
  transition:all .15s ease
}
.reasoning-btn:hover{background:#e8eaee;border-color:var(--border-strong)}
.reasoning-btn.thinking{
  color:#fff;
  background:var(--accent);
  border-color:var(--accent);
  animation:pulse 1.5s infinite
}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}
.reasoning-block{margin:0 0 8px;display:flex;flex-direction:column;align-items:flex-start}
.reasoning-preview{
  margin-top:6px;
  align-self:stretch;
  max-height:92px;
  overflow:hidden;
  padding:6px 10px 8px 12px;
  border-left:2px solid var(--accent);
  background:#f9fafb;
  border-radius:0 8px 8px 0;
  font-size:12px;
  line-height:1.55;
  color:#6b7280;
  white-space:pre-wrap;
  word-break:break-word;
  position:relative;
}
.reasoning-preview.collapsed{display:none}
.reasoning-preview::after{
  content:'';
  position:absolute;
  left:0;right:0;bottom:0;height:26px;
  background:linear-gradient(rgba(249,250,251,0),#f9fafb);
  pointer-events:none;
}
.msg-content{line-height:1.65;min-width:0}
.msg-actions{display:flex;justify-content:flex-end;gap:6px;margin-top:8px}
.composer{padding:12px 14px;background:#fff;border-top:1px solid var(--border)}
.composer-inner{max-width:900px;margin:0 auto}
.app.wide-mode .msg,
.app.wide-mode .composer-inner,
.app.wide-mode .top-inner{
  max-width:min(1600px,96vw);
}

.app.wide-mode .top-inner{
  margin:0 auto;
}
textarea{
  width:100%;
  border:1px solid var(--border-strong);
  border-radius:var(--r-md);
  padding:10px 12px;
  resize:vertical;
  outline:none;
  background:#fff;
  color:var(--text);
  font-size:14px;
  line-height:1.55;
  transition:border-color .15s ease,box-shadow .15s ease,background .15s ease
}
textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-ring)}
#input.dragover{
  border-color:var(--accent);
  background:var(--accent-soft);
  box-shadow:0 0 0 3px var(--accent-ring)
}
.row{display:flex;gap:8px;justify-content:space-between;margin-top:8px;align-items:center}
.btn{
  border:1px solid var(--border-strong);
  background:#fff;
  color:#374151;
  border-radius:var(--r-sm);
  padding:6px 12px;
  cursor:pointer;
  font-size:12.5px;
  font-weight:500;
  line-height:1.4;
  user-select:none;
  box-shadow:var(--sh-sm);
  transition:background .15s ease,border-color .15s ease,color .15s ease,box-shadow .15s ease,transform .1s ease
}
.btn:hover{background:#f6f7f9;border-color:#b9c0c8}
.btn:active{transform:translateY(1px)}
.btn:focus-visible{outline:none;box-shadow:0 0 0 3px var(--accent-ring)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff;box-shadow:0 1px 3px rgba(0,102,183,.32)}
.btn.primary:hover{background:var(--accent-hover);border-color:var(--accent-hover)}
.btn.warn{background:#374151;color:#fff;border-color:#374151}
.btn.warn:hover{background:#1f2937;border-color:#1f2937}
.btn.danger{background:var(--danger);color:#fff;border-color:var(--danger)}
.btn.danger:hover{background:var(--danger-hover);border-color:var(--danger-hover)}
#sendBtn{padding:8px 22px;font-size:13px;font-weight:600;border-radius:10px}
.iconbtn{padding:3px 8px;font-size:11px;background:#fff;border-color:var(--border);color:#4b5563;box-shadow:none}
.iconbtn:hover{background:#f3f4f6;border-color:var(--border-strong);color:var(--text)}
.mobile-toggle{display:inline-block}
.badge{font-size:11px;color:var(--accent);background:var(--accent-soft);border:1px solid #cfe3f5;border-radius:999px;padding:1px 8px;font-weight:600}
.attach-list{display:flex;gap:6px;flex-wrap:wrap}
.attach-chip{
  border:1px solid var(--border);
  background:#f6f8fa;
  color:var(--text);
  border-radius:999px;
  padding:3px 10px;
  font-size:12px;
  display:flex;
  align-items:center;
  gap:6px
}
.attach-link{color:var(--accent);text-decoration:none}
.attach-link:hover{text-decoration:underline}
.attach-x{cursor:pointer;color:#9ca3af;font-weight:bold;transition:color .15s ease}
.attach-x:hover{color:var(--danger)}
.search-input{
  flex:1;
  min-width:120px;
  display:none;
  border:1px solid var(--border-strong);
  border-radius:var(--r-sm);
  padding:6px 10px;
  font-size:12.5px;
  background:#fff;
  color:var(--text);
  outline:none;
  transition:border-color .15s ease,box-shadow .15s ease
}
.search-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-ring)}

::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-thumb{background:#c9cfd6;border-radius:999px;border:2px solid transparent;background-clip:padding-box}
::-webkit-scrollbar-thumb:hover{background-color:#aab2bc}
::-webkit-scrollbar-track{background:transparent}
.history,.msgs,.reasoning-body,.overview-body,.x-scroll{scrollbar-width:thin;scrollbar-color:#c9cfd6 transparent}

@media (min-width: 861px) {
  .app.sidebar-closed .side {
    width: 0;
    min-width: 0;
    border: none;
    overflow: hidden;
  }
}
@media (max-width:860px){
  .side{position:fixed;left:0;top:0;bottom:0;z-index:20;transform:translateX(-100%);box-shadow:var(--sh-lg)}
  .side.open{transform:translateX(0)}
  .backdrop{position:fixed;inset:0;background:rgba(15,23,42,.4);backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px);z-index:10;display:none}
  .backdrop.show{display:block}
}

.md{min-width:0}
.md p,.md li,.md blockquote,.md h1,.md h2,.md h3,.md h4,.md h5,.md h6,.md th,.md td,.md div{overflow-wrap:anywhere;word-break:break-word}
.md pre{background:#f6f8fa;border:1px solid var(--border);padding:12px 14px;border-radius:10px;overflow:auto;max-width:100%}
.md code{background:#eff1f4;padding:2px 5px;border-radius:5px;font-size:.92em;overflow-wrap:anywhere;word-break:break-word}
.md pre code{background:transparent;padding:0;border-radius:0;white-space:pre;word-break:normal;overflow-wrap:normal}
.md table{border-collapse:collapse;width:max-content;min-width:100%;margin:8px 0;font-size:14px}
.md th,.md td{border:1px solid var(--border);padding:7px 12px;text-align:left;vertical-align:top}
.md th{background:#f6f8fa;font-weight:600}
.md tbody tr:hover{background:#f9fafb}
.md ul,.md ol{padding-left:0;margin:0;list-style-position:inside}
.md ul ul,.md ul ol,.md ol ul,.md ol ol{padding-left:20px}
.md blockquote{
  margin:8px 0;
  padding:10px 14px;
  border-left:3px solid var(--accent);
  border-radius:0 var(--r-sm) var(--r-sm) 0;
  background:#f5f9fd;
  color:#374151
}

.x-scroll{
  overflow-x:auto;
  overflow-y:hidden;
  max-width:100%;
  -webkit-overflow-scrolling:touch;
}
.x-scroll > *{
  min-width:max-content;
}
.x-scroll.code-wrap > pre{
  margin:0;
}
.x-scroll.table-wrap table{
  min-width:max-content;
}
.x-scroll.mj-wrap mjx-container,
.x-scroll.mj-wrap svg{
  max-width:none !important;
}
.x-scroll::-webkit-scrollbar{height:8px}
.x-scroll::-webkit-scrollbar-thumb{background:#bbb;border-radius:999px}
.x-scroll::-webkit-scrollbar-track{background:transparent}

.modal-mask{position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);display:none;align-items:center;justify-content:center;z-index:99}
.modal{
  width:min(680px,92vw);
  background:#fff;
  border-radius:var(--r-lg);
  border:1px solid var(--border);
  box-shadow:var(--sh-lg);
  padding:14px;
  max-height:85vh;
  display:flex;
  flex-direction:column;
  animation:modalIn .18s ease
}
@keyframes modalIn{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
.modal h3{margin:0 0 10px 0;font-size:16px}
.modal textarea{min-height:180px;width:100%;resize:vertical}
.modal .ops{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
.modal-header{display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.modal-header h3{margin:0;font-size:16px}
.modal-header-left,.reasoning-header-left,.overview-header-left{display:flex;align-items:center;gap:8px;min-width:0}
.modal-body{flex:1;display:flex;min-height:0;margin-top:10px}
.modal-body textarea{flex:1;min-height:180px}
.fullscreen-btn{width:28px;height:28px;padding:0;display:inline-flex;align-items:center;justify-content:center}
.fullscreen-btn svg{width:14px;height:14px;display:block}
.modal.fullscreen,.reasoning-modal.fullscreen,.overview-modal.fullscreen{width:100vw !important;height:100vh !important;max-width:none !important;max-height:none !important;border-radius:0 !important}
.modal.fullscreen .modal-body textarea{resize:none}

.agent-modal{width:min(640px,92vw)}
.agent-body{flex:1;overflow-y:auto;margin-top:10px;min-height:0;padding-right:2px}
.agent-section-title{font-size:13px;font-weight:600;color:var(--text);margin:12px 0 8px}
.agent-section-title:first-child{margin-top:0}
.agent-list{display:flex;flex-wrap:wrap;gap:8px}
.agent-chip{
  display:inline-flex;
  align-items:center;
  gap:6px;
  max-width:100%;
  padding:6px 12px;
  border:1px solid var(--border-strong);
  border-radius:999px;
  font-size:12.5px;
  color:#374151;
  background:#fff;
  cursor:pointer;
  transition:border-color .15s ease,box-shadow .15s ease,background .15s ease
}
.agent-chip:hover{border-color:#b9c0c8;background:#f9fafb}
.agent-chip.active{border-color:var(--accent);background:#eef5fc;box-shadow:0 0 0 3px var(--accent-ring)}
.agent-chip-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.agent-chip-tag{
  flex-shrink:0;
  font-size:10.5px;
  font-weight:400;
  color:#6b7280;
  background:#f3f4f6;
  border:1px solid var(--border);
  border-radius:999px;
  padding:1px 7px
}
.agent-chip.active .agent-chip-tag{color:var(--accent);border-color:#cfe2f3;background:#e3eef9}
.agent-field-label{display:block;font-size:12.5px;font-weight:600;color:#374151;margin:10px 0 4px}
.agent-body input[type=text]{
  width:100%;
  box-sizing:border-box;
  padding:7px 10px;
  border:1px solid var(--border-strong);
  border-radius:var(--r-sm);
  font-size:13px;
  color:var(--text);
  outline:none;
  transition:border-color .15s ease,box-shadow .15s ease
}
.agent-body input[type=text]:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-ring)}
.agent-body textarea{
  width:100%;
  box-sizing:border-box;
  min-height:140px;
  resize:vertical;
  padding:8px 10px;
  border:1px solid var(--border-strong);
  border-radius:var(--r-sm);
  font-family:inherit;
  font-size:13px;
  line-height:1.5;
  color:var(--text);
  outline:none;
  transition:border-color .15s ease,box-shadow .15s ease
}
.agent-body textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-ring)}
.agent-body input[type=range]{width:100%;accent-color:var(--accent)}

.reasoning-modal{
  width:min(800px,92vw);
  height:70vh;
  background:#fff;
  border-radius:var(--r-lg);
  border:1px solid var(--border);
  display:flex;
  flex-direction:column;
  overflow:hidden;
  box-shadow:var(--sh-lg);
  animation:modalIn .18s ease
}
.reasoning-header{
  padding:12px 16px;
  border-bottom:1px solid var(--border);
  display:flex;
  justify-content:space-between;
  align-items:center;
  flex-shrink:0
}
.reasoning-title{font-size:16px;font-weight:600;color:var(--text)}
.reasoning-actions{display:flex;gap:6px}
.reasoning-body{flex:1;overflow-y:auto;padding:16px;position:relative}
.reasoning-content{line-height:1.65;font-size:14px;color:#374151}
.reasoning-textarea{
  position:absolute;inset:16px;width:calc(100% - 32px);height:calc(100% - 32px);
  border:1px solid var(--border-strong);border-radius:10px;padding:10px;font-family:inherit;
  font-size:14px;line-height:1.5;resize:none;outline:none;box-sizing:border-box;
  background:#fff;color:var(--text)
}
.reasoning-textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-ring)}

.overview-modal-mask{position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);display:none;align-items:center;justify-content:center;z-index:100}
.overview-modal{
  width:min(900px,96vw);
  height:80vh;
  background:#fff;
  border-radius:var(--r-lg);
  border:1px solid var(--border);
  display:flex;
  flex-direction:column;
  overflow:hidden;
  box-shadow:var(--sh-lg);
  animation:modalIn .18s ease
}
.overview-header{
  padding:12px 16px;
  border-bottom:1px solid var(--border);
  display:flex;
  justify-content:space-between;
  align-items:center;
  flex-shrink:0
}
.overview-title{font-size:16px;font-weight:600}
.overview-search-area{display:flex;gap:8px;align-items:center}
.overview-body{flex:1;overflow-y:auto;padding:12px}
.overview-item{border:1px solid var(--border);border-radius:var(--r-md);margin-bottom:8px;background:#fff;overflow:hidden}
.overview-item-header{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:8px 12px;
  background:#f6f8fa;
  border-bottom:1px solid var(--border)
}
.overview-item-role{font-size:12px;font-weight:600;color:#374151}
.overview-item-actions{display:flex;gap:4px}
.overview-item-content{padding:10px 12px;font-size:14px;line-height:1.6;min-width:0}
.overview-item-preview{overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;white-space:pre-wrap}
.overview-item-full{display:none}
.overview-item-footer{padding:4px 12px 8px;display:flex;justify-content:flex-end}
.overview-item .msg-content{margin:0}
.overview-search-area input{
  padding:6px 10px;
  border:1px solid var(--border-strong);
  border-radius:var(--r-sm);
  font-size:12.5px;
  background:#fff;
  color:var(--text);
  outline:none;
  transition:border-color .15s ease,box-shadow .15s ease
}
.overview-search-area input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-ring)}
</style>
</head>
<body>
<div class="app" id="app">
  <div id="backdrop" class="backdrop"></div>
  <aside id="side" class="side">
    <div class="side-top">
        思鹿 deerthink webui
    </div>
    <div class="side-actions">
      <button id="newBtn" class="btn primary">+ 新建对话</button>
      <input id="searchInput" class="search-input" placeholder="输入关键词搜索..." />
      <button id="bulkModeBtn" class="btn">批量管理</button>
      <button id="wideModeBtn" class="btn" title="切换宽屏模式" hidden="">&lt;|&gt;</button>
      <button id="overviewBtn" class="btn">总览</button>
      <button id="searchBtn" class="btn">搜索</button>
      <button id="selectAllBtn" class="btn" style="display:none;">全选</button>
      <button id="bulkPinBtn" class="btn" style="display:none;">置顶</button>
      <button id="bulkUnpinBtn" class="btn" style="display:none;">取消置顶</button>
      <button id="bulkDelBtn" class="btn danger" style="display:none;">删除</button>
      <button id="bulkCancelBtn" class="btn" style="display:none;">取消</button>
    </div>
    <div id="history" class="history"></div>
  </aside>

  <main class="main">
    <div class="top">
      <div class="top-inner">
        <div class="top-left">
          <button id="openSideBtn" class="btn mobile-toggle">&#9776;</button>
          <div class="top-title-wrap">
            <h1 id="chatTitle" class="title" title="双击编辑标题">新会话</h1>
            <div id="chatHint" class="hint"></div>
          </div>
        </div>
        <button id="agentBtn" class="btn agent-btn" title="智能体设置">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3"></circle>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
          </svg>
          <span id="agentBtnName" class="agent-btn-name">默认助手</span>
        </button>
      </div>
    </div>

    <section id="msgs" class="msgs"></section>

    <footer class="composer">
      <div class="composer-inner">
        <textarea id="input" rows="3" placeholder="输入消息（Enter发送，Shift+Enter换行，可拖入文件上传）"></textarea>
        <div class="row">
          <div style="display:flex;gap:8px;align-items:center;min-width:0;">
            <button id="uploadBtn" class="btn">上传图片/文件</button>
                  选择模型：  <select id="modelSelect"></select>

            <input id="fileInput" type="file" multiple style="display:none" />
            <div id="attachList" class="attach-list"></div>
          </div>
          <button id="sendBtn" class="btn primary">发送</button>
        </div>
      </div>
    </footer>
  </main>
</div>

<div id="editModalMask" class="modal-mask">
  <div id="editModal" class="modal">
    <div class="modal-header">
      <div class="modal-header-left">
        <button id="editFullscreenBtn" class="btn iconbtn fullscreen-btn" title="切换全屏" aria-label="切换全屏">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="15 3 21 3 21 9"></polyline>
            <polyline points="9 21 3 21 3 15"></polyline>
            <line x1="21" y1="3" x2="14" y2="10"></line>
            <line x1="3" y1="21" x2="10" y2="14"></line>
          </svg>
        </button>
        <h3>编辑消息</h3>
      </div>
    </div>
    <div class="modal-body">
      <textarea id="editModalText"></textarea>
    </div>
    <div class="ops">
      <button id="editCancelBtn" class="btn">取消</button>
      <button id="editSaveBtn" class="btn primary">保存</button>
    </div>
  </div>
</div>

<div id="reasoningModalMask" class="modal-mask">
  <div id="reasoningModal" class="reasoning-modal">
    <div class="reasoning-header">
      <div class="reasoning-header-left">
        <button id="reasoningFullscreenBtn" class="btn iconbtn fullscreen-btn" title="切换全屏" aria-label="切换全屏">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="15 3 21 3 21 9"></polyline>
            <polyline points="9 21 3 21 3 15"></polyline>
            <line x1="21" y1="3" x2="14" y2="10"></line>
            <line x1="3" y1="21" x2="10" y2="14"></line>
          </svg>
        </button>
        <div class="reasoning-title">思考过程</div>
      </div>
      <div class="reasoning-actions">
        <button id="reasoningEditBtn" class="btn">编辑</button>
        <button id="reasoningCloseBtn" class="btn">关闭</button>
      </div>
    </div>
    <div class="reasoning-body">
      <div id="reasoningView" class="reasoning-content"></div>
      <textarea id="reasoningTextarea" class="reasoning-textarea" style="display:none;"></textarea>
    </div>
  </div>
</div>

<div id="overviewModalMask" class="overview-modal-mask">
  <div id="overviewModal" class="overview-modal">
    <div class="overview-header">
      <div class="overview-header-left">
        <button id="overviewFullscreenBtn" class="btn iconbtn fullscreen-btn" title="切换全屏" aria-label="切换全屏">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="15 3 21 3 21 9"></polyline>
            <polyline points="9 21 3 21 3 15"></polyline>
            <line x1="21" y1="3" x2="14" y2="10"></line>
            <line x1="3" y1="21" x2="10" y2="14"></line>
          </svg>
        </button>
        <div class="overview-title">聊天总览</div>
      </div>
      <div class="overview-search-area">
        <input id="overviewSearchInput" type="text" placeholder="搜索当前聊天...">
        <button id="overviewCloseBtn" class="btn">关闭</button>
      </div>
    </div>
    <div id="overviewContent" class="overview-body"></div>
  </div>
</div>

<div id="agentModalMask" class="modal-mask">
  <div id="agentModal" class="modal agent-modal">
    <div class="modal-header">
      <div class="modal-header-left">
        <h3>智能体设置</h3>
      </div>
      <button id="agentCloseBtn" class="btn">关闭</button>
    </div>
    <div class="agent-body">
      <div class="agent-section-title">选择智能体（预设 / 我的）</div>
      <div id="agentList" class="agent-list"></div>
      <div class="agent-section-title">提示词与参数</div>
      <label class="agent-field-label" for="agentNameInput">名称</label>
      <input id="agentNameInput" type="text" maxlength="30" placeholder="智能体名称" />
      <label class="agent-field-label" for="agentPromptInput">提示词（System Prompt，留空则不注入）</label>
      <textarea id="agentPromptInput" placeholder="输入系统提示词，例如：你是一名……"></textarea>
      <label class="agent-field-label" for="agentTempInput">温度 temperature：<span id="agentTempVal">0.7</span></label>
      <input id="agentTempInput" type="range" min="0" max="2" step="0.1" value="0.7" />
    </div>
    <div class="ops">
      <button id="agentDeleteBtn" class="btn danger" style="display:none;">删除</button>
      <button id="agentSaveBtn" class="btn">保存为我的智能体</button>
      <button id="agentApplyBtn" class="btn primary">使用该智能体</button>
    </div>
  </div>
</div>

<script>
const state = {
  id:null,title:'新会话',messages:[],
  source:null,generating:false,history:[],
  bulkMode:false,selected:new Set(),
  lastAssistantModel:'',searchKey:'',
  attachments:[],
  editingIndex:-1,
  searchMode:false,
  searchHits:{},
  autoScroll:true,
  titleLocked:false,
  currentReasoning:'',
  isReasoning:false,
  reasoningMsgIndex:-1,
  viewingReasoningIndex:-1,
  isEditingReasoning:false,
  currentStopToken:'',
  wideMode:true,
  sidebarDesktopOpen:true,
  sidebarMobileOpen:false,
  agentSel:{type:'preset',key:'default'},
  agentModalSel:null
};

const PRESET_AGENTS = <?php
  $__presetAgents = [];
  foreach (USER_ROLE as $__k => $__item) {
    $__presetAgents[] = [
      'key' => $__k,
      'name' => (string)($__item['name'] ?? $__k),
      'prompt' => (string)($__item['content'] ?? ''),
      'temperature' => 0.7
    ];
  }
  echo json_encode($__presetAgents, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_QUOT);
?>;

const $ = id => document.getElementById(id);

const els = {
  app:$('app'),
  side:$('side'),backdrop:$('backdrop'),history:$('history'),
  openSideBtn:$('openSideBtn'),newBtn:$('newBtn'),searchBtn:$('searchBtn'),searchInput:$('searchInput'),
  selectAllBtn:$('selectAllBtn'),
  chatTitle:$('chatTitle'),chatHint:$('chatHint'),
  msgs:$('msgs'),input:$('input'),sendBtn:$('sendBtn'),modelSelect:$('modelSelect'),
  agentBtn:$('agentBtn'),agentBtnName:$('agentBtnName'),
  agentModalMask:$('agentModalMask'),
  agentList:$('agentList'),
  agentNameInput:$('agentNameInput'),
  agentPromptInput:$('agentPromptInput'),
  agentTempInput:$('agentTempInput'),
  agentTempVal:$('agentTempVal'),
  agentCloseBtn:$('agentCloseBtn'),
  agentDeleteBtn:$('agentDeleteBtn'),
  agentSaveBtn:$('agentSaveBtn'),
  agentApplyBtn:$('agentApplyBtn'),
  bulkModeBtn:$('bulkModeBtn'),bulkPinBtn:$('bulkPinBtn'),bulkUnpinBtn:$('bulkUnpinBtn'),
  bulkDelBtn:$('bulkDelBtn'),bulkCancelBtn:$('bulkCancelBtn'),
  uploadBtn:$('uploadBtn'),fileInput:$('fileInput'),attachList:$('attachList'),
  wideModeBtn:$('wideModeBtn'),

  editModalMask:$('editModalMask'),
  editModal:$('editModal'),
  editModalText:$('editModalText'),
  editFullscreenBtn:$('editFullscreenBtn'),
  editCancelBtn:$('editCancelBtn'),
  editSaveBtn:$('editSaveBtn'),

  overviewBtn:$('overviewBtn'),
  overviewModalMask:$('overviewModalMask'),
  overviewModal:$('overviewModal'),
  overviewContent:$('overviewContent'),
  overviewSearchInput:$('overviewSearchInput'),
  overviewCloseBtn:$('overviewCloseBtn'),
  overviewFullscreenBtn:$('overviewFullscreenBtn'),

  reasoningModalMask:$('reasoningModalMask'),
  reasoningModal:$('reasoningModal'),
  reasoningView:$('reasoningView'),
  reasoningTextarea:$('reasoningTextarea'),
  reasoningEditBtn:$('reasoningEditBtn'),
  reasoningCloseBtn:$('reasoningCloseBtn'),
  reasoningFullscreenBtn:$('reasoningFullscreenBtn')
};

const PREF_KEYS = {
  model: 'austere_aichat_model',
  role: 'austere_aichat_user_role',
  wideMode: 'austere_aichat_wide_mode',
  sidebarDesktopOpen: 'austere_aichat_sidebar_desktop_open',
  sidebarMobileOpen: 'austere_aichat_sidebar_mobile_open'
};
const AGENT_PREF_KEY = 'austere_aichat_agent_v1';
const CUSTOM_AGENTS_KEY = 'austere_aichat_custom_agents';

function numTemp(v){
  v = parseFloat(v);
  if (isNaN(v)) return 0.7;
  return Math.min(2, Math.max(0, v));
}
function readCustomAgents(){
  try{
    const raw = localStorage.getItem(CUSTOM_AGENTS_KEY);
    const arr = raw ? JSON.parse(raw) : [];
    return Array.isArray(arr) ? arr : [];
  }catch(e){ return []; }
}
function writeCustomAgents(list){
  try{ localStorage.setItem(CUSTOM_AGENTS_KEY, JSON.stringify(list)); }
  catch(e){ alert('保存失败：浏览器本地存储空间不足'); }
}
function genAgentId(){
  return 'ca_' + Date.now().toString(36) + Math.random().toString(36).slice(2,8);
}
function findPreset(key){
  for (let i=0;i<PRESET_AGENTS.length;i++) if (PRESET_AGENTS[i].key===key) return PRESET_AGENTS[i];
  return PRESET_AGENTS[0] || {key:'default',name:'默认助手',prompt:'',temperature:0.7};
}
function findCustom(id){
  const list = readCustomAgents();
  for (let i=0;i<list.length;i++) if (list[i].id===id) return list[i];
  return null;
}
function readAgentPref(){
  try{
    const raw = localStorage.getItem(AGENT_PREF_KEY);
    const j = raw ? JSON.parse(raw) : null;
    if (j && j.type==='custom' && j.id && findCustom(j.id)) return {type:'custom', id:j.id};
    if (j && j.type==='preset' && j.key) return {type:'preset', key:findPreset(j.key).key};
  }catch(e){}
  const oldRole = String(readPref(PREF_KEYS.role, 'default') || 'default');
  return {type:'preset', key:findPreset(oldRole).key};
}
function writeAgentPref(sel){
  try{ localStorage.setItem(AGENT_PREF_KEY, JSON.stringify(sel)); }catch(e){}
}
function currentAgentConfig(){
  const sel = state.agentSel || {type:'preset', key:'default'};
  if (sel.type === 'custom'){
    const c = findCustom(sel.id);
    if (c) return {name:c.name, roleKey:'default', customPrompt:(c.prompt||''), temperature:numTemp(c.temperature)};
  }
  const p = findPreset(sel.key);
  return {name:p.name, roleKey:p.key, customPrompt:'', temperature:numTemp(p.temperature)};
}
function updateAgentButton(){
  if (els.agentBtnName) els.agentBtnName.textContent = currentAgentConfig().name;
}
function sameAgentRef(a, b){
  if (!a || !b || a.type !== b.type) return false;
  return a.type === 'preset' ? a.key === b.key : a.id === b.id;
}
function renderAgentList(){
  const wrap = els.agentList;
  wrap.innerHTML = '';
  PRESET_AGENTS.forEach(function(p){
    wrap.appendChild(buildAgentChip({type:'preset', key:p.key}, p.name, '预设'));
  });
  readCustomAgents().forEach(function(c){
    wrap.appendChild(buildAgentChip({type:'custom', id:c.id}, c.name, '我的'));
  });
}
function buildAgentChip(ref, name, tag){
  const chip = document.createElement('div');
  chip.className = 'agent-chip' + (sameAgentRef(ref, state.agentModalSel) ? ' active' : '');
  const nameSpan = document.createElement('span');
  nameSpan.className = 'agent-chip-name';
  nameSpan.textContent = name;
  const tagSpan = document.createElement('span');
  tagSpan.className = 'agent-chip-tag';
  tagSpan.textContent = tag;
  chip.appendChild(nameSpan);
  chip.appendChild(tagSpan);
  chip.onclick = function(){
    state.agentModalSel = ref;
    fillAgentForm();
    renderAgentList();
  };
  return chip;
}
function fillAgentForm(){
  const sel = state.agentModalSel || {type:'preset', key:'default'};
  let name = '', prompt = '', temp = 0.7, isCustom = false;
  if (sel.type === 'custom'){
    const c = findCustom(sel.id);
    if (c){ name = c.name; prompt = c.prompt || ''; temp = numTemp(c.temperature); isCustom = true; }
  } else {
    const p = findPreset(sel.key);
    name = p.name; prompt = p.prompt; temp = numTemp(p.temperature);
  }
  els.agentNameInput.value = name;
  els.agentPromptInput.value = prompt;
  els.agentTempInput.value = temp;
  els.agentTempVal.textContent = temp.toFixed(1);
  els.agentDeleteBtn.style.display = isCustom ? '' : 'none';
}
function openAgentModal(){
  state.agentModalSel = state.agentSel;
  renderAgentList();
  fillAgentForm();
  els.agentModalMask.style.display = 'flex';
}
function closeAgentModal(){
  els.agentModalMask.style.display = 'none';
}
function initAgent(){
  state.agentSel = readAgentPref();
  updateAgentButton();
}
const CHAT_INDEX_KEY = 'austere_aichat_chats_index';
const CHAT_KEY_PREFIX = 'austere_aichat_chat_';
const MIGRATED_KEY = 'austere_aichat_server_migrated';

function genChatId(){
  const buf = new Uint8Array(8);
  if (window.crypto && crypto.getRandomValues) crypto.getRandomValues(buf);
  else for (let i=0;i<buf.length;i++) buf[i]=Math.floor(Math.random()*256);
  return 'chat_' + Array.from(buf).map(function(b){ return b.toString(16).padStart(2,'0'); }).join('');
}
function readChatIndex(){
  try{
    const raw = localStorage.getItem(CHAT_INDEX_KEY);
    const arr = raw ? JSON.parse(raw) : [];
    return Array.isArray(arr) ? arr : [];
  }catch(e){ return []; }
}
function writeChatIndex(items){
  try{ localStorage.setItem(CHAT_INDEX_KEY, JSON.stringify(items)); }catch(e){}
}
function sortChatItems(items){
  return items.sort(function(a,b){
    if (!!a.pinned !== !!b.pinned) return (b.pinned?1:0)-(a.pinned?1:0);
    if ((a.order_index||0)!==(b.order_index||0)) return (a.order_index||0)-(b.order_index||0);
    return (b.updated_at||0)-(a.updated_at||0);
  });
}
function readChat(id){
  try{
    const raw = localStorage.getItem(CHAT_KEY_PREFIX + id);
    const c = raw ? JSON.parse(raw) : null;
    return (c && c.id) ? c : null;
  }catch(e){ return null; }
}
function writeChat(chat){
  chat.updated_at = Math.floor(Date.now()/1000);
  try{
    localStorage.setItem(CHAT_KEY_PREFIX + chat.id, JSON.stringify(chat));
  }catch(e){
    alert('保存到浏览器本地存储失败：可能空间不足');
    return false;
  }
  const idx = readChatIndex();
  let it = null;
  for (let i=0;i<idx.length;i++){ if (idx[i].id === chat.id) { it = idx[i]; break; } }
  if (!it) { it = {id:chat.id, pinned:false, order_index:0}; idx.push(it); }
  it.title = chat.title || '新会话';
  it.updated_at = chat.updated_at;
  it.title_locked = !!chat.title_locked;
  writeChatIndex(idx);
  return true;
}
function removeChatFromStore(id){
  try{ localStorage.removeItem(CHAT_KEY_PREFIX + id); }catch(e){}
  writeChatIndex(readChatIndex().filter(function(x){ return x.id !== id; }));
}
function currentChatObject(){
  const old = state.id ? readChat(state.id) : null;
  return {
    id: state.id,
    title: state.title || '新会话',
    messages: state.messages,
    model: els.modelSelect.value || '',
    title_locked: !!state.titleLocked,
    created_at: (old && old.created_at) || Math.floor(Date.now()/1000),
    updated_at: Math.floor(Date.now()/1000)
  };
}
function cleanupChatAttachments(chat){
  const names = [];
  ((chat && chat.messages) || []).forEach(function(m){
    (m.attachments || []).forEach(function(a){ if (a && a.stored_name) names.push(a.stored_name); });
  });
  if (!names.length) return;
  fetch('?action=delete_files',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({names:names})
  }).catch(function(){});
}
async function maybeImportServerChats(){
  if (readPref(MIGRATED_KEY, false)) return;
  try{
    const r = await fetch('?action=export_all');
    const j = await r.json();
    if (!j.ok) return;
    writePref(MIGRATED_KEY, true);
    if (!Array.isArray(j.chats) || !j.chats.length) return;
    const idx = readChatIndex();
    const now = Math.floor(Date.now()/1000);
    j.chats.forEach(function(c){
      if (!c || !c.id || readChat(c.id)) return;
      const chat = {
        id: String(c.id),
        title: c.title || '新会话',
        messages: Array.isArray(c.messages) ? c.messages : [],
        model: c.model || '',
        title_locked: !!c.title_locked,
        created_at: c.created_at || now,
        updated_at: c.updated_at || now
      };
      try{ localStorage.setItem(CHAT_KEY_PREFIX + chat.id, JSON.stringify(chat)); }catch(e){}
      idx.push({id:chat.id, title:chat.title, updated_at:chat.updated_at, pinned:!!c.pinned, order_index:c.order_index||0, title_locked:chat.title_locked});
    });
    writeChatIndex(idx);
  }catch(e){}
}
const MOBILE_BREAKPOINT = 860;

function readPref(key, fallback){
  try{
    const raw = localStorage.getItem(key);
    if (raw == null) return fallback;
    try { return JSON.parse(raw); } catch(e){ return raw; }
  }catch(e){
    return fallback;
  }
}
function writePref(key, value){
  try{
    localStorage.setItem(key, JSON.stringify(value));
  }catch(e){}
}
function asBool(value, fallback){
  if (typeof value === 'boolean') return value;
  if (value === 'true') return true;
  if (value === 'false') return false;
  return fallback;
}
function isMobileView(){
  return window.innerWidth <= MOBILE_BREAKPOINT;
}
function loadUIPreferences(){
  state.wideMode = asBool(readPref(PREF_KEYS.wideMode, false), false);
  state.sidebarDesktopOpen = asBool(readPref(PREF_KEYS.sidebarDesktopOpen, true), true);
  state.sidebarMobileOpen = asBool(readPref(PREF_KEYS.sidebarMobileOpen, false), false);
}
function isSidebarOpen(){
  return isMobileView() ? !!state.sidebarMobileOpen : !!state.sidebarDesktopOpen;
}
function applySidebarState(){
  if (isMobileView()) {
    els.app.classList.remove('sidebar-closed');
    els.side.classList.toggle('open', !!state.sidebarMobileOpen);
    els.backdrop.classList.toggle('show', !!state.sidebarMobileOpen);
  } else {
    els.side.classList.remove('open');
    els.backdrop.classList.remove('show');
    els.app.classList.toggle('sidebar-closed', !state.sidebarDesktopOpen);
  }
}
function setSidebarOpen(open, persist){
  const shouldPersist = persist !== false;
  if (isMobileView()) {
    state.sidebarMobileOpen = !!open;
    if (shouldPersist) writePref(PREF_KEYS.sidebarMobileOpen, state.sidebarMobileOpen);
  } else {
    state.sidebarDesktopOpen = !!open;
    if (shouldPersist) writePref(PREF_KEYS.sidebarDesktopOpen, state.sidebarDesktopOpen);
  }
  applySidebarState();
}

const fullscreenEnterSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"></polyline><polyline points="9 21 3 21 3 15"></polyline><line x1="21" y1="3" x2="14" y2="10"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>';
const fullscreenExitSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"></polyline><polyline points="20 10 14 10 14 4"></polyline><line x1="14" y1="10" x2="21" y2="3"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>';

function setModalFullscreenBtn(btn, on){
  if (!btn) return;
  btn.innerHTML = on ? fullscreenExitSvg : fullscreenEnterSvg;
  btn.title = on ? '恢复原状' : '切换全屏';
  btn.setAttribute('aria-label', on ? '恢复原状' : '切换全屏');
}
function toggleModalFullscreen(modal, btn){
  if (!modal) return;
  const on = modal.classList.toggle('fullscreen');
  setModalFullscreenBtn(btn, on);
}
function resetModalFullscreen(modal, btn){
  if (!modal) return;
  modal.classList.remove('fullscreen');
  setModalFullscreenBtn(btn, false);
}

function setWideMode(on){
  state.wideMode = !!on;
  if (state.wideMode) {
    els.app.classList.add('wide-mode');
    els.wideModeBtn.textContent = '>|<';
  } else {
    els.app.classList.remove('wide-mode');
    els.wideModeBtn.textContent = '<|>';
  }
  writePref(PREF_KEYS.wideMode, state.wideMode);
}

function esc(s){
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function md(text){ return '<div class="md">' + marked.parse(text || '') + '</div>'; }

function prettyModelName(model){
  if (!model) return 'Assistant';
  return model.split('-').map(function(seg){ return seg ? seg.charAt(0).toUpperCase() + seg.slice(1) : ''; }).join(' ');
}
function setHeader(){
  els.chatTitle.textContent = state.title || '新会话';
  els.chatHint.textContent = state.id ? ('ID: ' + state.id) : '';
}
function setSendBtn(){
  els.sendBtn.textContent = state.generating ? '停止' : '发送';
  els.sendBtn.className = state.generating ? 'btn warn' : 'btn primary';
}
function openSide(){ setSidebarOpen(true, true); }
function closeSide(){ setSidebarOpen(false, true); }

function setSearchMode(on){
  state.searchMode = on;
  els.searchInput.style.display = on ? 'block' : 'none';
  els.bulkModeBtn.style.display = on ? 'none' : '';
  els.wideModeBtn.style.display = on ? 'none' : '';
  els.overviewBtn.style.display = on ? 'none' : '';
  if (!on) {
    state.searchKey = '';
    state.searchHits = {};
    els.searchInput.value = '';
    renderHistory();
  } else {
    setTimeout(function(){ els.searchInput.focus(); }, 0);
  }
  els.searchBtn.textContent = on ? '取消搜索' : '搜索';
}

function copyText(text){
  if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
  const ta = document.createElement('textarea');
  ta.value = text;
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); } finally { document.body.removeChild(ta); }
  return Promise.resolve();
}

function normalizeLatexText(t){
  if (!t) return '';
  let x = t;
  x = x.replace(/\\$$/g, '$$').replace(/\\$$/g, '$$');
  x = x.replace(/\\$/g, '$').replace(/\\$/g, '$');
  return x;
}

function wrapElWithXScroll(el, cls){
  if (!el || !el.parentNode) return null;
  if (el.parentNode.classList && el.parentNode.classList.contains('x-scroll')) return el.parentNode;
  const wrap = document.createElement('div');
  wrap.className = 'x-scroll ' + (cls || '');
  el.parentNode.insertBefore(wrap, el);
  wrap.appendChild(el);
  return wrap;
}

function wrapOverflowContent(root){
  if (!root) return;

  root.querySelectorAll('table').forEach(function(table){
    wrapElWithXScroll(table, 'table-wrap');
  });

  root.querySelectorAll('pre').forEach(function(pre){
    wrapElWithXScroll(pre, 'code-wrap');
  });

  root.querySelectorAll('mjx-container[display="true"], .MathJax_Display, svg[data-mml-node="math"]').forEach(function(node){
    wrapElWithXScroll(node, 'mj-wrap');
  });
}

function captureInteractiveState(root){
  const xScrolls = [];
  const details = [];
  if (!root) return {xScrolls, details};

  root.querySelectorAll('.x-scroll').forEach(function(el, i){
    xScrolls.push({ index:i, scrollLeft:el.scrollLeft });
  });

  root.querySelectorAll('details').forEach(function(el, i){
    details.push({ index:i, open:el.open });
  });

  return {xScrolls, details};
}

function restoreInteractiveState(root, snapshot){
  if (!root || !snapshot) return;

  const scrollEls = root.querySelectorAll('.x-scroll');
  (snapshot.xScrolls || []).forEach(function(item){
    const el = scrollEls[item.index];
    if (el) el.scrollLeft = item.scrollLeft || 0;
  });

  const detailEls = root.querySelectorAll('details');
  (snapshot.details || []).forEach(function(item){
    const el = detailEls[item.index];
    if (el) el.open = !!item.open;
  });
}

function renderRich(el, snapshot){
  if (!el) return;

  const applyWrapAndRestore = function(){
    wrapOverflowContent(el);
    if (snapshot) restoreInteractiveState(el, snapshot);
  };

  if (window.MathJax) {
    MathJax.typesetPromise([el]).catch(function(){})
    .then(function(){
      applyWrapAndRestore();
    });
  } else {
    applyWrapAndRestore();
  }

  const blocks = el.querySelectorAll('pre code.language-mermaid, pre code.lang-mermaid');
  blocks.forEach(function(code, i){
    const pre = code.closest('pre');
    if (!pre) return;
    const src = code.textContent || '';
    const holder = document.createElement('div');
    const id = 'mmd_' + Date.now() + '_' + i + '_' + Math.random().toString(16).slice(2);
    holder.className = 'mermaid';
    holder.id = id;
    holder.textContent = src;
    pre.replaceWith(holder);
  });

  if (window.mermaid) {
    mermaid.run({ nodes: el.querySelectorAll('.mermaid') }).catch(function(){})
      .finally(function(){
        applyWrapAndRestore();
      });
  } else {
    applyWrapAndRestore();
  }
}

function renderUserAttachments(m){
  const atts = Array.isArray(m.attachments) ? m.attachments : [];
  if (!atts.length) return '';
  return '<div class="attach-list" style="margin-top:8px;">' + atts.map(function(a){
    const name = esc(a.name || 'file');
    const url = esc(a.url || '#');
    const size = a.size ? (' ' + Math.round(a.size/1024) + 'KB') : '';
    return '<span class="attach-chip"><a class="attach-link" href="' + url + '" target="_blank" rel="noopener">' + name + '</a><span>' + size + '</span></span>';
  }).join('') + '</div>';
}

function renderMessageContentHtml(m){
  return '<div class="msg-content">' + md(normalizeLatexText(m.content || '')) + '</div>';
}

function addMsgDom(m, idx){
  const div = document.createElement('div');
  div.className = 'msg ' + m.role;
  div.dataset.idx = String(idx);
  const roleText = m.role === 'assistant' ? prettyModelName(m.model || state.lastAssistantModel || els.modelSelect.value) : 'User';

  const hasReasoning = m.role === 'assistant' && ((m.reasoning_content != null && m.reasoning_content !== '') || (state.isReasoning && idx === state.reasoningMsgIndex));
  const isCurrentlyReasoning = state.isReasoning && idx === state.reasoningMsgIndex;

  let headerHtml = '';
  if (m.role === 'assistant') {
    let reasoningBtnText = '';
    let reasoningBtnClass = 'reasoning-btn';
    if (isCurrentlyReasoning) {
      reasoningBtnText = '思考中...';
      reasoningBtnClass += ' thinking';
    } else if (hasReasoning) {
      reasoningBtnText = '思考过程';
    }
    headerHtml = '<div class="msg-header">'
      + '<span class="role">' + esc(roleText) + '</span>'
      + '</div>';
  } else {
    headerHtml = '';
  }

  let reasoningBlockHtml = '';
  if (m.role === 'assistant' && hasReasoning) {
    const previewOpen = isCurrentlyReasoning;
    const previewText = isCurrentlyReasoning ? reasoningTail(state.currentReasoning) : '';
    reasoningBlockHtml = '<div class="reasoning-block">'
      + '<button class="' + reasoningBtnClass + '" data-idx="' + idx + '">' + reasoningBtnText + '</button>'
      + '<div class="reasoning-preview' + (previewOpen ? '' : ' collapsed') + '"><div class="reasoning-preview-text">' + esc(previewText) + '</div></div>'
      + '</div>';
  }

  div.innerHTML = ''
    + headerHtml
    + reasoningBlockHtml
    + renderMessageContentHtml(m)
    + (m.role === 'user' ? renderUserAttachments(m) : '')
    + '<div class="msg-actions">'
    + '<button class="btn iconbtn act-copy">复制</button>'
    + '<button class="btn iconbtn act-edit">编辑</button>'
    + '<button class="btn iconbtn act-retry">重试</button>'
    + '<button class="btn iconbtn act-del">删除</button>'
    + '</div>';

  const reasoningBtn = div.querySelector('.reasoning-btn');
  if (reasoningBtn) {
    reasoningBtn.onclick = function(e){
      e.stopPropagation();
      openReasoningModal(idx);
    };
  }

  bindMsgActions(div);
  els.msgs.appendChild(div);
  renderRich(div);
  return div;
}

function rerenderMessages(){
  els.msgs.innerHTML='';
  state.messages.forEach(function(m,i){ addMsgDom(m,i); });
  if(state.autoScroll) els.msgs.scrollTop = els.msgs.scrollHeight;
}

function updateMessageContentDom(idx){
  const div = els.msgs.querySelector('.msg[data-idx="' + idx + '"]');
  if (!div) return;
  const contentEl = div.querySelector('.msg-content');
  if (!contentEl) return;

  const snapshot = captureInteractiveState(contentEl);
  contentEl.outerHTML = renderMessageContentHtml(state.messages[idx] || {content:''});
  const newContentEl = div.querySelector('.msg-content');
  renderRich(newContentEl, snapshot);
}

function openEditModal(index){
  state.editingIndex = index;
  els.editModalText.value = state.messages[index] ? (state.messages[index].content || '') : '';
  setModalFullscreenBtn(els.editFullscreenBtn, els.editModal.classList.contains('fullscreen'));
  els.editModalMask.style.display = 'flex';
  els.editModalText.focus();
}
function closeEditModal(){
  els.editModalMask.style.display = 'none';
  state.editingIndex = -1;
  resetModalFullscreen(els.editModal, els.editFullscreenBtn);
}

function getReasoningTextByIndex(index){
  if (state.isReasoning && index === state.reasoningMsgIndex) return state.currentReasoning;
  const msg = state.messages[index];
  return (msg && msg.reasoning_content) ? msg.reasoning_content : '';
}

function openReasoningModal(index){
  state.viewingReasoningIndex = index;
  state.isEditingReasoning = false;

  const canOpen = (state.isReasoning && index === state.reasoningMsgIndex) ||
                  (state.messages[index] && typeof state.messages[index].reasoning_content !== 'undefined');

  if (!canOpen) return;

  const reasoningText = getReasoningTextByIndex(index);

  els.reasoningView.style.display = 'block';
  els.reasoningTextarea.style.display = 'none';
  els.reasoningView.innerHTML = md(normalizeLatexText(reasoningText));
  els.reasoningEditBtn.textContent = '编辑';
  els.reasoningEditBtn.style.display = '';
  setModalFullscreenBtn(els.reasoningFullscreenBtn, els.reasoningModal.classList.contains('fullscreen'));
  els.reasoningModalMask.style.display = 'flex';
  renderRich(els.reasoningView);
}
function closeReasoningModal(){
  els.reasoningModalMask.style.display = 'none';
  state.viewingReasoningIndex = -1;
  state.isEditingReasoning = false;
  resetModalFullscreen(els.reasoningModal, els.reasoningFullscreenBtn);
}

function toggleReasoningEdit(){
  const idx = state.viewingReasoningIndex;
  if (idx < 0) return;

  if (state.isEditingReasoning) {
    const newContent = els.reasoningTextarea.value;

    if (state.isReasoning && idx === state.reasoningMsgIndex) {
      state.currentReasoning = newContent;
    } else if (state.messages[idx]) {
      state.messages[idx].reasoning_content = newContent;
      persistMessages();
    }

    els.reasoningView.innerHTML = md(normalizeLatexText(newContent));
    els.reasoningView.style.display = 'block';
    els.reasoningTextarea.style.display = 'none';
    els.reasoningEditBtn.textContent = '编辑';
    state.isEditingReasoning = false;
    renderRich(els.reasoningView);

    if (state.messages[idx] && state.messages[idx].role === 'assistant') {
      updateReasoningButton(idx, state.isReasoning && idx === state.reasoningMsgIndex, true);
    }
  } else {
    els.reasoningTextarea.value = getReasoningTextByIndex(idx);
    els.reasoningView.style.display = 'none';
    els.reasoningTextarea.style.display = 'block';
    els.reasoningEditBtn.textContent = '保存';
    state.isEditingReasoning = true;
    els.reasoningTextarea.focus();
  }
}

function reasoningTail(text){
  const t = text || '';
  return t.length > 400 ? '…' + t.slice(-400) : t;
}

function ensureReasoningBlock(index){
  let btn = els.msgs.querySelector('.reasoning-btn[data-idx="' + index + '"]');
  if (btn) return btn;
  const msgDiv = els.msgs.querySelector('.msg[data-idx="' + index + '"]');
  if (!msgDiv) return null;
  const content = msgDiv.querySelector('.msg-content');
  const block = document.createElement('div');
  block.className = 'reasoning-block';
  block.innerHTML = '<button class="reasoning-btn thinking" data-idx="' + index + '">思考中...</button>'
    + '<div class="reasoning-preview"><div class="reasoning-preview-text"></div></div>';
  btn = block.querySelector('.reasoning-btn');
  btn.onclick = function(e){ e.stopPropagation(); openReasoningModal(index); };
  if (content) msgDiv.insertBefore(block, content);
  else msgDiv.appendChild(block);
  return btn;
}

let reasoningPreviewLastTs = 0;
function updateReasoningButton(index, isThinking, hasContent){
  const btn = ensureReasoningBlock(index);
  if (!btn) return;
  const block = btn.parentNode;
  const preview = block.querySelector('.reasoning-preview');
  const previewText = block.querySelector('.reasoning-preview-text');

  if (isThinking) {
    btn.textContent = '思考中...';
    btn.className = 'reasoning-btn thinking';
    if (preview) {
      preview.classList.remove('collapsed');
      if (previewText) {
        const now = Date.now();
        if (now - reasoningPreviewLastTs >= 120) {
          reasoningPreviewLastTs = now;
          previewText.textContent = reasoningTail(getReasoningTextByIndex(index));
        }
        preview.scrollTop = preview.scrollHeight;
      }
    }
  } else if (hasContent) {
    btn.textContent = '思考过程';
    btn.className = 'reasoning-btn';
    if (preview) preview.classList.add('collapsed');
  }
}

function bindMsgActions(div){
  const idx = Number(div.dataset.idx);
  const btnCopy = div.querySelector('.act-copy');
  btnCopy.onclick = function(){
    copyText((state.messages[idx] && state.messages[idx].content) || '').then(function(){
      const originText = btnCopy.textContent;
      btnCopy.textContent = '已复制';
      btnCopy.style.color = '#111';
      setTimeout(function(){ btnCopy.textContent = originText; btnCopy.style.color = ''; }, 1500);
    });
  };
  div.querySelector('.act-edit').onclick = function(){ openEditModal(idx); };
  div.querySelector('.act-del').onclick = function(){
    if (!confirm('删除这条消息？')) return;
    state.messages.splice(idx,1);
    persistMessages().then(function(){ rerenderMessages(); });
  };
  div.querySelector('.act-retry').onclick = function(){
    let userText = '';
    for (let i=idx;i>=0;i--) {
      if(state.messages[i].role==='user'){ userText=state.messages[i].content; break; }
    }
    if (!userText){
      for (let i=state.messages.length-1;i>=0;i--) {
        if(state.messages[i].role==='user'){ userText=state.messages[i].content; break; }
      }
    }
    if (!userText) return alert('没有可重试的用户消息');
    let cut=-1;
    for(let i=idx;i>=0;i--){ if(state.messages[i].role==='user'){ cut=i; break; } }
    if (cut>=0) state.messages = state.messages.slice(0,cut+1);
    else state.messages.push({role:'user',content:userText});
    persistMessages().then(function(){
      rerenderMessages();
      sendWithText(userText,true);
    });
  };
}

async function persistMessages(){
  if (!state.id) return;
  writeChat(currentChatObject());
  await loadHistory();
}

function updateBulkUI(){
  const on=state.bulkMode;
  els.bulkPinBtn.style.display=on?'':'none';
  els.bulkUnpinBtn.style.display=on?'':'none';
  els.bulkDelBtn.style.display=on?'':'none';
  els.bulkCancelBtn.style.display=on?'':'none';
  els.searchBtn.style.display = on ? 'none' : '';
  els.overviewBtn.style.display = on ? 'none' : '';
  els.wideModeBtn.style.display = on ? 'none' : '';
  els.selectAllBtn.style.display = on ? '' : 'none';
  if (!state.searchMode) els.bulkModeBtn.style.display=on?'none':'';
}

function highlightTitle(title, key){
  const t = title || '';
  if (!key) return esc(t);
  const i = t.toLowerCase().indexOf(key.toLowerCase());
  if (i < 0) return esc(t);
  return esc(t.slice(0,i)) + '<mark class="hl">' + esc(t.slice(i,i+key.length)) + '</mark>' + esc(t.slice(i+key.length));
}
function highlightText(text, key){
  const t = text || '';
  if (!key) return esc(t);
  const i = t.toLowerCase().indexOf(key.toLowerCase());
  if (i < 0) return esc(t);
  return esc(t.slice(0,i)) + '<mark class="hl">' + esc(t.slice(i,i+key.length)) + '</mark>' + esc(t.slice(i+key.length));
}
function highlightAll(text, key){
  if (!key) return esc(text || '');
  const t = text || '';
  const safeKey = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  try {
    const regex = new RegExp('(' + safeKey + ')', 'gi');
    const parts = t.split(regex);
    return parts.map(function(part){
      if (part.toLowerCase() === key.toLowerCase()) return '<mark class="hl">' + esc(part) + '</mark>';
      return esc(part);
    }).join('');
  } catch(e) {
    return esc(t);
  }
}
function findHitsFromMessages(messages, key){
  const kw = (key || '').toLowerCase();
  if (!kw) return [];
  const hits = [];
  for (let i = 0; i < (messages || []).length; i++) {
    const txt = String((messages[i] && messages[i].content) || '');
    let searchPos = 0;
    while (true) {
      const p = txt.toLowerCase().indexOf(kw, searchPos);
      if (p < 0) break;
      const start = Math.max(0, p - 16);
      const end = Math.min(txt.length, p + kw.length + 24);
      const snippet = (start > 0 ? '...' : '') + txt.slice(start, end).replace(/\s+/g, ' ') + (end < txt.length ? '...' : '');
      hits.push({ index:i, snippet:snippet });
      searchPos = p + kw.length;
      if (hits.length > 200) break;
    }
  }
  return hits;
}

const lockSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>';
const unlockSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 9.9-1"></path></svg>';

function renderHistory(){
  els.history.innerHTML='';
  let view = state.history;

  if (state.searchKey) {
    const k = state.searchKey.toLowerCase();
    view = view.filter(function(it){
      const titleHit = (it.title || '').toLowerCase().indexOf(k) >= 0;
      const contentHit = !!(state.searchHits[it.id] && state.searchHits[it.id].length > 0);
      return titleHit || contentHit;
    });
  }

  view.forEach(function(it){
    const active = it.id===state.id?' active':'';
    const checked = state.selected.has(it.id)?'checked':'';
    const pinBadge = it.pinned?'<span class="badge">置顶</span>':'';
    const isLocked = it.title_locked || false;
    const hits = state.searchHits[it.id] || [];
    let hitsHtml = '';
    const maxDisplay = 3;

    if (hits.length > 0) {
      const displayHits = hits.slice(0, maxDisplay);
      hitsHtml = displayHits.map(function(hit){
        return '<div class="item-hit" data-hit-id="'+it.id+'" data-hit-idx="'+hit.index+'">命中：' + highlightText(hit.snippet, state.searchKey) + '</div>';
      }).join('');
      if (hits.length > maxDisplay) {
        const hiddenCount = hits.length - maxDisplay;
        hitsHtml += '<div class="expand-hits" data-id="'+it.id+'">还有 ' + hiddenCount + ' 个命中...</div>';
        hitsHtml += '<div class="hidden-hits">' + hits.slice(maxDisplay).map(function(hit){
          return '<div class="item-hit" data-hit-id="'+it.id+'" data-hit-idx="'+hit.index+'">命中：' + highlightText(hit.snippet, state.searchKey) + '</div>';
        }).join('') + '</div>';
      }
    }

    const div = document.createElement('div');
    div.className='item'+active;
    div.draggable=!state.bulkMode;
    div.dataset.id=it.id;

    div.innerHTML=''
      + '<div class="item-top">'
      + (state.bulkMode?('<input type="checkbox" class="sel" data-id="'+it.id+'" '+checked+'>'):'')
      + '<div class="item-title" title="' + (isLocked ? '标题已锁定' : '双击重新生成标题') + '">' + highlightTitle(it.title || '无标题', state.searchKey) + '</div>'
      + pinBadge
      + '<button class="lock-btn ' + (isLocked ? 'locked' : '') + '" data-id="'+it.id+'" title="'+(isLocked?'点击解锁标题':'点击锁定标题')+'">' + (isLocked ? lockSvg : unlockSvg) + '</button>'
      + '</div>'
      + hitsHtml
      + '<div class="item-meta"><span>'+new Date(it.updated_at*1000).toLocaleString()+'</span>'
      + '<span class="item-ops">'
      + '<button class="btn iconbtn op-clone">克隆</button>'
      + '<button class="btn iconbtn op-pin">'+(it.pinned?'取消置顶':'置顶')+'</button>'
      + '<button class="btn iconbtn op-del">删除</button>'
      + '</span>'
      + '</div>';

    div.onclick = function(e){
      if (state.bulkMode) return;
      if (e.target.closest('button') || e.target.closest('input') || e.target.closest('.item-hit') || e.target.closest('.expand-hits') || e.target.closest('.item-title')) return;
      loadChat(it.id);
    };

    div.querySelector('.lock-btn').onclick = function(e){
      e.stopPropagation();
      toggleLock(it.id);
    };

    div.querySelectorAll('.item-hit').forEach(function(el){
      el.onclick = function(e){
        e.stopPropagation();
        const idx = Number(el.dataset.hitIdx);
        loadChat(it.id, idx);
      };
    });

    div.querySelectorAll('.expand-hits').forEach(function(el){
      el.onclick = function(e){
        e.stopPropagation();
        const hidden = div.querySelector('.hidden-hits');
        if (hidden) {
          if(hidden.classList.contains('show')){
            hidden.classList.remove('show');
            el.textContent = '还有 ' + hidden.children.length + ' 个命中...';
          } else {
            hidden.classList.add('show');
            el.textContent = '收起';
          }
        }
      };
    });

    div.querySelector('.op-clone').onclick = function(e){
      e.stopPropagation();
      if(!confirm('克隆该会话？')) return;
      const src = readChat(it.id);
      if (!src) return alert('会话不存在');
      const now = Math.floor(Date.now()/1000);
      const copy = Object.assign({}, src, {id: genChatId(), title: (src.title||'新会话') + ' (副本)', created_at: now, updated_at: now, title_locked: false});
      writeChat(copy);
      loadHistory().then(function(){ loadChat(copy.id); });
    };

    const titleEl = div.querySelector('.item-title');
    let titleClickTimer = null;
    titleEl.onclick = function(e){
      e.stopPropagation();
      if (state.bulkMode) return;
      clearTimeout(titleClickTimer);
      titleClickTimer = setTimeout(function(){ loadChat(it.id); }, 220);
    };
    titleEl.ondblclick = function(e){
      e.stopPropagation();
      if (state.bulkMode) return;
      clearTimeout(titleClickTimer);
      if (it.title_locked) return;
      regenerateChatTitle(it.id, titleEl);
    };

    div.querySelector('.op-del').onclick = function(e){
      e.stopPropagation();
      if(!confirm('删除该会话？')) return;
      cleanupChatAttachments(readChat(it.id));
      removeChatFromStore(it.id);
      if(state.id===it.id) newChat();
      loadHistory();
    };

    div.querySelector('.op-pin').onclick = function(e){
      e.stopPropagation();
      const idx = readChatIndex();
      idx.forEach(function(x){ if (x.id === it.id) x.pinned = !it.pinned; });
      writeChatIndex(idx);
      loadHistory();
    };

    const sel = div.querySelector('.sel');
    if(sel) sel.onchange=function(e){
      e.stopPropagation();
      const id=e.target.dataset.id;
      if(e.target.checked) state.selected.add(id); else state.selected.delete(id);
      const viewIds = view.map(function(x){ return x.id; });
      const allSelected = viewIds.length > 0 && viewIds.every(function(xid){ return state.selected.has(xid); });
      els.selectAllBtn.textContent = allSelected ? '取消全选' : '全选';
    };

    div.addEventListener('dragstart', function(e){
      div.classList.add('dragging');
      e.dataTransfer.setData('text/plain', it.id);
      e.dataTransfer.effectAllowed='move';
    });
    div.addEventListener('dragend', function(){ div.classList.remove('dragging'); });
    div.addEventListener('dragover', function(e){
      if (state.bulkMode) return;
      e.preventDefault();
      div.classList.add('drag-over');
    });
    div.addEventListener('dragleave', function(){ div.classList.remove('drag-over'); });
    div.addEventListener('drop', function(e){
      e.preventDefault();
      div.classList.remove('drag-over');
      if (state.bulkMode) return;
      const fromId=e.dataTransfer.getData('text/plain');
      const toId=it.id;
      if(!fromId || fromId===toId) return;
      const arr=state.history.map(function(x){ return x.id; });
      const fi=arr.indexOf(fromId), ti=arr.indexOf(toId);
      if(fi<0||ti<0) return;
      const m=arr.splice(fi,1)[0];
      arr.splice(ti,0,m);
      const order = {}; arr.forEach(function(id,i){ order[id]=i; });
      const idx2 = readChatIndex();
      idx2.forEach(function(x){ if (x.id in order) x.order_index = order[x.id]; });
      writeChatIndex(idx2);
      loadHistory();
    });

    els.history.appendChild(div);
  });

  if (state.bulkMode) {
    const viewIds = view.map(function(it){ return it.id; });
    const allSelected = viewIds.length > 0 && viewIds.every(function(id){ return state.selected.has(id); });
    els.selectAllBtn.textContent = allSelected ? '取消全选' : '全选';
  }
}

function toggleLock(id){
  const chat = readChat(id);
  if (!chat) return alert('会话不存在');
  chat.title_locked = !(chat.title_locked || false);
  writeChat(chat);
  if (state.id === id) state.titleLocked = chat.title_locked;
  loadHistory();
}

async function loadHistory(){
  state.history = sortChatItems(readChatIndex());
  state.searchHits = {};
  const key = state.searchKey.trim();
  if (key) {
    state.history.forEach(function(it){
      const chat = readChat(it.id);
      const hits = findHitsFromMessages((chat && chat.messages) || [], key);
      if (hits.length > 0) state.searchHits[it.id] = hits;
    });
  }
  renderHistory();
}

async function loadModels(){
  const r=await fetch('?action=models');
  const j=await r.json();
  if (!j.ok) return;
  const models = j.models || [];
  els.modelSelect.innerHTML='';
  models.forEach(function(m){
    const op=document.createElement('option');
    op.value=m;
    op.textContent=m;
    els.modelSelect.appendChild(op);
  });

  const savedModel = String(readPref(PREF_KEYS.model, '') || '');
  const initialModel = savedModel && models.includes(savedModel)
    ? savedModel
    : (j.default || models[0] || '');

  els.modelSelect.value = initialModel || '';
  if (initialModel) writePref(PREF_KEYS.model, initialModel);

  initAgent();
}

function renderAttachmentList(){
  els.attachList.innerHTML='';
  state.attachments.forEach(function(a, idx){
    const chip=document.createElement('div');
    chip.className='attach-chip';
    const link = a.url ? ('<a class="attach-link" href="' + esc(a.url) + '" target="_blank" rel="noopener">' + esc(a.name) + '</a>') : ('<span>' + esc(a.name) + '</span>');
    chip.innerHTML=link + '<span class="attach-x">&times;</span>';
    chip.querySelector('.attach-x').onclick=function(){
      if(a.stored_name){
        fetch('?action=delete_file', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({name: a.stored_name})
        }).catch(function(){});
      }
      state.attachments.splice(idx,1);
      renderAttachmentList();
    };
    els.attachList.appendChild(chip);
  });
}

async function loadChat(id, jumpIndex=-1){
  if(state.generating && !confirm('当前在生成，确定切换？')) return;
  await stopGeneration(true);
  const chat = readChat(id);
  if(!chat) return alert('会话不存在或已被删除');
  state.id=chat.id;
  state.title=chat.title||'无标题';
  state.messages=chat.messages||[];
  state.titleLocked = chat.title_locked || false;
  setHeader();
  rerenderMessages();
  loadHistory();
  if (isMobileView()) closeSide();

  if (jumpIndex >= 0) {
    state.autoScroll = false;
    setTimeout(function(){
      const target = els.msgs.querySelector('.msg[data-idx="'+jumpIndex+'"]');
      if (target) {
        target.scrollIntoView({ behavior:'smooth', block:'center' });
        target.style.boxShadow = '0 0 0 2px rgba(0,0,0,.25)';
        setTimeout(function(){ target.style.boxShadow=''; }, 1200);
      }
    }, 50);
  }
}

function stopClientOnly(){
  if(state.source){state.source.close();state.source=null;}
  state.generating=false;
  state.isReasoning=false;
  state.reasoningMsgIndex=-1;
  state.currentStopToken='';
  setSendBtn();
}

async function stopGeneration(silent){
  if (!state.generating) {
    stopClientOnly();
    return;
  }
  const token = state.currentStopToken;
  if (token) {
    try {
      await fetch('?action=stop_chat', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({stop_token: token})
      });
    } catch (e) {}
  }
  if (state.isReasoning && state.reasoningMsgIndex >= 0 && state.messages[state.reasoningMsgIndex]) {
    state.messages[state.reasoningMsgIndex].reasoning_content = state.currentReasoning;
  }
  if (state.source) {
    state.source.close();
    state.source = null;
  }
  state.generating = false;
  state.isReasoning = false;
  state.reasoningMsgIndex = -1;
  state.currentStopToken = '';
  setSendBtn();
  if (state.id && state.messages.length) await persistMessages();
}

function newChat(){
  stopClientOnly();
  state.id=null;
  state.title='新会话';
  state.messages=[];
  state.attachments=[];
  state.autoScroll=true;
  state.titleLocked=false;
  state.currentReasoning='';
  state.isReasoning=false;
  state.reasoningMsgIndex=-1;
  state.currentStopToken='';
  setHeader();
  rerenderMessages();
  renderAttachmentList();
  loadHistory();
  if (isMobileView()) closeSide();
}

function toggleSelectAll(){
  const view = state.searchKey
    ? state.history.filter(function(it){
        const titleHit = (it.title || '').toLowerCase().indexOf(state.searchKey.toLowerCase()) >= 0;
        const contentHit = !!state.searchHits[it.id];
        return titleHit || contentHit;
      })
    : state.history;

  const ids = view.map(function(it){ return it.id; });
  const allSelected = ids.length > 0 && ids.every(function(id){ return state.selected.has(id); });

  if (allSelected) {
    ids.forEach(function(id){ state.selected.delete(id); });
    els.selectAllBtn.textContent = '全选';
  } else {
    ids.forEach(function(id){ state.selected.add(id); });
    els.selectAllBtn.textContent = '取消全选';
  }
  renderHistory();
}

async function uploadOneFile(file){
  const fd = new FormData();
  fd.append('file', file);
  const r = await fetch('?action=upload', { method:'POST', body:fd });
  const j = await r.json();
  if (!j.ok) throw new Error(j.error || '上传失败');
  return j.file;
}

async function regenerateChatTitle(id, titleEl){
  if (!id) return;
  const chat = readChat(id);
  if (!chat) return alert('会话不存在');
  if (chat.title_locked) return;
  if (titleEl && titleEl.dataset.busy === '1') return;

  const oldHtml = titleEl ? titleEl.innerHTML : '';
  if (titleEl) {
    titleEl.dataset.busy = '1';
    titleEl.textContent = '生成中...';
    titleEl.style.opacity = '.65';
  }

  try {
    const r = await fetch('?action=title_ai', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({messages:chat.messages||[]})
    });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || '重新生成标题失败');

    if (j.title) {
      chat.title = j.title;
      writeChat(chat);
      if (state.id === id) {
        state.title = j.title;
        setHeader();
      }
    }

    await loadHistory();
  } catch (e) {
    if (titleEl) titleEl.innerHTML = oldHtml || '无标题';
    alert((e && e.message) || '重新生成标题失败');
  } finally {
    if (titleEl) {
      titleEl.style.opacity = '';
      titleEl.removeAttribute('data-busy');
    }
  }
}

function hasDraggedFiles(dt){
  if (!dt) return false;
  if (dt.files && dt.files.length > 0) return true;
  const types = Array.from(dt.types || []);
  return types.indexOf('Files') !== -1;
}

async function handleSelectedFiles(fileList){
  const files = Array.from(fileList || []);
  if (!files.length) return;

  const maxAttachments = 6;
  const remain = Math.max(0, maxAttachments - state.attachments.length);

  if (remain <= 0) {
    alert('单次最多添加 6 个附件，请先移除一些附件再上传');
    return;
  }

  const queue = files.slice(0, remain);
  if (files.length > remain) {
    alert('最多再添加 ' + remain + ' 个附件，其余已跳过');
  }

  for (const f of queue) {
    if (f.size > 15 * 1024 * 1024) {
      alert(f.name + ' 超过 15MB，已跳过');
      continue;
    }
    try {
      const uploaded = await uploadOneFile(f);
      state.attachments.push({
        name: uploaded.name || f.name || 'file',
        mime: uploaded.mime || f.type || 'application/octet-stream',
        size: uploaded.size || f.size || 0,
        stored_name: uploaded.stored_name || '',
        url: uploaded.url || '',
        is_image: !!uploaded.is_image
      });
      renderAttachmentList();
    } catch (err) {
      alert(f.name + ' 上传失败: ' + (err && err.message ? err.message : '未知错误'));
    }
  }
}

function bindInputDragUpload(){
  document.addEventListener('dragover', function(e){
    if (hasDraggedFiles(e.dataTransfer)) e.preventDefault();
  });

  document.addEventListener('drop', function(e){
    if (!hasDraggedFiles(e.dataTransfer)) return;
    if (e.target !== els.input) e.preventDefault();
    els.input.classList.remove('dragover');
  });

  els.input.addEventListener('dragenter', function(e){
    if (!hasDraggedFiles(e.dataTransfer)) return;
    e.preventDefault();
    els.input.classList.add('dragover');
  });

  els.input.addEventListener('dragover', function(e){
    if (!hasDraggedFiles(e.dataTransfer)) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    els.input.classList.add('dragover');
  });

  els.input.addEventListener('dragleave', function(){
    els.input.classList.remove('dragover');
  });

  els.input.addEventListener('drop', function(e){
    if (!hasDraggedFiles(e.dataTransfer)) return;
    e.preventDefault();
    e.stopPropagation();
    els.input.classList.remove('dragover');
    handleSelectedFiles(e.dataTransfer.files);
    els.input.focus();
  });
}

async function createChatStream(message, model, attachments, contextMessages){
  const agent = currentAgentConfig();
  const r = await fetch('?action=chat_prepare', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({
      model:model,
      message:message,
      user_role:agent.roleKey,
      custom_prompt:agent.customPrompt,
      temperature:agent.temperature,
      attachments:attachments,
      messages:contextMessages,
      title_locked:!!state.titleLocked,
      current_title:state.title || '新会话'
    })
  });
  const j = await r.json();
  if (!j.ok || !j.token) throw new Error(j.error || '创建会话流失败');
  return j;
}

async function sendWithText(text, fromRetry){
  if(state.generating) return;
  const t=(text||'').trim();
  if(!t) return;

  state.generating=true;
  state.autoScroll = true;
  state.isReasoning = false;
  state.currentReasoning = '';
  state.currentStopToken = '';
  setSendBtn();

  if(!state.id){
    state.id = genChatId();
    state.title = state.title || '新会话';
    setHeader();
  }

  const sendingAttachments = state.attachments.slice(0);
  state.attachments = [];
  renderAttachmentList();

  if(!fromRetry){
    state.messages.push({
      role:'user',
      content:t,
      attachments:sendingAttachments
    });
    addMsgDom(state.messages[state.messages.length-1], state.messages.length-1);
  }

  const currentModel=els.modelSelect.value;
  const a={role:'assistant',content:'',model:currentModel};
  state.messages.push(a);
  const aIdx=state.messages.length-1;
  const aDom=addMsgDom(a,aIdx);
  const roleEl=aDom.querySelector('.role');
  if(roleEl) roleEl.textContent=prettyModelName(currentModel);

  let es = null;
  try {
    const contextMessages = state.messages.slice(0, Math.max(0, state.messages.length - 2));
    const prep = await createChatStream(t, currentModel, sendingAttachments, contextMessages);
    if (prep.stop_token) state.currentStopToken = prep.stop_token;
    es = new EventSource('?action=chat&token=' + encodeURIComponent(prep.token));
    state.source = es;
  } catch (err) {
    alert((err && err.message) || '发送失败');
    state.messages.pop();
    if(!fromRetry) state.messages.pop();
    rerenderMessages();
    state.attachments = sendingAttachments.concat(state.attachments);
    renderAttachmentList();
    stopClientOnly();
    return;
  }

  es.onmessage=function(ev){
    let d={};
    try{ d=JSON.parse(ev.data); }catch(e){ return; }

    if(d.type==='meta'){
      if(d.stop_token) state.currentStopToken = d.stop_token;
    }else if(d.type==='reasoning_start'){
      state.isReasoning = true;
      state.reasoningMsgIndex = aIdx;
      state.currentReasoning = '';
      updateReasoningButton(aIdx, true, false);

      if (els.reasoningModalMask.style.display === 'flex' && state.viewingReasoningIndex === aIdx) {
        if (!state.isEditingReasoning) {
          els.reasoningView.innerHTML = '';
          renderRich(els.reasoningView);
        }
      }
    }else if(d.type==='reasoning_delta'){
      if (!(state.isEditingReasoning && state.viewingReasoningIndex === aIdx)) {
        state.currentReasoning += d.text || '';
      }
      updateReasoningButton(aIdx, true, true);

      if (els.reasoningModalMask.style.display === 'flex' && state.viewingReasoningIndex === aIdx) {
        if (state.isEditingReasoning) {
        } else {
          const snap = captureInteractiveState(els.reasoningView);
          els.reasoningView.innerHTML = md(normalizeLatexText(state.currentReasoning));
          const body = els.reasoningModal.querySelector('.reasoning-body');
          if (body) body.scrollTop = body.scrollHeight;
          renderRich(els.reasoningView, snap);
        }
      }
    }else if(d.type==='reasoning_end'){
      state.isReasoning = false;
      state.messages[aIdx].reasoning_content = state.currentReasoning;
      updateReasoningButton(aIdx, false, true);
    }else if(d.type==='delta'){
      a.content += d.text || '';
      state.messages[aIdx].content = a.content;
      updateMessageContentDom(aIdx);
      if(state.autoScroll) els.msgs.scrollTop = els.msgs.scrollHeight;
    }else if(d.type==='title_updated'){
      if(d.title){
        state.title = d.title;
        setHeader();
        loadHistory();
      }
    }else if(d.type==='stopped'){
      if (state.isReasoning && state.reasoningMsgIndex === aIdx) {
        state.messages[aIdx].reasoning_content = state.currentReasoning;
        updateReasoningButton(aIdx, false, true);
      }
      stopClientOnly();
      persistMessages();
    }else if(d.type==='done'){
      a.model=d.model||currentModel;
      state.lastAssistantModel=a.model;
      if(roleEl) roleEl.textContent=prettyModelName(a.model);
      if(d.title) state.title = d.title;
      if (state.isReasoning && state.reasoningMsgIndex === aIdx) {
        state.messages[aIdx].reasoning_content = state.currentReasoning;
        updateReasoningButton(aIdx, false, true);
      }
      setHeader();
      stopClientOnly();
      persistMessages();
    }else if(d.type==='error'){
      alert(d.message||'生成失败');
      if (state.isReasoning && state.reasoningMsgIndex === aIdx) {
        state.messages[aIdx].reasoning_content = state.currentReasoning;
        updateReasoningButton(aIdx, false, true);
      }
      stopClientOnly();
      persistMessages();
    }
  };

  es.onerror=function(){
    if (!state.generating) return;
    if (state.isReasoning && state.reasoningMsgIndex >= 0 && state.messages[state.reasoningMsgIndex]) {
      state.messages[state.reasoningMsgIndex].reasoning_content = state.currentReasoning;
      updateReasoningButton(state.reasoningMsgIndex, false, true);
    }
    stopClientOnly();
    persistMessages();
  };
}

function sendOrStop(){
  if(state.generating){
    stopGeneration(false);
    return;
  }
  const t=els.input.value.trim();
  if(!t && state.attachments.length===0) return;
  const finalText = t || '请分析我上传的附件';
  els.input.value='';
  sendWithText(finalText,false);
}

function enableChatTitleEdit(){
  els.chatTitle.ondblclick=function(){
    if(!state.id) return;
    const input=document.createElement('input');
    input.value=state.title||'新会话';
    input.className='btn';
    input.style.fontSize='18px';
    input.style.width='260px';
    const parent=els.chatTitle.parentElement;
    parent.replaceChild(input,els.chatTitle);
    input.focus();
    input.select();

    const save=function(){
      const t=input.value.trim()||'新会话';
      state.title=t;
      if (state.id) writeChat(currentChatObject());
      setHeader();
      parent.replaceChild(els.chatTitle,input);
      loadHistory();
      enableChatTitleEdit();
    };

    input.addEventListener('blur',save,{once:true});
    input.addEventListener('keydown',function(e){
      if(e.key==='Enter') input.blur();
      if(e.key==='Escape'){
        parent.replaceChild(els.chatTitle,input);
        enableChatTitleEdit();
      }
    });
  };
}

function renderOverviewRichMessage(m){
  return '<div class="msg-content">' + md(normalizeLatexText(m.content || '')) + '</div>' + (m.role === 'user' ? renderUserAttachments(m) : '');
}

function openOverview(){
  if(!state.id && state.messages.length === 0) {
    alert('当前没有聊天记录');
    return;
  }
  setModalFullscreenBtn(els.overviewFullscreenBtn, els.overviewModal.classList.contains('fullscreen'));
  els.overviewModalMask.style.display = 'flex';
  els.overviewSearchInput.value = '';
  renderOverview('');
}
function closeOverview(){
  els.overviewModalMask.style.display = 'none';
  resetModalFullscreen(els.overviewModal, els.overviewFullscreenBtn);
}
function renderOverview(keyword){
  const container = els.overviewContent;
  container.innerHTML = '';
  const msgs = state.messages;
  if(msgs.length === 0){
    container.innerHTML = '<div style="text-align:center; color:#666; padding:20px;">当前会话无消息</div>';
    return;
  }

  const kw = keyword ? keyword.trim() : '';
  const kwLower = kw ? kw.toLowerCase() : '';
  const list = [];

  if (kwLower) {
    msgs.forEach(function(m, idx){
      const content = m.content || '';
      if (content.toLowerCase().indexOf(kwLower) !== -1) list.push(idx);
    });
  } else {
    for(let i=0; i<msgs.length; i++) list.push(i);
  }

  if (list.length === 0) {
    container.innerHTML = '<div style="text-align:center; color:#666; padding:20px;">未找到匹配内容</div>';
    return;
  }

  list.forEach(function(idx){
    const m = msgs[idx];
    const content = m.content || '';
    const item = document.createElement('div');
    item.className = 'overview-item';
    const role = m.role === 'user' ? 'User' : (prettyModelName(m.model) || 'Assistant');

    let html = '<div class="overview-item-header">';
    html += '<span class="overview-item-role">' + esc(role) + '</span>';
    html += '<div class="overview-item-actions">';
    html += '<button class="btn iconbtn overview-copy" data-idx="'+idx+'">复制</button>';
    html += '<button class="btn iconbtn overview-jump" data-idx="'+idx+'">跳转</button>';
    html += '<button class="btn iconbtn overview-expand">展开</button>';
    html += '</div></div>';
    html += '<div class="overview-item-content">';

    if (kw) {
      let previewContent = content;
      if (content.length > 500) previewContent = content.slice(0, 500);
      html += '<div class="overview-item-preview">' + highlightAll(previewContent, kw) + '</div>';
      html += '<div class="overview-item-full" style="display:none;">' + highlightAll(content, kw) + '</div>';
    } else {
      html += '<div class="overview-item-preview">' + renderOverviewRichMessage(m) + '</div>';
      html += '<div class="overview-item-full" style="display:none;">' + renderOverviewRichMessage(m) + '</div>';
    }

    html += '</div>';
    item.innerHTML = html;

    const expandBtn = item.querySelector('.overview-expand');
    const previewDiv = item.querySelector('.overview-item-preview');
    const fullDiv = item.querySelector('.overview-item-full');

    if (!kw) {
      renderRich(previewDiv);
      renderRich(fullDiv);
    }

    item.querySelector('.overview-copy').onclick = function(){
      copyText(content).then(() => {
        const old = this.textContent;
        this.textContent = '已复制';
        this.style.color = '#111';
        setTimeout(() => {
          this.textContent = old;
          this.style.color = '';
        }, 1200);
      });
    };

    expandBtn.onclick = function(){
      if(fullDiv.style.display === 'none'){
        fullDiv.style.display = 'block';
        previewDiv.style.display = 'none';
        expandBtn.textContent = '收起';
      } else {
        fullDiv.style.display = 'none';
        previewDiv.style.display = '';
        expandBtn.textContent = '展开';
      }
    };

    item.querySelector('.overview-jump').onclick = function(){
      const targetIdx = Number(this.dataset.idx);
      closeOverview();
      state.autoScroll = false;
      setTimeout(function(){
        const target = els.msgs.querySelector('.msg[data-idx="'+targetIdx+'"]');
        if(target){
          target.scrollIntoView({ behavior:'smooth', block:'center' });
          target.style.boxShadow = '0 0 0 2px rgba(0,0,0,.25)';
          setTimeout(function(){ target.style.boxShadow=''; }, 1200);
        }
      }, 50);
    };

    container.appendChild(item);
  });
}

els.msgs.addEventListener('scroll', function(){
  const threshold = 50;
  const scrollTop = els.msgs.scrollTop;
  const scrollHeight = els.msgs.scrollHeight;
  const clientHeight = els.msgs.clientHeight;
  const isAtBottom = scrollHeight - scrollTop - clientHeight < threshold;
  if(isAtBottom) state.autoScroll = true;
  else if(state.autoScroll) state.autoScroll = false;
});

els.sendBtn.onclick=sendOrStop;
els.input.addEventListener('keydown',function(e){
  if(e.key==='Enter'&&!e.shiftKey){
    e.preventDefault();
    sendOrStop();
  }
});

els.openSideBtn.onclick = function(){
  setSidebarOpen(!isSidebarOpen(), true);
};

els.backdrop.onclick=function(){
  if (isMobileView()) closeSide();
};

els.newBtn.onclick=newChat;
els.searchBtn.onclick=function(){ setSearchMode(!state.searchMode); };
els.searchInput.oninput = function(){
  state.searchKey = els.searchInput.value.trim();
  loadHistory();
};

els.bulkModeBtn.onclick=function(){
  state.bulkMode=true;
  state.selected.clear();
  updateBulkUI();
  renderHistory();
};
els.bulkCancelBtn.onclick=function(){
  state.bulkMode=false;
  state.selected.clear();
  updateBulkUI();
  renderHistory();
};
els.selectAllBtn.onclick=toggleSelectAll;

els.bulkPinBtn.onclick=function(){
  const ids=[...state.selected];
  if(!ids.length) return alert('未选择');
  const idx = readChatIndex();
  idx.forEach(function(x){ if (state.selected.has(x.id)) x.pinned = true; });
  writeChatIndex(idx);
  state.selected.clear();
  loadHistory();
};
els.bulkUnpinBtn.onclick=function(){
  const ids=[...state.selected];
  if(!ids.length) return alert('未选择');
  const idx = readChatIndex();
  idx.forEach(function(x){ if (state.selected.has(x.id)) x.pinned = false; });
  writeChatIndex(idx);
  state.selected.clear();
  loadHistory();
};
els.bulkDelBtn.onclick=function(){
  const ids=[...state.selected];
  if(!ids.length) return alert('未选择');
  if(!confirm('确认删除 ' + ids.length + ' 个会话？')) return;
  ids.forEach(function(id){ cleanupChatAttachments(readChat(id)); removeChatFromStore(id); });
  if(state.id&&ids.includes(state.id)) newChat();
  state.selected.clear();
  loadHistory();
};

els.wideModeBtn.onclick=function(){
  setWideMode(!state.wideMode);
};

els.uploadBtn.onclick=function(){ els.fileInput.click(); };
els.fileInput.onchange=function(){
  handleSelectedFiles(els.fileInput.files);
  els.fileInput.value='';
};

els.modelSelect.onchange = function(){
  writePref(PREF_KEYS.model, els.modelSelect.value || '');
};

els.agentBtn.onclick = openAgentModal;
els.agentCloseBtn.onclick = closeAgentModal;
els.agentModalMask.onclick = function(e){
  if (e.target === els.agentModalMask) closeAgentModal();
};
els.agentTempInput.oninput = function(){
  els.agentTempVal.textContent = numTemp(els.agentTempInput.value).toFixed(1);
};
els.agentApplyBtn.onclick = function(){
  if (!state.agentModalSel) return;
  state.agentSel = state.agentModalSel;
  writeAgentPref(state.agentSel);
  updateAgentButton();
  closeAgentModal();
};
els.agentSaveBtn.onclick = function(){
  const name = els.agentNameInput.value.trim();
  if (!name){ alert('请填写智能体名称'); return; }
  const prompt = els.agentPromptInput.value;
  const temp = numTemp(els.agentTempInput.value);
  const list = readCustomAgents();
  let id = null;
  if (state.agentModalSel && state.agentModalSel.type === 'custom'){
    for (let i=0;i<list.length;i++){
      if (list[i].id === state.agentModalSel.id){
        list[i].name = name;
        list[i].prompt = prompt;
        list[i].temperature = temp;
        id = list[i].id;
        break;
      }
    }
  }
  if (!id){
    id = genAgentId();
    list.push({id:id, name:name, prompt:prompt, temperature:temp});
  }
  writeCustomAgents(list);
  state.agentSel = {type:'custom', id:id};
  state.agentModalSel = state.agentSel;
  writeAgentPref(state.agentSel);
  updateAgentButton();
  renderAgentList();
  fillAgentForm();
};
els.agentDeleteBtn.onclick = function(){
  if (!state.agentModalSel || state.agentModalSel.type !== 'custom') return;
  if (!confirm('确定删除该自定义智能体？')) return;
  const id = state.agentModalSel.id;
  writeCustomAgents(readCustomAgents().filter(function(c){ return c.id !== id; }));
  if (state.agentSel && state.agentSel.type === 'custom' && state.agentSel.id === id){
    state.agentSel = {type:'preset', key:'default'};
    writeAgentPref(state.agentSel);
    updateAgentButton();
  }
  state.agentModalSel = {type:'preset', key:'default'};
  renderAgentList();
  fillAgentForm();
};
initAgent();

els.editFullscreenBtn.onclick = function(){ toggleModalFullscreen(els.editModal, els.editFullscreenBtn); };
els.reasoningFullscreenBtn.onclick = function(){ toggleModalFullscreen(els.reasoningModal, els.reasoningFullscreenBtn); };
els.overviewFullscreenBtn.onclick = function(){ toggleModalFullscreen(els.overviewModal, els.overviewFullscreenBtn); };

els.editCancelBtn.onclick = closeEditModal;
els.editSaveBtn.onclick = function(){
  if (state.editingIndex < 0) return;
  const idx = state.editingIndex;
  state.messages[idx].content = els.editModalText.value;
  persistMessages().then(function(){
    closeEditModal();
    updateMessageContentDom(idx);
  });
};
els.editModalMask.onclick = function(e){
  if (e.target === els.editModalMask) closeEditModal();
};

els.reasoningCloseBtn.onclick = closeReasoningModal;
els.reasoningEditBtn.onclick = toggleReasoningEdit;
els.reasoningModalMask.onclick = function(e){
  if (e.target === els.reasoningModalMask) closeReasoningModal();
};

els.overviewBtn.onclick = openOverview;
els.overviewCloseBtn.onclick = closeOverview;
els.overviewModalMask.onclick = function(e){
  if (e.target === els.overviewModalMask) closeOverview();
};
els.overviewSearchInput.oninput = function(){
  renderOverview(els.overviewSearchInput.value);
};

window.addEventListener('resize', function(){
  applySidebarState();
});

(function init(){
  loadUIPreferences();
  setHeader();
  setSendBtn();
  updateBulkUI();
  enableChatTitleEdit();
  renderAttachmentList();
  bindInputDragUpload();
  setModalFullscreenBtn(els.editFullscreenBtn, false);
  setModalFullscreenBtn(els.reasoningFullscreenBtn, false);
  setModalFullscreenBtn(els.overviewFullscreenBtn, false);
  setWideMode(state.wideMode);
  applySidebarState();

  loadModels().then(function(){
    return maybeImportServerChats();
  }).then(function(){
    loadHistory();
  }).catch(function(e){
    console.error(e);
    alert('初始化失败，请打开控制台查看错误');
  });
})();
</script>
</body>
</html>
