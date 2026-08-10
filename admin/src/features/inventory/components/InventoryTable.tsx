import React from 'react';
import { History, PencilLine } from './Icons';
import { Building2 } from 'lucide-react';
import StockStatusBadge from './StockStatusBadge';
import type { InventoryProduct } from '@/types/inventory';

interface Props {
  products: InventoryProduct[];
  loading: boolean;
  selected: Set<number>;
  onSelect: (id: number, checked: boolean) => void;
  onSelectPage: (checked: boolean) => void;
  onAdjust: (product: InventoryProduct) => void;
  onHistory: (product: InventoryProduct) => void;
}

const productType = { simple: 'ساده', variable: 'متغیر', variation: 'تنوع' } as const;

export const InventoryTable: React.FC<Props> = ({ products, loading, selected, onSelect, onSelectPage, onAdjust, onHistory }) => {
  const allSelected = products.length > 0 && products.every((product) => selected.has(product.id));
  return (
    <div className="stockino-table-wrap" aria-busy={loading}>
      <table className="stockino-table">
        <thead><tr><th><input aria-label="انتخاب صفحه" type="checkbox" checked={allSelected} onChange={(event) => onSelectPage(event.target.checked)} /></th><th>محصول</th><th>SKU</th><th>نوع</th><th>موجودی</th><th>وضعیت</th><th>حد کمبود</th><th>پیش‌فروش</th><th>آخرین تغییر</th><th>عملیات</th></tr></thead>
        <tbody>
          {loading && Array.from({ length: 5 }).map((_, index) => <tr className="stockino-loading-row" key={index}><td colSpan={10}><span /></td></tr>)}
          {!loading && products.map((product) => (
            <tr key={product.id} className={selected.has(product.id) ? 'is-selected' : ''}>
              <td data-label="انتخاب"><input aria-label={`انتخاب ${product.name}`} type="checkbox" checked={selected.has(product.id)} onChange={(event) => onSelect(product.id, event.target.checked)} /></td>
              <td data-label="محصول"><div className="stockino-product-name"><strong>{product.parent_name ?? product.name}</strong>{product.parent_id > 0 && <span>— {product.variation_attributes || product.name}</span>}<small className="stockino-tabular">#{product.id.toLocaleString('en-US')}</small></div></td>
              <td data-label="SKU"><code dir="ltr">{product.sku || '—'}</code></td>
              <td data-label="نوع">{productType[product.type]}</td>
              <td data-label="موجودی"><strong className="stockino-quantity" dir="ltr">{product.stock_quantity === null ? '—' : product.stock_quantity.toLocaleString('en-US')}</strong>{!product.manage_stock && <small>مدیریت غیرفعال</small>}</td>
              <td data-label="وضعیت"><StockStatusBadge status={product.stock_status} low={product.is_low_stock} /></td>
              <td data-label="حد کمبود" className="stockino-tabular">{product.low_stock_amount.toLocaleString('en-US')}</td>
              <td data-label="پیش‌فروش">{product.backorders === 'no' ? 'خیر' : product.backorders === 'notify' ? 'با اعلان' : 'بله'}</td>
              <td data-label="آخرین تغییر">{product.last_movement ? <span className={`stockino-delta ${product.last_movement.quantity_delta >= 0 ? 'positive' : 'negative'}`} dir="ltr">{product.last_movement.quantity_delta > 0 ? '+' : ''}{product.last_movement.quantity_delta}</span> : '—'}</td>
              <td data-label="عملیات"><div className="stockino-row-actions"><button className="stockino-text-action" disabled={!product.can_adjust} title={!product.can_adjust ? 'این محصول موجودی مستقل مدیریت نمی‌کند' : undefined} onClick={() => onAdjust(product)}><PencilLine size={16} /> تنظیم</button><button className="stockino-text-action" onClick={() => onHistory(product)}><History size={16} /> تاریخچه</button><a className="stockino-text-action" href={`${window.stockinoSettings.adminUrl}?page=stockino-suppliers`}><Building2 size={16} /> تأمین‌کنندگان</a></div></td>
            </tr>
          ))}
          {!loading && products.length === 0 && <tr><td className="stockino-no-results" colSpan={10}>محصولی با این فیلترها پیدا نشد.</td></tr>}
        </tbody>
      </table>
    </div>
  );
};

export default InventoryTable;
