<?php

// Fonction pour vérifier si un utilisateur est connecté
function is_logged_in(): bool
{
    // Vérifie si la session a été démarrée et si les variables de session de l'utilisateur existent
    return isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role_id']);
}

// Fonction pour obtenir l'ID de l'utilisateur connecté
function get_current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

// Fonction pour obtenir le nom d'utilisateur connecté
function get_current_username(): ?string
{
    return $_SESSION['username'] ?? null;
}

// Fonction pour obtenir l'ID du rôle de l'utilisateur connecté
function get_current_user_role_id(): ?int
{
    return $_SESSION['role_id'] ?? null;
}

// Vous pouvez ajouter d'autres fonctions utilitaires ici plus tard
?>