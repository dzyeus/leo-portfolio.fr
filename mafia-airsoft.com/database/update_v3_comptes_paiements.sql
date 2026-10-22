-- =====================================================================
-- Mise à jour v3 : comptes, paiements Stripe, places multiples, billets
-- =====================================================================
-- À exécuter UNE SEULE FOIS dans phpMyAdmin (onglet SQL), après avoir
-- fait une sauvegarde de la base (Exporter).
--
-- Ce script ne supprime aucune donnée : les inscriptions et billets
-- existants sont conservés et migrés.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Comptes : nom, prénom, vérification de l'email
-- ---------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NULL AFTER pseudo,
    ADD COLUMN IF NOT EXISTS last_name VARCHAR(100) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER email,
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL;

-- Les comptes existants (créés par l'admin) sont considérés comme vérifiés
UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL;

-- Jetons à usage unique : confirmation d'email et mot de passe oublié
CREATE TABLE IF NOT EXISTS user_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    purpose VARCHAR(30) NOT NULL,            -- 'verify_email' ou 'reset_password'
    token_hash CHAR(64) NOT NULL UNIQUE,     -- SHA-256 du jeton envoyé par email
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_purpose (user_id, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limitation des tentatives (connexion, mot de passe oublié, inscription)
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(30) NOT NULL,
    identifier VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_lookup (action, identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Parties : prix par place (0 = gratuit)
-- ---------------------------------------------------------------------
-- Les parties existantes restent gratuites ; les nouvelles sont à 10 € par défaut
ALTER TABLE events
    ADD COLUMN IF NOT EXISTS price_cents INT NOT NULL DEFAULT 0 AFTER location;

-- ---------------------------------------------------------------------
-- 3. Commandes : un achat de 1 à N places pour une équipe
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    event_id INT NOT NULL,
    team_key VARCHAR(50) NOT NULL,
    quantity INT NOT NULL,
    unit_price_cents INT NOT NULL,
    amount_cents INT NOT NULL,
    status ENUM('pending', 'paid', 'free', 'expired', 'refunded', 'cancelled') NOT NULL DEFAULT 'pending',
    -- Copie des infos de l'acheteur (pour les reçus, même si le compte est supprimé)
    buyer_name VARCHAR(200) NOT NULL,
    buyer_email VARCHAR(100) NOT NULL,
    receipt_number VARCHAR(30) NULL UNIQUE,
    stripe_session_id VARCHAR(255) NULL UNIQUE,
    stripe_payment_intent VARCHAR(255) NULL,
    expires_at DATETIME NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    INDEX idx_event_team_status (event_id, team_key, status),
    INDEX idx_user (user_id),
    INDEX idx_payment_intent (stripe_payment_intent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Inscriptions : une ligne = une place (le joueur OU un invité)
-- ---------------------------------------------------------------------
-- user_id devient facultatif (NULL = invité sans compte).
-- La contrainte unique (user_id, event_id) reste : un compte = une place à lui
-- par partie, mais il peut acheter des places pour des invités.
ALTER TABLE registrations
    MODIFY COLUMN user_id INT NULL,
    ADD COLUMN IF NOT EXISTS order_id INT NULL AFTER event_id,
    ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NULL AFTER team,
    ADD COLUMN IF NOT EXISTS last_name VARCHAR(100) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS pseudo VARCHAR(50) NULL AFTER last_name,
    ADD INDEX IF NOT EXISTS idx_order_id (order_id);

ALTER TABLE registrations
    ADD CONSTRAINT fk_registrations_order
    FOREIGN KEY IF NOT EXISTS (order_id) REFERENCES orders(id) ON DELETE CASCADE;

-- ---------------------------------------------------------------------
-- 5. Billets : un billet (QR code) par place
-- ---------------------------------------------------------------------
-- Les PDF et QR codes ne sont plus stockés en fichiers publics :
-- ils sont générés à la demande, uniquement pour l'acheteur ou un admin.
CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    registration_id INT NOT NULL UNIQUE,
    event_id INT NOT NULL,
    code VARCHAR(64) NOT NULL UNIQUE,
    scanned_at DATETIME NULL,
    scanned_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (scanned_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un billet pour chaque inscription existante qui n'en a pas encore
INSERT INTO tickets (registration_id, event_id, code)
SELECT r.id, r.event_id,
       CONCAT('MAT-', UPPER(LEFT(SHA2(CONCAT(r.id, '-', UUID(), '-', RAND()), 256), 24)))
FROM registrations r
LEFT JOIN tickets t ON t.registration_id = r.id
WHERE t.id IS NULL;

-- ---------------------------------------------------------------------
-- 6. Reprise des anciens billets (table event_tickets)
-- ---------------------------------------------------------------------
-- Les joueurs déjà inscrits gardent le QR code qu'ils ont reçu par email.
-- (Si la table event_tickets n'existe pas chez toi, cette requête affiche
--  une erreur sans conséquence : tout le reste a déjà été appliqué.)
UPDATE tickets t
JOIN registrations r ON r.id = t.registration_id
JOIN event_tickets et ON et.event_id = r.event_id AND et.user_id = r.user_id
SET t.code = et.ticket_code,
    t.scanned_at = IF(et.is_scanned = 1, COALESCE(et.scanned_at, NOW()), NULL),
    t.scanned_by = et.scanned_by;

SELECT 'Mise à jour v3 appliquée.' AS Message;
