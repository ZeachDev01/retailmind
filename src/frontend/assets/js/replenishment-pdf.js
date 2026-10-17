(() => {
    const button = document.getElementById('queue-download-pdf');
    if (!button) return;
    button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            await document.fonts.ready;
            const pdf = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4' });
            // Render with browser fonts so product names retain Unicode characters.
            const canvas = document.createElement('canvas');
            canvas.width = 1190;
            canvas.height = 1684;
            const context = canvas.getContext('2d');
            const columns = [40, 240, 650, 790, 930];
            const widths = [180, 390, 120, 120, 200];
            let y;
            let page = 0;
            const writePage = () => pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 0, 0, 595.28, 841.89);
            const startPage = () => {
                if (page++) pdf.addPage();
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.fillStyle = '#111827';
                context.font = 'bold 30px sans-serif';
                context.fillText('Replenishment Recommendations', 40, 60);
                context.font = '20px sans-serif';
                context.fillText('Buying guidance only. Quantities are in base units.', 40, 96);
                context.fillText(`Page ${page}`, 1040, 96);
                context.font = 'bold 20px sans-serif';
                ['Priority', 'Product / SKU', 'On hand', 'Suggested', 'Why now'].forEach((text, i) => context.fillText(text, columns[i], 144));
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
                const values = [row.dataset.stock, `${row.dataset.name}\n${row.dataset.sku}`, row.dataset.onHand, row.dataset.quantity, row.querySelector('.queue-reason').firstChild.textContent.trim()];
                const cells = values.map((text, i) => wrap(text, widths[i]));
                const lineCount = Math.max(...cells.map(lines => lines.length));
                for (let line = 0; line < lineCount; line++) {
                    if (y > 1590) { writePage(); startPage(); }
                    cells.forEach((lines, i) => context.fillText(lines[line] || '', columns[i], y));
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
            pdf.save('replenishment-recommendations.pdf');
        } catch (error) {
            console.error('Replenishment PDF download failed', error);
            window.alert('Could not download the PDF. Reload the page and try again.');
        } finally {
            button.disabled = false;
        }
    });
})();
