<?php
/**
 * Envoi d'emails (PHPMailer + SMTP de config/app.php).
 */

/** Envoi via la fonction mail() du serveur (au lieu d'un serveur SMTP) ? */
function mail_uses_php_mail(): bool
{
    return strtolower((string)MAIL_TRANSPORT) === 'mail';
}

/** Le SMTP est-il renseigné dans config/app.php ? */
function smtp_configured(): bool
{
    return SMTP_HOST !== '' && SMTP_USERNAME !== '' && SMTP_PASSWORD !== ''
        && !str_contains(SMTP_PASSWORD, 'A_REMPLACER') && !str_contains(SMTP_HOST, 'xxxx');
}

/** L'envoi d'emails est-il configuré (mail() ou SMTP) ? */
function mail_configured(): bool
{
    return mail_uses_php_mail() ? FROM_EMAIL !== '' : smtp_configured();
}

/** Crée un PHPMailer configuré avec le SMTP de config/app.php. */
function mailer_create(): PHPMailer\PHPMailer\PHPMailer
{
    require_vendor();
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    if (mail_uses_php_mail()) {
        // Serveur mail de l'hébergeur (o2switch) : pas de mot de passe, signé pour le domaine du site
        $mail->isMail();
        $mail->Sender = FROM_EMAIL; // adresse de retour : nécessaire pour ne pas finir en spam
    } else {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = (int)SMTP_PORT;
        $mail->Timeout    = 20;
        // Ne jamais bloquer une page plus de 30 s si le serveur mail ne répond plus
        $mail->getSMTPInstance()->Timelimit = 30;
    }
    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    $mail->addReplyTo(REPLY_TO_EMAIL, REPLY_TO_NAME);
    return $mail;
}

/**
 * Envoie un email HTML. $attachments = ['nom-du-fichier.pdf' => contenu binaire, ...]
 * $headers = en-têtes supplémentaires (ex. List-Unsubscribe).
 * Renvoie true si l'email est parti. Les erreurs sont écrites dans le log PHP
 * (et visibles dans Admin › Diagnostic).
 */
function send_mail(string $toEmail, string $toName, string $subject, string $html, array $attachments = [], array $headers = []): bool
{
    if (!mail_configured()) {
        error_log('Email non envoyé (envoi non configuré dans config/app.php) : ' . $subject . ' → ' . $toEmail);
        return false;
    }

    try {
        $mail = mailer_create();
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = mail_layout($subject, $html);
        $mail->AltBody = trim(html_entity_decode(strip_tags(
            preg_replace(['#<br\s*/?>#i', '#</p>#i', '#</h\d>#i'], "\n", $html)
        ), ENT_QUOTES, 'UTF-8'));

        foreach ($headers as $name => $value) {
            $mail->addCustomHeader($name, $value);
        }

        foreach ($attachments as $filename => $content) {
            $mail->addStringAttachment($content, $filename, 'base64', 'application/pdf');
        }

        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('Erreur envoi email "' . $subject . '" : ' . $e->getMessage());
        return false;
    }
}

/**
 * Envoi de test avec le journal complet de la discussion avec le serveur mail
 * (mot de passe masqué). Utilisé par admin/diagnostic.php.
 * Renvoie ['ok' => bool, 'error' => string, 'log' => string[], 'hint' => string].
 */
function smtp_test(string $toEmail): array
{
    $log = [];
    $result = ['ok' => false, 'error' => '', 'log' => &$log, 'hint' => ''];

    if (!mail_configured()) {
        $result['error'] = mail_uses_php_mail()
            ? 'FROM_EMAIL est vide dans config/app.php.'
            : 'SMTP non configuré : renseigne SMTP_HOST (nom réel du serveur), SMTP_USERNAME et SMTP_PASSWORD dans config/app.php, '
              . 'ou passe MAIL_TRANSPORT à \'mail\'.';
        return $result;
    }

    try {
        $mail = mailer_create();
        if (mail_uses_php_mail()) {
            $log[] = 'Envoi via la fonction mail() du serveur (MAIL_TRANSPORT = mail), expéditeur ' . FROM_EMAIL;
        }
        $mail->SMTPDebug = PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
        $mail->Debugoutput = function ($line) use (&$log) {
            $line = trim((string)$line);
            if ($line !== '') {
                $log[] = str_replace([SMTP_PASSWORD, base64_encode(SMTP_PASSWORD)], '********', $line);
            }
        };
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Test d\'envoi – ' . SITE_NAME;
        $mail->Body = mail_layout('Test d\'envoi', '<h2 style="color:#ffffff;margin-top:0;">Ça marche ✅</h2>'
            . '<p>Si tu lis ce message, les emails du site (confirmation de compte, billets, reçus, alertes) partent correctement.</p>');
        $mail->AltBody = 'Ça marche : les emails du site partent correctement.';
        $mail->send();
        $result['ok'] = true;
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }

    // Explication en clair des erreurs les plus courantes
    $all = mb_strtolower($result['error'] . ' ' . implode(' ', $log));
    if (!$result['ok']) {
        if (mail_uses_php_mail()) {
            $result['hint'] = 'La fonction mail() du serveur a refusé l\'envoi : vérifie que FROM_EMAIL est une adresse '
                . 'du domaine du site (contact@mafia-airsoft.com) qui existe dans cPanel › Comptes de messagerie.';
        } elseif (str_contains($all, 'certificate') || str_contains($all, 'ssl operation failed') || str_contains($all, 'peer')) {
            $result['hint'] = 'Problème de certificat SSL : mets dans SMTP_HOST le nom de ton serveur o2switch '
                . '(xxxx.o2switch.net, visible dans cPanel) au lieu de mail.mafia-airsoft.com.';
        } elseif (str_contains($all, 'authenticate') || str_contains($all, '535')) {
            $result['hint'] = 'Identifiants refusés : SMTP_USERNAME doit être l\'adresse complète (contact@mafia-airsoft.com) '
                . 'et SMTP_PASSWORD le mot de passe de CETTE boîte mail (cPanel › Comptes de messagerie), pas celui de cPanel.';
        } elseif (str_contains($all, 'connect') || str_contains($all, 'timed out') || str_contains($all, 'getaddrinfo')) {
            $result['hint'] = 'Connexion au serveur SMTP impossible. o2switch bloque les serveurs SMTP extérieurs (Gmail, Outlook…). '
                . 'Le plus simple : mets define(\'MAIL_TRANSPORT\', \'mail\'); et FROM_EMAIL = contact@mafia-airsoft.com dans config/app.php. '
                . 'Sinon, utilise la boîte contact@ d\'o2switch avec SMTP_HOST = le vrai nom du serveur (xxxx.o2switch.net), port 465, ssl.';
        } elseif (str_contains($all, 'sender') || str_contains($all, 'from')) {
            $result['hint'] = 'Expéditeur refusé : FROM_EMAIL doit être la même adresse que SMTP_USERNAME (ou une adresse du même domaine).';
        } elseif (str_contains($all, 'dépendances') || str_contains($all, 'composer')) {
            $result['hint'] = 'PHPMailer manquant : le dossier vendor/ doit être présent sur le serveur (composer install).';
        }
    }
    unset($result['log']);
    $result['log'] = $log;
    return $result;
}

