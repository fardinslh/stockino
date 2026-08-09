interface StockinoSettings {
  root: string;
  nonce: string;
  locale: string;
  currency: string;
}

interface Window {
  stockinoSettings: StockinoSettings;
}

