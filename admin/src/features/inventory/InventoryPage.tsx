import React, { useCallback, useEffect, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Boxes, Download } from 'lucide-react';
import { inventoryApi } from '@/lib/api';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import InventoryStats from './components/InventoryStats';
import InventoryToolbar from './components/InventoryToolbar';
import InventoryTable from './components/InventoryTable';
import InventoryPagination from './components/InventoryPagination';
import { BulkStockAdjustmentDialog, StockAdjustmentDialog } from './components/AdjustmentDialogs';
import StockHistoryDrawer from './components/StockHistoryDrawer';
import type { AdjustmentPayload, InventoryParams, InventoryProduct } from '@/types/inventory';
import { t } from '@/lib/i18n';

const initialParams: InventoryParams = { page: 1, per_page: 20, search: '', stock_status: '', type: '', category: '', low_stock: false, manage_stock: '' };

export const InventoryPage: React.FC = () => {
  const queryClient = useQueryClient();
  const [params, setParams] = useState<InventoryParams>(initialParams);
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebouncedValue(search);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [adjusting, setAdjusting] = useState<InventoryProduct | null>(null);
  const [history, setHistory] = useState<InventoryProduct | null>(null);
  const [bulkOpen, setBulkOpen] = useState(false);
  const [formError, setFormError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => setParams((value) => ({ ...value, page: 1, search: debouncedSearch })), [debouncedSearch]);
  useEffect(() => { if (!notice) return; const timeout = window.setTimeout(() => setNotice(''), 4500); return () => window.clearTimeout(timeout); }, [notice]);

  const inventoryQuery = useQuery({ queryKey: ['inventory', params], queryFn: () => inventoryApi.list(params), placeholderData: keepPreviousData });
  const statsQuery = useQuery({ queryKey: ['inventoryStats'], queryFn: inventoryApi.stats });
  const filtersQuery = useQuery({ queryKey: ['inventoryFilters'], queryFn: inventoryApi.filters, staleTime: 5 * 60_000 });
  const refresh = useCallback(async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['inventory'] }),
      queryClient.invalidateQueries({ queryKey: ['inventoryStats'] }),
      queryClient.invalidateQueries({ queryKey: ['stockMovements'] }),
    ]);
  }, [queryClient]);

  const adjustment = useMutation({
    mutationFn: ({ productId, payload }: { productId: number; payload: AdjustmentPayload }) => inventoryApi.adjust(productId, payload),
    onSuccess: async () => { setAdjusting(null); setFormError(''); setNotice('موجودی با موفقیت ثبت شد.'); await refresh(); },
    onError: (error: Error) => setFormError(error.message),
  });
  const bulkAdjustment = useMutation({
    mutationFn: (payload: Omit<AdjustmentPayload, 'mode' | 'expected_current'>) => inventoryApi.bulkAdjust(Array.from(selected), payload),
    onSuccess: async (result) => { setBulkOpen(false); setFormError(''); setSelected(new Set()); setNotice(result.failed.length ? `${result.updated.length.toLocaleString('fa-IR')} مورد ثبت شد و ${result.failed.length.toLocaleString('fa-IR')} مورد ناموفق بود.` : `${result.updated.length.toLocaleString('fa-IR')} موجودی با موفقیت تغییر کرد.`); await refresh(); },
    onError: (error: Error) => setFormError(error.message),
  });

  const products = inventoryQuery.data?.items ?? [];
  const setFilter = useCallback((patch: Partial<InventoryParams>) => setParams((value) => ({ ...value, ...patch, page: 1 })), []);
  const select = useCallback((id: number, checked: boolean) => setSelected((current) => { const next = new Set(current); checked ? next.add(id) : next.delete(id); return next; }), []);
  const selectPage = useCallback((checked: boolean) => setSelected((current) => { const next = new Set(current); products.forEach((product) => checked ? next.add(product.id) : next.delete(product.id)); return next; }), [products]);
  const openBulk = useCallback(() => { setFormError(''); if (selected.size > 100) { setNotice('حداکثر ۱۰۰ محصول را می‌توان هم‌زمان تغییر داد.'); return; } setBulkOpen(true); }, [selected.size]);
  const exportInventory = useCallback(async () => {
    try {
      const response = await fetch(inventoryApi.exportUrl(params), { credentials: 'same-origin', headers: { 'X-WP-Nonce': window.stockinoSettings.nonce } });
      if (!response.ok) throw new Error('خروجی CSV آماده نشد.');
      const url = URL.createObjectURL(await response.blob());
      const link = document.createElement('a'); link.href = url; link.download = `stockino-inventory-${new Date().toISOString().slice(0, 10)}.csv`; link.click(); URL.revokeObjectURL(url);
    } catch (error) { setNotice(error instanceof Error ? error.message : 'خروجی CSV آماده نشد.'); }
  }, [params]);

  return (
    <main className="stockino-app relative" dir="rtl">
      <header className="stockino-page-header stockino-enter"><div className="stockino-mark" aria-hidden="true"><Boxes size={22} /></div><div><p className="stockino-eyebrow">STOCKINO / INVENTORY OPS</p><h1>{t('مدیریت موجودی')}</h1><p>{t('کنترل موجودی و تاریخچه تغییرات محصولات ووکامرس')}</p></div><button className="stockino-button stockino-button-primary stockino-header-action" onClick={exportInventory}><Download size={17} /> {t('دریافت CSV')}</button></header>
      <InventoryStats data={statsQuery.data} loading={statsQuery.isLoading} />
      <InventoryToolbar params={params} searchValue={search} filters={filtersQuery.data} selectedCount={selected.size} onSearch={setSearch} onChange={setFilter} onBulk={openBulk} onExport={exportInventory} />
      {inventoryQuery.isError && <p className="stockino-page-error" role="alert">{inventoryQuery.error.message}</p>}
      <InventoryTable products={products} loading={inventoryQuery.isLoading} selected={selected} onSelect={select} onSelectPage={selectPage} onAdjust={(product) => { setFormError(''); setAdjusting(product); }} onHistory={setHistory} />
      <InventoryPagination page={params.page} perPage={params.per_page} totalItems={inventoryQuery.data?.total_items ?? 0} totalPages={inventoryQuery.data?.total_pages ?? 0} onPage={(page) => setParams((value) => ({ ...value, page }))} onPerPage={(per_page) => setParams((value) => ({ ...value, page: 1, per_page }))} />
      <StockAdjustmentDialog product={adjusting} pending={adjustment.isPending} error={formError} onClose={() => setAdjusting(null)} onSubmit={(payload) => adjusting && adjustment.mutate({ productId: adjusting.id, payload })} />
      <BulkStockAdjustmentDialog count={selected.size} open={bulkOpen} pending={bulkAdjustment.isPending} error={formError} onClose={() => setBulkOpen(false)} onSubmit={(payload) => bulkAdjustment.mutate(payload)} />
      <StockHistoryDrawer product={history} onClose={() => setHistory(null)} />
      <div className={`stockino-toast ${notice ? 'is-visible' : ''}`} aria-live="polite">{notice}</div>
    </main>
  );
};

export default InventoryPage;
