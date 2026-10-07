<?php
// Recebe o formulário de contato do site e registra o lead.
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { json_out(['ok' => false, 'erro' => 'Método não permitido.'], 405); }
exigir_mesma_origem();

$in = body_json();

// Campo-armadilha: pessoas não veem nem preenchem; robôs costumam preencher.
if (campo($in, 'site', 200) !== '') { json_out(['ok' => true]); }

$lead = novo_lead($in, 'site');
$lead['notas'] = '';
$lead['retorno'] = '';
$digitos = preg_replace('/\D+/', '', $lead['tel']) ?? '';
if ($lead['nome'] === '' || strlen($digitos) < 8) { json_out(['ok' => false, 'erro' => 'Informe nome e WhatsApp.'], 422); }

// Limite simples por endereço de rede: 8 envios por hora.
$ip = ip_cliente();
$liberado = store_update('limite', function (array $d) use ($ip) {
    $agora = time();
    foreach ($d as $k => $lista) {
        $d[$k] = array_values(array_filter(is_array($lista) ? $lista : [], function ($t) use ($agora) { return $t > $agora - 3600; }));
        if (!$d[$k]) { unset($d[$k]); }
    }
    $n = count($d[$ip] ?? []);
    if ($n >= 8) { return [$d, false]; }
    $d[$ip][] = $agora;
    return [$d, true];
});
if (!$liberado) { json_out(['ok' => false, 'erro' => 'Muitos envios. Tente mais tarde.'], 429); }

store_update('leads', function (array $d) use ($lead) {
    $d[] = $lead;
    return [$d, true];
});

json_out(['ok' => true]);
