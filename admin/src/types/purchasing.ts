import type { SupplierPageData, SupplierProduct } from './suppliers';

export type PurchaseOrderStatus = 'draft' | 'ordered' | 'partially_received' | 'received' | 'cancelled';
export type ReceiptStatus = 'processing' | 'completed' | 'requires_attention';

export interface PurchaseOrderItem {
  id: number;
  purchase_order_id: number;
  product_id: number;
  product_name: string;
  sku: string | null;
  product_type: string;
  variation: string | null;
  supplier_sku: string | null;
  ordered_quantity: string;
  received_quantity: string;
  remaining_quantity: string;
  notes: string | null;
}

export interface PurchaseOrder {
  id: number;
  po_number: string;
  supplier_id: number;
  supplier_name: string;
  supplier_code: string | null;
  status: PurchaseOrderStatus;
  supplier_reference: string | null;
  order_date: string | null;
  expected_date: string | null;
  notes: string | null;
  item_count: number;
  ordered_units: string;
  received_units: string;
  remaining_units: string;
  created_at: string;
  updated_at: string;
  ordered_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
}

export interface PurchaseOrderDetail extends PurchaseOrder { items: PurchaseOrderItem[] }
export interface PurchaseOrderParams { page: number; per_page: 20 | 50 | 100; search: string; status: '' | PurchaseOrderStatus; supplier_id: number | '' }
export interface PurchaseOrderStats { drafts: number; ordered: number; partially_received: number; received: number; overdue: number }
export interface PurchaseOrderInput { supplier_id: number; supplier_reference: string; expected_date: string; notes: string }
export interface ReceiptItem {
  id: number;
  purchase_order_item_id: number;
  product_id: number;
  stock_owner_id: number;
  quantity_received: string;
  quantity_before: string | null;
  quantity_after: string | null;
  movement_id: number | null;
  status: 'pending' | 'completed' | 'requires_attention' | 'failed';
  error_code: string | null;
  error_message: string | null;
}
export interface PurchaseReceipt { id: number; receipt_number: string; purchase_order_id: number; status: ReceiptStatus; note: string | null; item_count: number; received_units: string; confirmed_units: string; attention_units: string; failed_units: string; created_at: string; completed_at: string | null; items?: ReceiptItem[]; idempotent_replay?: boolean }
export type PurchasePage<T> = SupplierPageData<T>;
export type PurchaseProduct = SupplierProduct;
