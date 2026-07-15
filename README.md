# SQLIE-5 - Frontend layout et pipeline SCSS

## Description

Cette tâche met en place la base frontend de l’application Symfony. Elle comprend la configuration des assets avec Webpack Encore, la création d’un layout Twig commun, l’ajout d’une barre de navigation, d’un footer, ainsi que l’utilisation d’un fichier SCSS partagé chargé sur toutes les pages.

## Éléments réalisés

- Mise en place du layout principal dans `templates/base.html.twig`.
- Ajout d’un header commun.
- Ajout d’une barre de navigation avec les liens : Home, Categories, Cart, Login.
- Ajout d’une zone principale de contenu avec le bloc Twig `{% block body %}`.
- Ajout d’un footer commun.
- Création d’un fichier SCSS partagé : `assets/styles/app.scss`.
- Import du fichier SCSS dans `assets/app.js`.
- Vérification du chargement global des styles sur les pages.
- Documentation du choix du framework CSS.

## Structure frontend

Le layout principal est défini dans `templates/base.html.twig`.

Ce layout contient la structure suivante : Header -> Navbar -> Main content -> Footer.

Les styles partagés sont définis dans `assets/styles/app.scss`.

Le fichier SCSS est importé depuis `assets/app.js`.

## Compilation des assets

Installation des dépendances frontend : `yarn install`.

Lancement du mode watch : `yarn watch`.

Le mode watch permet de recompiler automatiquement les assets lorsqu’un fichier frontend est modifié.

## Choix du framework CSS

Aucun framework CSS externe comme Bootstrap ou Tailwind n’a été ajouté pour cette tâche.

Le projet utilise du SCSS personnalisé afin de garder une base frontend simple, légère et facile à maintenir.

Bootstrap ou Tailwind pourront être ajoutés plus tard si le projet nécessite des composants d’interface plus avancés.

## Vérification

La tâche est considérée comme validée lorsque :

- `yarn watch` s’exécute correctement.
- Une modification dans `assets/styles/app.scss` déclenche une recompilation automatique.
- Le layout principal s’affiche avec une barre de navigation.
- La barre de navigation contient les liens Home, Categories, Cart et Login.
- Le footer est visible sur la page.
- Le fichier SCSS partagé est chargé sur les pages.
## SQLIE-11 - QA panier et stock

Une vérification QA a été effectuée sur les cas limites liés au panier et au stock avant de passer à l'étape checkout.

### Cas testés

- Un utilisateur ne peut pas ajouter une quantité supérieure au stock actuel depuis l'interface de la page produit.
- Un utilisateur ne peut pas ajouter une quantité supérieure au stock actuel via une soumission directe du formulaire.
- La réduction du stock d'un produit déjà présent dans le panier ne fait pas crasher la page panier.

### Limitation connue

Si un produit est ajouté au panier avec une quantité valide, puis que le stock du produit est réduit ensuite via les fixtures ou une modification admin, le panier peut temporairement contenir une quantité supérieure au stock réellement disponible.

Ce cas est documenté comme une limitation connue avant la mise en place du checkout. Le stock devra être revérifié au moment du checkout afin de s'assurer que la commande finale ne dépasse pas le stock disponible.