import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ToastContainer } from './toast-container';
import { ToastService } from './toast-service';

describe('ToastContainer', () => {
  let fixture: ComponentFixture<ToastContainer>;
  let toastService: ToastService;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ToastContainer],
    }).compileComponents();

    fixture = TestBed.createComponent(ToastContainer);
    toastService = TestBed.inject(ToastService);
    fixture.detectChanges();
  });

  it('should render the notifications published by the service', () => {
    toastService.success('Produit « Marteau 500 g » créé avec succès.');
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;

    expect(element.textContent).toContain('Marteau 500 g');
    expect(element.querySelectorAll('[role="status"]')).toHaveLength(1);
  });

  it('should close a notification when the user clicks the close button', () => {
    toastService.info('À fermer');
    fixture.detectChanges();

    const button = (fixture.nativeElement as HTMLElement).querySelector(
      'button[aria-label="Fermer la notification"]',
    ) as HTMLButtonElement;

    button.click();
    fixture.detectChanges();

    expect(toastService.toasts()).toHaveLength(0);
    expect((fixture.nativeElement as HTMLElement).textContent).not.toContain('À fermer');
  });
});
