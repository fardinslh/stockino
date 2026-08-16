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
import type { CostHistoryPage, ValuationPageData, ValuationParams, ValuationRow, ValuationStats } from '@/types/valuation';
import type { IncomingPurchaseOrder, ReorderCreationResult, ReorderFilters, ReorderPageData, ReorderParams, ReorderSettings, ReorderStats } from '@/types/reorder';
import type {
  CentralSyncResult,
  ImportProcessResult,
  MarketplaceCategory,
  MarketplaceConnection,
  MarketplaceConnectionInput,
  PublicationLog,
  PublicationParams,
  PublicationProduct,
  PublicationStats,
  PublishPayload,
} from '@/types/marketplaces';

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

const queryString = (params: Record<string, unknown>): string =>
  Object.entries(params)
    .filter(([, value]) => value !== undefined && value !== null && value !== '')
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    .join('&');

export const inventoryApi = {
  list: (params: InventoryParams): Promise<Paginated<InventoryProduct>> => request(`inventory?${queryString(params)}`),
  stats: (): Promise<InventoryStatsData> => request('inventory/stats'),
  filters: (): Promise<InventoryFiltersData> => request('inventory/filters'),
  history: (id: number, page: number): Promise<Paginated<StockMovement>> => request(`inventory/${id}/history?${queryString({ page, per_page: 20 })}`),
  adjust: (id: number, payload: AdjustmentPayload): Promise<AdjustmentResult> => request(`inventory/${id}/adjustments`, { method: 'POST', body: JSON.stringify(payload) }),
  bulkAdjust: (product_ids: number[], payload: AdjustmentPayload): Promise<BulkAdjustmentResult> => request('inventory/bulk-adjustments', { method: 'POST', body: JSON.stringify({ product_ids, ...payload }) }),
};

export const supplierApi = {
  list: (params: SupplierParams): Promise<SupplierPageData> => request(`suppliers?${queryString(params)}`),
  stats: (): Promise<SupplierStats> => request('suppliers/stats'),
  get: (id: number): Promise<SupplierDetail> => request(`suppliers/${id}`),
  create: (payload: SupplierInput): Promise<SupplierListItem> => request('suppliers', { method: 'POST', body: JSON.stringify(payload) }),
  update: (id: number, payload: SupplierInput): Promise<SupplierListItem> => request(`suppliers/${id}`, { method: 'PUT', body: JSON.stringify(payload) }),
  archive: (id: number): Promise<SupplierListItem> => request(`suppliers/${id}/archive`, { method: 'POST' }),
  reactivate: (id: number): Promise<SupplierListItem> => request(`suppliers/${id}/reactivate`, { method: 'POST' }),
  products: (supplierId: number, params: { page?: number; per_page?: number; search?: string }): Promise<Paginated<SupplierProduct>> => request(`suppliers/${supplierId}/products?${queryString(params)}`),
  linkProduct: (supplierId: number, payload: RelationshipInput): Promise<SupplierProduct> => request(`suppliers/${supplierId}/products`, { method: 'POST', body: JSON.stringify(payload) }),
  updateProduct: (supplierId: number, relationId: number, payload: RelationshipInput): Promise<SupplierProduct> => request(`suppliers/${supplierId}/products/${relationId}`, { method: 'PUT', body: JSON.stringify(payload) }),
  unlinkProduct: (supplierId: number, relationId: number): Promise<unknown> => request(`suppliers/${supplierId}/products/${relationId}`, { method: 'DELETE' }),
  searchProducts: (query: string): Promise<Array<{ id: number; name: string; sku: string; type: string }>> => request(`products/search?${queryString({ q: query })}`),
};

export const purchasingApi = {
  list: (params: PurchaseOrderParams): Promise<PurchasePage> => request(`purchase-orders?${queryString(params)}`),
  stats: (): Promise<PurchaseOrderStats> => request('purchase-orders/stats'),
  get: (id: number): Promise<PurchaseOrderDetail> => request(`purchase-orders/${id}`),
  create: (payload: PurchaseOrderInput): Promise<PurchaseOrder> => request('purchase-orders', { method: 'POST', body: JSON.stringify(payload) }),
  update: (id: number, payload: PurchaseOrderInput): Promise<PurchaseOrder> => request(`purchase-orders/${id}`, { method: 'PUT', body: JSON.stringify(payload) }),
  markOrdered: (id: number): Promise<PurchaseOrder> => request(`purchase-orders/${id}/mark-ordered`, { method: 'POST' }),
  cancel: (id: number, reason: string): Promise<PurchaseOrder> => request(`purchase-orders/${id}/cancel`, { method: 'POST', body: JSON.stringify({ reason }) }),
  receipts: (id: number, page: number): Promise<Paginated<PurchaseReceipt>> => request(`purchase-orders/${id}/receipts?${queryString({ page, per_page: 20 })}`),
  receive: (id: number, payload: { idempotency_key: string; note?: string; lines: Array<{ item_id: number; quantity: string; actual_unit_cost?: string; note?: string }> }): Promise<PurchaseReceipt> => request(`purchase-orders/${id}/receive`, { method: 'POST', body: JSON.stringify(payload) }),
  supplierDefaults: (supplierId: number, productIds: number[]): Promise<Array<{ product_id: number; supplier_sku: string; default_unit_cost: string | null }>> => request(`suppliers/${supplierId}/product-defaults?${queryString({ product_ids: productIds.join(',') })}`),
};

