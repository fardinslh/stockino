import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { CheckCircle2, AlertTriangle, AlertCircle, RefreshCw } from 'lucide-react';
import { publicationApi } from '@/lib/api';

interface Props {
  page: number;
  onPageChange: (page: number) => void;
}

export const PublicationLogsTable: React.FC<Props> = ({ page, onPageChange }) => {
  const logsQuery = useQuery({
    queryKey: ['publicationLogs', page],
    queryFn: () => publicationApi.logs(page),
  });

  const logs = logsQuery.data?.items ?? [];
  const totalPages = logsQuery.data?.total_pages ?? 1;

  const actionLabel = (action: string) => {
    switch (action) {
      case 'publish':
        return 'انتشار محصول';
      case 'update':
        return 'به‌روزرسانی محصول';
      case 'sync_stock':
        return 'همگام‌سازی موجودی';
      case 'test_connection':
        return 'تست اتصال';
      case 'save_connection':
        return 'ذخیره تنظیمات';
      default:
        return action;
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex justify-between items-center">
        <h3 className="text-sm font-semibold text-slate-800">گزارش‌ها و لاگ‌های عملیات بازارگاه</h3>
        <button
          className="stockino-button stockino-button-secondary text-xs flex items-center gap-1"
          onClick={() => logsQuery.refetch()}
          disabled={logsQuery.isFetching}
        >
          <RefreshCw size={14} className={logsQuery.isFetching ? 'animate-spin' : ''} />
          به‌روزرسانی
        </button>
      </div>

      <div className="stockino-card overflow-x-auto">
        <table className="stockino-table w-full text-right text-xs">
          <thead>
            <tr>
              <th className="py-2.5 px-3">زمان</th>
              <th className="py-2.5 px-3">بازارگاه</th>
              <th className="py-2.5 px-3">محصول</th>
              <th className="py-2.5 px-3">عملیات</th>
              <th className="py-2.5 px-3">وضعیت</th>
              <th className="py-2.5 px-3">پیام سیستم</th>
            </tr>
          </thead>
          <tbody>
            {logsQuery.isLoading ? (
              <tr>
                <td colSpan={6} className="text-center py-6 text-slate-400">
                  در حال بارگذاری گزارش‌ها...
                </td>
              </tr>
            ) : logs.length === 0 ? (
              <tr>
                <td colSpan={6} className="text-center py-6 text-slate-400">
                  هنوز گزارشی ثبت نشده است.
                </td>
              </tr>
            ) : (
              logs.map((log) => (
                <tr key={log.id} className="border-b border-slate-100 hover:bg-slate-50/50">
                  <td className="py-2 px-3 whitespace-nowrap text-slate-500">{log.created_at}</td>
                  <td className="py-2 px-3 font-medium text-slate-700">{log.connection_name || log.marketplace || '-'}</td>
                  <td className="py-2 px-3 font-medium text-slate-800">{log.product_name || (log.product_id ? `محصول #${log.product_id}` : '-')}</td>
                  <td className="py-2 px-3">
                    <span className="px-2 py-0.5 rounded bg-slate-100 text-slate-700 font-normal">
                      {actionLabel(log.action)}
                    </span>
                  </td>
                  <td className="py-2 px-3">
                    {log.status === 'success' ? (
                      <span className="inline-flex items-center gap-1 text-emerald-700 font-medium bg-emerald-50 px-2 py-0.5 rounded">
                        <CheckCircle2 size={13} />
                        موفق
                      </span>
                    ) : log.status === 'failure' ? (
                      <span className="inline-flex items-center gap-1 text-rose-700 font-medium bg-rose-50 px-2 py-0.5 rounded">
                        <AlertCircle size={13} />
                        خطا
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 text-amber-700 font-medium bg-amber-50 px-2 py-0.5 rounded">
                        <AlertTriangle size={13} />
                        هشدار
                      </span>
                    )}
                  </td>
                  <td className="py-2 px-3 text-slate-600 max-w-xs truncate" title={log.message}>
                    {log.message}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {totalPages > 1 && (
        <div className="flex justify-between items-center text-xs text-slate-500 pt-2">
          <span>صفحه {page.toLocaleString('fa-IR')} از {totalPages.toLocaleString('fa-IR')}</span>
          <div className="flex gap-1">
            <button
              className="stockino-button stockino-button-secondary text-xs px-3 py-1"
              disabled={page <= 1}
              onClick={() => onPageChange(page - 1)}
            >
              قبلی
            </button>
            <button
              className="stockino-button stockino-button-secondary text-xs px-3 py-1"
              disabled={page >= totalPages}
              onClick={() => onPageChange(page + 1)}
            >
              بعدی
            </button>
          </div>
        </div>
      )}
    </div>
  );
};
