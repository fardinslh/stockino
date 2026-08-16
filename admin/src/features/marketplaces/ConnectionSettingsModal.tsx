import React, { useEffect, useState } from 'react';
import { CheckCircle2, Loader2, X, AlertTriangle, ShieldCheck } from 'lucide-react';
import type { MarketplaceConnection, MarketplaceConnectionInput } from '@/types/marketplaces';
import { marketplaceApi } from '@/lib/api';

interface Props {
  connection: MarketplaceConnection | null;
  onClose: () => void;
  onSaved: () => void;
}

export const ConnectionSettingsModal: React.FC<Props> = ({ connection, onClose, onSaved }) => {
  const [token, setToken] = useState('');
  const [vendorId, setVendorId] = useState('');
  const [prepDays, setPrepDays] = useState(1);
  const [mockBehavior, setMockBehavior] = useState<'success' | 'failure' | 'timeout'>('success');
  const [testing, setTesting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [testResult, setTestResult] = useState<{ success: boolean; message: string } | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (connection) {
      setVendorId(connection.vendor_id ?? '');
      setPrepDays(connection.preparation_days ?? 1);
      setToken('');
      setTestResult(null);
      setError('');
      if (connection.credentials_meta?.behavior) {
        setMockBehavior(connection.credentials_meta.behavior as 'success' | 'failure' | 'timeout');
      }
    }
  }, [connection]);

  if (!connection) return null;

  const isMock = connection.marketplace === 'mock';

  const handleTest = async () => {
    setTesting(true);
    setTestResult(null);
    setError('');
    try {
      // If token entered, save first then test
      if (token.trim() || isMock) {
        const payload: MarketplaceConnectionInput & { marketplace: string } = {
          marketplace: connection.marketplace,
          preparation_days: prepDays,
          vendor_id: vendorId.trim() || undefined,
          credentials: isMock ? { behavior: mockBehavior } : { access_token: token.trim() },
        };
        await marketplaceApi.saveConnection(payload);
      }
      const res = await marketplaceApi.testConnection(connection.marketplace);
      setTestResult({ success: true, message: res.message });
    } catch (err) {
      setTestResult({
        success: false,
        message: err instanceof Error ? err.message : 'خطا در برقراری ارتباط با بازارگاه',
      });
    } finally {
      setTesting(false);
    }
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    try {
      const payload: MarketplaceConnectionInput & { marketplace: string } = {
        marketplace: connection.marketplace,
        preparation_days: prepDays,
        vendor_id: vendorId.trim() || undefined,
        credentials: isMock
          ? { behavior: mockBehavior }
          : token.trim()
          ? { access_token: token.trim() }
          : undefined,
      };
      await marketplaceApi.saveConnection(payload);
      onSaved();
      onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'خطا در ذخیره تنظیمات');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="stockino-modal-overlay" role="dialog" aria-modal="true">
      <div className="stockino-dialog stockino-enter" style={{ maxWidth: '520px' }}>
        <div className="stockino-dialog-header">
          <div className="flex items-center gap-2">
            <ShieldCheck className="text-emerald-600" size={20} />
            <h2 className="text-base font-semibold">تنظیمات اتصال {connection.name}</h2>
          </div>
          <button className="stockino-icon-button" onClick={onClose} aria-label="بستن">
            <X size={18} />
          </button>
        </div>

        <form onSubmit={handleSave} className="stockino-dialog-body space-y-4">
          {error && (
            <div className="p-3 bg-rose-50 border border-rose-200 text-rose-700 text-sm rounded flex items-center gap-2">
              <AlertTriangle size={16} />
              <span>{error}</span>
            </div>
          )}

          {testResult && (
            <div
              className={`p-3 rounded border text-sm flex items-start gap-2 ${
                testResult.success
                  ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                  : 'bg-rose-50 border-rose-200 text-rose-700'
              }`}
            >
              {testResult.success ? <CheckCircle2 size={16} className="mt-0.5" /> : <AlertTriangle size={16} className="mt-0.5" />}
              <span>{testResult.message}</span>
            </div>
          )}

          {isMock ? (
            <div>
              <label className="block text-xs font-medium text-slate-700 mb-1">رفتار بازارگاه آزمایشی</label>
              <select
                className="stockino-select w-full"
                value={mockBehavior}
                onChange={(e) => setMockBehavior(e.target.value as 'success' | 'failure' | 'timeout')}
              >
                <option value="success">همیشه موفق (Success)</option>
                <option value="failure">شبیه‌سازی خطا (503 Service Unavailable)</option>
                <option value="timeout">شبیه‌سازی تاخیر و تایم‌اوت (Timeout)</option>
              </select>
            </div>
          ) : (
            <>
              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1">
                  توکن دسترسی باسلام (PAT Token)
                  {connection.credentials_meta?.access_token_masked && (
                    <span className="text-slate-500 font-normal mr-2">
                      (تنظیم شده: {connection.credentials_meta.access_token_masked})
                    </span>
                  )}
                </label>
                <input
                  type="password"
                  className="stockino-input w-full"
                  placeholder={connection.credentials_meta?.access_token_masked ? 'برای تغییر، توکن جدید را وارد کنید' : 'توکن با دسترسی vendor.product وارد کنید'}
                  value={token}
                  onChange={(e) => setToken(e.target.value)}
                />
                <p className="text-xs text-slate-500 mt-1">
                  توکن دریافتی از پنل توسعه‌دهندگان باسلام با دسترسی‌های خواندن و نوشتن محصول.
                </p>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1">شناسه غرفه (اختیاری)</label>
                <input
                  type="text"
                  className="stockino-input w-full"
                  placeholder="در صورت خالی بودن، به طور خودکار شناسایی می‌شود"
                  value={vendorId}
                  onChange={(e) => setVendorId(e.target.value)}
                />
              </div>
            </>
          )}

          <div>
            <label className="block text-xs font-medium text-slate-700 mb-1">روزهای آماده‌سازی محصول (پیش‌فرض)</label>
            <input
              type="number"
              min={1}
              max={30}
              className="stockino-input w-full"
              value={prepDays}
              onChange={(e) => setPrepDays(parseInt(e.target.value) || 1)}
            />
          </div>

          <div className="stockino-dialog-footer flex justify-between items-center pt-4 border-t border-slate-100">
            <button
              type="button"
              className="stockino-button stockino-button-secondary flex items-center gap-1.5"
              onClick={handleTest}
              disabled={testing}
            >
              {testing ? <Loader2 size={16} className="animate-spin" /> : null}
              تست اتصال
            </button>

            <div className="flex items-center gap-2">
              <button type="button" className="stockino-button stockino-button-secondary" onClick={onClose}>
                انصراف
              </button>
              <button type="submit" className="stockino-button stockino-button-primary flex items-center gap-1.5" disabled={saving}>
                {saving ? <Loader2 size={16} className="animate-spin" /> : null}
                ذخیره تنظیمات
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  );
};
