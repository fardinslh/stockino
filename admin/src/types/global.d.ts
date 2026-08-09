interface StockinoSettings {
  root: string;
  nonce: string;
  locale: string;
  currency: string;
}

interface Window {
  stockinoSettings: StockinoSettings;
  wp?: {
    i18n?: {
      __: (text: string, domain?: string) => string;
    };
  };
}

