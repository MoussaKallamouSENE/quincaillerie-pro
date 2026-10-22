import { registerLocaleData } from '@angular/common';
import localeFr from '@angular/common/locales/fr';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, LOCALE_ID, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { authInterceptor, unauthorizedInterceptor } from './core/auth/auth-interceptor';
import { routes } from './app.routes';

// L'interface est entierement en francais : les pipes `number` et `date`
// utilisent les conventions locales (`1 250 000`, `jeudi 22 octobre 2026`).
registerLocaleData(localeFr);

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes, withComponentInputBinding()),
    provideHttpClient(withInterceptors([authInterceptor, unauthorizedInterceptor])),
    { provide: LOCALE_ID, useValue: 'fr-FR' },
  ],
};
