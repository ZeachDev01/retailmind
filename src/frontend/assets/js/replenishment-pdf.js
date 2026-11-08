(() => {
    const triggerButtons = [
        document.getElementById('queue-download-pdf'),
        document.getElementById('btn-generate-po-pdf')
    ].filter(Boolean);
    if (!triggerButtons.length) return;

    const generatePdf = async () => {
        triggerButtons.forEach(btn => btn.disabled = true);
        try {
            await document.fonts.ready;
            const pdf = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4' });
            // Render with browser fonts so product names retain Unicode characters.
            const canvas = document.createElement('canvas');
            canvas.width = 1190;
            canvas.height = 1684;
            const context = canvas.getContext('2d');
            const columns = [40, 540, 710, 940];
            const widths = [480, 150, 210, 210];
            let y;
            let page = 0;
            const writePage = () => pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 0, 0, 595.28, 841.89);
            const startPage = () => {
                if (page++) pdf.addPage();
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.fillStyle = '#2563eb';
                context.fillRect(40, 24, 1110, 6);
                context.fillStyle = '#111827';
                context.font = 'bold 30px sans-serif';
                context.fillText('Purchase Order & Replenishment Worksheet', 40, 60);
                context.font = '20px sans-serif';
                context.fillText('Official stock reorder recommendations and purchase authorization. Quantities are in base units.', 40, 96);
                context.fillText(`Page ${page}`, 1040, 96);
                context.fillStyle = '#111827';
                context.fillRect(40, 116, 1110, 44);
                context.fillStyle = '#ffffff';
                context.font = 'bold 20px sans-serif';
                ['Product / SKU / Status', 'On hand', 'Suggested PO buy', '28-day sales'].forEach((text, i) => context.fillText(text, columns[i], 144));
                context.font = '20px sans-serif';
                y = 180;
            };
            const wrap = (text, width) => {
                const lines = [''];
                for (const character of text) {
                    const last = lines.length - 1;
                    if (character === '\n') lines.push('');
                    else if (context.measureText(lines[last] + character).width > width) lines.push(character);
                    else lines[last] += character;
                }
                return lines;
            };
            startPage();
            document.querySelectorAll('.queue-item').forEach(row => {
                const [priorityColor, priorityBackground] = ({
                    'Out of stock': ['#b91c1c', '#fef2f2'],
                    'Low stock': ['#92400e', '#fef3c7'],
                    'In stock': ['#166534', '#dcfce7'],
                })[row.dataset.stock] || ['#111827', '#f8fafc'];
                const expectedSales = row.dataset.expectedSales;
                const forecastPeriod = row.querySelector?.('.queue-forecast-period')?.textContent?.trim() || row.dataset.forecastPeriod;
                const values = [`${row.dataset.name}\n${row.dataset.sku}\n${row.dataset.stock}`, row.dataset.onHand, row.dataset.quantity,
                    expectedSales == null || expectedSales === '' ? 'Not available yet' : `${expectedSales}\n${forecastPeriod}`];
                const cells = values.map((text, i) => wrap(text, widths[i]));
                const lineCount = Math.max(...cells.map(lines => lines.length));
                for (let line = 0; line < lineCount; line++) {
                    if (y > 1590) { writePage(); startPage(); }
                    context.fillStyle = priorityBackground;
                    context.fillRect(40, y - 21, 490, 26);
                    cells.forEach((lines, i) => {
                        context.fillStyle = i === 0 ? priorityColor : '#111827';
                        context.fillText(lines[line] || '', columns[i], y);
                    });
                    y += 26;
                }
                context.strokeStyle = '#d1d5db';
                context.beginPath();
                context.moveTo(40, y);
                context.lineTo(1150, y);
                context.stroke();
                y += 30;
            });
            writePage();
            pdf.save('purchase-order-replenishment.pdf');
        } catch (error) {
            console.error('Purchase Order PDF download failed', error);
            if (window.RetailMindUI && window.RetailMindUI.toast) {
                window.RetailMindUI.toast('Could not download the PO PDF. Reload the page and try again.', 'error');
            } else {
                alert('Could not download the Purchase Order PDF. Reload the page and try again.');
            }
        } finally {
            triggerButtons.forEach(btn => btn.disabled = false);
        }
    };

    triggerButtons.forEach(btn => btn.addEventListener('click', generatePdf));
})();
