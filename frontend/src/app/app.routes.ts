import { Routes } from '@angular/router';
import { authGuard, guestGuard } from './core/auth/auth-guard';

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
        path: 'products',
        loadComponent: () => import('./pages/products/product-list').then((m) => m.ProductList),
        title: 'Produits - Quincaillerie Pro',
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
