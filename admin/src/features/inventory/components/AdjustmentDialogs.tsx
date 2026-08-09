import React, { useEffect, useMemo, useState } from 'react';
import { TriangleAlert, X, IconButton } from './Icons';
import type { AdjustmentPayload, AdjustmentReason, InventoryProduct } from '@/types/inventory';
import { t } from '@/lib/i18n';

export const reasonLabels: Record<AdjustmentReason, string> = {
  manual_adjustment: t('اصلاح دستی'),
  damaged: t('کالای آسیب‌دیده'),
  correction: t('اصلاح شمارش'),
  found_stock: t('موجودی پیدا شده'),
  internal_use: t('مصرف داخلی'),
  other: t('سایر'),
};

interface AdjustmentDialogProps {
  product: InventoryProduct | null;
  pending: boolean;
  error: string;
  onClose: () => void;
  onSubmit: (payload: AdjustmentPayload) => void;
}

export const StockAdjustmentDialog: React.FC<AdjustmentDialogProps> = ({ product, pending, error, onClose, onSubmit }) => {
  const [mode, setMode] = useState<'delta' | 'set'>('delta');
  const [quantity, setQuantity] = useState('');
  const [reason, setReason] = useState<AdjustmentReason>('manual_adjustment');
  const [note, setNote] = useState('');
  useEffect(() => { setMode('delta'); setQuantity(''); setReason('manual_adjustment'); setNote(''); }, [product?.id]);
  const projected = useMemo(() => {
    if (!product || quantity.trim() === '' || Number.isNaN(Number(quantity))) return null;
    return mode === 'delta' ? (product.stock_quantity ?? 0) + Number(quantity) : Number(quantity);
  }, [mode, product, quantity]);
  if (!product) return null;

  const submit = (event: React.FormEvent): void => {
    event.preventDefault();
    onSubmit({ mode, quantity: Number(quantity), reason, note, ...(mode === 'set' && product.stock_quantity !== null ? { expected_current: product.stock_quantity } : {}) });
  };

  return (
    <div className="stockino-modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <section className="stockino-dialog" role="dialog" aria-modal="true" aria-labelledby="stockino-adjust-title">
        <header><div><p>#{product.id.toLocaleString('en-US')}</p><h2 id="stockino-adjust-title">تنظیم موجودی</h2><span>{product.parent_name ?? product.name}</span></div><IconButton label="بستن" onClick={onClose}><X size={20} /></IconButton></header>
        <form onSubmit={submit}>
          <div className="stockino-current-stock"><span>موجودی فعلی</span><strong className="stockino-tabular" dir="ltr">{product.stock_quantity ?? '—'}</strong></div>
          <fieldset className="stockino-segment"><legend>روش تغییر</legend><label className={mode === 'delta' ? 'is-active' : ''}><input type="radio" name="mode" value="delta" checked={mode === 'delta'} onChange={() => setMode('delta')} /> تغییر به میزان</label><label className={mode === 'set' ? 'is-active' : ''}><input type="radio" name="mode" value="set" checked={mode === 'set'} onChange={() => setMode('set')} /> ثبت مقدار جدید</label></fieldset>
          <label className="stockino-field"><span>{mode === 'delta' ? 'میزان تغییر (مثلاً +۵ یا -۳)' : 'موجودی جدید'}</span><input dir="ltr" type="number" step="any" required value={quantity} onChange={(event) => setQuantity(event.target.value)} autoFocus /></label>
          {projected !== null && <div className={`stockino-projection ${projected < 0 ? 'is-negative' : ''}`}><span>{product.stock_quantity ?? 0} ← {projected}</span>{projected < 0 && <strong><TriangleAlert size={16} /> نتیجه منفی است و فقط با پیش‌فروش مجاز ثبت می‌شود.</strong>}</div>}
          <label className="stockino-field"><span>دلیل تغییر</span><select value={reason} onChange={(event) => setReason(event.target.value as AdjustmentReason)}>{Object.entries(reasonLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
          <label className="stockino-field"><span>یادداشت اختیاری</span><textarea maxLength={1000} value={note} onChange={(event) => setNote(event.target.value)} rows={3} /></label>
          {error && <p className="stockino-form-error" role="alert">{error}</p>}
          <footer><button type="button" className="stockino-button stockino-button-secondary" onClick={onClose}>انصراف</button><button type="submit" className="stockino-button stockino-button-primary" disabled={pending || quantity.trim() === ''}>{pending ? 'در حال ثبت…' : 'ثبت تغییر'}</button></footer>
        </form>
      </section>
    </div>
  );
};

interface BulkDialogProps {
  count: number;
  open: boolean;
  pending: boolean;
  error: string;
  onClose: () => void;
  onSubmit: (payload: Omit<AdjustmentPayload, 'mode' | 'expected_current'>) => void;
}

export const BulkStockAdjustmentDialog: React.FC<BulkDialogProps> = ({ count, open, pending, error, onClose, onSubmit }) => {
  const [quantity, setQuantity] = useState('');
  const [reason, setReason] = useState<AdjustmentReason>('correction');
  const [note, setNote] = useState('');
  useEffect(() => { if (open) { setQuantity(''); setReason('correction'); setNote(''); } }, [open]);
  if (!open) return null;
  return (
    <div className="stockino-modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <section className="stockino-dialog stockino-dialog-compact" role="dialog" aria-modal="true" aria-labelledby="stockino-bulk-title">
        <header><div><p>{count.toLocaleString('fa-IR')} محصول</p><h2 id="stockino-bulk-title">تغییر گروهی موجودی</h2><span>یک مقدار به موجودی همه موارد انتخاب‌شده اضافه یا کم می‌شود.</span></div><IconButton label="بستن" onClick={onClose}><X size={20} /></IconButton></header>
        <form onSubmit={(event) => { event.preventDefault(); onSubmit({ quantity: Number(quantity), reason, note }); }}>
          <label className="stockino-field"><span>میزان تغییر</span><input dir="ltr" type="number" step="any" required value={quantity} onChange={(event) => setQuantity(event.target.value)} placeholder="+5 / -2" autoFocus /></label>
          <label className="stockino-field"><span>دلیل تغییر</span><select value={reason} onChange={(event) => setReason(event.target.value as AdjustmentReason)}>{Object.entries(reasonLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
          <label className="stockino-field"><span>یادداشت اختیاری</span><textarea maxLength={1000} value={note} onChange={(event) => setNote(event.target.value)} rows={3} /></label>
          {error && <p className="stockino-form-error" role="alert">{error}</p>}
          <footer><button type="button" className="stockino-button stockino-button-secondary" onClick={onClose}>انصراف</button><button type="submit" className="stockino-button stockino-button-primary" disabled={pending || quantity.trim() === '' || Number(quantity) === 0}>{pending ? 'در حال اعمال…' : 'اعمال تغییر گروهی'}</button></footer>
        </form>
      </section>
    </div>
  );
};
