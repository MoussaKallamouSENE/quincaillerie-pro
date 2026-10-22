import { Routes } from '@angular/router';
import { authGuard, guestGuard, permissionOrRoleGuard } from './core/auth/auth-guard';

export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    redirectTo: 'login',
  },
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () => import('./pages/login/login.component').then((m) => m.LoginComponent),
    title: 'Connexion - Quincaillerie Pro',
  },
  {
    // Coquille de l'application : barre laterale, barre superieure et page.
    path: '',
    canActivate: [authGuard],
    loadComponent: () => import('./layout/layout').then((m) => m.Layout),
    children: [
      {
        path: 'dashboard',
        loadComponent: () =>
          import('./features/dashboard/dashboard/dashboard').then((m) => m.Dashboard),
        title: 'Tableau de bord - Quincaillerie Pro',
      },
      {
        path: 'pos',
        loadComponent: () => import('./pages/pos/pos').then((m) => m.Pos),
        title: 'Caisse - Quincaillerie Pro',
      },
      {
        path: 'sales',
        loadComponent: () =>
          import('./pages/sales/sale-list.component').then((m) => m.SaleListComponent),
        title: 'Historique des ventes - Quincaillerie Pro',
      },
      {
        path: 'products',
        loadComponent: () => import('./pages/products/product-list').then((m) => m.ProductList),
        title: 'Produits - Quincaillerie Pro',
      },
      {
        path: 'customers',
        loadComponent: () => import('./pages/customers/customer-list').then((m) => m.CustomerList),
        title: 'Clients - Quincaillerie Pro',
      },
      {
        path: 'suppliers',
        loadComponent: () => import('./pages/suppliers/supplier-list').then((m) => m.SupplierList),
        title: 'Fournisseurs - Quincaillerie Pro',
      },
      {
        // Reserve aux comptes autorises a gerer les utilisateurs.
        path: 'users',
        canActivate: [permissionOrRoleGuard('gerer_utilisateurs', ['Admin', 'Gérant'])],
        loadComponent: () => import('./pages/users/user-list').then((m) => m.UserList),
        title: 'Utilisateurs - Quincaillerie Pro',
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
