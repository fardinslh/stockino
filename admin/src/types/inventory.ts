export type ProductType = 'simple' | 'variable' | 'variation';
export type StockStatus = 'instock' | 'outofstock' | 'onbackorder';
export type AdjustmentReason = 'manual_adjustment' | 'damaged' | 'correction' | 'found_stock' | 'internal_use' | 'other';

export interface StockMovement {
  product_id: number;
  variation_id: number | null;
  movement_type: 'manual_adjustment' | 'woocommerce_external_change';
  reason: AdjustmentReason | 'external_change';
  quantity_before: number;
  quantity_delta: number;
  quantity_after: number;
  actor_id: number | null;
  actor_name: string | null;
  note: string | null;
  created_at: string;
}

export interface InventoryProduct {
  id: number;
  parent_id: number;
  parent_name: string | null;
  name: string;
  variation_attributes: string;
  type: ProductType;
  sku: string;
  manage_stock: boolean;
  can_adjust: boolean;
  stock_managed_by_id: number;
  stock_quantity: number | null;
  stock_status: StockStatus;
  backorders: 'no' | 'notify' | 'yes';
  low_stock_amount: number;
  is_low_stock: boolean;
  category_names: string[];
  permalink: string;
  edit_url: string;
  last_movement: StockMovement | null;
}

export interface Paginated<T> {
  items: T[];
  current_page: number;
  per_page: number;
  total_items: number;
  total_pages: number;
}

export interface InventoryStatsData {
  managed_products: number;
  out_of_stock: number;
  low_stock: number;
  total_units: number;
}

export interface InventoryFiltersData {
  categories: Array<{ id: number; name: string; slug: string }>;
  product_types: ProductType[];
  stock_statuses: StockStatus[];
}

export interface InventoryParams {
  page: number;
  per_page: 20 | 50 | 100;
  search: string;
  stock_status: '' | StockStatus;
  type: '' | ProductType;
  category: string;
  low_stock: boolean;
  manage_stock: '' | 'true' | 'false';
}

export interface AdjustmentPayload {
  mode: 'set' | 'delta';
  quantity: number;
  reason: AdjustmentReason;
  note?: string;
  expected_current?: number;
}

export interface AdjustmentResult {
  product_id: number;
  movement_id: number;
  quantity_before: number;
  quantity_delta: number;
  quantity_after: number;
  negative: boolean;
}

export interface BulkAdjustmentResult {
  updated: AdjustmentResult[];
  failed: Array<{ product_id: number; code: string; message: string }>;
}
