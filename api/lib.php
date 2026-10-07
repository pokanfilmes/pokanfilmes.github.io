<?php
// Funções compartilhadas pelo registro de leads e pelo painel.
// Os dados ficam em arquivos JSON fora da pasta pública do site (pokan-dados/, ao lado de public_html).
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

const STATUS_VALIDOS = ['novo', 'contato', 'proposta', 'fechado', 'perdido'];

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function data_dir(): string {
    $candidatos = [];
    $root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($root !== '') { $candidatos[] = dirname($root) . '/pokan-dados'; }
    $candidatos[] = __DIR__ . '/.dados';
    foreach ($candidatos as $d) {
        if (!is_dir($d)) { @mkdir($d, 0700, true); }
        if (is_dir($d) && is_writable($d)) {
            if (!file_exists($d . '/.htaccess')) { @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); }
            return $d;
        }
    }
    json_out(['ok' => false, 'erro' => 'Armazenamento indisponível no servidor.'], 500);
    return '';
}

// Lê, altera e grava um arquivo JSON com trava exclusiva. $fn recebe os dados atuais e devolve [novosDados, resultado].
function store_update(string $nome, callable $fn) {
    $path = data_dir() . '/' . $nome . '.json';
    $fh = fopen($path, 'c+');
    if ($fh === false) { json_out(['ok' => false, 'erro' => 'Não foi possível abrir o armazenamento.'], 500); }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = [];
    if (is_string($raw) && trim($raw) !== '') {
        $dec = json_decode($raw, true);
        if (is_array($dec)) { $data = $dec; }
        else { @copy($path, $path . '.corrompido-' . date('Ymd-His')); }
    }
    [$novo, $resultado] = $fn($data);
    if ($novo !== null) {
        $json = json_encode($novo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $json);
            fflush($fh);
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $resultado;
}

function store_read(string $nome): array {
    return store_update($nome, function (array $d) { return [null, $d]; });
}

function body_json(): array {
    $raw = file_get_contents('php://input');
    $d = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($d)) { $d = $_POST; }
    return is_array($d) ? $d : [];
}

function campo(array $d, string $k, int $max): string {
    $v = $d[$k] ?? '';
    if (!is_string($v) && !is_numeric($v)) { return ''; }
    $v = trim(str_replace("\0", '', (string)$v));
    if (function_exists('mb_substr')) { return mb_substr($v, 0, $max, 'UTF-8'); }
    return substr($v, 0, $max);
}

function ip_cliente(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

// Recusa pedidos vindos de outro site.
function exigir_mesma_origem(): void {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') { return; }
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $oh = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $op = parse_url($origin, PHP_URL_PORT);
    if ($op) { $oh .= ':' . $op; }
    $semWww = function (string $h): string { return preg_replace('/^www\./', '', $h) ?? $h; };
    if ($semWww($oh) !== $semWww($host)) { json_out(['ok' => false, 'erro' => 'Origem não permitida.'], 403); }
}

function novo_lead(array $in, string $origem): array {
    $agora = date('c');
    return [
        'id' => bin2hex(random_bytes(8)),
        'criado' => $agora,
        'atualizado' => $agora,
        'origem' => $origem,
        'status' => 'novo',
        'nome' => campo($in, 'nome', 120),
        'empresa' => campo($in, 'empresa', 120),
        'tel' => campo($in, 'tel', 40),
        'seg' => campo($in, 'seg', 80),
        'pacote' => campo($in, 'pacote', 80),
        'quando' => campo($in, 'quando', 60),
        'msg' => campo($in, 'msg', 3000),
        'campanha' => campo($in, 'campanha', 200),
        'notas' => campo($in, 'notas', 5000),
        'retorno' => preg_match('/^\d{4}-\d{2}-\d{2}$/', campo($in, 'retorno', 10)) ? campo($in, 'retorno', 10) : '',
    ];
}
