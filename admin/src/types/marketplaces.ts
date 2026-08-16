export type MarketplaceKind = 'basalam' | 'digikala' | 'torob' | 'mock';

export type MarketplaceStatus = 'active' | 'disconnected' | 'invalid';

export type PublicationStatus = 'not_published' | 'publishing' | 'published' | 'error' | 'sync_failed';

export interface MarketplaceConnection {
  id: number;
  marketplace: MarketplaceKind;
  name: string;
  status: MarketplaceStatus;
  vendor_id: string | null;
  vendor_name: string | null;
  vendor_identifier: string | null;
  preparation_days: number;
  created_at: string;
  updated_at: string;
  credentials_meta?: {
    access_token_masked?: string;
    behavior?: string;
  };
}

export interface MarketplaceConnectionInput {
  name?: string;
  status?: MarketplaceStatus;
  credentials?: {
    access_token?: string;
    behavior?: string;
  };
  vendor_id?: string;
  vendor_name?: string;
  vendor_identifier?: string;
  preparation_days?: number;
}

export interface MarketplaceCategory {
  id: string;
  label: string;
  parentId: string | null;
}

export interface PublicationProduct {
  product_id: number;
  product_name: string;
  sku: string;
  price: string;
  regular_price: string;
  stock_quantity: number | null;
  stock_status: string;
  product_type: string;
  parent_id: number;
  marketplace: string;
  publication_status: PublicationStatus;
  external_product_id: string | null;
  category_external_id: string | null;
  auto_sync_stock: boolean;
  last_published_at: string | null;
  last_synced_at: string | null;
  last_error_message: string | null;
}

export interface PublicationParams {
  page?: number;
  per_page?: number;
  search?: string;
  status?: string;
  marketplace?: string;
}

export interface PublicationStats {
  total: number;
  published: number;
  not_published: number;
  in_progress: number;
  failed: number;
}

export interface PublicationLog {
  id: number;
  connection_id: number;
  product_id: number | null;
  action: string;
  status: 'success' | 'failure' | 'warning';
  message: string;
  payload_snapshot: string | null;
  created_at: string;
  connection_name?: string;
  marketplace?: string;
  product_name?: string;
}

export interface PublishPayload {
  product_id: number;
  marketplace?: string;
  category_external_id?: string;
  preparation_days?: number;
  title?: string;
  price?: number;
  description?: string;
  sync_price?: boolean;
  sync_inventory?: boolean;
  sync_images?: boolean;
  sync_description?: boolean;
  dry_run?: boolean;
}

export interface ImportProcessResult {
  total_rows: number;
  valid_count: number;
  imported_count: number;
  failed_count: number;
  errors: Array<{ row: number; error: string }>;
  products: Array<Record<string, unknown>>;
}

export interface CentralSyncResult {
  status: string;
  order_results: {
    total_fetched: number;
    created_count: number;
    updated_count: number;
    failed_count: number;
    errors: Array<{ external_order_id: string; error: string }>;
  };
  inventory_results: unknown[];
  retried_publications: Record<string, unknown>;
}
