-- =====================================================================
-- Mise à jour v4 : acceptation des conditions + alertes nouvelles parties
-- =====================================================================
-- À exécuter UNE FOIS, APRÈS update_v3_comptes_paiements.sql.
-- Peut être relancé sans risque.
-- =====================================================================

-- Comptes : acceptation des conditions et abonnement aux alertes (désactivé par défaut)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS terms_accepted_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS terms_version VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS notify_new_events TINYINT(1) NOT NULL DEFAULT 0;

-- Réservations : preuve d'acceptation des CGV au moment de l'achat
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS terms_accepted_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS terms_version VARCHAR(20) NULL;

-- Parties : annonce par email envoyée (une seule fois par partie)
ALTER TABLE events
    ADD COLUMN IF NOT EXISTS announced_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS announced_count INT NOT NULL DEFAULT 0;

-- Les parties déjà créées ne sont pas annoncées automatiquement
-- (l'admin peut toujours envoyer l'annonce depuis « Modifier la partie »)
UPDATE events SET announced_at = NOW() WHERE announced_at IS NULL;

SELECT 'Mise à jour v4 appliquée.' AS Message;
