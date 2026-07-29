# SQLIE-22 - Vérification du flow email des commandes

## Objectif

L’objectif de cette tâche est de vérifier le fonctionnement du flow email lié aux commandes dans le projet e-commerce Symfony.

Plus précisément, j’ai vérifié que lorsqu’un utilisateur connecté passe une commande, l’application :

- crée correctement la commande ;
- crée les lignes de commande associées ;
- déclenche l’événement `OrderPlacedEvent` ;
- envoie un email de confirmation via `OrderConfirmationSubscriber` ;
- affiche l’email dans MailHog ;
- inclut les informations importantes de la commande dans l’email.

Cette tâche est principalement une tâche de vérification et de documentation.

## Tickets liés

Cette vérification dépend principalement des tickets suivants :

- SQLIE-18 : création du checkout et transformation du panier en commande ;
- SQLIE-19 : envoi de l’email de confirmation via MailHog ;
- SQLIE-20 : page de confirmation après checkout ;
- SQLIE-32 : authentification utilisateur avec register, login et logout.

## Flow technique vérifié

Le flow email fonctionne de la manière suivante :

```text
CheckoutController
    -> création de Order
    -> création des OrderItems
    -> sauvegarde de la commande en base
    -> commit de la transaction
    -> dispatch de OrderPlacedEvent
    -> OrderConfirmationSubscriber intercepte l'événement
    -> envoi de l'email de confirmation
    -> réception de l'email dans MailHog
```

L’événement utilisé est :

```text
src/Event/OrderPlacedEvent.php
```

Le subscriber responsable de l’envoi de l’email est :

```text
src/EventSubscriber/OrderConfirmationSubscriber.php
```

Le template utilisé pour l’email est :

```text
templates/emails/order_confirmation.html.twig
```

## Vérification de l’environnement

Avant de tester le flow email, j’ai vérifié que les services Docker étaient bien lancés avec la commande :

```bash
docker compose ps
```

Les services importants pour cette vérification sont :

```text
symfony_nginx
symfony_php
symfony_mysql
symfony_mailhog
symfony_phpmyadmin
```

L’application Symfony est accessible sur :

```text
http://localhost:8080
```

MailHog est accessible sur :

```text
http://localhost:8025
```

MailHog est utilisé pour vérifier que les emails envoyés par l’application sont bien reçus pendant le développement.

## Vérification des routes

J’ai vérifié que les routes nécessaires existent bien.

Commande utilisée pour le checkout :

```bash
docker compose exec -T php php bin/console debug:router | findstr checkout
```

Résultat obtenu :

```text
app_checkout                GET|POST   /checkout
app_checkout_confirmation   GET        /checkout/confirmation/{id}
```

Commande utilisée pour le login :

```bash
docker compose exec -T php php bin/console debug:router | findstr login
```

Résultat obtenu :

```text
app_login                   GET|POST   /login
```

Commande utilisée pour le register :

```bash
docker compose exec -T php php bin/console debug:router | findstr register
```

Résultat obtenu :

```text
app_register                GET|POST   /register
```

Ces routes permettent de tester le flow complet avec un utilisateur connecté.

## Vérification des fichiers liés à l’email

J’ai vérifié l’existence des fichiers liés au flow email.

Le dossier `src/Event` contient :

```text
OrderPlacedEvent.php
```

Le dossier `src/EventSubscriber` contient :

```text
OrderConfirmationSubscriber.php
```

Le dossier `templates/emails` contient :

```text
order_confirmation.html.twig
```

Dans `OrderPlacedEvent.php`, l’événement transporte l’objet `Order`.

Dans `OrderConfirmationSubscriber.php`, le subscriber écoute l’événement `OrderPlacedEvent` et prépare un email avec Symfony Mailer.

L’email est configuré avec :

- l’expéditeur : `no-reply@ecom-sqli.test` ;
- le nom de l’expéditeur : `Ecom SQLI` ;
- le destinataire : l’email de l’utilisateur qui a passé la commande ;
- le sujet contenant le numéro de commande ;
- le template HTML `emails/order_confirmation.html.twig` ;
- le contexte contenant l’objet `order`.

Le sujet de l’email est généré sous cette forme :

```text
Confirmation de votre commande #{orderId}
```

## Scénario de test manuel

### 1. Ouvrir l’application

J’ouvre l’application depuis :

```text
http://localhost:8080
```

### 2. Créer un compte ou se connecter

Si aucun utilisateur de test n’existe, je crée un compte depuis :

```text
http://localhost:8080/register
```

Exemple d’utilisateur de test :

```text
Email : test@example.com
Password : password123
```

Ensuite, je me connecte depuis :

```text
http://localhost:8080/login
```

