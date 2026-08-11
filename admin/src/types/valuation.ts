export type CostStatus = 'costed' | 'uncosted';
export type ValuationStockStatus = 'positive' | 'zero' | 'negative';

export interface ValuationRow {
  stock_owner_id: number;
  product_name: string;
  parent_id: number | null;
  sku: string | null;
  current_stock: string;
  average_unit_cost: string | null;
  inventory_value: string;
  cost_status: CostStatus;
  stock_status: ValuationStockStatus;
  currency_snapshot: string;
  cost_updated_at: string | null;
}

export interface ValuationStats {
  total_known_value: string;
  costed_stock_owners: number;
  uncosted_positive_stock_owners: number;
  positive_stock_owners: number;
  total_stock_owners: number;
  is_partial: boolean;
  currency: string;
}

export interface CostMovement {
  id: number;
  stock_owner_id: number;
  stock_owner_name_snapshot: string;
  source_product_id: number;
  source_variation_id: number | null;
  source_product_name_snapshot: string;
  source_sku_snapshot: string | null;
  purchase_order_id: number | null;
  receipt_id: number | null;
  movement_type: 'purchase_receipt' | 'initial_cost' | 'cost_correction';
  quantity_received: string;
  unit_cost: string;
  quantity_before: string;
  quantity_after: string;
  average_cost_before: string | null;
  average_cost_after: string;
  inventory_value_before: string;
  inventory_value_after: string;
  currency_snapshot: string;
  reason: string | null;
  actor_name: string | null;
  po_number: string | null;
  receipt_number: string | null;
  created_at: string;
}

export interface ValuationParams {
  page: number;
  per_page: 20 | 50 | 100;
  search: string;
  stock_status: '' | ValuationStockStatus;
  cost_status: '' | CostStatus;
  sort: 'name' | 'stock' | 'average_cost' | 'value' | 'updated';
  direction: 'asc' | 'desc';
}

export interface ValuationPageData {
  items: ValuationRow[];
  pagination: { current_page: number; per_page: number; total_items: number; total_pages: number };
}
export interface CostHistoryPage {
  items: CostMovement[];
  pagination: { current_page: number; per_page: number; total_items: number; total_pages: number };
}
