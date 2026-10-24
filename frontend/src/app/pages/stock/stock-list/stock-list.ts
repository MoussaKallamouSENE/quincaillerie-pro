import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { StockService } from '../../../services/stock';
import { ProductService } from '../../../services/product-service';

@Component({
  selector: 'app-stock-list',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './stock-list.html'
})
export class StockListComponent implements OnInit {
  // L'application est zoneless : l'etat affiche doit etre porte par des
  // signals pour que les reponses HTTP declenchent un nouveau rendu.
  readonly products = signal<any[]>([]);
  readonly movements = signal<any[]>([]);
  readonly showModal = signal(false);
  adjustment: any = { product_id: null, type: 'in', quantity: 1, reason: '' };

  constructor(
    private stockService: StockService,
    private productService: ProductService
  ) {}

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

  openModal(product: any) {
    this.adjustment = { product_id: product.id, type: 'in', quantity: 1, reason: '' };
    this.showModal.set(true);
  }

  onSubmit() {
    this.stockService.createMovement(this.adjustment).subscribe({
      next: () => {
        this.showModal.set(false);
        this.loadData();
        alert('Stock mis à jour avec succès !');
      },
      error: (err) => alert('Erreur: ' + (err.error.message || 'Erreur inconnue'))
    });
  }
}
