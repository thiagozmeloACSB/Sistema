<?php
/**
 * SETUP SUPERADMIN — rode UMA VEZ para criar/atualizar sua senha.
 * Acesse: /setup_admin.php?senha=SUA_SENHA&email=seu@email&nome=Seu+Nome
 * APAGUE o arquivo após usar.
 */
require_once __DIR__.'/auth.php';
$senha=$_GET['senha']??''; $email=$_GET['email']??''; $nome=$_GET['nome']??'Super Admin';
if(!$senha||!$email) die('<pre>Passe ?senha=...&email=...&nome=...</pre>');
$hash=password_hash($senha,PASSWORD_BCRYPT);
$ck=db()->prepare("SELECT id FROM usuarios WHERE email=?"); $ck->execute([strtolower($email)]);
if($ck->fetch()){
  db()->prepare("UPDATE usuarios SET nome=?,senha_hash=?,tipo='superadmin',tenant_id=NULL WHERE email=?")->execute([$nome,$hash,strtolower($email)]);
  echo "<pre>✓ Admin atualizado: {$email}\nAPAGUE ESTE ARQUIVO!</pre>";
} else {
  db()->prepare("INSERT INTO usuarios (tenant_id,nome,email,senha_hash,tipo) VALUES (NULL,?,?,?,'superadmin')")->execute([$nome,strtolower($email),$hash]);
  echo "<pre>✓ Admin criado: {$email}\nAPAGUE ESTE ARQUIVO!</pre>";
}
