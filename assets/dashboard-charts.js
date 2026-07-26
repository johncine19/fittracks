document.addEventListener('DOMContentLoaded', () => {
    const data = window.apexDashboardCharts;

    if (!data || typeof Chart === 'undefined') {
        return;
    }

    const computedStyle = getComputedStyle(document.documentElement);
    let lime = computedStyle.getPropertyValue('--lime').trim() || '#c7ff22';
    let muted = computedStyle.getPropertyValue('--muted').trim() || '#8792ad';

    Chart.defaults.color = muted;
    Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

    const gridColor = `color-mix(in srgb, ${muted} 12%, transparent)`;
    const limeSoft = `color-mix(in srgb, ${lime} 14%, transparent)`;

    const revenueCanvas = document.getElementById('revenueTrendChart');
    if (revenueCanvas) {
        const ctx = revenueCanvas.getContext('2d');
        const fill = ctx.createLinearGradient(0, 0, 0, revenueCanvas.clientHeight || 260);
        fill.addColorStop(0, `color-mix(in srgb, ${lime} 24%, transparent)`);
        fill.addColorStop(1, `color-mix(in srgb, ${lime} 2%, transparent)`);

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
                        ticks: { color: muted }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor, drawBorder: false },
                        ticks: {
                            color: muted,
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
                    hoverBackgroundColor: `color-mix(in srgb, ${lime} 80%, white)`,
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
                        ticks: { color: muted }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor, drawBorder: false },
                        ticks: {
                            color: muted,
                            precision: 0
                        }
                    }
                }
            }
        });
    }
});