export const valuationApi = {
  list: (params: ValuationParams): Promise<ValuationPageData> => request(`valuation?${queryString(params)}`),
  stats: (): Promise<ValuationStats> => request('valuation/stats'),
  get: (id: number): Promise<ValuationRow> => request(`valuation/${id}`),
  history: (id: number, page: number): Promise<CostHistoryPage> => request(`valuation/${id}/history?${queryString({ page, per_page: 20 })}`),
  setInitial: (id: number, average_unit_cost: string, reason: string): Promise<unknown> => request(`valuation/${id}/initial-cost`, { method: 'POST', body: JSON.stringify({ average_unit_cost, reason }) }),
  correct: (id: number, average_unit_cost: string, reason: string): Promise<unknown> => request(`valuation/${id}/corrections`, { method: 'POST', body: JSON.stringify({ average_unit_cost, reason }) }),
};

export const reorderApi = {
  list: (params: ReorderParams): Promise<ReorderPageData> => request(`reorder?${queryString(params)}`),
  stats: (): Promise<ReorderStats> => request('reorder/stats'),
  filters: (): Promise<ReorderFilters> => request('reorder/filters'),
  incoming: (id: number, page: number): Promise<{ items: IncomingPurchaseOrder[]; pagination: { current_page: number; per_page: number; total_items: number; total_pages: number } }> => request(`reorder/${id}/incoming?${queryString({ page, per_page: 20 })}`),
  settings: (id: number): Promise<ReorderSettings> => request(`reorder/${id}/settings`),
  updateSettings: (id: number, payload: Pick<ReorderSettings, 'custom_reorder_point' | 'custom_target_stock' | 'preferred_supplier_id' | 'preferred_product_id'>): Promise<ReorderSettings> => request(`reorder/${id}/settings`, { method: 'PATCH', body: JSON.stringify(payload) }),
  createPurchaseOrders: (stock_owner_ids: number[]): Promise<ReorderCreationResult> => request('reorder/create-purchase-orders', { method: 'POST', body: JSON.stringify({ stock_owner_ids }) }),
};

export const marketplaceApi = {
  connections: (): Promise<{ connections: MarketplaceConnection[] }> => request('marketplaces/connections'),
  saveConnection: (payload: MarketplaceConnectionInput & { marketplace: string }): Promise<{ connection: MarketplaceConnection }> =>
    request('marketplaces/connections', { method: 'POST', body: JSON.stringify(payload) }),
  testConnection: (marketplace: string): Promise<{ status: string; account_id: string; account_name: string; message: string }> =>
    request('marketplaces/test-connection', { method: 'POST', body: JSON.stringify({ marketplace }) }),
  categories: (marketplace = 'basalam'): Promise<{ categories: MarketplaceCategory[] }> =>
    request(`marketplaces/categories?${queryString({ marketplace })}`),
};

export const publicationApi = {
  products: (params: PublicationParams): Promise<Paginated<PublicationProduct>> =>
    request(`publication/products?${queryString(params)}`),
  stats: (): Promise<PublicationStats> => request('publication/stats'),
  publish: (payload: PublishPayload): Promise<{ status: string; external_product_id: string; message: string }> =>
    request('publication/publish', { method: 'POST', body: JSON.stringify(payload) }),
  publishBatch: (productIds: number[], marketplace = 'basalam'): Promise<{ published: unknown[]; failed: unknown[] }> =>
    request('publication/publish-batch', { method: 'POST', body: JSON.stringify({ product_ids: productIds, marketplace }) }),
  syncStock: (productIds: number[]): Promise<{ message: string; results: unknown[] }> =>
    request('publication/sync-stock', { method: 'POST', body: JSON.stringify({ product_ids: productIds }) }),
  logs: (page = 1, connectionId?: number, productId?: number): Promise<Paginated<PublicationLog>> =>
    request(`publication/logs?${queryString({ page, per_page: 20, connection_id: connectionId ?? '', product_id: productId ?? '' })}`),
};

export const importApi = {
  processCsv: (content: string, persist = true): Promise<ImportProcessResult> =>
    request('import/process', { method: 'POST', body: JSON.stringify({ content, persist }) }),
};

export const syncApi = {
  full: (marketplace = 'basalam'): Promise<CentralSyncResult> =>
    request('sync/full', { method: 'POST', body: JSON.stringify({ marketplace }) }),
  orders: (marketplace = 'basalam'): Promise<{ total_fetched: number; created_count: number; updated_count: number; failed_count: number; orders: unknown[]; errors: unknown[] }> =>
    request('orders/sync', { method: 'POST', body: JSON.stringify({ marketplace }) }),
};
