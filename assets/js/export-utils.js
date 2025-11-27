/**
 * Export Utilities for School Management System
 * Common functions for exporting data to CSV, Excel, and PDF formats
 */

class ExportUtils {
    /**
     * Export table data to CSV format
     * @param {string} tableSelector - CSS selector for the table
     * @param {string} filename - Name of the file to download
     * @param {Array} excludeColumns - Array of column indices to exclude
     */
    static exportTableToCSV(tableSelector, filename, excludeColumns = []) {
        const table = document.querySelector(tableSelector);
        if (!table) {
            console.error('Table not found:', tableSelector);
            return;
        }

        let csv = [];
        const rows = table.querySelectorAll('tr');

        for (let i = 0; i < rows.length; i++) {
            const row = [];
            const cols = rows[i].querySelectorAll('td, th');

            for (let j = 0; j < cols.length; j++) {
                if (excludeColumns.includes(j)) continue;
                
                // Clean the text content
                let cellText = cols[j].innerText || cols[j].textContent || '';
                cellText = cellText.replace(/"/g, '""'); // Escape quotes
                cellText = cellText.replace(/\s+/g, ' ').trim(); // Clean whitespace
                row.push('"' + cellText + '"');
            }
            csv.push(row.join(','));
        }

        this.downloadCSV(csv.join('\n'), filename);
    }

    /**
     * Export array data to CSV format
     * @param {Array} data - Array of objects to export
     * @param {string} filename - Name of the file to download
     * @param {Array} headers - Array of header names
     */
    static exportArrayToCSV(data, filename, headers = null) {
        if (!data || data.length === 0) {
            alert('No data to export');
            return;
        }

        let csv = [];
        
        // Add headers
        if (headers) {
            csv.push(headers.map(h => '"' + h + '"').join(','));
        } else if (data.length > 0) {
            csv.push(Object.keys(data[0]).map(k => '"' + k + '"').join(','));
        }

        // Add data rows
        data.forEach(row => {
            const values = Object.values(row).map(value => {
                if (value === null || value === undefined) return '""';
                return '"' + String(value).replace(/"/g, '""') + '"';
            });
            csv.push(values.join(','));
        });

        this.downloadCSV(csv.join('\n'), filename);
    }

    /**
     * Download CSV content as file
     * @param {string} csvContent - CSV content string
     * @param {string} filename - Name of the file to download
     */
    static downloadCSV(csvContent, filename) {
        // Add UTF-8 BOM for proper Excel support
        const BOM = '\uFEFF';
        const blob = new Blob([BOM + csvContent], { type: 'text/csv;charset=utf-8;' });
        
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        
        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        URL.revokeObjectURL(url);
    }

    /**
     * Export current page content to PDF (using print)
     * @param {string} title - Title for the printed document
     * @param {string} contentSelector - CSS selector for content to print
     */
    static exportToPDF(title = 'Document', contentSelector = 'main') {
        const content = document.querySelector(contentSelector);
        if (!content) {
            console.error('Content not found:', contentSelector);
            return;
        }

        // Create a new window for printing
        const printWindow = window.open('', '_blank');
        
        // Get current styles
        const styles = Array.from(document.styleSheets)
            .map(styleSheet => {
                try {
                    return Array.from(styleSheet.cssRules)
                        .map(rule => rule.cssText)
                        .join('\n');
                } catch (e) {
                    return '';
                }
            })
            .join('\n');

        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>${title}</title>
                <style>
                    ${styles}
                    @media print {
                        body { margin: 0; padding: 20px; }
                        .no-print { display: none !important; }
                        .print-break { page-break-before: always; }
                    }
                </style>
            </head>
            <body>
                <h1>${title}</h1>
                ${content.innerHTML}
            </body>
            </html>
        `);

        printWindow.document.close();
        printWindow.focus();
        
        setTimeout(() => {
            printWindow.print();
            printWindow.close();
        }, 250);
    }

    /**
     * Show export options modal
     * @param {Object} options - Export options
     */
    static showExportModal(options = {}) {
        const {
            title = 'Export Data',
            csvCallback = null,
            pdfCallback = null,
            excelCallback = null
        } = options;

        // Remove existing modal if any
        const existingModal = document.getElementById('export-modal');
        if (existingModal) {
            existingModal.remove();
        }

        // Create modal HTML
        const modalHTML = `
            <div id="export-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
                <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white dark:bg-gray-800">
                    <div class="mt-3">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white text-center">${title}</h3>
                        <div class="mt-6 space-y-3">
                            ${csvCallback ? `
                                <button onclick="exportUtils_csvExport()" class="w-full bg-green-500 hover:bg-green-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-file-csv mr-2"></i>Export as CSV
                                </button>
                            ` : ''}
                            ${excelCallback ? `
                                <button onclick="exportUtils_excelExport()" class="w-full bg-blue-500 hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-file-excel mr-2"></i>Export as Excel
                                </button>
                            ` : ''}
                            ${pdfCallback ? `
                                <button onclick="exportUtils_pdfExport()" class="w-full bg-red-500 hover:bg-red-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-file-pdf mr-2"></i>Export as PDF
                                </button>
                            ` : ''}
                        </div>
                        <div class="mt-6">
                            <button onclick="ExportUtils.closeExportModal()" class="w-full bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Add modal to page
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Store callbacks globally for onclick handlers
        window.exportUtils_csvExport = () => {
            if (csvCallback) csvCallback();
            this.closeExportModal();
        };
        window.exportUtils_excelExport = () => {
            if (excelCallback) excelCallback();
            this.closeExportModal();
        };
        window.exportUtils_pdfExport = () => {
            if (pdfCallback) pdfCallback();
            this.closeExportModal();
        };
    }

    /**
     * Close export modal
     */
    static closeExportModal() {
        const modal = document.getElementById('export-modal');
        if (modal) {
            modal.remove();
        }
        // Clean up global callbacks
        delete window.exportUtils_csvExport;
        delete window.exportUtils_excelExport;
        delete window.exportUtils_pdfExport;
    }

    /**
     * Format date for filename
     * @param {Date} date - Date object
     * @returns {string} Formatted date string
     */
    static formatDateForFilename(date = new Date()) {
        return date.toISOString().split('T')[0];
    }

    /**
     * Generate filename with timestamp
     * @param {string} baseName - Base name for the file
     * @param {string} extension - File extension
     * @returns {string} Generated filename
     */
    static generateFilename(baseName, extension = 'csv') {
        const timestamp = this.formatDateForFilename();
        return `${baseName}_${timestamp}.${extension}`;
    }

    /**
     * Show success message
     * @param {string} message - Success message
     */
    static showSuccessMessage(message = 'Export completed successfully!') {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 transition-opacity duration-300';
        alertDiv.innerHTML = `<i class="fas fa-check-circle mr-2"></i>${message}`;
        
        document.body.appendChild(alertDiv);
        
        setTimeout(() => {
            alertDiv.style.opacity = '0';
            setTimeout(() => alertDiv.remove(), 300);
        }, 3000);
    }

    /**
     * Show error message
     * @param {string} message - Error message
     */
    static showErrorMessage(message = 'Export failed. Please try again.') {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'fixed top-4 right-4 bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 transition-opacity duration-300';
        alertDiv.innerHTML = `<i class="fas fa-exclamation-circle mr-2"></i>${message}`;
        
        document.body.appendChild(alertDiv);
        
        setTimeout(() => {
            alertDiv.style.opacity = '0';
            setTimeout(() => alertDiv.remove(), 300);
        }, 3000);
    }
}

// Make ExportUtils available globally
window.ExportUtils = ExportUtils;
