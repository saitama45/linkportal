/**
 * useInputRestriction.js
 * Composable for centralized input restrictions and formatting.
 */
export function useInputRestriction() {
    /**
     * Restrict input to numeric characters only (0-9).
     * Optionally allow decimals and positive only.
     */
    const restrictNumeric = (value, allowDecimal = true, allowNegative = false) => {
        if (value === null || value === undefined) return '';
        
        let val = value.toString();
        
        // Remove emojis and special characters first
        val = val.replace(/[\uD800-\uDBFF][\uDC00-\uDFFF]|\u200D/g, '');
        
        if (!allowNegative) {
            val = val.replace(/-/g, '');
        }
        
        if (allowDecimal) {
            // Remove everything except digits, minus sign (if allowed), and decimal point
            const regex = allowNegative ? /[^\d.-]/g : /[^\d.]/g;
            val = val.replace(regex, '');
            
            // Handle multiple decimal points
            const parts = val.split('.');
            if (parts.length > 2) {
                val = parts[0] + '.' + parts.slice(1).join('');
            }
        } else {
            // Remove everything except digits and minus sign (if allowed)
            const regex = allowNegative ? /[^\d-]/g : /\D/g;
            val = val.replace(regex, '');
        }
        
        return val;
    };

    /**
     * Restrict input to alphanumeric characters only (a-zA-Z0-9).
     */
    const restrictAlphanumeric = (value) => {
        if (!value) return '';
        return value.toString().replace(/[^a-zA-Z0-9]/g, '');
    };

    /**
     * Validate email format.
     */
    const isValidEmail = (email) => {
        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    };

    /**
     * Format a date string to YYYY-MM-DD for input[type="date"] compatibility.
     * Prevents the "one day off" issue caused by timezone shifts.
     */
    const formatDateForInput = (dateString) => {
        if (!dateString) return '';
        
        // If it's already a Date object, use its components directly to avoid UTC shift
        const date = dateString instanceof Date ? dateString : new Date(dateString);
        
        if (isNaN(date.getTime())) return '';
        
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        
        return `${year}-${month}-${day}`;
    };

    /**
     * Format date for display (e.g., Feb 18, 2026)
     */
    const formatDateDisplay = (dateString) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return 'N/A';
        
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    };

    /**
     * Restrict input to letters only (a-zA-Z).
     * Optionally force uppercase.
     */
    const restrictLetters = (value, forceUppercase = true) => {
        if (!value) return '';
        // Strip everything that is NOT a letter (a-z, A-Z) or a space
        let val = value.toString().replace(/[^a-zA-Z\s]/g, '');
        return forceUppercase ? val.toUpperCase() : val;
    };

    /**
     * Format contact number: 09XX XXX XXXX (13 chars max)
     */
    const formatContactNo = (value) => {
        if (!value) return '';
        // Strip non-digits
        let digits = value.toString().replace(/\D/g, '');
        // Limit to 11 digits
        digits = digits.substring(0, 11);
        
        let formatted = '';
        if (digits.length > 0) {
            formatted += digits.substring(0, 4);
            if (digits.length > 4) {
                formatted += ' ' + digits.substring(4, 7);
                if (digits.length > 7) {
                    formatted += ' ' + digits.substring(7, 11);
                }
            }
        }
        return formatted;
    };

    /**
     * Format TIN No: XXX-XXX-XXX-XXX (15 chars max)
     */
    const formatTinNo = (value) => {
        if (!value) return '';
        // Strip non-digits
        let digits = value.toString().replace(/\D/g, '');
        // Limit to 12 digits
        digits = digits.substring(0, 12);
        
        let formatted = '';
        if (digits.length > 0) {
            formatted += digits.substring(0, 3);
            if (digits.length > 3) {
                formatted += '-' + digits.substring(3, 6);
                if (digits.length > 6) {
                    formatted += '-' + digits.substring(6, 9);
                    if (digits.length > 9) {
                        formatted += '-' + digits.substring(9, 12);
                    }
                }
            }
        }
        return formatted;
    };

    const validateFileSize = (file, maxSizeMB = 50) => {
        if (!file) return true;
        const maxSizeBytes = maxSizeMB * 1024 * 1024;
        if (file.size > maxSizeBytes) {
            return {
                valid: false,
                message: `File size exceeds the ${maxSizeMB}MB limit.`
            };
        }
        return { valid: true };
    };

    /**
     * Format a numeric string with commas for better readability.
     * Optionally limit decimal places (precision).
     */
    const formatNumberWithCommas = (value, precision = 2) => {
        if (value === null || value === undefined || value === '') return '';
        
        // Strip everything except digits and one decimal point
        let val = value.toString().replace(/[^0-9.]/g, '');
        
        let parts = val.split('.');
        
        // Format integer part with commas
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        
        // Handle decimals if precision is specified
        if (parts.length > 1 && precision !== null) {
            parts[1] = parts[1].substring(0, precision);
        }
        
        // If it's a raw number without decimal but we want to show it as money
        // Note: For typing, we only join if dot exists.
        return parts.join('.');
    };

    /**
     * Strip commas from a formatted numeric string.
     */
    const stripCommas = (value) => {
        if (!value) return '';
        return value.toString().replace(/,/g, '');
    };

    return {
        restrictNumeric,
        restrictAlphanumeric,
        restrictLetters,
        isValidEmail,
        formatDateForInput,
        formatDateDisplay,
        formatContactNo,
        formatTinNo,
        validateFileSize,
        formatNumberWithCommas,
        stripCommas
    };
}
