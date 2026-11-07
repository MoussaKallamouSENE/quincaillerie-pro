# Quincaillerie Pro

Plateforme web de gestion de quincaillerie : ventes en caisse, stock, achats, clients, fournisseurs, facturation et rapports.

## Fonctionnalités

- Authentification et gestion des utilisateurs, avec profil et changement de mot de passe
- Point de vente (caisse) avec ventes protégées contre les doublons (idempotence)
- Génération de factures au format PDF
- Gestion des produits et des catégories
- Gestion du stock avec historique des mouvements
- Gestion des achats et des fournisseurs
- Gestion des clients
- Tableau de bord
- Rapports filtrables avec export PDF
- Paramètres de l'entreprise

## Technologies

- Backend : PHP, Laravel (API REST)
- Frontend : Angular, TypeScript, HTML5, CSS3
- Base de données : MySQL
- Outils : Git, GitHub, Composer, npm, Laravel Artisan
- Tests : tests Feature côté Laravel, tests unitaires côté Angular

## Structure du projet

- `backend/` : API Laravel
- `frontend/` : application Angular

## Installation

### Backend

```
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Configurez ensuite la base de données MySQL dans le fichier `.env`, puis :

```
php artisan migrate
php artisan serve
```

### Frontend

```
cd frontend
npm install
npm start
```

## Tests

```
cd backend && php artisan test
cd frontend && npm test
```

## Auteur

Moussa SENE, développeur et administrateur d'applications.
GitHub : https://github.com/MoussaKallamouSENE
