# Architecture du projet ecom_sqli

## Objectif

Ce document décrit l’architecture technique du projet e-commerce avant le développement.

Il présente deux chemins principaux :

1. Le chemin complet d’une requête utilisateur : Nginx -> PHP-FPM -> Symfony -> MySQL
2. Le chemin des logs : Symfony -> Monolog -> GELF -> Graylog GELF input

Cette tâche est réalisée en pair session afin que les deux stagiaires comprennent l’architecture et puissent l’expliquer pendant le standup.

---

## 1. Chemin d’une requête utilisateur

Quand un utilisateur accède à l’application depuis son navigateur, la requête suit le chemin suivant :

Navigateur -> Nginx -> PHP-FPM -> Application Symfony -> MySQL

## Diagramme du chemin de requête

Navigateur / Client
        |
        v
+----------------+
|     Nginx      |
| localhost:8080 |
+----------------+
        |
        v
+----------------+
|    PHP-FPM     |
|    php:9000    |
+----------------+
        |
        v
+----------------------+
| Application Symfony  |
+----------------------+
        |
        v
+----------------+
|     MySQL      |
|    database    |
+----------------+

## Explication du chemin de requête

L’utilisateur envoie une requête HTTP depuis son navigateur.

La requête arrive d’abord à Nginx. Nginx joue le rôle de serveur web. Il reçoit les requêtes HTTP et sert les fichiers publics de l’application.

Quand la requête doit être traitée par PHP, Nginx la transmet à PHP-FPM.

PHP-FPM exécute le code PHP de l’application Symfony.

Symfony traite ensuite la requête avec ses routes, contrôleurs, services, entités et repositories.

Si l’application a besoin de lire ou d’enregistrer des données, Symfony communique avec la base de données MySQL.

Dans Docker, Symfony utilise le nom du service database pour accéder à MySQL.

La réponse revient ensuite dans le sens inverse :

MySQL -> Symfony -> PHP-FPM -> Nginx -> Navigateur

---

## 2. Chemin des logs

Quand un événement important se produit dans l’application, Symfony peut générer un log.

Le chemin des logs est :

Application Symfony -> Monolog -> GELF -> Graylog GELF Input -> Graylog

## Diagramme du chemin des logs

+----------------------+
| Application Symfony  |
+----------------------+
        |
        v
+----------------+
|    Monolog     |
+----------------+
        |
        v
+----------------+
|  Format GELF   |
+----------------+
        |
        v
+----------------------+
| Graylog GELF Input   |
|     port 12201       |
+----------------------+
        |
        v
+----------------+
|    Graylog     |
| localhost:9000 |
+----------------+

## Explication du chemin des logs

Symfony utilise Monolog pour gérer les logs de l’application.

Monolog peut enregistrer différents événements comme :

- une commande créée ;
- une erreur serveur ;
- un échec de connexion ;
- un produit en rupture de stock ;
- un changement de statut d’une commande ;
- un email envoyé.

Les logs peuvent être envoyés au format GELF.

Graylog reçoit ces logs grâce à un GELF input.

Dans ce projet Docker, Graylog expose le port 12201 pour recevoir les logs GELF.

L’interface Graylog est disponible sur :

http://localhost:9000

---

## 3. Diagramme global

                         Requête utilisateur
                                |
                                v
                        +----------------+
                        |   Navigateur   |
                        +----------------+
                                |
                                v
                        +----------------+
                        |     Nginx      |
                        | localhost:8080 |
                        +----------------+
                                |
                                v
                        +----------------+
                        |    PHP-FPM     |
                        |    php:9000    |
                        +----------------+
                                |
                                v
                        +----------------------+
                        | Application Symfony  |
                        +----------------------+
                           |              |
                           |              |
                           v              v
                    +-------------+   +-------------+
                    |    MySQL    |   |   Monolog   |
                    |  database   |   +-------------+
                    +-------------+          |
                                             v
                                      +-------------+
                                      |    GELF     |
                                      +-------------+
                                             |
                                             v
                                      +-------------+
                                      | Graylog     |
                                      | GELF Input  |
                                      | port 12201  |
                                      +-------------+
                                             |
                                             v
                                      +-------------+
                                      |   Graylog   |
                                      | localhost:9000 |
                                      +-------------+

---

## 4. Rôle des composants

Nginx : serveur web qui reçoit les requêtes HTTP depuis le navigateur.

PHP-FPM : service qui exécute le code PHP de l’application.

Symfony : framework PHP utilisé pour gérer la logique métier de l’application.

MySQL : base de données relationnelle utilisée pour stocker les données.

Monolog : librairie utilisée par Symfony pour gérer les logs.

GELF : format utilisé pour envoyer les logs vers Graylog.

Graylog : outil de centralisation et visualisation des logs.

---

## 5. Résumé à expliquer en standup

Quand l’utilisateur ouvre l’application, la requête arrive d’abord à Nginx sur le port 8080. Nginx transmet les requêtes PHP vers PHP-FPM. PHP-FPM exécute l’application Symfony. Symfony traite la logique métier et communique avec MySQL via le service Docker database.

Pour les logs, Symfony utilise Monolog. Monolog prépare les messages de log et peut les envoyer vers Graylog au format GELF. Graylog reçoit ces logs via un GELF input sur le port 12201. Les logs peuvent ensuite être consultés depuis l’interface Graylog sur le port 9000.

---

## 6. Validation

Les deux stagiaires doivent être capables d’expliquer :

- le chemin d’une requête utilisateur ;
- le rôle de Nginx ;
- le rôle de PHP-FPM ;
- le rôle de Symfony ;
- le rôle de MySQL ;
- le chemin des logs ;
- le rôle de Monolog ;
- le rôle de Graylog ;
- le rôle du GELF input.