-- Mise à jour v5 : historique des messages envoyés aux joueurs (Admin › Messages)
-- Normalement INUTILE : la table est créée automatiquement à la première ouverture de la page.
-- À exécuter dans phpMyAdmin seulement si la page indique « Historique indisponible ».

CREATE TABLE IF NOT EXISTS broadcasts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    target VARCHAR(20) NOT NULL,
    target_label VARCHAR(255) NOT NULL,
    recipients INT NOT NULL DEFAULT 0,
    sent INT NOT NULL DEFAULT 0,
    failed INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
