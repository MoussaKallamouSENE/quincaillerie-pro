import { TestBed } from '@angular/core/testing';
import { ToastService } from './toast-service';

describe('ToastService', () => {
  let service: ToastService;

  beforeEach(() => {
    TestBed.configureTestingModule({});

    service = TestBed.inject(ToastService);
  });

  it('should publish a success notification', () => {
    service.success('Produit « Marteau 500 g » créé avec succès.');

    expect(service.toasts()).toHaveLength(1);
    expect(service.toasts()[0].type).toBe('success');
    expect(service.toasts()[0].message).toContain('Marteau 500 g');
  });

  it('should publish an error notification', () => {
    service.error('Le serveur est injoignable.');

    expect(service.toasts()[0].type).toBe('error');
    expect(service.toasts()[0].message).toBe('Le serveur est injoignable.');
  });

  it('should stack the notifications in the order they arrive', () => {
    service.success('Premier');
    service.info('Second');

    expect(service.toasts().map((toast) => toast.message)).toEqual(['Premier', 'Second']);
  });

  it('should dismiss a notification on demand', () => {
    service.info('À fermer');
    const id = service.toasts()[0].id;

    service.dismiss(id);

    expect(service.toasts()).toHaveLength(0);
  });

  it('should dismiss the notification once its duration has elapsed', () => {
    vi.useFakeTimers();

    try {
      service.info('Temporaire', 3000);

      expect(service.toasts()).toHaveLength(1);

      vi.advanceTimersByTime(3000);

      expect(service.toasts()).toHaveLength(0);
    } finally {
      vi.useRealTimers();
    }
  });

  it('should clear every notification', () => {
    service.success('Un');
    service.error('Deux');

    service.clear();

    expect(service.toasts()).toHaveLength(0);
  });
});
