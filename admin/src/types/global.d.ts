interface StockinoSettings {
  root: string;
  nonce: string;
  locale: string;
  currency: string;
  page: 'inventory' | 'suppliers' | 'purchase-orders' | 'valuation';
  adminUrl: string;
}

interface Window {
  stockinoSettings: StockinoSettings;
  wp?: {
    i18n?: {
      __: (text: string, domain?: string) => string;
    };
  };
}
