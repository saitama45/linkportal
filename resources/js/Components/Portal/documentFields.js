/**
 * Header-field definitions shared by the template annotator, the staff
 * validation screen and the vendor document view.
 *
 * The standard keys are the ones promoted onto real columns of
 * portal_intake_documents — they drive PO matching, payables and reporting, so
 * they are always offered even when a template does not box them. Any other key
 * a template defines is a custom field: it is extracted, shown and saved into
 * the validated_fields JSON, but has no column of its own.
 */

export const STANDARD_FIELDS = [
    { key: 'invoice_no', label: 'Document No.', type: 'text', required: true },
    { key: 'document_date', label: 'Document Date', type: 'date', required: true },
    { key: 'due_date', label: 'Due Date', type: 'date', required: false },
    { key: 'po_number', label: 'PO Number', type: 'text', required: false },
    { key: 'vendor_address', label: 'Vendor Address', type: 'text', required: false },
    { key: 'subtotal', label: 'Subtotal', type: 'amount', required: false },
    { key: 'tax_amount', label: 'Tax', type: 'amount', required: false },
    { key: 'total_amount', label: 'Total', type: 'amount', required: true },
];

export const STANDARD_FIELD_KEYS = new Set(STANDARD_FIELDS.map((f) => f.key));

/** The HTML input type each annotation type maps to on the validation form. */
const INPUT_TYPES = { text: 'text', date: 'date', amount: 'number', qty: 'number' };
export const inputTypeFor = (type) => INPUT_TYPES[type] || 'text';

/**
 * Key for a field the user just named. Trailing punctuation must not leak into
 * the key — "Invoice No." has to become `invoice_no`, not `invoice_no_`, or it
 * silently stops matching anything keyed on the standard name. A label matching
 * a standard field reuses that field's key so it keeps its promoted column.
 */
export const keyForFieldLabel = (label) => {
    const text = (label || '').toString().trim();
    const standard = STANDARD_FIELDS.find((f) => f.label.toLowerCase() === text.toLowerCase());
    if (standard) return standard.key;

    return text.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
};

/** Display label: the template's own wins, else the standard name, else humanized. */
export const fieldLabel = (field) => field?.label
    || STANDARD_FIELDS.find((f) => f.key === field?.key)?.label
    || (field?.key || '').replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());

/**
 * The header fields to render for a document: everything its template defines,
 * in the template's own order and under the template's own labels, followed by
 * any standard field the template left out. Custom fields would otherwise be
 * invisible on screens that render a fixed list, and dropping the unused
 * standard ones would remove the columns downstream matching depends on.
 */
export function headerFieldsFor(templateFields) {
    const fromTemplate = (templateFields || [])
        .filter((f) => f?.key)
        .map((f) => ({
            key: f.key,
            label: fieldLabel(f),
            type: inputTypeFor(f.type),
            custom: !STANDARD_FIELD_KEYS.has(f.key),
        }));

    const covered = new Set(fromTemplate.map((f) => f.key));
    const remaining = STANDARD_FIELDS
        .filter((f) => !covered.has(f.key))
        .map((f) => ({ key: f.key, label: f.label, type: inputTypeFor(f.type), custom: false }));

    return [...fromTemplate, ...remaining];
}
