import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Boxes, ChevronLeft, CircleCheck, Clock3, PackagePlus, Search, Settings2, ShieldAlert, Truck, X } from 'lucide-react';
import { reorderApi } from '@/lib/api';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import type { IncomingPurchaseOrder, ReorderCreationResult, ReorderParams, ReorderRow, ReorderSettings, ReorderState } from '@/types/reorder';

const stateLabels: Record<ReorderState, string> = {
  healthy: 'سالم',
  covered_by_incoming: 'پوشش با سفارش در راه',
  reorder_needed: 'نیازمند تأمین',
  threshold_unknown: 'آستانه نامشخص',
  no_supplier: 'بدون تأمین‌کننده',
  supplier_selection_required: 'نیازمند انتخاب تأمین‌کننده',
  attention_required: 'نیازمند بررسی',
};

const urgencyLabels = { critical: 'بحرانی', high: 'بالا', normal: 'عادی' } as const;
const decimal = (value: string | null): string => value === null ? '—' : Number(value).toLocaleString('fa-IR', { maximumFractionDigits: 6 });

const explanation = (row: ReorderRow): string => {
  if (row.state === 'attention_required') return `برای این مالک موجودی، دریافت حل‌نشده وجود دارد (${decimal(row.attention_incoming)} واحد). تا زمان تطبیق دستی، مقدار تأمین امن محاسبه نمی‌شود.`;
  if (row.state === 'threshold_unknown') return 'آستانه کمبود معتبر در ووکامرس یا تنظیمات Stockino تعریف نشده است.';
  if (row.state === 'healthy') return `موقعیت موجودی ${decimal(row.inventory_position)} از نقطه سفارش ${decimal(row.effective_reorder_point)} بیشتر است.`;
  if (row.state === 'covered_by_incoming') return `موجودی فعلی ${decimal(row.current_stock)} پایین است، اما ${decimal(row.confirmed_incoming)} واحد سفارش قطعی در راه، موقعیت موجودی را به ${decimal(row.inventory_position)} می‌رساند.`;
  const base = `موجودی فعلی ${decimal(row.current_stock)}، سفارش قطعی در راه ${decimal(row.confirmed_incoming)}، نقطه سفارش ${decimal(row.effective_reorder_point)} و هدف ${decimal(row.target_stock)} است؛ نیاز خام ${decimal(row.raw_reorder_quantity)} واحد.`;
  if (row.state === 'no_supplier') return `${base} هیچ رابطه فعال تأمین‌کننده‌ای یافت نشد.`;
  if (row.state === 'supplier_selection_required') return `${base} چند محصول/تنوع برای موجودی مشترک رقابت می‌کنند و انتخاب منبع خرید لازم است.`;
  const supplierRule = row.supplier ? ` پس از اعمال حداقل سفارش ${decimal(row.supplier.minimum_order_quantity)} و مضرب ${decimal(row.supplier.order_multiple)}، پیشنهاد نهایی ${decimal(row.recommended_quantity)} واحد است.` : '';
  return base + supplierRule;
};

const initialParams: ReorderParams = { page: 1, per_page: 20, search: '', state: '', supplier_id: '', category_id: '', sort: 'urgency' };