### 3. Ajouter un produit au panier

Après connexion, j’ajoute un produit disponible au panier.

Le produit utilisé pour le test doit avoir un stock supérieur à 0.

### 4. Passer au checkout

J’ouvre la page checkout :

```text
http://localhost:8080/checkout
```

Je remplis le formulaire d’adresse de livraison.

Exemple :

```text
Street : Rue Mohammed V
City : Oujda
Postal code : 60000
Country : Morocco
```

Ensuite, je valide le formulaire.

### 5. Vérifier la page de confirmation

Après validation du checkout, l’utilisateur est redirigé vers une page de confirmation :

```text
/checkout/confirmation/{id}
```

Cette page affiche :

- le numéro de commande ;
- la liste des produits commandés ;
- les quantités ;
- les prix unitaires ;
- les sous-totaux ;
- le total de la commande.

Cette redirection confirme aussi que le pattern redirect-after-post est bien respecté. Si on actualise la page de confirmation, la commande n’est pas recréée.

### 6. Vérifier l’email dans MailHog

J’ouvre MailHog :

```text
http://localhost:8025
```

Après la validation du checkout, un nouvel email doit apparaître dans MailHog.

L’email doit être envoyé à l’adresse email de l’utilisateur connecté.

Le sujet de l’email doit contenir le numéro de commande :

```text
Confirmation de votre commande #{orderId}
```

### 7. Vérifier le contenu de l’email

Dans MailHog, j’ouvre l’email reçu et je vérifie qu’il contient les informations suivantes :

- le numéro de commande ;
- la date de création de la commande ;
- la liste des produits ;
- les quantités ;
- les prix unitaires ;
- les sous-totaux ;
- le total ;
- l’adresse de livraison.

Ces informations sont affichées à partir du template :

```text
templates/emails/order_confirmation.html.twig
```

## Vérification en base de données

En plus de MailHog, la commande peut être vérifiée directement dans la base de données.

Commande utilisée pour vérifier les dernières commandes :

```bash
docker compose exec -T php php bin/console doctrine:query:sql "SELECT id, user_id, status, total, street, city, postal_code, country FROM \`order\` ORDER BY id DESC LIMIT 5"
```

Résultat attendu :

- une nouvelle commande existe ;
- le statut est `placed` ;
- le total correspond au total affiché au moment du checkout ;
- les champs d’adresse sont bien enregistrés.

Commande utilisée pour vérifier les lignes de commande :

```bash
docker compose exec -T php php bin/console doctrine:query:sql "SELECT id, related_order_id, product_id, quantity, unit_price FROM order_item ORDER BY id DESC LIMIT 10"
```

Résultat attendu :

- chaque produit commandé possède une ligne dans `order_item` ;
- la quantité est correcte ;
- le prix unitaire est bien sauvegardé au moment de l’achat.

## Vérification du statut de commande

Le flow email actuellement vérifié concerne la création d’une commande avec le statut :

```text
placed
```

L’enum `OrderStatus` contient aussi le statut :

```text
shipped
```

Cependant, au moment de cette vérification, je n’ai pas encore identifié dans la branche actuelle une interface permettant de modifier le statut d’une commande vers `shipped`.

Donc, pour cette tâche, le flow vérifié est :

```text
commande placée -> email de confirmation envoyé
```

Si une future tâche ajoute une interface admin pour modifier le statut d’une commande, il faudra compléter la vérification avec :

- le passage d’une commande de `placed` vers `shipped` ;
- la vérification d’un éventuel email lié au changement de statut ;
- la vérification dans MailHog ;
- la documentation du nouveau scénario testé.

## Résumé de validation

Les points suivants ont été vérifiés :

- les services Docker sont lancés ;
- MailHog est disponible sur le port 8025 ;
- les routes `/checkout`, `/login` et `/register` existent ;
- `OrderPlacedEvent` existe ;
- `OrderConfirmationSubscriber` existe ;
- le subscriber écoute bien `OrderPlacedEvent` ;
- le subscriber utilise Symfony Mailer pour envoyer un email ;
- le template email contient les informations de la commande ;
- l’email de confirmation peut être vérifié dans MailHog après un checkout réussi ;
- la commande peut être vérifiée dans la base de données.

## Conclusion

Le flow email de confirmation de commande fonctionne pour le statut `placed`.

Lorsqu’un utilisateur connecté passe une commande avec succès, l’application crée la commande, déclenche `OrderPlacedEvent`, envoie l’email via `OrderConfirmationSubscriber`, et l’email est visible dans MailHog.

La vérification des autres états de commande, comme `shipped`, pourra être ajoutée après la mise en place d’un mécanisme de modification du statut de commande.