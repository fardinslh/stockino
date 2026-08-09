import React from 'react';
import { Boxes } from 'lucide-react';

export const App: React.FC = () => (
  <main className="stockino-app" dir="rtl">
    <header className="stockino-shell-header">
      <div className="stockino-mark" aria-hidden="true"><Boxes size={22} /></div>
      <div>
        <p className="stockino-eyebrow">STOCKINO / INVENTORY OPS</p>
        <h1>مدیریت موجودی</h1>
        <p>کنترل موجودی و تاریخچه تغییرات محصولات ووکامرس</p>
      </div>
    </header>
    <section className="stockino-empty-panel">
      <span className="stockino-pulse" aria-hidden="true" />
      <div>
        <strong>زیرساخت استوکینو آماده است</strong>
        <p>داشبورد موجودی از API امن ووکامرس داده دریافت می‌کند.</p>
      </div>
    </section>
  </main>
);

export default App;