const ReorderPage: React.FC = () => {
  const client = useQueryClient();
  const [params, setParams] = useState<ReorderParams>(initialParams);
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<number[]>([]);
  const [detail, setDetail] = useState<ReorderRow | null>(null);
  const [settingsRow, setSettingsRow] = useState<ReorderRow | null>(null);
  const [notice, setNotice] = useState('');
  const [lastResult, setLastResult] = useState<ReorderCreationResult | null>(null);
  const debouncedSearch = useDebouncedValue(search, 350);

  useEffect(() => setParams((current) => ({ ...current, page: 1, search: debouncedSearch })), [debouncedSearch]);
  useEffect(() => { if (!notice) return; const timeout = window.setTimeout(() => setNotice(''), 5000); return () => window.clearTimeout(timeout); }, [notice]);
  const rows = useQuery({ queryKey: ['reorder', params], queryFn: () => reorderApi.list(params) });
  const stats = useQuery({ queryKey: ['reorder-stats'], queryFn: reorderApi.stats });
  const filters = useQuery({ queryKey: ['reorder-filters'], queryFn: reorderApi.filters });
  const actionable = useMemo(() => rows.data?.items.filter((row) => row.state === 'reorder_needed' && row.supplier !== null).map((row) => row.stock_owner_id) ?? [], [rows.data]);
  useEffect(() => setSelected((current) => current.filter((id) => actionable.includes(id))), [actionable]);

  const createOrders = useMutation({
    mutationFn: () => reorderApi.createPurchaseOrders(selected),
    onSuccess: async (result) => {
      const created = result.created.length;
      const skipped = result.skipped.length;
      setNotice(`${created.toLocaleString('fa-IR')} پیش‌نویس ساخته شد${skipped ? ` و ${skipped.toLocaleString('fa-IR')} مورد پس از ارزیابی مجدد رد شد.` : '.'}`);
      setLastResult(result);
      setSelected([]);
      await Promise.all([client.invalidateQueries({ queryKey: ['reorder'] }), client.invalidateQueries({ queryKey: ['reorder-stats'] }), client.invalidateQueries({ queryKey: ['purchase-orders'] })]);
    },
    onError: (error: Error) => { setLastResult(null); setNotice(error.message); },
  });

  const pagination = rows.data?.pagination;
  const allSelected = actionable.length > 0 && actionable.every((id) => selected.includes(id));
  const toggle = (id: number) => setSelected((current) => current.includes(id) ? current.filter((value) => value !== id) : current.length < 50 ? [...current, id] : current);

  return <main className="stockino-app stockino-reorder-app" dir="rtl">
    <header className="stockino-page-header stockino-reorder-header">
      <div><p>REPLENISHMENT CONTROL / PHASE 05</p><h1>پیشنهادهای تأمین</h1><span>تصمیم‌یار قطعی بر پایه موجودی ووکامرس، آستانه کمبود، سفارش‌های باز و قواعد خرید تأمین‌کننده</span></div>
      <div className="stockino-reorder-header-action"><small>انتخاب‌شده</small><strong>{selected.length.toLocaleString('fa-IR')}</strong><button className="stockino-button stockino-button-primary" disabled={!selected.length || createOrders.isPending} onClick={() => createOrders.mutate()}><PackagePlus size={18} />{createOrders.isPending ? 'در حال ارزیابی…' : 'ساخت پیش‌نویس خرید'}</button></div>
    </header>

    <section className="stockino-stats stockino-reorder-stats">
      <Stat icon={<PackagePlus size={20} />} label="نیازمند تأمین" value={stats.data?.reorder_needed} tone="orange" />
      <Stat icon={<AlertTriangle size={20} />} label="بحرانی" value={stats.data?.critical} tone="red" />
      <Stat icon={<Truck size={20} />} label="پوشش با سفارش در راه" value={stats.data?.covered_by_incoming} tone="blue" />
      <Stat icon={<Boxes size={20} />} label="بدون تأمین‌کننده" value={stats.data?.no_supplier} tone="slate" />
      <Stat icon={<ShieldAlert size={20} />} label="نیازمند بررسی" value={stats.data?.attention_required} tone="purple" />
    </section>

    <section className="stockino-toolbar stockino-reorder-toolbar">
      <label className="stockino-search-field"><Search size={18} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="جستجو با نام، SKU یا شناسه مالک" aria-label="جستجوی پیشنهادهای تأمین" /></label>
      <div className="stockino-reorder-filters">
        <Filter label="وضعیت" value={params.state} onChange={(value) => setParams({ ...params, page: 1, state: value as ReorderParams['state'] })}><option value="">همه وضعیت‌ها</option>{Object.entries(stateLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</Filter>
        <Filter label="تأمین‌کننده" value={params.supplier_id} onChange={(value) => setParams({ ...params, page: 1, supplier_id: value ? Number(value) : '' })}><option value="">همه تأمین‌کنندگان</option>{filters.data?.suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</Filter>
        <Filter label="دسته" value={params.category_id} onChange={(value) => setParams({ ...params, page: 1, category_id: value ? Number(value) : '' })}><option value="">همه دسته‌ها</option>{filters.data?.categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</Filter>
        <Filter label="مرتب‌سازی" value={params.sort} onChange={(value) => setParams({ ...params, page: 1, sort: value as ReorderParams['sort'] })}><option value="urgency">فوریت</option><option value="name">نام</option><option value="stock">موجودی</option><option value="incoming">در راه</option><option value="position">موقعیت موجودی</option></Filter>
      </div>
    </section>

    {rows.isError && <p className="stockino-page-error" role="alert">{rows.error.message}</p>}
    <div className="stockino-reorder-selection"><label><input type="checkbox" checked={allSelected} disabled={!actionable.length} onChange={() => setSelected(allSelected ? selected.filter((id) => !actionable.includes(id)) : Array.from(new Set([...selected, ...actionable])).slice(0, 50))} /> انتخاب تمام پیشنهادهای آماده این صفحه</label><span>حداکثر ۵۰ ردیف؛ پیش‌نویس‌ها بر اساس تأمین‌کننده گروه‌بندی می‌شوند.</span></div>
    {lastResult && <section className="stockino-reorder-result" aria-label="نتیجه ساخت پیش‌نویس"><header><strong>نتیجه ارزیابی مجدد</strong><button className="stockino-icon-button" aria-label="بستن نتیجه" onClick={() => setLastResult(null)}><X size={17} /></button></header>{lastResult.created.length > 0 && <div className="stockino-reorder-result-links">{lastResult.created.map(({ purchase_order: order }) => <a key={order.id} href={`${window.stockinoSettings.adminUrl}?page=stockino-purchase-orders&po_id=${order.id}`}><PackagePlus size={15} /><span><b dir="ltr">{order.po_number}</b><small>{order.supplier_name} · پیش‌نویس قابل ویرایش</small></span></a>)}</div>}{lastResult.skipped.length > 0 && <ul>{lastResult.skipped.map((item) => <li key={item.stock_owner_id}><b dir="ltr">OWNER #{item.stock_owner_id}</b><span>{item.reason === 'already_replenishing' ? 'پیش‌نویس تأمین فعال دارد' : item.reason === 'not_found' ? 'مالک موجودی دیگر وجود ندارد' : item.reason === 'purchase_order_failed' ? 'ساخت سفارش خرید ناموفق بود' : 'پس از محاسبه مجدد، آماده تأمین نبود'}{item.detail ? ` (${item.detail})` : ''}</span></li>)}</ul>}</section>}
    <div className="stockino-table-wrap"><table className="stockino-table stockino-reorder-table"><thead><tr><th aria-label="انتخاب" /><th>محصول / مالک موجودی</th><th>موجودی</th><th>نقطه سفارش</th><th>در راه</th><th>موقعیت</th><th>هدف / پیشنهاد</th><th>تأمین‌کننده</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
      {rows.isLoading && Array.from({ length: 5 }).map((_, index) => <tr className="stockino-loading-row" key={index}><td colSpan={10}><span /></td></tr>)}
      {!rows.isLoading && rows.data?.items.map((row) => {
        const canSelect = row.state === 'reorder_needed' && row.supplier !== null;
        return <tr key={row.stock_owner_id} className={`is-reorder-${row.state}`}>
          <td data-label="انتخاب"><input type="checkbox" aria-label={`انتخاب ${row.product_name}`} disabled={!canSelect} checked={selected.includes(row.stock_owner_id)} onChange={() => toggle(row.stock_owner_id)} /></td>
          <td data-label="محصول"><strong>{row.product_name}</strong><small dir="ltr">{row.sku || 'No SKU'} · OWNER #{row.stock_owner_id}</small></td>
          <td data-label="موجودی" dir="ltr" className="stockino-tabular">{decimal(row.current_stock)}</td>
          <td data-label="نقطه سفارش" dir="ltr" className="stockino-tabular">{decimal(row.effective_reorder_point)}</td>
          <td data-label="در راه" dir="ltr" className="stockino-tabular">{decimal(row.confirmed_incoming)}{Number(row.attention_incoming) > 0 && <small className="stockino-attention-number">+ {decimal(row.attention_incoming)} ?</small>}</td>
          <td data-label="موقعیت" dir="ltr" className="stockino-tabular"><strong>{decimal(row.inventory_position)}</strong></td>
          <td data-label="هدف / پیشنهاد" className="stockino-reorder-quantity"><span dir="ltr">هدف {decimal(row.target_stock)}</span><strong dir="ltr">{row.recommended_quantity === null ? '—' : `${decimal(row.recommended_quantity)} واحد`}</strong></td>
          <td data-label="تأمین‌کننده">{row.supplier ? <><strong>{row.supplier.supplier_name}</strong><small>{row.supplier.effective_lead_time_days === null ? 'زمان تحویل نامشخص' : `${row.supplier.effective_lead_time_days.toLocaleString('fa-IR')} روز`} · {row.supplier.source_product_name}</small><small dir="ltr">MOQ {decimal(row.supplier.minimum_order_quantity)} · MULTIPLE {decimal(row.supplier.order_multiple)}</small></> : <span>—</span>}</td>
          <td data-label="وضعیت"><span className={`stockino-reorder-state is-${row.state}`}>{row.urgency && <i>{urgencyLabels[row.urgency]}</i>}{stateLabels[row.state]}</span></td>
          <td data-label="عملیات"><div className="stockino-row-actions"><button className="stockino-text-action" onClick={() => setDetail(row)}>جزئیات <ChevronLeft size={14} /></button><button className="stockino-icon-button" aria-label={`تنظیمات ${row.product_name}`} onClick={() => setSettingsRow(row)}><Settings2 size={17} /></button></div></td>
        </tr>;
      })}
      {!rows.isLoading && !rows.data?.items.length && <tr><td className="stockino-no-results" colSpan={10}>پیشنهادی با این فیلترها پیدا نشد.</td></tr>}
    </tbody></table></div>

    <div className="stockino-pagination"><span>{(pagination?.total_items ?? 0).toLocaleString('fa-IR')} مالک موجودی</span><div><button disabled={params.page <= 1} onClick={() => setParams({ ...params, page: params.page - 1 })}>قبلی</button><span>صفحه {params.page.toLocaleString('fa-IR')} از {(pagination?.total_pages ?? 1).toLocaleString('fa-IR')}</span><button disabled={params.page >= (pagination?.total_pages ?? 1)} onClick={() => setParams({ ...params, page: params.page + 1 })}>بعدی</button></div><label>تعداد<select value={params.per_page} onChange={(event) => setParams({ ...params, page: 1, per_page: Number(event.target.value) as 20 | 50 | 100 })}><option>20</option><option>50</option><option>100</option></select></label></div>
    {detail && <ReorderDrawer row={detail} onClose={() => setDetail(null)} onSettings={() => { setSettingsRow(detail); setDetail(null); }} />}
    {settingsRow && <SettingsDialog row={settingsRow} onClose={() => setSettingsRow(null)} onSaved={async () => { setSettingsRow(null); setNotice('تنظیمات تأمین با موفقیت ذخیره شد.'); await Promise.all([client.invalidateQueries({ queryKey: ['reorder'] }), client.invalidateQueries({ queryKey: ['reorder-stats'] })]); }} />}
    <div className={`stockino-toast ${notice ? 'is-visible' : ''}`} role="status" aria-live="polite">{notice}</div>
  </main>;
};

const Stat: React.FC<{ icon: React.ReactNode; label: string; value?: number; tone: string }> = ({ icon, label, value, tone }) => <article className={`stockino-stat stockino-stat-${tone}`}><span className="stockino-stat-icon">{icon}</span><div><span>{label}</span><strong>{(value ?? 0).toLocaleString('fa-IR')}</strong></div></article>;

const Filter: React.FC<{ label: string; value: string | number; onChange: (value: string) => void; children: React.ReactNode }> = ({ label, value, onChange, children }) => <label><span>{label}</span><select value={value} onChange={(event) => onChange(event.target.value)}>{children}</select></label>;

const ReorderDrawer: React.FC<{ row: ReorderRow; onClose: () => void; onSettings: () => void }> = ({ row, onClose, onSettings }) => {
  const [page, setPage] = useState(1);
  const incoming = useQuery({ queryKey: ['reorder-incoming', row.stock_owner_id, page], queryFn: () => reorderApi.incoming(row.stock_owner_id, page) });
  return <div className="stockino-drawer-backdrop"><aside className="stockino-drawer stockino-reorder-drawer" role="dialog" aria-modal="true" aria-labelledby="stockino-reorder-detail"><header><div><p>OWNER #{row.stock_owner_id} / SUPPLY EXPLAINER</p><h2 id="stockino-reorder-detail">{row.product_name}</h2><span dir="ltr">{row.sku || 'No SKU'}</span></div><button className="stockino-icon-button" aria-label="بستن" onClick={onClose}><X size={20} /></button></header><div className="stockino-reorder-detail-body">
    <section className="stockino-reorder-equation"><div><small>CURRENT</small><strong>{decimal(row.current_stock)}</strong></div><i>+</i><div><small>INCOMING</small><strong>{decimal(row.confirmed_incoming)}</strong></div><i>=</i><div><small>POSITION</small><strong>{decimal(row.inventory_position)}</strong></div><i>→</i><div className="is-accent"><small>SUGGESTED</small><strong>{decimal(row.recommended_quantity)}</strong></div></section>
    <section className="stockino-reorder-explanation"><span className={`stockino-reorder-state is-${row.state}`}>{stateLabels[row.state]}</span><p>{explanation(row)}</p>{row.preferred_supplier_invalid && <div className="stockino-inline-warning"><AlertTriangle size={17} />انتخاب ترجیحی قبلی دیگر معتبر نیست؛ انتخاب امن جایگزین یا انتخاب دستی لازم است.</div>}<button className="stockino-button stockino-button-secondary" onClick={onSettings}><Settings2 size={17} /> تنظیم نقطه، هدف و تأمین‌کننده</button></section>
    <section className="stockino-incoming-list"><div className="stockino-section-head"><div><small>CONFIRMED OPEN SUPPLY</small><h3>سفارش‌های خرید در راه</h3></div><Truck size={20} /></div>{incoming.isLoading && <div className="stockino-skeleton" />}{!incoming.isLoading && incoming.data?.items.map((item) => <IncomingCard key={`${item.purchase_order_id}-${item.source_product_id}`} item={item} />)}{!incoming.isLoading && !incoming.data?.items.length && <p className="stockino-picker-state">سفارش خرید فعال و قطعی در راه نیست.</p>}<div className="stockino-mini-pagination"><button disabled={page <= 1} onClick={() => setPage(page - 1)}>قبلی</button><span>{page.toLocaleString('fa-IR')} / {Math.max(1, incoming.data?.pagination.total_pages ?? 1).toLocaleString('fa-IR')}</span><button disabled={page >= (incoming.data?.pagination.total_pages ?? 1)} onClick={() => setPage(page + 1)}>بعدی</button></div></section>
  </div></aside></div>;
};

const IncomingCard: React.FC<{ item: IncomingPurchaseOrder }> = ({ item }) => <article className="stockino-incoming-card"><header><a href={`${window.stockinoSettings.adminUrl}?page=stockino-purchase-orders&po_id=${item.purchase_order_id}`}><strong dir="ltr">{item.po_number}</strong></a><span>{item.status === 'ordered' ? 'سفارش‌گذاری‌شده' : 'دریافت جزئی'}</span></header><div><span>سفارش <b dir="ltr">{decimal(item.ordered_quantity)}</b></span><span>دریافت ثبت‌شده <b dir="ltr">{decimal(item.received_quantity)}</b>{Number(item.attention_quantity) > 0 && <small>شامل {decimal(item.attention_quantity)} مبهم</small>}</span><span>باقی‌مانده قطعی <b dir="ltr">{decimal(item.remaining_quantity)}</b></span></div><footer><Clock3 size={15} />{item.expected_date ? new Date(`${item.expected_date}T00:00:00`).toLocaleDateString('fa-IR') : 'تاریخ مورد انتظار نامشخص'}</footer></article>;

const SettingsDialog: React.FC<{ row: ReorderRow; onClose: () => void; onSaved: () => void }> = ({ row, onClose, onSaved }) => {
  const settings = useQuery({ queryKey: ['reorder-settings', row.stock_owner_id], queryFn: () => reorderApi.settings(row.stock_owner_id) });
  if (!settings.data) return <div className="stockino-modal-backdrop"><section className="stockino-dialog stockino-reorder-settings" role="dialog" aria-modal="true"><header><span /> <button className="stockino-icon-button" aria-label="بستن" onClick={onClose}><X size={20} /></button></header><div className="stockino-skeleton" />{settings.isError && <p role="alert">{settings.error.message}</p>}</section></div>;
  return <SettingsForm row={row} settings={settings.data} onClose={onClose} onSaved={onSaved} />;
};

const SettingsForm: React.FC<{ row: ReorderRow; settings: ReorderSettings; onClose: () => void; onSaved: () => void }> = ({ row, settings, onClose, onSaved }) => {
  const [point, setPoint] = useState(settings.custom_reorder_point ?? '');
  const [target, setTarget] = useState(settings.custom_target_stock ?? '');
  const [preferred, setPreferred] = useState(settings.preferred_supplier_id && settings.preferred_product_id ? `${settings.preferred_supplier_id}:${settings.preferred_product_id}` : '');
  const [error, setError] = useState('');
  const save = useMutation({ mutationFn: () => {
    const [supplier, product] = preferred ? preferred.split(':').map(Number) : [null, null];
    return reorderApi.updateSettings(row.stock_owner_id, { custom_reorder_point: point || null, custom_target_stock: target || null, preferred_supplier_id: supplier, preferred_product_id: product });
  }, onSuccess: onSaved, onError: (value: Error) => setError(value.message) });
  return <div className="stockino-modal-backdrop"><section className="stockino-dialog stockino-reorder-settings" role="dialog" aria-modal="true" aria-labelledby="stockino-reorder-settings"><header><div><p>OWNER #{row.stock_owner_id} / POLICY OVERRIDE</p><h2 id="stockino-reorder-settings">تنظیم سیاست تأمین</h2><span>{row.product_name}</span></div><button className="stockino-icon-button" aria-label="بستن" onClick={onClose}><X size={20} /></button></header><form onSubmit={(event) => { event.preventDefault(); save.mutate(); }}><div className="stockino-reorder-settings-grid"><label className="stockino-field"><span>نقطه سفارش سفارشی</span><input dir="ltr" inputMode="decimal" value={point} onChange={(event) => setPoint(event.target.value)} placeholder={`پیش‌فرض: ${decimal(row.woo_low_stock_amount)}`} /><small>خالی = آستانه کمبود ووکامرس</small></label><label className="stockino-field"><span>موجودی هدف سفارشی</span><input dir="ltr" inputMode="decimal" value={target} onChange={(event) => setTarget(event.target.value)} placeholder="فرمول پیش‌فرض" /><small>هدف باید از نقطه سفارش کمتر نباشد.</small></label></div><label className="stockino-field"><span>محصول و تأمین‌کننده ترجیحی</span><select value={preferred} onChange={(event) => setPreferred(event.target.value)}><option value="">انتخاب قطعی خودکار</option>{settings.candidates.map((candidate) => <option key={`${candidate.supplier_id}:${candidate.source_product_id}`} value={`${candidate.supplier_id}:${candidate.source_product_id}`}>{candidate.supplier_name} — {candidate.source_product_name}{candidate.effective_lead_time_days === null ? ' — زمان نامشخص' : ` — ${candidate.effective_lead_time_days} روز`}</option>)}</select><small>برای تنوع‌های مشترک، این انتخاب منبع خرید را نیز مشخص می‌کند.</small></label>{error && <p className="stockino-form-error" role="alert">{error}</p>}<footer><button type="button" className="stockino-button stockino-button-secondary" onClick={onClose}>انصراف</button><button type="submit" className="stockino-button stockino-button-primary" disabled={save.isPending}><CircleCheck size={17} />{save.isPending ? 'در حال ذخیره…' : 'ذخیره سیاست'}</button></footer></form></section></div>;
};

export default ReorderPage;
