import React from 'react';

interface Props { page: number; totalPages: number; totalItems: number; perPage: 20 | 50 | 100; onPage: (page: number) => void; onPerPage: (perPage: 20 | 50 | 100) => void }

export const InventoryPagination: React.FC<Props> = ({ page, totalPages, totalItems, perPage, onPage, onPerPage }) => (
  <nav className="stockino-pagination" aria-label="صفحه‌بندی موجودی">
    <span className="stockino-tabular">{totalItems.toLocaleString('fa-IR')} ردیف</span>
    <div><button disabled={page <= 1} onClick={() => onPage(page - 1)}>قبلی</button><span className="stockino-tabular">صفحه {page.toLocaleString('fa-IR')} از {Math.max(1, totalPages).toLocaleString('fa-IR')}</span><button disabled={page >= totalPages} onClick={() => onPage(page + 1)}>بعدی</button></div>
    <label><span>در هر صفحه</span><select value={perPage} onChange={(event) => onPerPage(Number(event.target.value) as 20 | 50 | 100)}><option value={20}>۲۰</option><option value={50}>۵۰</option><option value={100}>۱۰۰</option></select></label>
  </nav>
);

export default InventoryPagination;
