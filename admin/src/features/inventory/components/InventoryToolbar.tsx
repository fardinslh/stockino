import React from 'react';
import { Download, Search } from './Icons';
import type { InventoryFiltersData, InventoryParams } from '@/types/inventory';

interface Props {
  params: InventoryParams;
  searchValue: string;
  filters?: InventoryFiltersData;
  selectedCount: number;
  onSearch: (value: string) => void;
  onChange: (patch: Partial<InventoryParams>) => void;
  onBulk: () => void;
  onExport: () => void;
}

export const InventoryToolbar: React.FC<Props> = ({ params, searchValue, filters, selectedCount, onSearch, onChange, onBulk, onExport }) => (
  <section className="stockino-toolbar" aria-label="جستجو و فیلتر موجودی">
    <div className="stockino-search-field">
      <Search size={18} aria-hidden="true" />
      <label className="screen-reader-text" htmlFor="stockino-search">جستجوی محصول</label>
      <input id="stockino-search" value={searchValue} onChange={(event) => onSearch(event.target.value)} placeholder="جستجوی نام، SKU یا شناسه محصول…" />
    </div>
    <div className="stockino-filter-row">
      <label><span>وضعیت موجودی</span><select value={params.stock_status} onChange={(event) => onChange({ stock_status: event.target.value as InventoryParams['stock_status'] })}><option value="">همه وضعیت‌ها</option><option value="instock">موجود</option><option value="outofstock">ناموجود</option><option value="onbackorder">پیش‌فروش</option></select></label>
      <label><span>نوع محصول</span><select value={params.type} onChange={(event) => onChange({ type: event.target.value as InventoryParams['type'] })}><option value="">همه انواع</option><option value="simple">ساده</option><option value="variable">متغیر</option><option value="variation">تنوع</option></select></label>
      <label><span>دسته‌بندی</span><select value={params.category} onChange={(event) => onChange({ category: event.target.value })}><option value="">همه دسته‌ها</option>{filters?.categories.map((category) => <option value={category.slug} key={category.id}>{category.name}</option>)}</select></label>
      <label><span>مدیریت موجودی</span><select value={params.manage_stock} onChange={(event) => onChange({ manage_stock: event.target.value as InventoryParams['manage_stock'] })}><option value="">همه</option><option value="true">فعال</option><option value="false">غیرفعال</option></select></label>
      <label className="stockino-check-label"><input type="checkbox" checked={params.low_stock} onChange={(event) => onChange({ low_stock: event.target.checked })} /><span>فقط کم‌موجودی</span></label>
    </div>
    <div className="stockino-toolbar-actions">
      {selectedCount > 0 && <button className="stockino-button stockino-button-primary" onClick={onBulk}>{selectedCount.toLocaleString('fa-IR')} محصول انتخاب شده · تغییر گروهی</button>}
      <button className="stockino-button stockino-button-secondary" onClick={onExport}><Download size={17} /> خروجی CSV</button>
    </div>
  </section>
);

export default InventoryToolbar;
