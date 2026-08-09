import React, { Suspense } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import App from './App';
import './styles.css';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 30_000, retry: 1, refetchOnWindowFocus: false },
  },
});

const rootElement = document.getElementById('stockino-admin-root');

if (rootElement) {
  createRoot(rootElement).render(
    <React.StrictMode>
      <QueryClientProvider client={queryClient}>
        <Suspense fallback={<div className="stockino-skeleton" aria-label="در حال بارگذاری" />}>
          <App />
        </Suspense>
      </QueryClientProvider>
    </React.StrictMode>,
  );
}

