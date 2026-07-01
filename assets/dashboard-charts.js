document.addEventListener('DOMContentLoaded', () => {
    const data = window.apexDashboardCharts;

    if (!data || typeof Chart === 'undefined') {
        return;
    }

    Chart.defaults.color = '#9aa8c7';
    Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

    const gridColor = 'rgba(135, 146, 173, 0.12)';
    const lime = '#c7ff22';
    const limeSoft = 'rgba(199, 255, 34, 0.14)';

    const revenueCanvas = document.getElementById('revenueTrendChart');
    if (revenueCanvas) {
        const ctx = revenueCanvas.getContext('2d');
        const fill = ctx.createLinearGradient(0, 0, 0, revenueCanvas.clientHeight || 260);
        fill.addColorStop(0, 'rgba(199, 255, 34, 0.24)');
        fill.addColorStop(1, 'rgba(199, 255, 34, 0.02)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.revenue.labels,
                datasets: [{
                    label: 'Revenue',
                    data: data.revenue.values,
                    borderColor: lime,
                    backgroundColor: fill,
                    borderWidth: 2,
                    fill: true,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    tension: 0.35
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => `Revenue: PHP ${Number(context.raw || 0).toLocaleString()}`
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: gridColor, drawBorder: false },
                        ticks: { color: '#9aa8c7' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor, drawBorder: false },
                        ticks: {
                            color: '#9aa8c7',
                            callback: (value) => `PHP ${Number(value).toLocaleString()}`
                        }
                    }
                }
            }
        });
    }

    const checkinsCanvas = document.getElementById('weeklyCheckinsChart');
    if (checkinsCanvas) {
        new Chart(checkinsCanvas, {
            type: 'bar',
            data: {
                labels: data.checkins.labels,
                datasets: [{
                    label: 'Check-ins',
                    data: data.checkins.values,
                    backgroundColor: lime,
                    hoverBackgroundColor: '#dbff63',
                    borderRadius: 5,
                    barThickness: 18
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        grid: { display: false, drawBorder: false },
                        ticks: { color: '#9aa8c7' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor, drawBorder: false },
                        ticks: {
                            color: '#9aa8c7',
                            precision: 0
                        }
                    }
                }
            }
        });
    }
});
