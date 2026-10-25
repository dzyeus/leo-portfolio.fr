<?php
/**
 * Déconnexion. Le lien contient un jeton (logout_url()) : un autre site ne peut
 * pas déconnecter un joueur à son insu.
 */
require_once 'config/session.php';

$token = (string)($_GET['t'] ?? $_POST['csrf_token'] ?? '');
if (isLoggedIn() && (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token))) {
    redirect('index.php');
}
logout();
