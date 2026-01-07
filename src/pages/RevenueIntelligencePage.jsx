import React, { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import ReactECharts from 'echarts-for-react';
import { API } from '../utils/api';
import StatCard from '../components/StatCard';
import ChartWrapper from '../components/ChartWrapper';

/**
 * Revenue Intelligence Dashboard
 *
 * Provides comprehensive revenue analytics including:
 * - Revenue waterfall analysis
 * - Cohort revenue tracking
 * - Revenue forecasting
 * - Payment recovery metrics
 */
function RevenueIntelligencePage({ dateRange }) {
    // Fetch all revenue intelligence data
    const { data: revenueBreakdown, isLoading: breakdownLoading } = useQuery({
        queryKey: ['revenue-breakdown', dateRange],
        queryFn: () => API.getRevenueBreakdown(dateRange),
    });

    const { data: revenueConcentration, isLoading: concentrationLoading } = useQuery({
        queryKey: ['revenue-concentration', dateRange],
        queryFn: () => API.getRevenueConcentration(dateRange),
    });

    const { data: revenueByPaymentMethod, isLoading: paymentLoading } = useQuery({
        queryKey: ['revenue-by-payment', dateRange],
        queryFn: () => API.getRevenueByPaymentMethod(dateRange),
    });

    const { data: cohortRevenue, isLoading: cohortLoading } = useQuery({
        queryKey: ['cohort-revenue', dateRange],
        queryFn: () => API.getCohortRevenue(dateRange),
    });

    const { data: revenueProjections, isLoading: projectionsLoading } = useQuery({
        queryKey: ['revenue-projections', dateRange],
        queryFn: () => API.getRevenueProjections(dateRange),
    });

    const { data: failedPayments, isLoading: failedLoading } = useQuery({
        queryKey: ['failed-payments', dateRange],
        queryFn: () => API.getFailedPaymentRecovery(dateRange),
    });

    // Calculate derived metrics
    const metrics = useMemo(() => {
        if (!revenueBreakdown?.data) return null;

        const total = revenueBreakdown.data.reduce((sum, item) => sum + parseFloat(item.revenue || 0), 0);
        const recurring = revenueBreakdown.data.find(item => item.source === 'recurring')?.revenue || 0;
        const recurringPercentage = total > 0 ? (recurring / total * 100) : 0;

        // Calculate revenue velocity (daily growth rate)
        const velocity = revenueProjections?.data?.velocity || 0;

        return {
            total,
            recurringPercentage,
            velocity,
            health: velocity > 5 ? 'excellent' : velocity > 2 ? 'good' : velocity > 0 ? 'fair' : 'poor'
        };
    }, [revenueBreakdown, revenueProjections]);

    // Revenue Waterfall Chart
    const waterfallOption = useMemo(() => {
        if (!revenueBreakdown?.data) return {};

        const items = revenueBreakdown.data;
        const startingRevenue = items.find(i => i.source === 'starting')?.revenue || 0;
        const newRevenue = items.find(i => i.source === 'new')?.revenue || 0;
        const expansion = items.find(i => i.source === 'expansion')?.revenue || 0;
        const contraction = items.find(i => i.source === 'contraction')?.revenue || 0;
        const churn = items.find(i => i.source === 'churn')?.revenue || 0;

        return {
            title: {
                text: 'Revenue Waterfall',
                left: 'center',
                textStyle: { fontSize: 16, fontWeight: 600 }
            },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'shadow' },
                formatter: (params) => {
                    const item = params[0];
                    return `${item.name}<br/>$${item.value?.toLocaleString() || 0}`;
                }
            },
            grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
            xAxis: {
                type: 'category',
                data: ['Starting', 'New Sales', 'Expansion', 'Contraction', 'Churn', 'Ending'],
                splitLine: { show: false }
            },
            yAxis: {
                type: 'value',
                axisLabel: {
                    formatter: (value) => `$${(value / 1000).toFixed(0)}K`
                }
            },
            series: [{
                type: 'bar',
                data: [
                    { value: startingRevenue, itemStyle: { color: '#94a3b8' } },
                    { value: newRevenue, itemStyle: { color: '#10b981' } },
                    { value: expansion, itemStyle: { color: '#06b6d4' } },
                    { value: -Math.abs(contraction), itemStyle: { color: '#f59e0b' } },
                    { value: -Math.abs(churn), itemStyle: { color: '#ef4444' } },
                    {
                        value: startingRevenue + newRevenue + expansion - contraction - churn,
                        itemStyle: { color: '#0ea5e9' }
                    }
                ],
                barWidth: '50%'
            }]
        };
    }, [revenueBreakdown]);

    // Revenue Velocity Gauge
    const velocityOption = useMemo(() => {
        if (!metrics) return {};

        const velocity = metrics.velocity;
        const color = velocity > 5 ? '#10b981' : velocity > 2 ? '#06b6d4' : velocity > 0 ? '#f59e0b' : '#ef4444';

        return {
            title: {
                text: 'Revenue Velocity',
                subtext: 'Daily Growth Rate',
                left: 'center',
                textStyle: { fontSize: 16, fontWeight: 600 }
            },
            series: [{
                type: 'gauge',
                startAngle: 180,
                endAngle: 0,
                min: 0,
                max: 10,
                splitNumber: 5,
                axisLine: {
                    lineStyle: {
                        width: 30,
                        color: [
                            [0.2, '#ef4444'],
                            [0.4, '#f59e0b'],
                            [0.6, '#06b6d4'],
                            [1, '#10b981']
                        ]
                    }
                },
                pointer: {
                    itemStyle: { color: color }
                },
                axisTick: { show: false },
                splitLine: {
                    length: 30,
                    lineStyle: { width: 2, color: '#fff' }
                },
                axisLabel: {
                    distance: 40,
                    color: '#64748b',
                    fontSize: 12,
                    formatter: '{value}%'
                },
                detail: {
                    valueAnimation: true,
                    formatter: '{value}%',
                    color: color,
                    fontSize: 24,
                    fontWeight: 'bold',
                    offsetCenter: [0, '70%']
                },
                data: [{ value: velocity.toFixed(1) }]
            }]
        };
    }, [metrics]);

    // Revenue Source Breakdown (Pie Chart)
    const sourceBreakdownOption = useMemo(() => {
        if (!revenueBreakdown?.data) return {};

        const sources = revenueBreakdown.data.filter(item =>
            ['new', 'recurring', 'expansion'].includes(item.source)
        );

        return {
            title: {
                text: 'Revenue by Source',
                left: 'center',
                textStyle: { fontSize: 16, fontWeight: 600 }
            },
            tooltip: {
                trigger: 'item',
                formatter: '{b}: ${c} ({d}%)'
            },
            legend: {
                orient: 'vertical',
                left: 'left',
                top: 'middle'
            },
            series: [{
                type: 'pie',
                radius: ['40%', '70%'],
                avoidLabelOverlap: false,
                itemStyle: {
                    borderRadius: 10,
                    borderColor: '#fff',
                    borderWidth: 2
                },
                label: {
                    show: false,
                    position: 'center'
                },
                emphasis: {
                    label: {
                        show: true,
                        fontSize: 20,
                        fontWeight: 'bold'
                    }
                },
                labelLine: { show: false },
                data: sources.map(item => ({
                    name: item.source.charAt(0).toUpperCase() + item.source.slice(1),
                    value: parseFloat(item.revenue || 0)
                })),
                color: ['#10b981', '#0ea5e9', '#06b6d4']
            }]
        };
    }, [revenueBreakdown]);

    // Revenue Concentration (Pareto Chart)
    const concentrationOption = useMemo(() => {
        if (!revenueConcentration?.data) return {};

        const data = revenueConcentration.data;
        let cumulativeRevenue = 0;
        const cumulativePercentages = [];

        data.forEach(item => {
            cumulativeRevenue += parseFloat(item.revenue || 0);
            cumulativePercentages.push(item.cumulative_percentage);
        });

        return {
            title: {
                text: 'Revenue Concentration (80/20 Analysis)',
                left: 'center',
                textStyle: { fontSize: 16, fontWeight: 600 }
            },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'cross' }
            },
            legend: {
                data: ['Revenue', 'Cumulative %'],
                top: 30
            },
            grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
            xAxis: {
                type: 'category',
                data: data.map((_, idx) => `Top ${idx + 1}`),
                boundaryGap: true
            },
            yAxis: [
                {
                    type: 'value',
                    name: 'Revenue',
                    position: 'left',
                    axisLabel: {
                        formatter: (value) => `$${(value / 1000).toFixed(0)}K`
                    }
                },
                {
                    type: 'value',
                    name: 'Cumulative %',
                    position: 'right',
                    min: 0,
                    max: 100,
                    axisLabel: {
                        formatter: '{value}%'
                    }
                }
            ],
            series: [
                {
                    name: 'Revenue',
                    type: 'bar',
                    data: data.map(item => parseFloat(item.revenue || 0)),
                    itemStyle: {
                        color: {
                            type: 'linear',
                            x: 0, y: 0, x2: 0, y2: 1,
                            colorStops: [
                                { offset: 0, color: '#0ea5e9' },
                                { offset: 1, color: '#06b6d4' }
                            ]
                        }
                    }
                },
                {
                    name: 'Cumulative %',
                    type: 'line',
                    yAxisIndex: 1,
                    data: cumulativePercentages,
                    smooth: true,
                    itemStyle: { color: '#f59e0b' },
                    lineStyle: { width: 3 },
                    markLine: {
                        silent: true,
                        lineStyle: { type: 'dashed', color: '#ef4444' },
                        data: [{ yAxis: 80, label: { formatter: '80%' } }]
                    }
                }
            ]
        };
    }, [revenueConcentration]);

    // Payment Method Performance
    const paymentMethodOption = useMemo(() => {
        if (!revenueByPaymentMethod?.data) return {};

        const data = revenueByPaymentMethod.data;

        return {
            title: {
                text: 'Revenue by Payment Method',
                left: 'center',
                textStyle: { fontSize: 16, fontWeight: 600 }
            },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'shadow' }
            },
            legend: {
                data: ['Revenue', 'Success Rate'],
                top: 30
            },
            grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
            xAxis: {
                type: 'category',
                data: data.map(item => item.payment_method || 'Unknown')
            },
            yAxis: [
                {
                    type: 'value',
                    name: 'Revenue',
                    position: 'left',
                    axisLabel: {
                        formatter: (value) => `$${(value / 1000).toFixed(0)}K`
                    }
                },
                {
                    type: 'value',
                    name: 'Success Rate',
                    position: 'right',
                    min: 0,
                    max: 100,
                    axisLabel: {
                        formatter: '{value}%'
                    }
                }
            ],
            series: [
                {
                    name: 'Revenue',
                    type: 'bar',
                    data: data.map(item => parseFloat(item.revenue || 0)),
                    itemStyle: { color: '#0ea5e9' }
                },
                {
                    name: 'Success Rate',
                    type: 'line',
                    yAxisIndex: 1,
                    data: data.map(item => parseFloat(item.success_rate || 0)),
                    smooth: true,
                    itemStyle: { color: '#10b981' },
                    lineStyle: { width: 3 }
                }
            ]
        };
    }, [revenueByPaymentMethod]);

    // Cohort Revenue Heatmap
    const cohortHeatmapOption = useMemo(() => {
        if (!cohortRevenue?.data) return {};

        const data = cohortRevenue.data;
        const cohorts = [...new Set(data.map(item => item.cohort))].sort();
        const months = [...new Set(data.map(item => item.month_number))].sort((a, b) => a - b);

        const heatmapData = [];
        data.forEach(item => {
            const cohortIndex = cohorts.indexOf(item.cohort);
            const monthIndex = months.indexOf(item.month_number);
            heatmapData.push([monthIndex, cohortIndex, parseFloat(item.revenue || 0)]);
        });

        return {
            title: {
                text: 'Cohort Revenue Retention',
                subtext: 'Revenue generated by each cohort over time',
                left: 'center',
                textStyle: { fontSize: 16, fontWeight: 600 }
            },
            tooltip: {
                position: 'top',
                formatter: (params) => {
                    const [monthIdx, cohortIdx, value] = params.data;
                    return `${cohorts[cohortIdx]}<br/>Month ${months[monthIdx]}<br/>$${value.toLocaleString()}`;
                }
            },
            grid: { left: '15%', right: '4%', bottom: '10%', top: '15%', containLabel: true },
            xAxis: {
                type: 'category',
                data: months.map(m => `M${m}`),
                splitArea: { show: true }
            },
            yAxis: {
                type: 'category',
                data: cohorts,
                splitArea: { show: true }
            },
            visualMap: {
                min: 0,
                max: Math.max(...heatmapData.map(item => item[2])),
                calculable: true,
                orient: 'horizontal',
                left: 'center',
                bottom: '0%',
                inRange: {
                    color: ['#f0f9ff', '#0ea5e9', '#0369a1']
                }
            },
            series: [{
                name: 'Revenue',
                type: 'heatmap',
                data: heatmapData,
                label: {
                    show: false
                },
                emphasis: {
                    itemStyle: {
                        shadowBlur: 10,
                        shadowColor: 'rgba(0, 0, 0, 0.5)'
                    }
                }
            }]
        };
    }, [cohortRevenue]);

    // Revenue Projections
    const projectionsOption = useMemo(() => {
        if (!revenueProjections?.data?.projections) return {};

        const projections = revenueProjections.data.projections;

        return {
            title: {
                text: 'Revenue Forecast (30/60/90 Days)',
                left: 'center',
                textStyle: { fontSize: 16, fontWeight: 600 }
            },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'cross' }
            },
            legend: {
                data: ['Best Case', 'Expected', 'Worst Case'],
                top: 30
            },
            grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
            xAxis: {
                type: 'category',
                data: ['Today', '30 Days', '60 Days', '90 Days']
            },
            yAxis: {
                type: 'value',
                axisLabel: {
                    formatter: (value) => `$${(value / 1000).toFixed(0)}K`
                }
            },
            series: [
                {
                    name: 'Best Case',
                    type: 'line',
                    data: [
                        projections.current,
                        projections.day_30_best,
                        projections.day_60_best,
                        projections.day_90_best
                    ],
                    smooth: true,
                    itemStyle: { color: '#10b981' },
                    lineStyle: { width: 2, type: 'dashed' },
                    areaStyle: {
                        color: {
                            type: 'linear',
                            x: 0, y: 0, x2: 0, y2: 1,
                            colorStops: [
                                { offset: 0, color: 'rgba(16, 185, 129, 0.2)' },
                                { offset: 1, color: 'rgba(16, 185, 129, 0.05)' }
                            ]
                        }
                    }
                },
                {
                    name: 'Expected',
                    type: 'line',
                    data: [
                        projections.current,
                        projections.day_30,
                        projections.day_60,
                        projections.day_90
                    ],
                    smooth: true,
                    itemStyle: { color: '#0ea5e9' },
                    lineStyle: { width: 3 },
                    areaStyle: {
                        color: {
                            type: 'linear',
                            x: 0, y: 0, x2: 0, y2: 1,
                            colorStops: [
                                { offset: 0, color: 'rgba(14, 165, 233, 0.3)' },
                                { offset: 1, color: 'rgba(14, 165, 233, 0.05)' }
                            ]
                        }
                    }
                },
                {
                    name: 'Worst Case',
                    type: 'line',
                    data: [
                        projections.current,
                        projections.day_30_worst,
                        projections.day_60_worst,
                        projections.day_90_worst
                    ],
                    smooth: true,
                    itemStyle: { color: '#ef4444' },
                    lineStyle: { width: 2, type: 'dashed' },
                    areaStyle: {
                        color: {
                            type: 'linear',
                            x: 0, y: 0, x2: 0, y2: 1,
                            colorStops: [
                                { offset: 0, color: 'rgba(239, 68, 68, 0.2)' },
                                { offset: 1, color: 'rgba(239, 68, 68, 0.05)' }
                            ]
                        }
                    }
                }
            ]
        };
    }, [revenueProjections]);

    // Failed Payment Recovery
    const recoveryOption = useMemo(() => {
        if (!failedPayments?.data) return {};

        const data = failedPayments.data;

        return {
            title: {
                text: 'Failed Payment Recovery',
                left: 'center',
                textStyle: { fontSize: 16, fontWeight: 600 }
            },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'cross' }
            },
            legend: {
                data: ['Failed Amount', 'Recovered', 'Recovery Rate'],
                top: 30
            },
            grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
            xAxis: {
                type: 'category',
                data: data.map(item => item.period)
            },
            yAxis: [
                {
                    type: 'value',
                    name: 'Amount',
                    position: 'left',
                    axisLabel: {
                        formatter: (value) => `$${(value / 1000).toFixed(0)}K`
                    }
                },
                {
                    type: 'value',
                    name: 'Recovery Rate',
                    position: 'right',
                    min: 0,
                    max: 100,
                    axisLabel: {
                        formatter: '{value}%'
                    }
                }
            ],
            series: [
                {
                    name: 'Failed Amount',
                    type: 'bar',
                    data: data.map(item => parseFloat(item.failed_amount || 0)),
                    itemStyle: { color: '#ef4444' }
                },
                {
                    name: 'Recovered',
                    type: 'bar',
                    data: data.map(item => parseFloat(item.recovered_amount || 0)),
                    itemStyle: { color: '#10b981' }
                },
                {
                    name: 'Recovery Rate',
                    type: 'line',
                    yAxisIndex: 1,
                    data: data.map(item => parseFloat(item.recovery_rate || 0)),
                    smooth: true,
                    itemStyle: { color: '#0ea5e9' },
                    lineStyle: { width: 3 }
                }
            ]
        };
    }, [failedPayments]);

    const isLoading = breakdownLoading || concentrationLoading || paymentLoading ||
                     cohortLoading || projectionsLoading || failedLoading;

    return (
        <div className="space-y-6">
            {/* Header */}
            <div>
                <h1 className="text-3xl font-bold text-gray-900">Revenue Intelligence</h1>
                <p className="mt-2 text-gray-600">
                    Comprehensive revenue analytics, forecasting, and business health metrics
                </p>
            </div>

            {/* Key Metrics */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                <StatCard
                    title="Total Revenue"
                    value={metrics?.total}
                    type="currency"
                    loading={breakdownLoading}
                />
                <StatCard
                    title="Recurring Revenue %"
                    value={metrics?.recurringPercentage}
                    type="percentage"
                    loading={breakdownLoading}
                />
                <StatCard
                    title="Revenue Velocity"
                    value={metrics?.velocity}
                    type="percentage"
                    suffix="daily"
                    loading={projectionsLoading}
                />
                <StatCard
                    title="Revenue Health"
                    value={metrics?.health?.toUpperCase()}
                    loading={isLoading}
                />
            </div>

            {/* Revenue Flow and Velocity */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2">
                    <ChartWrapper
                        option={waterfallOption}
                        loading={breakdownLoading}
                        style={{ height: '400px' }}
                    />
                </div>
                <div>
                    <ChartWrapper
                        option={velocityOption}
                        loading={projectionsLoading}
                        style={{ height: '400px' }}
                    />
                </div>
            </div>

            {/* Revenue Breakdown */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <ChartWrapper
                    option={sourceBreakdownOption}
                    loading={breakdownLoading}
                    style={{ height: '400px' }}
                />
                <ChartWrapper
                    option={concentrationOption}
                    loading={concentrationLoading}
                    style={{ height: '400px' }}
                />
            </div>

            {/* Payment Method Analysis */}
            <ChartWrapper
                option={paymentMethodOption}
                loading={paymentLoading}
                style={{ height: '400px' }}
            />

            {/* Cohort Revenue Heatmap */}
            <ChartWrapper
                option={cohortHeatmapOption}
                loading={cohortLoading}
                style={{ height: '500px' }}
            />

            {/* Revenue Projections */}
            <ChartWrapper
                option={projectionsOption}
                loading={projectionsLoading}
                style={{ height: '400px' }}
            />

            {/* Failed Payment Recovery */}
            <ChartWrapper
                option={recoveryOption}
                loading={failedLoading}
                style={{ height: '400px' }}
            />

           
        </div>
    );
}

export default RevenueIntelligencePage;
