<?php
/**
 * Opa Studio — Mailer Helper
 * Envia e-mails transacionais com template HTML
 *
 * Usa PHP mail() nativo — funciona em qualquer hospedagem.
 * Para melhor entregabilidade, configure SPF/DKIM no DNS
 * ou substitua pela função smtp_send() usando SMTP externo.
 */

define('MAIL_FROM_NAME',  'Opa Studio');
define('MAIL_FROM_EMAIL', 'noreply@opadesign.com.br'); // ← troque pelo seu domínio
define('MAIL_REPLY_TO',   'contato@opadesign.com.br');

/**
 * Envia e-mail HTML
 */
function mailer_send(string $to_email, string $to_name, string $subject, string $body_html): bool {
    $from      = MAIL_FROM_EMAIL;
    $from_name = MAIL_FROM_NAME;
    $boundary  = md5(uniqid());

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$from_name} <{$from}>\r\n";
    $headers .= "Reply-To: " . MAIL_REPLY_TO . "\r\n";
    $headers .= "X-Mailer: OpaStudio/1.0\r\n";

    $to = "{$to_name} <{$to_email}>";

    return mail($to, $subject, $body_html, $headers);
}

/**
 * Wrapper com template base
 */
function mailer_template(string $titulo, string $content_html, ?string $cta_url = null, ?string $cta_texto = null): string {
    $cta_block = '';
    if ($cta_url && $cta_texto) {
        $cta_block = "
        <div style='text-align:center;margin:2rem 0'>
          <a href='{$cta_url}'
             style='display:inline-block;background:#78be20;color:#0d0d0d;font-weight:700;
                    font-family:sans-serif;font-size:1rem;padding:14px 36px;border-radius:10px;
                    text-decoration:none;letter-spacing:.5px'>
            {$cta_texto}
          </a>
        </div>";
    }

    return "<!DOCTYPE html>
<html lang='pt-BR'>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#0d0d0d;font-family:DM Sans,Arial,sans-serif'>
  <div style='max-width:560px;margin:2rem auto;background:#141414;border:1px solid #2a2a2a;
              border-radius:16px;overflow:hidden'>

    <!-- Header -->
    <div style='background:#111;border-bottom:1px solid #2a2a2a;padding:1.5rem 2rem;text-align:center'>
      <div style='font-family:Arial Black,sans-serif;font-size:1.3rem;letter-spacing:3px;color:#78be20'>
        ◈ OPA STUDIO
      </div>
      <div style='font-size:.75rem;color:#585858;font-family:monospace;margin-top:4px;letter-spacing:1px'>
        SEU ASSISTENTE DIÁRIO
      </div>
    </div>

    <!-- Body -->
    <div style='padding:2rem 2.5rem'>
      <h2 style='margin:0 0 1rem;font-size:1.4rem;font-weight:700;color:#e8eaf0;letter-spacing:-.5px'>
        {$titulo}
      </h2>
      <div style='color:#8a9bb0;font-size:.95rem;line-height:1.7'>
        {$content_html}
      </div>
      {$cta_block}
    </div>

    <!-- Footer -->
    <div style='background:#111;border-top:1px solid #2a2a2a;padding:1.25rem 2rem;text-align:center'>
      <p style='font-size:.75rem;color:#585858;margin:0;font-family:monospace'>
        Opa Design · Florianópolis, SC<br>
        Você recebeu este e-mail porque se cadastrou no Opa Studio.
      </p>
    </div>
  </div>
</body>
</html>";
}

/**
 * E-mail 1: Confirmação de e-mail
 */
function mailer_confirmacao(string $email, string $nome, string $token): bool {
    $url  = APP_URL . '/confirmar_email.php?token=' . urlencode($token);
    $body = mailer_template(
        'Confirme seu e-mail',
        "<p>Olá, <strong style='color:#e8eaf0'>{$nome}</strong>!</p>
         <p>Obrigado por criar sua conta no Opa Studio. Para ativar o acesso, confirme seu e-mail clicando no botão abaixo:</p>
         <p style='margin:1rem 0;padding:1rem;background:#1e1e1e;border-radius:8px;border:1px solid #2a2a2a;
                   font-family:monospace;font-size:.82rem;color:#78be20;word-break:break-all'>{$url}</p>
         <p style='font-size:.82rem;color:#585858'>Este link expira em <strong style='color:#e8eaf0'>24 horas</strong>. Se não foi você, ignore este e-mail.</p>",
        $url,
        '✓ Confirmar meu e-mail'
    );
    return mailer_send($email, $nome, 'Confirme seu e-mail — Opa Studio', $body);
}

/**
 * E-mail 2: Boas-vindas (após confirmação)
 */
