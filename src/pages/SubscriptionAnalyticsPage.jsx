import React from 'react';
import { useQuery } from '@tanstack/react-query';
import ReactECharts from 'echarts-for-react';
import StatCard from '../components/StatCard';
import ChartWrapper from '../components/ChartWrapper';
import { API } from '../utils/api';

/**
 * Subscription Analytics Dashboard
 *
 * Provides deep subscription metrics including:
 * - MRR momentum and waterfall analysis
 * - Cohort retention heatmaps
 * - Subscription lifecycle flows
 * - Churn analysis and patterns
 * - Upgrade/downgrade matrices
 * - Dunning and payment recovery
 */
function SubscriptionAnalyticsPage({ dateRange }) {
    // Fetch all subscription analytics data
    const { data: mrrData, isLoading: mrrLoading } = useQuery({
        queryKey: ['mrr-momentum', dateRange],
        queryFn: () => API.getMRRMomentum(dateRange),
    });

    const { data: cohortData, isLoading: cohortLoading } = useQuery({
        queryKey: ['cohort-retention', dateRange],
        queryFn: () => API.getCohortRetention(dateRange),
    });

    const { data: lifecycleData, isLoading: lifecycleLoading } = useQuery({
        queryKey: ['subscription-lifecycle', dateRange],
        queryFn: () => API.getSubscriptionLifecycle(dateRange),
    });

    const { data: churnData, isLoading: churnLoading } = useQuery({
        queryKey: ['churn-analysis', dateRange],
        queryFn: () => API.getChurnAnalysis(dateRange),
    });

    const { data: planMovementData, isLoading: planMovementLoading } = useQuery({
        queryKey: ['plan-movement', dateRange],
        queryFn: () => API.getPlanMovement(dateRange),
    });

    const { data: dunningData, isLoading: dunningLoading } = useQuery({
        queryKey: ['dunning-recovery', dateRange],
        queryFn: () => API.getDunningRecovery(dateRange),
    });

    // MRR Waterfall Chart Configuration
    const mrrWaterfallOption = {
        title: {
            text: 'MRR Waterfall Analysis',
            left: 'center',
            textStyle: { fontSize: 16, fontWeight: 'bold' }
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: (params) => {
                const param = params[0];
                return `${param.name}<br/>$${param.value?.toLocaleString() || 0}`;
            }
        },
        grid: {
            left: '3%',
            right: '4%',
            bottom: '3%',
            containLabel: true
        },
        xAxis: {
            type: 'category',
            data: ['Starting MRR', 'New', 'Expansion', 'Reactivation', 'Contraction', 'Churn', 'Ending MRR'],
            axisLabel: {
                rotate: 30,
                fontSize: 11
            }
        },
        yAxis: {
            type: 'value',
            axisLabel: {
                formatter: '${value}'
            }
        },
        series: [{
            type: 'bar',
            data: mrrData?.data?.waterfall || [],
            itemStyle: {
                color: (params) => {
                    const colors = {
                        0: '#94a3b8', // Starting (gray)
                        1: '#10b981', // New (green)
                        2: '#3b82f6', // Expansion (blue)
                        3: '#8b5cf6', // Reactivation (purple)
                        4: '#f59e0b', // Contraction (amber)
                        5: '#ef4444', // Churn (red)
                        6: '#0ea5e9'  // Ending (sky)
                    };
                    return colors[params.dataIndex] || '#94a3b8';
                }
            },
            label: {
                show: true,
                position: 'top',
                formatter: (params) => `$${params.value?.toLocaleString() || 0}`
            }
        }]
    };

    // Cohort Retention Heatmap Configuration
    const cohortHeatmapOption = {
        title: {
            text: 'Cohort Retention Heatmap',
            subtext: 'Percentage of subscribers retained over time',
            left: 'center',
            textStyle: { fontSize: 16, fontWeight: 'bold' }
        },
        tooltip: {
            position: 'top',
            formatter: (params) => {
                return `Cohort: ${params.name}<br/>Month ${params.value[0]}: ${params.value[2]}% retained`;
            }
        },
        grid: {
            left: '10%',
            right: '10%',
            bottom: '10%',
            top: '15%'
        },
        xAxis: {
            type: 'category',
            data: Array.from({ length: 12 }, (_, i) => `Month ${i + 1}`),
            splitArea: { show: true },
            name: 'Months Since Signup',
            nameLocation: 'middle',
            nameGap: 30
        },
        yAxis: {
            type: 'category',
            data: cohortData?.data?.cohorts || [],
            splitArea: { show: true },
            name: 'Signup Cohort',
            nameLocation: 'middle',
            nameGap: 50
        },
        visualMap: {
            min: 0,
            max: 100,
            calculable: true,
            orient: 'horizontal',
            left: 'center',
            bottom: '0%',
            inRange: {
                color: ['#fee2e2', '#fecaca', '#fca5a5', '#f87171', '#ef4444', '#dc2626', '#b91c1c']
            },
            text: ['High Retention', 'Low Retention']
        },
        series: [{
            type: 'heatmap',
            data: cohortData?.data?.heatmapData || [],
            label: {
                show: true,
                formatter: (params) => `${params.value[2]}%`
            },
            emphasis: {
                itemStyle: {
                    shadowBlur: 10,
                    shadowColor: 'rgba(0, 0, 0, 0.5)'
                }
            }
        }]
    };

    // Subscription Lifecycle Sankey Diagram
    const lifecycleSankeyOption = {
        title: {
            text: 'Subscription Lifecycle Flow',
            subtext: 'Movement between subscription stages',
            left: 'center',
            textStyle: { fontSize: 16, fontWeight: 'bold' }
        },
        tooltip: {
            trigger: 'item',
            triggerOn: 'mousemove',
            formatter: (params) => {
                if (params.dataType === 'edge') {
                    return `${params.data.source} → ${params.data.target}<br/>Count: ${params.data.value}`;
                }
                return `${params.name}<br/>Total: ${params.value}`;
            }
        },
        series: [{
            type: 'sankey',
            layout: 'none',
            emphasis: {
                focus: 'adjacency'
            },
            data: lifecycleData?.data?.nodes || [
                { name: 'Trial' },
                { name: 'Active Paid' },
                { name: 'Upgraded' },
                { name: 'Downgraded' },
                { name: 'Churned' },
                { name: 'Reactivated' }
            ],
            links: lifecycleData?.data?.links || [],
            lineStyle: {
                color: 'gradient',
                curveness: 0.5
            },
            itemStyle: {
                color: (params) => {
                    const colors = {
                        'Trial': '#94a3b8',
                        'Active Paid': '#10b981',
                        'Upgraded': '#3b82f6',
                        'Downgraded': '#f59e0b',
                        'Churned': '#ef4444',
                        'Reactivated': '#8b5cf6'
                    };
                    return colors[params.name] || '#64748b';
                }
            },
            label: {
                fontSize: 12,
                fontWeight: 'bold'
            }
        }]
    };

    // Churn Rate Trends
    const churnTrendOption = {
        title: {
            text: 'Churn Rate Over Time',
            left: 'center',
            textStyle: { fontSize: 16, fontWeight: 'bold' }
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'cross' }
        },
        legend: {
            data: ['Voluntary Churn', 'Involuntary Churn', 'Total Churn'],
            bottom: 0
        },
        grid: {
            left: '3%',
            right: '4%',
            bottom: '10%',
            containLabel: true
        },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: churnData?.data?.dates || []
        },
        yAxis: {
            type: 'value',
            axisLabel: {
                formatter: '{value}%'
            }
        },
        series: [
            {
                name: 'Voluntary Churn',
                type: 'line',
                smooth: true,
                data: churnData?.data?.voluntary || [],
                itemStyle: { color: '#ef4444' },
                areaStyle: {
                    color: {
                        type: 'linear',
                        x: 0, y: 0, x2: 0, y2: 1,
                        colorStops: [
                            { offset: 0, color: 'rgba(239, 68, 68, 0.3)' },
                            { offset: 1, color: 'rgba(239, 68, 68, 0.05)' }
                        ]
                    }
                }
            },
            {
                name: 'Involuntary Churn',
                type: 'line',
                smooth: true,
                data: churnData?.data?.involuntary || [],
                itemStyle: { color: '#f59e0b' },
                areaStyle: {
                    color: {
                        type: 'linear',
                        x: 0, y: 0, x2: 0, y2: 1,
                        colorStops: [
                            { offset: 0, color: 'rgba(245, 158, 11, 0.3)' },
                            { offset: 1, color: 'rgba(245, 158, 11, 0.05)' }
                        ]
                    }
                }
            },
            {
                name: 'Total Churn',
                type: 'line',
                smooth: true,
                data: churnData?.data?.total || [],
                itemStyle: { color: '#6366f1' },
                lineStyle: { width: 3 }
            }
        ]
    };

    // Plan Movement Matrix
    const planMovementOption = {
        title: {
            text: 'Plan Movement Matrix',
            subtext: 'Upgrades and downgrades between plans',
            left: 'center',
            textStyle: { fontSize: 16, fontWeight: 'bold' }
        },
        tooltip: {
            position: 'top',
            formatter: (params) => {
                return `From: ${params.value[1]}<br/>To: ${params.value[0]}<br/>Count: ${params.value[2]}`;
            }
        },
        grid: {
            left: '15%',
            right: '10%',
            bottom: '15%',
            top: '15%'
        },
        xAxis: {
            type: 'category',
            data: planMovementData?.data?.planNames || ['Basic', 'Pro', 'Premium', 'Enterprise'],
            splitArea: { show: true },
            name: 'From Plan',
            nameLocation: 'middle',
            nameGap: 30
        },
        yAxis: {
            type: 'category',
            data: planMovementData?.data?.planNames || ['Basic', 'Pro', 'Premium', 'Enterprise'],
            splitArea: { show: true },
            name: 'To Plan',
            nameLocation: 'middle',
            nameGap: 50
        },
        visualMap: {
            min: 0,
            max: planMovementData?.data?.maxMovement || 100,
            calculable: true,
            orient: 'horizontal',
            left: 'center',
            bottom: '0%',
            inRange: {
                color: ['#dbeafe', '#bfdbfe', '#93c5fd', '#60a5fa', '#3b82f6', '#2563eb', '#1d4ed8']
            }
        },
        series: [{
            type: 'heatmap',
            data: planMovementData?.data?.matrixData || [],
            label: {
                show: true,
                formatter: (params) => params.value[2] || '0'
            },
            emphasis: {
                itemStyle: {
                    shadowBlur: 10,
                    shadowColor: 'rgba(0, 0, 0, 0.5)'
                }
            }
        }]
    };

    // Dunning Recovery Success Rates
    const dunningRecoveryOption = {
        title: {
            text: 'Payment Recovery Success by Attempt',
            left: 'center',
            textStyle: { fontSize: 16, fontWeight: 'bold' }
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' }
        },
        legend: {
            data: ['Recovery Rate', 'Revenue Recovered'],
            bottom: 0
        },
        grid: {
            left: '3%',
            right: '4%',
            bottom: '10%',
            containLabel: true
        },
        xAxis: {
            type: 'category',
            data: ['Attempt 1', 'Attempt 2', 'Attempt 3', 'Attempt 4+']
        },
        yAxis: [
            {
                type: 'value',
                name: 'Recovery Rate',
                position: 'left',
                axisLabel: {
                    formatter: '{value}%'
                }
            },
            {
                type: 'value',
                name: 'Revenue',
                position: 'right',
                axisLabel: {
                    formatter: '${value}'
                }
            }
        ],
        series: [
            {
                name: 'Recovery Rate',
                type: 'bar',
                data: dunningData?.data?.recoveryRates || [65, 45, 30, 15],
                itemStyle: { color: '#10b981' },
                yAxisIndex: 0,
                label: {
                    show: true,
                    position: 'top',
                    formatter: '{c}%'
                }
            },
            {
                name: 'Revenue Recovered',
                type: 'line',
                data: dunningData?.data?.revenueRecovered || [],
                itemStyle: { color: '#3b82f6' },
                yAxisIndex: 1,
                smooth: true,
                label: {
                    show: true,
                    position: 'top',
                    formatter: (params) => `$${params.value?.toLocaleString() || 0}`
                }
            }
        ]
    };

    // Churn by Reason
    const churnReasonOption = {
        title: {
            text: 'Churn Reasons Distribution',
            left: 'center',
            textStyle: { fontSize: 16, fontWeight: 'bold' }
        },
        tooltip: {
            trigger: 'item',
            formatter: '{b}: {c} ({d}%)'
        },
        legend: {
            orient: 'vertical',
            right: '5%',
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
                show: true,
                formatter: '{b}: {d}%'
            },
            emphasis: {
                label: {
                    show: true,
                    fontSize: 16,
                    fontWeight: 'bold'
                }
            },
            data: churnData?.data?.reasons || [
                { value: 35, name: 'Too Expensive', itemStyle: { color: '#ef4444' } },
                { value: 25, name: 'Not Using Enough', itemStyle: { color: '#f59e0b' } },
                { value: 20, name: 'Missing Features', itemStyle: { color: '#8b5cf6' } },
                { value: 10, name: 'Technical Issues', itemStyle: { color: '#6366f1' } },
                { value: 10, name: 'Other', itemStyle: { color: '#94a3b8' } }
            ]
        }]
    };

    // Revenue Recovered Over Time
    const revenueRecoveredTimelineOption = {
        title: {
            text: 'Revenue Recovered Over Time',
            left: 'center',
            textStyle: { fontSize: 16, fontWeight: 'bold' }
        },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'cross' }
        },
        legend: {
            data: ['Failed Payments', 'Recovered Revenue', 'Recovery Rate'],
            bottom: 0
        },
        grid: {
            left: '3%',
            right: '4%',
            bottom: '10%',
            containLabel: true
        },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: dunningData?.data?.timelineDates || []
        },
        yAxis: [
            {
                type: 'value',
                name: 'Revenue',
                position: 'left',
                axisLabel: {
                    formatter: '${value}'
                }
            },
            {
                type: 'value',
                name: 'Recovery Rate',
                position: 'right',
                axisLabel: {
                    formatter: '{value}%'
                }
            }
        ],
        series: [
            {
                name: 'Failed Payments',
                type: 'bar',
                data: dunningData?.data?.failedPayments || [],
                itemStyle: { color: '#ef4444' },
                yAxisIndex: 0
            },
            {
                name: 'Recovered Revenue',
                type: 'bar',
                data: dunningData?.data?.recoveredRevenue || [],
                itemStyle: { color: '#10b981' },
                yAxisIndex: 0
            },
            {
                name: 'Recovery Rate',
                type: 'line',
                data: dunningData?.data?.timelineRecoveryRate || [],
                itemStyle: { color: '#3b82f6' },
                yAxisIndex: 1,
                smooth: true,
                lineStyle: { width: 3 }
            }
        ]
    };

    return (
        <div className="space-y-6">
            {/* Page Header */}
            <div className="bg-gradient-to-r from-purple-600 to-blue-600 text-white p-6 rounded-lg shadow-lg">
                <h1 className="text-3xl font-bold mb-2">Subscription Analytics</h1>
                <p className="text-purple-100">
                    Deep subscription metrics: MRR momentum, cohort retention, lifecycle flows, churn analysis, and payment recovery
                </p>
            </div>

            {/* MRR Overview Cards */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                <StatCard
                    title="Current MRR"
                    value={mrrData?.data?.currentMRR}
                    type="currency"
                    loading={mrrLoading}
                    change={mrrData?.data?.mrrGrowthRate}
                />
                <StatCard
                    title="New MRR"
                    value={mrrData?.data?.newMRR}
                    type="currency"
                    loading={mrrLoading}
                />
                <StatCard
                    title="Expansion MRR"
                    value={mrrData?.data?.expansionMRR}
                    type="currency"
                    loading={mrrLoading}
                />
                <StatCard
                    title="Churned MRR"
                    value={mrrData?.data?.churnedMRR}
                    type="currency"
                    loading={mrrLoading}
                    invertChange={true}
                />
            </div>

            {/* MRR Waterfall Chart */}
            <div className="stat-card">
                <ChartWrapper
                    option={mrrWaterfallOption}
                    loading={mrrLoading}
                    style={{ height: '400px' }}
                />
            </div>

            {/* Cohort Retention Section */}
            <div className="stat-card">
                <h2 className="text-xl font-bold mb-4">Cohort Retention Analysis</h2>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <StatCard
                        title="Average 3-Month Retention"
                        value={cohortData?.data?.avgThreeMonthRetention}
                        type="percentage"
                        loading={cohortLoading}
                    />
                    <StatCard
                        title="Average 6-Month Retention"
                        value={cohortData?.data?.avgSixMonthRetention}
                        type="percentage"
                        loading={cohortLoading}
                    />
                    <StatCard
                        title="Average 12-Month Retention"
                        value={cohortData?.data?.avgTwelveMonthRetention}
                        type="percentage"
                        loading={cohortLoading}
                    />
                </div>
                <ChartWrapper
                    option={cohortHeatmapOption}
                    loading={cohortLoading}
                    style={{ height: '500px' }}
                />
            </div>

            {/* Subscription Lifecycle Flow */}
            <div className="stat-card">
                <h2 className="text-xl font-bold mb-4">Subscription Lifecycle Flow</h2>
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <StatCard
                        title="Trial Conversion Rate"
                        value={lifecycleData?.data?.trialConversionRate}
                        type="percentage"
                        loading={lifecycleLoading}
                    />
                    <StatCard
                        title="Upgrade Rate"
                        value={lifecycleData?.data?.upgradeRate}
                        type="percentage"
                        loading={lifecycleLoading}
                    />
                    <StatCard
                        title="Downgrade Rate"
                        value={lifecycleData?.data?.downgradeRate}
                        type="percentage"
                        loading={lifecycleLoading}
                        invertChange={true}
                    />
                    <StatCard
                        title="Reactivation Rate"
                        value={lifecycleData?.data?.reactivationRate}
                        type="percentage"
                        loading={lifecycleLoading}
                    />
                </div>
                <ChartWrapper
                    option={lifecycleSankeyOption}
                    loading={lifecycleLoading}
                    style={{ height: '500px' }}
                />
            </div>

            {/* Churn Analysis Section */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Churn Trends */}
                <div className="stat-card">
                    <ChartWrapper
                        option={churnTrendOption}
                        loading={churnLoading}
                        style={{ height: '400px' }}
                    />
                </div>

                {/* Churn Reasons */}
                <div className="stat-card">
                    <ChartWrapper
                        option={churnReasonOption}
                        loading={churnLoading}
                        style={{ height: '400px' }}
                    />
                </div>
            </div>

            {/* Churn Metrics Cards */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                <StatCard
                    title="Overall Churn Rate"
                    value={churnData?.data?.overallChurnRate}
                    type="percentage"
                    loading={churnLoading}
                    invertChange={true}
                />
                <StatCard
                    title="Voluntary Churn Rate"
                    value={churnData?.data?.voluntaryChurnRate}
                    type="percentage"
                    loading={churnLoading}
                    invertChange={true}
                />
                <StatCard
                    title="Involuntary Churn Rate"
                    value={churnData?.data?.involuntaryChurnRate}
                    type="percentage"
                    loading={churnLoading}
                    invertChange={true}
                />
                <StatCard
                    title="Win-back Success Rate"
                    value={churnData?.data?.winbackRate}
                    type="percentage"
                    loading={churnLoading}
                />
            </div>

            {/* Plan Movement Matrix */}
            <div className="stat-card">
                <h2 className="text-xl font-bold mb-4">Upgrade/Downgrade Patterns</h2>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <StatCard
                        title="Net Plan Upgrades"
                        value={planMovementData?.data?.netUpgrades}
                        type="number"
                        loading={planMovementLoading}
                    />
                    <StatCard
                        title="Upgrade Revenue Impact"
                        value={planMovementData?.data?.upgradeRevenueImpact}
                        type="currency"
                        loading={planMovementLoading}
                    />
                    <StatCard
                        title="Downgrade Revenue Impact"
                        value={planMovementData?.data?.downgradeRevenueImpact}
                        type="currency"
                        loading={planMovementLoading}
                        invertChange={true}
                    />
                </div>
                <ChartWrapper
                    option={planMovementOption}
                    loading={planMovementLoading}
                    style={{ height: '500px' }}
                />
            </div>

            {/* Dunning & Recovery Section */}
            <div className="stat-card">
                <h2 className="text-xl font-bold mb-4">Payment Dunning & Recovery</h2>
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <StatCard
                        title="Failed Payment Rate"
                        value={dunningData?.data?.failedPaymentRate}
                        type="percentage"
                        loading={dunningLoading}
                        invertChange={true}
                    />
                    <StatCard
                        title="Overall Recovery Rate"
                        value={dunningData?.data?.overallRecoveryRate}
                        type="percentage"
                        loading={dunningLoading}
                    />
                    <StatCard
                        title="Total Revenue Recovered"
                        value={dunningData?.data?.totalRevenueRecovered}
                        type="currency"
                        loading={dunningLoading}
                    />
                    <StatCard
                        title="Avg Days to Recovery"
                        value={dunningData?.data?.avgDaysToRecovery}
                        type="number"
                        loading={dunningLoading}
                    />
                </div>

                {/* Recovery Success by Attempt */}
                <ChartWrapper
                    option={dunningRecoveryOption}
                    loading={dunningLoading}
                    style={{ height: '400px' }}
                />
            </div>

            {/* Revenue Recovered Timeline */}
            <div className="stat-card">
                <ChartWrapper
                    option={revenueRecoveredTimelineOption}
                    loading={dunningLoading}
                    style={{ height: '400px' }}
                />
            </div>

         
        </div>
    );
}

export default SubscriptionAnalyticsPage;
