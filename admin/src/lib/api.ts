import type {
  AdjustmentPayload,
  AdjustmentResult,
  BulkAdjustmentResult,
  InventoryFiltersData,
  InventoryParams,
  InventoryProduct,
  InventoryStatsData,
  Paginated,
  StockMovement,
} from '@/types/inventory';
import type { RelationshipInput, SupplierDetail, SupplierInput, SupplierListItem, SupplierPageData, SupplierParams, SupplierProduct, SupplierStats } from '@/types/suppliers';
import type { PurchaseOrder, PurchaseOrderDetail, PurchaseOrderInput, PurchaseOrderItem, PurchaseOrderParams, PurchaseOrderStats, PurchasePage, PurchaseReceipt } from '@/types/purchasing';

interface ApiErrorBody { code?: string; message?: string }

export class ApiError extends Error {
  constructor(message: string, public readonly status: number, public readonly code?: string) {
    super(message);
  }
}

const endpoint = (path: string): string => {
  const [pathname, query] = path.split('?');
  const url = `${window.stockinoSettings.root}${pathname}`;
  if (!query) return url;
  return `${url}${window.stockinoSettings.root.includes('?rest_route=') ? '&' : '?'}${query}`;
};

const request = async <T>(path: string, init?: RequestInit): Promise<T> => {
  const response = await fetch(endpoint(path), {
    ...init,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': window.stockinoSettings.nonce,
      ...init?.headers,
    },
  });

  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as ApiErrorBody;
    throw new ApiError(body.message ?? 'خطای ناشناخته‌ای رخ داد.', response.status, body.code);
  }
  return response.json() as Promise<T>;
};

const queryString = (params: object): string => {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== '') query.set(key, String(value));
  });
  return query.toString();
};

export const inventoryApi = {
  list: (params: InventoryParams): Promise<Paginated<InventoryProduct>> =>
    request(`inventory?${queryString(params)}`),
  stats: (): Promise<InventoryStatsData> => request('inventory/stats'),
  filters: (): Promise<InventoryFiltersData> => request('inventory/filters'),
  movements: (productId: number, page = 1): Promise<Paginated<StockMovement>> =>
    request(`products/${productId}/movements?page=${page}&per_page=20`),
  adjust: (productId: number, payload: AdjustmentPayload): Promise<AdjustmentResult> =>
    request(`products/${productId}/adjust-stock`, { method: 'POST', body: JSON.stringify(payload) }),
  bulkAdjust: (productIds: number[], payload: Omit<AdjustmentPayload, 'mode' | 'expected_current'>): Promise<BulkAdjustmentResult> =>
    request('inventory/bulk-adjust', { method: 'POST', body: JSON.stringify({ product_ids: productIds, ...payload }) }),
  exportUrl: (params: InventoryParams): string => endpoint(`inventory/export?${queryString(params)}`),
};

export const supplierApi = {
  list: (params: SupplierParams): Promise<SupplierPageData<SupplierListItem>> => request(`suppliers?${queryString(params)}`),
  stats: (): Promise<SupplierStats> => request('suppliers/stats'),
  get: (id: number): Promise<SupplierDetail> => request(`suppliers/${id}`),
  create: (payload: SupplierInput): Promise<SupplierDetail> => request('suppliers', { method: 'POST', body: JSON.stringify(payload) }),
  update: (id: number, payload: Partial<SupplierInput>): Promise<SupplierDetail> => request(`suppliers/${id}`, { method: 'PUT', body: JSON.stringify(payload) }),
  archive: (id: number): Promise<SupplierDetail> => request(`suppliers/${id}/archive`, { method: 'POST' }),
  reactivate: (id: number): Promise<SupplierDetail> => request(`suppliers/${id}/reactivate`, { method: 'POST' }),
  products: (id: number, page: number, search: string): Promise<SupplierPageData<SupplierProduct>> => request(`suppliers/${id}/products?${queryString({ page, per_page: 20, search })}`),
  linkProduct: (id: number, payload: RelationshipInput & { product_id: number }): Promise<SupplierProduct> => request(`suppliers/${id}/products`, { method: 'POST', body: JSON.stringify(payload) }),
  updateProduct: (id: number, productId: number, payload: RelationshipInput): Promise<SupplierProduct> => request(`suppliers/${id}/products/${productId}`, { method: 'PUT', body: JSON.stringify(payload) }),
  unlinkProduct: (id: number, productId: number): Promise<{ deleted: true }> => request(`suppliers/${id}/products/${productId}`, { method: 'DELETE' }),
  searchProducts: (search: string): Promise<Paginated<InventoryProduct>> => request(`products/search?${queryString({ search, page: 1, per_page: 20 })}`),
};

export const purchasingApi = {
  list: (params: PurchaseOrderParams): Promise<PurchasePage<PurchaseOrder>> => request(`purchase-orders?${queryString(params)}`),
  stats: (): Promise<PurchaseOrderStats> => request('purchase-orders/stats'),
  get: (id: number): Promise<PurchaseOrderDetail> => request(`purchase-orders/${id}`),
  create: (payload: PurchaseOrderInput): Promise<PurchaseOrderDetail> => request('purchase-orders', { method: 'POST', body: JSON.stringify(payload) }),
  update: (id: number, payload: Partial<PurchaseOrderInput>): Promise<PurchaseOrderDetail> => request(`purchase-orders/${id}`, { method: 'PUT', body: JSON.stringify(payload) }),
  markOrdered: (id: number): Promise<PurchaseOrderDetail> => request(`purchase-orders/${id}/mark-ordered`, { method: 'POST' }),
  cancel: (id: number): Promise<PurchaseOrderDetail> => request(`purchase-orders/${id}/cancel`, { method: 'POST' }),
  addItem: (id: number, payload: { product_id: number; ordered_quantity: string; notes?: string }): Promise<PurchaseOrderItem> => request(`purchase-orders/${id}/items`, { method: 'POST', body: JSON.stringify(payload) }),
  updateItem: (id: number, itemId: number, payload: { ordered_quantity: string; notes?: string }): Promise<PurchaseOrderItem> => request(`purchase-orders/${id}/items/${itemId}`, { method: 'PUT', body: JSON.stringify(payload) }),
  deleteItem: (id: number, itemId: number): Promise<{ deleted: true }> => request(`purchase-orders/${id}/items/${itemId}`, { method: 'DELETE' }),
  receipts: (id: number): Promise<PurchasePage<PurchaseReceipt>> => request(`purchase-orders/${id}/receipts?page=1&per_page=20`),
  receipt: (id: number): Promise<PurchaseReceipt> => request(`purchase-receipts/${id}`),
  receive: (id: number, payload: { idempotency_key: string; note: string; items: { item_id: number; quantity: string }[] }): Promise<PurchaseReceipt> => request(`purchase-orders/${id}/receipts`, { method: 'POST', body: JSON.stringify(payload) }),
};
