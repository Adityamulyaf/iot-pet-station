import Chart from 'chart.js/auto';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('reservoirChart', (initial) => {
        let chart;
        return {
            empty: !initial.labels.length,
            init() {
                chart = new Chart(this.$refs.canvas, {
                    type: 'line',
                    data: {
                        labels: initial.labels,
                        datasets: [
                            { label: 'Pakan (g)', data: initial.food, yAxisID: 'food', borderColor: '#e0a526', backgroundColor: '#e0a52618', fill: true, pointRadius: initial.labels.length === 1 ? 4 : 0, pointHoverRadius: 4, borderWidth: 3, tension: .3, spanGaps: false },
                            { label: 'Air (% level)', data: initial.water, yAxisID: 'water', borderColor: '#4a9cc0', backgroundColor: '#4a9cc018', fill: true, pointRadius: initial.labels.length === 1 ? 4 : 0, pointHoverRadius: 4, borderWidth: 3, tension: .3, spanGaps: false }
                        ]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false, animation: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#4a2c18', padding: 12, cornerRadius: 8, displayColors: true } },
                        scales: {
                            x: { grid: { display: false }, ticks: { color: '#8a6a4f', maxTicksLimit: 6, maxRotation: 0, font: { size: 11 } }, border: { display: false } },
                            food: { position: 'left', beginAtZero: true, suggestedMax: 1000, ticks: { color: '#8a6a4f', maxTicksLimit: 5, font: { size: 11 } }, grid: { color: '#f3e6c2', drawTicks: false }, border: { display: false } },
                            water: { position: 'right', min: 0, max: 100, ticks: { color: '#8a6a4f', stepSize: 25, callback: value => value + '%', font: { size: 11 } }, grid: { drawOnChartArea: false, drawTicks: false }, border: { display: false } }
                        }
                    }
                });
            },
            update(data) {
                if (!chart) return;
                this.empty = !data.labels.length;
                chart.data.labels = data.labels;
                chart.data.datasets[0].data = data.food;
                chart.data.datasets[1].data = data.water;
                chart.data.datasets.forEach(dataset => dataset.pointRadius = data.labels.length === 1 ? 4 : 0);
                chart.update('none');
            },
            destroy() { chart?.destroy(); }
        };
    });
});