/** Habillage HTML commun des emails, aux couleurs du site. */
function mail_layout(string $title, string $content): string
{
    $siteUrl = e(SITE_URL);
    $siteName = e(SITE_NAME);
    $title = e($title);
    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{$title}</title></head>
<body style="margin:0;padding:0;background:#0a0a0a;font-family:Arial,Helvetica,sans-serif;color:#e5e5e5;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#0a0a0a;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:10px;overflow:hidden;">
        <tr><td style="background:linear-gradient(135deg,#dc2626 0%,#991b1b 100%);background-color:#dc2626;padding:22px 28px;">
          <div style="font-size:20px;font-weight:bold;letter-spacing:2px;color:#ffffff;">MAFIA AIRSOFT TEAM</div>
          <div style="font-size:12px;color:#fecaca;letter-spacing:1px;">Memento Mori</div>
        </td></tr>
        <tr><td style="padding:28px;font-size:15px;line-height:1.6;color:#e5e5e5;">
          {$content}
        </td></tr>
        <tr><td style="padding:16px 28px;border-top:1px solid #2a2a2a;font-size:12px;color:#737373;">
          {$siteName} · <a href="{$siteUrl}" style="color:#ef4444;">{$siteUrl}</a>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

/** Bouton rouge pour les emails. */
function mail_button(string $url, string $label): string
{
    return '<p style="margin:24px 0;"><a href="' . e($url) . '" style="display:inline-block;background:#dc2626;color:#ffffff;'
        . 'text-decoration:none;padding:12px 22px;border-radius:6px;font-weight:bold;">' . e($label) . '</a></p>'
        . '<p style="font-size:12px;color:#a3a3a3;">Si le bouton ne marche pas, copie ce lien :<br>'
        . '<span style="color:#ef4444;word-break:break-all;">' . e($url) . '</span></p>';
}

// ---------------------------------------------------------------------
// Emails des comptes
// ---------------------------------------------------------------------

function send_verification_email(PDO $pdo, array $user): bool
{
    $token = user_token_create($pdo, (int)$user['id'], 'verify_email', 48 * 60);
    $url = SITE_URL . '/verify_email.php?token=' . $token;
    $html = '<h2 style="color:#ffffff;margin-top:0;">Bienvenue ' . e($user['pseudo']) . ' !</h2>'
        . '<p>Ton compte est presque prêt. Confirme ton adresse email pour pouvoir te connecter et t\'inscrire aux parties.</p>'
        . mail_button($url, 'Confirmer mon email')
        . '<p style="font-size:13px;color:#a3a3a3;">Ce lien est valable 48 heures. Si tu n\'as pas créé de compte, ignore cet email.</p>';
    return send_mail($user['email'], $user['pseudo'], 'Confirme ton compte ' . SITE_NAME, $html);
}

function send_password_reset_email(PDO $pdo, array $user): bool
{
    $token = user_token_create($pdo, (int)$user['id'], 'reset_password', 60);
    $url = SITE_URL . '/reset_password.php?token=' . $token;
    $html = '<h2 style="color:#ffffff;margin-top:0;">Mot de passe oublié</h2>'
        . '<p>Salut ' . e($user['pseudo']) . ', tu as demandé à changer ton mot de passe.</p>'
        . mail_button($url, 'Choisir un nouveau mot de passe')
        . '<p style="font-size:13px;color:#a3a3a3;">Ce lien est valable 1 heure. Si ce n\'est pas toi, ignore cet email : ton mot de passe ne change pas.</p>';
    return send_mail($user['email'], $user['pseudo'], 'Réinitialisation de ton mot de passe', $html);
}
