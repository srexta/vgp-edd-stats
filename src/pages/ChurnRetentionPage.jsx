import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { API } from '../utils/api';
import StatCard from '../components/StatCard';
import ChartWrapper from '../components/ChartWrapper';

function ChurnRetentionPage({ dateRange }) {
	const [maxYears, setMaxYears] = useState(7);

	// Fetch comprehensive churn data
	const { data: churnData, isLoading: churnLoading } = useQuery({
		queryKey: ['churn-comprehensive', dateRange],
		queryFn: () => API.getChurnComprehensive(dateRange),
	});

	// Fetch monthly trends
	const { data: trendsData, isLoading: trendsLoading } = useQuery({
		queryKey: ['churn-monthly-trends', dateRange],
		queryFn: () => API.getChurnMonthlyTrends(dateRange),
	});

	// Fetch cohort heatmap
	const { data: cohortData, isLoading: cohortLoading } = useQuery({
		queryKey: ['retention-cohort-heatmap', maxYears],
		queryFn: () => API.getRetentionCohortHeatmap(maxYears),
	});

	// Fetch retention curve
	const { data: curveData, isLoading: curveLoading } = useQuery({
		queryKey: ['retention-curve', dateRange],
		queryFn: () => API.getRetentionCurve(dateRange),
	});

	// Extract summary data
	const summary = churnData?.data?.summary || {};
	const monthlyTrend = churnData?.data?.monthly_trend || [];
	const churnByReason = churnData?.data?.churn_by_reason || [];

	// Churn Trend Chart
	const churnTrendOption = {
		tooltip: {
			trigger: 'axis',
			formatter: (params) => {
				let result = `${params[0].name}<br/>`;
				params.forEach((param) => {
					if (param.seriesName === 'Churn Rate') {
						result += `${param.marker} ${param.seriesName}: ${param.value}%<br/>`;
					} else {
						result += `${param.marker} ${param.seriesName}: ${param.value}<br/>`;
					}
				});
				return result;
			},
		},
		legend: {
			data: ['Churned', 'Canceled', 'Expired', 'Churn Rate'],
			bottom: 0,
		},
		grid: {
			left: '3%',
			right: '4%',
			bottom: '15%',
			containLabel: true,
		},
		xAxis: {
			type: 'category',
			data: trendsData?.data?.trends?.map((d) => d.label) || [],
			axisLabel: {
				rotate: 45,
			},
		},
		yAxis: [
			{
				type: 'value',
				name: 'Count',
				position: 'left',
			},
			{
				type: 'value',
				name: 'Rate (%)',
				position: 'right',
				axisLabel: {
					formatter: '{value}%',
				},
			},
		],
		series: [
			{
				name: 'Churned',
				type: 'bar',
				stack: 'total',
				data: trendsData?.data?.trends?.map((d) => d.churned) || [],
				itemStyle: { color: '#ef4444' },
			},
			{
				name: 'Canceled',
				type: 'bar',
				stack: 'detail',
				data: trendsData?.data?.trends?.map((d) => d.canceled) || [],
				itemStyle: { color: '#f97316' },
				emphasis: { focus: 'series' },
			},
			{
				name: 'Expired',
				type: 'bar',
				stack: 'detail',
				data: trendsData?.data?.trends?.map((d) => d.expired) || [],
				itemStyle: { color: '#eab308' },
				emphasis: { focus: 'series' },
			},
			{
				name: 'Churn Rate',
				type: 'line',
				yAxisIndex: 1,
				data: trendsData?.data?.trends?.map((d) => d.churn_rate) || [],
				itemStyle: { color: '#8b5cf6' },
				smooth: true,
			},
		],
	};

	// Churn by Reason Pie Chart
	const churnReasonOption = {
		tooltip: {
			trigger: 'item',
			formatter: '{a} <br/>{b}: {c} ({d}%)',
		},
		legend: {
			orient: 'vertical',
			left: 'left',
		},
		series: [
			{
				name: 'Churn Reason',
				type: 'pie',
				radius: ['40%', '70%'],
				avoidLabelOverlap: false,
				itemStyle: {
					borderRadius: 10,
					borderColor: '#fff',
					borderWidth: 2,
				},
				label: {
					show: true,
					formatter: '{b}: {d}%',
				},
				emphasis: {
					label: {
						show: true,
						fontSize: 16,
						fontWeight: 'bold',
					},
				},
				data: churnByReason.map((item, index) => ({
					value: item.count,
					name: item.reason,
					itemStyle: {
						color: index === 0 ? '#f97316' : '#eab308',
					},
				})),
			},
		],
	};

	// Retention Curve Chart
	const retentionCurveOption = {
		tooltip: {
			trigger: 'axis',
			formatter: (params) => {
				const p = params[0];
				return `${p.name}<br/>
					${p.marker} Retention: ${p.value}%<br/>
					Retained: ${curveData?.data?.curve?.[p.dataIndex]?.retained || 0}`;
			},
		},
		xAxis: {
			type: 'category',
			data: curveData?.data?.curve?.map((d) => d.label) || [],
			axisLabel: {
				rotate: 45,
			},
		},
		yAxis: {
			type: 'value',
			name: 'Retention %',
			max: 100,
			axisLabel: {
				formatter: '{value}%',
			},
		},
		series: [
			{
				name: 'Retention Rate',
				type: 'line',
				data: curveData?.data?.curve?.map((d) => d.retention_rate) || [],
				smooth: true,
				itemStyle: { color: '#10b981' },
				areaStyle: {
					color: {
						type: 'linear',
						x: 0,
						y: 0,
						x2: 0,
						y2: 1,
						colorStops: [
							{ offset: 0, color: 'rgba(16, 185, 129, 0.3)' },
							{ offset: 1, color: 'rgba(16, 185, 129, 0.05)' },
						],
					},
				},
				markLine: {
					data: [
						{ type: 'average', name: 'Average' },
					],
				},
			},
		],
	};

	// Get churn rate color
	const getChurnRateColor = (rate) => {
		if (rate < 30) return 'bg-green-100 text-green-800';
		if (rate < 60) return 'bg-yellow-100 text-yellow-800';
		return 'bg-red-100 text-red-800';
	};

	// Get churn rate cell color for heatmap (for other uses)
	const getCellColor = (rate) => {
		if (rate === null || rate === undefined) return 'bg-gray-100 text-gray-400';
		if (rate < 20) return 'bg-green-200 text-green-900';
		if (rate < 40) return 'bg-green-100 text-green-800';
		if (rate < 60) return 'bg-yellow-100 text-yellow-800';
		if (rate < 80) return 'bg-orange-100 text-orange-800';
		return 'bg-red-100 text-red-800';
	};

	// Get retention rate cell color for cohort table (inverted logic: high retention = good)
	const getRetentionCellColor = (retentionRate) => {
		if (retentionRate === null || retentionRate === undefined) return 'bg-gray-100 text-gray-400';
		// High retention (80%+) = excellent (green)
		if (retentionRate >= 80) return 'bg-green-200 text-green-900';
		// Good retention (60-80%) = good (light green)
		if (retentionRate >= 60) return 'bg-green-100 text-green-800';
		// Medium retention (40-60%) = moderate (yellow)
		if (retentionRate >= 40) return 'bg-yellow-100 text-yellow-800';
		// Low retention (20-40%) = concerning (orange)
		if (retentionRate >= 20) return 'bg-orange-100 text-orange-800';
		// Very low retention (<20%) = critical (red)
		return 'bg-red-100 text-red-800';
	};

	return (
		<div className="space-y-6">
			{/* Summary Cards */}
			<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
				<StatCard
					title="Current Churn Rate"
					value={summary.churn_rate || 0}
					type="percentage"
					subtitle={`${summary.total_churned || 0} churned out of ${summary.active_at_start || 0}`}
					loading={churnLoading}
					change={summary.churn_change_percent}
				/>
				<StatCard
					title="Retention Rate"
					value={summary.retention_rate || 0}
					type="percentage"
					subtitle="Percentage of customers retained"
					loading={churnLoading}
				/>
				<StatCard
					title="Annualized Churn"
					value={summary.annualized_churn || 0}
					type="percentage"
					subtitle="Projected annual churn rate"
					loading={churnLoading}
				/>
				<StatCard
					title="Avg. Lifetime"
					value={`${curveData?.data?.average_lifetime || 0} mo`}
					subtitle="Average subscription lifetime"
					loading={curveLoading}
				/>
			</div>

			{/* Churn Breakdown Cards */}
			<div className="grid grid-cols-1 md:grid-cols-3 gap-6">
				<StatCard
					title="Voluntary Churn"
					value={summary.canceled || 0}
					description="Customers who canceled"
					loading={churnLoading}
					icon="user-minus"
				/>
				<StatCard
					title="Involuntary Churn"
					value={summary.expired || 0}
					description="Subscriptions expired/failed"
					loading={churnLoading}
					icon="clock"
				/>
				<StatCard
					title="Current Active"
					value={summary.current_active || 0}
					description="Currently active subscriptions"
					loading={churnLoading}
					valueColor="text-green-600"
					icon="users"
				/>
			</div>

			{/* Monthly Churn Trends Chart */}
			<div className="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
				<h3 className="text-lg font-semibold mb-4">Monthly Churn Trends</h3>
				<ChartWrapper
					option={churnTrendOption}
					loading={trendsLoading}
					height={400}
				/>
			</div>

			{/* Churn Reason & Retention Curve Row */}
			<div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
				{/* Churn by Reason */}
				<div className="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
					<h3 className="text-lg font-semibold mb-4">Churn by Reason</h3>
					<ChartWrapper
						option={churnReasonOption}
						loading={churnLoading}
						height={300}
					/>
				</div>

				{/* Retention Curve */}
				<div className="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
					<h3 className="text-lg font-semibold mb-4">Retention Curve</h3>
					<ChartWrapper
						option={retentionCurveOption}
						loading={curveLoading}
						height={300}
					/>
				</div>
			</div>

			{/* Cohort Retention Heatmap */}
			<div className="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
				<div className="flex items-center justify-between mb-4">
					<h3 className="text-lg font-semibold">Cohort Retention Analysis</h3>
					<div className="flex items-center gap-2">
						<label className="text-sm text-gray-600">Max Years:</label>
						<select
							value={maxYears}
							onChange={(e) => setMaxYears(parseInt(e.target.value))}
							className="border border-gray-300 rounded px-2 py-1 text-sm"
						>
							{[3, 4, 5, 6, 7, 8].map((n) => (
								<option key={n} value={n}>{n}</option>
							))}
						</select>
					</div>
				</div>

				{cohortLoading ? (
					<div className="h-64 flex items-center justify-center">
						<div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
					</div>
				) : (
					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-gray-200">
							<thead className="bg-gray-50">
								<tr>
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
										Signup Year
									</th>
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
										Customers
									</th>
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
										Subscriptions
									</th>
									{Array.from({ length: maxYears }, (_, i) => (
										<th
											key={i}
											className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"
										>
											Year {i + 1} Retention
										</th>
									))}
								</tr>
							</thead>
							<tbody className="bg-white divide-y divide-gray-200">
								{cohortData?.data?.cohorts?.map((cohort) => (
									<tr key={cohort.signup_year} className="hover:bg-gray-50">
										<td className="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
											{cohort.signup_year}
										</td>
										<td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
											{cohort.customers}
										</td>
										<td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
											{cohort.subscriptions || cohort.customers}
										</td>
										{Array.from({ length: maxYears }, (_, i) => {
											const churnRate = cohort.churn_rates?.[`year_${i + 1}`];
											// Convert churn rate to retention rate
											const retentionRate = churnRate !== null && churnRate !== undefined 
												? Math.round((100 - churnRate) * 10) / 10 
												: null;
											return (
												<td
													key={i}
													className={`px-4 py-3 whitespace-nowrap text-sm text-center ${getRetentionCellColor(retentionRate)}`}
												>
													{retentionRate !== null && retentionRate !== undefined ? `${retentionRate}%` : '-'}
												</td>
											);
										})}
									</tr>
								))}
							</tbody>
						</table>
					</div>
				)}

				{/* Legend */}
				<div className="mt-4 flex items-center gap-4 text-xs">
					<span className="font-medium text-gray-700">Retention Rate:</span>
					<span className="flex items-center gap-1">
						<span className="w-4 h-4 bg-green-200 rounded"></span> Excellent (≥80%)
					</span>
					<span className="flex items-center gap-1">
						<span className="w-4 h-4 bg-green-100 rounded"></span> Good (60-80%)
					</span>
					<span className="flex items-center gap-1">
						<span className="w-4 h-4 bg-yellow-100 rounded"></span> Moderate (40-60%)
					</span>
					<span className="flex items-center gap-1">
						<span className="w-4 h-4 bg-orange-100 rounded"></span> Concerning (20-40%)
					</span>
					<span className="flex items-center gap-1">
						<span className="w-4 h-4 bg-red-100 rounded"></span> Critical (&lt;20%)
					</span>
				</div>
			</div>

			{/* Insights Panel */}
			<div className="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 rounded-lg border border-blue-200">
				<h3 className="text-lg font-semibold text-blue-900 mb-4">Key Insights</h3>
				<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
					{/* Churn Status Insight */}
					<div className="bg-white p-4 rounded-lg shadow-sm">
						<div className={`inline-block px-2 py-1 rounded text-xs font-medium mb-2 ${getChurnRateColor(summary.churn_rate || 0)}`}>
							{summary.churn_rate < 30 ? 'Healthy' : summary.churn_rate < 60 ? 'Moderate' : 'Needs Attention'}
						</div>
						<p className="text-sm text-gray-600">
							Your churn rate of <strong>{summary.churn_rate || 0}%</strong> is{' '}
							{summary.churn_rate < 30
								? 'within the healthy range. Keep up the good retention strategies!'
								: summary.churn_rate < 60
								? 'moderate. Consider implementing retention campaigns.'
								: 'above average. Immediate action is recommended.'}
						</p>
					</div>

					{/* Voluntary vs Involuntary */}
					<div className="bg-white p-4 rounded-lg shadow-sm">
						<div className="text-xs font-medium text-gray-500 mb-2">CHURN TYPE ANALYSIS</div>
						<p className="text-sm text-gray-600">
							{summary.canceled > summary.expired ? (
								<>
									<strong>Voluntary churn</strong> ({summary.canceled}) exceeds involuntary ({summary.expired}).
									Focus on customer satisfaction and value proposition.
								</>
							) : (
								<>
									<strong>Involuntary churn</strong> ({summary.expired}) exceeds voluntary ({summary.canceled}).
									Consider improving payment recovery and dunning processes.
								</>
							)}
						</p>
					</div>

					{/* Lifetime Value */}
					<div className="bg-white p-4 rounded-lg shadow-sm">
						<div className="text-xs font-medium text-gray-500 mb-2">CUSTOMER LIFETIME</div>
						<p className="text-sm text-gray-600">
							Average subscription lifetime is <strong>{curveData?.data?.average_lifetime || 0} months</strong>.
							{curveData?.data?.average_lifetime >= 12
								? ' This indicates strong customer loyalty.'
								: ' There may be opportunities to improve long-term engagement.'}
						</p>
					</div>
				</div>
			</div>
		</div>
	);
}

export default ChurnRetentionPage;

