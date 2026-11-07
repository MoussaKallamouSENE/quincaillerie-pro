import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs';
import { StockService } from '../../../services/stock';
import { ProductService } from '../../../services/product-service';
import { AuthService } from '../../../core/auth/auth-service';
import { ToastService } from '../../../core/toast/toast-service';
import { extractErrorMessage } from '../../../core/http/api-error';

@Component({
  selector: 'app-stock-list',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './stock-list.html',
})
export class StockListComponent implements OnInit {
  private readonly stockService = inject(StockService);
  private readonly productService = inject(ProductService);
  private readonly auth = inject(AuthService);
  private readonly toastService = inject(ToastService);

  // L'application est zoneless : l'etat affiche doit etre porte par des
  // signals pour que les reponses HTTP declenchent un nouveau rendu.
  readonly products = signal<any[]>([]);
  readonly movements = signal<any[]>([]);
  readonly showModal = signal(false);
  readonly saving = signal(false);
  readonly loadError = signal<string | null>(null);
  adjustment: any = { product_id: null, type: 'in', quantity: 1, reason: '' };

  /** Seuls les responsables peuvent ajuster le stock. */
  readonly canAdjust = computed(() => this.auth.hasRole('Admin', 'Gérant'));

  ngOnInit() {
    this.loadData();
  }

  loadData() {
    this.loadError.set(null);

    this.productService.getProducts({ per_page: 100 }).subscribe({
      next: (res: any) => {
        // Si Laravel renvoie { data: [...] }, on prend res.data, sinon res
        if (Array.isArray(res)) {
          this.products.set(res);
        } else if (res && Array.isArray(res.data)) {
          this.products.set(res.data);
        } else if (res && res.data && Array.isArray(res.data.data)) {
          this.products.set(res.data.data);
        } else {
          this.products.set([]);
        }
      },
      error: (err) => {
        console.error('Erreur produits:', err);
        this.products.set([]);
        this.loadError.set(
          extractErrorMessage(err, 'Impossible de charger les produits du stock.'),
        );
      },
    });

    this.stockService.getMovements().subscribe({
      next: (res: any) => {
        if (Array.isArray(res)) {
          this.movements.set(res);
        } else if (res && Array.isArray(res.data)) {
          this.movements.set(res.data);
        } else if (res && res.data && Array.isArray(res.data.data)) {
          this.movements.set(res.data.data);
        } else {
          this.movements.set([]);
        }
      },
      error: (err) => {
        console.error('Erreur mouvements:', err);
        this.movements.set([]);
        this.loadError.set(
          extractErrorMessage(err, 'Impossible de charger les mouvements de stock.'),
        );
      },
    });
  }

  /** Quantite signee affichee dans l'historique. */
  movementQuantity(movement: { type: string; quantity: number | string }): number {
    const quantity = Number(movement.quantity);

    if (!Number.isFinite(quantity)) {
      return 0;
    }

    return movement.type === 'out' ? -Math.abs(quantity) : quantity;
  }

  openModal(product: any) {
    if (!this.canAdjust()) {
      return;
    }

    this.adjustment = { product_id: product.id, type: 'in', quantity: 1, reason: '' };
    this.showModal.set(true);
  }

  closeModal() {
    if (this.saving()) {
      return;
    }

    this.showModal.set(false);
  }

  onSubmit() {
    if (!this.canAdjust() || this.saving()) {
      return;
    }

    const rawQuantity = this.adjustment.quantity;
    const quantity = Number(rawQuantity);
    const reason = String(this.adjustment.reason ?? '').trim();
    const isAdjustment = this.adjustment.type === 'adjustment';

    if (
      rawQuantity === '' ||
      rawQuantity === null ||
      !Number.isInteger(quantity) ||
      quantity < 0 ||
      (!isAdjustment && quantity < 1)
    ) {
      this.toastService.error('Saisissez une quantité valide.');
      return;
    }

    if (!reason) {
      this.toastService.error('Indiquez le motif de l’ajustement.');
      return;
    }

    this.saving.set(true);
    this.stockService
      .createMovement({ ...this.adjustment, quantity, reason })
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: () => {
          this.showModal.set(false);
          this.loadData();
          this.toastService.success('Stock mis à jour avec succès.');
        },
        error: (err) => {
          this.toastService.error(
            extractErrorMessage(err, 'Impossible de mettre à jour le stock.'),
          );
        },
      });
  }
}
