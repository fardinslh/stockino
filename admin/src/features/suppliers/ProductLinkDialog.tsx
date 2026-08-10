import React, { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { PackageSearch, Search, X } from 'lucide-react';
import { supplierApi } from '@/lib/api';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import type { InventoryProduct } from '@/types/inventory';
import type { RelationshipInput, SupplierProduct } from '@/types/suppliers';

interface Props { relation: SupplierProduct | null; open: boolean; pending: boolean; error: string; onClose: () => void; onSubmit: (productId: number, input: RelationshipInput) => void }
const empty: RelationshipInput = { supplier_sku: '', lead_time_days: '', minimum_order_quantity: '', order_multiple: '', notes: '' };
const typeLabel = { simple: 'ساده', variable: 'متغیر', variation: 'تنوع' } as const;

export const ProductLinkDialog: React.FC<Props> = ({ relation, open, pending, error, onClose, onSubmit }) => {
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<InventoryProduct | null>(null);
  const [form, setForm] = useState<RelationshipInput>(empty);
  const debounced = useDebouncedValue(search, 300);
  const products = useQuery({ queryKey: ['supplierProductSearch', debounced], queryFn: () => supplierApi.searchProducts(debounced), enabled: open && !relation && debounced.trim().length >= 2 });
  useEffect(() => {
    setSearch(''); setSelected(null);
    setForm(relation ? { supplier_sku: relation.supplier_sku ?? '', lead_time_days: relation.lead_time_days?.toString() ?? '', minimum_order_quantity: relation.minimum_order_quantity ?? '', order_multiple: relation.order_multiple ?? '', notes: relation.notes ?? '' } : empty);
  }, [relation, open]);
  if (!open) return null;
  const field = (key: keyof RelationshipInput) => ({ value: form[key] ?? '', onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setForm((current) => ({ ...current, [key]: event.target.value })) });
  const productId = relation?.product_id ?? selected?.id ?? 0;

  return (
    <div className="stockino-modal-backdrop" role="presentation">
      <section className="stockino-dialog stockino-link-dialog" role="dialog" aria-modal="true" aria-labelledby="stockino-link-title">
        <header><div><p>SUPPLIER / PRODUCT</p><h2 id="stockino-link-title">{relation ? 'ویرایش شرایط خرید محصول' : 'افزودن محصول تأمین‌شده'}</h2><span>این مقادیر فقط به رابطه خرید تعلق دارند و موجودی ووکامرس را تغییر نمی‌دهند.</span></div><button className="stockino-icon-button" aria-label="بستن" onClick={onClose}><X size={20} /></button></header>
        <form onSubmit={(event) => { event.preventDefault(); if (productId) onSubmit(productId, form); }}>
          {!relation && <div className="stockino-picker">
            <label className="stockino-search-field"><Search size={18} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="جستجو با نام، SKU یا شناسه محصول" aria-label="جستجوی محصول" /></label>
            {products.isFetching && <p className="stockino-picker-state">در حال جستجو…</p>}
            {!products.isFetching && debounced.length >= 2 && products.data?.items.length === 0 && <p className="stockino-picker-state">محصولی پیدا نشد.</p>}
            <div className="stockino-picker-results">{products.data?.items.map((product) => <button className={selected?.id === product.id ? 'is-selected' : ''} type="button" key={product.id} onClick={() => setSelected(product)}><PackageSearch size={18} /><span><strong>{product.name}</strong><small>{typeLabel[product.type]} · <bdi>#{product.id}</bdi></small></span><code dir="ltr">{product.sku || '—'}</code></button>)}</div>
          </div>}
          {(relation || selected) && <div className="stockino-selected-product"><strong>{relation?.product?.name ?? selected?.name}</strong><code dir="ltr">{relation?.product?.sku || selected?.sku || '—'}</code></div>}
          <div className="stockino-form-grid">
            <label className="stockino-field"><span>SKU تأمین‌کننده</span><input dir="ltr" maxLength={190} {...field('supplier_sku')} /></label>
            <label className="stockino-field"><span>زمان تأمین ویژه (روز)</span><input dir="ltr" type="number" min="0" max="3650" {...field('lead_time_days')} /></label>
            <label className="stockino-field"><span>حداقل سفارش (MOQ)</span><input dir="ltr" inputMode="decimal" {...field('minimum_order_quantity')} /></label>
            <label className="stockino-field"><span>مضرب سفارش</span><input dir="ltr" inputMode="decimal" {...field('order_multiple')} /></label>
          </div>
          <label className="stockino-field"><span>یادداشت رابطه</span><textarea rows={3} maxLength={10000} {...field('notes')} /></label>
          {error && <p className="stockino-form-error" role="alert">{error}</p>}
          <footer><button className="stockino-button stockino-button-secondary" type="button" onClick={onClose}>انصراف</button><button className="stockino-button stockino-button-primary" disabled={pending || !productId}>{pending ? 'در حال ذخیره…' : 'ذخیره رابطه'}</button></footer>
        </form>
      </section>
    </div>
  );
};

export default ProductLinkDialog;
