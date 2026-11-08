<?php
require_once __DIR__ . '/config.php';

function asaas(string $method, string $path, array $data = []): array {
    $url = ASAAS_URL . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'access_token: ' . ASAAS_KEY,
            'User-Agent: PrintMyCase/1.0',
        ],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    $resp  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) return ['_error' => $error, '_code' => 0];
    $decoded = json_decode($resp, true) ?? [];
    $decoded['_code'] = $code;
    return $decoded;
}

/**
 * Cria cliente no Asaas e retorna o ID
 */
function asaas_criar_cliente(array $dados): ?string {
    $r = asaas('POST', '/customers', [
        'name'             => $dados['nome'],
        'email'            => $dados['email'],
        'mobilePhone'      => $dados['telefone'] ?? '',
        'cpfCnpj'          => preg_replace('/\D/', '', $dados['documento'] ?? ''),
        'notificationDisabled' => false,
    ]);
    return $r['id'] ?? null;
}

/**
 * Cria assinatura mensal e retorna ['subscription_id', 'payment_url']
 */
function asaas_criar_assinatura(string $customerId, int $preco_centavos, string $descricao): array {
    $trial_end = date('Y-m-d', strtotime('+' . TRIAL_DAYS . ' days'));
    $r = asaas('POST', '/subscriptions', [
        'customer'      => $customerId,
        'billingType'   => 'UNDEFINED', // cliente escolhe na hora do pagamento
        'value'         => $preco_centavos / 100,
        'nextDueDate'   => $trial_end,
        'cycle'         => 'MONTHLY',
        'description'   => $descricao,
        'externalReference' => '',
    ]);
    $sub_id = $r['id'] ?? null;
    // Busca link de pagamento da primeira cobrança
    $pay_url = '';
    if ($sub_id) {
        $payments = asaas('GET', "/subscriptions/{$sub_id}/payments");
        $pay_url  = $payments['data'][0]['invoiceUrl'] ?? $payments['data'][0]['bankSlipUrl'] ?? '';
    }
    return ['subscription_id' => $sub_id, 'payment_url' => $pay_url];
}

/**
 * Cancela assinatura no Asaas
 */
function asaas_cancelar_assinatura(string $subscriptionId): bool {
    $r = asaas('DELETE', "/subscriptions/{$subscriptionId}");
    return ($r['_code'] ?? 0) === 200;
}
