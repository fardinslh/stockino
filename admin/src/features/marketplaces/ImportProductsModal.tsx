import React, { useState } from 'react';
import { Upload, X, Loader2, CheckCircle2, AlertTriangle, FileText, Check } from 'lucide-react';
import { importApi } from '@/lib/api';
import type { ImportProcessResult } from '@/types/marketplaces';

interface Props {
  onClose: () => void;
  onImported: () => void;
}

export const ImportProductsModal: React.FC<Props> = ({ onClose, onImported }) => {
  const [csvContent, setCsvContent] = useState('');
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<ImportProcessResult | null>(null);
  const [error, setError] = useState('');
  const [activeTab, setActiveTab] = useState<'input' | 'preview'>('input');

  const sampleCsv = `title,sku,price,stock,category,weight
گوشی موبایل هوشمند نمونه,STK-PHONE-01,15000000,10,دیجیتال,180
کیف چرمی مردانه دست‌دوز,STK-BAG-02,850000,25,پوشاک,450
ساعت مچی اسپرت دیجیتال,STK-WATCH-03,1200000,15,اکسسوری,90`;

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (event) => {
      setCsvContent((event.target?.result as string) || '');
      setResult(null);
      setError('');
    };
    reader.readAsText(file, 'UTF-8');
  };

  const handleValidate = async () => {
    if (!csvContent.trim()) {
      setError('لطفاً ابتدا محتوای CSV را وارد کنید.');
      return;
    }
    setLoading(true);
    setError('');
    try {
      const res = await importApi.processCsv(csvContent, false);
      setResult(res);
      setActiveTab('preview');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'خطا در ارزیابی فایل');
    } finally {
      setLoading(false);
    }
  };

  const handleImport = async () => {
    setLoading(true);
    setError('');
    try {
      const res = await importApi.processCsv(csvContent, true);
      setResult(res);
      onImported();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'خطا در ثبت محصولات');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="stockino-modal-overlay" role="dialog" aria-modal="true">
      <div className="stockino-dialog stockino-enter" style={{ maxWidth: '680px' }}>
        <div className="stockino-dialog-header">
          <div className="flex items-center gap-2">
            <Upload className="text-indigo-600" size={18} />
            <h2 className="text-base font-semibold">ورود و استانداردسازی محصولات (CSV)</h2>
          </div>
          <button className="stockino-icon-button" onClick={onClose} aria-label="بستن">
            <X size={18} />
          </button>
        </div>

        <div className="stockino-dialog-body space-y-4">
          {error && (
            <div className="p-3 bg-rose-50 border border-rose-200 text-rose-700 text-sm rounded flex items-center gap-2">
              <AlertTriangle size={16} />
              <span>{error}</span>
            </div>
          )}

          {/* Tab buttons */}
          <div className="flex border-b border-slate-200 text-xs">
            <button
              type="button"
              className={`pb-2 px-3 font-medium border-b-2 ${
                activeTab === 'input' ? 'border-indigo-600 text-indigo-700 font-semibold' : 'border-transparent text-slate-500'
              }`}
              onClick={() => setActiveTab('input')}
            >
              ورود داده‌ها
            </button>
            {result && (
              <button
                type="button"
                className={`pb-2 px-3 font-medium border-b-2 flex items-center gap-1 ${
                  activeTab === 'preview' ? 'border-indigo-600 text-indigo-700 font-semibold' : 'border-transparent text-slate-500'
                }`}
                onClick={() => setActiveTab('preview')}
              >
                پیش‌نمایش و ارزیابی
                <span className="bg-indigo-100 text-indigo-800 text-[10px] px-1.5 py-0.2 rounded-full">
                  {result.valid_count.toLocaleString('fa-IR')}
                </span>
              </button>
            )}
          </div>

          {activeTab === 'input' && (
            <div className="space-y-3">
              <div className="flex justify-between items-center text-xs text-slate-600">
                <span>انتخاب فایل یا درج مستقیم محتوای CSV:</span>
                <button
                  type="button"
                  className="text-indigo-600 hover:underline text-[11px]"
                  onClick={() => setCsvContent(sampleCsv)}
                >
                  درج نمونه آزمایشی
                </button>
              </div>

              <div className="flex items-center gap-2">
                <input
                  type="file"
                  accept=".csv"
                  className="stockino-input text-xs flex-1"
                  onChange={handleFileUpload}
                />
              </div>

              <div>
                <textarea
                  className="stockino-input font-mono text-xs w-full h-44 p-2.5"
                  placeholder="title,sku,price,stock,category,weight..."
                  value={csvContent}
                  onChange={(e) => setCsvContent(e.target.value)}
                  dir="ltr"
                />
              </div>
            </div>
          )}

          {activeTab === 'preview' && result && (
            <div className="space-y-3">
              <div className="grid grid-cols-3 gap-2 text-center text-xs">
                <div className="bg-slate-50 p-2.5 rounded border border-slate-100">
                  <div className="text-slate-500">کل ردیف‌ها</div>
                  <div className="font-bold text-slate-800 text-sm mt-0.5">{result.total_rows.toLocaleString('fa-IR')}</div>
                </div>
                <div className="bg-emerald-50 p-2.5 rounded border border-emerald-100">
                  <div className="text-emerald-700">ردیف‌های معتبر</div>
                  <div className="font-bold text-emerald-800 text-sm mt-0.5">{result.valid_count.toLocaleString('fa-IR')}</div>
                </div>
                <div className={`p-2.5 rounded border ${result.failed_count > 0 ? 'bg-rose-50 border-rose-100 text-rose-800' : 'bg-slate-50 border-slate-100 text-slate-500'}`}>
                  <div>خطاها</div>
                  <div className="font-bold text-sm mt-0.5">{result.failed_count.toLocaleString('fa-IR')}</div>
                </div>
              </div>

              {result.errors.length > 0 && (
                <div className="bg-rose-50/70 border border-rose-200 rounded p-2.5 max-h-36 overflow-y-auto text-xs text-rose-800 space-y-1">
                  <div className="font-semibold mb-1 flex items-center gap-1">
                    <AlertTriangle size={13} />
                    خطاهای اعتبارسنجی ردیف‌ها:
                  </div>
                  {result.errors.map((err, i) => (
                    <div key={i} className="text-[11px]">
                      ردیف {err.row.toLocaleString('fa-IR')}: {err.error}
                    </div>
                  ))}
                </div>
              )}

              {result.products.length > 0 && (
                <div className="border border-slate-200 rounded overflow-hidden max-h-48 overflow-y-auto">
                  <table className="w-full text-right text-xs">
                    <thead className="bg-slate-50 border-b border-slate-200 text-slate-600">
                      <tr>
                        <th className="p-2">عنوان محصول</th>
                        <th className="p-2">SKU</th>
                        <th className="p-2">قیمت</th>
                        <th className="p-2">موجودی</th>
                      </tr>
                    </thead>
                    <tbody>
                      {result.products.slice(0, 10).map((p, i) => {
                        const priceObj = (p.price as Record<string, unknown>) || {};
                        const invObj = (p.inventory as Record<string, unknown>) || {};
                        return (
                          <tr key={i} className="border-b border-slate-100">
                            <td className="p-2 font-medium text-slate-800 truncate max-w-xs">{String(p.title || '')}</td>
                            <td className="p-2 font-mono text-slate-500">{String(p.sku || '-')}</td>
                            <td className="p-2 whitespace-nowrap">{Number(priceObj.regular_price || 0).toLocaleString('fa-IR')} تومان</td>
                            <td className="p-2 font-semibold">{Number(invObj.quantity || 0).toLocaleString('fa-IR')}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}

          <div className="stockino-dialog-footer flex justify-between items-center pt-4 border-t border-slate-100">
            <button type="button" className="stockino-button stockino-button-secondary" onClick={onClose}>
              انصراف
            </button>

            <div className="flex gap-2">
              {activeTab === 'input' ? (
                <button
                  type="button"
                  className="stockino-button stockino-button-secondary flex items-center gap-1.5"
                  onClick={handleValidate}
                  disabled={loading || !csvContent.trim()}
                >
                  {loading ? <Loader2 size={15} className="animate-spin" /> : <FileText size={15} />}
                  ارزیابی و اعتبارسنجی
                </button>
              ) : (
                <button
                  type="button"
                  className="stockino-button stockino-button-primary flex items-center gap-1.5"
                  onClick={handleImport}
                  disabled={loading || !result || result.valid_count === 0}
                >
                  {loading ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />}
                  ثبت و واردسازی به انبار
                </button>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};
