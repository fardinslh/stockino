import React, { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import type { SupplierDetail, SupplierInput } from '@/types/suppliers';

interface Props {
  supplier: SupplierDetail | null;
  open: boolean;
  pending: boolean;
  error: string;
  onClose: () => void;
  onSubmit: (input: SupplierInput) => void;
}

const empty: SupplierInput = { name: '', code: '', status: 'active', contact_name: '', phone: '', email: '', website: '', address: '', lead_time_days: '', notes: '' };

export const SupplierFormDialog: React.FC<Props> = ({ supplier, open, pending, error, onClose, onSubmit }) => {
  const [form, setForm] = useState<SupplierInput>(empty);
  useEffect(() => {
    setForm(supplier ? {
      name: supplier.name,
      code: supplier.code ?? '',
      status: supplier.status,
      contact_name: supplier.contact_name ?? '',
      phone: supplier.phone ?? '',
      email: supplier.email ?? '',
      website: supplier.website ?? '',
      address: supplier.address ?? '',
      lead_time_days: supplier.lead_time_days?.toString() ?? '',
      notes: supplier.notes ?? '',
    } : empty);
  }, [supplier, open]);
  if (!open) return null;
  const field = (key: keyof SupplierInput) => ({ value: form[key], onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => setForm((current) => ({ ...current, [key]: event.target.value })) });

  return (
    <div className="stockino-modal-backdrop" role="presentation">
      <section className="stockino-dialog stockino-supplier-dialog" role="dialog" aria-modal="true" aria-labelledby="stockino-supplier-form-title">
        <header><div><p>SUPPLIER PROFILE</p><h2 id="stockino-supplier-form-title">{supplier ? 'ویرایش تأمین‌کننده' : 'افزودن تأمین‌کننده'}</h2><span>اطلاعات تجاری و زمان تأمین پیش‌فرض را ثبت کنید.</span></div><button className="stockino-icon-button" type="button" aria-label="بستن" onClick={onClose}><X size={20} /></button></header>
        <form onSubmit={(event) => { event.preventDefault(); onSubmit(form); }}>
          <div className="stockino-form-grid">
            <label className="stockino-field"><span>نام تأمین‌کننده *</span><input required maxLength={190} autoFocus {...field('name')} /></label>
            <label className="stockino-field"><span>کد</span><input dir="ltr" maxLength={100} placeholder="SUP-001" {...field('code')} /></label>
            <label className="stockino-field"><span>وضعیت</span><select {...field('status')}><option value="active">فعال</option><option value="inactive">غیرفعال</option></select></label>
            <label className="stockino-field"><span>شخص تماس</span><input maxLength={190} {...field('contact_name')} /></label>
            <label className="stockino-field"><span>تلفن</span><input dir="ltr" maxLength={100} {...field('phone')} /></label>
            <label className="stockino-field"><span>ایمیل</span><input dir="ltr" type="email" maxLength={190} {...field('email')} /></label>
            <label className="stockino-field"><span>وب‌سایت</span><input dir="ltr" type="url" placeholder="https://" {...field('website')} /></label>
            <label className="stockino-field"><span>زمان تأمین پیش‌فرض (روز)</span><input dir="ltr" type="number" min="0" max="3650" {...field('lead_time_days')} /></label>
          </div>
          <label className="stockino-field"><span>نشانی</span><textarea rows={3} maxLength={4000} {...field('address')} /></label>
          <label className="stockino-field"><span>یادداشت</span><textarea rows={3} maxLength={10000} {...field('notes')} /></label>
          {error && <p className="stockino-form-error" role="alert">{error}</p>}
          <footer><button className="stockino-button stockino-button-secondary" type="button" onClick={onClose}>انصراف</button><button className="stockino-button stockino-button-primary" disabled={pending}>{pending ? 'در حال ذخیره…' : 'ذخیره تأمین‌کننده'}</button></footer>
        </form>
      </section>
    </div>
  );
};

export default SupplierFormDialog;
