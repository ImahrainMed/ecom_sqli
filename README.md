# Ecom SQLI - Projet e-commerce Symfony

## Présentation du projet

Ce projet est une application e-commerce développée pendant le stage d'initiation chez SQLI Oujda. L'objectif est de construire progressivement une application web permettant de consulter un catalogue de produits, gérer un panier, passer une commande, recevoir un email de confirmation et gérer certaines fonctionnalités depuis une interface admin. Le projet a été réalisé en binôme sous forme de tickets Jira.

## Technologies utilisées

- Symfony / PHP
- Twig / Doctrine ORM / MySQL
- Docker / Docker Compose
- phpMyAdmin / MailHog / Graylog
- Ollama (llama3.2) — assistant IA conversationnel
- SCSS / Stimulus.js / Webpack Encore
- Git / GitHub / Jira

## Installation et démarrage

### Prérequis

- Docker Desktop
- Git
- Node.js + Yarn
- [Ollama](https://ollama.com)

### Configuration système obligatoire (avant Docker)

```bash
sysctl -w vm.max_map_count=262144
```

Requis par OpenSearch/Graylog — sans ça, ces conteneurs ne démarrent pas.

### Clone et démarrage

```bash
git clone <repository-url>
cd ecom_sqli
docker compose up -d --build
```

### Migrations et fixtures (une seule fois)

```bash
docker compose exec php php bin/console doctrine:migrations:migrate
docker compose exec php php bin/console doctrine:fixtures:load
```

### Assets frontend

```bash
yarn install
yarn build
```

### Fichier `.env.local` à créer à la racine

```
MAILER_DSN=smtp://mailhog:1025
AI_PROVIDER=ollama
```

### Ollama — installer et lancer le modèle

```bash
ollama pull llama3.2
ollama serve
```

### URLs et accès

| Service | URL | Credentials |
| --- | --- | --- |
| Application | http://localhost:8080 | — |
| phpMyAdmin | http://localhost:8081 | root / root |
| MailHog | http://localhost:8025 | — |
| Graylog | http://localhost:9000 | admin / adminpassword |

## Architecture générale

Voir [docs/architecture.md](docs/architecture.md) pour le diagramme complet.

```text
Navigateur → nginx → PHP-FPM / Symfony → MySQL
Symfony / Monolog → Graylog (GELF UDP)
Symfony Mailer → MailHog
```

### Services Docker

| Service | Rôle |
| --- | --- |
| nginx | Serveur web |
| php | PHP-FPM / Symfony |
| database | MySQL |
| phpmyadmin | Interface BDD |
| mailhog | Test emails |
| graylog | Centralisation logs |
| mongodb | Dépendance Graylog |
| opensearch | Moteur recherche Graylog |

## Commandes utiles

```bash
# Vider le cache
docker compose exec php php bin/console cache:clear

# Voir les routes
docker compose exec php php bin/console debug:router

# Lancer les tests
docker compose exec php php bin/phpunit

# Compiler les assets
yarn build
```

## Avancement par tickets Jira

### SQLIE-1 — Mise en place de l'environnement Docker

Mise en place de l'environnement de développement complet via Docker, avec un fichier `docker-compose.yaml` orchestrant 8 conteneurs. La configuration nginx redirige les requêtes PHP vers le conteneur `php` via FastCGI. Plusieurs bugs d'infrastructure ont été résolus au démarrage, notamment l'ajustement du paramètre kernel `vm.max_map_count` requis par OpenSearch.

### SQLIE-2 — Documentation de l'architecture

Documentation du chemin d'une requête dans l'application, depuis le navigateur jusqu'à nginx, PHP-FPM, Symfony puis MySQL. Le chemin des logs depuis Symfony vers Graylog via Monolog et GELF a également été documenté. Voir [docs/architecture.md](docs/architecture.md).

### SQLIE-3 — Entités Doctrine principales

Création des entités `Category`, `Product` et `User` avec leurs relations Doctrine et migrations correspondantes. `User` implémente les interfaces de sécurité Symfony.

### SQLIE-4 — Fixtures avec Faker

Ajout de fixtures (`AppFixtures.php`) générant 4 catégories et 20 produits avec stocks variés, dont certains en rupture, pour servir de jeu de données de base.

### SQLIE-5 — Structure frontend de base

Mise en place du layout principal Twig (`base.html.twig`), navbar, footer, fichier SCSS partagé, et configuration Webpack Encore.

### SQLIE-6 — Homepage produits

La page d'accueil affiche les produits regroupés par catégorie. Webpack Encore est configuré pour gérer les images des produits comme assets compilés.

### SQLIE-7 — Page détail produit

Page `/product/{id}` affichant toutes les informations d'un produit. Les IDs invalides retournent une 404. Le bouton "Add to cart" est désactivé si le produit est en rupture de stock.

### SQLIE-8 — Filtre catégorie et recherche

Filtre par catégorie (`?category=slug`) et recherche par mot-clé (`?q=`) sur la homepage, combinables, construits via `ProductRepository::search()`.

### SQLIE-9 — Service panier

`CartService` basé sur la session Symfony : ajout, modification, suppression, calcul du total, validation du stock. Tests unitaires inclus.

### SQLIE-10 — Page panier et mini-panier

Page panier complète et dropdown mini-panier dans la navbar, branchés sur `CartService`. Badge navbar synchronisé en temps réel via une extension Twig dédiée. Fix d'un bug préexistant sur `stimulus_bootstrap.js` qui empêchait le chargement des contrôleurs Stimulus.

### SQLIE-11 — QA panier et stock

Vérification des cas limites liés au panier et au stock. Limitation documentée : si le stock change après l'ajout au panier, une revalidation au checkout est nécessaire.

### SQLIE-12 — Tests croisés CartService

6 nouveaux tests PHPUnit couvrant les cas limites non testés : quantité négative, cumul dépassant le stock, produit fantôme en session.

### SQLIE-17 — Entités Order et OrderItem

Entités `Order` et `OrderItem` avec enum `OrderStatus`. Le champ `unitPrice` sur `OrderItem` copie le prix au moment de l'achat pour préserver l'historique même si le prix change ensuite.

### SQLIE-18 — Backend checkout

Contrôleur checkout avec transaction Doctrine (rollback si erreur), vérification du stock, décrément atomique, et vidage du panier uniquement après succès complet.

### SQLIE-19 — Email de confirmation

Envoi d'un email de confirmation via `OrderPlacedEvent` + `OrderConfirmationSubscriber`. Fix du transport Messenger async qui bloquait les emails (aucun worker consommateur en local).

### SQLIE-20 — Interface checkout et confirmation

Page checkout avec résumé de commande, formulaire d'adresse avec validation inline, et page de confirmation après commande réussie.

### SQLIE-21 — Historique des commandes

Page `/account/orders` listant les commandes de l'utilisateur connecté. Vérification de sécurité cross-user : accès à la commande d'un autre utilisateur renvoie 403.

### SQLIE-22 — Vérification flux email

Documentation et vérification du flux complet de l'email de confirmation, depuis le checkout jusqu'à MailHog. Voir [docs/email-flow-verification.md](docs/email-flow-verification.md) pour le détail de la vérification.

### SQLIE-23 — Admin produits (CRUD)

Interface admin protégée par `ROLE_ADMIN` pour créer, modifier, ajuster le stock et supprimer des produits. La suppression d'un produit lié à une commande est bloquée pour préserver l'historique. La stratégie de suppression (restrict delete) est détaillée dans [docs/admin-product-management.md](docs/admin-product-management.md).

### SQLIE-31 — Assistant IA conversationnel

Chatbot basé sur Ollama (llama3.2 en local) avec function calling : recherche produit en langage naturel, gestion du panier par conversation, historique persistant en session. Toute action modifiant le panier nécessite une confirmation explicite garantie côté code PHP, jamais déléguée au modèle.

### SQLIE-32 — Authentification

Système d'authentification complet : inscription (`/register`), connexion (`/login`), déconnexion. Configuration du firewall Symfony dans `security.yaml`.

### SQLIE-33 — Amélioration UI Login et Checkout

Amélioration du style visuel des pages Login et Checkout pour les rendre cohérentes avec le reste du site.

## Limites connues

- Si le stock d'un produit change après son ajout au panier, une revalidation au checkout est nécessaire.
- Le chatbot utilise un modèle local 3B (llama3.2) — fiabilité variable sur les formulations ambiguës, comportement documenté dans `ChatbotService.php`.
- Le logging structuré vers Graylog (SQLIE-24/25) n'est pas encore implémenté — Graylog tourne mais sans données structurées pour l'instant.
- Les pages d'erreur 404/500 personnalisées ne sont pas encore finalisées.

## Captures d'écran

Voir [docs/screenshots/](docs/screenshots/) pour les captures de l'application (liste des captures attendues dans [docs/screenshots/README.md](docs/screenshots/README.md)).

## Scénario de démonstration

1. Page d'accueil → catalogue → filtre catégorie → recherche
2. Page détail produit → ajout au panier
3. Mini-panier dropdown → page panier → modification quantité
4. Chatbot IA → recherche en langage naturel → ajout au panier par conversation
5. Checkout → formulaire adresse → confirmation commande
6. Email de confirmation dans MailHog
7. Historique commandes `/account/orders`
8. Interface admin `/admin/products`
