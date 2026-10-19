// Grafik tren harga (Chart.js). Dimuat hanya pada halaman yang memiliki grafik.
//
// Cara pasang:  npm install chart.js
// lalu tambahkan satu baris di resources/js/app.js:  import './charts';

const rupiah = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

async function renderTrendChart(canvas) {
    if (!canvas || !canvas.dataset.chart) {
        return;
    }

    let cfg;
    try {
        cfg = JSON.parse(canvas.dataset.chart);
    } catch (e) {
        return;
    }

    const { default: Chart } = await import('chart.js/auto');

    // Buat ulang bila grafik sudah ada (mis. setelah filter berubah).
    Chart.getChart(canvas)?.destroy();

    const datasets = [
        {
            label: 'Rata-rata',
            data: cfg.avg,
            borderColor: '#047857',
            backgroundColor: '#047857',
            borderWidth: 2.5,
            tension: 0.25,
            pointRadius: 3,
        },
    ];

    if (cfg.range) {
        for (const [label, data] of [['Terendah', cfg.min], ['Tertinggi', cfg.max]]) {
            datasets.push({
                label,
                data,
                borderColor: '#9ca3af',
                backgroundColor: '#9ca3af',
                borderDash: [5, 4],
                borderWidth: 1.5,
                pointRadius: 0,
                tension: 0.25,
            });
        }
    }

    new Chart(canvas, {
        type: 'line',
        data: { labels: cfg.labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${rupiah.format(ctx.parsed.y)}`,
                    },
                },
            },
            scales: {
                y: { ticks: { callback: (value) => rupiah.format(value) } },
            },
        },
    });
}

window.renderTrendChart = renderTrendChart;

// Portal publik: halaman biasa tanpa Livewire, grafik dibuat saat halaman siap.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('canvas[data-public-chart]').forEach(renderTrendChart);
});
