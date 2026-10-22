<?php
/**
 * Envoi d'emails (PHPMailer + SMTP de config/app.php).
 */

/**
 * Envoie un email HTML. $attachments = ['nom-du-fichier.pdf' => contenu binaire, ...]
 * Renvoie true si l'email est parti. Les erreurs sont écrites dans le log PHP.
 */
function send_mail(string $toEmail, string $toName, string $subject, string $html, array $attachments = []): bool
{
    if (SMTP_HOST === '' || SMTP_USERNAME === '') {
        error_log('Email non envoyé (SMTP non configuré dans config/app.php) : ' . $subject . ' → ' . $toEmail);
        return false;
    }

    try {
        require_vendor();
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = (int)SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 20;

        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addReplyTo(REPLY_TO_EMAIL, REPLY_TO_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = mail_layout($subject, $html);
        $mail->AltBody = trim(html_entity_decode(strip_tags(
            preg_replace(['#<br\s*/?>#i', '#</p>#i', '#</h\d>#i'], "\n", $html)
        ), ENT_QUOTES, 'UTF-8'));

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
