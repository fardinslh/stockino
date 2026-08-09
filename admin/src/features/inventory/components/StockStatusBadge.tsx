import React from 'react';
import type { StockStatus } from '@/types/inventory';
import { t } from '@/lib/i18n';

const labels: Record<StockStatus, string> = { instock: t('موجود'), outofstock: t('ناموجود'), onbackorder: t('پیش‌فروش') };

export const StockStatusBadge: React.FC<{ status: StockStatus; low: boolean }> = ({ status, low }) => (
  <span className={`stockino-badge stockino-badge-${low && status === 'instock' ? 'low' : status}`}>{low && status === 'instock' ? t('کم‌موجود') : labels[status]}</span>
);

export default StockStatusBadge;
