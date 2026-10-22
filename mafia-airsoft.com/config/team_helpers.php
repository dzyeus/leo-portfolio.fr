<?php
/**
 * Fonctions helper pour le système d'équipes dynamiques
 */

/**
 * Récupère toutes les équipes d'un événement avec le nombre de places prises
 * (joueurs inscrits + places bloquées par un paiement en cours)
 * @param PDO $pdo Instance PDO
 * @param int $event_id ID de l'événement
 * @return array Tableau des équipes
 */
function getEventTeams($pdo, $event_id) {
    $stmt = $pdo->prepare("
        SELECT et.*,
               (SELECT COUNT(*) FROM registrations 
                WHERE event_id = et.event_id AND team = et.team_key)
             + (SELECT COALESCE(SUM(o.quantity), 0) FROM orders o
                WHERE o.event_id = et.event_id AND o.team_key = et.team_key
                  AND o.status = 'pending' AND o.expires_at > NOW()) as current_players
        FROM event_teams et
        WHERE et.event_id = ?
        ORDER BY et.display_order ASC
    ");
    $stmt->execute([$event_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Récupère une équipe spécifique par sa clé
 * @param PDO $pdo Instance PDO
 * @param int $event_id ID de l'événement
 * @param string $team_key Clé de l'équipe
 * @return array|false Données de l'équipe ou false
 */
function getEventTeam($pdo, $event_id, $team_key) {
    $stmt = $pdo->prepare("
        SELECT et.*,
               (SELECT COUNT(*) FROM registrations 
                WHERE event_id = et.event_id AND team = et.team_key)
             + (SELECT COALESCE(SUM(o.quantity), 0) FROM orders o
                WHERE o.event_id = et.event_id AND o.team_key = et.team_key
                  AND o.status = 'pending' AND o.expires_at > NOW()) as current_players
        FROM event_teams et
        WHERE et.event_id = ? AND et.team_key = ?
    ");
    $stmt->execute([$event_id, $team_key]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Vérifie si une équipe est complète
 * @param PDO $pdo Instance PDO
 * @param int $event_id ID de l'événement
 * @param string $team_key Clé de l'équipe
 * @return bool True si l'équipe est complète
 */
function isTeamFull($pdo, $event_id, $team_key) {
    $team = getEventTeam($pdo, $event_id, $team_key);
    if (!$team) return true; // Si l'équipe n'existe pas, considérer comme complète
    return $team['current_players'] >= $team['max_players'];
}

/**
 * Récupère les événements avec les informations des équipes agrégées
 * Pour compatibilité avec l'ancien système (BLUE, RED, etc.)
 * @param PDO $pdo Instance PDO
 * @param string $where_clause Clause WHERE SQL (optionnelle)
 * @param array $params Paramètres pour la requête
 * @return array Tableau des événements avec infos équipes
 */
function getEventsWithTeams($pdo, $where_clause = '', $params = []) {
    $sql = "SELECT e.* FROM events e";
    if ($where_clause) {
        $sql .= " WHERE " . $where_clause;
    }
    $sql .= " ORDER BY e.event_date ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pour chaque événement, récupérer les équipes
    foreach ($events as &$event) {
        $teams = getEventTeams($pdo, $event['id']);
        $event['teams'] = $teams;
        
        // Pour compatibilité, ajouter les compteurs des équipes principales
        foreach ($teams as $team) {
            $key_lower = strtolower($team['team_key']);
            $event[$key_lower . '_count'] = $team['current_players'];
            $event['max_players_' . $key_lower] = $team['max_players'];
        }
        
        // Calculer le total
        $event['total_players'] = array_sum(array_column($teams, 'current_players'));
    }
    
    return $events;
}

/**
 * Récupère un événement avec les informations des équipes
 * @param PDO $pdo Instance PDO
 * @param int $event_id ID de l'événement
 * @return array|false Données de l'événement ou false
 */
function getEventWithTeams($pdo, $event_id) {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) return false;
    
    $teams = getEventTeams($pdo, $event['id']);
    $event['teams'] = $teams;
    
    // Pour compatibilité
    foreach ($teams as $team) {
        $key_lower = strtolower($team['team_key']);
        $event[$key_lower . '_count'] = $team['current_players'];
        $event['max_players_' . $key_lower] = $team['max_players'];
    }
    
    $event['total_players'] = array_sum(array_column($teams, 'current_players'));
    
    return $event;
}
