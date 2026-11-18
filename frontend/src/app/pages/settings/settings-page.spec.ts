import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';
import { AuthService } from '../../core/auth/auth-service';
import { ToastService } from '../../core/toast/toast-service';
import { SettingsService } from '../../services/settings-service';
import { SettingsPage } from './settings-page';

const COMPANY_SETTINGS = {
  business_name: 'Quincaillerie Test',
  phone: '770000000',
  email: 'contact@test.sn',
  address: 'Dakar',
  invoice_footer: 'Merci pour votre achat',
  currency: 'XOF' as const,
  logo_path: null,
};

const PROFILE = {
  id: 1,
  name: 'Utilisateur Test',
  email: 'user@test.sn',
  phone: '771111111',
  is_active: true,
  roles: ['Admin'],
  permissions: [],
};

describe('SettingsPage', () => {
  let fixture: ComponentFixture<SettingsPage>;
  let settingsApi: {
    getCompany: ReturnType<typeof vi.fn>;
    saveCompany: ReturnType<typeof vi.fn>;
    uploadLogo: ReturnType<typeof vi.fn>;
    getLogo: ReturnType<typeof vi.fn>;
    getProfile: ReturnType<typeof vi.fn>;
    updateProfile: ReturnType<typeof vi.fn>;
    changePassword: ReturnType<typeof vi.fn>;
  };
  let auth: {
    hasRole: ReturnType<typeof vi.fn>;
    hasPermission: ReturnType<typeof vi.fn>;
    refreshProfile: ReturnType<typeof vi.fn>;
  };
  let toast: { success: ReturnType<typeof vi.fn> };

  beforeEach(() => {
    settingsApi = {
      getCompany: vi.fn().mockReturnValue(of({ settings: COMPANY_SETTINGS })),
      saveCompany: vi.fn().mockReturnValue(of({ message: 'Entreprise enregistrée.' })),
      uploadLogo: vi.fn().mockReturnValue(
        of({
          settings: COMPANY_SETTINGS,
          message: 'Logo enregistré.',
        }),
      ),
      getLogo: vi.fn().mockReturnValue(of(new Blob(['logo'], { type: 'image/png' }))),
      getProfile: vi.fn().mockReturnValue(of({ user: PROFILE })),
      updateProfile: vi.fn().mockReturnValue(of({ message: 'Profil mis à jour.' })),
      changePassword: vi.fn().mockReturnValue(of({ message: 'Mot de passe modifié.' })),
    };

    auth = {
      hasRole: vi.fn((...roles: string[]) => roles.includes('Admin')),
      hasPermission: vi.fn().mockReturnValue(false),
      refreshProfile: vi.fn().mockResolvedValue(PROFILE),
    };

    toast = { success: vi.fn() };

    TestBed.configureTestingModule({
      imports: [SettingsPage],
      providers: [
        provideRouter([]),
        { provide: SettingsService, useValue: settingsApi },
        { provide: AuthService, useValue: auth },
        { provide: ToastService, useValue: toast },
      ],
    });

    fixture = TestBed.createComponent(SettingsPage);
    fixture.detectChanges();
  });

  it('should create the settings page', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should load company settings and the current profile for an admin', () => {
    expect(settingsApi.getCompany).toHaveBeenCalledOnce();
    expect(settingsApi.getProfile).toHaveBeenCalledOnce();

    const page = fixture.componentInstance as unknown as {
      companyForm: { getRawValue: () => typeof COMPANY_SETTINGS };
      profileForm: { getRawValue: () => { name: string; email: string; phone: string } };
      loading: () => boolean;
    };

    expect(page.companyForm.getRawValue()).toEqual({
      business_name: COMPANY_SETTINGS.business_name,
      phone: COMPANY_SETTINGS.phone,
      email: COMPANY_SETTINGS.email,
      address: COMPANY_SETTINGS.address,
      invoice_footer: COMPANY_SETTINGS.invoice_footer,
    });
    expect(page.profileForm.getRawValue()).toEqual({
      name: PROFILE.name,
      email: PROFILE.email,
      phone: PROFILE.phone,
    });
    expect(page.loading()).toBe(false);
  });

  it('should load company settings once the admin role becomes available', () => {
    fixture.destroy();
    settingsApi.getCompany.mockClear();

    const adminRole = signal(false);
    auth.hasRole.mockImplementation(() => adminRole());

    const delayedFixture = TestBed.createComponent(SettingsPage);
    delayedFixture.detectChanges();

    expect(settingsApi.getCompany).not.toHaveBeenCalled();

    adminRole.set(true);
    delayedFixture.detectChanges();

    expect(settingsApi.getCompany).toHaveBeenCalledOnce();
    expect(delayedFixture.nativeElement.textContent).toContain('Entreprise et facturation');

    delayedFixture.destroy();
  });

  it('should load the stored company logo when a logo path is present', () => {
    fixture.destroy();
    settingsApi.getCompany.mockReturnValue(
      of({
        settings: { ...COMPANY_SETTINGS, logo_path: 'company-logos/logo.png' },
      }),
    );

    const logoFixture = TestBed.createComponent(SettingsPage);
    logoFixture.detectChanges();

    expect(settingsApi.getLogo).toHaveBeenCalledOnce();
    expect(logoFixture.nativeElement.textContent).toContain('Logo actuel de l’entreprise.');

    logoFixture.destroy();
  });

  it('should retry loading company settings after a failure', () => {
    fixture.destroy();
    settingsApi.getCompany.mockReset();
    settingsApi.getCompany
      .mockReturnValueOnce(
        throwError(
          () =>
            new HttpErrorResponse({
              status: 500,
              error: { message: 'Service indisponible.' },
            }),
        ),
      )
      .mockReturnValue(of({ settings: COMPANY_SETTINGS }));

    const retryFixture = TestBed.createComponent(SettingsPage);
    retryFixture.detectChanges();

    expect(retryFixture.nativeElement.textContent).toContain('Service indisponible.');
    expect(retryFixture.nativeElement.textContent).toContain('Réessayer');

    const page = retryFixture.componentInstance as unknown as {
      retryCompany: () => void;
    };
    page.retryCompany();
    retryFixture.detectChanges();

    expect(settingsApi.getCompany).toHaveBeenCalledTimes(2);
    expect(retryFixture.nativeElement.textContent).not.toContain('Réessayer');

    retryFixture.destroy();
  });

  it('should display company settings and user management for an admin', () => {
    const html = fixture.nativeElement as HTMLElement;

    expect(html.textContent).toContain('Entreprise et facturation');
    expect(html.textContent).toContain('Utilisateurs et accès');
  });

  it('should not load company settings for a non-admin user', () => {
    fixture.destroy();

    auth.hasRole.mockReturnValue(false);

    const employeeFixture = TestBed.createComponent(SettingsPage);
    employeeFixture.detectChanges();

    expect(settingsApi.getCompany).toHaveBeenCalledTimes(1);
    expect(settingsApi.getProfile).toHaveBeenCalledTimes(2);
    expect(employeeFixture.nativeElement.textContent).not.toContain('Entreprise et facturation');

    employeeFixture.destroy();
  });

  it('should not save company settings when the form is invalid', () => {
    const page = fixture.componentInstance as unknown as {
      saveCompany: () => void;
    };

    const component = fixture.componentInstance as unknown as {
      companyForm: { patchValue: (value: { business_name: string }) => void };
      saveCompany: () => void;
    };
    component.companyForm.patchValue({ business_name: '' });
    component.saveCompany();

    expect(settingsApi.saveCompany).not.toHaveBeenCalled();
  });

  it('should save valid company settings and display a success message', () => {
    const page = fixture.componentInstance as unknown as {
      companyForm: {
        setValue: (value: {
          business_name: string;
          phone: string;
          email: string;
          address: string;
          invoice_footer: string;
        }) => void;
      };
      saveCompany: () => void;
    };

    page.companyForm.setValue({
      business_name: COMPANY_SETTINGS.business_name,
      phone: COMPANY_SETTINGS.phone,
      email: COMPANY_SETTINGS.email,
      address: COMPANY_SETTINGS.address,
      invoice_footer: COMPANY_SETTINGS.invoice_footer,
    });
    page.saveCompany();

    expect(settingsApi.saveCompany).toHaveBeenCalledWith({
      business_name: COMPANY_SETTINGS.business_name,
      phone: COMPANY_SETTINGS.phone,
      email: COMPANY_SETTINGS.email,
      address: COMPANY_SETTINGS.address,
      invoice_footer: COMPANY_SETTINGS.invoice_footer,
    });
    expect(toast.success).toHaveBeenCalledWith('Entreprise enregistrée.');
  });

  it('should update the profile and refresh the authenticated user', () => {
    const page = fixture.componentInstance as unknown as {
      profileForm: { setValue: (value: { name: string; email: string; phone: string }) => void };
      saveProfile: () => void;
    };

    const updatedProfile = {
      name: 'Nouveau Nom',
      email: 'nouveau@test.sn',
      phone: '772222222',
    };

    page.profileForm.setValue(updatedProfile);
    page.saveProfile();

    expect(settingsApi.updateProfile).toHaveBeenCalledWith(updatedProfile);
    expect(toast.success).toHaveBeenCalledWith('Profil mis à jour.');
    expect(auth.refreshProfile).toHaveBeenCalledOnce();
  });

  it('should reject a password when the confirmation does not match', () => {
    const page = fixture.componentInstance as unknown as {
      passwordForm: {
        setValue: (value: {
          current_password: string;
          password: string;
          password_confirmation: string;
        }) => void;
      };
      changePassword: () => void;
      confirmationError: () => string | null;
    };

    page.passwordForm.setValue({
      current_password: 'ancien-mot-de-passe',
      password: 'nouveau-mot-de-passe',
      password_confirmation: 'confirmation-differente',
    });

    page.changePassword();
    fixture.detectChanges();

    expect(settingsApi.changePassword).not.toHaveBeenCalled();
    expect(page.confirmationError()).toBe('La confirmation ne correspond pas.');
    expect(fixture.nativeElement.textContent).toContain('La confirmation ne correspond pas.');
  });

  it('should change the password when the confirmation matches', () => {
    const page = fixture.componentInstance as unknown as {
      passwordForm: {
        setValue: (value: {
          current_password: string;
          password: string;
          password_confirmation: string;
        }) => void;
      };
      changePassword: () => void;
      passwordFormValue: () => unknown;
    };

    const passwordValues = {
      current_password: 'ancien-mot-de-passe',
      password: 'nouveau-mot-de-passe',
      password_confirmation: 'nouveau-mot-de-passe',
    };

    page.passwordForm.setValue(passwordValues);
    page.changePassword();

    expect(settingsApi.changePassword).toHaveBeenCalledWith(passwordValues);
    expect(toast.success).toHaveBeenCalledWith('Mot de passe modifié.');
  });

  it('should reject an unsupported logo file', () => {
    const page = fixture.componentInstance as unknown as {
      chooseLogo: (event: Event) => void;
      companyError: () => string | null;
    };

    const file = new File(['contenu'], 'document.txt', { type: 'text/plain' });
    const event = {
      target: { files: [file] },
    } as unknown as Event;

    page.chooseLogo(event);

    expect(settingsApi.uploadLogo).not.toHaveBeenCalled();
    expect(page.companyError()).toBe('Choisissez une image PNG, JPEG ou WebP de 2 Mo maximum.');
  });

  it('should upload a supported logo file', () => {
    const page = fixture.componentInstance as unknown as {
      chooseLogo: (event: Event) => void;
    };

    const input = document.createElement('input');
    const file = new File(['image'], 'logo.png', { type: 'image/png' });
    Object.defineProperty(input, 'files', { value: [file] });
    const event = { target: input } as unknown as Event;

    page.chooseLogo(event);

    expect(settingsApi.uploadLogo).toHaveBeenCalledWith(file);
    expect(toast.success).toHaveBeenCalledWith('Logo enregistré.');
  });

  it('should display an error when the profile cannot be loaded', () => {
    fixture.destroy();

    settingsApi.getProfile.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 500,
            error: { message: 'Erreur de chargement du profil.' },
          }),
      ),
    );

    const errorFixture = TestBed.createComponent(SettingsPage);
    errorFixture.detectChanges();

    expect(errorFixture.nativeElement.textContent).toContain('Erreur de chargement du profil.');

    errorFixture.destroy();
  });
});
