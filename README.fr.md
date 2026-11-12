[English](README.md) · [Français](README.fr.md)

# Form Guard Laravel

![PHP](https://img.shields.io/badge/PHP-%5E8.3-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
[![MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Adaptateur Laravel du moteur PHP Form Guard commun : conteneur/provider, routes web protégées, formulaires en session, champs Blade, erreurs JSON/HTML et entretien. Le moteur est une dépendance Composer, pas un fork. La 1.0 couvre PHP + Laravel 13 ; les autres versions majeures de Laravel et portages ne sont pas annoncés comme testés.

## Installation

Installer le package depuis Packagist :

```sh
composer require olivcode/form-guard-laravel:^1.0
php artisan vendor:publish --tag=form-guard-config
php artisan vendor:publish --tag=form-guard-assets
```

Laravel utilise Composer ; l’installation sans Composer concerne le moteur PHP autonome.

Régler `APP_URL` sur l’URL réelle de l’application, avec son sous-dossier éventuel. L’origine autorisée par défaut en extrait seulement le protocole/hôte/port de confiance ; adapter `form-guard.http.origins` si nécessaire. `directory` doit être un chemin absolu privé persistant, hors racine HTTP. Configurer les limites serveur en complément des 16 Kio du package. Les défauts sont trois secondes de délai et le profil Standard. `options` accepte les options à plat du moteur et surcharge le profil ; scope/répertoire/langue utilisent les réglages dédiés de l’adaptateur. `plugins` contient des noms de classes résolus par le conteneur, sans instances/closures dans la configuration mise en cache. `config:cache` reste utilisable ; aucun singleton ne conserve une requête.

## Intégration du formulaire

Utiliser `.formGuard()` sur les routes **GET et POST**. Cette macro ajoute le middleware et le verrou de session. Les routes de `routes/web.php` appartiennent déjà au groupe `web` : ne pas le doubler. Exemple pour des routes personnalisées/de package :

```php
use FormGuard\Laravel\FormManager;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('/contact', function (FormManager $forms) {
        return view('contact', ['form' => $forms->issue(['email', 'message'])]);
    })->formGuard();

    Route::post('/contact', function (FormManager $forms) {
        return $forms->submit(
            fn (array $values): array => validator($values, [
                'email' => 'required|email|max:254',
                'message' => 'required|string|max:4000',
            ])->validate(),
            function (array $data) {
                // Perform your application action here.
                return redirect('/contact')->with('status', 'Thank you');
            },
        );
    })->formGuard();
});
```

```blade
<form method="post" action="/contact" data-antispam>
    @include('form-guard::fields', ['form' => $form])
    <label for="email">Email</label>
    <input id="email" type="email" name="{{ $form['fields']['email'] }}" autocomplete="email" required>
    <label for="message">Message</label>
    <textarea id="message" name="{{ $form['fields']['message'] }}" required></textarea>
    <button type="submit">Send</button>
</form>
<script src="/vendor/form-guard/form-guard.js" defer></script>
```

L’include Blade émet le jeton CSRF natif de Laravel et les jetons/signaux/honeypot Form Guard. Ces vérifications sont distinctes ; ne jamais désactiver le CSRF Laravel pour intégrer le package. Le JavaScript facultatif est une copie publique, sans compilation Node. Associer des labels aux champs et prévoir des erreurs accessibles.

`issue(array $fields, string $name = 'contact')` conserve le formulaire dans la session serveur. `submit(callable $validate, callable $action, string $name = 'contact')` retrouve les champs aléatoires, vérifie les protections, appelle un validateur métier **sans effet de bord** renvoyant un tableau, réserve/consomme le jeton, persiste la session puis effectue l’action. Utiliser le même nom défini côté serveur en GET/POST. Une erreur de validation Laravel ne consomme pas le formulaire ; une action exécutée le consomme. Ne pas envoyer d’e-mail/créer une commande dans le validateur. La reprise des écritures ou appels externes exige l’idempotence/les transactions de l’application ; la bibliothèque ne garantit pas une livraison distante exactement une fois.

## Sessions, rejeu et pannes

Utiliser une session persistante et un stockage de verrou atomique (`file`, `database`, `redis`, `memcached` ou `dynamodb`). Une session cookie/array ou une route sans verrou est refusée. Le backend fichiers et les réservations exigent un hôte avec des verrous locaux fiables ; une session Redis ne rend pas le limiteur distribué. Plusieurs workers PHP sur cet hôte sont pris en charge.

Le middleware passe après StartSession et avant le CSRF natif : les essais CSRF invalides comptent aussi. Une copie bornée des entrées précède le nettoyage global Laravel ; leurs valeurs originales sont rétablies uniquement sur les routes protégées. Cela empêche d’effacer un honeypot rempli d’espaces ; valider/normaliser soi-même les données métier.

Le répertoire privé dédié `claims/` conserve les réservations d’action indépendamment de la durée du verrou de session : une ancienne session ou un verrou expiré ne permet pas de rejouer l’action. La réservation est persistée avant l’action, sa capacité est bornée (10 000 par défaut) et elle est purgée après expiration du formulaire et une marge de sécurité. Garder `limits/`, `claims/` et les sessions persistants. Ne pas effacer les réservations lors d’un déploiement ordinaire. Une erreur de stockage, composition invalide ou panne technique de plugin arrête le traitement. Les appels JSON reçoivent statut/raison, les limites un `Retry-After` ; les réponses protégées utilisent `Cache-Control: no-store`.

## Entretien

```sh
php artisan form-guard:check
php artisan form-guard:check --prune
```

Planifier régulièrement `form-guard:check --prune`, par exemple chaque heure. Vérifier les permissions/ACL Windows des utilisateurs PHP et CLI. Conserver ensemble clé et état du limiteur ; ne pas changer le scope à chaque mise à jour. Sauvegarder les réservations de manière cohérente avec les sessions si la restauration doit préserver le refus des rejeux. Voir [SECURITY](SECURITY.fr.md).

## Développement et vérification

```sh
composer install
composer check
composer test
composer test:distribution
composer audit --locked
```

Le moteur est installé depuis Packagist comme dans une application consommatrice. La CI vérifie PHP 8.3/8.4/8.5 sur Linux et Windows. Les tests utilisent uniquement des données synthétiques et aucun fournisseur de production.
