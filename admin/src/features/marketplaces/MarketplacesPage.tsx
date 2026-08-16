import React, { useCallback, useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Globe,
  Send,
  RefreshCw,
  Settings,
  ListFilter,
  CheckCircle2,
  AlertCircle,
  Clock,
  Layers,
  FileText,
  Search,
  ExternalLink,
  Upload,
  ShoppingBag,
  Zap,
  Loader2,
} from 'lucide-react';
import { marketplaceApi, publicationApi, syncApi } from '@/lib/api';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { ConnectionSettingsModal } from './ConnectionSettingsModal';
import { PublishProductDialog } from './PublishProductDialog';
import { PublicationTable } from './PublicationTable';
import { PublicationLogsTable } from './PublicationLogsTable';
import { ImportProductsModal } from './ImportProductsModal';
import type {
  MarketplaceConnection,
  PublicationParams,
  PublicationProduct,
} from '@/types/marketplaces';

export const MarketplacesPage: React.FC = () => {
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState<'products' | 'connections' | 'logs'>('products');
  const [params, setParams] = useState<PublicationParams>({
    page: 1,
    per_page: 20,
    search: '',
    status: '',
    marketplace: 'basalam',
  });
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebouncedValue(search);

  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [publishingProduct, setPublishingProduct] = useState<PublicationProduct | null>(null);
  const [bulkPublishOpen, setBulkPublishOpen] = useState(false);
  const [editingConnection, setEditingConnection] = useState<MarketplaceConnection | null>(null);
  const [importOpen, setImportOpen] = useState(false);
  const [syncingId, setSyncingId] = useState<number | null>(null);
  const [syncingOrders, setSyncingOrders] = useState(false);
  const [syncingFull, setSyncingFull] = useState(false);
  const [logsPage, setLogsPage] = useState(1);
  const [toastMessage, setToastMessage] = useState('');

  const showToast = (msg: string) => {
    setToastMessage(msg);
  };

  useEffect(() => {
    if (!toastMessage) return;
    const t = setTimeout(() => setToastMessage(''), 4500);
    return () => clearTimeout(t);
  }, [toastMessage]);

  useEffect(() => {
    setParams((p) => ({ ...p, page: 1, search: debouncedSearch }));
  }, [debouncedSearch]);

  const statsQuery = useQuery({
    queryKey: ['publicationStats'],
    queryFn: publicationApi.stats,
  });

  const connectionsQuery = useQuery({
    queryKey: ['marketplaceConnections'],
    queryFn: marketplaceApi.connections,
  });

  const productsQuery = useQuery({
    queryKey: ['publicationProducts', params],
    queryFn: () => publicationApi.products(params),
  });

  const refreshAll = useCallback(async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['publicationProducts'] }),
      queryClient.invalidateQueries({ queryKey: ['publicationStats'] }),
      queryClient.invalidateQueries({ queryKey: ['marketplaceConnections'] }),
      queryClient.invalidateQueries({ queryKey: ['publicationLogs'] }),
    ]);
  }, [queryClient]);

  const syncStockMutation = useMutation({
    mutationFn: (productIds: number[]) => publicationApi.syncStock(productIds),
    onSuccess: async (res) => {
      showToast(res.message || 'همگام‌سازی موجودی با موفقیت انجام شد.');
      setSyncingId(null);
      await refreshAll();
    },
    onError: (err: Error) => {
      showToast(`خطا در همگام‌سازی موجودی: ${err.message}`);
      setSyncingId(null);
    },
  });

  const handleSyncOrders = async () => {
    setSyncingOrders(true);
    try {
      const res = await syncApi.orders(params.marketplace || 'basalam');
      showToast(
        `همگام‌سازی سفارشات: ${res.created_count.toLocaleString('fa-IR')} سفارش جدید ثبت شد، ${res.updated_count.toLocaleString('fa-IR')} سفارش به‌روز شد.`
      );
      await refreshAll();
    } catch (err) {
      showToast(err instanceof Error ? err.message : 'خطا در دریافت سفارشات');
    } finally {
      setSyncingOrders(false);
    }
  };

  const handleSyncFull = async () => {
    setSyncingFull(true);
    try {
      const res = await syncApi.full(params.marketplace || 'basalam');
      showToast('همگام‌سازی کامل انبار، سفارشات و بازارگاه با موفقیت انجام شد.');
      await refreshAll();
    } catch (err) {
      showToast(err instanceof Error ? err.message : 'خطا در اجرای همگام‌سازی سراسری');
    } finally {
      setSyncingFull(false);
    }
  };

  const stats = statsQuery.data || {
    total: 0,
    published: 0,
    not_published: 0,
    in_progress: 0,
    failed: 0,
  };

  const connections = connectionsQuery.data?.connections || [];
  const products = productsQuery.data?.items || [];
  const totalItems = productsQuery.data?.total_items ?? 0;
  const totalPages = productsQuery.data?.total_pages || 1;

  const handleSelectToggle = (id: number) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const handleSelectAll = () => {
    if (selected.size === products.length) {
      setSelected(new Set());
    } else {
      setSelected(new Set(products.map((p) => p.product_id)));
    }
  };

  return (
    <div className="space-y-6">
      {/* Toast Banner */}
      {toastMessage && (
        <div className="fixed top-12 left-1/2 -translate-x-1/2 z-50 bg-slate-900 text-white text-xs px-4 py-2.5 rounded-lg shadow-xl flex items-center gap-2 border border-slate-700 animate-bounce">
          <CheckCircle2 size={16} className="text-emerald-400" />
          <span>{toastMessage}</span>
        </div>
      )}

      {/* Header & Actions */}
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-slate-900 flex items-center gap-2.5">
            <Globe className="text-indigo-600" size={24} />
            بازارگاه‌ها و انتشار محصول
          </h1>
          <p className="text-xs text-slate-500 mt-1">
            یکپارچه‌سازی و همگام‌سازی مستقیم محصولات، انبار و سفارشات باسلام و Orderino
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            className="stockino-button stockino-button-secondary text-xs flex items-center gap-1.5"
            onClick={() => setImportOpen(true)}
          >
            <Upload size={14} className="text-indigo-600" />
            ورود داده‌ها (CSV)
          </button>

          <button
            type="button"
            className="stockino-button stockino-button-secondary text-xs flex items-center gap-1.5"
            onClick={handleSyncOrders}
            disabled={syncingOrders}
          >
            {syncingOrders ? <Loader2 size={14} className="animate-spin" /> : <ShoppingBag size={14} className="text-amber-600" />}
            همگام‌سازی سفارشات
          </button>

          <button
            type="button"
            className="stockino-button stockino-button-primary text-xs flex items-center gap-1.5"
            onClick={handleSyncFull}
            disabled={syncingFull}
          >
            {syncingFull ? <Loader2 size={14} className="animate-spin" /> : <Zap size={14} />}
            همگام‌سازی کامل
          </button>

          <button
            type="button"
            className="stockino-icon-button"
            onClick={refreshAll}
            title="به‌روزرسانی داده‌ها"
          >
            <RefreshCw size={16} className={productsQuery.isFetching ? 'animate-spin' : ''} />
          </button>
        </div>
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div className="stockino-card p-4 flex items-center gap-3">
          <div className="p-2.5 rounded-lg bg-indigo-50 text-indigo-600">
            <Layers size={20} />
          </div>
          <div>
            <div className="text-xs text-slate-500">کل محصولات انبار</div>
            <div className="text-lg font-bold text-slate-800 mt-0.5">
              {stats.total.toLocaleString('fa-IR')}
            </div>
          </div>
        </div>

        <div className="stockino-card p-4 flex items-center gap-3">
          <div className="p-2.5 rounded-lg bg-emerald-50 text-emerald-600">
            <CheckCircle2 size={20} />
          </div>
          <div>
            <div className="text-xs text-slate-500">منتشر شده در بازارگاه</div>
            <div className="text-lg font-bold text-slate-800 mt-0.5">
              {stats.published.toLocaleString('fa-IR')}
            </div>
          </div>
        </div>

        <div className="stockino-card p-4 flex items-center gap-3">
          <div className="p-2.5 rounded-lg bg-slate-100 text-slate-600">
            <Clock size={20} />
          </div>
          <div>
            <div className="text-xs text-slate-500">در انتظار انتشار</div>
            <div className="text-lg font-bold text-slate-800 mt-0.5">
              {stats.not_published.toLocaleString('fa-IR')}
            </div>
          </div>
        </div>

        <div className="stockino-card p-4 flex items-center gap-3">
          <div className="p-2.5 rounded-lg bg-rose-50 text-rose-600">
            <AlertCircle size={20} />
          </div>
          <div>
            <div className="text-xs text-slate-500">نیازمند بازبینی و خطا</div>
            <div className="text-lg font-bold text-slate-800 mt-0.5">
              {stats.failed.toLocaleString('fa-IR')}
            </div>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex border-b border-slate-200 text-sm gap-2">
        <button
          type="button"
          className={`pb-3 px-4 font-medium flex items-center gap-2 border-b-2 transition-colors ${
            activeTab === 'products'
              ? 'border-indigo-600 text-indigo-700 font-semibold'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          }`}
          onClick={() => setActiveTab('products')}
        >
          <Send size={16} />
          محصولات و انتشار
        </button>

        <button
          type="button"
          className={`pb-3 px-4 font-medium flex items-center gap-2 border-b-2 transition-colors ${
            activeTab === 'connections'
              ? 'border-indigo-600 text-indigo-700 font-semibold'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          }`}
          onClick={() => setActiveTab('connections')}
        >
          <Settings size={16} />
          اتصال به بازارگاه‌ها
        </button>

        <button
          type="button"
          className={`pb-3 px-4 font-medium flex items-center gap-2 border-b-2 transition-colors ${
            activeTab === 'logs'
              ? 'border-indigo-600 text-indigo-700 font-semibold'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          }`}
          onClick={() => setActiveTab('logs')}
        >
          <FileText size={16} />
          گزارش‌ها و لاگ‌ها
        </button>
      </div>

      {/* Tab 1: Products and Publishing */}
      {activeTab === 'products' && (
        <section className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3 bg-white p-3 rounded-lg border border-slate-200 shadow-sm">
            <div className="flex flex-wrap items-center gap-3">
              <div className="relative" style={{ width: '260px' }}>
                <Search size={15} className="absolute right-3 top-2.5 text-slate-400" />
                <input
                  type="text"
                  className="stockino-input w-full pr-8 text-xs"
                  placeholder="جستجوی عنوان یا شناسه محصول..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                />
              </div>

              <select
                className="stockino-select text-xs"
                value={params.status}
                onChange={(e) => setParams((p) => ({ ...p, status: e.target.value, page: 1 }))}
              >
                <option value="">همه وضعیت‌های انتشار</option>
                <option value="published">منتشر شده</option>
                <option value="not_published">در انتظار انتشار</option>
                <option value="error">خطا در انتشار</option>
              </select>

              <select
                className="stockino-select text-xs"
                value={params.marketplace}
                onChange={(e) => setParams((p) => ({ ...p, marketplace: e.target.value, page: 1 }))}
              >
                <option value="basalam">بازارگاه باسلام</option>
                <option value="digikala">بازارگاه دیجی‌کالا</option>
                <option value="torob">موتور جستجوی ترب</option>
                <option value="mock">بازارگاه آزمایشی (Mock)</option>
              </select>
            </div>

            {selected.size > 0 && (
              <div className="flex items-center gap-2">
                <span className="text-xs text-indigo-700 font-medium">
                  {selected.size.toLocaleString('fa-IR')} محصول انتخاب شده:
                </span>
                <button
                  type="button"
                  className="stockino-button stockino-button-primary text-xs flex items-center gap-1.5"
                  onClick={() => setBulkPublishOpen(true)}
                >
                  <Send size={13} />
                  انتشار گروهی
                </button>
                <button
                  type="button"
                  className="stockino-button stockino-button-secondary text-xs flex items-center gap-1.5"
                  onClick={() => syncStockMutation.mutate(Array.from(selected))}
                  disabled={syncStockMutation.isPending}
                >
                  <RefreshCw size={13} className={syncStockMutation.isPending ? 'animate-spin' : ''} />
                  همگام‌سازی موجودی
                </button>
              </div>
            )}
          </div>

          <div className="stockino-card overflow-hidden">
            <PublicationTable
              products={products}
              loading={productsQuery.isLoading}
              selected={selected}
              syncingId={syncingId}
              onSelect={(id, checked) => {
                setSelected((prev) => {
                  const next = new Set(prev);
                  if (checked) next.add(id);
                  else next.delete(id);
                  return next;
                });
              }}
              onSelectAll={(checked) => {
                if (checked) setSelected(new Set(products.map((p) => p.product_id)));
                else setSelected(new Set());
              }}
              onPublish={(p) => setPublishingProduct(p)}
              onSyncStock={(id) => {
                setSyncingId(id);
                syncStockMutation.mutate([id]);
              }}
            />

            {/* Pagination */}
            {totalPages > 1 && (
              <div className="flex items-center justify-between p-3 border-t border-slate-200 text-xs text-slate-500">
                <div>
                  نمایش صفحه {(params.page || 1).toLocaleString('fa-IR')} از {totalPages.toLocaleString('fa-IR')} ({totalItems.toLocaleString('fa-IR')} محصول)
                </div>
                <div className="flex gap-1">
                  <button
                    type="button"
                    className="stockino-button stockino-button-secondary text-xs px-2.5 py-1"
                    disabled={(params.page || 1) <= 1}
                    onClick={() => setParams((p) => ({ ...p, page: Math.max(1, (p.page || 1) - 1) }))}
                  >
                    قبلی
                  </button>
                  <button
                    type="button"
                    className="stockino-button stockino-button-secondary text-xs px-2.5 py-1"
                    disabled={(params.page || 1) >= totalPages}
                    onClick={() => setParams((p) => ({ ...p, page: (p.page || 1) + 1 }))}
                  >
                    بعدی
                  </button>
                </div>
              </div>
            )}
          </div>
        </section>
      )}

      {/* Tab 2: Connections */}
      {activeTab === 'connections' && (
        <section className="grid md:grid-cols-2 gap-4">
          {connections.map((conn) => (
            <div key={conn.id} className="stockino-card p-5 space-y-4">
              <div className="flex items-start justify-between">
                <div>
                  <h3 className="font-bold text-slate-800 text-sm">{conn.name}</h3>
                  <p className="text-xs text-slate-400 mt-0.5">
                    نوع اتصال: {conn.marketplace === 'basalam' ? 'باسلام (OpenAPI)' : 'شبیه‌ساز تستی'}
                  </p>
                </div>
                <span
                  className={`text-[11px] font-semibold px-2 py-0.5 rounded-full ${
                    conn.status === 'active'
                      ? 'bg-emerald-50 text-emerald-700'
                      : 'bg-rose-50 text-rose-700'
                  }`}
                >
                  {conn.status === 'active' ? 'متصل و فعال' : 'قطع ارتباط'}
                </span>
              </div>

              <div className="text-xs text-slate-600 space-y-1.5 bg-slate-50 p-3 rounded border border-slate-100">
                <div className="flex justify-between">
                  <span className="text-slate-400">نام غرفه:</span>
                  <span className="font-medium text-slate-700">{conn.vendor_name || 'نامشخص'}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-400">شناسه فروشنده:</span>
                  <span className="font-mono text-slate-700">{conn.vendor_id || '-'}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-400">زمان آماده‌سازی پیش‌فرض:</span>
                  <span className="text-slate-700">{conn.preparation_days?.toLocaleString('fa-IR')} روز</span>
                </div>
              </div>

              <div className="flex justify-end gap-2 pt-2">
                <button
                  type="button"
                  className="stockino-button stockino-button-secondary text-xs flex items-center gap-1.5"
                  onClick={() => setEditingConnection(conn)}
                >
                  <Settings size={14} />
                  تنظیمات و تست اتصال
                </button>
              </div>
            </div>
          ))}
        </section>
      )}

      {/* Tab 3: Logs */}
      {activeTab === 'logs' && (
        <section className="stockino-card overflow-hidden">
          <PublicationLogsTable
            page={logsPage}
            onPageChange={(p) => setLogsPage(p)}
          />
        </section>
      )}

      {/* Modals */}
      {editingConnection && (
        <ConnectionSettingsModal
          connection={editingConnection}
          onClose={() => setEditingConnection(null)}
          onSaved={refreshAll}
        />
      )}

      {publishingProduct && (
        <PublishProductDialog
          product={publishingProduct}
          marketplace={params.marketplace}
          onClose={() => setPublishingProduct(null)}
          onPublished={() => {
            showToast('درخواست انتشار محصول با موفقیت ثبت شد.');
            refreshAll();
          }}
        />
      )}

      {bulkPublishOpen && (
        <PublishProductDialog
          product={null}
          selectedIds={Array.from(selected)}
          marketplace={params.marketplace}
          onClose={() => setBulkPublishOpen(false)}
          onPublished={() => {
            showToast('انتشار گروهی محصولات با موفقیت انجام شد.');
            setSelected(new Set());
            refreshAll();
          }}
        />
      )}

      {importOpen && (
        <ImportProductsModal
          onClose={() => setImportOpen(false)}
          onImported={() => {
            showToast('محصولات با موفقیت وارد و ذخیره شدند.');
            refreshAll();
          }}
        />
      )}
    </div>
  );
};

export default MarketplacesPage;

