import React, { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { inventoryApi } from '@/lib/api';
import { IconButton, X } from './Icons';
import { reasonLabels } from './AdjustmentDialogs';
import type { InventoryProduct } from '@/types/inventory';

interface Props { product: InventoryProduct | null; onClose: () => void }

export const StockHistoryDrawer: React.FC<Props> = ({ product, onClose }) => {
  const [page, setPage] = useState(1);
  useEffect(() => setPage(1), [product?.id]);
  const query = useQuery({ queryKey: ['stockMovements', product?.id, page], queryFn: () => inventoryApi.movements(product!.id, page), enabled: product !== null });
  if (!product) return null;
  return (
    <div className="stockino-drawer-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <aside className="stockino-drawer" role="dialog" aria-modal="true" aria-labelledby="stockino-history-title">
        <header><div><p>#{product.id.toLocaleString('en-US')}</p><h2 id="stockino-history-title">تاریخچه موجودی</h2><span>{product.parent_name ?? product.name}</span></div><IconButton label="بستن" onClick={onClose}><X size={20} /></IconButton></header>
        <div className="stockino-timeline">
          {query.isLoading && Array.from({ length: 4 }).map((_, index) => <div className="stockino-history-skeleton" key={index} />)}
          {query.isError && <p className="stockino-form-error">دریافت تاریخچه ممکن نشد.</p>}
          {query.data?.items.map((movement, index) => (
            <article className="stockino-movement" key={`${movement.created_at}-${index}`}>
              <span className={`stockino-movement-dot ${movement.quantity_delta >= 0 ? 'positive' : 'negative'}`} />
              <div className="stockino-movement-head"><strong className={movement.quantity_delta >= 0 ? 'positive' : 'negative'} dir="ltr">{movement.quantity_delta > 0 ? '+' : ''}{movement.quantity_delta}</strong><time dateTime={movement.created_at}>{new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(movement.created_at))}</time></div>
              <p>{movement.reason === 'external_change' ? 'تغییر خارج از استوکینو' : reasonLabels[movement.reason]}</p>
              <span className="stockino-stock-route" dir="ltr">{movement.quantity_before} → {movement.quantity_after}</span>
              <small>{movement.actor_name ? `توسط ${movement.actor_name}` : 'فرایند ووکامرس'}{movement.note ? ` · ${movement.note}` : ''}</small>
            </article>
          ))}
          {!query.isLoading && query.data?.items.length === 0 && <p className="stockino-no-history">هنوز تغییری در دفتر موجودی ثبت نشده است.</p>}
        </div>
        {(query.data?.total_pages ?? 0) > 1 && <footer className="stockino-history-pages"><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>قبلی</button><span>{page.toLocaleString('fa-IR')} / {query.data?.total_pages.toLocaleString('fa-IR')}</span><button disabled={page >= (query.data?.total_pages ?? 1)} onClick={() => setPage((value) => value + 1)}>بعدی</button></footer>}
      </aside>
    </div>
  );
};

export default StockHistoryDrawer;
