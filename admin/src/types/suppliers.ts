import type { InventoryProduct } from './inventory';

export type SupplierStatus = 'active' | 'inactive';

export interface SupplierListItem {
  id: number;
  name: string;
  code: string | null;
  status: SupplierStatus;
  contact_name: string | null;
  phone: string | null;
  email: string | null;
  lead_time_days: number | null;
  linked_product_count: number;
  open_purchase_order_count: number;
  last_purchase_order_date: string | null;
  updated_at: string;
}

export interface SupplierDetail extends SupplierListItem {
  website: string | null;
  address: string | null;
  notes: string | null;
  created_at: string;
}

export interface SupplierInput {
  name: string;
  code: string;
  status: SupplierStatus;
  contact_name: string;
  phone: string;
  email: string;
  website: string;
  address: string;
  lead_time_days: string;
  notes: string;
}

export interface SupplierStats {
  active_suppliers: number;
  inactive_suppliers: number;
  linked_products: number;
  products_with_suppliers: number;
}

export interface SupplierPagination {
  current_page: number;
  per_page: number;
  total_items: number;
  total_pages: number;
}

export interface SupplierPageData<T> {
  items: T[];
  pagination: SupplierPagination;
}

export interface SupplierParams {
  page: number;
  per_page: 20 | 50 | 100;
  search: string;
  status: '' | SupplierStatus;
}

export interface SupplierProduct {
  id: number;
  supplier_id: number;
  product_id: number;
  supplier_sku: string | null;
  lead_time_days: number | null;
  effective_lead_time_days: number | null;
  minimum_order_quantity: string | null;
  order_multiple: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
  product: Pick<InventoryProduct, 'id' | 'parent_id' | 'name' | 'sku' | 'type' | 'variation_attributes' | 'stock_quantity' | 'stock_status' | 'manage_stock'> | null;
}

export interface RelationshipInput {
  product_id?: number;
  supplier_sku: string;
  lead_time_days: string;
  minimum_order_quantity: string;
  order_multiple: string;
  notes: string;
}
