(() => {
const ForecastAnalyticsChart = {
    groupDaily(rows, groupBy = 'day', order = 'asc') {
        const groups = new Map();
        rows.forEach(row => {
            const date = new Date(row.target_date + 'T00:00:00Z');
            if (!Number.isFinite(date.getTime()) || date.toISOString().slice(0, 10) !== row.target_date
                || row.actual_units == null || row.predicted_units == null
                || !Number.isFinite(Number(row.actual_units)) || !Number.isFinite(Number(row.predicted_units))) return;
            if (groupBy === 'week') date.setUTCDate(date.getUTCDate() - (date.getUTCDay() + 6) % 7);
            if (groupBy === 'month') date.setUTCDate(1);
            const key = date.toISOString().slice(0, 10);
            const point = groups.get(key) || {date: key, actual: 0, predicted: 0, count: 0};
            point.actual += Number(row.actual_units);
            point.predicted += Number(row.predicted_units);
            point.count++;
            groups.set(key, point);
        });
        return Array.from(groups.values()).sort((a, b) => order === 'desc' ? b.date.localeCompare(a.date) : a.date.localeCompare(b.date));
    },
    dateLabel(date, groupBy = 'day') {
        return new Intl.DateTimeFormat('en-GB', {
            timeZone: 'UTC', month: 'short', ...(groupBy === 'month' ? {year: 'numeric'} : {day: 'numeric'}),
        }).format(new Date(date + 'T00:00:00Z'));
    },
    tickIndices(length, width, labelWidth) {
        if (!length) return [];
        const count = Math.min(length, Math.max(1, Math.floor(width / (labelWidth + 20))));
        if (count === 1) return [0];
        return Array.from({length: count}, (_, i) => Math.round(i * (length - 1) / (count - 1)));
    },
};
if (typeof module !== 'undefined') module.exports = ForecastAnalyticsChart;
if (typeof window !== 'undefined') window.ForecastAnalyticsChart = ForecastAnalyticsChart;
})();
