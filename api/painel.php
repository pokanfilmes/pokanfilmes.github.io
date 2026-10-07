<?php
// API do painel de leads. Tudo exige login, exceto consultar o estado, configurar a senha no primeiro acesso e entrar.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$hostAtual = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if (!$https && preg_match('/(^|\.)pokanfilmes\.com\.br$/', $hostAtual)) { json_out(['ok' => false, 'erro' => 'Abra o painel pelo endereço com https.'], 400); }
session_name('pokan_painel');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$acao = (string)($_GET['a'] ?? '');
$metodo = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$post = $metodo === 'POST';
if ($post) { exigir_mesma_origem(); }
$in = $post ? body_json() : [];

function logado(): bool { return !empty($_SESSION['ok']); }
function csrf(): string {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return (string)$_SESSION['csrf'];
}
function exigir_login(bool $post): void {
    if (!logado()) { json_out(['ok' => false, 'erro' => 'Sessão encerrada. Entre de novo.'], 401); }
    if ($post) {
        $t = (string)($_SERVER['HTTP_X_CSRF'] ?? '');
        if ($t === '' || !hash_equals(csrf(), $t)) { json_out(['ok' => false, 'erro' => 'Sessão inválida. Recarregue a página.'], 403); }
    }
}
function abrir_sessao(): void {
    session_regenerate_id(true);
    $_SESSION['ok'] = 1;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$config = store_read('config');
$configurado = !empty($config['senha']);

if ($acao === 'estado') {
    json_out(['ok' => true, 'configurado' => $configurado, 'logado' => logado(), 'csrf' => logado() ? csrf() : '', 'https' => $https]);
}

if ($acao === 'configurar' && $post) {
    $senha = (string)($in['senha'] ?? '');
    if (strlen($senha) < 10) { json_out(['ok' => false, 'erro' => 'A senha precisa ter pelo menos 10 caracteres.'], 422); }
    $feito = store_update('config', function (array $d) use ($senha) {
        if (!empty($d['senha'])) { return [null, false]; }
        $d['senha'] = password_hash($senha, PASSWORD_DEFAULT);
        $d['criado'] = date('c');
        return [$d, true];
    });
    if (!$feito) { json_out(['ok' => false, 'erro' => 'O painel já tem senha. Entre com ela.'], 409); }
    abrir_sessao();
    json_out(['ok' => true, 'csrf' => csrf()]);
}

if ($acao === 'entrar' && $post) {
    if (!$configurado) { json_out(['ok' => false, 'erro' => 'Painel ainda sem senha.'], 409); }
    $ip = ip_cliente();
    $bloqueio = store_update('tentativas', function (array $d) use ($ip) {
        $agora = time();
        foreach ($d as $k => $v) { if (($v['ate'] ?? 0) < $agora && ($v['ultima'] ?? 0) < $agora - 900) { unset($d[$k]); } }
        $ate = (int)($d[$ip]['ate'] ?? 0);
        return [$d, $ate > $agora ? $ate - $agora : 0];
    });
    if ($bloqueio > 0) { json_out(['ok' => false, 'erro' => 'Muitas tentativas. Aguarde ' . (int)ceil($bloqueio / 60) . ' min.'], 429); }
    $certa = password_verify((string)($in['senha'] ?? ''), (string)$config['senha']);
    store_update('tentativas', function (array $d) use ($ip, $certa) {
        if ($certa) { unset($d[$ip]); return [$d, true]; }
        $n = (int)($d[$ip]['n'] ?? 0) + 1;
        $d[$ip] = ['n' => $n, 'ultima' => time(), 'ate' => $n >= 5 ? time() + 900 : 0];
        if ($n >= 5) { $d[$ip]['n'] = 0; }
        return [$d, true];
    });
    if (!$certa) { usleep(400000); json_out(['ok' => false, 'erro' => 'Senha incorreta.'], 401); }
    abrir_sessao();
    json_out(['ok' => true, 'csrf' => csrf()]);
}

if ($acao === 'sair' && $post) {
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true]);
}

if ($acao === 'leads') {
    exigir_login(false);
    $leads = store_read('leads');
    usort($leads, function ($a, $b) { return strcmp((string)($b['criado'] ?? ''), (string)($a['criado'] ?? '')); });
    json_out(['ok' => true, 'leads' => array_values($leads)]);
}

if ($acao === 'criar' && $post) {
    exigir_login(true);
    $lead = novo_lead($in, 'manual');
    if ($lead['nome'] === '') { json_out(['ok' => false, 'erro' => 'Informe o nome.'], 422); }
    $st = campo($in, 'status', 20);
    if (in_array($st, STATUS_VALIDOS, true)) { $lead['status'] = $st; }
    store_update('leads', function (array $d) use ($lead) { $d[] = $lead; return [$d, true]; });
    json_out(['ok' => true, 'lead' => $lead]);
}

if ($acao === 'atualizar' && $post) {
    exigir_login(true);
    $id = campo($in, 'id', 40);
    $lead = store_update('leads', function (array $d) use ($id, $in) {
        foreach ($d as $i => $l) {
            if (($l['id'] ?? '') !== $id) { continue; }
            $limites = ['nome' => 120, 'empresa' => 120, 'tel' => 40, 'seg' => 80, 'pacote' => 80, 'quando' => 60, 'msg' => 3000, 'notas' => 5000];
            foreach ($limites as $k => $max) { if (array_key_exists($k, $in)) { $l[$k] = campo($in, $k, $max); } }
            if (array_key_exists('status', $in) && in_array($in['status'], STATUS_VALIDOS, true)) { $l['status'] = $in['status']; }
            if (array_key_exists('retorno', $in)) {
                $r = campo($in, 'retorno', 10);
                $l['retorno'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $r) ? $r : '';
            }
            $l['atualizado'] = date('c');
            $d[$i] = $l;
            return [$d, $l];
        }
        return [null, null];
    });
    if (!$lead) { json_out(['ok' => false, 'erro' => 'Lead não encontrado.'], 404); }
    json_out(['ok' => true, 'lead' => $lead]);
}

if ($acao === 'excluir' && $post) {
    exigir_login(true);
    $id = campo($in, 'id', 40);
    $ok = store_update('leads', function (array $d) use ($id) {
        $novo = array_values(array_filter($d, function ($l) use ($id) { return ($l['id'] ?? '') !== $id; }));
        return count($novo) === count($d) ? [null, false] : [$novo, true];
    });
    if (!$ok) { json_out(['ok' => false, 'erro' => 'Lead não encontrado.'], 404); }
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'erro' => 'Ação desconhecida.'], 404);
