import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PackagePlus, PencilLine, Search, Unlink, X } from 'lucide-react';
import { supplierApi } from '@/lib/api';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import ProductLinkDialog from './ProductLinkDialog';
import type { RelationshipInput, SupplierDetail, SupplierProduct } from '@/types/suppliers';

interface Props { supplierId: number | null; onClose: () => void; onEdit: (supplier: SupplierDetail) => void; onNotice: (message: string) => void }

export const SupplierDetailDrawer: React.FC<Props> = ({ supplierId, onClose, onEdit, onNotice }) => {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [linkOpen, setLinkOpen] = useState(false);
  const [editing, setEditing] = useState<SupplierProduct | null>(null);
  const [error, setError] = useState('');
  const debounced = useDebouncedValue(search);
  useEffect(() => { setPage(1); }, [debounced, supplierId]);
  const detail = useQuery({ queryKey: ['supplier', supplierId], queryFn: () => supplierApi.get(supplierId!), enabled: supplierId !== null });
  const products = useQuery({ queryKey: ['supplierProducts', supplierId, page, debounced], queryFn: () => supplierApi.products(supplierId!, page, debounced), enabled: supplierId !== null });
  const refresh = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['supplierProducts', supplierId] }),
      queryClient.invalidateQueries({ queryKey: ['supplier', supplierId] }),
      queryClient.invalidateQueries({ queryKey: ['suppliers'] }),
      queryClient.invalidateQueries({ queryKey: ['supplierStats'] }),
    ]);
  };
  const save = useMutation({
    mutationFn: ({ productId, input }: { productId: number; input: RelationshipInput }) => editing ? supplierApi.updateProduct(supplierId!, productId, input) : supplierApi.linkProduct(supplierId!, { ...input, product_id: productId }),
    onSuccess: async () => { setLinkOpen(false); setEditing(null); setError(''); onNotice('رابطه محصول با موفقیت ذخیره شد.'); await refresh(); },
    onError: (failure: Error) => setError(failure.message),
  });
  const unlink = useMutation({
    mutationFn: (productId: number) => supplierApi.unlinkProduct(supplierId!, productId),
    onSuccess: async () => { onNotice('رابطه محصول حذف شد.'); await refresh(); },
    onError: (failure: Error) => onNotice(failure.message),
  });
  if (supplierId === null) return null;
  const supplier = detail.data;
  const pagination = products.data?.pagination;

  return (
    <div className="stockino-drawer-backdrop" role="presentation">
      <aside className="stockino-drawer stockino-supplier-drawer" role="dialog" aria-modal="true" aria-labelledby="stockino-supplier-detail-title">
        <header><div><p>{supplier?.code ?? 'SUPPLIER'}</p><h2 id="stockino-supplier-detail-title">{supplier?.name ?? 'در حال بارگذاری…'}</h2><span>{supplier?.status === 'inactive' ? 'تأمین‌کننده بایگانی شده' : 'پروفایل و محصولات تأمین‌شده'}</span></div><button className="stockino-icon-button" aria-label="بستن" onClick={onClose}><X size={20} /></button></header>
        {detail.isError && <p className="stockino-form-error" role="alert">{detail.error.message}</p>}
        {supplier && <div className="stockino-supplier-detail-body">
          <section className="stockino-supplier-profile">
            <div className="stockino-section-head"><div><small>اطلاعات تأمین‌کننده</small><h3>{supplier.contact_name || 'بدون شخص تماس'}</h3></div><button className="stockino-text-action" onClick={() => onEdit(supplier)}><PencilLine size={15} /> ویرایش</button></div>
            <dl><div><dt>تلفن</dt><dd dir="ltr">{supplier.phone || '—'}</dd></div><div><dt>ایمیل</dt><dd dir="ltr">{supplier.email || '—'}</dd></div><div><dt>زمان تأمین</dt><dd>{supplier.lead_time_days === null ? 'نامشخص' : `${supplier.lead_time_days.toLocaleString('fa-IR')} روز`}</dd></div><div><dt>وب‌سایت</dt><dd dir="ltr">{supplier.website ? <a href={supplier.website} target="_blank" rel="noreferrer">{supplier.website}</a> : '—'}</dd></div><div><dt>سفارش‌های خرید باز</dt><dd><a href={`${window.stockinoSettings.adminUrl}?page=stockino-purchase-orders`}>{supplier.open_purchase_order_count.toLocaleString('fa-IR')} سفارش</a></dd></div><div><dt>آخرین سفارش خرید</dt><dd dir="ltr">{supplier.last_purchase_order_date || '—'}</dd></div></dl>
            {(supplier.address || supplier.notes) && <div className="stockino-profile-notes">{supplier.address && <p>{supplier.address}</p>}{supplier.notes && <small>{supplier.notes}</small>}</div>}
          </section>
          <section className="stockino-supplier-products">
            <div className="stockino-section-head"><div><small>محصولات تأمین‌شده</small><h3>{supplier.linked_product_count.toLocaleString('fa-IR')} رابطه خرید</h3></div><button className="stockino-button stockino-button-primary" disabled={supplier.status === 'inactive'} title={supplier.status === 'inactive' ? 'ابتدا تأمین‌کننده را فعال کنید' : undefined} onClick={() => { setEditing(null); setError(''); setLinkOpen(true); }}><PackagePlus size={16} /> افزودن محصول</button></div>
            <label className="stockino-search-field"><Search size={17} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="جستجو در محصولات این تأمین‌کننده" /></label>
            <div className="stockino-relation-list" aria-busy={products.isFetching}>
              {products.isFetching && <p className="stockino-picker-state">در حال بارگذاری روابط…</p>}
              {!products.isFetching && products.data?.items.map((relation) => <article key={relation.id}>
                <div className="stockino-relation-product"><strong>{relation.product?.name ?? `محصول #${relation.product_id}`}</strong><span><code dir="ltr">{relation.supplier_sku || relation.product?.sku || '—'}</code> · {relation.product?.type === 'variation' ? 'تنوع' : relation.product?.type === 'variable' ? 'متغیر' : 'ساده'}</span></div>
                <div className="stockino-relation-meta"><span>زمان تأمین <b>{relation.effective_lead_time_days === null ? '—' : `${relation.effective_lead_time_days} روز`}</b></span><span>MOQ <b dir="ltr">{relation.minimum_order_quantity || '—'}</b></span><span>مضرب <b dir="ltr">{relation.order_multiple || '—'}</b></span></div>
                <div className="stockino-row-actions"><button className="stockino-text-action" onClick={() => { setEditing(relation); setError(''); setLinkOpen(true); }}><PencilLine size={15} /> ویرایش</button><button className="stockino-text-action stockino-danger-action" disabled={unlink.isPending} onClick={() => { if (window.confirm('رابطه این محصول با تأمین‌کننده حذف شود؟')) unlink.mutate(relation.product_id); }}><Unlink size={15} /> حذف رابطه</button></div>
              </article>)}
              {!products.isFetching && products.data?.items.length === 0 && <p className="stockino-picker-state">هنوز محصولی به این تأمین‌کننده متصل نشده است.</p>}
            </div>
            {pagination && pagination.total_pages > 1 && <nav className="stockino-mini-pagination" aria-label="صفحه‌بندی محصولات تأمین‌کننده"><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>قبلی</button><span>صفحه {page.toLocaleString('fa-IR')} از {pagination.total_pages.toLocaleString('fa-IR')}</span><button disabled={page >= pagination.total_pages} onClick={() => setPage((value) => value + 1)}>بعدی</button></nav>}
          </section>
        </div>}
      </aside>
      <ProductLinkDialog relation={editing} open={linkOpen} pending={save.isPending} error={error} onClose={() => { setLinkOpen(false); setEditing(null); }} onSubmit={(productId, input) => save.mutate({ productId, input })} />
    </div>
  );
};

export default SupplierDetailDrawer;
