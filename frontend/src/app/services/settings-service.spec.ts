import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { environment } from '../../environments/environment';
import { SettingsService, CompanySettings } from './settings-service';

describe('SettingsService', () => {
  let service: SettingsService;
  let httpMock: HttpTestingController;

  const baseUrl = environment.apiUrl;

  const company: CompanySettings = {
    business_name: 'Quincaillerie Pro',
    phone: '771234567',
    email: 'contact@example.com',
    address: 'Dakar',
    invoice_footer: 'Merci pour votre confiance.',
    currency: 'XOF',
    logo_path: null,
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        SettingsService,
      ],
    });

    service = TestBed.inject(SettingsService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('récupère les paramètres de l’entreprise', () => {
    service.getCompany().subscribe((response) => {
      expect(response.settings).toEqual(company);
    });

    const request = httpMock.expectOne(`${baseUrl}/settings/company`);

    expect(request.request.method).toBe('GET');
    request.flush({ settings: company });
  });

  it('enregistre les informations de l’entreprise', () => {
    const values = {
      business_name: 'Quincaillerie Pro',
      phone: '771234567',
      email: 'contact@example.com',
      address: 'Dakar',
      invoice_footer: 'Merci pour votre confiance.',
    };

    service.saveCompany(values).subscribe((response) => {
      expect(response.message).toBe('Paramètres enregistrés.');
      expect(response.settings).toEqual(company);
    });

    const request = httpMock.expectOne(`${baseUrl}/settings/company`);

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual(values);
    request.flush({ settings: company, message: 'Paramètres enregistrés.' });
  });

  it('téléverse le logo de l’entreprise en multipart/form-data', () => {
    const file = new File(['image-test'], 'logo.png', {
      type: 'image/png',
    });

    service.uploadLogo(file).subscribe((response) => {
      expect(response.message).toBe('Logo enregistré.');
      expect(response.settings).toEqual(company);
    });

    const request = httpMock.expectOne(`${baseUrl}/settings/company/logo`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toBeInstanceOf(FormData);
    expect((request.request.body as FormData).get('logo')).toBe(file);
    request.flush({ settings: company, message: 'Logo enregistré.' });
  });

  it('récupère le profil de l’utilisateur', () => {
    const user = {
      id: 1,
      name: 'Utilisateur test',
      email: 'user@example.com',
      phone: '771234567',
    };

    service.getProfile().subscribe((response) => {
      expect(response.user).toEqual(user);
    });

    const request = httpMock.expectOne(`${baseUrl}/profile`);

    expect(request.request.method).toBe('GET');
    request.flush({ user });
  });

  it('met à jour le profil de l’utilisateur', () => {
    const values = {
      name: 'Utilisateur modifié',
      email: 'modified@example.com',
      phone: '779876543',
    };

    const user = {
      id: 1,
      ...values,
      is_active: true,
      roles: ['Admin'],
      permissions: [],
    };

    service.updateProfile(values).subscribe((response) => {
      expect(response.user).toEqual(user);
      expect(response.message).toBe('Profil mis à jour.');
    });

    const request = httpMock.expectOne(`${baseUrl}/profile`);

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual(values);
    request.flush({ user, message: 'Profil mis à jour.' });
  });

  it('transmet les données de changement de mot de passe', () => {
    const values = {
      current_password: 'ancien-mot-de-passe',
      password: 'nouveau-mot-de-passe',
      password_confirmation: 'nouveau-mot-de-passe',
    };

    service.changePassword(values).subscribe((response) => {
      expect(response.message).toBe('Mot de passe modifié.');
    });

    const request = httpMock.expectOne(`${baseUrl}/profile/password`);

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual(values);
    request.flush({ message: 'Mot de passe modifié.' });
  });
});
