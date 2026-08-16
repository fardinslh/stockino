import React from 'react';
import {
  CheckCircle2,
  AlertCircle,
  Clock,
  RefreshCw,
  Send,
  ExternalLink,
  RotateCcw,
  Loader2,
} from 'lucide-react';
import type { PublicationProduct } from '@/types/marketplaces';

interface Props {
  products: PublicationProduct[];
  loading: boolean;
  selected: Set<number>;
  onSelect: (id: number, checked: boolean) => void;
  onSelectAll: (checked: boolean) => void;
  onPublish: (product: PublicationProduct) => void;
  onSyncStock: (productId: number) => void;
  syncingId: number | null;
}

export const PublicationTable: React.FC<Props> = ({
  products,
  loading,
  selected,
  onSelect,
  onSelectAll,
  onPublish,
  onSyncStock,
  syncingId,
}) => {
  const allSelected = products.length > 0 && products.every((p) => selected.has(p.product_id));

  const renderStatus = (product: PublicationProduct) => {
    switch (product.publication_status) {
      case 'published':
        return (
          <span className="inline-flex items-center gap-1 text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded text-xs font-medium">
            <CheckCircle2 size={13} />
            منتشر شده
          </span>
        );
      case 'publishing':
        return (
          <span className="inline-flex items-center gap-1 text-indigo-700 bg-indigo-50 border border-indigo-200 px-2 py-0.5 rounded text-xs font-medium">
            <Loader2 size={13} className="animate-spin" />
            در حال انتشار...
          </span>
        );
      case 'error':
      case 'sync_failed':
        return (
          <span
            className="inline-flex items-center gap-1 text-rose-700 bg-rose-50 border border-rose-200 px-2 py-0.5 rounded text-xs font-medium cursor-help"
            title={product.last_error_message || 'خطا در انتشار یا همگام‌سازی'}
          >
            <AlertCircle size={13} />
            خطا در انتشار
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center gap-1 text-slate-600 bg-slate-100 border border-slate-200 px-2 py-0.5 rounded text-xs">
            <Clock size={13} />
            در انتظار انتشار
          </span>
        );
    }
  };

  return (
    <div className="stockino-card overflow-x-auto">
      <table className="stockino-table w-full text-right text-xs">
        <thead>
          <tr>
            <th className="py-2.5 px-3 w-8">
              <input
                type="checkbox"
                className="stockino-checkbox"
                checked={allSelected}
                onChange={(e) => onSelectAll(e.target.checked)}
                aria-label="انتخاب همه"
              />
            </th>
            <th className="py-2.5 px-3">نام محصول</th>
            <th className="py-2.5 px-3">شناسه / SKU</th>
            <th className="py-2.5 px-3">قیمت</th>
            <th className="py-2.5 px-3">موجودی</th>
            <th className="py-2.5 px-3">وضعیت انتشار</th>
            <th className="py-2.5 px-3">شناسه بازارگاه</th>
            <th className="py-2.5 px-3">آخرین همگام‌سازی</th>
            <th className="py-2.5 px-3 text-left">عملیات</th>
          </tr>
        </thead>
        <tbody>
          {loading ? (
            <tr>
              <td colSpan={9} className="text-center py-8 text-slate-400">
                در حال بارگذاری لیست محصولات...
              </td>
            </tr>
          ) : products.length === 0 ? (
            <tr>
              <td colSpan={9} className="text-center py-8 text-slate-400">
                هیچ محصولی با فیلترهای جاری یافت نشد.
              </td>
            </tr>
          ) : (
            products.map((product) => {
              const isChecked = selected.has(product.product_id);
              const isSyncing = syncingId === product.product_id;

              return (
                <tr
                  key={product.product_id}
                  className={`border-b border-slate-100 transition-colors ${
                    isChecked ? 'bg-indigo-50/40' : 'hover:bg-slate-50/60'
                  }`}
                >
                  <td className="py-2.5 px-3">
                    <input
                      type="checkbox"
                      className="stockino-checkbox"
                      checked={isChecked}
                      onChange={(e) => onSelect(product.product_id, e.target.checked)}
                    />
                  </td>
                  <td className="py-2.5 px-3">
                    <div className="font-medium text-slate-900 line-clamp-1" title={product.product_name}>
                      {product.product_name}
                    </div>
                    <div className="text-[11px] text-slate-400">
                      {product.product_type === 'variation' ? 'متغیر (تنوع)' : 'ساده'}
                    </div>
                  </td>
                  <td className="py-2.5 px-3 text-slate-600 font-mono">
                    {product.sku || `#${product.product_id}`}
                  </td>
                  <td className="py-2.5 px-3 whitespace-nowrap text-slate-800">
                    {product.price ? `${parseInt(product.price).toLocaleString('fa-IR')} تومان` : '-'}
                  </td>
                  <td className="py-2.5 px-3 whitespace-nowrap">
                    {product.stock_quantity !== null ? (
                      <span
                        className={`font-semibold ${
                          product.stock_quantity > 0 ? 'text-slate-800' : 'text-rose-600'
                        }`}
                      >
                        {product.stock_quantity.toLocaleString('fa-IR')}
                      </span>
                    ) : (
                      <span className="text-slate-400">مدیریت نشده</span>
                    )}
                  </td>
                  <td className="py-2.5 px-3">{renderStatus(product)}</td>
                  <td className="py-2.5 px-3 font-mono text-slate-600">
                    {product.external_product_id ? (
                      <span className="inline-flex items-center gap-1 bg-slate-100 px-2 py-0.5 rounded text-[11px]">
                        {product.external_product_id}
                      </span>
                    ) : (
                      <span className="text-slate-400">-</span>
                    )}
                  </td>
                  <td className="py-2.5 px-3 whitespace-nowrap text-slate-500 text-[11px]">
                    {product.last_synced_at || product.last_published_at || '-'}
                  </td>
                  <td className="py-2.5 px-3 text-left">
                    <div className="flex items-center justify-end gap-1.5">
                      {product.publication_status === 'published' ? (
                        <button
                          type="button"
                          className="stockino-button stockino-button-secondary text-xs px-2.5 py-1 flex items-center gap-1"
                          onClick={() => onSyncStock(product.product_id)}
                          disabled={isSyncing}
                          title="همگام‌سازی مجدد موجودی"
                        >
                          <RefreshCw size={13} className={isSyncing ? 'animate-spin' : ''} />
                          همگام‌سازی موجودی
                        </button>
                      ) : (
                        <button
                          type="button"
                          className="stockino-button stockino-button-primary text-xs px-2.5 py-1 flex items-center gap-1"
                          onClick={() => onPublish(product)}
                        >
                          <Send size={13} />
                          انتشار
                        </button>
                      )}

                      {product.publication_status === 'error' && (
                        <button
                          type="button"
                          className="stockino-button stockino-button-secondary text-xs px-2 py-1 text-rose-700"
                          onClick={() => onPublish(product)}
                          title="تلاش مجدد برای انتشار"
                        >
                          <RotateCcw size={13} />
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              );
            })
          )}
        </tbody>
      </table>
    </div>
  );
};
