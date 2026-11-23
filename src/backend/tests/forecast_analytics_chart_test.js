const assert = require('node:assert/strict');
const chart = require('../../frontend/assets/js/forecast-analytics-chart.js');
const rows = [
    {target_date: '2016-02-28', actual_units: 2, predicted_units: 1.5},
    {target_date: '2016-02-29', actual_units: 0, predicted_units: 1},
    {target_date: '2016-03-01', actual_units: 4, predicted_units: 3.25},
    {target_date: '2016-03-02', actual_units: null, predicted_units: 8},
];
assert.deepEqual(chart.groupDaily(rows, 'week'), [
    {date:'2016-02-22',actual:2,predicted:1.5,count:1},
    {date:'2016-02-29',actual:4,predicted:4.25,count:2},
]);
assert.deepEqual(chart.groupDaily(rows, 'month', 'desc'), [
    {date:'2016-03-01',actual:4,predicted:3.25,count:1},
    {date:'2016-02-01',actual:2,predicted:2.5,count:2},
]);
assert.equal(chart.groupDaily([{target_date:'2016-02-30',actual_units:1,predicted_units:1}]).length,0);
assert.equal(chart.groupDaily(rows).reduce((sum,x)=>sum+x.actual,0),6,'Grouping preserves genuine zeros and excludes missing observations');
assert.equal(chart.dateLabel('2016-03-01'),'1 Mar');
assert.deepEqual(chart.tickIndices(84,280,70),[0,42,83],'Mobile labels stay sparse and preserve endpoints');
assert.deepEqual(chart.tickIndices(1,280,70),[0]);
console.log('Forecast Analytics chart grouping, ordering, dates and tick density passed.');
