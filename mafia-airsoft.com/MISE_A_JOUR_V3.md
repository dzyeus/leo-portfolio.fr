# Mise à jour v3 – Comptes, paiements Stripe et billets

Cette version refait le système de comptes, ajoute le paiement des parties par Stripe
(plusieurs places par achat, avec invités) et remplace l'ancien système de billets QR.
Le style du site (CSS, header, footer, pages publiques) n'a pas changé.

## Ce qui change

**Comptes**
- Inscription publique (`register.php`) avec confirmation par email.
- Mot de passe oublié (`forgot_password.php` → `reset_password.php`).
- Page « Mon profil » (`player/profile.php`) : nom, prénom, pseudo, téléphone, mot de passe.
- Protection CSRF, limitation des tentatives de connexion (5 échecs / 15 min), session sécurisée.
- Les comptes créés par l'admin sont confirmés d'office.

**Parties et paiement**
- Prix réglable par partie dans l'admin (0 € = inscription gratuite comme avant).
- Le joueur choisit son équipe et 1 à 5 places (lui + ses invités), puis paie sur Stripe.
- Les places sont bloquées 30 min pendant le paiement, puis libérées si le paiement est abandonné.
- Après le paiement, le joueur renseigne nom, prénom et pseudo de chaque invité (`order.php`).
- Remboursement total depuis le Dashboard Stripe = places et billets supprimés automatiquement.

**Billets et reçus**
- Un billet (QR code unique) par place, en PDF, envoyé par email et téléchargeable dans « Mon espace ».
- Reçu de paiement PDF au nom de l'asso (`receipt.php`).
- Les PDF ne sont plus stockés dans `uploads/` (ils étaient accessibles publiquement) : ils sont générés à la demande.
- Le scanner (`qr-code/scan.php`) affiche nom, pseudo et équipe, et refuse un billet déjà utilisé.
- Les anciens billets déjà envoyés restent valables.

## Installation sur le serveur (o2switch)

1. **Sauvegarder la base** : phpMyAdmin → Exporter.
2. **Envoyer les fichiers** du dossier `mafia-airsoft.com/` (FTP ou git pull).
   Sur le serveur, supprimer les anciens fichiers qui n'existent plus :
   `qr-code/generate_ticket.php`, `qr-code/send_ticket_email.php`, `qr-code/ticket_integration.php`,
   ainsi que le contenu de `uploads/tickets/` et `uploads/qrcodes/` (anciens PDF/QR, plus utilisés).
3. **Dépendances** : `composer install --no-dev` (TCPDF et PHPMailer, déjà dans `composer.json`).
4. **Base de données** : phpMyAdmin → onglet SQL → coller `database/update_v3_comptes_paiements.sql` → Exécuter (une seule fois).
5. **Configuration** : copier `config/app.example.php` en `config/app.php` et remplir :
   - l'URL du site, les infos de l'asso (adresse, RNA, SIRET) pour les reçus ;
   - la clé secrète Stripe (`sk_test_...` pour commencer) ;
   - le SMTP (mêmes valeurs que l'ancien `qr-code/email_config.php` si tu l'avais).
6. **Webhook Stripe** : Dashboard Stripe → Développeurs → Webhooks → Ajouter un endpoint
   - URL : `https://mafia-airsoft.com/stripe_webhook.php`
   - Événements : `checkout.session.completed`, `checkout.session.async_payment_succeeded`,
     `checkout.session.expired`, `charge.refunded`
   - Copier la clé de signature (`whsec_...`) dans `config/app.php`.
7. **Tester** : créer une partie à 10 €, réserver 2 places, payer avec la carte `4242 4242 4242 4242`
   (date future, CVC quelconque), compléter l'invité, télécharger billets et reçu, scanner un billet.

## Passage en paiement réel

Quand le compte Stripe de l'asso est activé :
- remplacer `sk_test_...` par `sk_live_...` dans `config/app.php` ;
- recréer le webhook en mode live et mettre sa nouvelle clé `whsec_...` ;
- recréer le produit « Place – Partie d'airsoft » en mode live et mettre son ID dans `STRIPE_PRODUCT_ID`
  (ou laisser vide : le nom du produit sera alors envoyé directement à Stripe).

## ⚠️ Sécurité : compte admin par défaut

Le compte créé par `database/schema.sql` (`admin@airsoft.com`) a pour mot de passe **`password`**
(le commentaire « admin123 » du script est faux). Si ce compte existe encore en production,
change son mot de passe (« Mot de passe oublié » ou « Mon profil ») ou supprime-le.