function mailer_boasvindas(string $email, string $nome, string $nome_empresa, int $trial_dias = 7): bool {
    $login_url = APP_URL . '/login.php';
    $body = mailer_template(
        "Bem-vindo ao Opa Studio! 🎉",
        "<p>Olá, <strong style='color:#e8eaf0'>{$nome}</strong>!</p>
         <p>Seu e-mail foi confirmado e a conta da <strong style='color:#e8eaf0'>{$nome_empresa}</strong> está ativa.</p>
         <p>Você tem <strong style='color:#78be20'>{$trial_dias} dias grátis</strong> para explorar tudo:</p>
         <ul style='margin:1rem 0 1rem 1rem;padding:0'>
           <li style='margin-bottom:.5rem'>📋 <strong style='color:#e8eaf0'>Briefings estruturados</strong> — seus clientes preenchem, você produz</li>
           <li style='margin-bottom:.5rem'>✅ <strong style='color:#e8eaf0'>Aprovação com estrelas</strong> — sem mais áudio de WhatsApp</li>
           <li style='margin-bottom:.5rem'>🤖 <strong style='color:#e8eaf0'>IA Criativa</strong> — legendas, hashtags e imagens com DALL-E 3</li>
           <li style='margin-bottom:.5rem'>📊 <strong style='color:#e8eaf0'>Meta Ads Analytics</strong> — métricas de todos os clientes</li>
         </ul>
         <p>Qualquer dúvida, basta responder este e-mail — estamos aqui.</p>",
        $login_url,
        '🚀 Acessar o Opa Studio'
    );
    return mailer_send($email, $nome, "Bem-vindo ao Opa Studio, {$nome}!", $body);
}

/**
 * E-mail para o cliente quando a agência cria a conta dele
 * Envia: URL de acesso, login (e-mail) e senha
 */
function mailer_cliente_acesso(
    string $email_cliente,
    string $nome_cliente,
    string $nome_empresa_cliente,
    string $senha_temporaria,
    string $nome_agencia
): bool {
    $login_url = APP_URL . '/login.php';

    $body = mailer_template(
        "Seu acesso ao Opa Studio está pronto! 🎉",
        "<p>Olá, <strong style='color:#e8eaf0'>{$nome_cliente}</strong>!</p>
         <p>A agência <strong style='color:#e8eaf0'>{$nome_agencia}</strong> criou sua área exclusiva no <strong style='color:#78be20'>Opa Studio</strong>.</p>
         <p>Por aqui você pode:</p>
         <ul style='margin:.75rem 0 1rem 1.25rem;padding:0;line-height:2'>
           <li>📋 Enviar briefings e imagens de referência</li>
           <li>✅ Visualizar e aprovar seus criativos</li>
           <li>⭐ Avaliar cada entrega da agência</li>
           <li>📂 Acessar o histórico completo dos seus projetos</li>
         </ul>
         <p style='margin-bottom:.5rem'>Seus dados de acesso:</p>
         <div style='background:#1e1e1e;border:1px solid #2a2a2a;border-radius:10px;padding:1rem 1.25rem;font-family:monospace;margin:1rem 0'>
           <div style='margin-bottom:.5rem'>
             <span style='color:#585858;font-size:.8rem'>LOGIN (E-MAIL)</span><br>
             <span style='color:#e8eaf0;font-size:.95rem'>{$email_cliente}</span>
           </div>
           <div>
             <span style='color:#585858;font-size:.8rem'>SENHA</span><br>
             <span style='color:#78be20;font-size:1.1rem;font-weight:700;letter-spacing:2px'>{$senha_temporaria}</span>
           </div>
         </div>
         <p style='font-size:.82rem;color:#585858'>Por segurança, recomendamos trocar sua senha após o primeiro acesso.</p>",
        $login_url,
        '🚀 Acessar minha área agora'
    );

    return mailer_send(
        $email_cliente,
        $nome_cliente,
        "Seu acesso ao Opa Studio — {$nome_agencia}",
        $body
    );
}

/**
 * E-mail de redefinição de senha
 */
function mailer_reset_senha(string $email, string $nome, string $token): bool {
    $url  = APP_URL . '/redefinir_senha.php?token=' . urlencode($token);
    $body = mailer_template(
        'Redefinir sua senha',
        "<p>Olá, <strong style='color:#e8eaf0'>{$nome}</strong>!</p>
         <p>Recebemos uma solicitação para redefinir a senha da sua conta no <strong style='color:#78be20'>Opa Studio</strong>.</p>
         <p>Clique no botão abaixo para criar uma nova senha:</p>
         <p style='margin:1rem 0;padding:1rem;background:#1e1e1e;border-radius:8px;border:1px solid #2a2a2a;
                   font-family:monospace;font-size:.82rem;color:#78be20;word-break:break-all'>{$url}</p>
         <p style='font-size:.82rem;color:#585858'>Este link é válido por <strong style='color:#e8eaf0'>1 hora</strong>.<br>
         Se você não solicitou a redefinição de senha, ignore este e-mail — sua senha permanece a mesma.</p>",
        $url,
        '🔑 Redefinir minha senha'
    );
    return mailer_send($email, $nome, 'Redefinição de senha — Opa Studio', $body);
}
