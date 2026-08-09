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
