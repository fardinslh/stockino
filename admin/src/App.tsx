import React, { lazy, Suspense } from 'react';

const InventoryPage = lazy(() => import('./features/inventory/InventoryPage'));
const SuppliersPage = lazy(() => import('./features/suppliers/SuppliersPage'));

export const App: React.FC = () => <Suspense fallback={<div className="stockino-skeleton" aria-label="در حال بارگذاری" />}>{window.stockinoSettings.page === 'suppliers' ? <SuppliersPage /> : <InventoryPage />}</Suspense>;

export default App;
