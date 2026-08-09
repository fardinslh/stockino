import React, { lazy, Suspense } from 'react';

const InventoryPage = lazy(() => import('./features/inventory/InventoryPage'));

export const App: React.FC = () => <Suspense fallback={<div className="stockino-skeleton" aria-label="در حال بارگذاری" />}><InventoryPage /></Suspense>;

export default App;
