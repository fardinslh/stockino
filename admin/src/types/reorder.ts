export type ReorderState = 'healthy' | 'covered_by_incoming' | 'reorder_needed' | 'threshold_unknown' | 'no_supplier' | 'supplier_selection_required' | 'attention_required';
export type ReorderUrgency = 'critical' | 'high' | 'normal' | null;

export interface ReorderSupplier {
  supplier_id: number;
  supplier_name: string;
  supplier_code: string | null;
  source_product_id: number;
  source_product_name: string;
  source_sku: string | null;
  supplier_sku: string | null;
  minimum_order_quantity: string | null;
  order_multiple: string | null;
  effective_lead_time_days: number | null;
  default_ordered_unit_cost: string | null;
  default_cost_source: 'latest_confirmed_receipt' | 'latest_po_default' | null;
}

export interface ReorderRow {
  stock_owner_id: number;
  product_name: string;
  parent_id: number | null;
  sku: string | null;
  current_stock: string;
  woo_low_stock_amount: string | null;
  confirmed_incoming: string;
  attention_incoming: string;
  attention_required: boolean;
  inventory_position: string;
  effective_reorder_point: string | null;
  target_stock: string | null;
  raw_reorder_quantity: string | null;
  recommended_quantity: string | null;
  state: ReorderState;
  urgency: ReorderUrgency;
  custom_reorder_point: string | null;
  custom_target_stock: string | null;
  preferred_supplier_id: number | null;
  preferred_product_id: number | null;
  preferred_supplier_invalid: boolean;
  settings_updated_at: string | null;
  supplier: ReorderSupplier | null;
  supplier_selection_method: string | null;
  supplier_source_count: number;
}

export interface ReorderStats {
  reorder_needed: number;
  critical: number;
  covered_by_incoming: number;
  no_supplier: number;
  attention_required: number;
}

export interface ReorderParams {
  page: number;
  per_page: 20 | 50 | 100;
  search: string;
  state: '' | ReorderState;
  supplier_id: number | '';
  category_id: number | '';
  sort: 'urgency' | 'name' | 'stock' | 'incoming' | 'position';
}

export interface ReorderPageData {
  items: ReorderRow[];
  pagination: { current_page: number; per_page: number; total_items: number; total_pages: number };
}

export interface ReorderFilters {
  suppliers: { id: number; name: string }[];
  categories: { id: number; name: string }[];
}

export interface IncomingPurchaseOrder {
  purchase_order_id: number;
  po_number: string;
  status: 'ordered' | 'partially_received';
  expected_date: string | null;
  supplier_id: number;
  source_product_id: number;
  ordered_quantity: string;
  received_quantity: string;
  confirmed_received_quantity: string;
  attention_quantity: string;
  remaining_quantity: string;
}

export interface ReorderSettings {
  stock_owner_id: number;
  custom_reorder_point: string | null;
  custom_target_stock: string | null;
  preferred_supplier_id: number | null;
  preferred_product_id: number | null;
  updated_at: string | null;
  candidates: ReorderSupplier[];
}

export interface ReorderCreationResult {
  created: { purchase_order: { id: number; po_number: string; supplier_name: string }; stock_owner_ids: number[] }[];
  skipped: { stock_owner_id: number; reason: string; detail: string | null }[];
}
