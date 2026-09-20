document.addEventListener('DOMContentLoaded', () => {
    const data = window.apexDashboardCharts;

    if (!data || typeof Chart === 'undefined') {
        return;
    }

    function getColors() {
        const isLight = document.documentElement.getAttribute('data-theme') === 'light';
        const computedStyle = getComputedStyle(document.documentElement);
        const lime = computedStyle.getPropertyValue('--lime').trim() || (isLight ? '#4d7c0f' : '#c7ff22');
        const textColor = isLight ? '#0f172a' : '#cbd5e1';
        const gridColor = isLight ? '#e2e8f0' : 'rgba(255, 255, 255, 0.08)';
        const borderColor = isLight ? '#94a3b8' : 'rgba(255, 255, 255, 0.20)';
        return { isLight, lime, textColor, gridColor, borderColor };
    }

    let colors = getColors();
    Chart.defaults.color = colors.textColor;
    Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
    Chart.defaults.font.weight = '600';

    let revChart = null;
    let chkChart = null;

    const revenueCanvas = document.getElementById('revenueTrendChart');
    if (revenueCanvas) {
        const ctx = revenueCanvas.getContext('2d');
        const fill = ctx.createLinearGradient(0, 0, 0, revenueCanvas.clientHeight || 260);
        fill.addColorStop(0, `color-mix(in srgb, ${colors.lime} 28%, transparent)`);
        fill.addColorStop(1, `color-mix(in srgb, ${colors.lime} 4%, transparent)`);

        revChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.revenue.labels,
                datasets: [{
                    label: 'Revenue',
                    data: data.revenue.values,
                    borderColor: colors.lime,
                    backgroundColor: fill,
                    borderWidth: 2.5,
                    fill: true,
                    pointRadius: 0,
                    pointHoverRadius: 5,
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
                        grid: { color: colors.gridColor, drawBorder: false },
                        border: { color: colors.borderColor },
                        ticks: { color: colors.textColor }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: colors.gridColor, drawBorder: false },
                        border: { color: colors.borderColor },
                        ticks: {
                            color: colors.textColor,
                            callback: (value) => `PHP ${Number(value).toLocaleString()}`
                        }
                    }
                }
            }
        });
    }

    const checkinsCanvas = document.getElementById('weeklyCheckinsChart');
    if (checkinsCanvas) {
        chkChart = new Chart(checkinsCanvas, {
            type: 'bar',
            data: {
                labels: data.checkins.labels,
                datasets: [{
                    label: 'Check-ins',
                    data: data.checkins.values,
                    backgroundColor: colors.lime,
                    hoverBackgroundColor: `color-mix(in srgb, ${colors.lime} 80%, white)`,
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
                        border: { color: colors.borderColor },
                        ticks: { color: colors.textColor }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: colors.gridColor, drawBorder: false },
                        border: { color: colors.borderColor },
                        ticks: {
                            color: colors.textColor,
                            precision: 0
                        }
                    }
                }
            }
        });
    }

    function updateDashboardCharts() {
        const c = getColors();
        Chart.defaults.color = c.textColor;

        if (revChart) {
            const ctx = revenueCanvas.getContext('2d');
            const fill = ctx.createLinearGradient(0, 0, 0, revenueCanvas.clientHeight || 260);
            fill.addColorStop(0, `color-mix(in srgb, ${c.lime} 28%, transparent)`);
            fill.addColorStop(1, `color-mix(in srgb, ${c.lime} 4%, transparent)`);

            revChart.data.datasets[0].borderColor = c.lime;
            revChart.data.datasets[0].backgroundColor = fill;
            if (revChart.options.scales.x) {
                revChart.options.scales.x.ticks.color = c.textColor;
                revChart.options.scales.x.grid.color = c.gridColor;
                if (!revChart.options.scales.x.border) revChart.options.scales.x.border = {};
                revChart.options.scales.x.border.color = c.borderColor;
            }
            if (revChart.options.scales.y) {
                revChart.options.scales.y.ticks.color = c.textColor;
                revChart.options.scales.y.grid.color = c.gridColor;
                if (!revChart.options.scales.y.border) revChart.options.scales.y.border = {};
                revChart.options.scales.y.border.color = c.borderColor;
            }
            revChart.update('none');
        }

        if (chkChart) {
            chkChart.data.datasets[0].backgroundColor = c.lime;
            chkChart.data.datasets[0].hoverBackgroundColor = `color-mix(in srgb, ${c.lime} 80%, white)`;
            if (chkChart.options.scales.x) {
                chkChart.options.scales.x.ticks.color = c.textColor;
                if (!chkChart.options.scales.x.border) chkChart.options.scales.x.border = {};
                chkChart.options.scales.x.border.color = c.borderColor;
            }
            if (chkChart.options.scales.y) {
                chkChart.options.scales.y.ticks.color = c.textColor;
                chkChart.options.scales.y.grid.color = c.gridColor;
                if (!chkChart.options.scales.y.border) chkChart.options.scales.y.border = {};
                chkChart.options.scales.y.border.color = c.borderColor;
            }
            chkChart.update('none');
        }
    }

    try {
        const obs = new MutationObserver((mutations) => {
            mutations.forEach((m) => {
                if (m.attributeName === 'data-theme') {
                    updateDashboardCharts();
                }
            });
        });
        obs.observe(document.documentElement, { attributes: true });
    } catch (e) {}

    const themeToggle = document.getElementById('theme-toggle-btn');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            setTimeout(updateDashboardCharts, 50);
        });
    }
});
