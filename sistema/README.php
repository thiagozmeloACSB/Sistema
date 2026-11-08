<?php
/**
 * ═══════════════════════════════════════════════════════════
 *  OPA STUDIO — SECURITY PACK
 *  Arquivo de integração central
 * ═══════════════════════════════════════════════════════════
 *
 *  ESTRUTURA DE ARQUIVOS:
 *
 *  /security/
 *  ├── 1_blocked_domains.php   → Bloqueia domínios suspeitos no cadastro
 *  ├── 2_captcha.php           → hCaptcha no formulário
 *  ├── 3_2fa.php               → 2FA TOTP para o Super Admin
 *  ├── 4_alerts.php            → Alertas por e-mail de cadastros suspeitos
 *  ├── 5_ip_guard.php          → Proteção da rota /superadmin.php por IP
 *  ├── logs/                   → Logs automáticos (criados automaticamente)
 *  │   ├── suspicious.log
 *  │   ├── blocked_access.log
 *  │   └── rate_register.json
 *  └── secrets/
 *      └── 2fa_secret.txt      → Segredo 2FA (proteja com chmod 600)
 *
 * ═══════════════════════════════════════════════════════════
 *  INTEGRAÇÃO — ONDE ADICIONAR CADA MÓDULO
 * ═══════════════════════════════════════════════════════════
 *
 *  1. superadmin.php (PRIMEIRA LINHA):
 *     ┌──────────────────────────────────────────┐
 *     │ <?php                                    │
 *     │ require __DIR__.'/security/5_ip_guard.php'; │
 *     │ require __DIR__.'/security/3_2fa.php';   │
 *     │ require2FA(); // verifica sessão 2FA     │
 *     └──────────────────────────────────────────┘
 *
 *  2. cadastro.php / register endpoint:
 *     ┌──────────────────────────────────────────┐
 *     │ require __DIR__.'/security/1_blocked_domains.php'; │
 *     │ require __DIR__.'/security/2_captcha.php';         │
 *     │ require __DIR__.'/security/4_alerts.php';          │
 *     │                                          │
 *     │ // Valida domínio                        │
 *     │ $check = checkEmailSecurity($_POST['email']); │
 *     │ if (!$check['ok']) respondError($check); │
 *     │                                          │
 *     │ // Valida CAPTCHA                        │
 *     │ $cap = verifyCaptcha($_POST['h-captcha-response']); │
 *     │ if (!$cap['ok']) respondError($cap);     │
 *     │                                          │
 *     │ // ... salva no banco ...                │
 *     │                                          │
 *     │ // Alerta pós-cadastro                   │
 *     │ processNewRegistration([                 │
 *     │     'empresa' => $_POST['empresa'],      │
 *     │     'email'   => $_POST['email'],        │
 *     │ ]);                                      │
 *     └──────────────────────────────────────────┘
 *
 * ═══════════════════════════════════════════════════════════
 *  CHECKLIST DE CONFIGURAÇÃO
 * ═══════════════════════════════════════════════════════════
 *
 *  [ ] Módulo 1: Adicionar seus domínios extras em $BLOCKED_DOMAINS
 *  [ ] Módulo 2: Criar conta no hCaptcha e colocar as chaves reais
 *  [ ] Módulo 3: Acessar superadmin_2fa.php → escanear QR Code no app
 *  [ ] Módulo 4: Confirmar seu e-mail em ALERT_TO_EMAIL
 *  [ ] Módulo 5: Adicionar seu IP real em $ALLOWED_IPS
 *               (Acesse https://meuip.com.br para descobrir)
 *
 *  [ ] Proteger pasta /security com .htaccess:
 *      Deny from all
 *
 *  [ ] Chmod nos arquivos sensíveis:
 *      chmod 600 security/secrets/2fa_secret.txt
 *      chmod 750 security/logs/
 *
 * ═══════════════════════════════════════════════════════════
 *  ARQUIVO .htaccess — Bloqueio direto da pasta /security
 * ═══════════════════════════════════════════════════════════

# Cole em /security/.htaccess:
# Order deny,allow
# Deny from all

 * ═══════════════════════════════════════════════════════════
 */

echo "Opa Studio — Security Pack carregado com sucesso.\n";
echo "Versão: 1.0 | " . date('d/m/Y') . "\n";
