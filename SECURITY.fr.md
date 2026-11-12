[English](SECURITY.md) · [Français](SECURITY.fr.md)

# Politique de sécurité

La dernière révision corrective 1.0.x reçoit les correctifs de sécurité. Aucun délai contractuel de réponse ni audit indépendant n’est promis.

## Signaler une vulnérabilité

Utiliser le [signalement confidentiel GitHub](https://github.com/olivCode/form-guard-laravel/security/advisories/new). Ne pas publier de détails d’exploitation dans une issue ou une PR publique. Si la vulnérabilité affecte aussi l’autre package Form Guard, le préciser dans un seul signalement.

Fournir la version, l’environnement et une reproduction synthétique minimale. Ne transmettre ni identifiants SMTP, ni clés, ni jetons/cookies de session, ni messages réels ou identifiants de visiteurs.

## Périmètre

Conserver CSRF Laravel, proxys de confiance, sessions HTTPS, validation métier et limites serveur. Ne pas utiliser directement un Host/en-tête proxy pour déterminer origine ou IP de confiance. Stockage fichiers et réservations exigent un système de fichiers local privé fiable sur un même hôte ; le distribué sort des garanties 1.0. Conserver la cohérence réservations/sessions en sauvegarde/restauration, sans les purger à chaque déploiement. La disparition de tout l’état ne peut être distinguée d’une nouvelle installation. Une réservation empêche le rejeu mais ne garantit pas une livraison distante exactement une fois après un crash. Afficher des erreurs génériques et ne jamais continuer après un échec de protection.
