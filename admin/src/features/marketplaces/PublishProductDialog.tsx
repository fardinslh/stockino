import React, { useEffect, useState } from 'react';
import { AlertCircle, CheckCircle, Loader2, Send, X, SlidersHorizontal } from 'lucide-react';
import type { MarketplaceCategory, PublicationProduct, PublishPayload } from '@/types/marketplaces';
import { marketplaceApi, publicationApi } from '@/lib/api';

interface Props {
  product: PublicationProduct | null;
  selectedIds?: number[];
  marketplace?: string;
  onClose: () => void;
  onPublished: () => void;
}

export const PublishProductDialog: React.FC<Props> = ({
  product,
  selectedIds = [],
  marketplace = 'basalam',
  onClose,
  onPublished,
}) => {
  const isBatch = selectedIds.length > 0;
  const [categories, setCategories] = useState<MarketplaceCategory[]>([]);
  const [categoryId, setCategoryId] = useState('');
  const [prepDays, setPrepDays] = useState(1);
  const [title, setTitle] = useState('');
  const [price, setPrice] = useState<number>(0);
  const [loadingCats, setLoadingCats] = useState(false);
  const [publishing, setPublishing] = useState(false);
  const [error, setError] = useState('');

  // Selective sync options
  const [syncPrice, setSyncPrice] = useState(true);
  const [syncInventory, setSyncInventory] = useState(true);
  const [syncImages, setSyncImages] = useState(true);
  const [syncDescription, setSyncDescription] = useState(true);
  const [dryRun, setDryRun] = useState(false);
  const [previewNotice, setPreviewNotice] = useState('');

  useEffect(() => {
    let mounted = true;
    setLoadingCats(true);
    marketplaceApi
      .categories(marketplace)
      .then((res) => {
        if (mounted) {
          setCategories(res.categories || []);
          if (res.categories?.length) {
            setCategoryId(product?.category_external_id || res.categories[0].id);
          }
        }
      })
      .catch(() => {
        if (mounted) {
          setCategories([
            { id: '100', label: 'عمومی و متفرقه', parentId: null },
            { id: '101', label: 'کالای دیجیتال و لوازم جانبی', parentId: '100' },
          ]);
          setCategoryId('100');
        }
      })
      .finally(() => {
        if (mounted) setLoadingCats(false);
      });

    if (product) {
      setTitle(product.product_name);
      setPrice(parseFloat(product.price || product.regular_price || '0') || 0);
    }
    setError('');
    setPreviewNotice('');

    return () => {
      mounted = false;
    };
  }, [product, marketplace]);

  if (!product && !isBatch) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setPublishing(true);
    setError('');
    setPreviewNotice('');

    try {
      if (isBatch) {
        await publicationApi.publishBatch(selectedIds, marketplace);
      } else if (product) {
        const payload: PublishPayload = {
          product_id: product.product_id,
          marketplace,
          category_external_id: categoryId,
          preparation_days: prepDays,
          title: title.trim() || undefined,
          price: price > 0 ? price : undefined,
          sync_price: syncPrice,
          sync_inventory: syncInventory,
          sync_images: syncImages,
          sync_description: syncDescription,
          dry_run: dryRun,
        };
        const res = await publicationApi.publish(payload);
        if (dryRun) {
          setPreviewNotice(res.message);
          setPublishing(false);
          return;
        }
      }
      onPublished();
      onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'خطا در فرآیند انتشار محصول');
    } finally {
      setPublishing(false);
    }
  };

  return (
    <div className="stockino-modal-overlay" role="dialog" aria-modal="true">
      <div className="stockino-dialog stockino-enter" style={{ maxWidth: '520px' }}>
        <div className="stockino-dialog-header">
          <div className="flex items-center gap-2">
            <Send className="text-indigo-600" size={18} />
            <h2 className="text-base font-semibold">
              {isBatch
                ? `انتشار گروهی (${selectedIds.length.toLocaleString('fa-IR')} محصول)`
                : `انتشار در ${marketplace === 'basalam' ? 'باسلام' : 'بازارگاه'}`}
            </h2>
          </div>
          <button className="stockino-icon-button" onClick={onClose} aria-label="بستن">
            <X size={18} />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="stockino-dialog-body space-y-4">
          {error && (
            <div className="p-3 bg-rose-50 border border-rose-200 text-rose-700 text-sm rounded flex items-center gap-2">
              <AlertCircle size={16} />
              <span>{error}</span>
            </div>
          )}

          {previewNotice && (
            <div className="p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded flex items-center gap-2">
              <CheckCircle size={16} />
              <span>{previewNotice}</span>
            </div>
          )}

          {!isBatch && product && (
            <div>
              <label className="block text-xs font-medium text-slate-700 mb-1">عنوان محصول در بازارگاه</label>
              <input
                type="text"
                className="stockino-input w-full"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                required
              />
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium text-slate-700 mb-1">دسته‌بندی بازارگاه</label>
              {loadingCats ? (
                <div className="text-xs text-slate-500 py-2 flex items-center gap-1.5">
                  <Loader2 size={14} className="animate-spin" />
                  در حال بارگذاری...
                </div>
              ) : (
                <select
                  className="stockino-select w-full text-xs"
                  value={categoryId}
                  onChange={(e) => setCategoryId(e.target.value)}
                >
                  {categories.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.parentId ? `↳ ${c.label}` : c.label}
                    </option>
                  ))}
                </select>
              )}
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-700 mb-1">روزهای آماده‌سازی</label>
              <input
                type="number"
                min={1}
                max={30}
                className="stockino-input w-full text-xs"
                value={prepDays}
                onChange={(e) => setPrepDays(parseInt(e.target.value) || 1)}
              />
            </div>
          </div>

          {!isBatch && (
            <div>
              <label className="block text-xs font-medium text-slate-700 mb-1">قیمت فروش (تومان)</label>
              <input
                type="number"
                min={1000}
                step={1000}
                className="stockino-input w-full text-xs"
                value={price}
                onChange={(e) => setPrice(parseFloat(e.target.value) || 0)}
              />
            </div>
          )}

          {/* Selective field synchronization controls */}
          <div className="bg-slate-50 p-3 rounded border border-slate-200 text-xs space-y-2">
            <div className="font-semibold text-slate-800 flex items-center gap-1.5">
              <SlidersHorizontal size={14} className="text-indigo-600" />
              تنظیمات فیلدهای همگام‌سازی
            </div>
            <div className="grid grid-cols-2 gap-2 text-slate-700">
              <label className="flex items-center gap-1.5 cursor-pointer">
                <input
                  type="checkbox"
                  checked={syncPrice}
                  onChange={(e) => setSyncPrice(e.target.checked)}
                />
                قیمت محصول
              </label>
              <label className="flex items-center gap-1.5 cursor-pointer">
                <input
                  type="checkbox"
                  checked={syncInventory}
                  onChange={(e) => setSyncInventory(e.target.checked)}
                />
                موجودی انبار
              </label>
              <label className="flex items-center gap-1.5 cursor-pointer">
                <input
                  type="checkbox"
                  checked={syncDescription}
                  onChange={(e) => setSyncDescription(e.target.checked)}
                />
                توضیحات و خلاصه
              </label>
              <label className="flex items-center gap-1.5 cursor-pointer">
                <input
                  type="checkbox"
                  checked={syncImages}
                  onChange={(e) => setSyncImages(e.target.checked)}
                />
                تصاویر محصول
              </label>
            </div>

            <div className="pt-1 border-t border-slate-200">
              <label className="flex items-center gap-1.5 cursor-pointer text-indigo-700 font-medium">
                <input
                  type="checkbox"
                  checked={dryRun}
                  onChange={(e) => setDryRun(e.target.checked)}
                />
                حالت پیش‌نمایش آزمایشی (بدون ارسال نهایی به بازارگاه)
              </label>
            </div>
          </div>

          <div className="stockino-dialog-footer flex justify-end gap-2 pt-3 border-t border-slate-100">
            <button type="button" className="stockino-button stockino-button-secondary" onClick={onClose}>
              انصراف
            </button>
            <button
              type="submit"
              className="stockino-button stockino-button-primary flex items-center gap-1.5"
              disabled={publishing || loadingCats}
            >
              {publishing ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
              {dryRun ? 'ارزیابی و پیش‌نمایش' : isBatch ? 'انتشار همه موارد' : 'ارسال و انتشار محصول'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
