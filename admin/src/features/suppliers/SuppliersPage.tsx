import React, { useEffect, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Archive, Building2, PackageCheck, PencilLine, Plus, RotateCcw, Search, Users } from 'lucide-react';
import { supplierApi } from '@/lib/api';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import SupplierDetailDrawer from './SupplierDetailDrawer';
import SupplierFormDialog from './SupplierFormDialog';
import type { SupplierDetail, SupplierInput, SupplierListItem, SupplierParams } from '@/types/suppliers';

const initial: SupplierParams = { page: 1, per_page: 20, search: '', status: '' };

export const SuppliersPage: React.FC = () => {
  const queryClient = useQueryClient();
  const [params, setParams] = useState<SupplierParams>(initial);
  const [search, setSearch] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<SupplierDetail | null>(null);
  const [detailId, setDetailId] = useState<number | null>(null);
  const [formError, setFormError] = useState('');
  const [notice, setNotice] = useState('');
  const debounced = useDebouncedValue(search);
  useEffect(() => setParams((current) => ({ ...current, page: 1, search: debounced })), [debounced]);
  useEffect(() => { if (!notice) return; const timer = window.setTimeout(() => setNotice(''), 4500); return () => window.clearTimeout(timer); }, [notice]);
  const suppliers = useQuery({ queryKey: ['suppliers', params], queryFn: () => supplierApi.list(params), placeholderData: keepPreviousData });
  const stats = useQuery({ queryKey: ['supplierStats'], queryFn: supplierApi.stats });
  const refresh = async () => Promise.all([queryClient.invalidateQueries({ queryKey: ['suppliers'] }), queryClient.invalidateQueries({ queryKey: ['supplierStats'] })]);
  const save = useMutation({
    mutationFn: (input: SupplierInput) => editing ? supplierApi.update(editing.id, input) : supplierApi.create(input),
    onSuccess: async (saved) => { setFormOpen(false); setEditing(null); setFormError(''); setNotice('اطلاعات تأمین‌کننده ذخیره شد.'); if (detailId === saved.id) await queryClient.invalidateQueries({ queryKey: ['supplier', saved.id] }); await refresh(); },
    onError: (error: Error) => setFormError(error.message),
  });
  const statusMutation = useMutation({
    mutationFn: (supplier: SupplierListItem) => supplier.status === 'active' ? supplierApi.archive(supplier.id) : supplierApi.reactivate(supplier.id),
    onSuccess: async (saved) => { setNotice(saved.status === 'active' ? 'تأمین‌کننده دوباره فعال شد.' : 'تأمین‌کننده بایگانی شد.'); await refresh(); },
    onError: (error: Error) => setNotice(error.message),
  });
  const openEdit = async (supplier: SupplierListItem | SupplierDetail) => {
    try { const detail = 'website' in supplier ? supplier : await supplierApi.get(supplier.id); setEditing(detail); setFormError(''); setFormOpen(true); } catch (error) { setNotice(error instanceof Error ? error.message : 'اطلاعات تأمین‌کننده بارگذاری نشد.'); }
  };
  const data = suppliers.data;

  return (
    <main className="stockino-app stockino-suppliers-app" dir="rtl">
      <header className="stockino-page-header stockino-enter"><div className="stockino-mark stockino-supplier-mark" aria-hidden="true"><Building2 size={23} /></div><div><p className="stockino-eyebrow">STOCKINO / SUPPLY NETWORK</p><h1>مدیریت تأمین‌کنندگان</h1><p>پروفایل تأمین‌کنندگان و شرایط خرید هر محصول را یک‌جا مدیریت کنید.</p></div><button className="stockino-button stockino-button-primary stockino-header-action" onClick={() => { setEditing(null); setFormError(''); setFormOpen(true); }}><Plus size={17} /> افزودن تأمین‌کننده</button></header>
      <section className="stockino-stats" aria-label="خلاصه تأمین‌کنندگان">
        <article className="stockino-stat stockino-stat-blue"><span className="stockino-stat-icon"><Users size={20} /></span><div><span>تأمین‌کنندگان فعال</span><strong>{(stats.data?.active_suppliers ?? 0).toLocaleString('fa-IR')}</strong></div></article>
        <article className="stockino-stat stockino-stat-red"><span className="stockino-stat-icon"><Archive size={20} /></span><div><span>بایگانی‌شده</span><strong>{(stats.data?.inactive_suppliers ?? 0).toLocaleString('fa-IR')}</strong></div></article>
        <article className="stockino-stat stockino-stat-amber"><span className="stockino-stat-icon"><PackageCheck size={20} /></span><div><span>رابطه‌های محصول</span><strong>{(stats.data?.linked_products ?? 0).toLocaleString('fa-IR')}</strong></div></article>
        <article className="stockino-stat stockino-stat-green"><span className="stockino-stat-icon"><Building2 size={20} /></span><div><span>محصولات دارای تأمین‌کننده</span><strong>{(stats.data?.products_with_suppliers ?? 0).toLocaleString('fa-IR')}</strong></div></article>
      </section>
      <section className="stockino-toolbar stockino-supplier-toolbar">
        <label className="stockino-search-field"><Search size={18} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="جستجو در نام، کد، تماس، ایمیل یا تلفن" aria-label="جستجوی تأمین‌کنندگان" /></label>
        <div className="stockino-supplier-filter-row"><label><span>وضعیت</span><select value={params.status} onChange={(event) => setParams((current) => ({ ...current, page: 1, status: event.target.value as SupplierParams['status'] }))}><option value="">همه تأمین‌کنندگان</option><option value="active">فعال</option><option value="inactive">بایگانی‌شده</option></select></label><button className="stockino-button stockino-button-primary stockino-mobile-add" onClick={() => { setEditing(null); setFormError(''); setFormOpen(true); }}><Plus size={17} /> افزودن تأمین‌کننده</button></div>
      </section>
      {suppliers.isError && <p className="stockino-page-error" role="alert">{suppliers.error.message}</p>}
      <div className="stockino-table-wrap" aria-busy={suppliers.isFetching}>
        <table className="stockino-table stockino-supplier-table"><thead><tr><th>تأمین‌کننده</th><th>کد</th><th>تماس</th><th>زمان تأمین</th><th>محصولات</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
          {suppliers.isFetching && !data && Array.from({ length: 5 }).map((_, index) => <tr className="stockino-loading-row" key={index}><td colSpan={7}><span /></td></tr>)}
          {data?.items.map((supplier) => <tr key={supplier.id}>
            <td data-label="تأمین‌کننده"><button className="stockino-supplier-name" onClick={() => setDetailId(supplier.id)}><strong>{supplier.name}</strong><small>به‌روزرسانی {new Date(supplier.updated_at).toLocaleDateString('fa-IR')}</small></button></td>
            <td data-label="کد"><code dir="ltr">{supplier.code || '—'}</code></td>
            <td data-label="تماس"><span className="stockino-contact-cell"><strong>{supplier.contact_name || '—'}</strong><small dir="ltr">{supplier.phone || supplier.email || '—'}</small></span></td>
            <td data-label="زمان تأمین">{supplier.lead_time_days === null ? '—' : `${supplier.lead_time_days.toLocaleString('fa-IR')} روز`}</td>
            <td data-label="محصولات"><button className="stockino-count-chip" onClick={() => setDetailId(supplier.id)}>{supplier.linked_product_count.toLocaleString('fa-IR')} محصول</button></td>
            <td data-label="وضعیت"><span className={`stockino-supplier-status is-${supplier.status}`}>{supplier.status === 'active' ? 'فعال' : 'بایگانی‌شده'}</span></td>
            <td data-label="عملیات"><div className="stockino-row-actions"><button className="stockino-text-action" onClick={() => openEdit(supplier)}><PencilLine size={15} /> ویرایش</button><button className={`stockino-text-action ${supplier.status === 'active' ? 'stockino-danger-action' : ''}`} disabled={statusMutation.isPending} onClick={() => statusMutation.mutate(supplier)}>{supplier.status === 'active' ? <Archive size={15} /> : <RotateCcw size={15} />}{supplier.status === 'active' ? 'بایگانی' : 'فعال‌سازی'}</button></div></td>
          </tr>)}
          {!suppliers.isFetching && data?.items.length === 0 && <tr><td className="stockino-no-results" colSpan={7}>تأمین‌کننده‌ای با این فیلتر پیدا نشد.</td></tr>}
        </tbody></table>
      </div>
      <nav className="stockino-pagination" aria-label="صفحه‌بندی تأمین‌کنندگان"><span>{(data?.pagination.total_items ?? 0).toLocaleString('fa-IR')} تأمین‌کننده</span><div><button disabled={params.page <= 1} onClick={() => setParams((current) => ({ ...current, page: current.page - 1 }))}>قبلی</button><span>صفحه {params.page.toLocaleString('fa-IR')} از {Math.max(1, data?.pagination.total_pages ?? 0).toLocaleString('fa-IR')}</span><button disabled={params.page >= (data?.pagination.total_pages ?? 0)} onClick={() => setParams((current) => ({ ...current, page: current.page + 1 }))}>بعدی</button></div><label><span>در هر صفحه</span><select value={params.per_page} onChange={(event) => setParams((current) => ({ ...current, page: 1, per_page: Number(event.target.value) as 20 | 50 | 100 }))}><option value={20}>۲۰</option><option value={50}>۵۰</option><option value={100}>۱۰۰</option></select></label></nav>
      <SupplierFormDialog supplier={editing} open={formOpen} pending={save.isPending} error={formError} onClose={() => { setFormOpen(false); setEditing(null); }} onSubmit={(input) => save.mutate(input)} />
      <SupplierDetailDrawer supplierId={detailId} onClose={() => setDetailId(null)} onEdit={(supplier) => { setEditing(supplier); setFormError(''); setFormOpen(true); }} onNotice={setNotice} />
      <div className={`stockino-toast ${notice ? 'is-visible' : ''}`} role="status" aria-live="polite">{notice}</div>
    </main>
  );
};

export default SuppliersPage;
