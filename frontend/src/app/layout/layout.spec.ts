import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Layout } from './layout';

describe('Layout', () => {
  let fixture: ComponentFixture<Layout>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Layout],
      providers: [provideRouter([])],
    }).compileComponents();

    fixture = TestBed.createComponent(Layout);
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should link to the dashboard and to the products catalogue', () => {
    const element = fixture.nativeElement as HTMLElement;
    const links = Array.from(element.querySelectorAll('a[href]')) as HTMLAnchorElement[];

    expect(links.some((link) => link.getAttribute('href') === '/dashboard')).toBe(true);
    expect(links.some((link) => link.getAttribute('href') === '/products')).toBe(true);
  });

  it('should name the products entry in the navigation', () => {
    const element = fixture.nativeElement as HTMLElement;
    const link = Array.from(element.querySelectorAll('a[href]')).find(
      (candidate) => candidate.getAttribute('href') === '/products',
    );

    expect(link?.textContent).toContain('Produits');
  });

  it('should disable the modules that are not available yet', () => {
    const element = fixture.nativeElement as HTMLElement;
    const disabled = Array.from(element.querySelectorAll('button[aria-disabled="true"]'));

    expect(disabled.length).toBeGreaterThan(0);
    expect(element.textContent).toContain('Rapports');
  });
});
