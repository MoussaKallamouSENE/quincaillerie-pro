import { CommonModule, DatePipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../core/auth/auth-service';
import { ToastService } from '../../core/toast/toast-service';
import { ProductService } from '../../services/product-service';
import { Purchase, PurchaseService } from '../../services/purchase-service';
import { SupplierService } from '../../services/supplier-service';

interface PurchaseLine {
  product_id: number | null;
  quantity: number;
  unit_price: number;
}

@Component({
  selector: 'app-purchase-list',
  standalone: true,
  imports: [CommonModule, FormsModule, DatePipe],
  templateUrl: './purchase-list.html',
  styleUrl: './purchase-list.css',
})
export class PurchaseListComponent implements OnInit {
  private readonly purchaseService = inject(PurchaseService);
  private readonly supplierService = inject(SupplierService);
  private readonly productService = inject(ProductService);
  private readonly toastService = inject(ToastService);
  protected readonly auth = inject(AuthService);

  readonly purchases = signal<Purchase[]>([]);
  readonly suppliers = signal<any[]>([]);
  readonly products = signal<any[]>([]);
  readonly loading = signal(false);
  readonly showModal = signal(false);
  readonly submitting = signal(false);

  supplierId: number | null = null;
  lines: PurchaseLine[] = [{ product_id: null, quantity: 1, unit_price: 0 }];

  protected readonly canManagePurchases = computed(() =>
    this.auth.hasRole('Admin', 'Gérant'),
  );

  ngOnInit(): void {
    this.loadPurchases();
    this.loadSuppliers();
    this.loadProducts();
  }

  loadPurchases(): void {
    this.loading.set(true);
    this.purchaseService.getPurchases().subscribe({
      next: (res: any) => {
        if (Array.isArray(res)) {
          this.purchases.set(res);
        } else if (res && Array.isArray(res.data)) {
          this.purchases.set(res.data);
        } else {
          this.purchases.set([]);
        }
        this.loading.set(false);
      },
      error: (err) => {
        console.error('Erreur achats:', err);
        this.loading.set(false);
        this.purchases.set([]);
      },
    });
  }

  loadSuppliers(): void {
    this.supplierService.getSuppliers({ per_page: 100 }).subscribe({
      next: (res: any) => {
        if (Array.isArray(res)) {
          this.suppliers.set(res);
        } else if (res && Array.isArray(res.data)) {
          this.suppliers.set(res.data);
        } else {
          this.suppliers.set([]);
        }
      },
    });
  }

  loadProducts(): void {
    this.productService.getProducts({ per_page: 100 }).subscribe({
      next: (res: any) => {
        if (Array.isArray(res)) {
          this.products.set(res);
        } else if (res && Array.isArray(res.data)) {
          this.products.set(res.data);
        } else {
          this.products.set([]);
        }
      },
    });
  }

  openModal(): void {
    if (!this.canManagePurchases()) {
      return;
    }
    this.supplierId = null;
    this.lines = [{ product_id: null, quantity: 1, unit_price: 0 }];
    this.showModal.set(true);
  }

  closeModal(): void {
    this.showModal.set(false);
  }

  addLine(): void {
    this.lines.push({ product_id: null, quantity: 1, unit_price: 0 });
  }

  removeLine(index: number): void {
    if (this.lines.length === 1) {
      return;
    }
    this.lines.splice(index, 1);
  }

  onProductChange(line: PurchaseLine): void {
    const product = this.products().find((p) => p.id === Number(line.product_id));
    if (product && product.purchase_price) {
      line.unit_price = Number(product.purchase_price);
    }
  }

  get total(): number {
    return this.lines.reduce((sum, line) => {
      const qty = Number(line.quantity) || 0;
      const price = Number(line.unit_price) || 0;
      return sum + qty * price;
    }, 0);
  }

  createPurchase(): void {
    if (!this.canManagePurchases()) {
      return;
    }
    if (!this.supplierId) {
      alert('Veuillez sélectionner un fournisseur');
      return;
    }
    const items = this.lines
      .filter((l) => l.product_id && Number(l.quantity) > 0 && Number(l.unit_price) >= 0)
      .map((l) => ({
        product_id: Number(l.product_id),
        quantity: Number(l.quantity),
        unit_price: Number(l.unit_price),
      }));

    if (items.length === 0) {
      alert('Veuillez ajouter au moins un produit');
      return;
    }

    this.submitting.set(true);
    this.purchaseService.createPurchase({ supplier_id: Number(this.supplierId), items }).subscribe({
      next: () => {
        this.submitting.set(false);
        this.showModal.set(false);
        this.toastService.success("Commande d'achat créée avec succès");
        this.loadPurchases();
      },
      error: (err) => {
        this.submitting.set(false);
        alert(err.error?.message || "Erreur lors de la création de l'achat");
      },
    });
  }

  receivePurchase(purchase: Purchase): void {
    if (purchase.status !== 'pending') {
      return;
    }
    if (!confirm('Confirmer la réception de cette commande ? Le stock sera mis à jour.')) {
      return;
    }
    this.purchaseService.receivePurchase(purchase.id).subscribe({
      next: () => {
        this.toastService.success('Réception validée - Stock mis à jour');
        this.loadPurchases();
      },
      error: (err) => {
        alert(err.error?.message || 'Erreur lors de la réception');
      },
    });
  }

  getStatusLabel(status: string): string {
    if (status === 'received') {
      return 'Réceptionné';
    }
    if (status === 'pending') {
      return 'En attente';
    }
    return status;
  }

  getStatusClass(status: string): string {
    if (status === 'received') {
      return 'bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/25';
    }
    return 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/25';
  }
}
