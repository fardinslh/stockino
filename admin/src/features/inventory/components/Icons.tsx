import React from 'react';
import { ArchiveX, Boxes, Download, History, PackageCheck, PencilLine, Search, TriangleAlert, X } from 'lucide-react';

export { ArchiveX, Boxes, Download, History, PackageCheck, PencilLine, Search, TriangleAlert, X };

interface IconButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  label: string;
}

export const IconButton: React.FC<IconButtonProps> = ({ label, children, className = '', ...props }) => (
  <button className={`stockino-icon-button ${className}`} aria-label={label} title={label} {...props}>{children}</button>
);
