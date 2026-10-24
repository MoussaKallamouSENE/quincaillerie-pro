import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { StockListComponent } from './stock-list';

describe('StockListComponent', () => {
  let component: StockListComponent;
  let fixture: ComponentFixture<StockListComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [StockListComponent],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    }).compileComponents();

    fixture = TestBed.createComponent(StockListComponent);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('should render the products returned by the paginated API', async () => {
    const httpMock = TestBed.inject(HttpTestingController);

    fixture.detectChanges();

    httpMock.expectOne((request) => request.url.endsWith('/products')).flush({
      data: [
        {
          id: 1,
          sku: 'M-001',
          name: 'Marteau 500 g',
          stock_quantity: 12,
          min_stock_alert: 5,
        },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
    });
    httpMock
      .expectOne((request) => request.url.endsWith('/stock-movements'))
      .flush({ data: [] });

    await fixture.whenStable();
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Marteau 500 g');
  });
});
