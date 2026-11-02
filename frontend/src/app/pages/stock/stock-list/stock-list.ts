import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { StockService } from '../../../services/stock';
import { ProductService } from '../../../services/product-service';
import { AuthService } from '../../../core/auth/auth-service';
import { ToastService } from '../../../core/toast/toast-service';
import { extractErrorMessage } from '../../../core/http/api-error';

@Component({
  selector: 'app-stock-list',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './stock-list.html'
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
  adjustment: any = { product_id: null, type: 'in', quantity: 1, reason: '' };

  /** Seuls les responsables peuvent ajuster le stock. */
  readonly canAdjust = computed(() => this.auth.hasRole('Admin', 'Gérant'));

  ngOnInit() {
    this.loadData();
  }

  loadData() {
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
      }
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
      }
    });
  }

  /** Quantite signee affichee dans l'historique. */
  movementQuantity(movement: { type: string; quantity: number | string }): number {
    const quantity = Number(movement.quantity);

    if (! Number.isFinite(quantity)) {
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

  onSubmit() {
    if (!this.canAdjust()) {
      return;
    }

    this.stockService.createMovement(this.adjustment).subscribe({
      next: () => {
        this.showModal.set(false);
        this.loadData();
        this.toastService.success('Stock mis à jour avec succès.');
      },
      error: (err) => {
        this.toastService.error(
          extractErrorMessage(err, 'Impossible de mettre à jour le stock.'),
        );
      }
    });
  }
}
