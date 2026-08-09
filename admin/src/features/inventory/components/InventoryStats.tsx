import React from 'react';
import { ArchiveX, Boxes, PackageCheck, TriangleAlert } from './Icons';
import type { InventoryStatsData } from '@/types/inventory';
import { t } from '@/lib/i18n';

interface Props { data?: InventoryStatsData; loading: boolean }

export const InventoryStats: React.FC<Props> = ({ data, loading }) => {
  const cards = [
    { label: t('محصول دارای مدیریت موجودی'), value: data?.managed_products, icon: Boxes, tone: 'blue' },
    { label: t('محصول ناموجود'), value: data?.out_of_stock, icon: ArchiveX, tone: 'red' },
    { label: t('محصول کم‌موجودی'), value: data?.low_stock, icon: TriangleAlert, tone: 'amber' },
    { label: t('مجموع واحدهای موجود'), value: data?.total_units, icon: PackageCheck, tone: 'green' },
  ] as const;

  return (
    <section className="stockino-stats" aria-label="خلاصه موجودی">
      {cards.map(({ label, value, icon: Icon, tone }, index) => (
        <article className={`stockino-stat stockino-enter stockino-stat-${tone}`} style={{ animationDelay: `${index * 70}ms` }} key={label}>
          <span className="stockino-stat-icon"><Icon size={19} /></span>
          <div><span>{label}</span><strong className="stockino-tabular">{loading ? '—' : (value ?? 0).toLocaleString('fa-IR')}</strong></div>
        </article>
      ))}
    </section>
  );
};

export default InventoryStats;
